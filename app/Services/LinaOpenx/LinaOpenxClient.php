<?php

namespace App\Services\LinaOpenx;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cliente HTTP da Lina OpenX (ITP Open Finance).
 * Token OAuth2 client_credentials apenas server-side, com cache até expires_in - 60s.
 */
class LinaOpenxClient
{
    /** IAM OAuth — homologação (Realm Keycloak). */
    public const DEFAULT_TOKEN_URL_HML = 'https://iam.hml.linaob.com.br/realms/ob-epp/protocol/openid-connect/token';

    /** Embedded Payment Manager — HML (SDK / white-label). */
    public const DEFAULT_API_BASE_HML = 'https://embedded-payment-manager.hml.linaob.com.br';

    /** IAM OAuth — produção. */
    public const DEFAULT_TOKEN_URL_PROD = 'https://iam.linaob.com.br/realms/ob-epp/protocol/openid-connect/token';

    /** Embedded Payment Manager — produção. */
    public const DEFAULT_API_BASE_PROD = 'https://embedded-payment-manager.linaob.com.br';

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        try {
            $token = $this->getAccessToken($credentials, forceRefresh: true);
            if ($token === null || $token === '') {
                return false;
            }
            $response = $this->authenticatedGet($credentials, '/api/v1/sub-tenants');
            if ($response->successful()) {
                return true;
            }
            $response = $this->authenticatedGet($credentials, '/api/v1/open-integration/participants/registered');

            return $response->successful() || $response->status() === 404;
        } catch (\Throwable $e) {
            Log::warning('LinaOpenxClient: testConnection failed', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Cria pagamento white-label (portal) e retorna id + redirectUrl.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{transaction_id: string, redirect_url: string, raw: array<string, mixed>}
     */
    public function createWhiteLabelPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $redirectUri,
        array $options = []
    ): array {
        $document = preg_replace('/\D+/', '', (string) ($consumer['document'] ?? '')) ?? '';
        if ($document === '') {
            throw new \RuntimeException('Lina OpenX: CPF/CNPJ do pagador é obrigatório.');
        }

        $name = trim((string) ($consumer['name'] ?? ''));
        if ($name === '') {
            $name = 'Cliente';
        }
        $email = trim((string) ($consumer['email'] ?? ''));
        $redirectUri = trim($redirectUri);
        if ($redirectUri === '' || ! filter_var($redirectUri, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Lina OpenX: redirectUri inválida.');
        }

        $amount = round(max(0.01, $amount), 2);
        // Contrato real Embedded Payment Manager (white-label):
        // - value: number (BRL, 2 casas)
        // - cpfCnpj: top-level do pagador
        // - creditor: flat (accountIspb, accountIssuer, accountNumber, accountType, personType)
        $creditor = $this->buildCreditorPayload($credentials, $options);
        if ($creditor === null) {
            throw new \RuntimeException(
                'Lina OpenX: preencha os dados do credor (recebedor) no adquirente — nome, CPF/CNPJ, ISPB (8 dígitos), agência, conta e tipo (CACC/SVGS/TRAN).'
            );
        }

        $body = [
            'value' => $amount,
            'externalId' => $externalId,
            'redirectUri' => $redirectUri,
            'cpfCnpj' => $document,
            'description' => mb_substr((string) ($options['description'] ?? ('Pedido #'.$externalId)), 0, 140),
            'debtor' => array_filter([
                'name' => mb_substr($name, 0, 140),
                'cpfCnpj' => $document,
                'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            ], fn ($v) => $v !== null && $v !== ''),
            'creditor' => $creditor,
        ];
        if (! empty($options['metadata']) && is_array($options['metadata'])) {
            $body['metadata'] = $options['metadata'];
        }

        $response = $this->authenticatedPost($credentials, '/api/v1/payments', $body);
        if (! $response->successful()) {
            $message = $this->extractErrorMessage($response);
            Log::warning('LinaOpenxClient: createWhiteLabelPayment failed', [
                'status' => $response->status(),
                'message' => $message,
                'body' => Str::limit(trim((string) $response->body()), 800),
            ]);
            throw new \RuntimeException('Lina OpenX: '.$message);
        }

        $data = $this->unwrapData($response->json());
        $transactionId = $this->firstString($data, [
            'id',
            'paymentRequestId',
            'payment_request_id',
            'paymentLinkId',
            'payment_link_id',
        ]);
        $redirectUrl = $this->firstString($data, [
            'redirectUrl',
            'redirect_url',
            'url',
        ]);
        if ($transactionId === '' || $redirectUrl === '') {
            // Alguns envelopes aninham em data
            $nested = is_array($data['data'] ?? null) ? $data['data'] : [];
            if ($transactionId === '') {
                $transactionId = $this->firstString($nested, ['id', 'paymentRequestId', 'paymentLinkId']);
            }
            if ($redirectUrl === '') {
                $redirectUrl = $this->firstString($nested, ['redirectUrl', 'redirect_url', 'url']);
            }
        }
        if ($transactionId === '' || $redirectUrl === '') {
            throw new \RuntimeException('Lina OpenX: resposta sem payment id ou redirectUrl.');
        }
        if (! filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Lina OpenX: redirectUrl inválida retornada pela API.');
        }

        return [
            'transaction_id' => $transactionId,
            'redirect_url' => $redirectUrl,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * Consulta o payment request e normaliza status de pagamento.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{status: string, request_status: ?string, payment_status: ?string, raw: array<string, mixed>}
     */
    public function getPaymentRequest(array $credentials, string $paymentRequestId): array
    {
        $paymentRequestId = trim($paymentRequestId);
        if ($paymentRequestId === '') {
            return ['status' => 'pending', 'request_status' => null, 'payment_status' => null, 'raw' => []];
        }

        $path = '/api/v1/payments/requests/'.rawurlencode($paymentRequestId);
        $response = $this->authenticatedGet($credentials, $path);
        if (! $response->successful()) {
            Log::info('LinaOpenxClient: getPaymentRequest not successful', [
                'status' => $response->status(),
                'payment_request_id' => $paymentRequestId,
            ]);

            return ['status' => 'pending', 'request_status' => null, 'payment_status' => null, 'raw' => []];
        }

        $data = $this->unwrapData($response->json());
        $requestStatus = $this->firstString($data, ['status', 'consentStatus', 'consent_status']);
        $paymentStatus = null;
        $payments = $data['payments'] ?? null;
        if (is_array($payments) && $payments !== []) {
            $first = $payments[0];
            if (is_array($first)) {
                $paymentStatus = $this->firstString($first, ['status', 'paymentStatus', 'payment_status']);
            }
            foreach ($payments as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $s = strtoupper($this->firstString($row, ['status', 'paymentStatus', 'payment_status']));
                if ($s === 'PAGO' || $s === 'PAID' || $s === 'ACSC' || $s === 'ACSP') {
                    $paymentStatus = $this->firstString($row, ['status', 'paymentStatus', 'payment_status']);
                    break;
                }
            }
        }

        $normalized = $this->normalizeStatuses($requestStatus, $paymentStatus);

        return [
            'status' => $normalized,
            'request_status' => $requestStatus !== '' ? $requestStatus : null,
            'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * Instituições habilitadas para Automatic Payments (GET /open-integration/participants/registered).
     *
     * @param  array<string, mixed>  $credentials
     * @return list<array{organisation_id: string, authorisation_server_id: string, name: string}>
     */
    public function listRegisteredParticipants(array $credentials): array
    {
        $response = $this->authenticatedGet($credentials, '/api/v1/open-integration/participants/registered');
        if (! $response->successful()) {
            Log::info('LinaOpenxClient: listRegisteredParticipants failed', [
                'status' => $response->status(),
            ]);

            return [];
        }

        return $this->parseRegisteredParticipants($response->json());
    }

    /**
     * POST /automatic-payments/recurring-consents
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @return array{consent_id: string, redirect_url: string, payment_request_id: string, raw: array<string, mixed>}
     */
    public function createRecurringConsent(array $credentials, array $body): array
    {
        $response = $this->authenticatedPost($credentials, '/api/v1/automatic-payments/recurring-consents', $body);
        if (! $response->successful()) {
            $message = $this->extractErrorMessage($response);
            Log::warning('LinaOpenxClient: createRecurringConsent failed', [
                'status' => $response->status(),
                'message' => $message,
                'body' => Str::limit(trim((string) $response->body()), 800),
            ]);
            throw new \RuntimeException('Lina OpenX: '.$message);
        }

        $data = $this->unwrapData($response->json());
        $nested = is_array($data['data'] ?? null) ? $data['data'] : [];
        $consentId = $this->firstString($data, ['consentId', 'recurringConsentId', 'recurring_consent_id']);
        if ($consentId === '') {
            $consentId = $this->firstString($nested, ['consentId', 'recurringConsentId', 'recurring_consent_id']);
        }
        $redirectUrl = $this->firstString($data, ['redirectUrl', 'redirect_url', 'url']);
        if ($redirectUrl === '') {
            $redirectUrl = $this->firstString($nested, ['redirectUrl', 'redirect_url', 'url']);
        }
        $paymentRequestId = $this->firstString($data, [
            'paymentRequestId',
            'payment_request_id',
            'paymentLinkId',
            'payment_link_id',
            'id',
        ]);
        if ($paymentRequestId === '') {
            $paymentRequestId = $this->firstString($nested, [
                'paymentRequestId',
                'payment_request_id',
                'paymentLinkId',
                'payment_link_id',
                'id',
            ]);
        }
        if ($consentId === '' || $redirectUrl === '') {
            throw new \RuntimeException('Lina OpenX: resposta sem consentId ou redirectUrl.');
        }
        if (! filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Lina OpenX: redirectUrl inválida retornada pela API.');
        }

        return [
            'consent_id' => $consentId,
            'redirect_url' => $redirectUrl,
            'payment_request_id' => $paymentRequestId !== '' ? $paymentRequestId : $consentId,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * GET /automatic-payments/request/{id}
     *
     * @param  array<string, mixed>  $credentials
     * @return array{
     *     status: string,
     *     consent_id: ?string,
     *     consent_status: ?string,
     *     payment_status: ?string,
     *     payments: list<array<string, mixed>>,
     *     raw: array<string, mixed>
     * }
     */
    public function getAutomaticPaymentRequest(array $credentials, string $paymentRequestId): array
    {
        $paymentRequestId = trim($paymentRequestId);
        if ($paymentRequestId === '') {
            return [
                'status' => 'pending',
                'consent_id' => null,
                'consent_status' => null,
                'payment_status' => null,
                'payments' => [],
                'raw' => [],
            ];
        }

        $path = '/api/v1/automatic-payments/request/'.rawurlencode($paymentRequestId);
        $response = $this->authenticatedGet($credentials, $path);
        if (! $response->successful()) {
            Log::info('LinaOpenxClient: getAutomaticPaymentRequest not successful', [
                'status' => $response->status(),
                'payment_request_id' => $paymentRequestId,
            ]);

            return [
                'status' => 'pending',
                'consent_id' => null,
                'consent_status' => null,
                'payment_status' => null,
                'payments' => [],
                'raw' => [],
            ];
        }

        $data = $this->unwrapData($response->json());
        $consentStatus = $this->firstString($data, ['consentStatus', 'consent_status', 'status']);
        $consentId = $this->firstString($data, ['consentId', 'recurringConsentId', 'recurring_consent_id']);
        $payments = $this->extractPaymentsList($data);
        $paymentStatus = $this->pickPaymentStatus($payments);

        return [
            'status' => $this->normalizeAutomaticStatuses($consentStatus, $paymentStatus),
            'consent_id' => $consentId !== '' ? $consentId : null,
            'consent_status' => $consentStatus !== '' ? $consentStatus : null,
            'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
            'payments' => $payments,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * POST /automatic-payments/recurring-payments
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @return array{recurring_payment_id: string, raw: array<string, mixed>}
     */
    public function createRecurringPayment(array $credentials, array $body): array
    {
        $response = $this->authenticatedPost($credentials, '/api/v1/automatic-payments/recurring-payments', $body);
        if (! $response->successful()) {
            $message = $this->extractErrorMessage($response);
            Log::warning('LinaOpenxClient: createRecurringPayment failed', [
                'status' => $response->status(),
                'message' => $message,
                'body' => Str::limit(trim((string) $response->body()), 800),
            ]);
            throw new \RuntimeException('Lina OpenX: '.$message);
        }

        $data = $this->unwrapData($response->json());
        $nested = is_array($data['data'] ?? null) ? $data['data'] : [];
        $paymentId = $this->firstString($data, ['recurringPaymentId', 'recurring_payment_id', 'paymentId', 'id']);
        if ($paymentId === '') {
            $paymentId = $this->firstString($nested, ['recurringPaymentId', 'recurring_payment_id', 'paymentId', 'id']);
        }
        if ($paymentId === '') {
            throw new \RuntimeException('Lina OpenX: resposta sem recurringPaymentId.');
        }

        return [
            'recurring_payment_id' => $paymentId,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * GET /automatic-payments/recurring-payments/{id}
     *
     * @param  array<string, mixed>  $credentials
     * @return array{status: string, payment_status: ?string, raw: array<string, mixed>}
     */
    public function getRecurringPayment(array $credentials, string $recurringPaymentId): array
    {
        $recurringPaymentId = trim($recurringPaymentId);
        if ($recurringPaymentId === '') {
            return ['status' => 'pending', 'payment_status' => null, 'raw' => []];
        }

        $path = '/api/v1/automatic-payments/recurring-payments/'.rawurlencode($recurringPaymentId);
        $response = $this->authenticatedGet($credentials, $path);
        if (! $response->successful()) {
            return ['status' => 'pending', 'payment_status' => null, 'raw' => []];
        }

        $data = $this->unwrapData($response->json());
        $paymentStatus = $this->firstString($data, ['status', 'paymentStatus', 'payment_status']);
        $nested = is_array($data['data'] ?? null) ? $data['data'] : [];
        if ($paymentStatus === '') {
            $paymentStatus = $this->firstString($nested, ['status', 'paymentStatus', 'payment_status']);
        }

        return [
            'status' => $this->normalizeAutomaticStatuses(null, $paymentStatus),
            'payment_status' => $paymentStatus !== '' ? $paymentStatus : null,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * PATCH /automatic-payments/recurring-consents/{id} — status REVOKED.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     */
    public function revokeRecurringConsent(array $credentials, string $consentId, array $body = []): void
    {
        $consentId = trim($consentId);
        if ($consentId === '') {
            return;
        }
        if ($body === []) {
            $body = [
                'status' => 'REVOKED',
                'revocation' => [
                    'revokedBy' => 'INICIADORA',
                    'revokedFrom' => 'DETENTORA',
                    'reason' => [
                        'code' => 'REVOGADO_RECEBEDOR',
                        'detail' => 'NAO_INFORMADO',
                    ],
                ],
            ];
        }

        $path = '/api/v1/automatic-payments/recurring-consents/'.rawurlencode($consentId);
        $response = $this->authenticatedPatch($credentials, $path, $body);
        if (! $response->successful() && $response->status() !== 404) {
            $message = $this->extractErrorMessage($response);
            Log::warning('LinaOpenxClient: revokeRecurringConsent failed', [
                'status' => $response->status(),
                'consent_id' => $consentId,
                'message' => $message,
            ]);
            throw new \RuntimeException('Lina OpenX: '.$message);
        }
    }

    /**
     * PATCH /automatic-payments/recurring-payments/{id}
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     */
    public function cancelRecurringPayment(array $credentials, string $recurringPaymentId, array $body): void
    {
        $recurringPaymentId = trim($recurringPaymentId);
        if ($recurringPaymentId === '') {
            return;
        }

        $path = '/api/v1/automatic-payments/recurring-payments/'.rawurlencode($recurringPaymentId);
        $response = $this->authenticatedPatch($credentials, $path, $body);
        if (! $response->successful() && $response->status() !== 404) {
            $message = $this->extractErrorMessage($response);
            Log::warning('LinaOpenxClient: cancelRecurringPayment failed', [
                'status' => $response->status(),
                'recurring_payment_id' => $recurringPaymentId,
                'message' => $message,
            ]);
            throw new \RuntimeException('Lina OpenX: '.$message);
        }
    }

    /**
     * POST /automatic-payments/recurring-payments/{id}/retry
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     * @return array{recurring_payment_id: ?string, raw: array<string, mixed>}
     */
    public function retryRecurringPayment(array $credentials, string $originalRecurringPaymentId, array $body): array
    {
        $originalRecurringPaymentId = trim($originalRecurringPaymentId);
        if ($originalRecurringPaymentId === '') {
            throw new \RuntimeException('Lina OpenX: recurringPaymentId ausente para retentativa.');
        }

        $path = '/api/v1/automatic-payments/recurring-payments/'.rawurlencode($originalRecurringPaymentId).'/retry';
        $response = $this->authenticatedPost($credentials, $path, $body);
        if (! $response->successful()) {
            $message = $this->extractErrorMessage($response);
            Log::warning('LinaOpenxClient: retryRecurringPayment failed', [
                'status' => $response->status(),
                'recurring_payment_id' => $originalRecurringPaymentId,
                'message' => $message,
            ]);
            throw new \RuntimeException('Lina OpenX: '.$message);
        }

        $data = $this->unwrapData($response->json());
        $nested = is_array($data['data'] ?? null) ? $data['data'] : [];
        $paymentId = $this->firstString($data, ['recurringPaymentId', 'paymentRequestId', 'id']);
        if ($paymentId === '') {
            $paymentId = $this->firstString($nested, ['recurringPaymentId', 'paymentRequestId', 'id']);
        }

        return [
            'recurring_payment_id' => $paymentId !== '' ? $paymentId : null,
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * Credor (recebedor) a partir das credenciais do adquirente.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{
     *     name: string,
     *     cpfCnpj: string,
     *     personType: string,
     *     accountIspb: string,
     *     accountIssuer: string,
     *     accountNumber: string,
     *     accountType: string
     * }|null
     */
    public function creditorPayload(array $credentials, array $options = []): ?array
    {
        return $this->buildCreditorPayload($credentials, $options);
    }

    /**
     * AUTHORISED no consentimento ainda não é liquidação — só ACSC/PAGO.
     */
    public function normalizeAutomaticStatuses(?string $consentStatus, ?string $paymentStatus): string
    {
        $req = strtoupper(trim((string) $consentStatus));
        $pay = strtoupper(trim((string) $paymentStatus));

        if (in_array($pay, ['PAGO', 'PAID', 'ACSC', 'ACSP', 'SETTLED', 'COMPLETED'], true)) {
            return 'paid';
        }
        if (in_array($pay, ['REJEITADO', 'REJECTED', 'RJCT', 'FAILED'], true)
            || in_array($req, ['REJECTED', 'REJEITADO', 'RJCT'], true)) {
            return 'rejected';
        }
        if (in_array($pay, ['CANCELADO', 'CANCELLED', 'CANCELED', 'CANC'], true)
            || in_array($req, ['REVOKED', 'CANCELADO', 'CANCELLED', 'CANCELED'], true)) {
            return 'cancelled';
        }
        if (in_array($pay, ['EXPIRADO', 'EXPIRED'], true)
            || in_array($req, ['EXPIRED', 'EXPIRADO'], true)) {
            return 'cancelled';
        }

        return 'pending';
    }

    /**
     * Mapeia status Lina → paid|pending|cancelled|rejected.
     */
    public function normalizeStatuses(?string $requestStatus, ?string $paymentStatus): string
    {
        $req = strtoupper(trim((string) $requestStatus));
        $pay = strtoupper(trim((string) $paymentStatus));

        // ACSP = aceito, liquidação em andamento (Pix Open Finance) — trata como pago no e-commerce.
        if (in_array($pay, ['PAGO', 'PAID', 'ACSC', 'ACSP', 'SETTLED', 'COMPLETED'], true)
            || in_array($req, ['PAGO', 'PAID', 'ACSC', 'ACSP', 'SETTLED', 'COMPLETED'], true)) {
            return 'paid';
        }
        if (in_array($pay, ['REJEITADO', 'REJECTED', 'RJCT', 'FAILED'], true)) {
            return 'rejected';
        }
        if (in_array($pay, ['CANCELADO', 'CANCELLED', 'CANCELED', 'CANC'], true)
            || in_array($req, ['CANCELADO', 'CANCELLED', 'CANCELED'], true)) {
            return 'cancelled';
        }
        if (in_array($pay, ['EXPIRADO', 'EXPIRED'], true)
            || in_array($req, ['EXPIRADO', 'EXPIRED'], true)) {
            return 'cancelled';
        }
        if (in_array($req, ['ERRO', 'ERRO_NA_DETENTORA'], true)
            || in_array($pay, ['ERRO', 'ERRO_NA_DETENTORA'], true)) {
            return 'rejected';
        }
        // Consent consumido ainda com payment em processamento → pending (poll/webhook reconsulta).
        if ($req === 'CONSUMIDO' && in_array($pay, ['PENDENTE', 'EM_PROCESSAMENTO', 'PENDING'], true)) {
            return 'pending';
        }
        // Instant: request CONSUMIDO sem detalhe de payment → liquidado (contrato Instant / unit test).
        if ($req === 'CONSUMIDO' && $pay === '') {
            return 'paid';
        }

        return 'pending';
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  bool  $forceRefresh  Ignora cache (útil no testConnection multi-ambiente).
     */
    public function getAccessToken(array $credentials, bool $forceRefresh = false): ?string
    {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $clientSecret = (string) ($credentials['client_secret'] ?? '');
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $cacheKey = 'linaopenx_token:'.hash('sha256', $clientId.'|'.($credentials['token_url'] ?? '').'|'.($this->isSandbox($credentials) ? 'hml' : 'prod'));
        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } else {
            Cache::forget($cacheKey);
        }

        $tokenUrl = $this->tokenUrl($credentials);
        try {
            $response = Http::asForm()
                ->timeout(20)
                ->connectTimeout(10)
                ->post($tokenUrl, [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (\Throwable $e) {
            Log::warning('LinaOpenxClient: token request failed', [
                'message' => $e->getMessage(),
                'sandbox' => $this->isSandbox($credentials),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('LinaOpenxClient: token HTTP error', [
                'status' => $response->status(),
                'sandbox' => $this->isSandbox($credentials),
                'token_host' => parse_url($tokenUrl, PHP_URL_HOST),
            ]);

            return null;
        }

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 900);
        $ttl = max(60, $expiresIn - 60);
        Cache::put($cacheKey, $accessToken, now()->addSeconds($ttl));

        return $accessToken;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function authenticatedGet(array $credentials, string $path): Response
    {
        return $this->request($credentials, 'GET', $path);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     */
    private function authenticatedPost(array $credentials, string $path, array $body): Response
    {
        return $this->request($credentials, 'POST', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $body
     */
    private function authenticatedPatch(array $credentials, string $path, array $body): Response
    {
        return $this->request($credentials, 'PATCH', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $body
     */
    private function request(array $credentials, string $method, string $path, ?array $body = null): Response
    {
        $token = $this->getAccessToken($credentials);
        if ($token === null) {
            throw new \RuntimeException('Lina OpenX: falha na autenticação OAuth.');
        }

        $url = rtrim($this->apiBaseUrl($credentials), '/').'/'.ltrim($path, '/');
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'StackerGateway/1.0',
        ];
        $subTenantId = trim((string) ($credentials['sub_tenant_id'] ?? ''));
        if ($subTenantId === '') {
            // Na conta New Pay o subTenantId coincide com o client_id (ex.: newpay).
            $subTenantId = trim((string) ($credentials['client_id'] ?? ''));
        }
        if ($subTenantId !== '') {
            $headers['subTenantId'] = $subTenantId;
            $headers['X-Sub-Tenant-Id'] = $subTenantId;
        }

        $pending = Http::withHeaders($headers)
            ->timeout(25)
            ->connectTimeout(10);

        return match (strtoupper($method)) {
            'POST' => $pending->post($url, $body ?? []),
            'PATCH' => $pending->patch($url, $body ?? []),
            default => $pending->get($url),
        };
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function tokenUrl(array $credentials): string
    {
        $override = trim((string) ($credentials['token_url'] ?? ''));
        if ($override !== '' && filter_var($override, FILTER_VALIDATE_URL)) {
            return $override;
        }

        return $this->isSandbox($credentials)
            ? self::DEFAULT_TOKEN_URL_HML
            : self::DEFAULT_TOKEN_URL_PROD;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function apiBaseUrl(array $credentials): string
    {
        $override = trim((string) ($credentials['api_base_url'] ?? ''));
        if ($override !== '' && filter_var($override, FILTER_VALIDATE_URL)) {
            return rtrim($override, '/');
        }

        return $this->isSandbox($credentials)
            ? self::DEFAULT_API_BASE_HML
            : self::DEFAULT_API_BASE_PROD;
    }

    /**
     * Sandbox (HML) só se marcado explicitamente. Default = produção:
     * as credenciais "newpay" etc. vivem em iam.linaob.com.br, não no HML.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function isSandbox(array $credentials): bool
    {
        if (! array_key_exists('sandbox', $credentials)) {
            return false;
        }

        return filter_var($credentials['sandbox'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  mixed  $json
     * @return array<string, mixed>
     */
    private function unwrapData(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        if (isset($json['data']) && is_array($json['data']) && ! array_is_list($json['data'])) {
            return array_merge($json, $json['data']);
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function firstString(array $data, array $keys): string
    {
        foreach ($this->candidateValues($data, $keys) as $v) {
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            if (is_int($v) || is_float($v)) {
                return (string) $v;
            }
        }

        return '';
    }

    /**
     * A API documentada devolve PascalCase (OrganisationId, AuthorisationServers).
     * A busca exata vem primeiro; o fallback ignora maiúsculas.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private function candidateValues(array $data, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $values[] = $data[$key];
            }
        }
        if ($values !== []) {
            return $values;
        }

        $lower = [];
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                $lower[strtolower($k)] = $v;
            }
        }
        foreach ($keys as $key) {
            $lk = strtolower($key);
            if (array_key_exists($lk, $lower)) {
                $values[] = $lower[$lk];
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function arrayValue(array $data, array $keys): mixed
    {
        $values = $this->candidateValues($data, $keys);
        foreach ($values as $value) {
            if (is_array($value)) {
                return $value;
            }
        }

        return $values[0] ?? null;
    }

    /**
     * Monta creditor no formato flat exigido pela API white-label Lina.
     * Credenciais: creditor_name, creditor_cpf_cnpj, creditor_ispb, creditor_issuer, creditor_number, creditor_account_type.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $options
     * @return array{
     *     name: string,
     *     cpfCnpj: string,
     *     personType: string,
     *     accountIspb: string,
     *     accountIssuer: string,
     *     accountNumber: string,
     *     accountType: string
     * }|null
     */
    private function buildCreditorPayload(array $credentials, array $options): ?array
    {
        if (! empty($options['creditor']) && is_array($options['creditor'])) {
            return $this->normalizeCreditorArray($options['creditor']);
        }

        return $this->normalizeCreditorArray([
            'name' => $credentials['creditor_name'] ?? null,
            'cpfCnpj' => $credentials['creditor_cpf_cnpj'] ?? null,
            'personType' => $credentials['creditor_person_type'] ?? null,
            'accountIspb' => $credentials['creditor_ispb'] ?? null,
            'accountIssuer' => $credentials['creditor_issuer'] ?? null,
            'accountNumber' => $credentials['creditor_number'] ?? null,
            'accountType' => $credentials['creditor_account_type'] ?? null,
            // aliases legados (caso alguém tenha gravado nested / nomes API)
            'ispb' => $credentials['creditor_ispb'] ?? null,
            'issuer' => $credentials['creditor_issuer'] ?? null,
            'number' => $credentials['creditor_number'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *     name: string,
     *     cpfCnpj: string,
     *     personType: string,
     *     accountIspb: string,
     *     accountIssuer: string,
     *     accountNumber: string,
     *     accountType: string
     * }|null
     */
    private function normalizeCreditorArray(array $raw): ?array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        $cpfCnpj = preg_replace('/\D+/', '', (string) ($raw['cpfCnpj'] ?? $raw['cpf_cnpj'] ?? '')) ?? '';

        $account = is_array($raw['account'] ?? null) ? $raw['account'] : [];
        $ispbRaw = (string) ($raw['accountIspb'] ?? $raw['account_ispb'] ?? $account['ispb'] ?? $raw['ispb'] ?? '');
        $ispb = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ispbRaw) ?? '');
        // ISPB numérico costuma vir com menos dígitos (ex.: 607=00000607).
        if ($ispb !== '' && ctype_digit($ispb) && strlen($ispb) < 8) {
            $ispb = str_pad($ispb, 8, '0', STR_PAD_LEFT);
        }

        $issuer = preg_replace('/\D+/', '', (string) ($raw['accountIssuer'] ?? $raw['account_issuer'] ?? $account['issuer'] ?? $raw['issuer'] ?? '')) ?? '';
        $number = preg_replace('/\D+/', '', (string) ($raw['accountNumber'] ?? $raw['account_number'] ?? $account['number'] ?? $raw['number'] ?? '')) ?? '';

        $accountType = strtoupper(trim((string) ($raw['accountType'] ?? $raw['account_type'] ?? $account['accountType'] ?? $account['account_type'] ?? 'CACC')));
        if (! in_array($accountType, ['CACC', 'SVGS', 'TRAN'], true)) {
            $accountType = 'CACC';
        }

        $personType = strtoupper(trim((string) ($raw['personType'] ?? $raw['person_type'] ?? '')));
        if ($personType === 'PF' || $personType === 'NATURAL' || $personType === 'PESSOA_NATURAL') {
            $personType = 'PESSOA_NATURAL';
        } elseif ($personType === 'PJ' || $personType === 'LEGAL' || $personType === 'PESSOA_JURIDICA') {
            $personType = 'PESSOA_JURIDICA';
        } elseif ($cpfCnpj !== '') {
            $personType = strlen($cpfCnpj) > 11 ? 'PESSOA_JURIDICA' : 'PESSOA_NATURAL';
        } else {
            $personType = '';
        }

        if ($name === '' || $cpfCnpj === '' || $ispb === '' || $issuer === '' || $number === '' || $personType === '') {
            return null;
        }
        if (strlen($ispb) !== 8) {
            return null;
        }
        if (strlen($issuer) > 4 || strlen($number) > 20) {
            return null;
        }
        if (strlen($cpfCnpj) !== 11 && strlen($cpfCnpj) !== 14) {
            return null;
        }

        return [
            'name' => mb_substr($name, 0, 140),
            'cpfCnpj' => $cpfCnpj,
            'personType' => $personType,
            'accountIspb' => $ispb,
            'accountIssuer' => $issuer,
            'accountNumber' => $number,
            'accountType' => $accountType,
        ];
    }

    /**
     * @param  mixed  $json
     * @return list<array{organisation_id: string, authorisation_server_id: string, name: string}>
     */
    private function parseRegisteredParticipants(mixed $json): array
    {
        $rows = $this->participantRowsFromJson($json);
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $expanded = $this->expandParticipantRow($row);
            foreach ($expanded as $item) {
                $key = $item['organisation_id'].'|'.$item['authorisation_server_id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private function participantRowsFromJson(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }
        foreach (['data', 'content', 'participants', 'items'] as $key) {
            $rows = $this->participantList($this->arrayValue($json, [$key]));
            if ($rows !== []) {
                return $rows;
            }
        }
        if (array_is_list($json)) {
            return $json;
        }

        return [];
    }

    /**
     * @return list<mixed>
     */
    private function participantList(mixed $block): array
    {
        if (! is_array($block)) {
            return [];
        }
        if (array_is_list($block)) {
            return $block;
        }
        foreach (['data', 'content', 'participants', 'items'] as $key) {
            $inner = $this->arrayValue($block, [$key]);
            if (is_array($inner) && array_is_list($inner)) {
                return $inner;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{organisation_id: string, authorisation_server_id: string, name: string}>
     */
    private function expandParticipantRow(array $row): array
    {
        $status = strtolower($this->firstString($row, ['status']));
        if (in_array($status, ['inactive', 'disabled', 'inativo', 'blocked', 'suspended'], true)) {
            return [];
        }

        $orgId = $this->firstString($row, ['organisationId', 'organisation_id', 'orgId', 'org_id']);
        $organisation = $this->arrayValue($row, ['organisation']);
        if ($orgId === '' && is_array($organisation)) {
            $orgId = $this->firstString($organisation, ['organisationId', 'id', 'orgId']);
        }

        $servers = $this->arrayValue($row, ['authorisationServers', 'authorisation_servers', 'authorizationServers']);
        if (is_array($servers) && $servers !== []) {
            $items = [];
            foreach ($servers as $server) {
                if (! is_array($server)) {
                    continue;
                }
                $asId = $this->firstString($server, ['authorisationServerId', 'authorisation_server_id', 'authorizationServerId', 'asId', 'id']);
                if ($orgId === '' || $asId === '') {
                    continue;
                }
                $name = $this->participantName($server, $row);
                $items[] = [
                    'organisation_id' => $orgId,
                    'authorisation_server_id' => $asId,
                    'name' => $name !== '' ? $name : $orgId,
                ];
            }

            return $items;
        }

        $asId = $this->firstString($row, ['authorisationServerId', 'authorisation_server_id', 'authorizationServerId', 'asId', 'as_id']);
        $authorisationServer = $this->arrayValue($row, ['authorisationServer', 'authorizationServer']);
        if ($asId === '' && is_array($authorisationServer)) {
            $asId = $this->firstString($authorisationServer, ['authorisationServerId', 'id', 'asId']);
        }
        if ($orgId === '' || $asId === '') {
            return [];
        }
        $name = $this->participantName($row, $row);

        return [[
            'organisation_id' => $orgId,
            'authorisation_server_id' => $asId,
            'name' => $name !== '' ? $name : $orgId,
        ]];
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $fallback
     */
    private function participantName(array $primary, array $fallback): string
    {
        $name = $this->firstString($primary, [
            'customerFriendlyName',
            'customer_friendly_name',
            'organisationName',
            'organisation_name',
            'registeredName',
            'legalEntityName',
            'name',
            'tradeName',
        ]);
        if ($name !== '' || $primary === $fallback) {
            return $name;
        }

        return $this->firstString($fallback, [
            'customerFriendlyName',
            'organisationName',
            'organisation_name',
            'registeredName',
            'legalEntityName',
            'name',
            'tradeName',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function extractPaymentsList(array $data): array
    {
        foreach (['payments', 'recurringPayments', 'recurring_payments'] as $key) {
            $raw = $data[$key] ?? null;
            if (is_array($raw) && $raw !== []) {
                return array_values(array_filter($raw, 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    private function pickPaymentStatus(array $payments): string
    {
        $last = '';
        foreach ($payments as $row) {
            $s = $this->firstString($row, ['status', 'paymentStatus', 'payment_status']);
            if ($s === '') {
                continue;
            }
            $last = $s;
            $upper = strtoupper($s);
            if (in_array($upper, ['PAGO', 'PAID', 'ACSC', 'ACSP', 'SETTLED', 'COMPLETED'], true)) {
                return $s;
            }
        }

        return $last;
    }

    private function extractErrorMessage(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $message = $json['message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                return Str::limit(trim($message), 400);
            }
            if (is_array($message)) {
                $parts = collect($message)
                    ->flatten()
                    ->filter(fn ($v) => is_string($v) && trim($v) !== '')
                    ->map(fn ($v) => trim((string) $v))
                    ->unique()
                    ->values()
                    ->all();
                if ($parts !== []) {
                    return Str::limit(implode('; ', $parts), 400);
                }
            }
            foreach (['error_description', 'error', 'title', 'detail'] as $key) {
                $v = $json[$key] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    return Str::limit(trim($v), 400);
                }
            }
            if (isset($json['errors']) && is_array($json['errors'])) {
                $flat = collect($json['errors'])->flatten()->filter()->first();
                if (is_string($flat) && $flat !== '') {
                    return Str::limit($flat, 400);
                }
            }
        }
        $body = Str::limit(trim((string) $response->body()), 200);

        return $body !== '' ? $body : ('HTTP '.$response->status());
    }
}
