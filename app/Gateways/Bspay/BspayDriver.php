<?php

namespace App\Gateways\Bspay;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BspayDriver implements GatewayDriver
{
    private const BASE_URL = 'https://api.bspay.co';

    private const TOKEN_SKEW_SECONDS = 60;

    public function testConnection(array $credentials): bool
    {
        $token = $this->getToken($credentials);
        if ($token === null) {
            return false;
        }

        try {
            $response = $this->http($token)->get($this->url('/v2/account/balance'));
        } catch (\Throwable $e) {
            Log::debug('BspayDriver testConnection error', ['message' => $e->getMessage()]);

            return false;
        }

        if ($response->status() === 401) {
            $this->forgetToken($credentials);

            return false;
        }

        return $response->successful();
    }

    /**
     * Saldo da conta (envelope { success, data }).
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function fetchAccountBalance(array $credentials): array
    {
        $token = $this->getToken($credentials);
        if ($token === null) {
            throw new \RuntimeException('BSPay: falha na autenticação (Client ID/Secret).');
        }

        try {
            $response = $this->requestWithAuthRetry($credentials, $token, function (string $bearer) {
                return $this->http($bearer)->timeout(8)->withOptions(['connect_timeout' => 4])->get($this->url('/v2/account/balance'));
            });
        } catch (\Throwable $e) {
            throw new \RuntimeException('BSPay: falha ao consultar saldo.', 0, $e);
        }

        if ($response->status() === 401) {
            $this->forgetToken($credentials);

            throw new \RuntimeException('BSPay: token inválido ao consultar saldo.');
        }

        if (! $response->successful()) {
            throw new \RuntimeException('BSPay: falha ao consultar saldo (HTTP '.$response->status().').');
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array{name?: string, document?: string, email?: string}  $consumer
     * @return array{transaction_id: string, qrcode?: string|null, copy_paste?: string|null, raw?: array}
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        $token = $this->getToken($credentials);
        if ($token === null) {
            throw new \RuntimeException('BSPay: falha na autenticação (Client ID/Secret).');
        }

        $document = $this->normalizeDocument((string) ($consumer['document'] ?? ''));
        $name = $this->sanitizeName((string) ($consumer['name'] ?? ''));
        $email = $this->sanitizeEmail((string) ($consumer['email'] ?? ''));
        $postback = $this->validPostbackUrl($this->sanitizeUrlString($postbackUrl));

        $payer = [
            'name' => $name,
            'document' => $document,
        ];
        if ($email !== '') {
            $payer['email'] = $email;
        }

        $body = [
            'amount' => round($amount, 2),
            'currency' => 'BRL',
            'external_id' => $externalId,
            'payer' => $payer,
        ];
        if ($postback !== null) {
            $body['postback_url'] = $postback;
        } else {
            Log::warning('BspayDriver: postback_url omitido (exige HTTPS público)', [
                'external_id' => $externalId,
            ]);
        }

        $response = $this->requestWithAuthRetry($credentials, $token, function (string $bearer) use ($body) {
            return $this->http($bearer)->post($this->url('/v2/transactions/cashin'), $body);
        });

        if (! $response->successful()) {
            throw new \RuntimeException('BSPay: '.$this->errorMessage($response->json(), 'Erro ao gerar cobrança PIX.'));
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $data = $this->unwrapData($payload);
        $transactionId = trim((string) ($data['transaction_id'] ?? ''));
        if ($transactionId === '') {
            throw new \RuntimeException('BSPay: resposta sem transaction_id.');
        }

        $emv = $this->extractEmv($data);

        return [
            'transaction_id' => $transactionId,
            'qrcode' => null,
            'copy_paste' => $emv,
            'raw' => $payload,
        ];
    }

    /**
     * Defesa MED: POST /v2/account/infractions/reply (multipart).
     *
     * @param  array<string, mixed>  $credentials
     * @param  list<\Illuminate\Http\UploadedFile>  $attachments
     * @return array<string, mixed>
     *
     * @see https://dev.bspay.co/disputes/reply
     */
    public function submitInfractionReply(array $credentials, string $infractionId, string $message, array $attachments = []): array
    {
        $token = $this->getToken($credentials);
        if ($token === null) {
            throw new \RuntimeException('BSPay: falha na autenticação (Client ID/Secret).');
        }

        $response = $this->requestWithAuthRetry($credentials, $token, function (string $bearer) use ($infractionId, $message, $attachments) {
            $request = Http::acceptJson()
                ->timeout(30)
                ->withOptions(['connect_timeout' => 8])
                ->withToken($bearer);

            foreach ($attachments as $i => $file) {
                $path = $file->getRealPath();
                if (! is_string($path) || $path === '') {
                    continue;
                }
                $request = $request->attach(
                    'files[]',
                    fopen($path, 'r'),
                    $file->getClientOriginalName() ?: ('evidence-'.$i.'.bin')
                );
            }

            return $request->post($this->url('/v2/account/infractions/reply'), [
                'id' => $infractionId,
                'message' => $message,
            ]);
        });

        if (! $response->successful()) {
            throw new \RuntimeException('BSPay: '.$this->errorMessage($response->json(), 'Erro ao enviar defesa da infração.'));
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        return $this->mapListedStatus($this->lookupListedStatus($transactionId, $credentials, 'cashin'));
    }

    /**
     * @return array{ok: bool, transaction_id?: string|null, error?: string}
     */
    public function createPixCashout(
        array $credentials,
        float $amount,
        string $pixKey,
        string $keyType,
        string $externalId,
        string $postbackUrl,
        string $receiverName = ''
    ): array {
        $token = $this->getToken($credentials);
        if ($token === null) {
            return ['ok' => false, 'error' => 'BSPay: falha na autenticação (Client ID/Secret).'];
        }

        $key = trim($pixKey);
        $type = $this->normalizePixKeyType($keyType, $key);
        if ($type === 'phone') {
            $digits = preg_replace('/\D/', '', $key);
            $key = is_string($digits) && $digits !== '' ? $digits : $key;
        }
        if ($key === '' || $type === '') {
            return ['ok' => false, 'error' => 'Chave PIX de destino inválida.'];
        }

        $postback = $this->validPostbackUrl($this->sanitizeUrlString($postbackUrl));
        $body = [
            'amount' => round($amount, 2),
            'currency' => 'BRL',
            'external_id' => $externalId,
            'key' => $key,
            'key_type' => $type,
        ];
        $name = $this->sanitizeName($receiverName);
        if ($name !== '' && $name !== 'Cliente') {
            $body['name'] = $name;
        }
        if ($postback !== null) {
            $body['postback_url'] = $postback;
        }

        try {
            $response = $this->requestWithAuthRetry($credentials, $token, function (string $bearer) use ($body) {
                return $this->http($bearer)->post($this->url('/v2/transactions/cashout'), $body);
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'BSPay: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'BSPay: '.$this->errorMessage($response->json(), 'Erro ao enviar o saque PIX.')];
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $data = $this->unwrapData($payload);
        $transactionId = trim((string) ($data['transaction_id'] ?? $payload['transaction_id'] ?? ''));
        if ($transactionId === '') {
            return ['ok' => false, 'error' => 'BSPay: resposta de saque sem transaction_id.'];
        }

        return ['ok' => true, 'transaction_id' => $transactionId];
    }

    public function getCashoutStatus(string $transactionId, array $credentials): ?string
    {
        return $this->mapListedStatus($this->lookupListedStatus($transactionId, $credentials, 'cashout'));
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new \RuntimeException('BSPay não suporta pagamento com cartão neste fluxo.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('BSPay não suporta boleto neste fluxo.');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function getToken(array $credentials): ?string
    {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $clientSecret = trim((string) ($credentials['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $cacheKey = $this->tokenCacheKey($clientId, $clientSecret);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http()
                ->withHeaders([
                    'Authorization' => 'Basic '.base64_encode($clientId.':'.$clientSecret),
                ])
                ->post($this->url('/v2/oauth/token'), [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (\Throwable $e) {
            Log::warning('BspayDriver: auth request failed', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('BspayDriver: auth rejected', ['status' => $response->status()]);

            return null;
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        $expiresIn = (int) $response->json('expires_in', 3600);
        $ttl = max(30, $expiresIn - self::TOKEN_SKEW_SECONDS);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  callable(string): \Illuminate\Http\Client\Response  $callback
     */
    private function requestWithAuthRetry(array $credentials, string $token, callable $callback): \Illuminate\Http\Client\Response
    {
        $response = $callback($token);
        if ($response->status() !== 401) {
            return $response;
        }

        $this->forgetToken($credentials);
        $fresh = $this->getToken($credentials);
        if ($fresh === null) {
            return $response;
        }

        return $callback($fresh);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function lookupListedStatus(string $transactionId, array $credentials, string $type): ?string
    {
        $token = $this->getToken($credentials);
        if ($token === null) {
            return null;
        }

        try {
            $response = $this->requestWithAuthRetry($credentials, $token, function (string $bearer) use ($transactionId, $type) {
                return $this->http($bearer)->post($this->url('/v2/account/transactions/list'), [
                    'page' => 1,
                    'page_size' => 50,
                    'type' => $type,
                    'transaction_id' => $transactionId,
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('BspayDriver lookupListedStatus error', [
                'transaction_id' => $transactionId,
                'type' => $type,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('BspayDriver lookupListedStatus http error', [
                'transaction_id' => $transactionId,
                'type' => $type,
                'status' => $response->status(),
            ]);

            return null;
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $row = $this->firstTransactionRow($payload, $transactionId);
        if ($row === null) {
            Log::warning('BspayDriver lookupListedStatus row missing', [
                'transaction_id' => $transactionId,
                'type' => $type,
                'payload_keys' => array_keys($payload),
            ]);

            return null;
        }

        $status = strtolower(trim((string) ($row['status'] ?? $row['state'] ?? '')));

        return $status !== '' ? $status : null;
    }

    private function mapListedStatus(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return match ($status) {
            'confirmed', 'paid', 'completed', 'success', 'approved' => 'paid',
            'cancelled', 'canceled' => 'cancelled',
            'failed' => 'failed',
            'pending' => 'pending',
            default => 'pending',
        };
    }

    private function normalizePixKeyType(string $type, string $key): string
    {
        $t = strtolower(trim($type));
        $mapped = match ($t) {
            'cpf' => 'cpf',
            'cnpj' => 'cnpj',
            'email' => 'email',
            'phone', 'telefone' => 'phone',
            'random', 'evp', 'aleatoria', 'aleatória' => 'random',
            default => '',
        };
        if ($mapped !== '') {
            return $mapped;
        }
        if (str_contains($key, '@')) {
            return 'email';
        }
        $digits = preg_replace('/\D/', '', $key);
        $digits = is_string($digits) ? $digits : '';
        if (strlen($digits) === 11) {
            if (preg_match('/^[1-9]{2}9\d{8}$/', $digits) === 1) {
                return 'phone';
            }

            return 'cpf';
        }
        if (strlen($digits) === 14) {
            return 'cnpj';
        }
        if (strlen($digits) >= 10 && strlen($digits) <= 13) {
            return 'phone';
        }

        return 'random';
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function forgetToken(array $credentials): void
    {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $clientSecret = trim((string) ($credentials['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            return;
        }
        Cache::forget($this->tokenCacheKey($clientId, $clientSecret));
    }

    private function tokenCacheKey(string $clientId, string $clientSecret): string
    {
        return 'bspay.oauth.'.hash('sha256', $clientId.'|'.$clientSecret);
    }

    private function http(?string $token = null): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->withOptions(['connect_timeout' => 8]);

        if ($token !== null && $token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function url(string $path): string
    {
        return rtrim(self::BASE_URL, '/').$path;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrapData(array $payload): array
    {
        $data = $payload['data'] ?? null;

        return is_array($data) ? $data : $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractEmv(array $data): ?string
    {
        $info = $data['payment_info'] ?? null;
        $raw = is_array($info) ? ($info['qrcode'] ?? null) : null;
        if (! is_string($raw)) {
            return null;
        }
        $emv = trim($raw);
        if ($emv === '' || str_starts_with($emv, 'data:image')) {
            return null;
        }

        return $emv;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function firstTransactionRow(array $payload, string $transactionId): ?array
    {
        $data = $this->unwrapData($payload);
        $candidates = $this->collectTransactionRows($data);

        foreach ($candidates as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['transaction_id'] ?? $row['id'] ?? $row['uuid'] ?? ''));
            if ($id !== '' && $id === $transactionId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function collectTransactionRows(array $data): array
    {
        foreach (['items', 'transactions', 'results', 'records', 'rows', 'docs', 'list', 'data'] as $key) {
            $list = $data[$key] ?? null;
            if (is_array($list) && $list !== [] && array_is_list($list)) {
                /** @var list<array<string, mixed>> $list */
                return $list;
            }
        }
        if (array_is_list($data)) {
            /** @var list<array<string, mixed>> $data */
            return $data;
        }
        if (isset($data['transaction_id']) || isset($data['id'])) {
            return [$data];
        }

        return [];
    }

    private function errorMessage(mixed $json, string $fallback): string
    {
        if (! is_array($json)) {
            return $fallback;
        }
        $error = $json['error'] ?? null;
        if (is_array($error)) {
            $message = $error['message'] ?? $error['code'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }
        $message = $json['message'] ?? null;

        return is_string($message) && trim($message) !== '' ? trim($message) : $fallback;
    }

    private function normalizeDocument(string $document): string
    {
        $digits = preg_replace('/\D/', '', $document);
        $digits = is_string($digits) ? $digits : '';
        if (strlen($digits) === 11 || strlen($digits) === 14) {
            return $digits;
        }

        return $digits !== '' ? $digits : '00000000000';
    }

    private function sanitizeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: '');
        if ($name === '') {
            return 'Cliente';
        }

        return strlen($name) > 80 ? substr($name, 0, 80) : $name;
    }

    private function sanitizeEmail(string $email): string
    {
        $email = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $email) ?: '');
        if ($email === '') {
            return '';
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function sanitizeUrlString(string $value): string
    {
        $v = trim($value);
        $v = str_replace(["\r", "\n", "\t"], '', $v);

        return trim(str_replace(['`', '"', "'"], '', $v));
    }

    private function validPostbackUrl(string $value): ?string
    {
        if ($value === '' || ! str_starts_with($value, 'https://')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }
}
