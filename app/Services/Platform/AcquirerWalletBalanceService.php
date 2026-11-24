<?php

namespace App\Services\Platform;

use App\Gateways\Asaas\AsaasDriver;
use App\Gateways\Bspay\BspayDriver;
use App\Gateways\CajuPay\CajuPayDriver;
use App\Gateways\Efi\EfiDriver;
use App\Gateways\GatewayRegistry;
use App\Gateways\Stripe\StripeDriver;
use App\Gateways\Woovi\WooviDriver;
use App\Gateways\Xflow\XflowDriver;
use App\Gateways\Okto\OktoDriver;
use App\Gateways\Xpag\XpagDriver;
use App\Gateways\Versell\VersellCredentials;
use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Services\MercadoPago\MercadoPagoBalanceService;
use App\Services\Versell\VersellHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saldo disponível nas carteiras das adquirentes (PSP).
 * Isolado da analytics da dashboard: cache próprio e falha por adquirente.
 */
class AcquirerWalletBalanceService
{
    private const CACHE_TTL_SECONDS = 45;

    /** @var list<string> */
    private const BALANCE_SLUGS = ['cajupay', 'efi', 'woovi', 'mercadopago', 'asaas', 'stripe', 'versell', 'xflow', 'okto', 'xpag'];

    /**
     * @return list<array{
     *     id: string,
     *     slug: string,
     *     nome: string,
     *     conta: string|null,
     *     image: string|null,
     *     status: 'ok'|'error'|'inactive'|'unsupported',
     *     available: float|null,
     *     currency: string,
     *     error: string|null
     * }>
     */
    public function list(): array
    {
        $rows = [];

        foreach (self::BALANCE_SLUGS as $slug) {
            if (! GatewayRegistry::isAllowedAcquirer($slug)) {
                continue;
            }

            if ($slug === 'cajupay') {
                $targets = $this->cajupayTargets();
                if ($targets === []) {
                    $rows[] = $this->staticRow($slug, 'inactive');

                    continue;
                }
                foreach ($targets as $target) {
                    $rows[] = $this->cachedRow($target);
                }

                continue;
            }

            $credential = $this->platformCredential($slug);
            $creds = $credential !== null ? $credential->getDecryptedCredentials() : [];
            if ($credential === null || ! $this->hasUsableCredentials($slug, $creds)) {
                $rows[] = $this->staticRow($slug, 'inactive');

                continue;
            }

            $rows[] = $this->cachedRow([
                'id' => $slug.':credential:'.$credential->id,
                'slug' => $slug,
                'nome' => $this->gatewayName($slug),
                'conta' => null,
                'image' => $this->gatewayImage($slug),
                'cache_key' => 'acquirer-wallet:v2:'.$slug.':'.$credential->id,
                'fetch' => $slug === 'stripe'
                    ? fn (): array => $this->fetchStripe($creds)
                    : fn (): float => $this->fetchBySlug($slug, $creds),
            ]);
        }

        return $rows;
    }

    /**
     * @param  array{
     *     id: string,
     *     slug: string,
     *     nome: string,
     *     conta: string|null,
     *     image: string|null,
     *     cache_key: string,
     *     fetch: callable(): float|array{available: float, currency?: string}
     * }  $target
     * @return array{
     *     id: string,
     *     slug: string,
     *     nome: string,
     *     conta: string|null,
     *     image: string|null,
     *     status: 'ok'|'error'|'inactive'|'unsupported',
     *     available: float|null,
     *     currency: string,
     *     error: string|null
     * }
     */
    private function cachedRow(array $target): array
    {
        $base = [
            'id' => $target['id'],
            'slug' => $target['slug'],
            'nome' => $target['nome'],
            'conta' => $target['conta'],
            'image' => $target['image'],
            'currency' => 'BRL',
        ];

        try {
            $cached = Cache::remember(
                $target['cache_key'],
                self::CACHE_TTL_SECONDS,
                fn () => $target['fetch']()
            );

            if (is_array($cached)) {
                $available = (float) ($cached['available'] ?? 0);
                $currency = strtoupper((string) ($cached['currency'] ?? 'BRL'));
                if ($currency === '') {
                    $currency = 'BRL';
                }

                return array_merge($base, [
                    'status' => 'ok',
                    'available' => round($available, 2),
                    'currency' => $currency,
                    'error' => null,
                ]);
            }

            return array_merge($base, [
                'status' => 'ok',
                'available' => round((float) $cached, 2),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            Log::debug('AcquirerWalletBalanceService failed', [
                'slug' => $target['slug'],
                'id' => $target['id'],
                'message' => $e->getMessage(),
            ]);

            return array_merge($base, [
                'status' => 'error',
                'available' => null,
                'error' => 'Falha ao consultar saldo',
            ]);
        }
    }

    /**
     * @return list<array{
     *     id: string,
     *     slug: string,
     *     nome: string,
     *     conta: string|null,
     *     image: string|null,
     *     cache_key: string,
     *     fetch: callable(): float
     * }>
     */
    private function cajupayTargets(): array
    {
        $nome = $this->gatewayName('cajupay');
        $image = $this->gatewayImage('cajupay');
        $accounts = CajuPayAccount::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        if ($accounts->isNotEmpty()) {
            $targets = [];
            foreach ($accounts as $account) {
                if (! $account->is_enabled || ! $account->is_connected) {
                    continue;
                }
                $creds = $account->getDecryptedCredentials();
                if (trim((string) ($creds['public_key'] ?? '')) === '' || trim((string) ($creds['secret_key'] ?? '')) === '') {
                    continue;
                }
                $accountId = (int) $account->id;
                $targets[] = [
                    'id' => 'cajupay:account:'.$accountId,
                    'slug' => 'cajupay',
                    'nome' => $nome,
                    'conta' => trim((string) $account->name) !== '' ? (string) $account->name : null,
                    'image' => $image,
                    'cache_key' => 'acquirer-wallet:v1:cajupay:account:'.$accountId,
                    'fetch' => fn (): float => $this->fetchCajuPay($creds),
                ];
            }

            return $targets;
        }

        $credential = $this->platformCredential('cajupay');
        if ($credential === null) {
            return [];
        }
        $creds = $credential->getDecryptedCredentials();
        if (trim((string) ($creds['public_key'] ?? '')) === '' || trim((string) ($creds['secret_key'] ?? '')) === '') {
            return [];
        }

        return [[
            'id' => 'cajupay:credential:'.$credential->id,
            'slug' => 'cajupay',
            'nome' => $nome,
            'conta' => null,
            'image' => $image,
            'cache_key' => 'acquirer-wallet:v1:cajupay:credential:'.$credential->id,
            'fetch' => fn (): float => $this->fetchCajuPay($creds),
        ]];
    }

    /**
     * @return array{
     *     id: string,
     *     slug: string,
     *     nome: string,
     *     conta: string|null,
     *     image: string|null,
     *     status: 'inactive'|'unsupported',
     *     available: float|null,
     *     currency: string,
     *     error: string|null
     * }
     */
    private function staticRow(string $slug, string $status): array
    {
        return [
            'id' => $slug.':'.$status,
            'slug' => $slug,
            'nome' => $this->gatewayName($slug),
            'conta' => null,
            'image' => $this->gatewayImage($slug),
            'status' => $status,
            'available' => null,
            'currency' => 'BRL',
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function hasUsableCredentials(string $slug, array $credentials): bool
    {
        return match ($slug) {
            'bspay' => trim((string) ($credentials['client_id'] ?? '')) !== ''
                && trim((string) ($credentials['client_secret'] ?? '')) !== '',
            'efi' => $this->hasEfiBalanceCredentials($credentials),
            'woovi' => trim((string) ($credentials['app_id'] ?? $credentials['authorization'] ?? '')) !== '',
            'mercadopago' => trim((string) ($credentials['access_token'] ?? '')) !== '',
            'asaas' => trim((string) ($credentials['api_key'] ?? '')) !== '',
            'stripe' => trim((string) ($credentials['secret_key'] ?? '')) !== '',
            'versell' => VersellCredentials::isCashOutReady($credentials),
            'xflow' => trim((string) ($credentials['public_key'] ?? '')) !== ''
                && trim((string) ($credentials['secret_key'] ?? '')) !== '',
            'okto' => trim((string) ($credentials['access_token'] ?? '')) !== '',
            'xpag' => trim((string) ($credentials['client_id'] ?? '')) !== ''
                && trim((string) ($credentials['client_secret'] ?? '')) !== '',
            default => true,
        };
    }

    private function platformCredential(string $slug): ?GatewayCredential
    {
        return GatewayCredential::query()
            ->forTenant(null)
            ->where('gateway_slug', $slug)
            ->where('is_connected', true)
            ->enabledForPayments()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchBySlug(string $slug, array $credentials): float
    {
        return match ($slug) {
            'bspay' => $this->fetchBspay($credentials),
            'efi' => $this->fetchEfi($credentials),
            'woovi' => $this->fetchWoovi($credentials),
            'mercadopago' => $this->fetchMercadoPago($credentials),
            'asaas' => $this->fetchAsaas($credentials),
            'stripe' => $this->fetchStripe($credentials)['available'],
            'versell' => $this->fetchVersell($credentials),
            'xflow' => $this->fetchXflow($credentials),
            'okto' => $this->fetchOkto($credentials),
            'xpag' => $this->fetchXpag($credentials),
            default => throw new \RuntimeException('Adquirente sem consulta de saldo.'),
        };
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchCajuPay(array $credentials): float
    {
        $body = app(CajuPayDriver::class)->fetchWalletBalance($credentials);

        return $this->parseCajuPayAvailable($body);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchBspay(array $credentials): float
    {
        $payload = app(BspayDriver::class)->fetchAccountBalance($credentials);

        return $this->parseBspayAvailable($payload);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchEfi(array $credentials): float
    {
        $payload = app(EfiDriver::class)->fetchAccountBalance($credentials);

        return $this->parseEfiAvailable($payload);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchWoovi(array $credentials): float
    {
        $payload = app(WooviDriver::class)->fetchAccountBalance($credentials);

        return $this->parseWooviAvailable($payload);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchMercadoPago(array $credentials): float
    {
        $token = trim((string) ($credentials['access_token'] ?? ''));
        $balance = app(MercadoPagoBalanceService::class)->fetchBalance($token);

        return round((float) ($balance['available_balance'] ?? 0), 2);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchAsaas(array $credentials): float
    {
        $payload = app(AsaasDriver::class)->fetchAccountBalance($credentials);

        return round((float) $payload['balance'], 2);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{available: float, currency: string}
     */
    private function fetchStripe(array $credentials): array
    {
        $payload = app(StripeDriver::class)->fetchAccountBalance($credentials);

        return $this->parseStripeAvailableDetails($payload);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchVersell(array $credentials): float
    {
        $start = now()->subDays(7)->utc()->format('Y-m-d\TH:i:s\Z');
        $end = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $query = http_build_query([
            'event_date_start' => $start,
            'event_date_end' => $end,
            'page_limit' => 1,
            'sort_by' => 'EVENT_DATE',
            'sort_type' => 'DESC',
        ]);

        $response = app(VersellHttpClient::class)->request(
            VersellCredentials::API_CASH_OUT,
            $credentials,
            'GET',
            '/accounts/balances?'.$query
        );

        if (! $response->successful()) {
            throw new \RuntimeException('Versell: falha ao consultar saldo (HTTP '.$response->status().').');
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException('Versell: resposta de saldo inválida.');
        }

        return $this->parseVersellAvailable($body);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchXflow(array $credentials): float
    {
        $payload = app(XflowDriver::class)->fetchAccountBalance($credentials);

        return $this->parseXflowAvailable($payload);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchOkto(array $credentials): float
    {
        $payload = app(OktoDriver::class)->fetchAccountBalance($credentials);

        return $this->parseOktoAvailable($payload);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchXpag(array $credentials): float
    {
        $payload = app(XpagDriver::class)->fetchAccountBalance($credentials);

        return $this->parseXpagAvailable($payload);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function parseCajuPayAvailable(array $body): float
    {
        $node = $body['data'] ?? $body['wallet'] ?? $body['balance'] ?? $body;
        if (! is_array($node)) {
            $node = $body;
        }

        foreach (['available_cents', 'available_amount_cents', 'balance_cents', 'amount_cents'] as $key) {
            if (isset($node[$key]) && is_numeric($node[$key])) {
                return round(((float) $node[$key]) / 100, 2);
            }
        }

        foreach (['available', 'available_balance', 'balance'] as $key) {
            $value = $node[$key] ?? null;
            if (is_array($value) && isset($value['available']) && is_numeric($value['available'])) {
                return $this->centsOrReais((float) $value['available'], is_int($value['available']));
            }
            if (is_numeric($value)) {
                return $this->centsOrReais((float) $value, is_int($value) || (is_string($value) && ! str_contains($value, '.')));
            }
        }

        throw new \RuntimeException('CajuPay: saldo disponível não encontrado na resposta.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseBspayAvailable(array $payload): float
    {
        $data = $payload['data'] ?? $payload;
        if (! is_array($data)) {
            $data = $payload;
        }

        $fromCurrency = $this->numericFromCurrencyMap($data, 'BRL');
        if ($fromCurrency !== null) {
            return $fromCurrency;
        }

        $balanceRows = $data['balances'] ?? null;
        if (is_array($balanceRows)) {
            $fromRows = $this->bspayAvailableFromBalanceRows($balanceRows);
            if ($fromRows !== null) {
                return $fromRows;
            }

            $fromCurrency = $this->numericFromCurrencyMap($balanceRows, 'BRL');
            if ($fromCurrency !== null) {
                return $fromCurrency;
            }
        }

        if (array_is_list($data)) {
            $fromRows = $this->bspayAvailableFromBalanceRows($data);
            if ($fromRows !== null) {
                return $fromRows;
            }
        }

        $direct = $this->firstNumeric($data, ['available', 'available_balance', 'balance', 'amount']);
        if ($direct !== null) {
            return $direct;
        }

        throw new \RuntimeException('BSPay: saldo disponível não encontrado na resposta.');
    }

    /**
     * Envelope oficial GET /v2/account/balance: data.balances[] com currency + available.
     *
     * @param  array<int|string, mixed>  $rows
     */
    private function bspayAvailableFromBalanceRows(array $rows): ?float
    {
        $fallback = null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $currency = strtoupper((string) ($row['currency'] ?? $row['currency_id'] ?? ''));
            $amount = $this->firstNumeric($row, ['available', 'available_balance', 'balance', 'amount']);
            if ($amount === null) {
                continue;
            }
            if ($currency === 'BRL') {
                return $amount;
            }
            if ($currency === '' && $fallback === null) {
                $fallback = $amount;
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function parseEfiAvailable(array $body): float
    {
        if (isset($body['saldo']) && is_numeric($body['saldo'])) {
            return round((float) $body['saldo'], 2);
        }

        throw new \RuntimeException('Efí: saldo da conta não encontrado na resposta.');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function parseWooviAvailable(array $body): float
    {
        $account = $this->wooviAccountNode($body);
        $available = is_array($account['balance'] ?? null)
            ? ($account['balance']['available'] ?? null)
            : null;

        if (! is_numeric($available)) {
            throw new \RuntimeException('Woovi: saldo disponível não encontrado na resposta.');
        }

        return round(((float) $available) / 100, 2);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function wooviAccountNode(array $body): array
    {
        if (isset($body['account']) && is_array($body['account'])) {
            return $body['account'];
        }

        $accounts = $body['accounts'] ?? null;
        if (is_array($accounts)) {
            $first = null;
            foreach ($accounts as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $first ??= $row;
                if (! empty($row['isDefault'])) {
                    return $row;
                }
            }
            if (is_array($first)) {
                return $first;
            }
        }

        if (isset($body['balance']) && is_array($body['balance'])) {
            return $body;
        }

        throw new \RuntimeException('Woovi: conta não encontrada na resposta.');
    }

    /**
     * GET /v1/balance: available[] em unidade menor (centavos), por moeda.
     * Prefere BRL quando há saldo; senão USD ou a primeira moeda com fundo.
     *
     * @param  array<string, mixed>  $body
     */
    public function parseStripeAvailable(array $body): float
    {
        return $this->parseStripeAvailableDetails($body)['available'];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{available: float, currency: string}
     */
    public function parseStripeAvailableDetails(array $body): array
    {
        $buckets = $body['available'] ?? null;
        if (! is_array($buckets)) {
            throw new \RuntimeException('Stripe: saldo disponível não encontrado na resposta.');
        }

        $byCurrency = [];
        foreach ($buckets as $bucket) {
            if (! is_array($bucket)) {
                continue;
            }
            $currency = strtolower(trim((string) ($bucket['currency'] ?? '')));
            if ($currency === '') {
                continue;
            }
            $amount = $bucket['amount'] ?? null;
            if (! is_numeric($amount)) {
                continue;
            }
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + (int) $amount;
        }

        if ($byCurrency === []) {
            return ['available' => 0.0, 'currency' => 'BRL'];
        }

        $chosen = $this->chooseStripeCurrency($byCurrency);

        return [
            'available' => StripeDriver::fromSmallestUnit($byCurrency[$chosen], $chosen),
            'currency' => strtoupper($chosen),
        ];
    }

    /**
     * @param  array<string, int>  $byCurrency
     */
    private function chooseStripeCurrency(array $byCurrency): string
    {
        $nonZero = array_filter($byCurrency, fn (int $amount) => $amount !== 0);

        if (isset($nonZero['brl'])) {
            return 'brl';
        }
        if (isset($nonZero['usd'])) {
            return 'usd';
        }
        if ($nonZero !== []) {
            return (string) array_key_first($nonZero);
        }
        if (isset($byCurrency['brl'])) {
            return 'brl';
        }
        if (isset($byCurrency['usd'])) {
            return 'usd';
        }

        return (string) array_key_first($byCurrency);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function parseVersellAvailable(array $body): float
    {
        $rows = $body['data'] ?? null;
        if (! is_array($rows) || $rows === []) {
            throw new \RuntimeException('Versell: saldo disponível não encontrado na resposta.');
        }

        $latest = null;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($latest === null) {
                $latest = $row;

                continue;
            }
            $currentDate = (string) ($row['eventDate'] ?? '');
            $latestDate = (string) ($latest['eventDate'] ?? '');
            if ($currentDate > $latestDate) {
                $latest = $row;
            }
        }

        $amount = is_array($latest) ? ($latest['balanceAmount'] ?? null) : null;
        if (! is_array($amount) || ! isset($amount['available']) || ! is_numeric($amount['available'])) {
            throw new \RuntimeException('Versell: saldo disponível não encontrado na resposta.');
        }

        return round((float) $amount['available'], 2);
    }

    /**
     * GET /api/v1/balance: available/reserved em centavos.
     *
     * @param  array<string, mixed>  $body
     */
    public function parseXflowAvailable(array $body): float
    {
        $node = $body;
        if (isset($body['balance']) && is_array($body['balance'])) {
            $node = $body['balance'];
        }

        $available = $node['available'] ?? null;
        if (! is_numeric($available)) {
            throw new \RuntimeException('Xflow: saldo disponível não encontrado na resposta.');
        }

        return round(((float) $available) / 100, 2);
    }

    /**
     * GET /operator/v2/balance: availableBalance já em reais.
     *
     * @param  array<string, mixed>  $body
     */
    public function parseOktoAvailable(array $body): float
    {
        $available = $body['availableBalance'] ?? $body['available'] ?? null;
        if (! is_numeric($available)) {
            throw new \RuntimeException('Okto: saldo disponível não encontrado na resposta.');
        }

        return round((float) $available, 2);
    }

    /**
     * GET /balance: balances.BRL.available em reais.
     *
     * @param  array<string, mixed>  $body
     */
    public function parseXpagAvailable(array $body): float
    {
        $balances = is_array($body['balances'] ?? null) ? $body['balances'] : $body;
        $available = $this->numericFromCurrencyMap($balances, 'BRL');
        if ($available === null) {
            throw new \RuntimeException('XPag: saldo BRL disponível não encontrado na resposta.');
        }

        return $available;
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function numericFromCurrencyMap(array $map, string $currency): ?float
    {
        $block = $map[$currency] ?? $map[strtolower($currency)] ?? null;
        if (is_numeric($block)) {
            return round((float) $block, 2);
        }
        if (! is_array($block)) {
            return null;
        }

        return $this->firstNumeric($block, ['available', 'available_balance', 'balance', 'amount']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function firstNumeric(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return round((float) $row[$key], 2);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function hasEfiBalanceCredentials(array $credentials): bool
    {
        $certPath = trim((string) ($credentials['certificate_path'] ?? ''));

        return trim((string) ($credentials['client_id'] ?? '')) !== ''
            && trim((string) ($credentials['client_secret'] ?? '')) !== ''
            && $certPath !== ''
            && is_file($certPath);
    }

    private function centsOrReais(float $value, bool $treatAsCents): float
    {
        if ($treatAsCents) {
            return round($value / 100, 2);
        }

        return round($value, 2);
    }

    private function gatewayName(string $slug): string
    {
        $def = GatewayRegistry::get($slug);

        return is_string($def['name'] ?? null) && $def['name'] !== '' ? $def['name'] : $slug;
    }

    private function gatewayImage(string $slug): ?string
    {
        $def = GatewayRegistry::get($slug);
        $image = $def['image'] ?? null;

        return is_string($image) && $image !== '' ? $image : null;
    }
}
