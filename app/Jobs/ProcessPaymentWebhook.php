<?php

namespace App\Jobs;

use App\Events\OrderCancelled;
use App\Events\OrderCompleted;
use App\Events\OrderRejected;
use App\Gateways\GatewayRegistry;
use App\Gateways\MercadoPago\MercadoPagoDriver;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\CajuPay\CajuPaySdkCheckoutService;
use App\Services\PlatformOrderAdminService;
use App\Support\CajuPayCheckoutMetadata;
use App\Support\GatewayPaymentCredentials;
use App\Support\MercadoPagoCredentialCandidates;
use App\Services\EfiPixRecorrenteService;
use App\Services\Versell\VersellPixAutoRenewalService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Tentativas extras cobrem race payment.created → payment.updated no Mercado Pago PIX. */
    public int $tries = 5;

    public int $backoff = 10;

    /**
     * @param  array<string, mixed>  $payload  Optional raw payload for logging/future use.
     */
    public function __construct(
        public string $gatewaySlug,
        public string $transactionId,
        public string $event,
        public string $status,
        public array $payload = []
    ) {
        $this->onQueue((string) config('queue.webhooks_inbound_queue', 'webhooks-inbound'));
    }

    public function handle(): void
    {
        $order = $this->resolveOrderForWebhook();

        if (! $order) {
            Log::info('ProcessPaymentWebhook: order not found for gateway transaction', [
                'gateway' => $this->gatewaySlug,
                'transaction_id' => $this->transactionId,
                'event' => $this->event,
                'status' => $this->status,
            ]);

            return;
        }

        $this->syncMercadoPagoGatewayId($order);

        if ($this->shouldProcessMercadoPagoPaidBranch($order) || $this->isConfirmedPaidWebhook()) {
            $this->processPaidBranch($order);
        }

        if ($this->event === 'order.cancelled' && in_array($this->status, ['cancelled', 'canceled'], true)) {
            if ($order->status === 'pending') {
                if (! $this->reconfirmGatewayStatus($order, ['cancelled'])) {
                    return;
                }
                $order->update(['status' => 'cancelled']);
                event(new OrderCancelled($order));
            }
        }

        $isRejectEvent = in_array($this->event, ['order.rejected', 'payment.rejected'], true)
            || ($this->gatewaySlug === 'cajupay' && $this->event === 'checkout.payment.failed');
        if ($isRejectEvent && in_array($this->status, ['rejected', 'refused', 'failed'], true)) {
            if ($order->status === 'pending') {
                $skipReconfirm = $this->gatewaySlug === 'cajupay' && $this->event === 'checkout.payment.failed';
                // Lina (e outros) mapeiam RJCT → rejected; vários drivers usam cancelled para falha.
                if (! $skipReconfirm && ! $this->reconfirmGatewayStatus($order, ['cancelled', 'rejected'])) {
                    return;
                }
                $order->update(['status' => 'rejected']);
                event(new OrderRejected($order));
            }
        }

        $isDisputeEvent = in_array($this->event, ['order.disputed', 'payment.disputed'], true)
            || ($this->gatewaySlug === 'cajupay' && in_array($this->event, ['checkout.payment.disputed', 'card.payment.disputed'], true));
        if ($isDisputeEvent && in_array($this->status, ['disputed', 'chargeback'], true)) {
            if (in_array($order->status, ['completed', 'pending'], true)) {
                if ($this->gatewaySlug === 'cajupay') {
                    try {
                        app(\App\Services\CajuPay\CajuPayMedService::class)->syncOpenedFromCheckoutDispute($order, [
                            'gateway_event' => $this->event,
                            'status' => $this->status,
                        ]);
                    } catch (\InvalidArgumentException) {
                        //
                    }
                } else {
                    try {
                        PlatformOrderAdminService::markDisputed($order);
                    } catch (\InvalidArgumentException) {
                        //
                    }
                }
            }

            return;
        }

        $isRefundEvent = in_array($this->event, ['order.refunded', 'payment.refunded'], true)
            || ($this->gatewaySlug === 'cajupay' && in_array($this->event, ['checkout.payment.refunded', 'card.payment.refunded', 'pix.payment.refunded'], true));
        if ($isRefundEvent && in_array($this->status, ['refunded', 'refund'], true)) {
            if (in_array($order->status, ['completed', 'disputed', 'refund_pending'], true)) {
                $skipReconfirmRefund = $this->gatewaySlug === 'cajupay'
                    && in_array($this->event, ['checkout.payment.refunded', 'card.payment.refunded', 'pix.payment.refunded'], true);
                if (! $skipReconfirmRefund && ! $this->reconfirmGatewayStatus($order, ['cancelled'])) {
                    return;
                }
                PlatformOrderAdminService::applyGatewayRefund($order);
            }
        }
    }

    private function resolveOrderForWebhook(): ?Order
    {
        $order = Order::where('gateway', $this->gatewaySlug)
            ->where('gateway_id', $this->transactionId)
            ->first();
        if ($order !== null) {
            return $order;
        }

        if ($this->gatewaySlug === 'linaopenx') {
            $order = Order::query()
                ->where('gateway', 'linaopenx')
                ->where(function ($q) {
                    $q->where('metadata->lina_payment_request_id', $this->transactionId)
                        ->orWhere('metadata->payment_request_id', $this->transactionId);
                })
                ->first();
            if ($order !== null) {
                return $order;
            }
        }

        if ($this->gatewaySlug === 'mercadopago') {
            $order = Order::query()
                ->where('gateway', 'mercadopago')
                ->where('metadata->mercadopago_payment_id', $this->transactionId)
                ->first();
            if ($order !== null) {
                return $order;
            }

            $externalReference = trim((string) ($this->payload['external_reference'] ?? ''));
            if ($externalReference !== '' && ctype_digit($externalReference)) {
                $order = Order::query()
                    ->where('gateway', 'mercadopago')
                    ->where('id', (int) $externalReference)
                    ->first();
                if ($order !== null) {
                    return $order;
                }
            }
        }

        if ($this->gatewaySlug !== 'cajupay') {
            return null;
        }
        $tid = $this->transactionId;

        return Order::query()
            ->where(function ($q) use ($tid) {
                $q->where('metadata->cajupay_checkout_session_id', $tid)
                    ->orWhere('metadata->cajupay_session_token', $tid)
                    ->orWhere('metadata->cajupay_sdk_token', $tid)
                    ->orWhere('metadata->cajupay_payment_id', $tid);
            })
            ->first();
    }

    /**
     * Pagamento confirmado: formato comum `order.paid` ou Stripe `payment_intent.succeeded` (com status mapeado para paid).
     */
    private function isConfirmedPaidWebhook(): bool
    {
        if ($this->status !== 'paid') {
            return false;
        }
        if ($this->event === 'order.paid') {
            return true;
        }
        if ($this->gatewaySlug === 'stripe' && $this->event === 'payment_intent.succeeded') {
            return true;
        }
        if ($this->gatewaySlug === 'linaopenx' && in_array($this->event, [
            'order.paid',
            'payment.paid',
        ], true)) {
            return true;
        }
        if ($this->gatewaySlug === 'cajupay' && in_array($this->event, [
            'checkout.payment.paid',
            'payment.paid',
            'pix.payment.paid',
            'card.payment.succeeded',
        ], true)) {
            return true;
        }
        if ($this->gatewaySlug === 'mercadopago' && $this->status === 'paid' && in_array($this->event, [
            'order.paid',
            'payment.updated',
            'payment.created',
        ], true)) {
            return true;
        }
        if ($this->gatewaySlug === 'paypal' && $this->event === 'PAYMENT.CAPTURE.COMPLETED') {
            return true;
        }

        return false;
    }

    private function shouldProcessMercadoPagoPaidBranch(Order $order): bool
    {
        if ($this->gatewaySlug !== 'mercadopago' || $order->status !== 'pending') {
            return false;
        }

        if ($this->status === 'paid' && $this->isConfirmedPaidWebhook()) {
            return true;
        }

        return in_array($this->event, ['payment.updated', 'payment.created'], true);
    }

    private function syncMercadoPagoGatewayId(Order $order): void
    {
        if ($this->gatewaySlug !== 'mercadopago') {
            return;
        }

        $paymentId = trim($this->transactionId);
        if ($paymentId === '' || $order->gateway_id === $paymentId) {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['mercadopago_payment_id'] = $paymentId;

        $order->update([
            'gateway_id' => $paymentId,
            'metadata' => $meta,
        ]);
        $order->refresh();
    }

    private function processPaidBranch(Order $order): void
    {
        $lockKey = 'webhook_processing.'.$this->gatewaySlug.'.'.$this->transactionId;
        if (! Cache::add($lockKey, true, now()->addMinutes(5))) {
            Log::info('ProcessPaymentWebhook: paid branch skipped (concurrent lock)', [
                'order_id' => $order->id,
                'gateway' => $this->gatewaySlug,
                'transaction_id' => $this->transactionId,
                'event' => $this->event,
                'attempt' => $this->attempts(),
            ]);

            // Outro job (ex.: payment.created) segura o lock — reenfileira em vez de descartar o approval.
            $this->releasePaidBranchForRetry(5, 'concurrent_lock', $order);

            return;
        }

        try {
            if ($order->status === 'completed') {
                Log::info('ProcessPaymentWebhook: paid branch skipped (order already completed)', [
                    'order_id' => $order->id,
                    'gateway' => $this->gatewaySlug,
                    'transaction_id' => $this->transactionId,
                    'event' => $this->event,
                ]);

                return;
            }

            $apiStatus = $this->fetchGatewayTransactionStatus($order);
            $trustedCajuCheckoutWebhook = $this->gatewaySlug === 'cajupay'
                && ($this->payload['webhook_source'] ?? '') !== ''
                && in_array($this->event, [
                    'checkout.payment.paid',
                    'payment.paid',
                    'pix.payment.paid',
                    'card.payment.succeeded',
                ], true);
            if ($apiStatus !== 'paid' && $trustedCajuCheckoutWebhook) {
                $apiStatus = 'paid';
            }
            // Mercado Pago: NUNCA liberar só pelo webhook/evento. Fonte de verdade = GET /v1/payments/{id}
            // (status approved). Se a API falhar (null) ou ainda estiver pending → retry; rejected → não liberar.
            if ($apiStatus !== 'paid') {
                Log::warning('ProcessPaymentWebhook: paid branch aborted (gateway reconfirm not paid)', [
                    'order_id' => $order->id,
                    'gateway' => $this->gatewaySlug,
                    'transaction_id' => $this->transactionId,
                    'event' => $this->event,
                    'api_status' => $apiStatus,
                    'attempt' => $this->attempts(),
                ]);

                if ($this->shouldRetryMercadoPagoReconfirm($apiStatus)) {
                    $this->releasePaidBranchForRetry(
                        $this->mercadoPagoReconfirmDelaySeconds(),
                        $apiStatus === null ? 'reconfirm_api_unavailable' : 'reconfirm_pending',
                        $order,
                        $apiStatus
                    );
                } elseif ($this->gatewaySlug === 'mercadopago' && $apiStatus === 'cancelled' && $order->status === 'pending') {
                    // rejected/cancelled confirmado na API — não liberar; espelha a carteira do comprador.
                    $order->update(['status' => 'rejected']);
                    event(new OrderRejected($order));
                } elseif ($this->gatewaySlug === 'linaopenx' && ($apiStatus === null || $apiStatus === 'pending')) {
                    $this->releasePaidBranchForRetry(10, 'lina_reconfirm_pending', $order, $apiStatus);
                } elseif ($this->gatewaySlug === 'bspay' && ($apiStatus === null || $apiStatus === 'pending')) {
                    $this->releasePaidBranchForRetry(15, 'bspay_reconfirm_pending', $order, $apiStatus);
                } elseif ($this->gatewaySlug === 'versell' && $order->payment_method === 'pix_auto' && ($apiStatus === null || $apiStatus === 'pending')) {
                    $this->releasePaidBranchForRetry(10, 'versell_pix_auto_reconfirm', $order, $apiStatus);
                }

                return;
            }

            if ($this->gatewaySlug === 'mercadopago') {
                $this->syncMercadoPagoGatewayId($order);
            }

            $completedPatch = ['status' => 'completed'];
            if ($order->payment_method === null || $order->payment_method === '') {
                $completedPatch['payment_method'] = $this->inferPaymentMethodForOrder($order);
            }
            $order->update($completedPatch);

            $createExtra = [];
            $metadata = is_array($order->metadata) ? $order->metadata : [];
            if (isset($metadata['efi_pix_auto_id_rec']) && $this->gatewaySlug === 'efi') {
                $createExtra['gateway_subscription_id'] = $metadata['efi_pix_auto_id_rec'];
            } elseif (isset($metadata['versell_pix_auto_id_rec']) && $this->gatewaySlug === 'versell') {
                $createExtra['gateway_subscription_id'] = $metadata['versell_pix_auto_id_rec'];
            } elseif (isset($metadata['pushinpay_subscription_id']) && $this->gatewaySlug === 'pushinpay') {
                $createExtra['gateway_subscription_id'] = $metadata['pushinpay_subscription_id'];
            }

            $sync = app(\App\Services\SubscriptionRenewalService::class)->syncFromPaidOrder($order, $createExtra);
            $subscription = $sync['subscription'] ?? null;
            if ($subscription) {
                if (! empty($sync['renewed']) && $this->gatewaySlug === 'versell') {
                    $this->scheduleVersellPixAutoNextCobr($order, $subscription);
                } elseif (! empty($sync['created']) && ! empty($createExtra['gateway_subscription_id'])) {
                    $plan = $order->subscriptionPlan ?? $subscription->subscriptionPlan;
                    if ($this->gatewaySlug === 'efi' && $plan) {
                        $this->createEfiPixAutoCobrForNextPeriod($order, $subscription, $plan);
                    } elseif ($this->gatewaySlug === 'versell') {
                        $this->scheduleVersellPixAutoNextCobr($order, $subscription);
                    }
                }
            }

            event(new OrderCompleted($order->fresh()));
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Mercado Pago: payment.created costuma chegar com status ainda pending na API.
     * Também retenta quando a consulta à API falhou (null) — nunca liberar sem approved.
     */
    private function shouldRetryMercadoPagoReconfirm(?string $apiStatus): bool
    {
        if ($this->gatewaySlug !== 'mercadopago') {
            return false;
        }

        if (! $this->isTrustedMercadoPagoSource()) {
            return false;
        }

        // null = API indisponível / credencial errada — retry, não liberar.
        if ($apiStatus === null) {
            return true;
        }

        return in_array($apiStatus, ['pending', 'in_process', 'in_mediation'], true);
    }

    private function mercadoPagoReconfirmDelaySeconds(): int
    {
        $attempt = max(1, $this->attempts());

        return match (true) {
            $attempt <= 1 => 5,
            $attempt === 2 => 15,
            $attempt === 3 => 30,
            default => 60,
        };
    }

    private function releasePaidBranchForRetry(
        int $delaySeconds,
        string $reason,
        Order $order,
        ?string $apiStatus = null
    ): void {
        if ($this->attempts() >= $this->tries) {
            Log::warning('ProcessPaymentWebhook: paid branch retries exhausted', [
                'order_id' => $order->id,
                'gateway' => $this->gatewaySlug,
                'transaction_id' => $this->transactionId,
                'event' => $this->event,
                'reason' => $reason,
                'api_status' => $apiStatus,
                'attempt' => $this->attempts(),
                'tries' => $this->tries,
            ]);

            return;
        }

        Log::info('ProcessPaymentWebhook: releasing paid branch for retry', [
            'order_id' => $order->id,
            'gateway' => $this->gatewaySlug,
            'transaction_id' => $this->transactionId,
            'event' => $this->event,
            'reason' => $reason,
            'api_status' => $apiStatus,
            'delay' => $delaySeconds,
            'attempt' => $this->attempts(),
        ]);

        $this->release($delaySeconds);
    }

    private function isTrustedMercadoPagoSource(): bool
    {
        $source = (string) ($this->payload['webhook_source'] ?? $this->payload['source'] ?? '');

        return in_array($source, [
            'mercadopago_webhook',
            'reconcile_pending',
            'reconcile_mercadopago',
            'order_status_poll',
        ], true);
    }

    private function fetchGatewayTransactionStatus(Order $order): ?string
    {
        if ($this->gatewaySlug === 'cajupay') {
            $credentials = GatewayPaymentCredentials::resolve($order->tenant_id, $this->gatewaySlug, $order);
            if ($credentials === null) {
                return null;
            }
            $publicToken = CajuPayCheckoutMetadata::publicSessionToken($order) ?? '';
            if ($publicToken !== '') {
                $fromSdk = app(CajuPaySdkCheckoutService::class)->getPublicSessionStatus($publicToken, $credentials);
                if ($fromSdk === 'paid' || $fromSdk === 'cancelled') {
                    return $fromSdk;
                }
                // Sessão "pending/active" não é definitiva — continua no driver/PIX abaixo.
            }
        }

        $driver = GatewayRegistry::driver($this->gatewaySlug);
        if (! $driver) {
            return null;
        }

        if ($this->gatewaySlug === 'mercadopago' && $driver instanceof MercadoPagoDriver) {
            $paymentId = trim($this->transactionId);
            $lastStatus = null;
            foreach (MercadoPagoCredentialCandidates::forOrder($order) as $candidate) {
                $credentials = $candidate['credentials'];
                if ($paymentId !== '') {
                    $apiStatus = $driver->getTransactionStatus($paymentId, $credentials);
                    $lastStatus = $apiStatus ?? $lastStatus;
                    if ($apiStatus === 'paid') {
                        return 'paid';
                    }
                }
                $foundId = $driver->findApprovedPaymentByExternalReference((string) $order->id, $credentials);
                if ($foundId !== null) {
                    if ($foundId !== $this->transactionId) {
                        $this->transactionId = $foundId;
                        $this->syncMercadoPagoGatewayId($order);
                    }

                    return 'paid';
                }
            }

            return $lastStatus;
        }

        $credentials = GatewayPaymentCredentials::resolve($order->tenant_id, $this->gatewaySlug, $order);
        if ($credentials === null) {
            return null;
        }

        return $driver->getTransactionStatus($this->transactionId, $credentials);
    }

    /**
     * @param  list<string>  $expectedStatuses  e.g. ['cancelled'] — vários drivers mapeiam refund/rejected para cancelled
     */
    private function reconfirmGatewayStatus(Order $order, array $expectedStatuses): bool
    {
        $apiStatus = $this->fetchGatewayTransactionStatus($order);
        if ($apiStatus === null) {
            return $this->shouldAcceptUnconfirmedDestructive();
        }

        return in_array($apiStatus, $expectedStatuses, true);
    }

    private function shouldAcceptUnconfirmedDestructive(): bool
    {
        $perGateway = config("webhooks.reconfirm_fail_policy.{$this->gatewaySlug}");
        if (is_string($perGateway) && $perGateway !== '') {
            $accept = $perGateway === 'accept';
        } else {
            $accept = config('webhooks.reconfirm_fail_policy.default', 'accept') === 'accept';
        }

        if (! $accept) {
            Log::warning('Webhook cancel/refund/reject skipped: reconfirmation unavailable (policy=reject)', [
                'gateway' => $this->gatewaySlug,
                'transaction_id' => $this->transactionId,
                'event' => $this->event,
            ]);
        }

        return $accept;
    }

    private function createEfiPixAutoCobrForNextPeriod(Order $order, Subscription $subscription, $plan): void
    {
        $credential = GatewayCredential::resolveForPayment($order->tenant_id, 'efi');
        if (! $credential) {
            return;
        }
        $credentials = $credential->getDecryptedCredentials();
        if (empty($credentials['certificate_path'])) {
            return;
        }

        $idRec = $subscription->gateway_subscription_id;
        if ($idRec === null || $idRec === '') {
            return;
        }

        $amount = (float) $plan->price;
        $periodEnd = $subscription->current_period_end;
        $dataDeVencimento = $periodEnd ? $periodEnd->format('Y-m-d') : now()->addMonth()->format('Y-m-d');

        $devedor = [
            'name' => $order->user ? $order->user->name : null ?? $order->email,
            'email' => $order->email,
        ];

        try {
            $service = new EfiPixRecorrenteService($credentials);
            $service->createCobrancaRecorrente(
                $idRec,
                $amount,
                $dataDeVencimento,
                null,
                $devedor,
                'Renovação assinatura - Pedido #' . $order->id
            );
        } catch (\Throwable $e) {
            Log::warning('ProcessPaymentWebhook: falha ao criar cobr PIX automático', [
                'order_id' => $order->id,
                'idRec' => $idRec,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function scheduleVersellPixAutoNextCobr(Order $order, Subscription $subscription): void
    {
        try {
            app(VersellPixAutoRenewalService::class)->ensureNextCobr($subscription, $order);
        } catch (\Throwable $e) {
            Log::warning('ProcessPaymentWebhook: falha ao criar cobr PIX automático Versell', [
                'order_id' => $order->id,
                'idRec' => $subscription->gateway_subscription_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function inferPaymentMethodForOrder(Order $order): string
    {
        $meta = is_array($order->metadata ?? null) ? $order->metadata : [];
        $m = $meta['checkout_payment_method'] ?? null;
        if (in_array($m, ['pix', 'card', 'boleto', 'pix_auto', 'open_finance', 'paypal'], true)) {
            return $m;
        }
        $g = (string) ($order->gateway ?? '');
        if ($g === 'stripe' || $g === 'paypal') {
            return $g === 'paypal' ? 'paypal' : 'card';
        }
        if ($g === 'linaopenx') {
            return 'open_finance';
        }

        return 'pix';
    }
}
