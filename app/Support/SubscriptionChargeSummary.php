<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Support\Collection;

class SubscriptionChargeSummary
{
    /**
     * @param  Collection<int, Subscription>|iterable<Subscription>  $subscriptions
     * @return array<int, array<string, mixed>>
     */
    public static function forSubscriptions(iterable $subscriptions): array
    {
        $subscriptions = collect($subscriptions)->values();
        if ($subscriptions->isEmpty()) {
            return [];
        }

        $orders = Order::query()
            ->where(function ($query) use ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    $query->orWhere(function ($inner) use ($subscription) {
                        $inner->where('tenant_id', $subscription->tenant_id)
                            ->where('user_id', $subscription->user_id)
                            ->where('product_id', $subscription->product_id)
                            ->where('subscription_plan_id', $subscription->subscription_plan_id);
                    });
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $grouped = $orders->groupBy(fn (Order $order) => self::key(
            $order->tenant_id,
            $order->user_id,
            (string) $order->product_id,
            $order->subscription_plan_id
        ));

        $summaries = [];
        foreach ($subscriptions as $subscription) {
            $rows = $grouped->get(self::key(
                $subscription->tenant_id,
                $subscription->user_id,
                (string) $subscription->product_id,
                $subscription->subscription_plan_id
            ), collect());

            $purchase = $rows->first(fn (Order $order) => ! $order->is_renewal) ?? $rows->first();
            $completed = $rows->filter(fn (Order $order) => $order->status === 'completed');
            $latest = $rows->last();
            $method = (string) ($latest?->payment_method ?? '');
            $cancelled = $subscription->status === Subscription::STATUS_CANCELLED;

            $summaries[$subscription->id] = [
                'purchased_at' => ($purchase?->created_at ?? $subscription->created_at)?->toIso8601String(),
                'next_charge_at' => $cancelled ? null : $subscription->current_period_end?->toDateString(),
                'next_charge_amount' => $cancelled ? null : (float) ($subscription->subscriptionPlan?->price ?? 0),
                'payment_method' => $method !== '' ? $method : null,
                'payment_method_label' => self::methodLabel($method),
                'charges_count' => $completed->count(),
                'automatic_charges_count' => $completed->where('is_renewal', true)->count(),
            ];
        }

        return $summaries;
    }

    public static function methodLabel(?string $method): string
    {
        return match ($method) {
            'pix_auto' => 'PIX automático',
            'pix' => 'PIX',
            'card' => 'Cartão',
            'boleto' => 'Boleto',
            '', null => '—',
            default => $method,
        };
    }

    private static function key(mixed $tenantId, mixed $userId, string $productId, mixed $planId): string
    {
        return implode('|', [$tenantId, $userId, $productId, $planId]);
    }
}
