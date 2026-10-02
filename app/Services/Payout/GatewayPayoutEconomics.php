<?php

namespace App\Services\Payout;

use App\Models\GatewayCredential;
use App\Support\PercentDecimal;

class GatewayPayoutEconomics
{
    public const DEFAULT_MIN_PAYOUT_BRL = 7.0;

    /** @var list<string> */
    private const ECONOMICS_SLUGS = [
        'cajupay',
        'spacepag',
        'woovi',
        'bspay',
        'versell',
        'xflow',
        'okto',
        'onlyup',
    ];

    /**
     * Economia mínima para o gateway de payout ativo (primeiro conectado na ordem fixa).
     *
     * @return array{
     *     required_min_net: float,
     *     payout_min_brl: float,
     *     admin_fee_pix_brl: float,
     *     admin_fee_payout_brl: float,
     *     admin_fee_pix_percent: float,
     *     admin_fee_payout_percent: float
     * }
     */
    public static function forActiveGateway(): array
    {
        $slug = PlatformPayoutGateway::activeSlug();
        if ($slug === null) {
            return self::defaults();
        }

        return self::fromSlug($slug);
    }

    /**
     * @return array{
     *     required_min_net: float,
     *     payout_min_brl: float,
     *     admin_fee_pix_brl: float,
     *     admin_fee_payout_brl: float,
     *     admin_fee_pix_percent: float,
     *     admin_fee_payout_percent: float
     * }
     */
    public static function fromSlug(string $slug): array
    {
        if ($slug === 'cajupay') {
            // Contas multi-CajuPay (CajuPayAccount) — não a credencial legada GatewayCredential.
            $e = \App\Services\CajuPay\CajuPayCredentialEconomics::fromGateway();

            return [
                'required_min_net' => $e['required_min_net'],
                'payout_min_brl' => $e['cajupay_payout_min_brl'],
                'admin_fee_pix_brl' => $e['cajupay_admin_fee_pix_brl'],
                'admin_fee_payout_brl' => $e['cajupay_admin_fee_payout_brl'],
                'admin_fee_pix_percent' => $e['cajupay_admin_fee_pix_percent'],
                'admin_fee_payout_percent' => $e['cajupay_admin_fee_payout_percent'],
            ];
        }

        $cred = GatewayCredential::resolveForPayment(null, $slug);
        if ($cred === null || ! $cred->is_connected) {
            return self::defaults();
        }

        return self::fromCredentialsArray($slug, $cred->getDecryptedCredentials());
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{
     *     required_min_net: float,
     *     payout_min_brl: float,
     *     admin_fee_pix_brl: float,
     *     admin_fee_payout_brl: float,
     *     admin_fee_pix_percent: float,
     *     admin_fee_payout_percent: float
     * }
     */
    public static function fromCredentialsArray(string $slug, array $credentials): array
    {
        if (! in_array($slug, self::ECONOMICS_SLUGS, true)) {
            return self::defaults();
        }

        $minPayout = self::parseNonNegative($credentials[$slug.'_payout_min_brl'] ?? null, self::DEFAULT_MIN_PAYOUT_BRL);
        $feePix = self::parseNonNegative($credentials[$slug.'_admin_fee_pix_brl'] ?? null, 0.0);
        $feePayout = self::parseNonNegative($credentials[$slug.'_admin_fee_payout_brl'] ?? null, 0.0);
        $feePixPct = self::parsePercent($credentials[$slug.'_admin_fee_pix_percent'] ?? null);
        $feePayoutPct = self::parsePercent($credentials[$slug.'_admin_fee_payout_percent'] ?? null);

        // Piso técnico do adquirente = mínimo líquido configurado.
        // Taxas admin (PIX/saque) não entram no piso do seller — só KPIs + valor da API de cashout.
        $required = round($minPayout, 2);

        return [
            'required_min_net' => $required,
            'payout_min_brl' => $minPayout,
            'admin_fee_pix_brl' => $feePix,
            'admin_fee_payout_brl' => $feePayout,
            'admin_fee_pix_percent' => $feePixPct,
            'admin_fee_payout_percent' => $feePayoutPct,
        ];
    }

    /**
     * @return array{
     *     required_min_net: float,
     *     payout_min_brl: float,
     *     admin_fee_pix_brl: float,
     *     admin_fee_payout_brl: float,
     *     admin_fee_pix_percent: float,
     *     admin_fee_payout_percent: float
     * }
     */
    private static function defaults(): array
    {
        return [
            'required_min_net' => self::DEFAULT_MIN_PAYOUT_BRL,
            'payout_min_brl' => self::DEFAULT_MIN_PAYOUT_BRL,
            'admin_fee_pix_brl' => 0.0,
            'admin_fee_payout_brl' => 0.0,
            'admin_fee_pix_percent' => 0.0,
            'admin_fee_payout_percent' => 0.0,
        ];
    }

    private static function parseNonNegative(mixed $value, float $defaultWhenEmpty): float
    {
        if ($value === null) {
            return $defaultWhenEmpty;
        }
        if (is_numeric($value)) {
            return max(0.0, round((float) $value, 2));
        }
        $s = trim((string) $value);
        if ($s === '') {
            return $defaultWhenEmpty;
        }
        $normalized = str_replace([' ', ','], ['', '.'], $s);
        if ($normalized === '' || ! is_numeric($normalized)) {
            return $defaultWhenEmpty;
        }

        return max(0.0, round((float) $normalized, 2));
    }

    /**
     * Percentual 0–100 (até 4 casas), vazio => 0.
     */
    private static function parsePercent(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = PercentDecimal::normalize($value);
        $pct = PercentDecimal::toFloat($normalized);

        return max(0.0, min(100.0, $pct));
    }

    /**
     * Valor a enviar na API de payout quando o adquirente desconta taxa (fixo e/ou %) do valor da ordem:
     * o infoprodutor deve receber exatamente o líquido informado em $sellerNet.
     *
     * Modelo: API − fixo − (pct% × API) = líquido  ⇒  API = (líquido + fixo) / (1 − pct/100).
     * Com pct = 0 reduz a líquido + fixo (comportamento histórico).
     */
    public static function transferAmountBrlForApi(
        float $sellerNet,
        float $adminFeePayoutBrl,
        float $adminFeePayoutPercent = 0.0,
    ): float {
        $fixed = max(0.0, $adminFeePayoutBrl);
        $pct = max(0.0, min(100.0, $adminFeePayoutPercent));
        $base = max(0.0, $sellerNet) + $fixed;

        if ($pct <= 0.0) {
            return round($base, 2);
        }

        $factor = 1.0 - ($pct / 100.0);
        if ($factor <= 0.0) {
            // 100% tornaria o gross-up impossível; preserva base (só fixo + líquido).
            return round($base, 2);
        }

        return round($base / $factor, 2);
    }

    /**
     * Custo estimado do adquirente no cash-out (valor enviado na API − líquido do seller).
     */
    public static function payoutFeeCostBrl(
        float $sellerNet,
        float $adminFeePayoutBrl,
        float $adminFeePayoutPercent = 0.0,
    ): float {
        $api = self::transferAmountBrlForApi($sellerNet, $adminFeePayoutBrl, $adminFeePayoutPercent);

        return round(max(0.0, $api - max(0.0, $sellerNet)), 2);
    }

    /**
     * Custo estimado do adquirente no cash-in PIX: % do bruto + fixo.
     */
    public static function pixInFeeCostBrl(
        float $orderGrossBrl,
        float $adminFeePixBrl,
        float $adminFeePixPercent = 0.0,
    ): float {
        $fixed = max(0.0, $adminFeePixBrl);
        $pct = max(0.0, min(100.0, $adminFeePixPercent));

        if ($pct <= 0.0 && $fixed <= 0.0) {
            return 0.0;
        }

        return PercentDecimal::feeFromGross(
            max(0.0, $orderGrossBrl),
            PercentDecimal::normalize($pct),
            $fixed,
        )['fee'];
    }
}
