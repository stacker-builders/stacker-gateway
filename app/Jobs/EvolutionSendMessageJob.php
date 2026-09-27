<?php

namespace App\Jobs;

use App\Exceptions\EvolutionRequestException;
use App\Models\CheckoutSession;
use App\Models\EvolutionInstance;
use App\Models\EvolutionMessageDispatch;
use App\Models\Order;
use App\Services\Evolution\EvolutionAccountResolver;
use App\Services\Evolution\EvolutionClient;
use App\Services\Whatsapp\WhatsappRecoveryGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EvolutionSendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public int $dispatchId)
    {
        $this->tries = (int) config('evolution.retry.tries', 3);
        $this->timeout = (int) config('evolution.retry.timeout', 60);
        $this->onQueue((string) config('evolution.queue', 'uazapi'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $backoff = config('evolution.retry.backoff', [30, 90]);

        return is_array($backoff) ? array_map('intval', $backoff) : [30, 90];
    }

    public function handle(EvolutionClient $client): void
    {
        $dispatch = EvolutionMessageDispatch::query()->with('instance')->find($this->dispatchId);
        if (! $dispatch || $dispatch->status !== EvolutionMessageDispatch::STATUS_PENDING) {
            return;
        }

        if ($this->shouldAbort($dispatch)) {
            $dispatch->update([
                'status' => EvolutionMessageDispatch::STATUS_CANCELED,
                'error' => $this->abortReason($dispatch),
            ]);

            return;
        }

        $instance = $dispatch->instance;
        if (! $instance instanceof EvolutionInstance || ! $instance->canSendRecovery()) {
            $capability = $dispatch->event_type === EvolutionInstance::EVENT_CART_RECOVERY
                ? EvolutionAccountResolver::CAPABILITY_CART
                : ($dispatch->event_type === EvolutionInstance::EVENT_PIX_GENERATED
                    ? EvolutionAccountResolver::CAPABILITY_PIX
                    : EvolutionAccountResolver::CAPABILITY_SEND);
            $resolver = app(EvolutionAccountResolver::class);
            $applies = null;
            if ($dispatch->order_id) {
                $order = Order::query()->find($dispatch->order_id);
                if ($order) {
                    $applies = fn (EvolutionInstance $candidate) => $candidate->appliesToOrder($order);
                }
            } elseif ($dispatch->checkout_session_id) {
                $session = CheckoutSession::query()->find($dispatch->checkout_session_id);
                if ($session) {
                    $applies = fn (EvolutionInstance $candidate) => $candidate->appliesToProduct(
                        $session->product_id !== null ? (string) $session->product_id : null
                    );
                }
            }
            $fallback = $instance instanceof EvolutionInstance
                ? $resolver->failover($instance, $capability, $applies)
                : $resolver->resolveForTenant((int) $dispatch->tenant_id, $capability);

            if ($fallback) {
                $dispatch->evolution_instance_id = $fallback->id;
                $dispatch->save();
                $dispatch->setRelation('instance', $fallback);
                $instance = $fallback;
            } else {
                $dispatch->update([
                    'status' => EvolutionMessageDispatch::STATUS_FAILED,
                    'error' => 'Instância Evolution desconectada ou inativa.',
                ]);

                return;
            }
        }

        $client = $client->using($instance);
        $token = (string) $instance->instance_token;
        $name = (string) $instance->instance_name;
        $payload = is_array($dispatch->payload) ? $dispatch->payload : [];

        try {
            $this->assertNumberOnWhatsapp($client, $token, $name, $dispatch->phone);
            $this->sendProductImageIfNeeded($client, $token, $name, $dispatch, $payload);

            $text = $dispatch->message;
            $buttonUrl = trim((string) ($payload['button_url'] ?? ''));
            if ($buttonUrl !== '' && ! str_contains($text, $buttonUrl)) {
                $text = trim($text."\n".$buttonUrl);
            }

            $response = $client->sendText($token, $name, $dispatch->phone, $text);
            $this->sendPixCopyIfNeeded($client, $token, $name, $dispatch, $payload);

            $dispatch->update([
                'status' => EvolutionMessageDispatch::STATUS_SENT,
                'wa_status' => 'Sent',
                'provider_message_id' => $this->extractMessageId($response),
                'sent_at' => now(),
                'error' => null,
            ]);
        } catch (EvolutionRequestException $e) {
            $dispatch->update([
                'status' => $e->retryable ? EvolutionMessageDispatch::STATUS_PENDING : EvolutionMessageDispatch::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('EvolutionSendMessageJob failed', [
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
        $dispatch = EvolutionMessageDispatch::query()->find($this->dispatchId);
        if (! $dispatch || $dispatch->status !== EvolutionMessageDispatch::STATUS_PENDING) {
            return;
        }

        $dispatch->update([
            'status' => EvolutionMessageDispatch::STATUS_FAILED,
            'error' => $exception !== null ? mb_substr($exception->getMessage(), 0, 500) : null,
        ]);
    }

    private function shouldAbort(EvolutionMessageDispatch $dispatch): bool
    {
        if (WhatsappRecoveryGuard::isOptedOut((int) $dispatch->tenant_id, $dispatch->phone)) {
            return true;
        }

        $startedAt = $this->recoveryStartedAt($dispatch);
        if ($startedAt && WhatsappRecoveryGuard::blocks((int) $dispatch->tenant_id, $dispatch->phone, $startedAt)) {
            return true;
        }

        if ($dispatch->event_type === EvolutionInstance::EVENT_CART_RECOVERY && $dispatch->checkout_session_id) {
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

    private function abortReason(EvolutionMessageDispatch $dispatch): string
    {
        if (WhatsappRecoveryGuard::isOptedOut((int) $dispatch->tenant_id, $dispatch->phone)) {
            return 'Número em opt-out — envio cancelado.';
        }

        $startedAt = $this->recoveryStartedAt($dispatch);
        if ($startedAt && WhatsappRecoveryGuard::blocks((int) $dispatch->tenant_id, $dispatch->phone, $startedAt)) {
            return 'Lead respondeu no WhatsApp — envio cancelado.';
        }

        if ($dispatch->event_type === EvolutionInstance::EVENT_CART_RECOVERY) {
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

    private function recoveryStartedAt(EvolutionMessageDispatch $dispatch): ?\DateTimeInterface
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
     */
    private function sendProductImageIfNeeded(
        EvolutionClient $client,
        string $token,
        string $name,
        EvolutionMessageDispatch $dispatch,
        array $payload
    ): void {
        $url = trim((string) ($payload['image_url'] ?? ''));
        if ($url === '') {
            return;
        }

        try {
            $client->sendImage($token, $name, $dispatch->phone, $url);
        } catch (EvolutionRequestException $e) {
            if ($e->retryable) {
                throw $e;
            }

            Log::warning('EvolutionSendMessageJob: falha ao enviar imagem do produto', [
                'dispatch_id' => $dispatch->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendPixCopyIfNeeded(
        EvolutionClient $client,
        string $token,
        string $name,
        EvolutionMessageDispatch $dispatch,
        array $payload
    ): void {
        $pix = trim((string) ($payload['pix_copy'] ?? ''));
        if ($pix === '') {
            return;
        }

        try {
            $client->sendText($token, $name, $dispatch->phone, $pix);
        } catch (EvolutionRequestException $e) {
            Log::warning('EvolutionSendMessageJob: falha ao enviar copia-e-cola PIX', [
                'dispatch_id' => $dispatch->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function assertNumberOnWhatsapp(EvolutionClient $client, string $token, string $name, string $phone): void
    {
        try {
            $results = $client->checkNumbers($token, $name, [$phone]);
        } catch (EvolutionRequestException $e) {
            if ($e->retryable) {
                throw $e;
            }

            return;
        }

        $first = $results[0] ?? null;
        if (! is_array($first)) {
            return;
        }

        $exists = $first['exists'] ?? $first['numberExists'] ?? null;
        if ($exists === false) {
            throw new EvolutionRequestException('Este número não está no WhatsApp.', 422, false);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function extractMessageId(array $response): ?string
    {
        $key = $response['key']['id'] ?? $response['messageId'] ?? $response['id'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
