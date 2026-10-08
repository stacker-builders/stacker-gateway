<?php

namespace App\Services\Uazapi;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use Illuminate\Support\Collection;

class UazapiAccountResolver
{
    public const CAPABILITY_CART = 'cart';

    public const CAPABILITY_PIX = 'pix';

    public const CAPABILITY_ORDER_PAID = 'order_paid';

    public const CAPABILITY_SEND = 'send';

    /**
     * @return Collection<int, UazapiInstance>
     */
    public function allForTenant(int $tenantId): Collection
    {
        return UazapiInstance::query()
            ->where('tenant_id', $tenantId)
            ->with('products')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, UazapiInstance>
     */
    public function routableForTenant(int $tenantId, string $capability = self::CAPABILITY_SEND): Collection
    {
        return $this->allForTenant($tenantId)
            ->filter(fn (UazapiInstance $instance) => $this->matchesCapability($instance, $capability))
            ->values();
    }

    public function resolveForTenant(int $tenantId, string $capability = self::CAPABILITY_SEND): ?UazapiInstance
    {
        $pool = $this->routableForTenant($tenantId, $capability);
        if ($pool->isEmpty()) {
            return null;
        }

        $default = $pool->first(fn (UazapiInstance $instance) => $instance->is_default);
        if ($default) {
            return $default;
        }

        return $pool
            ->sortBy(fn (UazapiInstance $instance) => $instance->last_used_at?->getTimestamp() ?? 0)
            ->first();
    }

    public function resolveForSession(CheckoutSession $session): ?UazapiInstance
    {
        return $this->stickyOrNext(
            (int) $session->tenant_id,
            self::CAPABILITY_CART,
            'checkout_session_id',
            (int) $session->id,
            fn (UazapiInstance $instance) => $instance->appliesToProduct($session->product_id !== null ? (string) $session->product_id : null)
        );
    }

    public function resolveForOrder(Order $order): ?UazapiInstance
    {
        return $this->stickyOrNext(
            (int) $order->tenant_id,
            self::CAPABILITY_PIX,
            'order_id',
            (int) $order->id,
            fn (UazapiInstance $instance) => $instance->appliesToOrder($order)
        );
    }

    public function resolveForPaidOrder(Order $order): ?UazapiInstance
    {
        return $this->stickyOrNext(
            (int) $order->tenant_id,
            self::CAPABILITY_ORDER_PAID,
            'order_id',
            (int) $order->id,
            fn (UazapiInstance $instance) => $instance->appliesToOrder($order)
        );
    }

    public function failover(UazapiInstance $current, string $capability = self::CAPABILITY_SEND, ?callable $applies = null): ?UazapiInstance
    {
        return $this->routableForTenant((int) $current->tenant_id, $capability)
            ->first(function (UazapiInstance $instance) use ($current, $applies) {
                if ((int) $instance->id === (int) $current->id) {
                    return false;
                }

                return $applies === null || $applies($instance);
            });
    }

    /**
     * @param  callable(UazapiInstance): bool|null  $applies
     */
    private function stickyOrNext(int $tenantId, string $capability, string $column, int $id, ?callable $applies = null): ?UazapiInstance
    {
        $previousId = UazapiMessageDispatch::query()
            ->where('tenant_id', $tenantId)
            ->where($column, $id)
            ->orderByDesc('id')
            ->value('uazapi_instance_id');

        if ($previousId) {
            $sticky = UazapiInstance::query()->with('products')->whereKey($previousId)->first();
            if ($sticky && $this->matchesCapability($sticky, $capability) && ($applies === null || $applies($sticky))) {
                return $sticky;
            }
        }

        $pool = $this->routableForTenant($tenantId, $capability)
            ->filter(fn (UazapiInstance $instance) => $applies === null || $applies($instance))
            ->values();
        if ($pool->isEmpty()) {
            return null;
        }

        $default = $pool->first(fn (UazapiInstance $instance) => $instance->is_default);
        if ($default) {
            return $default;
        }

        return $pool
            ->sortBy(fn (UazapiInstance $instance) => $instance->last_used_at?->getTimestamp() ?? 0)
            ->first();
    }

    public function makeDefault(UazapiInstance $instance): void
    {
        UazapiInstance::query()
            ->where('tenant_id', $instance->tenant_id)
            ->where('id', '!=', $instance->id)
            ->update(['is_default' => false]);

        if (! $instance->is_default) {
            $instance->is_default = true;
            $instance->save();
        }
    }

    public function touch(UazapiInstance $instance): void
    {
        $instance->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * @return list<int>
     */
    public function instanceIdsForOrderOrSession(?int $orderId, ?int $sessionId): array
    {
        $query = UazapiMessageDispatch::query()->whereNotNull('uazapi_instance_id');
        $query->where(function ($inner) use ($orderId, $sessionId) {
            if ($orderId) {
                $inner->orWhere('order_id', $orderId);
            }
            if ($sessionId) {
                $inner->orWhere('checkout_session_id', $sessionId);
            }
        });

        return $query->pluck('uazapi_instance_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function matchesCapability(UazapiInstance $instance, string $capability): bool
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
