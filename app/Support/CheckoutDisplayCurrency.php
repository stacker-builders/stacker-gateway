<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;

/**
 * Moeda escolhida na vitrine do checkout (BRL, USD, EUR) e a mesma cotação usada na tela.
 */
final class CheckoutDisplayCurrency
{
    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return ['BRL', 'USD', 'EUR'];
    }

    public static function normalize(mixed $currency): string
    {
        $code = strtoupper(trim((string) $currency));

        return in_array($code, self::allowed(), true) ? $code : 'BRL';
    }

    public static function fromOrder(Order $order): string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];

        return self::normalize($meta['display_currency'] ?? null);
    }

    /**
     * @return array{brl_eur: float, brl_usd: float}
     */
    public static function rates(): array
    {
        $currenciesRaw = Setting::get('currencies', null, null);
        $currencies = $currenciesRaw
            ? (is_string($currenciesRaw) ? json_decode($currenciesRaw, true) : $currenciesRaw)
            : config('products.currencies');
        $currencies = is_array($currencies) ? $currencies : config('products.currencies');

        $rates = [
            'brl_eur' => (float) config('products.rates.brl_eur', 0.16),
            'brl_usd' => (float) config('products.rates.brl_usd', 0.18),
        ];
        foreach ($currencies as $currency) {
            if (! is_array($currency)) {
                continue;
            }
            $code = strtoupper((string) ($currency['code'] ?? ''));
            $rate = (float) ($currency['rate_to_brl'] ?? 0);
            if ($rate <= 0) {
                continue;
            }
            if ($code === 'EUR') {
                $rates['brl_eur'] = $rate;
            } elseif ($code === 'USD') {
                $rates['brl_usd'] = $rate;
            }
        }

        return $rates;
    }

    public static function formatStored(float $storedPrice, string $storedCurrency, string $displayCurrency): string
    {
        $displayCurrency = self::normalize($displayCurrency);
        $rates = self::rates();
        $brl = MoneyDecimal::brlFromStorage($storedPrice, $storedCurrency, $rates);
        $amount = MoneyDecimal::storageFromBrl($brl, $displayCurrency, $rates);

        return self::formatAmount($amount, $displayCurrency);
    }

    public static function formatAmount(float $amount, string $currency): string
    {
        $currency = self::normalize($currency);
        if (class_exists(\NumberFormatter::class)) {
            $locale = match ($currency) {
                'EUR' => 'de_DE',
                'USD' => 'en_US',
                default => 'pt_BR',
            };
            $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency($amount, $currency);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return match ($currency) {
            'EUR' => '€ '.number_format($amount, 2, ',', '.'),
            'USD' => 'US$ '.number_format($amount, 2, '.', ','),
            default => 'R$ '.number_format($amount, 2, ',', '.'),
        };
    }
}
