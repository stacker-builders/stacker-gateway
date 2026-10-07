<?php

namespace App\Support;

use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Services\Payout\GatewayPayoutEconomics;

/**
 * Resumo curto das taxas de entrada (in) e saída (out) para o card do adquirente.
 */
final class GatewayAdminFeeSummary
{
    public static function labelForCredential(string $slug, ?GatewayCredential $credential): ?string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        if ($slug === 'cajupay') {
            $account = CajuPayAccount::query()->orderByDesc('is_default')->orderBy('id')->first();
            if ($account !== null) {
                $fromAccount = self::labelFromCredentials($slug, $account->getDecryptedCredentials());
                if ($fromAccount !== null) {
                    return $fromAccount;
                }
            }
        }

        if ($credential === null) {
            return null;
        }

        return self::labelFromCredentials($slug, $credential->getDecryptedCredentials());
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public static function labelFromCredentials(string $slug, array $credentials): ?string
    {
        $economics = GatewayPayoutEconomics::fromCredentialsArray($slug, $credentials);

        return self::format(
            (float) ($economics['admin_fee_pix_brl'] ?? 0),
            (float) ($economics['admin_fee_pix_percent'] ?? 0),
            (float) ($economics['admin_fee_payout_brl'] ?? 0),
            (float) ($economics['admin_fee_payout_percent'] ?? 0),
        );
    }

    public static function format(float $inBrl, float $inPercent, float $outBrl, float $outPercent): ?string
    {
        $in = self::formatSide($inBrl, $inPercent);
        $out = self::formatSide($outBrl, $outPercent);
        if ($in === null && $out === null) {
            return null;
        }

        $parts = [];
        if ($in !== null) {
            $parts[] = 'in = '.$in;
        }
        if ($out !== null) {
            $parts[] = 'out = '.$out;
        }

        return 'taxa: '.implode(' / ', $parts);
    }

    private static function formatSide(float $brl, float $percent): ?string
    {
        $hasBrl = $brl > 0;
        $hasPercent = $percent > 0;
        if (! $hasBrl && ! $hasPercent) {
            return null;
        }

        $parts = [];
        if ($hasBrl) {
            $parts[] = number_format($brl, 2, ',', '.');
        }
        if ($hasPercent) {
            $parts[] = self::formatPercent($percent).'%';
        }

        return implode(' + ', $parts);
    }

    private static function formatPercent(float $percent): string
    {
        $normalized = PercentDecimal::normalize($percent);
        if (! str_contains($normalized, '.')) {
            return $normalized;
        }

        [$whole, $fraction] = explode('.', $normalized, 2);
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $whole : $whole.','.$fraction;
    }
}
