<?php

namespace App\Services;

use App\Events\OrderCompleted;
use App\Events\SubscriptionCreated;
use App\Events\SubscriptionRenewed;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Log;

class SubscriptionRenewalService
{
    /**
     * @return array{subscription: ?Subscription, renewed: bool, created: bool}
     */
    public function syncFromPaidOrder(Order $order, array $createExtra = []): array
    {
        $order->grantPurchasedProductAccessToBuyer();
        $order->loadMissing('subscriptionPlan', 'product');

        $plan = $this->resolvePlanForOrder($order);
        if (! $plan) {
            return ['subscription' => null, 'renewed' => false, 'created' => false];
        }

        $existing = $this->findRenewableForUserProduct((int) $order->user_id, $order->product_id, $plan);
        $treatAsRenewal = (bool) $order->is_renewal || $existing !== null;

        if ($treatAsRenewal) {
            if ($existing === null) {
                Log::warning('SubscriptionRenewalService: renovação sem assinatura para reativar; criando uma nova.', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'product_id' => $order->product_id,
                ]);

                $created = $this->createInitial($order, $plan, $createExtra);

                return ['subscription' => $created, 'renewed' => false, 'created' => true];
            }

            $this->markOrderAsRenewal($order);
            $this->reactivate($order, $existing, $plan, $createExtra);

            return ['subscription' => $existing->fresh(), 'renewed' => true, 'created' => false];
        }

        $active = $this->findActiveForUserProduct((int) $order->user_id, $order->product_id, $plan);
        if ($active) {
            $order->grantPurchasedProductAccessToBuyer();

            return ['subscription' => $active, 'renewed' => false, 'created' => false];
        }

        $created = $this->createInitial($order, $plan, $createExtra);

        return ['subscription' => $created, 'renewed' => false, 'created' => true];
    }

    /**
     * Após pagamento confirmado de um pedido de renovação: estende o período e dispara eventos.
     * Usado pelo checkout de renovação e pela cobrança automática com cartão salvo.
     */
    public function applySuccessfulRenewal(Order $order, Subscription $subscription, SubscriptionPlan $plan): void
    {
        if ($order->status !== 'completed') {
            $order->update(['status' => 'completed']);
        }

        $this->markOrderAsRenewal($order);
        $order->grantPurchasedProductAccessToBuyer();
        $this->reactivate($order, $subscription, $plan);
        event(new OrderCompleted($order->fresh()));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withRenewalFlag(array $payload): array
    {
        if (! empty($payload['is_renewal'])) {
            return $payload;
        }

        $userId = $payload['user_id'] ?? null;
        $productId = $payload['product_id'] ?? null;
        if (! $userId || $productId === null || $productId === '') {
            return $payload;
        }

        if ($this->findRenewableForUserProduct((int) $userId, $productId)) {
            $payload['is_renewal'] = true;
        }

        return $payload;
    }

    public function findRenewableForUserProduct(int $userId, mixed $productId, ?SubscriptionPlan $plan = null): ?Subscription
    {
        if ($userId <= 0 || $productId === null || $productId === '') {
            return null;
        }

        $query = Subscription::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->whereIn('status', [
                Subscription::STATUS_ACTIVE,
                Subscription::STATUS_PAST_DUE,
                Subscription::STATUS_CANCELLED,
            ]);

        if ($plan) {
            $query->orderByRaw('CASE WHEN subscription_plan_id = ? THEN 0 ELSE 1 END', [$plan->id]);
        }

        return $query
            ->orderByRaw("CASE status WHEN 'past_due' THEN 0 WHEN 'active' THEN 1 WHEN 'cancelled' THEN 2 ELSE 3 END")
            ->orderByDesc('id')
            ->first();
    }

    public function resolvePlanForOrder(Order $order): ?SubscriptionPlan
    {
        $plan = $order->subscriptionPlan;
        if ($plan) {
            return $plan;
        }

        if ($order->subscription_plan_id) {
            $plan = SubscriptionPlan::query()->find($order->subscription_plan_id);
            if ($plan) {
                return $plan;
            }
        }

        $product = $order->product;
        if (! $product || ($product->billing_type ?? Product::BILLING_ONE_TIME) !== Product::BILLING_SUBSCRIPTION) {
            return null;
        }

        // Renovação sem plan_id no pedido: preferir o plano da assinatura existente
        // (não o fallback vitalício/primeiro do produto).
        if ($order->user_id) {
            $existing = $this->findRenewableForUserProduct((int) $order->user_id, $order->product_id);
            if ($existing?->subscription_plan_id) {
                $plan = SubscriptionPlan::query()->find($existing->subscription_plan_id);
                if ($plan) {
                    $this->attachPlanToOrder($order, $plan);

                    return $plan;
                }
            }
        }

        $plan = app(MemberAccessGrantService::class)->resolvePlan($product);
        if ($plan) {
            $this->attachPlanToOrder($order, $plan);
        }

        return $plan;
    }

    /**
     * @return array{0: \Carbon\Carbon|\Carbon\CarbonInterface, 1: \Carbon\Carbon|\Carbon\CarbonInterface|null}
     */
    public function resolvePeriod(Order $order, SubscriptionPlan $plan, ?Subscription $subscription): array
    {
        // Renovação com período ainda válido: estende a partir do fim atual usando o
        // intervalo do plano do checkout (mensal/anual/etc.), não "reinicia" do dia.
        if ($subscription?->current_period_end
            && $subscription->current_period_end->copy()->startOfDay()->gte(now()->startOfDay())
        ) {
            $start = $subscription->current_period_end->copy()->startOfDay();
            if ($plan->isLifetime()) {
                return [$start, null];
            }

            $end = match ($plan->interval) {
                SubscriptionPlan::INTERVAL_WEEKLY => $start->copy()->addWeek(),
                SubscriptionPlan::INTERVAL_MONTHLY => $start->copy()->addMonth(),
                SubscriptionPlan::INTERVAL_QUARTERLY => $start->copy()->addMonths(3),
                SubscriptionPlan::INTERVAL_SEMI_ANNUAL => $start->copy()->addMonths(6),
                SubscriptionPlan::INTERVAL_ANNUAL => $start->copy()->addYear(),
                default => $start->copy()->addMonth(),
            };

            return [$start, $end];
        }

        if ($order->period_start && ($order->period_end || $plan->isLifetime())) {
            return [$order->period_start, $order->period_end];
        }

        return $plan->getCurrentPeriod();
    }

    private function attachPlanToOrder(Order $order, SubscriptionPlan $plan): void
    {
        if ($order->subscription_plan_id) {
            $order->setRelation('subscriptionPlan', $plan);

            return;
        }

        $order->update(['subscription_plan_id' => $plan->id]);
        $order->setRelation('subscriptionPlan', $plan);
    }

    /**
     * @param  array<string, mixed>  $createExtra
     */
    private function reactivate(Order $order, Subscription $subscription, SubscriptionPlan $plan, array $createExtra = []): void
    {
        [$periodStart, $periodEnd] = $this->resolvePeriod($order, $plan, $subscription);

        $patch = [
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ];
        if ((int) $subscription->subscription_plan_id !== (int) $plan->id) {
            $patch['subscription_plan_id'] = $plan->id;
        }
        $gatewaySubId = $createExtra['gateway_subscription_id'] ?? null;
        if (is_string($gatewaySubId) && $gatewaySubId !== '' && empty($subscription->gateway_subscription_id)) {
            $patch['gateway_subscription_id'] = $gatewaySubId;
        }

        $subscription->update($patch);

        if (! $order->period_start || ! $order->period_end) {
            $order->update([
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
        }

        $this->cancelDuplicateSubscriptions($subscription);

        event(new SubscriptionRenewed($subscription->fresh()));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createInitial(Order $order, SubscriptionPlan $plan, array $extra = []): Subscription
    {
        [$periodStart, $periodEnd] = $this->resolvePeriod($order, $plan, null);

        $subscription = Subscription::create([
            'tenant_id' => $order->tenant_id,
            'user_id' => $order->user_id,
            'product_id' => $order->product_id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'gateway_subscription_id' => $extra['gateway_subscription_id'] ?? null,
        ]);

        event(new SubscriptionCreated($subscription));

        return $subscription;
    }

    private function markOrderAsRenewal(Order $order): void
    {
        if ($order->is_renewal) {
            return;
        }

        $order->update(['is_renewal' => true]);
        $order->is_renewal = true;
    }

    private function findActiveForUserProduct(int $userId, mixed $productId, SubscriptionPlan $plan): ?Subscription
    {
        $today = now()->startOfDay()->toDateString();

        return Subscription::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->where('subscription_plan_id', $plan->id)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->where(function ($q) use ($today) {
                $q->whereDate('current_period_end', '>=', $today)
                    ->orWhereNull('current_period_end');
            })
            ->orderByDesc('id')
            ->first();
    }

    private function cancelDuplicateSubscriptions(Subscription $kept): void
    {
        Subscription::query()
            ->where('user_id', $kept->user_id)
            ->where('product_id', $kept->product_id)
            ->where('id', '!=', $kept->id)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->update(['status' => Subscription::STATUS_CANCELLED]);
    }
}
