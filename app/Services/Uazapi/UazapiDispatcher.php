<?php

namespace App\Services\Uazapi;

use App\Jobs\UazapiSendMessageJob;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\UazapiCampaign;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Models\UazapiOptOut;
use App\Services\Whatsapp\WhatsappRecoveryGuard;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

class UazapiDispatcher
{
    public function __construct(
        private UazapiClient $client,
        private UazapiMessageBuilder $messageBuilder,
    ) {}

    /**
     * @param  array<string, string>  $vars
     * @param  array<string, mixed>  $extra
     */
    public function dispatchCartRecoveryStep(
        UazapiInstance $instance,
        CheckoutSession $session,
        int $stepIndex,
        string $template,
        array $vars,
        array $extra = []
    ): bool {
        if (! $instance->canSendRecovery() || ! $instance->cart_recovery_enabled) {
            return false;
        }

        $phone = $this->client->normalizePhone($session->phone);
        if ($phone === null) {
            return false;
        }

        if ($this->isBlocked((int) $session->tenant_id, $phone, $session->created_at)) {
            return false;
        }

        if (WhatsappRecoveryGuard::alreadyOwnsProduct(
            (int) $session->tenant_id,
            $phone,
            $session->product_id
        )) {
            return false;
        }

        if (WhatsappRecoveryGuard::sessionTaken((int) $session->id, UazapiInstance::EVENT_CART_RECOVERY, $stepIndex)) {
            return false;
        }

        $consumed = UazapiMessageDispatch::consumedStepIndicesForSession($session->id);
        if (in_array($stepIndex, $consumed, true)) {
            return false;
        }

        $message = $this->messageBuilder->render($template, $vars);
        $this->assertMessageLength($message);

        $session->loadMissing('product');

        return $this->queueDispatch(
            instance: $instance,
            eventType: UazapiInstance::EVENT_CART_RECOVERY,
            phone: $phone,
            message: $message,
            tenantId: (int) $session->tenant_id,
            checkoutSessionId: $session->id,
            orderId: null,
            sequenceStep: $stepIndex,
            extra: [
                'button_url' => $vars['link'] ?? '',
                'button_label' => 'Finalizar compra',
                'label' => UazapiLabelService::ABANDONED,
                ...$this->imageExtra($instance, $session->product),
                ...$extra,
            ],
        );
    }

    /**
     * @param  array<string, string>  $vars
     * @param  array<string, mixed>  $extra
     */
    public function dispatchPixGenerated(
        UazapiInstance $instance,
        Order $order,
        string $phone,
        array $vars,
        array $extra = []
    ): bool {
        return $this->dispatchPixRecoveryStep(
            $instance,
            $order,
            $phone,
            0,
            $instance->pixMessageTemplate(),
            $vars,
            $extra
        );
    }

    /**
     * @param  array<string, string>  $vars
     * @param  array<string, mixed>  $extra
     */
    public function dispatchPixRecoveryStep(
        UazapiInstance $instance,
        Order $order,
        string $phone,
        int $stepIndex,
        string $template,
        array $vars,
        array $extra = []
    ): bool {
        if (! $instance->canSendRecovery() || ! $instance->pix_recovery_enabled) {
            return false;
        }

        if ($order->api_application_id !== null || $order->api_checkout_session_id !== null) {
            return false;
        }

        $normalized = $this->client->normalizePhone($phone);
        if ($normalized === null) {
            return false;
        }

        if ($this->isBlocked((int) $order->tenant_id, $normalized, $order->created_at)) {
            return false;
        }

        if (WhatsappRecoveryGuard::orderStepTaken(
            (int) $order->id,
            UazapiInstance::EVENT_PIX_GENERATED,
            $stepIndex
        )) {
            return false;
        }

        $message = $this->messageBuilder->render($template, $vars);
        $this->assertMessageLength($message);

        $order->loadMissing('product');

        return $this->queueDispatch(
            instance: $instance,
            eventType: UazapiInstance::EVENT_PIX_GENERATED,
            phone: $normalized,
            message: $message,
            tenantId: (int) $order->tenant_id,
            checkoutSessionId: null,
            orderId: (int) $order->id,
            sequenceStep: $stepIndex,
            extra: [
                'button_url' => $vars['link'] ?? '',
                'button_label' => 'Pagar agora',
                'pix_copy' => $vars['pix'] ?? '',
                'label' => UazapiLabelService::ABANDONED,
                ...$this->imageExtra($instance, $order->product),
                ...$extra,
            ],
        );
    }

    /**
     * Confirmação enviada quando o PIX do pedido é pago.
     *
     * @param  array<string, string>  $vars
     */
    public function dispatchOrderPaid(
        UazapiInstance $instance,
        Order $order,
        string $phone,
        array $vars,
    ): bool {
        if (! $instance->canSendRecovery() || ! $instance->order_paid_enabled) {
            return false;
        }

        if ($order->paymentMethodReportKey() !== 'pix') {
            return false;
        }

        if ($order->api_application_id !== null || $order->api_checkout_session_id !== null) {
            return false;
        }

        $normalized = $this->client->normalizePhone($phone);
        if ($normalized === null) {
            return false;
        }

        if (WhatsappRecoveryGuard::isOptedOut((int) $order->tenant_id, $normalized)) {
            return false;
        }

        if (WhatsappRecoveryGuard::orderStepTaken(
            (int) $order->id,
            UazapiInstance::EVENT_ORDER_PAID,
            0
        )) {
            return false;
        }

        $message = trim($this->messageBuilder->render($instance->orderPaidMessageTemplate(), $vars));
        if ($message === '') {
            return false;
        }
        $this->assertMessageLength($message);

        $order->loadMissing('product');
        $accessUrl = trim((string) ($vars['link_acesso'] ?? ''));

        return $this->queueDispatch(
            instance: $instance,
            eventType: UazapiInstance::EVENT_ORDER_PAID,
            phone: $normalized,
            message: $message,
            tenantId: (int) $order->tenant_id,
            checkoutSessionId: null,
            orderId: (int) $order->id,
            sequenceStep: 0,
            extra: [
                'button_url' => $accessUrl !== '' ? $accessUrl : (string) ($vars['link'] ?? ''),
                'button_label' => 'Acessar',
                'label' => UazapiLabelService::PAID,
                ...$this->imageExtra($instance, $order->product),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function dispatchCampaignMessage(
        UazapiInstance $instance,
        UazapiCampaign $campaign,
        string $phone,
        string $message,
        ?int $checkoutSessionId,
        ?int $orderId,
        array $extra = [],
        int $delaySeconds = 0,
    ): bool {
        if (! $instance->canSendRecovery()) {
            return false;
        }

        $normalized = $this->client->normalizePhone($phone);
        if ($normalized === null) {
            return false;
        }

        if (UazapiOptOut::isOptedOut((int) $campaign->tenant_id, $normalized)) {
            return false;
        }

        $this->assertMessageLength($message);

        return $this->queueDispatch(
            instance: $instance,
            eventType: UazapiInstance::EVENT_CAMPAIGN,
            phone: $normalized,
            message: $message,
            tenantId: (int) $campaign->tenant_id,
            checkoutSessionId: $checkoutSessionId,
            orderId: $orderId,
            sequenceStep: null,
            extra: $extra,
            campaignId: (int) $campaign->id,
            delaySeconds: $delaySeconds,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function queueDispatch(
        UazapiInstance $instance,
        string $eventType,
        string $phone,
        string $message,
        int $tenantId,
        ?int $checkoutSessionId,
        ?int $orderId,
        ?int $sequenceStep,
        array $extra = [],
        ?int $campaignId = null,
        int $delaySeconds = 0,
    ): bool {
        $dispatch = UazapiMessageDispatch::query()->create([
            'tenant_id' => $tenantId,
            'uazapi_instance_id' => $instance->id,
            'checkout_session_id' => $checkoutSessionId,
            'order_id' => $orderId,
            'campaign_id' => $campaignId,
            'event_type' => $eventType,
            'sequence_step' => $sequenceStep,
            'phone' => $phone,
            'message' => $message,
            'payload' => $extra,
            'status' => UazapiMessageDispatch::STATUS_PENDING,
        ]);

        $dispatch->track_id = 'uazapi-dispatch-'.$dispatch->id;
        $dispatch->save();
        $instance->forceFill(['last_used_at' => now()])->save();

        $job = UazapiSendMessageJob::dispatch($dispatch->id)->onQueue((string) config('uazapi.queue', 'uazapi'));
        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }

        Log::info('UazapiDispatcher: mensagem enfileirada', [
            'dispatch_id' => $dispatch->id,
            'event_type' => $eventType,
            'tenant_id' => $tenantId,
        ]);

        return true;
    }

    private function isBlocked(int $tenantId, string $phone, mixed $startedAt): bool
    {
        if (WhatsappRecoveryGuard::isOptedOut($tenantId, $phone)) {
            return true;
        }

        if (! $startedAt instanceof DateTimeInterface) {
            return false;
        }

        return WhatsappRecoveryGuard::blocks($tenantId, $phone, $startedAt);
    }

    /**
     * @return array<string, string>
     */
    private function imageExtra(UazapiInstance $instance, mixed $product): array
    {
        if (! $instance->send_product_image || ! $product instanceof Product) {
            return [];
        }

        $url = UazapiProductMedia::publicUrl($product);

        return $url ? ['image_url' => $url] : [];
    }

    private function assertMessageLength(string $message): void
    {
        $max = (int) config('uazapi.max_message_length', 1000);
        if (mb_strlen($message) > $max) {
            throw new \InvalidArgumentException(
                'Mensagem WhatsApp excede '.$max.' caracteres ('.mb_strlen($message).' após substituição).'
            );
        }
    }
}
