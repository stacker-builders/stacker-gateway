<?php

namespace App\Services\Versell;

use App\Gateways\Versell\VersellCredentials;
use App\Models\GatewayCredential;
use App\Models\Withdrawal;
use App\Services\EffectiveMerchantFees;
use App\Services\Payout\GatewayPayoutEconomics;
use App\Services\Withdrawal\WithdrawalMinimumService;
use App\Services\WithdrawalPixReceiptService;
use App\Support\BrazilianDocumentDigits;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VersellPayoutService
{
    public function __construct(
        private readonly VersellHttpClient $client = new VersellHttpClient()
    ) {}

    /**
     * @return array{ok: bool, external_id?: string, error?: string, status?: string, pending?: bool}
     */
    public function sendWithdrawalToPixKey(
        Withdrawal $withdrawal,
        string $pixKey,
        string $pixKeyType,
        string $keyOwnerDocument,
    ): array {
        $credential = GatewayCredential::resolveForPayment(null, 'versell');
        if ($credential === null || ! $credential->is_connected) {
            return ['ok' => false, 'error' => 'Versell não configurada na plataforma (adquirentes).'];
        }

        $credentials = $credential->getDecryptedCredentials();
        if (! VersellCredentials::isCashOutReady($credentials)) {
            return ['ok' => false, 'error' => 'Credenciais Cash Out da Versell incompletas (client + certificados).'];
        }

        $net = (float) $withdrawal->net_amount;
        if ($net <= 0) {
            return ['ok' => false, 'error' => 'Valor líquido do saque inválido.'];
        }

        $economics = GatewayPayoutEconomics::fromCredentialsArray('versell', $credentials);
        $requiredNet = WithdrawalMinimumService::effectiveRequiredMinNet($economics);
        $minCents = (int) max(1, (int) round($requiredNet * 100));
        $netCents = (int) round($net * 100);
        if ($netCents < $minCents) {
            $tenantId = (int) $withdrawal->tenant_id;
            $minGross = EffectiveMerchantFees::minimumWithdrawalGrossForTargetNet($tenantId, $requiredNet);
            $msg = $minGross !== null
                ? 'O valor mínimo do saque é R$ '.number_format($minGross, 2, ',', '.').' (valor total a solicitar).'
                : 'O valor solicitado é inferior ao mínimo permitido.';

            return ['ok' => false, 'error' => $msg];
        }

        $apiAmount = GatewayPayoutEconomics::transferAmountBrlForApi(
            $net,
            $economics['admin_fee_payout_brl'],
            $economics['admin_fee_payout_percent'] ?? 0.0,
        );

        $pixKey = trim($pixKey);
        $pixKeyType = $this->normalizePixKeyType($pixKeyType, $pixKey);
        $doc = BrazilianDocumentDigits::onlyDigits($keyOwnerDocument);
        if ($pixKey === '' || $pixKeyType === '') {
            return ['ok' => false, 'error' => 'Configure a chave PIX e o tipo da chave em Financeiro antes de solicitar saque.'];
        }
        if ($doc === null || ! BrazilianDocumentDigits::isValidCpfOrCnpjLength($doc)) {
            return ['ok' => false, 'error' => 'CPF/CNPJ do titular ausente ou inválido no cadastro da chave PIX.'];
        }

        $meta = is_array($withdrawal->payout_meta) ? $withdrawal->payout_meta : [];
        $idempotencyKey = trim((string) ($meta['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || ! preg_match('/^[a-zA-Z0-9]{1,50}$/', $idempotencyKey)) {
            $idempotencyKey = $this->makeIdempotencyKey((int) $withdrawal->id);
            $meta['idempotency_key'] = $idempotencyKey;
            $withdrawal->update(['payout_meta' => $meta]);
        }

        // Já enviado: não POST de novo — reconcilia
        $existingExt = trim((string) ($withdrawal->payout_external_id ?? ''));
        if ($existingExt !== '') {
            return [
                'ok' => true,
                'pending' => true,
                'external_id' => $existingExt,
                'status' => (string) ($meta['versell_status'] ?? 'ON_QUEUE'),
            ];
        }

        $body = [
            'priority' => 'HIGH',
            'paymentFlow' => 'INSTANT',
            'expiration' => 3600,
            'payment' => [
                'currency' => 'BRL',
                'amount' => round($apiAmount, 2),
            ],
            'description' => 'Saque #'.$withdrawal->id,
            'pixKey' => $pixKey,
            'pixKeyType' => $pixKeyType,
            'creditorDocument' => $doc,
        ];

        try {
            $response = $this->client->request(
                VersellCredentials::API_CASH_OUT,
                $credentials,
                'POST',
                '/pix/payments/dict',
                $body,
                ['x-idempotency-key' => $idempotencyKey]
            );
        } catch (\Throwable $e) {
            Log::warning('VersellPayoutService: request failed', [
                'gateway' => 'versell',
                'withdrawal_id' => $withdrawal->id,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            // Timeout/rede: tenta localizar por idempotency
            $found = $this->lookupByIdempotencyKey($credentials, $idempotencyKey);
            if ($found !== null) {
                return $found;
            }

            return ['ok' => false, 'error' => 'Falha de comunicação com a Versell ao enviar o saque.'];
        }

        if ($response->status() === 412) {
            $found = $this->lookupByIdempotencyKey($credentials, $idempotencyKey);
            if ($found !== null) {
                return $found;
            }

            return [
                'ok' => false,
                'error' => 'Idempotency-key já utilizada na Versell; não foi possível recuperar o pagamento.',
            ];
        }

        if (! $response->successful()) {
            $problem = VersellProblemDetails::fromResponse($response->json(), $response->status(), $response->body());
            Log::warning('VersellPayoutService: payout rejected', [
                'gateway' => 'versell',
                'withdrawal_id' => $withdrawal->id,
                'status' => $problem['status'],
                'error' => $problem['message'],
            ]);

            return ['ok' => false, 'error' => 'Versell: '.$problem['message']];
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $endToEndId = VersellPayoutStatuses::endToEndIdFromPayload($payload);
        $status = VersellPayoutStatuses::statusFromPayload($payload) ?: 'ON_QUEUE';

        if ($endToEndId === '') {
            // Algumas respostas 202 podem trazer id próprio — ainda assim persistimos o que houver
            $endToEndId = trim((string) ($payload['id'] ?? $payload['paymentId'] ?? ''));
        }

        app(WithdrawalPixReceiptService::class)->snapshotDestination($withdrawal->fresh(), null, [
            'pix_key' => $pixKey,
            'pix_key_type' => strtolower($pixKeyType),
            'key_owner_document' => $doc,
        ]);

        Log::info('VersellPayoutService: payout aceito', [
            'gateway' => 'versell',
            'withdrawal_id' => $withdrawal->id,
            'status' => $status,
            'has_e2e' => $endToEndId !== '',
        ]);

        return [
            'ok' => true,
            'pending' => true,
            'external_id' => $endToEndId !== '' ? Str::limit($endToEndId, 80, '') : $idempotencyKey,
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, external_id?: string, status?: string, pending?: bool}|null
     */
    public function lookupByIdempotencyKey(array $credentials, string $idempotencyKey): ?array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            return null;
        }

        try {
            $response = $this->client->request(
                VersellCredentials::API_CASH_OUT,
                $credentials,
                'GET',
                '/pix/payments/idempotencyKey/'.rawurlencode($idempotencyKey)
            );
        } catch (\Throwable $e) {
            Log::warning('VersellPayoutService: idempotency lookup failed', [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return null;
        }

        if ($response->status() === 404 || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $endToEndId = VersellPayoutStatuses::endToEndIdFromPayload($payload);
        $status = VersellPayoutStatuses::statusFromPayload($payload) ?: 'ON_QUEUE';
        if ($endToEndId === '') {
            return null;
        }

        return [
            'ok' => true,
            'pending' => true,
            'external_id' => Str::limit($endToEndId, 80, ''),
            'status' => $status,
        ];
    }

    /**
     * @return 'paid'|'failed'|'pending'|null
     */
    public function getPayoutSettlementStatus(string $endToEndIdOrKey, array $credentials): ?string
    {
        $id = trim($endToEndIdOrKey);
        if ($id === '') {
            return null;
        }

        try {
            $response = $this->client->request(
                VersellCredentials::API_CASH_OUT,
                $credentials,
                'GET',
                '/pix/payments/'.rawurlencode($id)
            );
        } catch (\Throwable $e) {
            Log::warning('VersellPayoutService: get status failed', [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return null;
        }

        if ($response->status() === 404) {
            // Pode ser idempotency key gravada como external_id
            $byKey = $this->lookupByIdempotencyKey($credentials, $id);
            if ($byKey === null) {
                return null;
            }

            return VersellPayoutStatuses::mapToLocal($byKey['status'] ?? null);
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $status = VersellPayoutStatuses::statusFromPayload($payload);

        return VersellPayoutStatuses::mapToLocal($status !== '' ? $status : null);
    }

    private function makeIdempotencyKey(int $withdrawalId): string
    {
        // [a-zA-Z0-9]{1,50}
        $key = 'W'.$withdrawalId.'V'.strtoupper(bin2hex(random_bytes(8)));

        return substr(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?: ('W'.$withdrawalId), 0, 50);
    }

    private function normalizePixKeyType(string $type, string $key): string
    {
        $t = strtoupper(trim($type));
        $mapped = match (strtolower($t)) {
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'EMAIL',
            'phone', 'telefone' => 'PHONE',
            'evp', 'random', 'aleatoria', 'aleatória' => 'EVP',
            default => $t,
        };
        if (in_array($mapped, ['CPF', 'CNPJ', 'EMAIL', 'PHONE', 'EVP'], true)) {
            return $mapped;
        }
        if (str_contains($key, '@')) {
            return 'EMAIL';
        }
        $digits = preg_replace('/\D/', '', $key) ?: '';
        if (strlen($digits) === 11) {
            return 'CPF';
        }
        if (strlen($digits) === 14) {
            return 'CNPJ';
        }

        return 'EVP';
    }
}
