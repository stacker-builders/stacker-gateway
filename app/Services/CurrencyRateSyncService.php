<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyRateSyncService
{
    public const UPDATED_AT_KEY = 'currencies_rates_updated_at';

    private const API_URL = 'https://api.frankfurter.dev/v1/latest';

    /**
     * Atualiza rate_to_brl das moedas estrangeiras gravadas na plataforma.
     * A data da última atualização efetiva só muda quando pelo menos uma taxa é gravada.
     *
     * @return array{updated: int, attempted: int}
     */
    public function sync(?CarbonInterface $now = null): array
    {
        $currencies = $this->currencies();
        $codes = $this->foreignCodes($currencies);
        if ($codes === []) {
            return ['updated' => 0, 'attempted' => 0];
        }

        $quote = $this->fetchRates($codes);
        $rates = $quote['rates'] ?? [];
        if ($rates === []) {
            return ['updated' => 0, 'attempted' => count($codes)];
        }

        $updated = 0;
        foreach ($currencies as $index => $currency) {
            if (! is_array($currency)) {
                continue;
            }
            $code = strtoupper(trim((string) ($currency['code'] ?? '')));
            if ($code === '' || $code === 'BRL' || ! isset($rates[$code]) || ! is_numeric($rates[$code])) {
                continue;
            }
            $currencies[$index]['rate_to_brl'] = (float) $rates[$code];
            $updated++;
        }

        if ($updated === 0) {
            return ['updated' => 0, 'attempted' => count($codes)];
        }

        Setting::set('currencies', array_values($currencies), null);
        $moment = ($now ?? Carbon::now('America/Sao_Paulo'))->timezone('America/Sao_Paulo');
        Setting::set(self::UPDATED_AT_KEY, $moment->toIso8601String(), null);

        return ['updated' => $updated, 'attempted' => count($codes)];
    }

    public static function updatedAtLabel(): ?string
    {
        $raw = Setting::get(self::UPDATED_AT_KEY, null, null);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)
                ->timezone('America/Sao_Paulo')
                ->format('d/m/Y \à\s H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currencies(): array
    {
        $raw = Setting::get('currencies', null, null);
        $currencies = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($currencies) || $currencies === []) {
            $currencies = config('products.currencies', []);
        }

        return array_values(is_array($currencies) ? $currencies : []);
    }

    /**
     * @param  array<int, mixed>  $currencies
     * @return list<string>
     */
    private function foreignCodes(array $currencies): array
    {
        $codes = [];
        foreach ($currencies as $currency) {
            if (! is_array($currency)) {
                continue;
            }
            $code = strtoupper(trim((string) ($currency['code'] ?? '')));
            if ($code !== '' && $code !== 'BRL') {
                $codes[$code] = $code;
            }
        }

        return array_values($codes);
    }

    /**
     * @param  list<string>  $codes
     * @return array{date?: string, rates: array<string, float>}
     */
    private function fetchRates(array $codes): array
    {
        $batch = $this->requestRates($codes);
        if ($batch !== null) {
            return $batch;
        }

        $rates = [];
        $date = null;
        foreach ($codes as $code) {
            $single = $this->requestRates([$code]);
            if ($single === null || ! isset($single['rates'][$code])) {
                continue;
            }
            $rates[$code] = $single['rates'][$code];
            $date = $single['date'] ?? $date;
        }

        return ['date' => $date, 'rates' => $rates];
    }

    /**
     * @param  list<string>  $codes
     * @return array{date?: string, rates: array<string, float>}|null
     */
    private function requestRates(array $codes): ?array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get(self::API_URL, [
                    'from' => 'BRL',
                    'to' => implode(',', $codes),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Falha ao consultar a taxa de câmbio.', [
                'codes' => $codes,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('A API de câmbio recusou a consulta.', [
                'codes' => $codes,
                'status' => $response->status(),
            ]);

            return null;
        }

        $payload = $response->json();
        $rates = is_array($payload) ? ($payload['rates'] ?? null) : null;
        if (! is_array($rates) || $rates === []) {
            return null;
        }

        $normalized = [];
        foreach ($rates as $code => $rate) {
            if (is_numeric($rate)) {
                $normalized[strtoupper((string) $code)] = (float) $rate;
            }
        }

        if ($normalized === []) {
            return null;
        }

        return [
            'date' => is_array($payload) ? ($payload['date'] ?? null) : null,
            'rates' => $normalized,
        ];
    }
}
