<?php

namespace App\Services\Asaas;

use App\Events\OrderPending;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Agenda a cobrança seguinte da Asaas entre 2 e 10 dias úteis antes do vencimento.
 */
class AsaasPixAutoRenewalService
{
    public function __construct(
        private readonly AsaasPixAutoService $pixAuto = new AsaasPixAutoService()
    ) {}

    public function ensureNextPayment(Subscription $subscription, ?Order $sourceOrder = null): ?Order
    {
        $authorizationId = trim((string) ($subscription->gateway_subscription_id ?? ''));
        if ($authorizationId === '') {
            return null;
        }

        $plan = $subscription->subscriptionPlan;
        if ($plan === null || $plan->isLifetime()) {
            return null;
        }

        [$periodStart, $periodEnd] = $this->nextPeriod($subscription, $plan);
        $periodEndStr = $periodEnd->toDateString();
        $existingEarly = $this->findPendingRenewal($subscription, $periodEndStr);
        if ($existingEarly !== null && trim((string) $existingEarly->gateway_id) !== '') {
            return $existingEarly;
        }
        if (! $this->pixAuto->withinInstructionWindow($periodStart)) {
            return null;
        }

        $lockKey = 'asaas_pix_auto_pay:'.$subscription->id.':'.$periodEndStr;
        if (! Cache::add($lockKey, 1, now()->addHours(12))) {
            return $this->findPendingRenewal($subscription, $periodEndStr);
        }

        try {
            if ($this->hasCompletedRenewalForPeriod($subscription, $periodEndStr)) {
                return null;
            }

            $credentials = $this->pixAuto->credentialsForTenant($subscription->tenant_id);
            if ($credentials === null || ! $this->pixAuto->authorizationIsActive($credentials, $authorizationId)) {
                Cache::forget($lockKey);

                return $existingEarly;
            }

            $customerId = trim((string) (($sourceOrder?->metadata['asaas_customer_id'] ?? null)
                ?: $this->customerIdFromAuthorization($subscription, $authorizationId)));
            if ($customerId === '') {
                Cache::forget($lockKey);

                return $existingEarly;
            }

            $created = false;
            $order = $existingEarly;
            if ($order === null) {
                $order = $this->createPendingRenewalOrder($subscription, $plan, $periodStart, $periodEnd, $authorizationId, $customerId, $sourceOrder);
                $created = true;
                event(new OrderPending($order));
            }

            $paymentId = $this->pixAuto->createRecurringPayment(
                $credentials,
                $customerId,
                $authorizationId,
                (float) $plan->price,
                $periodStart->toDateString(),
                (string) $order->id
            );
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $meta['checkout_payment_method'] = 'pix_auto';
            $meta['asaas_pix_auto_authorization_id'] = $authorizationId;
            $meta['asaas_pix_auto_status'] = 'ACTIVE';
            $meta['asaas_customer_id'] = $customerId;
            $meta['subscription_id'] = $subscription->id;
            $order->update([
                'gateway' => AsaasPixAutoService::GATEWAY_SLUG,
                'gateway_id' => $paymentId,
                'metadata' => $meta,
            ]);

            return $order->fresh();
        } catch (\Throwable $e) {
            Cache::forget($lockKey);
            Log::warning('AsaasPixAutoRenewalService: falha ao agendar cobrança', [
                'subscription_id' => $subscription->id,
                'authorization_id' => $authorizationId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);
            if (isset($created, $order) && $created && $order instanceof Order && trim((string) $order->gateway_id) === '') {
                try {
                    $order->delete();
                } catch (\Throwable) {
                }
            }

            return null;
        }
    }

    public function findPendingRenewal(Subscription $subscription, string $periodEnd): ?Order
    {
        return Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('payment_method', 'pix_auto')
            ->where('gateway', AsaasPixAutoService::GATEWAY_SLUG)
            ->where('is_renewal', true)
            ->where('status', 'pending')
            ->whereDate('period_end', $periodEnd)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    public function nextPeriod(Subscription $subscription, SubscriptionPlan $plan): array
    {
        $start = $subscription->current_period_end
            ? $subscription->current_period_end->copy()->startOfDay()
            : now()->startOfDay();
        if ($start->lt(now()->startOfDay())) {
            $start = now()->startOfDay();
        }
        $end = match ($plan->interval) {
            SubscriptionPlan::INTERVAL_WEEKLY => $start->copy()->addWeek(),
            SubscriptionPlan::INTERVAL_QUARTERLY => $start->copy()->addMonths(3),
            SubscriptionPlan::INTERVAL_SEMI_ANNUAL => $start->copy()->addMonths(6),
            SubscriptionPlan::INTERVAL_ANNUAL => $start->copy()->addYear(),
            default => $start->copy()->addMonth(),
        };

        return [$start, $end];
    }

    private function customerIdFromAuthorization(Subscription $subscription, string $authorizationId): string
    {
        $order = Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('gateway', AsaasPixAutoService::GATEWAY_SLUG)
            ->where('metadata->asaas_pix_auto_authorization_id', $authorizationId)
            ->orderByDesc('id')
            ->first();

        return trim((string) ($order?->metadata['asaas_customer_id'] ?? ''));
    }

    private function hasCompletedRenewalForPeriod(Subscription $subscription, string $periodEnd): bool
    {
        return Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('payment_method', 'pix_auto')
            ->where('gateway', AsaasPixAutoService::GATEWAY_SLUG)
            ->where('is_renewal', true)
            ->where('status', 'completed')
            ->whereDate('period_end', $periodEnd)
            ->exists();
    }

    private function createPendingRenewalOrder(
        Subscription $subscription,
        SubscriptionPlan $plan,
        $periodStart,
        $periodEnd,
        string $authorizationId,
        string $customerId,
        ?Order $sourceOrder
    ): Order {
        $user = $subscription->user;

        return Order::create([
            'tenant_id' => $subscription->tenant_id,
            'user_id' => $subscription->user_id,
            'product_id' => $subscription->product_id,
            'product_offer_id' => $sourceOrder?->product_offer_id,
            'subscription_plan_id' => $plan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => true,
            'amount' => (float) $plan->price,
            'email' => $user?->email ?? $sourceOrder?->email,
            'cpf' => $sourceOrder?->cpf,
            'phone' => $sourceOrder?->phone,
            'customer_ip' => null,
            'coupon_code' => null,
            'status' => 'pending',
            'gateway' => AsaasPixAutoService::GATEWAY_SLUG,
            'gateway_id' => null,
            'payment_method' => 'pix_auto',
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'asaas_pix_auto_authorization_id' => $authorizationId,
                'asaas_customer_id' => $customerId,
                'subscription_id' => $subscription->id,
                'subscription_auto_renewal' => true,
            ],
        ]);
    }
}
