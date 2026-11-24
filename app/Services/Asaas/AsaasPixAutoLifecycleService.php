<?php

namespace App\Services\Asaas;

use App\Events\OrderCancelled;
use App\Gateways\Asaas\AsaasDriver;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

class AsaasPixAutoLifecycleService
{
    public function __construct(
        private readonly AsaasPixAutoService $pixAuto = new AsaasPixAutoService(),
        private readonly AsaasDriver $driver = new AsaasDriver()
    ) {}

    public static function isAsaasSubscription(Subscription $subscription): bool
    {
        $authorizationId = trim((string) ($subscription->gateway_subscription_id ?? ''));
        if ($authorizationId === '') {
            return false;
        }

        $gateway = Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('payment_method', 'pix_auto')
            ->where('gateway', AsaasPixAutoService::GATEWAY_SLUG)
            ->orderByDesc('id')
            ->value('gateway');

        return $gateway === AsaasPixAutoService::GATEWAY_SLUG;
    }

    public function cancelRemote(Subscription $subscription): void
    {
        if (! self::isAsaasSubscription($subscription)) {
            return;
        }

        $authorizationId = trim((string) $subscription->gateway_subscription_id);
        $credentials = $this->pixAuto->credentialsForTenant($subscription->tenant_id);
        if ($credentials !== null) {
            try {
                $this->driver->cancelPixAutomaticAuthorization($credentials, $authorizationId);
            } catch (\Throwable $e) {
                Log::warning('AsaasPixAutoLifecycleService: falha ao cancelar autorização', [
                    'subscription_id' => $subscription->id,
                    'authorization_id' => $authorizationId,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        $pending = Order::query()
            ->where('gateway', AsaasPixAutoService::GATEWAY_SLUG)
            ->where('payment_method', 'pix_auto')
            ->where('status', 'pending')
            ->where('is_renewal', true)
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->get();
        foreach ($pending as $order) {
            $order->update(['status' => 'cancelled']);
            event(new OrderCancelled($order));
        }
    }

    /**
     * @return array{scheduled: int, errors: int}
     */
    public function reconcile(int $limit = 80): array
    {
        $stats = ['scheduled' => 0, 'errors' => 0];
        $subscriptions = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->whereNotNull('gateway_subscription_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($subscriptions as $subscription) {
            if (! self::isAsaasSubscription($subscription)) {
                continue;
            }
            try {
                $order = app(AsaasPixAutoRenewalService::class)->ensureNextPayment($subscription);
                if ($order !== null && trim((string) $order->gateway_id) !== '') {
                    $stats['scheduled']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::warning('AsaasPixAutoLifecycleService: reconcile falhou', [
                    'subscription_id' => $subscription->id,
                    'error' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        return $stats;
    }
}
