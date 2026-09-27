<?php

namespace App\Services\Whatsapp;

use App\Models\EvolutionMessageDispatch;
use App\Models\EvolutionOptOut;
use App\Models\EvolutionRecoveryStop;
use App\Models\Order;
use App\Models\UazapiMessageDispatch;
use App\Models\UazapiOptOut;
use App\Models\UazapiRecoveryStop;
use DateTimeInterface;

class WhatsappRecoveryGuard
{
    public static function isOptedOut(int $tenantId, string $phone): bool
    {
        return UazapiOptOut::isOptedOut($tenantId, $phone)
            || EvolutionOptOut::isOptedOut($tenantId, $phone);
    }

    public static function blocks(int $tenantId, string $phone, DateTimeInterface $startedAt): bool
    {
        return UazapiRecoveryStop::blocks($tenantId, $phone, $startedAt)
            || EvolutionRecoveryStop::blocks($tenantId, $phone, $startedAt);
    }

    public static function sessionTaken(int $sessionId, string $eventType, int $stepIndex): bool
    {
        return UazapiMessageDispatch::hasPendingStepForSession($sessionId, $stepIndex, $eventType)
            || EvolutionMessageDispatch::hasPendingStepForSession($sessionId, $stepIndex, $eventType)
            || in_array($stepIndex, UazapiMessageDispatch::consumedStepIndicesForSession($sessionId, $eventType), true)
            || in_array($stepIndex, EvolutionMessageDispatch::consumedStepIndicesForSession($sessionId, $eventType), true);
    }

    public static function orderStepTaken(int $orderId, string $eventType, int $stepIndex): bool
    {
        return UazapiMessageDispatch::alreadyQueuedForOrderStep($orderId, $eventType, $stepIndex)
            || EvolutionMessageDispatch::alreadyQueuedForOrderStep($orderId, $eventType, $stepIndex);
    }

    /**
     * True when the phone already has a completed (or disputed) purchase of this product.
     * Prevents recovering a cart after the buyer paid on another checkout session.
     */
    public static function alreadyOwnsProduct(int $tenantId, string $normalizedPhone, string|int|null $productId): bool
    {
        if ($productId === null || $productId === '') {
            return false;
        }

        $variants = self::phoneMatchVariants($normalizedPhone);
        if ($variants === []) {
            return false;
        }

        $needle = self::phoneSearchNeedle($normalizedPhone);
        if ($needle === null) {
            return false;
        }

        $candidates = Order::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['completed', 'disputed'])
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            // Últimos 4 dígitos costumam aparecer contíguos mesmo com máscara (ex.: 98877-6655).
            ->where('phone', 'like', '%'.$needle.'%')
            ->where(function ($query) use ($productId) {
                $query->where('product_id', $productId)
                    ->orWhereHas('orderItems', fn ($items) => $items->where('product_id', $productId));
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'phone']);

        foreach ($candidates as $order) {
            $orderDigits = self::digitsOnly((string) $order->phone);
            if ($orderDigits === null) {
                continue;
            }
            foreach (self::phoneMatchVariants($orderDigits) as $orderVariant) {
                if (in_array($orderVariant, $variants, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function phoneMatchVariants(string $phone): array
    {
        $digits = self::digitsOnly($phone);
        if ($digits === null) {
            return [];
        }

        $withCountry = str_starts_with($digits, '55') ? $digits : '55'.$digits;
        $local = str_starts_with($withCountry, '55') ? substr($withCountry, 2) : $withCountry;

        $variants = [$withCountry, $local, $digits];

        if (strlen($local) === 11 && ($local[2] ?? '') === '9') {
            $withoutNine = substr($local, 0, 2).substr($local, 3);
            $variants[] = $withoutNine;
            $variants[] = '55'.$withoutNine;
        }

        if (strlen($local) === 10) {
            $withNine = substr($local, 0, 2).'9'.substr($local, 2);
            $variants[] = $withNine;
            $variants[] = '55'.$withNine;
        }

        return array_values(array_unique(array_filter(
            $variants,
            fn ($value) => is_string($value) && $value !== '' && strlen($value) >= 10
        )));
    }

    private static function phoneSearchNeedle(string $phone): ?string
    {
        $digits = self::digitsOnly($phone);
        if ($digits === null) {
            return null;
        }

        $local = str_starts_with($digits, '55') ? substr($digits, 2) : $digits;
        if (strlen($local) < 4) {
            return null;
        }

        return substr($local, -4);
    }

    private static function digitsOnly(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (! is_string($digits) || $digits === '' || strlen($digits) < 10) {
            return null;
        }

        return $digits;
    }
}
