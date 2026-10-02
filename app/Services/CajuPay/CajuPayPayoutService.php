<?php

namespace App\Services\CajuPay;

use App\Models\GatewayCredential;
use App\Models\Withdrawal;
use App\Services\EffectiveMerchantFees;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Withdrawal\WithdrawalMinimumService;
use App\Services\WithdrawalPixReceiptService;
use App\Support\BrazilianDocumentDigits;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CajuPayPayoutService
{
    /**
     * @return array{ok: bool, external_id?: string, error?: string, status?: int, cajupay_error_code?: string}
     */
    public function sendWithdrawalToPixKey(
        Withdrawal $withdrawal,
        ?string $pixKeyId = null,
        ?string $pixKey = null,
        ?string $pixKeyType = null,
        ?string $keyOwnerDocument = null
    ): array
    {
        $account = app(CajuPayAccountResolver::class)->resolveForTenant((int) $withdrawal->tenant_id);
        if ($account === null) {
            $account = app(CajuPayAccountResolver::class)->defaultOrFirstConnected();
        }
        if ($account === null) {
            return ['ok' => false, 'error' => 'CajuPay não configurado na plataforma (Integrações > Gateways).'];
        }
        $credentials = $account->getDecryptedCredentials();
        if (empty($credentials['public_key'] ?? null) || empty($credentials['secret_key'] ?? null)) {
            return ['ok' => false, 'error' => 'Credenciais CajuPay incompletas.'];
        }

        $net = (float) $withdrawal->net_amount;
        if ($net <= 0) {
            return ['ok' => false, 'error' => 'Valor líquido do saque inválido.'];
        }

        $economics = GatewayPayoutEconomics::fromCredentialsArray('cajupay', $credentials);
        $requiredNet = WithdrawalMinimumService::effectiveRequiredMinNet($economics);
        $minCents = (int) max(1, (int) round($requiredNet * 100));

        $netCents = (int) round($net * 100);
        if ($netCents < $minCents) {
            $tenantId = (int) $withdrawal->tenant_id;
            $minGross = EffectiveMerchantFees::minimumWithdrawalGrossForTargetNet($tenantId, $requiredNet);
            $msg = $minGross !== null
                ? 'O valor mínimo do saque é R$ '
                    .number_format($minGross, 2, ',', '.').' (valor total a solicitar).'
                : 'O valor solicitado é inferior ao mínimo permitido.';

            return ['ok' => false, 'error' => $msg];
        }

        $apiAmount = GatewayPayoutEconomics::transferAmountBrlForApi(
            $net,
            $economics['admin_fee_payout_brl'],
            $economics['admin_fee_payout_percent'] ?? 0.0,
        );
        $amountCents = (int) round($apiAmount * 100);

        $http = $this->httpForCredentials($credentials);

        // CajuPay exige que a mesma Idempotency-Key seja reutilizada apenas com o MESMO payload.
        // Como chave/titular/taxas podem mudar entre tentativas, incluímos assinatura do payload.
        $idempotencyKey = Str::limit(
            'getfy-withdrawal-'.$withdrawal->id.'-'.substr(sha1(json_encode([
                'amount_cents' => $amountCents,
                'pix_key_type' => $pixKeyType,
                'pix_key' => $pixKey,
                'key_owner_document' => $keyOwnerDocument,
            ])), 0, 12),
            200,
            ''
        );

        $keyOwnerDocument = BrazilianDocumentDigits::onlyDigits((string) $keyOwnerDocument);
        if ($keyOwnerDocument === null || ! BrazilianDocumentDigits::isValidCpfOrCnpjLength($keyOwnerDocument)) {
            return ['ok' => false, 'error' => 'CPF/CNPJ do titular ausente ou inválido no cadastro da chave PIX. Atualize em Financeiro e salve novamente.'];
        }
        $pixKey = trim((string) $pixKey);
        $pixKeyType = trim((string) $pixKeyType);
        if ($pixKeyType !== '' && ! in_array($pixKeyType, ['cpf', 'cnpj', 'email', 'phone', 'evp'], true)) {
            $pixKeyType = '';
        }

        $body = [
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'wallet_kind' => 'main',
            'key_owner_document' => $keyOwnerDocument,
        ];
        // Saque dict-only: sempre usa chave/tipo/documento cadastrados localmente.
        if ($pixKey !== '' && $pixKeyType !== '') {
            $body['destination'] = ['method' => 'dict'];
            $body['pix_key'] = $pixKey;
            $body['pix_key_type'] = $pixKeyType;
        } else {
            return ['ok' => false, 'error' => 'Configure a chave PIX e o tipo da chave em Financeiro antes de solicitar saque.'];
        }

        $response = $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/api/payouts', $body);

        if ($response->successful()) {
            $json = $response->json();
            $ext = null;
            if (is_array($json)) {
                $ext = $json['id'] ?? $json['payout_id'] ?? $json['payment_id'] ?? $json['uuid'] ?? null;
                if ($ext === null && isset($json['data']) && is_array($json['data'])) {
                    $d = $json['data'];
                    $ext = $d['id'] ?? $d['payout_id'] ?? null;
                }
            }
            if (! is_string($ext) || $ext === '') {
                $ext = trim((string) $response->body());
            }
            if (strlen($ext) > 120) {
                $ext = substr($ext, 0, 120).'…';
            }

            Log::info('CajuPayPayoutService: payout aceito pela API.', [
                'withdrawal_id' => $withdrawal->id,
                'http_status' => $response->status(),
                'external_ref' => $ext,
            ]);

            app(WithdrawalPixReceiptService::class)->snapshotDestination($withdrawal->fresh(), null, [
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'key_owner_document' => $keyOwnerDocument,
            ]);

            return ['ok' => true, 'external_id' => Str::limit($ext, 80, ''), 'status' => $response->status()];
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            $decoded = json_decode((string) $response->body(), true);
        }
        if (is_array($decoded) && (($decoded['error'] ?? '') === 'idempotency_key_reuse_mismatch')) {
            return [
                'ok' => false,
                'error' => 'O provedor recusou a tentativa por conflito de idempotência (dados do saque mudaram entre tentativas). Tente novamente em alguns segundos.',
                'status' => $response->status(),
            ];
        }
        if (is_array($decoded) && (($decoded['error'] ?? '') === 'insufficient_funds')) {
            $userMessage = is_string($decoded['user_message'] ?? null) ? trim($decoded['user_message']) : '';
            $friendly = $userMessage !== ''
                ? $userMessage
                : 'Saldo insuficiente na conta de pagamentos para este valor (incluindo taxas).';

            Log::warning('CajuPayPayoutService: payout recusado.', [
                'withdrawal_id' => $withdrawal->id,
                'http_status' => $response->status(),
                'response_body' => $response->body(),
                'payload_preview' => [
                    'amount_cents' => $amountCents,
                    'wallet_kind' => 'main',
                    'pix_key_type' => $pixKeyType,
                    'pix_key' => $pixKey !== '' ? Str::mask($pixKey, '*', 2, max(0, strlen($pixKey) - 4)) : '',
                    'key_owner_document' => strlen($keyOwnerDocument) >= 4
                        ? str_repeat('*', max(0, strlen($keyOwnerDocument) - 4)).substr($keyOwnerDocument, -4)
                        : $keyOwnerDocument,
                ],
            ]);

            return [
                'ok' => false,
                'error' => $friendly,
                'status' => $response->status(),
                'cajupay_error_code' => 'insufficient_funds',
            ];
        }

        $msg = $response->body();
        if (strlen($msg) > 500) {
            $msg = substr($msg, 0, 500).'…';
        }
        $rawLower = strtolower($msg);
        if (str_contains($rawLower, 'profile_incomplete')) {
            $msg = 'A conta na CajuPay está com cadastro incompleto (documento do perfil ausente/inválido). Atualize o perfil da conta na CajuPay.';
        }
        if (str_contains($rawLower, 'pix_key_owner_required')) {
            $msg = 'A CajuPay exige CPF/CNPJ do titular para essa chave PIX. Revise o cadastro da chave em Financeiro.';
        }
        if (str_contains($rawLower, 'pix_key_profile_document_mismatch')) {
            $msg = 'Os dados da chave PIX não foram aceitos. Revise chave, tipo e CPF/CNPJ do titular informados no cadastro da chave. (Código CajuPay: pix_key_profile_document_mismatch)';
        }
        if ($response->status() === 403) {
            if (str_contains(strtolower($msg), 'kyc')) {
                $msg = 'A conta de recebimento está com validação pendente ou bloqueio de saques no provedor de pagamento.';
            } elseif (str_contains(strtolower($msg), 'scope') || str_contains(strtolower($msg), 'permiss')) {
                $msg = 'A integração de pagamentos não possui permissão de saque.';
            }
        }

        Log::warning('CajuPayPayoutService: payout recusado.', [
            'withdrawal_id' => $withdrawal->id,
            'http_status' => $response->status(),
            'response_body' => $response->body(),
            'payload_preview' => [
                'amount_cents' => $amountCents,
                'wallet_kind' => 'main',
                'pix_key_type' => $pixKeyType,
                'pix_key' => $pixKey !== '' ? Str::mask($pixKey, '*', 2, max(0, strlen($pixKey) - 4)) : '',
                'key_owner_document' => strlen($keyOwnerDocument) >= 4
                    ? str_repeat('*', max(0, strlen($keyOwnerDocument) - 4)).substr($keyOwnerDocument, -4)
                    : $keyOwnerDocument,
            ],
        ]);

        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'Erro HTTP '.$response->status(), 'status' => $response->status()];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function httpForCredentials(array $credentials): \Illuminate\Http\Client\PendingRequest
    {
        $public = trim((string) ($credentials['public_key'] ?? ''));
        $secret = trim((string) ($credentials['secret_key'] ?? ''));

        $override = isset($credentials['base_url']) ? trim((string) $credentials['base_url']) : '';
        $base = $override !== ''
            ? rtrim($override, '/')
            : rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');

        return Http::acceptJson()
            ->asJson()
            ->timeout(35)
            ->withOptions(['connect_timeout' => 15])
            ->baseUrl($base)
            ->withHeaders([
                'X-API-Key' => $public,
                'X-API-Secret' => $secret,
            ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<int, array<string, mixed>>
     */
    public static function listPixKeys(array $credentials): array
    {
        $public = trim((string) ($credentials['public_key'] ?? ''));
        $secret = trim((string) ($credentials['secret_key'] ?? ''));
        if ($public === '' || $secret === '') {
            return [];
        }
        $base = rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');
        $response = Http::acceptJson()
            ->timeout(25)
            ->baseUrl($base)
            ->withHeaders([
                'X-API-Key' => $public,
                'X-API-Secret' => $secret,
            ])
            ->get('/api/pix-keys');

        if (! $response->successful()) {
            return [];
        }
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{label: string, pix_key_type: string, pix_key: string, is_default?: bool, key_owner_document?: string}  $payload
     * @return array{ok: bool, id?: string, error?: string}
     */
    public static function createPixKey(array $credentials, array $payload): array
    {
        $public = trim((string) ($credentials['public_key'] ?? ''));
        $secret = trim((string) ($credentials['secret_key'] ?? ''));
        if ($public === '' || $secret === '') {
            return ['ok' => false, 'error' => 'Credenciais ausentes.'];
        }
        $base = rtrim((string) config('services.cajupay.base_url', 'https://api.cajupay.com.br'), '/');
        // Inclui key_owner_document/label para evitar reaproveitar erro antigo quando usuário corrige titular.
        $idempotencyKey = Str::limit(
            'getfy-pixkey-'.sha1(
                (string) ($payload['pix_key'] ?? '')
                .'|'.(string) ($payload['pix_key_type'] ?? '')
                .'|'.(string) ($payload['key_owner_document'] ?? '')
                .'|'.(string) ($payload['label'] ?? '')
            ),
            200,
            ''
        );

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(25)
            ->baseUrl($base)
            ->withHeaders([
                'X-API-Key' => $public,
                'X-API-Secret' => $secret,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->post('/api/pix-keys', $payload);

        if ($response->successful()) {
            $json = $response->json();
            $id = is_array($json) ? ($json['id'] ?? $json['pix_key_id'] ?? null) : null;

            return ['ok' => true, 'id' => is_string($id) ? $id : null];
        }

        $msg = $response->body();
        if (strlen($msg) > 300) {
            $msg = substr($msg, 0, 300).'…';
        }

        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'Erro ao cadastrar chave PIX.'];
    }

    /**
     * Consulta liquidação do saque na API (fallback ao webhook).
     *
     * @return 'paid'|'pending'|'failed'|null
     */
    public function getPayoutSettlementStatus(string $externalId, ?int $tenantId = null): ?string
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return null;
        }

        $account = $tenantId !== null
            ? app(CajuPayAccountResolver::class)->resolveForTenant($tenantId)
            : app(CajuPayAccountResolver::class)->defaultOrFirstConnected();
        if ($account === null) {
            return null;
        }

        $record = $this->fetchPayoutRecord($externalId, $tenantId);

        if ($record === null) {
            return null;
        }

        $raw = $this->extractStatusFromPayoutRecord($record);

        return CajuPayPayoutStatuses::settlementStatusFromRaw($raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchPayoutRecord(string $externalId, ?int $tenantId = null): ?array
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return null;
        }

        $account = $tenantId !== null
            ? app(CajuPayAccountResolver::class)->resolveForTenant($tenantId)
            : app(CajuPayAccountResolver::class)->defaultOrFirstConnected();
        if ($account === null) {
            return null;
        }

        $credentials = $account->getDecryptedCredentials();
        if (empty($credentials['public_key'] ?? null) || empty($credentials['secret_key'] ?? null)) {
            return null;
        }

        $record = $this->fetchPayoutById($externalId, $credentials);
        if ($record === null) {
            $record = $this->fetchPayoutFromList($externalId, $credentials);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    private function fetchPayoutById(string $externalId, array $credentials): ?array
    {
        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/payouts/'.rawurlencode($externalId));

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            if (! $this->isPayoutRecordPayload($data)) {
                $nested = $data['payout'] ?? $data['data'] ?? null;
                if (is_array($nested) && $this->isPayoutRecordPayload($nested)) {
                    return $nested;
                }

                return null;
            }

            return $data;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isPayoutRecordPayload(array $data): bool
    {
        foreach (['id', 'payout_id', 'uuid'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    private function fetchPayoutFromList(string $externalId, array $credentials): ?array
    {
        try {
            $response = $this->httpForCredentials($credentials)
                ->get('/api/payouts', ['limit' => 50]);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            if (! is_array($json)) {
                return null;
            }

            $items = $json['data'] ?? $json['payouts'] ?? $json['items'] ?? $json;
            if (! is_array($items)) {
                return null;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? $item['payout_id'] ?? ''));
                if ($id !== '' && $id === $externalId) {
                    return $item;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function extractStatusFromPayoutRecord(array $record): ?string
    {
        $candidates = [
            $record['status'] ?? null,
            data_get($record, 'state'),
            data_get($record, 'payout_status'),
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }
}
