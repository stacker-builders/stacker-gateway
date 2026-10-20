<?php

namespace App\Gateways\Okto;

use App\Gateways\Contracts\GatewayDriver;
use App\Support\BrazilianDocumentDigits;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class OktoDriver implements GatewayDriver
{
    public const STAGING_BASE = 'https://demo-pix.oktopay.eu';

    public const PRODUCTION_BASE = 'https://pix.oktopay.eu';

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        try {
            $this->fetchAccountBalance($credentials);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, document?: string, email?: string}  $consumer
     * @param  array<string, mixed>  $options
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
        $token = $this->accessToken($credentials);
        if ($token === '') {
            throw new RuntimeException('Okto: informe o access token.');
        }

        $document = BrazilianDocumentDigits::onlyDigits((string) ($consumer['document'] ?? ''));
        if (strlen($document) === 14) {
            throw new RuntimeException('Okto PIX aceita apenas CPF do pagador (não CNPJ).');
        }
        if (strlen($document) !== 11) {
            throw new RuntimeException('Okto: CPF do pagador é obrigatório.');
        }

        $name = $this->payerFullName((string) ($consumer['name'] ?? ''));
        $amountCents = max(1, (int) round($amount * 100));
        $expiration = (int) ($options['expiration'] ?? 3600);
        if ($expiration < 60) {
            $expiration = 3600;
        }
        if ($expiration > 10800) {
            $expiration = 10800;
        }

        $oktoExternalId = $this->normalizeExternalId($externalId, $options['okto_external_id'] ?? null);
        $body = [
            'name' => mb_substr($name, 0, 140),
            'taxId' => $this->formatCpf($document),
            'amount' => $amountCents,
            'expiration' => $expiration,
            'externalId' => $oktoExternalId,
        ];
        if (isset($options['tags']) && is_array($options['tags']) && $options['tags'] !== []) {
            $body['tags'] = array_values(array_filter(array_map('strval', $options['tags'])));
        }

        $url = $this->url($credentials, '/transactions/deposit');
        try {
            $response = $this->http($credentials)->post($url, $body);
        } catch (\Throwable $e) {
            Log::error('OktoDriver createPixPayment connection failed', [
                'order_id' => $externalId,
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('Okto: falha de conexão com '.$this->baseUrl($credentials).' — '.$e->getMessage());
        }

        if (! $response->successful()) {
            $message = $this->errorMessage($response, 'Erro ao gerar cobrança PIX.');
            Log::error('OktoDriver createPixPayment rejected', [
                'order_id' => $externalId,
                'url' => $url,
                'http' => $response->status(),
                'message' => $message,
                'body' => mb_substr($response->body(), 0, 800),
            ]);

            throw new RuntimeException('Okto: '.$message);
        }

        $payload = $this->unwrapPayload($response->json());
        if ($this->payloadHasHardFailure($payload)) {
            throw new RuntimeException('Okto: '.$this->errorMessageFromArray($payload, 'Cobrança PIX recusada.'));
        }

        $transactionId = trim((string) ($payload['id'] ?? ''));
        if ($transactionId === '') {
            throw new RuntimeException('Okto: resposta sem id da cobrança.');
        }

        $brcode = $this->extractBrcode($payload);
        if ($brcode === '') {
            $brcode = $this->fetchBrcodePayload($credentials, $transactionId);
        }
        $qrImage = $this->fetchQrImage($credentials, $transactionId);

        return [
            'transaction_id' => $transactionId,
            'qrcode' => $qrImage !== '' ? $qrImage : null,
            'copy_paste' => $brcode !== '' ? $brcode : null,
            'raw' => $payload,
            'metadata' => [
                'okto_external_id' => $oktoExternalId,
            ],
        ];
    }

    /**
     * Depósito de diagnóstico (persona oficial Marion Schuppe) para o botão Testar conexão.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, message: string, transaction_id?: string}
     */
    public function diagnosePixDeposit(array $credentials): array
    {
        try {
            $result = $this->createPixPayment(
                $credentials,
                1.00,
                ['name' => 'Marion Schuppe', 'document' => '60418443734'],
                (string) Str::uuid(),
                '',
                ['tags' => ['stacker-okto-probe'], 'expiration' => 600]
            );
            $hasCode = (is_string($result['copy_paste'] ?? null) && $result['copy_paste'] !== '')
                || (is_string($result['qrcode'] ?? null) && $result['qrcode'] !== '');

            if (! $hasCode) {
                return [
                    'ok' => false,
                    'message' => 'Okto criou a cobrança (id '.($result['transaction_id'] ?? '?').') mas não devolveu brcode/QR. Host: '.$this->baseUrl($credentials),
                    'transaction_id' => $result['transaction_id'] ?? null,
                ];
            }

            return [
                'ok' => true,
                'message' => 'PIX de teste gerado em '.$this->baseUrl($credentials).' (R$ 1,00, persona 604.184.437-34).',
                'transaction_id' => $result['transaction_id'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage().' Host: '.$this->baseUrl($credentials),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return null;
        }

        try {
            $response = $this->http($credentials)->post($this->url($credentials, '/transactions/status'), [
                'id' => $transactionId,
                'type' => 'deposit',
            ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        return $this->mapDepositStatus($json['status'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, document?: string, email?: string}  $consumer
     * @param  array{payment_token: string, card_mask?: string}  $card
     * @return array{transaction_id: string, status?: string}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        throw new RuntimeException('Okto não suporta pagamento com cartão neste fluxo.');
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, document?: string, email?: string}  $consumer
     * @return array{transaction_id: string, amount: float, expire_at: string, barcode: string, pdf_url: string, raw?: array}
     */
    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new RuntimeException('Okto não suporta boleto neste fluxo.');
    }

    /**
     * Saldo em reais (a API Non Betting já devolve float, sem converter centavos).
     *
     * @param  array<string, mixed>  $credentials
     * @return array{availableBalance?: float|int, totalBalance?: float|int, pendingBalance?: float|int}
     */
    public function fetchAccountBalance(array $credentials): array
    {
        $token = $this->accessToken($credentials);
        if ($token === '') {
            throw new RuntimeException('Okto: informe o access token.');
        }

        $response = $this->http($credentials)
            ->timeout(8)
            ->withOptions(['connect_timeout' => 4])
            ->get($this->url($credentials, '/operator/v2/balance'));

        if (! $response->successful()) {
            throw new RuntimeException('Okto: falha ao consultar saldo (HTTP '.$response->status().').');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Okto: resposta de saldo inválida.');
        }

        return $json;
    }

    /**
     * Cash-out Non Betting: só chave PIX (sem bankAccount).
     *
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, pending?: bool, transaction_id?: string|null, error?: string, raw?: array}
     */
    public function createTransfer(
        array $credentials,
        int $amountCents,
        string $pixKey,
        string $pixKeyType,
        string $externalId,
        ?string $ownerTaxId = null,
    ): array {
        $pixKey = trim($pixKey);
        $amountCents = max(1, $amountCents);
        if ($pixKey === '') {
            return ['ok' => false, 'error' => 'Okto: chave PIX de destino ausente.'];
        }

        $type = $this->normalizePixKeyType($pixKeyType, $pixKey);
        if ($type === 'cnpj') {
            return ['ok' => false, 'error' => 'Okto: saque por chave CNPJ não é suportado. Use CPF, e-mail, telefone ou EVP.'];
        }

        $dictKey = $this->formatDictKey($pixKey, $type);
        $body = [
            'dictKey' => $dictKey,
            'externalId' => mb_substr(trim($externalId), 0, 80),
            'amount' => $amountCents,
        ];

        if (in_array($type, ['email', 'phone'], true)) {
            $taxId = BrazilianDocumentDigits::onlyDigits((string) $ownerTaxId);
            if (strlen($taxId) !== 11) {
                return ['ok' => false, 'error' => 'Okto: informe o CPF do titular quando a chave PIX for e-mail ou telefone.'];
            }
            $body['taxId'] = $taxId;
        }

        try {
            $response = $this->http($credentials)
                ->timeout(40)
                ->post($this->url($credentials, '/transactions/withdraw'), $body);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Okto: falha na requisição de saque.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'Okto: '.$this->errorMessage($response, 'Erro ao enviar o saque PIX.')];
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            $id = trim((string) ($payload['externalId'] ?? $externalId));
        }

        return [
            'ok' => true,
            'pending' => true,
            'transaction_id' => $id !== '' ? $id : null,
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getTransferStatus(string $transferId, array $credentials): ?string
    {
        $transferId = trim($transferId);
        if ($transferId === '') {
            return null;
        }

        foreach ([['id' => $transferId], ['externalId' => $transferId]] as $identity) {
            try {
                $response = $this->http($credentials)->post($this->url($credentials, '/transactions/status'), array_merge($identity, [
                    'type' => 'withdraw',
                ]));
            } catch (\Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $json = $response->json();
            if (! is_array($json)) {
                continue;
            }

            $mapped = $this->mapWithdrawStatus($json['status'] ?? null);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return null;
    }

    /**
     * Estorno total do depósito pago.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{success: bool, pending?: bool, message?: string, error_code?: string, raw?: array<string, mixed>}
     */
    public function refundTransaction(array $credentials, string $txId, float $amount, string $externalId): array
    {
        $depositId = trim($txId);
        if ($depositId === '') {
            return [
                'success' => false,
                'message' => 'Okto: ID da cobrança ausente para estorno.',
                'error_code' => 'missing_charge_id',
            ];
        }

        try {
            $response = $this->http($credentials)->put($this->url($credentials, '/transactions/deposit/'.rawurlencode($depositId).'/refund'));
        } catch (\Throwable $e) {
            Log::warning('OktoDriver refundTransaction request failed', [
                'order_id' => $externalId,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return [
                'success' => false,
                'message' => 'Okto: falha de comunicação ao solicitar estorno.',
                'error_code' => 'communication_failure',
            ];
        }

        $json = $response->json();
        $payload = is_array($json) ? $json : [];

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => $this->errorMessage($response, 'Okto recusou o estorno.'),
                'error_code' => 'http_'.$response->status(),
                'raw' => $payload,
            ];
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));

        return [
            'success' => true,
            'pending' => ! in_array($status, ['reversed'], true),
            'message' => $status === 'reversed'
                ? 'Estorno Okto confirmado.'
                : 'Estorno Okto solicitado; aguardando webhook reversed.',
            'raw' => $payload,
        ];
    }

    /**
     * PUT /operator — cadastra URL única para cash-in e cash-out.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function registerWebhookUrls(array $credentials, string $url, ?string $notificationToken = null): void
    {
        $url = trim($url);
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Okto: URL de webhook precisa ser HTTPS pública.');
        }

        $body = [
            'depositUrl' => $url,
            'withdrawUrl' => $url,
        ];
        $token = trim((string) ($notificationToken ?? $credentials['notification_token'] ?? $this->accessToken($credentials)));
        if ($token !== '') {
            $body['notificationToken'] = $token;
        }

        $response = $this->http($credentials)->put($this->url($credentials, '/operator'), $body);
        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response, 'Falha ao registrar webhook na Okto.'));
        }
    }

    public static function verifyPayloadSignature(string $rawBody, string $signatureB64, string $publicKeyPem): bool
    {
        $pem = self::normalizePublicKeyPem($publicKeyPem);
        if ($pem === '' || trim($signatureB64) === '') {
            return false;
        }

        $binary = base64_decode($signatureB64, true);
        if ($binary === false || $binary === '') {
            return false;
        }

        try {
            $ok = @openssl_verify($rawBody, $binary, $pem, OPENSSL_ALGO_SHA256);
        } catch (\Throwable) {
            return false;
        }

        return $ok === 1;
    }

    public static function normalizePublicKeyPem(string $raw): string
    {
        $raw = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if ($raw === '') {
            return '';
        }
        if (str_contains($raw, '\\n')) {
            $raw = str_replace('\\n', "\n", $raw);
        }
        if (! str_contains($raw, 'BEGIN')) {
            $wrapped = trim(chunk_split(preg_replace('/\s+/', '', $raw) ?? '', 64, "\n"));
            $raw = "-----BEGIN PUBLIC KEY-----\n".$wrapped."\n-----END PUBLIC KEY-----";
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function baseUrl(array $credentials): string
    {
        $sandbox = filter_var($credentials['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $sandbox ? self::STAGING_BASE : self::PRODUCTION_BASE;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function url(array $credentials, string $path): string
    {
        return rtrim($this->baseUrl($credentials), '/').$path;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials): string
    {
        return trim((string) ($credentials['access_token'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function http(array $credentials): PendingRequest
    {
        $token = $this->accessToken($credentials);
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }

        return Http::timeout(30)
            ->connectTimeout(8)
            ->acceptJson()
            ->asJson()
            ->withToken($token);
    }

    private function errorMessage(Response $response, string $fallback): string
    {
        $json = $response->json();

        return $this->errorMessageFromArray(is_array($json) ? $json : [], $fallback);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function errorMessageFromArray(array $json, string $fallback): string
    {
        foreach ($this->errorObjects($json) as $item) {
            $code = $item['code'] ?? null;
            $msg = trim((string) ($item['detailedMessage'] ?? $item['message'] ?? ''));
            if ($msg === '') {
                continue;
            }
            if (is_numeric($code)) {
                return ((int) $code).' '.$msg;
            }

            return $msg;
        }

        foreach (['message', 'error', 'detailedMessage'] as $key) {
            $v = $json[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function errorObjects(array $json): array
    {
        $buckets = [];
        $list = $json['errorList'] ?? null;
        if (is_array($list)) {
            $buckets[] = $list;
            if (isset($list['errors']) && is_array($list['errors'])) {
                $buckets[] = $list['errors'];
            }
        }
        if (isset($json['errors']) && is_array($json['errors'])) {
            $buckets[] = $json['errors'];
        }

        $out = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $json
     * @return array<string, mixed>
     */
    private function unwrapPayload(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        $data = $json['data'] ?? null;
        if (! isset($json['id']) && is_array($data) && isset($data['id'])) {
            return $data;
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHasHardFailure(array $payload): bool
    {
        $status = strtolower(trim((string) ($payload['status'] ?? '')));

        return in_array($status, ['failed', 'expired', 'reversed'], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractBrcode(array $payload): string
    {
        foreach (['brcode', 'brCode', 'qrCode', 'qrcode', 'pixCopiaECola', 'emv', 'copyPaste'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '' && (str_starts_with($value, '00020') || strlen($value) > 40)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchBrcodePayload(array $credentials, string $depositId): string
    {
        try {
            $response = $this->http($credentials)
                ->get($this->url($credentials, '/transactions/qrcode/'.rawurlencode($depositId)), [
                    'format' => 'payload',
                ]);
        } catch (\Throwable) {
            return '';
        }

        if (! $response->successful()) {
            return '';
        }

        $json = $response->json();
        if (is_array($json)) {
            return $this->extractBrcode($json);
        }

        $body = trim($response->body());
        $body = trim($body, "\" \n\r\t");

        return (str_starts_with($body, '00020') || strlen($body) > 40) ? $body : '';
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchQrImage(array $credentials, string $depositId): string
    {
        try {
            $response = $this->http($credentials)
                ->get($this->url($credentials, '/transactions/qrcode/'.rawurlencode($depositId)), [
                    'format' => 'base64',
                ]);
        } catch (\Throwable) {
            return '';
        }

        if (! $response->successful()) {
            return '';
        }

        $body = trim($response->body());
        $body = trim($body, "\" \n\r\t");
        if ($body === '') {
            return '';
        }
        if (str_starts_with($body, 'data:image')) {
            return $body;
        }
        if (str_starts_with($body, '00020')) {
            return '';
        }

        return 'data:image/png;base64,'.$body;
    }

    private function payerFullName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') {
            return 'Cliente Checkout';
        }
        if (count(preg_split('/\s+/', $name) ?: []) < 2) {
            return mb_substr($name.' Checkout', 0, 140);
        }

        return $name;
    }

    private function formatCpf(string $digits): string
    {
        if (strlen($digits) !== 11) {
            return $digits;
        }

        return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
    }

    private function normalizeExternalId(string $externalId, mixed $preferred = null): string
    {
        $preferred = is_string($preferred) ? trim($preferred) : '';
        if ($this->isValidExternalId($preferred)) {
            return mb_substr($preferred, 0, 80);
        }

        $externalId = trim($externalId);
        if ($this->isValidExternalId($externalId)) {
            return mb_substr($externalId, 0, 80);
        }

        return (string) Str::uuid();
    }

    private function isValidExternalId(string $id): bool
    {
        if ($id === '' || ctype_digit($id) || strlen($id) < 8) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9._-]+$/', $id);
    }

    private function mapDepositStatus(mixed $status): ?string
    {
        $s = strtolower(trim((string) $status));

        return match ($s) {
            'paid' => 'paid',
            'created', 'processing', 'reversing', 'reviewing' => 'pending',
            'expired', 'failed', 'reversed' => 'cancelled',
            default => null,
        };
    }

    private function mapWithdrawStatus(mixed $status): ?string
    {
        $s = strtolower(trim((string) $status));

        return match ($s) {
            'success' => 'paid',
            'created', 'processing' => 'pending',
            'failed', 'expired' => 'failed',
            default => null,
        };
    }

    public function normalizePixKeyType(string $pixKeyType, string $pixKey): string
    {
        $type = strtolower(trim($pixKeyType));
        if ($type === 'random') {
            return 'evp';
        }
        if (in_array($type, ['cpf', 'cnpj', 'email', 'phone', 'evp'], true)) {
            return $type;
        }

        $digits = BrazilianDocumentDigits::onlyDigits($pixKey);
        if (strlen($digits) === 11) {
            return 'cpf';
        }
        if (strlen($digits) === 14) {
            return 'cnpj';
        }
        if (str_contains($pixKey, '@')) {
            return 'email';
        }

        return 'evp';
    }

    public function formatDictKey(string $pixKey, string $type): string
    {
        if ($type === 'cpf') {
            return BrazilianDocumentDigits::onlyDigits($pixKey);
        }
        if ($type === 'phone') {
            $digits = BrazilianDocumentDigits::onlyDigits($pixKey);
            if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
                return '+'.$digits;
            }
            if ($digits !== '') {
                return '+55'.$digits;
            }
        }

        return trim($pixKey);
    }
}
