<?php

namespace App\Jobs;

use App\Exceptions\UazapiRequestException;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Models\UazapiOptOut;
use App\Models\UazapiRecoveryStop;
use App\Services\Uazapi\UazapiAccountResolver;
use App\Services\Uazapi\UazapiClient;
use App\Services\Uazapi\UazapiLabelService;
use App\Services\Whatsapp\WhatsappRecoveryGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UazapiSendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public int $dispatchId)
    {
        $this->tries = (int) config('uazapi.retry.tries', 3);
        $this->timeout = (int) config('uazapi.retry.timeout', 60);
        $this->onQueue((string) config('uazapi.queue', 'uazapi'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('uazapi.retry.backoff', [30, 90]);

        return is_array($backoff) ? array_map('intval', $backoff) : [30, 90];
    }

    public function handle(UazapiClient $client): void
    {
        $dispatch = UazapiMessageDispatch::query()->with('instance')->find($this->dispatchId);
        if (! $dispatch || $dispatch->status !== UazapiMessageDispatch::STATUS_PENDING) {
            return;
        }

        if ($this->shouldAbort($dispatch)) {
            $dispatch->update([
                'status' => UazapiMessageDispatch::STATUS_CANCELED,
                'error' => $this->abortReason($dispatch),
            ]);

            return;
        }

        $instance = $dispatch->instance;
        if (! $instance instanceof UazapiInstance || ! $instance->canSendRecovery()) {
            $capability = match ($dispatch->event_type) {
                UazapiInstance::EVENT_CART_RECOVERY => UazapiAccountResolver::CAPABILITY_CART,
                UazapiInstance::EVENT_PIX_GENERATED => UazapiAccountResolver::CAPABILITY_PIX,
                UazapiInstance::EVENT_ORDER_PAID => UazapiAccountResolver::CAPABILITY_ORDER_PAID,
                default => UazapiAccountResolver::CAPABILITY_SEND,
            };
            $resolver = app(UazapiAccountResolver::class);
            $applies = null;
            if ($dispatch->order_id) {
                $order = Order::query()->find($dispatch->order_id);
                if ($order) {
                    $applies = fn (UazapiInstance $candidate) => $candidate->appliesToOrder($order);
                }
            } elseif ($dispatch->checkout_session_id) {
                $session = CheckoutSession::query()->find($dispatch->checkout_session_id);
                if ($session) {
                    $applies = fn (UazapiInstance $candidate) => $candidate->appliesToProduct(
                        $session->product_id !== null ? (string) $session->product_id : null
                    );
                }
            }
            $fallback = $instance instanceof UazapiInstance
                ? $resolver->failover($instance, $capability, $applies)
                : $resolver->resolveForTenant((int) $dispatch->tenant_id, $capability);

            if ($fallback) {
                $dispatch->uazapi_instance_id = $fallback->id;
                $dispatch->save();
                $dispatch->setRelation('instance', $fallback);
                $instance = $fallback;
            } else {
                $dispatch->update([
                    'status' => UazapiMessageDispatch::STATUS_FAILED,
                    'error' => 'Instância WhatsApp desconectada ou inativa.',
                ]);

                return;
            }
        }

        $token = (string) $instance->instance_token;
        $client = $client->using($instance);
        $payload = is_array($dispatch->payload) ? $dispatch->payload : [];
        $track = [
            'track_source' => 'stacker-recovery',
            'track_id' => (string) ($dispatch->track_id ?: 'uazapi-dispatch-'.$dispatch->id),
        ];

        try {
            $this->assertNumberOnWhatsapp($client, $token, $dispatch->phone);
            $this->assertWithinWhatsappLimits($client, $token);
            $this->sendProductImageIfNeeded($client, $token, $dispatch, $payload, $track);
            $response = $this->sendPrimary($client, $token, $dispatch, $payload, $track);
            $this->sendPixCopyIfNeeded($client, $token, $dispatch, $payload, $track);
            $this->applyLabelIfNeeded($dispatch, $payload);

            $messageId = $this->extractMessageId($response);

            $dispatch->update([
                'status' => UazapiMessageDispatch::STATUS_SENT,
                'wa_status' => 'Sent',
                'provider_message_id' => $messageId,
                'sent_at' => now(),
                'error' => null,
            ]);
        } catch (UazapiRequestException $e) {
            $dispatch->update([
                'status' => $e->retryable ? UazapiMessageDispatch::STATUS_PENDING : UazapiMessageDispatch::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('UazapiSendMessageJob failed', [
                'dispatch_id' => $dispatch->id,
                'retryable' => $e->retryable,
                'status' => $e->status,
                'message' => $e->getMessage(),
            ]);

            if ($e->retryable) {
                throw $e;
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $dispatch = UazapiMessageDispatch::query()->find($this->dispatchId);
        if (! $dispatch || $dispatch->status !== UazapiMessageDispatch::STATUS_PENDING) {
            return;
        }

        $dispatch->update([
            'status' => UazapiMessageDispatch::STATUS_FAILED,
            'error' => $exception !== null ? mb_substr($exception->getMessage(), 0, 500) : null,
        ]);
    }

    private function shouldAbort(UazapiMessageDispatch $dispatch): bool
    {
        if (UazapiOptOut::isOptedOut((int) $dispatch->tenant_id, $dispatch->phone)) {
            return true;
        }

        if ($dispatch->event_type === UazapiInstance::EVENT_CAMPAIGN) {
            return false;
        }

        // Confirmação de PIX pago: não aborta por resposta na recuperação nem por status completed.
        if ($dispatch->event_type === UazapiInstance::EVENT_ORDER_PAID) {
            if (! $dispatch->order_id) {
                return false;
            }

            $order = Order::query()->find($dispatch->order_id);

            return $order !== null && in_array($order->status, ['refunded', 'chargeback', 'cancelled', 'canceled'], true);
        }

        $startedAt = $this->recoveryStartedAt($dispatch);
        if ($startedAt && UazapiRecoveryStop::blocks((int) $dispatch->tenant_id, $dispatch->phone, $startedAt)) {
            return true;
        }

        if ($dispatch->event_type === UazapiInstance::EVENT_CART_RECOVERY && $dispatch->checkout_session_id) {
            $session = CheckoutSession::query()->find($dispatch->checkout_session_id);
            if ($session !== null && $session->order_id !== null) {
                return true;
            }

            return WhatsappRecoveryGuard::alreadyOwnsProduct(
                (int) $dispatch->tenant_id,
                $dispatch->phone,
                $session?->product_id
            );
        }

        if ($dispatch->order_id) {
            $order = Order::query()->find($dispatch->order_id);

            return $order !== null && in_array($order->status, ['completed', 'refunded', 'chargeback', 'cancelled', 'canceled'], true);
        }

        return false;
    }

    private function abortReason(UazapiMessageDispatch $dispatch): string
    {
        if (UazapiOptOut::isOptedOut((int) $dispatch->tenant_id, $dispatch->phone)) {
            return 'Número em opt-out — envio cancelado.';
        }

        if ($dispatch->event_type === UazapiInstance::EVENT_ORDER_PAID) {
            return 'Pedido reembolsado ou cancelado — confirmação não enviada.';
        }

        $startedAt = $this->recoveryStartedAt($dispatch);
        if ($startedAt && UazapiRecoveryStop::blocks((int) $dispatch->tenant_id, $dispatch->phone, $startedAt)) {
            return 'Lead respondeu no WhatsApp — envio cancelado.';
        }

        if ($dispatch->event_type === UazapiInstance::EVENT_CART_RECOVERY) {
            $session = $dispatch->checkout_session_id
                ? CheckoutSession::query()->find($dispatch->checkout_session_id)
                : null;
            if ($session && WhatsappRecoveryGuard::alreadyOwnsProduct(
                (int) $dispatch->tenant_id,
                $dispatch->phone,
                $session->product_id
            )) {
                return 'Cliente já comprou este produto — envio cancelado.';
            }
        }

        return 'Pedido pago ou carrinho convertido — envio cancelado.';
    }

    private function recoveryStartedAt(UazapiMessageDispatch $dispatch): ?\DateTimeInterface
    {
        if ($dispatch->checkout_session_id) {
            $session = CheckoutSession::query()->find($dispatch->checkout_session_id);
            if ($session?->created_at) {
                return $session->created_at;
            }
        }

        if ($dispatch->order_id) {
            $order = Order::query()->find($dispatch->order_id);
            if ($order?->created_at) {
                return $order->created_at;
            }
        }

        return $dispatch->created_at;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $track
     * @return array<string, mixed>
     */
    private function sendPrimary(
        UazapiClient $client,
        string $token,
        UazapiMessageDispatch $dispatch,
        array $payload,
        array $track
    ): array {
        $buttonUrl = trim((string) ($payload['button_url'] ?? ''));
        $buttonLabel = trim((string) ($payload['button_label'] ?? 'Abrir checkout'));

        if ($buttonUrl !== '') {
            try {
                return $client->sendMenu($token, [
                    'number' => $dispatch->phone,
                    'type' => 'button',
                    'text' => $dispatch->message,
                    'choices' => [$buttonLabel.'|'.$buttonUrl],
                    ...$track,
                ]);
            } catch (UazapiRequestException $e) {
                if ($e->retryable) {
                    throw $e;
                }
            }
        }

        $text = $dispatch->message;
        if ($buttonUrl !== '' && ! str_contains($text, $buttonUrl)) {
            $text = trim($text."\n".$buttonUrl);
        }

        return $client->sendText($token, [
            'number' => $dispatch->phone,
            'text' => $text,
            ...$track,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $track
     */
    private function sendProductImageIfNeeded(
        UazapiClient $client,
        string $token,
        UazapiMessageDispatch $dispatch,
        array $payload,
        array $track
    ): void {
        $url = trim((string) ($payload['image_url'] ?? ''));
        if ($url === '') {
            return;
        }

        try {
            $client->sendMedia($token, [
                'number' => $dispatch->phone,
                'type' => 'image',
                'file' => $url,
                ...$track,
            ]);
        } catch (UazapiRequestException $e) {
            if ($e->retryable) {
                throw $e;
            }

            Log::warning('UazapiSendMessageJob: falha ao enviar imagem do produto', [
                'dispatch_id' => $dispatch->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyLabelIfNeeded(UazapiMessageDispatch $dispatch, array $payload): void
    {
        $key = trim((string) ($payload['label'] ?? ''));
        if ($key === '') {
            $key = match ($dispatch->event_type) {
                UazapiInstance::EVENT_CART_RECOVERY, UazapiInstance::EVENT_PIX_GENERATED => UazapiLabelService::ABANDONED,
                default => '',
            };
        }
        if ($key === '') {
            return;
        }

        $instance = $dispatch->instance;
        if (! $instance instanceof UazapiInstance) {
            return;
        }

        try {
            app(UazapiLabelService::class)->apply($instance, $dispatch->phone, $key);
        } catch (\Throwable $e) {
            Log::debug('UazapiSendMessageJob: etiqueta ignorada', [
                'dispatch_id' => $dispatch->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $track
     */
    private function sendPixCopyIfNeeded(
        UazapiClient $client,
        string $token,
        UazapiMessageDispatch $dispatch,
        array $payload,
        array $track
    ): void {
        $pix = trim((string) ($payload['pix_copy'] ?? ''));
        if ($pix === '') {
            return;
        }

        try {
            $client->sendText($token, [
                'number' => $dispatch->phone,
                'text' => $pix,
                ...$track,
            ]);
        } catch (UazapiRequestException $e) {
            Log::warning('UazapiSendMessageJob: falha ao enviar copia-e-cola PIX', [
                'dispatch_id' => $dispatch->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function assertNumberOnWhatsapp(UazapiClient $client, string $token, string $phone): void
    {
        try {
            $results = $client->checkChats($token, [$phone]);
        } catch (UazapiRequestException $e) {
            if ($e->retryable) {
                throw $e;
            }

            return;
        }

        $first = $results[0] ?? null;
        if (! is_array($first)) {
            return;
        }

        if (array_key_exists('isInWhatsapp', $first) && $first['isInWhatsapp'] === false) {
            throw new UazapiRequestException('Este número não está no WhatsApp.', 400, false);
        }
    }

    private function assertWithinWhatsappLimits(UazapiClient $client, string $token): void
    {
        try {
            $limits = $client->messageLimits($token);
        } catch (UazapiRequestException $e) {
            if ($e->retryable) {
                throw $e;
            }

            return;
        }

        $canSend = $limits['canSend']
            ?? $limits['can_send']
            ?? $limits['can_send_new_messages']
            ?? $limits['canSendNewMessages']
            ?? true;

        if ($canSend === false || $canSend === 0 || $canSend === '0') {
            throw new UazapiRequestException('Limite de novas conversas do WhatsApp atingido. O envio será tentado de novo.', 429, true);
        }

        $remaining = $limits['remaining']
            ?? $limits['remainingNewConversations']
            ?? $limits['remaining_new_conversations']
            ?? null;

        if (is_numeric($remaining) && (int) $remaining <= 0) {
            throw new UazapiRequestException('Limite de novas conversas do WhatsApp atingido. O envio será tentado de novo.', 429, true);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractMessageId(array $response): ?string
    {
        foreach (['id', 'messageid'] as $key) {
            if (! empty($response[$key]) && is_scalar($response[$key])) {
                return (string) $response[$key];
            }
        }

        $message = $response['message'] ?? $response['data'] ?? null;
        if (is_array($message)) {
            foreach (['id', 'messageid'] as $key) {
                if (! empty($message[$key]) && is_scalar($message[$key])) {
                    return (string) $message[$key];
                }
            }
        }

        return null;
    }
}
