<?php

namespace App\Services\Evolution;

use App\Jobs\EvolutionSendMessageJob;
use App\Models\CheckoutSession;
use App\Models\EvolutionInstance;
use App\Models\EvolutionMessageDispatch;
use App\Models\Order;
use App\Models\Product;
use App\Services\Uazapi\UazapiMessageBuilder;
use App\Services\Uazapi\UazapiProductMedia;
use App\Services\Whatsapp\WhatsappRecoveryGuard;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

class EvolutionDispatcher
{
    public function __construct(
        private EvolutionClient $client,
        private UazapiMessageBuilder $messageBuilder,
    ) {}

    /**
     * @param  array<string, string>  $vars
     */
    public function dispatchCartRecoveryStep(
        EvolutionInstance $instance,
        CheckoutSession $session,
        int $stepIndex,
        string $template,
        array $vars,
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

        if (WhatsappRecoveryGuard::sessionTaken((int) $session->id, EvolutionInstance::EVENT_CART_RECOVERY, $stepIndex)) {
            return false;
        }

        $message = $this->messageBuilder->render($template, $vars);
        $this->assertMessageLength($message);
        $session->loadMissing('product');

        return $this->queueDispatch(
            instance: $instance,
            eventType: EvolutionInstance::EVENT_CART_RECOVERY,
            phone: $phone,
            message: $message,
            tenantId: (int) $session->tenant_id,
            checkoutSessionId: $session->id,
            orderId: null,
            sequenceStep: $stepIndex,
            extra: [
                'button_url' => $vars['link'] ?? '',
                ...$this->imageExtra($instance, $session->product),
            ],
        );
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function dispatchPixGenerated(
        EvolutionInstance $instance,
        Order $order,
        string $phone,
        array $vars,
    ): bool {
        return $this->dispatchPixRecoveryStep(
            $instance,
            $order,
            $phone,
            0,
            $instance->pixMessageTemplate(),
            $vars,
        );
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function dispatchPixRecoveryStep(
        EvolutionInstance $instance,
        Order $order,
        string $phone,
        int $stepIndex,
        string $template,
        array $vars,
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

        if (WhatsappRecoveryGuard::orderStepTaken((int) $order->id, EvolutionInstance::EVENT_PIX_GENERATED, $stepIndex)) {
            return false;
        }

        $message = $this->messageBuilder->render($template, $vars);
        $this->assertMessageLength($message);
        $order->loadMissing('product');

        return $this->queueDispatch(
            instance: $instance,
            eventType: EvolutionInstance::EVENT_PIX_GENERATED,
            phone: $normalized,
            message: $message,
            tenantId: (int) $order->tenant_id,
            checkoutSessionId: null,
            orderId: (int) $order->id,
            sequenceStep: $stepIndex,
            extra: [
                'button_url' => $vars['link'] ?? '',
                'pix_copy' => $vars['pix'] ?? '',
                ...$this->imageExtra($instance, $order->product),
            ],
        );
    }

    /**
     * Confirmação enviada quando o PIX do pedido é pago.
     *
     * @param  array<string, string>  $vars
     */
    public function dispatchOrderPaid(
        EvolutionInstance $instance,
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

        if (WhatsappRecoveryGuard::orderStepTaken((int) $order->id, EvolutionInstance::EVENT_ORDER_PAID, 0)) {
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
            eventType: EvolutionInstance::EVENT_ORDER_PAID,
            phone: $normalized,
            message: $message,
            tenantId: (int) $order->tenant_id,
            checkoutSessionId: null,
            orderId: (int) $order->id,
            sequenceStep: 0,
            extra: [
                'button_url' => $accessUrl !== '' ? $accessUrl : (string) ($vars['link'] ?? ''),
                ...$this->imageExtra($instance, $order->product),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function queueDispatch(
        EvolutionInstance $instance,
        string $eventType,
        string $phone,
        string $message,
        int $tenantId,
        ?int $checkoutSessionId,
        ?int $orderId,
        ?int $sequenceStep,
        array $extra = [],
    ): bool {
        $dispatch = EvolutionMessageDispatch::query()->create([
            'tenant_id' => $tenantId,
            'evolution_instance_id' => $instance->id,
            'checkout_session_id' => $checkoutSessionId,
            'order_id' => $orderId,
            'event_type' => $eventType,
            'sequence_step' => $sequenceStep,
            'phone' => $phone,
            'message' => $message,
            'payload' => $extra,
            'status' => EvolutionMessageDispatch::STATUS_PENDING,
        ]);

        $dispatch->track_id = 'evolution-dispatch-'.$dispatch->id;
        $dispatch->save();
        $instance->forceFill(['last_used_at' => now()])->save();

        EvolutionSendMessageJob::dispatch($dispatch->id)->onQueue((string) config('evolution.queue', 'uazapi'));

        Log::info('EvolutionDispatcher: mensagem enfileirada', [
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
    private function imageExtra(EvolutionInstance $instance, mixed $product): array
    {
        if (! $instance->send_product_image || ! $product instanceof Product) {
            return [];
        }

        $url = UazapiProductMedia::publicUrl($product);

        return $url ? ['image_url' => $url] : [];
    }

    private function assertMessageLength(string $message): void
    {
        $max = (int) config('evolution.max_message_length', 1000);
        if (mb_strlen($message) > $max) {
            throw new \InvalidArgumentException(
                'Mensagem WhatsApp excede '.$max.' caracteres ('.mb_strlen($message).' após substituição).'
            );
        }
    }
}
