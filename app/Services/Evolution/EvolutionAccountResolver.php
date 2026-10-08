<?php

namespace App\Services\Evolution;

use App\Models\CheckoutSession;
use App\Models\EvolutionInstance;
use App\Models\EvolutionMessageDispatch;
use App\Models\Order;
use Illuminate\Support\Collection;

class EvolutionAccountResolver
{
    public const CAPABILITY_CART = 'cart';

    public const CAPABILITY_PIX = 'pix';

    public const CAPABILITY_ORDER_PAID = 'order_paid';

    public const CAPABILITY_SEND = 'send';

    /**
     * @return Collection<int, EvolutionInstance>
     */
    public function allForTenant(int $tenantId): Collection
    {
        return EvolutionInstance::query()
            ->where('tenant_id', $tenantId)
            ->with('products')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, EvolutionInstance>
     */
    public function routableForTenant(int $tenantId, string $capability = self::CAPABILITY_SEND): Collection
    {
        return $this->allForTenant($tenantId)
            ->filter(fn (EvolutionInstance $instance) => $this->matchesCapability($instance, $capability))
            ->values();
    }

    public function resolveForTenant(int $tenantId, string $capability = self::CAPABILITY_SEND): ?EvolutionInstance
    {
        $pool = $this->routableForTenant($tenantId, $capability);
        if ($pool->isEmpty()) {
            return null;
        }

        $default = $pool->first(fn (EvolutionInstance $instance) => $instance->is_default);

        return $default ?: $pool
            ->sortBy(fn (EvolutionInstance $instance) => $instance->last_used_at?->getTimestamp() ?? 0)
            ->first();
    }

    public function resolveForSession(CheckoutSession $session): ?EvolutionInstance
    {
        return $this->stickyOrNext(
            (int) $session->tenant_id,
            self::CAPABILITY_CART,
            'checkout_session_id',
            (int) $session->id,
            fn (EvolutionInstance $instance) => $instance->appliesToProduct(
                $session->product_id !== null ? (string) $session->product_id : null
            )
        );
    }

    public function resolveForOrder(Order $order): ?EvolutionInstance
    {
        return $this->stickyOrNext(
            (int) $order->tenant_id,
            self::CAPABILITY_PIX,
            'order_id',
            (int) $order->id,
            fn (EvolutionInstance $instance) => $instance->appliesToOrder($order)
        );
    }

    public function resolveForPaidOrder(Order $order): ?EvolutionInstance
    {
        return $this->stickyOrNext(
            (int) $order->tenant_id,
            self::CAPABILITY_ORDER_PAID,
            'order_id',
            (int) $order->id,
            fn (EvolutionInstance $instance) => $instance->appliesToOrder($order)
        );
    }

    public function failover(EvolutionInstance $current, string $capability = self::CAPABILITY_SEND, ?callable $applies = null): ?EvolutionInstance
    {
        return $this->routableForTenant((int) $current->tenant_id, $capability)
            ->first(function (EvolutionInstance $instance) use ($current, $applies) {
                if ((int) $instance->id === (int) $current->id) {
                    return false;
                }

                return $applies === null || $applies($instance);
            });
    }

    /**
     * @param  callable(EvolutionInstance): bool|null  $applies
     */
    private function stickyOrNext(int $tenantId, string $capability, string $column, int $id, ?callable $applies = null): ?EvolutionInstance
    {
        $previousId = EvolutionMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where($column, $id)
            ->orderByDesc('id')
            ->value('evolution_instance_id');

        if ($previousId) {
            $sticky = EvolutionInstance::query()->with('products')->whereKey($previousId)->first();
            if ($sticky && $this->matchesCapability($sticky, $capability) && ($applies === null || $applies($sticky))) {
                return $sticky;
            }
        }

        $pool = $this->routableForTenant($tenantId, $capability)
            ->filter(fn (EvolutionInstance $instance) => $applies === null || $applies($instance))
            ->values();
        if ($pool->isEmpty()) {
            return null;
        }

        $default = $pool->first(fn (EvolutionInstance $instance) => $instance->is_default);

        return $default ?: $pool
            ->sortBy(fn (EvolutionInstance $instance) => $instance->last_used_at?->getTimestamp() ?? 0)
            ->first();
    }

    public function makeDefault(EvolutionInstance $instance): void
    {
        EvolutionInstance::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('id', '!=', $instance->id)
            ->update(['is_default' => false]);

        if (! $instance->is_default) {
            $instance->is_default = true;
            $instance->save();
        }
    }

    private function matchesCapability(EvolutionInstance $instance, string $capability): bool
    {
        if (! $instance->canSendRecovery()) {
            return false;
        }

        return match ($capability) {
            self::CAPABILITY_CART => (bool) $instance->cart_recovery_enabled,
            self::CAPABILITY_PIX => (bool) $instance->pix_recovery_enabled,
            self::CAPABILITY_ORDER_PAID => (bool) $instance->order_paid_enabled,
            default => true,
        };
    }
}
