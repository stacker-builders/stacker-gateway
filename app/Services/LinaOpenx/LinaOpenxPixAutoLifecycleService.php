<?php

namespace App\Services\LinaOpenx;

use App\Events\OrderCancelled;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cancelamento remoto, retentativa e reconciliação do PIX automático OpenX.
 */
class LinaOpenxPixAutoLifecycleService
{
    public const SKIP_REMOTE_CANCEL_TTL_SECONDS = 120;

    public const MAX_RETRIES = 3;

    public function __construct(
        private readonly LinaOpenxClient $client = new LinaOpenxClient(),
        private readonly LinaOpenxPixAutoService $pixAuto = new LinaOpenxPixAutoService()
    ) {}

    public static function skipRemoteCancelKey(string $consentId): string
    {
        return 'linaopenx_pix_auto_skip_remote_cancel:'.$consentId;
    }

    public static function isLinaSubscription(Subscription $subscription): bool
    {
        $consentId = trim((string) ($subscription->gateway_subscription_id ?? ''));
        if ($consentId === '') {
            return false;
        }

        $gateway = Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('payment_method', 'pix_auto')
            ->where('gateway', LinaOpenxPixAutoService::GATEWAY_SLUG)
            ->orderByDesc('id')
            ->value('gateway');

        return $gateway === LinaOpenxPixAutoService::GATEWAY_SLUG;
    }

    public function cancelRemote(Subscription $subscription): void
    {
        if (! self::isLinaSubscription($subscription)) {
            return;
        }

        $consentId = trim((string) $subscription->gateway_subscription_id);
        if (Cache::get(self::skipRemoteCancelKey($consentId))) {
            return;
        }

        $credentials = $this->pixAuto->credentialsForTenant($subscription->tenant_id);
        if ($credentials === null) {
            Log::info('LinaOpenxPixAutoLifecycleService: sem credencial para revogar consent', [
                'subscription_id' => $subscription->id,
                'consent_id' => $consentId,
            ]);

            return;
        }

        try {
            $this->client->revokeRecurringConsent($credentials, $consentId);
        } catch (\Throwable $e) {
            Log::warning('LinaOpenxPixAutoLifecycleService: falha ao revogar consent', [
                'subscription_id' => $subscription->id,
                'consent_id' => $consentId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }

        $pending = Order::query()
            ->where('gateway', LinaOpenxPixAutoService::GATEWAY_SLUG)
            ->where('payment_method', 'pix_auto')
            ->where('status', 'pending')
            ->where(function ($q) use ($consentId, $subscription) {
                $q->where('metadata->linaopenx_pix_auto_consent_id', $consentId)
                    ->orWhere(function ($q2) use ($subscription) {
                        $q2->where('user_id', $subscription->user_id)
                            ->where('product_id', $subscription->product_id)
                            ->where('subscription_plan_id', $subscription->subscription_plan_id)
                            ->where('is_renewal', true);
                    });
            })
            ->get();

        $cpf = preg_replace('/\D+/', '', (string) ($subscription->user?->document ?? '')) ?? '';
        foreach ($pending as $order) {
            $paymentId = trim((string) (($order->metadata['linaopenx_pix_auto_recurring_payment_id'] ?? null) ?: $order->gateway_id));
            if ($paymentId !== '' && strlen($cpf) >= 11) {
                try {
                    $this->client->cancelRecurringPayment($credentials, $paymentId, [
                        'cpfCancelledBy' => $cpf,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('LinaOpenxPixAutoLifecycleService: falha ao cancelar débito', [
                        'order_id' => $order->id,
                        'payment_id' => $paymentId,
                        'error' => mb_substr($e->getMessage(), 0, 300),
                    ]);
                }
            }
            if ($order->status === 'pending') {
                $order->update(['status' => 'cancelled']);
                event(new OrderCancelled($order));
            }
        }
    }

    public function retryFailedPayment(Order $order): bool
    {
        if ($order->gateway !== LinaOpenxPixAutoService::GATEWAY_SLUG
            || $order->payment_method !== 'pix_auto'
            || $order->status !== 'pending') {
            return false;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $paymentId = trim((string) ($meta['linaopenx_pix_auto_recurring_payment_id'] ?? $order->gateway_id ?? ''));
        if ($paymentId === '') {
            return false;
        }

        $retries = (int) ($meta['linaopenx_pix_auto_retry_count'] ?? 0);
        if ($retries >= self::MAX_RETRIES) {
            return false;
        }

        $credentials = $this->pixAuto->credentialsForOrder($order);
        if ($credentials === null) {
            return false;
        }

        $creditor = $this->client->creditorPayload($credentials);
        $ispb = is_array($creditor) ? (string) ($creditor['accountIspb'] ?? '00000000') : '00000000';

        try {
            $current = $this->client->getRecurringPayment($credentials, $paymentId);
            $status = strtoupper(trim((string) ($current['payment_status'] ?? $current['status'] ?? '')));
            if (in_array($status, ['PAGO', 'PAID', 'ACSC', 'ACSP', 'SETTLED', 'COMPLETED', 'paid'], true)) {
                return false;
            }
            if (! in_array($status, ['RJCT', 'REJECTED', 'REJEITADO', 'FAILED', 'EXPIRED', 'EXPIRADO', 'rejected', 'cancelled'], true)) {
                return false;
            }

            $retry = $this->client->retryRecurringPayment($credentials, $paymentId, [
                'endToEndId' => $this->makeEndToEndId($ispb),
                'date' => now()->addDays(LinaOpenxPixAutoRenewalService::MIN_DAYS_BEFORE_DUE)->toDateString(),
            ]);
            $meta['linaopenx_pix_auto_retry_count'] = $retries + 1;
            $meta['linaopenx_pix_auto_retry_at'] = now()->toIso8601String();
            if (! empty($retry['recurring_payment_id'])) {
                $meta['linaopenx_pix_auto_recurring_payment_id'] = $retry['recurring_payment_id'];
                $order->gateway_id = $retry['recurring_payment_id'];
            }
            $order->update(['metadata' => $meta, 'gateway_id' => $order->gateway_id]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('LinaOpenxPixAutoLifecycleService: falha na retentativa', [
                'order_id' => $order->id,
                'payment_id' => $paymentId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return false;
        }
    }

    /**
     * @return array{rec: int, paid: int, retries: int, cancelled: int, errors: int}
     */
    public function reconcile(int $limit = 80): array
    {
        $stats = ['rec' => 0, 'paid' => 0, 'retries' => 0, 'cancelled' => 0, 'errors' => 0];

        $subscriptions = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->whereNotNull('gateway_subscription_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($subscriptions as $subscription) {
            if (! self::isLinaSubscription($subscription)) {
                continue;
            }
            try {
                $this->reconcileSubscription($subscription);
                $stats['rec']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('LinaOpenxPixAutoLifecycleService: reconcile rec falhou', [
                    'subscription_id' => $subscription->id,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        $orders = Order::query()
            ->where('gateway', LinaOpenxPixAutoService::GATEWAY_SLUG)
            ->where('payment_method', 'pix_auto')
            ->where('status', 'pending')
            ->whereNotNull('gateway_id')
            ->where('gateway_id', '!=', '')
            ->where('created_at', '>=', now()->subDays(45))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            try {
                $outcome = $this->reconcilePendingOrder($order);
                if ($outcome === 'paid') {
                    $stats['paid']++;
                } elseif ($outcome === 'retry') {
                    $stats['retries']++;
                } elseif ($outcome === 'cancelled') {
                    $stats['cancelled']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('LinaOpenxPixAutoLifecycleService: reconcile débito falhou', [
                    'order_id' => $order->id,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        return $stats;
    }

    private function reconcileSubscription(Subscription $subscription): void
    {
        app(LinaOpenxPixAutoRenewalService::class)->ensureNextPayment($subscription);
    }

    private function reconcilePendingOrder(Order $order): ?string
    {
        $status = $this->pixAuto->resolveNormalizedStatus($order);
        if ($status === 'paid') {
            $txId = trim((string) $order->gateway_id);
            ProcessPaymentWebhook::dispatchSync(LinaOpenxPixAutoService::GATEWAY_SLUG, $txId, 'order.paid', 'paid', [
                'source' => 'linaopenx_pix_auto_reconcile',
            ]);

            return 'paid';
        }

        if ($status === 'cancelled' || $status === 'rejected') {
            if ($status === 'rejected' && $this->retryFailedPayment($order->fresh() ?? $order)) {
                return 'retry';
            }
            $order->update(['status' => $status === 'rejected' ? 'rejected' : 'cancelled']);
            event(new OrderCancelled($order));

            return 'cancelled';
        }

        if ($this->retryFailedPayment($order)) {
            return 'retry';
        }

        return null;
    }

    private function makeEndToEndId(string $ispb): string
    {
        $ispb = str_pad(preg_replace('/\D+/', '', $ispb) ?? '00000000', 8, '0', STR_PAD_LEFT);
        $stamp = now()->utc()->format('YmdHis');
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $rand = '';
        for ($i = 0; $i < 11; $i++) {
            $rand .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return 'E'.$ispb.$stamp.$rand;
    }
}
