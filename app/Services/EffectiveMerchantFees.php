<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\CardInstallmentEconomics;
use App\Support\PercentDecimal;

class EffectiveMerchantFees
{
    /** Origens em que o PIX usa a taxa `api_pix` (REST ou checkout hospedado criado pela API). Cartão/boleto seguem taxas de checkout. */
    public const API_ORDER_SOURCES = ['api', 'api_checkout_pro'];

    /** @var list<string> */
    private const RULE_KEYS = ['pix', 'api_pix', 'pixgo', 'open_finance', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal'];

    /** Canais de venda que aceitam tabela low ticket (saque fica de fora). */
    private const SALE_RULE_KEYS = ['pix', 'api_pix', 'pixgo', 'open_finance', 'card', 'apple_pay', 'google_pay', 'boleto'];

    /**
     * @return array{
     *     pix: array{percent: float, fixed: float},
     *     api_pix: array{percent: float, fixed: float},
     *     pixgo: array{percent: float, fixed: float},
     *     open_finance: array{percent: float, fixed: float},
     *     card: array{percent: float, fixed: float},
     *     apple_pay: array{percent: float, fixed: float},
     *     google_pay: array{percent: float, fixed: float},
     *     boleto: array{percent: float, fixed: float},
     *     withdrawal: array{percent: float, fixed: float},
     *     card_installments: array<int, array{percent: float, fixed: float, days_to_available: int}>,
     *     low_ticket: array{enabled: bool, max_amount: float, max_order_amount: float, max_order_amount_enabled: bool}&array<string, mixed>
     * }
     */
    public static function platformDefaults(): array
    {
        $raw = Setting::get('merchant_fee_rules', null, null);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $base = [
            'pix' => ['percent' => 0.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 0.0, 'fixed' => 0.0],
            'pixgo' => ['percent' => 0.0, 'fixed' => 0.0],
            'open_finance' => ['percent' => 0.0, 'fixed' => 0.0],
            'card' => ['percent' => 0.0, 'fixed' => 0.0],
            'apple_pay' => ['percent' => 0.0, 'fixed' => 0.0],
            'google_pay' => ['percent' => 0.0, 'fixed' => 0.0],
            'boleto' => ['percent' => 0.0, 'fixed' => 0.0],
            'withdrawal' => ['percent' => 0.0, 'fixed' => 0.0],
        ];
        if (is_array($raw)) {
            foreach (self::RULE_KEYS as $k) {
                if (! isset($raw[$k]) || ! is_array($raw[$k])) {
                    continue;
                }
                $base[$k]['percent'] = PercentDecimal::toFloat(PercentDecimal::normalize($raw[$k]['percent'] ?? 0));
                $base[$k]['fixed'] = round((float) ($raw[$k]['fixed'] ?? 0), 2);
            }
        }
        // Primeira configuração / legado: sem bloco `api_pix`, herda PIX checkout.
        if (! is_array($raw) || ! isset($raw['api_pix']) || ! is_array($raw['api_pix'])) {
            $base['api_pix'] = $base['pix'];
        }
        // Legado: sem bloco `pixgo`, herda PIX checkout (comportamento anterior).
        if (! is_array($raw) || ! isset($raw['pixgo']) || ! is_array($raw['pixgo'])) {
            $base['pixgo'] = $base['pix'];
        }
        // Legado: sem bloco `open_finance`, herda PIX (comportamento anterior à taxa dedicada).
        if (! is_array($raw) || ! isset($raw['open_finance']) || ! is_array($raw['open_finance'])) {
            $base['open_finance'] = $base['pix'];
        }
        // Wallets CajuPay: sem bloco próprio, herdam taxa de cartão checkout.
        if (! is_array($raw) || ! isset($raw['apple_pay']) || ! is_array($raw['apple_pay'])) {
            $base['apple_pay'] = $base['card'];
        }
        if (! is_array($raw) || ! isset($raw['google_pay']) || ! is_array($raw['google_pay'])) {
            $base['google_pay'] = $base['card'];
        }

        $installRaw = is_array($raw) ? ($raw['card_installments'] ?? null) : null;
        $base['card_installments'] = CardInstallmentEconomics::normalizeRules(
            is_array($installRaw) ? $installRaw : null,
            $base['card']
        );
        $base['low_ticket'] = self::normalizeLowTicket(
            is_array($raw) ? ($raw['low_ticket'] ?? null) : null
        );

        return $base;
    }

    /**
     * Método de taxa alinhado ao checkout (ex.: CajuPay SDK: apple_pay / google_pay em metadata).
     */
    public static function feeMethodForOrder(Order $order): string
    {
        $method = $order->payment_method;
        if ($method === null || $method === '') {
            $meta = $order->metadata ?? [];
            $method = is_array($meta) ? ($meta['checkout_payment_method'] ?? null) : null;
        }
        $feeMethod = (string) ($method ?: 'pix');
        $meta = is_array($order->metadata ?? null) ? $order->metadata : [];
        $cajuWallet = isset($meta['cajupay_wallet']) ? strtolower(trim((string) $meta['cajupay_wallet'])) : '';
        if (($method === 'card' || $feeMethod === 'card') && in_array($cajuWallet, ['apple_pay', 'google_pay'], true)) {
            return $cajuWallet;
        }

        if (in_array($feeMethod, ['paypal'], true)) {
            return 'card';
        }

        return $feeMethod;
    }

    /**
     * @return array{
     *     pix: array{percent: float, fixed: float},
     *     api_pix: array{percent: float, fixed: float},
     *     pixgo: array{percent: float, fixed: float},
     *     card: array{percent: float, fixed: float},
     *     apple_pay: array{percent: float, fixed: float},
     *     google_pay: array{percent: float, fixed: float},
     *     boleto: array{percent: float, fixed: float},
     *     withdrawal: array{percent: float, fixed: float}
     * }
     */
    public static function forTenant(int $tenantId): array
    {
        // tenant_id em pedidos/carteira é o id do infoprodutor dono; overrides ficam em users.merchant_fees.
        $owner = User::query()
            ->where('id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($owner === null) {
            $owner = User::query()
                ->where('tenant_id', $tenantId)
                ->where('role', User::ROLE_INFOPRODUTOR)
                ->first();
        }
        if ($owner === null || empty($owner->merchant_fees) || ! is_array($owner->merchant_fees)) {
            return self::platformDefaults();
        }

        return self::fromOverrides($owner->merchant_fees);
    }

    /**
     * Taxas efetivas a partir dos overrides crus (null/vazio = herdar só da plataforma).
     *
     * @param  array<string, mixed>|null  $rawOverrides
     * @return array{
     *     pix: array{percent: float, fixed: float},
     *     api_pix: array{percent: float, fixed: float},
     *     pixgo: array{percent: float, fixed: float},
     *     open_finance: array{percent: float, fixed: float},
     *     card: array{percent: float, fixed: float},
     *     apple_pay: array{percent: float, fixed: float},
     *     google_pay: array{percent: float, fixed: float},
     *     boleto: array{percent: float, fixed: float},
     *     withdrawal: array{percent: float, fixed: float}
     * }
     */
    public static function fromOverrides(?array $rawOverrides): array
    {
        $defaults = self::platformDefaults();
        if (! is_array($rawOverrides) || $rawOverrides === []) {
            return $defaults;
        }
        foreach (self::RULE_KEYS as $k) {
            if (! isset($rawOverrides[$k]) || ! is_array($rawOverrides[$k])) {
                continue;
            }
            if (array_key_exists('percent', $rawOverrides[$k])) {
                $defaults[$k]['percent'] = PercentDecimal::toFloat(PercentDecimal::normalize($rawOverrides[$k]['percent']));
            }
            if (array_key_exists('fixed', $rawOverrides[$k])) {
                $defaults[$k]['fixed'] = round((float) $rawOverrides[$k]['fixed'], 2);
            }
        }

        return self::applyDerivedInheritance($defaults, $rawOverrides);
    }

    /**
     * Herda canais derivados do pai efetivo quando o infoprodutor não definiu o filho explicitamente.
     *
     * @param  array<string, array{percent: float, fixed: float}>  $effective
     * @param  array<string, mixed>|null  $rawOverrides
     * @return array<string, array{percent: float, fixed: float}>
     */
    private static function applyDerivedInheritance(array $effective, ?array $rawOverrides): array
    {
        // Só herda quando o pai foi customizado. Override só de api_pix/pixgo/saque/etc.
        // não pode puxar wallets para card nem api_pix/pixgo para pix global.
        if (! self::overrideBlockIsExplicit($rawOverrides, 'api_pix')
            && self::overrideBlockIsExplicit($rawOverrides, 'pix')) {
            $effective['api_pix'] = $effective['pix'];
        }
        if (! self::overrideBlockIsExplicit($rawOverrides, 'pixgo')
            && self::overrideBlockIsExplicit($rawOverrides, 'pix')) {
            $effective['pixgo'] = $effective['pix'];
        }
        if (! self::overrideBlockIsExplicit($rawOverrides, 'open_finance')
            && self::overrideBlockIsExplicit($rawOverrides, 'pix')) {
            $effective['open_finance'] = $effective['pix'];
        }
        if (! self::overrideBlockIsExplicit($rawOverrides, 'apple_pay')
            && self::overrideBlockIsExplicit($rawOverrides, 'card')) {
            $effective['apple_pay'] = $effective['card'];
        }
        if (! self::overrideBlockIsExplicit($rawOverrides, 'google_pay')
            && self::overrideBlockIsExplicit($rawOverrides, 'card')) {
            $effective['google_pay'] = $effective['card'];
        }

        return $effective;
    }

    /**
     * @param  array<string, mixed>|null  $rawOverrides
     */
    private static function overrideBlockIsExplicit(?array $rawOverrides, string $key): bool
    {
        if (! is_array($rawOverrides) || ! isset($rawOverrides[$key]) || ! is_array($rawOverrides[$key])) {
            return false;
        }

        $block = $rawOverrides[$key];

        return (array_key_exists('percent', $block) && $block['percent'] !== '' && $block['percent'] !== null)
            || (array_key_exists('fixed', $block) && $block['fixed'] !== '' && $block['fixed'] !== null);
    }

    /**
     * @param  'pix'|'open_finance'|'card'|'apple_pay'|'google_pay'|'boleto'|'withdrawal'|'pix_auto'  $method
     * @return array{fee: float, net: float, gross: float, percent: float, fixed: float}
     */
    public static function calculateSaleFee(int $tenantId, string $method, float $gross, ?string $source = null, ?int $installments = null, ?float $productAmount = null, ?float $itemsAmount = null): array
    {
        $map = [
            'pix' => 'pix',
            'open_finance' => 'open_finance',
            'card' => 'card',
            'apple_pay' => 'apple_pay',
            'google_pay' => 'google_pay',
            'boleto' => 'boleto',
            'paypal' => 'card',
            'pix_auto' => 'pix',
        ];
        $key = $map[$method] ?? $method;
        if (! in_array($key, ['pix', 'open_finance', 'card', 'apple_pay', 'google_pay', 'boleto'], true)) {
            $key = 'pix';
        }
        $rules = self::forTenant($tenantId);
        if ($key === 'pix' && $source === 'pixgo') {
            $key = 'pixgo';
        } elseif ($key === 'pix' && $source !== null && in_array($source, self::API_ORDER_SOURCES, true)) {
            $key = 'api_pix';
        }
        $percent = PercentDecimal::toFloat(PercentDecimal::normalize($rules[$key]['percent'] ?? 0));
        $fixed = round((float) ($rules[$key]['fixed'] ?? 0), 2);
        $useLowTicket = self::appliesLowTicket($rules, $productAmount, $itemsAmount, $key);
        if ($useLowTicket) {
            $lowBlock = is_array($rules['low_ticket'][$key] ?? null) ? $rules['low_ticket'][$key] : [];
            $percent = PercentDecimal::toFloat(PercentDecimal::normalize($lowBlock['percent'] ?? 0));
            $fixed = round((float) ($lowBlock['fixed'] ?? 0), 2);
        } elseif ($key === 'card' && $installments !== null && CardInstallmentEconomics::platformHasSavedTable()) {
            $row = CardInstallmentEconomics::ruleFor(
                $rules['card_installments'] ?? [],
                $installments
            );
            $percent = $row['percent'];
            $fixed = $row['fixed'];
        }
        $amounts = PercentDecimal::feeFromGross($gross, PercentDecimal::normalize($percent), $fixed);

        return [
            'fee' => $amounts['fee'],
            'net' => $amounts['net'],
            'gross' => $gross,
            'percent' => $percent,
            'fixed' => $fixed,
        ];
    }

    /**
     * Taxa sobre valor solicitado de saque (bruto).
     *
     * @return array{fee: float, net: float, gross: float}
     */
    public static function calculateWithdrawalFee(int $tenantId, float $requestedAmount): array
    {
        $rules = self::forTenant($tenantId);
        $percent = PercentDecimal::toFloat(PercentDecimal::normalize($rules['withdrawal']['percent'] ?? 0));
        $fixed = round((float) ($rules['withdrawal']['fixed'] ?? 0), 2);
        $amounts = PercentDecimal::feeFromGross($requestedAmount, PercentDecimal::normalize($percent), $fixed);

        return [
            'fee' => $amounts['fee'],
            'net' => $amounts['net'],
            'gross' => $requestedAmount,
        ];
    }

    /**
     * Menor valor bruto (solicitado) tal que o líquido após taxa da plataforma seja >= targetNet.
     */
    public static function minimumWithdrawalGrossForTargetNet(int $tenantId, float $targetNet): ?float
    {
        if ($targetNet <= 0) {
            return 0.01;
        }

        $rules = self::forTenant($tenantId);
        $p = (float) $rules['withdrawal']['percent'];
        $f = (float) $rules['withdrawal']['fixed'];
        $rate = 1 - ($p / 100.0);
        if ($rate <= 0) {
            return null;
        }

        $g = max(0.01, round(($targetNet + $f) / $rate, 2));

        for ($i = 0; $i < 500000; $i++) {
            $calc = self::calculateWithdrawalFee($tenantId, $g);
            if ($calc['net'] + 0.0001 >= $targetNet) {
                return round($g, 2);
            }
            $g = round($g + 0.01, 2);
        }

        return null;
    }

    /**
     * Valor do produto principal do checkout (sem order bump, upsell ou frete).
     */
    public static function mainProductAmount(Order $order): float
    {
        return $order->mainProductAmount();
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    public static function appliesLowTicket(array $rules, ?float $productAmount, ?float $itemsAmount = null, ?string $feeKey = null): bool
    {
        $low = $rules['low_ticket'] ?? null;
        if (! is_array($low) || empty($low['enabled']) || $productAmount === null) {
            return false;
        }

        $max = round((float) ($low['max_amount'] ?? 0), 2);
        if ($max <= 0 || round($productAmount, 2) > $max) {
            return false;
        }

        if ($feeKey !== null) {
            $methodBlock = is_array($low[$feeKey] ?? null) ? $low[$feeKey] : [];
            if (array_key_exists('enabled', $methodBlock) && ! filter_var($methodBlock['enabled'], FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
        }

        $orderCapOn = array_key_exists('max_order_amount_enabled', $low)
            ? filter_var($low['max_order_amount_enabled'], FILTER_VALIDATE_BOOLEAN)
            : round((float) ($low['max_order_amount'] ?? 0), 2) > 0;
        if ($orderCapOn) {
            $maxOrder = round((float) ($low['max_order_amount'] ?? 0), 2);
            if ($maxOrder > 0 && $itemsAmount !== null && round($itemsAmount, 2) >= $maxOrder) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{enabled: bool, max_amount: float, max_order_amount: float, max_order_amount_enabled: bool}&array<string, array{percent: float, fixed: float, enabled: bool}|bool|float>
     */
    public static function normalizeLowTicket(?array $raw): array
    {
        $out = [
            'enabled' => false,
            'max_amount' => 0.0,
            'max_order_amount' => 0.0,
            'max_order_amount_enabled' => false,
        ];
        foreach (self::SALE_RULE_KEYS as $key) {
            $out[$key] = ['percent' => 0.0, 'fixed' => 0.0, 'enabled' => true];
        }

        if (! is_array($raw)) {
            return $out;
        }

        $out['enabled'] = filter_var($raw['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $out['max_amount'] = max(0.0, round((float) ($raw['max_amount'] ?? 0), 2));
        $out['max_order_amount'] = max(0.0, round((float) ($raw['max_order_amount'] ?? 0), 2));
        if (array_key_exists('max_order_amount_enabled', $raw)) {
            $out['max_order_amount_enabled'] = filter_var($raw['max_order_amount_enabled'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $out['max_order_amount_enabled'] = $out['max_order_amount'] > 0;
        }

        foreach (self::SALE_RULE_KEYS as $key) {
            if (! isset($raw[$key]) || ! is_array($raw[$key])) {
                continue;
            }
            $enabled = true;
            if (array_key_exists('enabled', $raw[$key])) {
                $enabled = filter_var($raw[$key]['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            $out[$key] = [
                'percent' => PercentDecimal::toFloat(PercentDecimal::normalize($raw[$key]['percent'] ?? 0)),
                'fixed' => round((float) ($raw[$key]['fixed'] ?? 0), 2),
                'enabled' => $enabled,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function saleRuleKeys(): array
    {
        return self::SALE_RULE_KEYS;
    }
}
