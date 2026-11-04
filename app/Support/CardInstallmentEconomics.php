<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;
use App\Services\EffectiveSettlementRules;

/**
 * Taxa (percentual + fixo) e intervalo de disponibilidade (D+N) por parcela no cartão.
 * O líquido de uma venda Nx é dividido em N fatias; a i-ésima libera em D+(i × dias).
 */
final class CardInstallmentEconomics
{
    public const MIN = 1;

    public const MAX = 12;

    public const MAX_MERCADOPAGO = 18;

    /**
     * Linhas editáveis no admin: 18x com Mercado Pago ativo, 12x nos demais.
     */
    public static function tableMax(): int
    {
        return \App\Services\PlatformCardInstallments::selectableCeiling();
    }

    /**
     * Ao regravar uma tabela já salva, não inventa linhas acima do que já existe.
     *
     * @param  array<int|string, mixed>|null  $raw
     */
    public static function preserveMax(?array $raw): int
    {
        $highest = self::MIN;
        if (is_array($raw)) {
            foreach ($raw as $key => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $n = (int) $key;
                if ($n > $highest) {
                    $highest = $n;
                }
            }
        }

        return max(self::MIN, min(self::tableMax(), $highest));
    }

    public static function clampCount(int $count): int
    {
        return max(self::MIN, min(self::MAX_MERCADOPAGO, $count));
    }

    public static function countFromMetadata(mixed $metadata): int
    {
        $n = 1;
        if (is_array($metadata) && array_key_exists('installments', $metadata)) {
            $n = (int) $metadata['installments'];
        }

        return self::clampCount($n);
    }

    public static function countFromOrder(Order $order): int
    {
        return self::countFromMetadata($order->metadata);
    }

    /**
     * A tabela só entra em vigor depois que o admin salvar as linhas de parcelamento.
     * Sem isso, vendas de cartão continuam na taxa/liquidação genéricas de `card`.
     */
    public static function platformHasSavedTable(): bool
    {
        $raw = Setting::get('merchant_fee_rules', null, null);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw) || ! isset($raw['card_installments']) || ! is_array($raw['card_installments'])) {
            return false;
        }

        return $raw['card_installments'] !== [];
    }

    public static function fallbackDaysFromSettlement(): int
    {
        $card = EffectiveSettlementRules::platformDefaults()['card'] ?? [];

        return max(0, min(365, (int) ($card['days_to_available'] ?? 0)));
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @param  array{percent?: float|int|string, fixed?: float|int|string}  $cardFee
     * @return array<int, array{percent: float, fixed: float, days_to_available: int}>
     */
    public static function normalizeRules(?array $raw, array $cardFee, ?int $fallbackDays = null, ?int $max = null): array
    {
        $fallbackDays = max(0, min(365, $fallbackDays ?? self::fallbackDaysFromSettlement()));
        $percent = PercentDecimal::toFloat(PercentDecimal::normalize($cardFee['percent'] ?? 0));
        $fixed = round((float) ($cardFee['fixed'] ?? 0), 2);
        $max = max(self::MIN, min(self::MAX_MERCADOPAGO, $max ?? self::tableMax()));

        $out = [];
        for ($i = self::MIN; $i <= $max; $i++) {
            $row = is_array($raw) ? ($raw[$i] ?? $raw[(string) $i] ?? null) : null;
            if (! is_array($row)) {
                $out[$i] = [
                    'percent' => $percent,
                    'fixed' => $fixed,
                    'days_to_available' => $fallbackDays,
                ];

                continue;
            }

            $out[$i] = [
                'percent' => self::hasValue($row, 'percent')
                    ? PercentDecimal::toFloat(PercentDecimal::normalize($row['percent']))
                    : $percent,
                'fixed' => self::hasValue($row, 'fixed')
                    ? round((float) $row['fixed'], 2)
                    : $fixed,
                'days_to_available' => self::hasValue($row, 'days_to_available')
                    ? max(0, min(365, (int) $row['days_to_available']))
                    : $fallbackDays,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int|string, array{percent?: float, fixed?: float, days_to_available?: int}>  $rules
     * @return array{percent: float, fixed: float, days_to_available: int}
     */
    public static function ruleFor(array $rules, int $installments): array
    {
        $n = self::clampCount($installments);
        $row = $rules[$n] ?? $rules[(string) $n] ?? null;
        if (! is_array($row)) {
            for ($i = $n - 1; $i >= self::MIN; $i--) {
                $candidate = $rules[$i] ?? $rules[(string) $i] ?? null;
                if (is_array($candidate)) {
                    $row = $candidate;
                    break;
                }
            }
        }
        if (! is_array($row)) {
            return ['percent' => 0.0, 'fixed' => 0.0, 'days_to_available' => 0];
        }

        return [
            'percent' => PercentDecimal::toFloat(PercentDecimal::normalize($row['percent'] ?? 0)),
            'fixed' => round((float) ($row['fixed'] ?? 0), 2),
            'days_to_available' => max(0, min(365, (int) ($row['days_to_available'] ?? 0))),
        ];
    }

    /**
     * Divide o líquido em N fatias; o resto de centavos vai para a última.
     *
     * @return list<array{index: int, amount: float}>
     */
    public static function splitAmount(float $amount, int $parts): array
    {
        $parts = max(1, $parts);
        $cents = (int) round($amount * 100);
        $base = intdiv($cents, $parts);
        $remainder = $cents - ($base * $parts);
        $out = [];
        for ($i = 1; $i <= $parts; $i++) {
            $slice = $base + ($i === $parts ? $remainder : 0);
            $out[] = [
                'index' => $i,
                'amount' => round($slice / 100, 2),
            ];
        }

        return $out;
    }

    public static function shouldSplit(int $installments, int $daysToAvailable): bool
    {
        return $installments > 1 && $daysToAvailable > 0;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function hasValue(array $row, string $key): bool
    {
        return array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null;
    }
}
