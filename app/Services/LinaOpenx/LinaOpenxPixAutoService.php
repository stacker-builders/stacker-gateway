<?php

namespace App\Services\LinaOpenx;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\SubscriptionPlan;
use App\Support\CheckoutPaymentConsumer;
use App\Support\GatewayPaymentCredentials;
use App\Support\GatewayPluginRequirement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * PIX automático OpenX: consentimento (redirect) + firstPayment. Sem QR Instant.
 */
class LinaOpenxPixAutoService
{
    public const GATEWAY_SLUG = 'linaopenx';

    public function __construct(
        private readonly LinaOpenxClient $client = new LinaOpenxClient()
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{transaction_id: string, redirect_url: string, consent_id: string, return_url: string}
     */
    public function startConsentForOrder(Order $order, array $validated, string $description = ''): array
    {
        GatewayPluginRequirement::assertUnlocked(self::GATEWAY_SLUG);

        $credentials = $this->credentialsForOrder($order);
        if ($credentials === null) {
            throw new \RuntimeException('Lina OpenX não configurada. Conecte o adquirente no painel.');
        }

        $creditor = $this->client->creditorPayload($credentials);
        if ($creditor === null) {
            throw new \RuntimeException(
                'Preencha os dados do credor (recebedor) no adquirente — nome, CPF/CNPJ, ISPB, agência e conta.'
            );
        }

        $consumer = CheckoutPaymentConsumer::build($validated, $order->id);
        $payerDocument = preg_replace('/\D+/', '', (string) ($consumer['document'] ?? $order->cpf ?? '')) ?? '';
        if (strlen($payerDocument) < 11) {
            throw new \RuntimeException('CPF/CNPJ é obrigatório para pagar com PIX automático.');
        }

        $organisationId = trim((string) ($validated['lina_organisation_id'] ?? ''));
        $authorisationServerId = trim((string) ($validated['lina_authorisation_server_id'] ?? ''));
        $participants = [];
        if ($organisationId === '' || $authorisationServerId === '') {
            try {
                $participants = $this->client->listRegisteredParticipants($credentials);
            } catch (\Throwable) {
                $participants = [];
            }
            if (count($participants) === 1) {
                $organisationId = $organisationId !== '' ? $organisationId : $participants[0]['organisation_id'];
                $authorisationServerId = $authorisationServerId !== '' ? $authorisationServerId : $participants[0]['authorisation_server_id'];
            }
        }
        if ($organisationId === '' || $authorisationServerId === '') {
            throw new \RuntimeException(
                $participants === []
                    ? 'Não foi possível carregar os bancos para o PIX automático. Recarregue a página e tente novamente.'
                    : 'Selecione o banco para autorizar o PIX automático.'
            );
        }

        $plan = $order->subscriptionPlan;
        $interval = self::intervalFromPlan($plan?->interval);
        $amount = $this->formatAmount((float) $order->amount);
        $firstDate = now()->addDay()->toDateString();
        $referenceStartDate = now()->addDay()->utc()->startOfDay()->format('Y-m-d\TH:i:s\Z');
        $payerName = trim((string) ($consumer['name'] ?? '')) ?: 'Cliente';
        $returnUrl = $this->signedReturnUrl($order);
        $info = $description !== '' ? $description : ('Assinatura PIX automático - Pedido #'.($order->public_reference ?: $order->id));
        $creditorAccount = [
            'ispb' => $creditor['accountIspb'],
            'issuer' => $creditor['accountIssuer'],
            'number' => $creditor['accountNumber'],
            'accountType' => $creditor['accountType'],
        ];

        $recurringConsent = [
            'loggedUser' => [
                'document' => $this->documentBlock($payerDocument),
            ],
            'creditors' => [[
                'personType' => $creditor['personType'],
                'cpfCnpj' => $creditor['cpfCnpj'],
                'name' => $creditor['name'],
            ]],
            'redirectUri' => $returnUrl,
            'expirationDateTime' => now()->addYears(10)->utc()->format('Y-m-d\TH:i:s\Z'),
            'additionalInformation' => mb_substr($info, 0, 140),
            'recurringConfiguration' => [
                'automatic' => [
                    'contractId' => $this->contractIdForOrder($order),
                    'fixedAmount' => $amount,
                    'interval' => $interval,
                    'contractDebtor' => [
                        'name' => mb_substr($payerName, 0, 140),
                        'document' => $this->documentBlock($payerDocument),
                    ],
                    'firstPayment' => [
                        'type' => 'PIX',
                        'date' => $firstDate,
                        'currency' => 'BRL',
                        'amount' => $amount,
                        'remittanceInformation' => mb_substr($info, 0, 140),
                        'creditorAccount' => $creditorAccount,
                    ],
                    'isRetryAccepted' => true,
                    'referenceStartDate' => $referenceStartDate,
                ],
            ],
        ];
        if ($creditor['personType'] === 'PESSOA_JURIDICA') {
            $recurringConsent['businessEntity'] = [
                'document' => $this->documentBlock($creditor['cpfCnpj']),
            ];
        }

        $result = $this->client->createRecurringConsent($credentials, [
            'organisationId' => $organisationId,
            'authorisationServerId' => $authorisationServerId,
            'recurringConsent' => $recurringConsent,
        ]);

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['checkout_payment_method'] = 'pix_auto';
        $meta['linaopenx_pix_auto_consent_id'] = $result['consent_id'];
        $meta['lina_payment_request_id'] = $result['payment_request_id'];
        $meta['lina_return_url'] = $returnUrl;
        $meta['lina_organisation_id'] = $organisationId;
        $meta['lina_authorisation_server_id'] = $authorisationServerId;

        $order->update([
            'gateway' => self::GATEWAY_SLUG,
            'gateway_id' => $result['payment_request_id'],
            'payment_method' => 'pix_auto',
            'metadata' => $meta,
        ]);

        return [
            'transaction_id' => $result['payment_request_id'],
            'redirect_url' => $result['redirect_url'],
            'consent_id' => $result['consent_id'],
            'return_url' => $returnUrl,
        ];
    }

    /**
     * @return array{order: Order, status: string, paid: bool}
     */
    public function handleReturn(Order $order, ?string $paymentLinkId = null): array
    {
        $order = $order->fresh() ?? $order;
        if ($paymentLinkId !== null && trim($paymentLinkId) !== '') {
            $linkId = trim($paymentLinkId);
            $meta = is_array($order->metadata) ? $order->metadata : [];
            if (($order->gateway_id === null || $order->gateway_id === '') || $order->gateway_id !== $linkId) {
                $meta['lina_payment_request_id'] = $linkId;
                $order->update([
                    'gateway' => self::GATEWAY_SLUG,
                    'gateway_id' => $linkId,
                    'metadata' => $meta,
                ]);
                $order->refresh();
            }
        }

        $this->syncConsentAndMaybeChargeFirst($order);
        $order->refresh();
        $status = $this->resolveNormalizedStatus($order);
        if ($status === 'paid' && $order->status === 'pending') {
            $this->dispatchPaid($order);
            $order->refresh();
        }

        return [
            'order' => $order,
            'status' => $status,
            'paid' => $order->status === 'completed' || $status === 'paid',
        ];
    }

    public function tryCompleteFromApi(Order $order): Order
    {
        $order = $order->fresh() ?? $order;
        if ($order->status !== 'pending') {
            return $order;
        }

        $this->syncConsentAndMaybeChargeFirst($order);
        $order->refresh();
        $status = $this->resolveNormalizedStatus($order);
        if ($status === 'paid') {
            $this->dispatchPaid($order);
            $order->refresh();
        }

        return $order;
    }

    public function resolveNormalizedStatus(Order $order): string
    {
        $credentials = $this->credentialsForOrder($order);
        if ($credentials === null) {
            return 'pending';
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $recurringPaymentId = trim((string) ($meta['linaopenx_pix_auto_recurring_payment_id'] ?? ''));
        if ($recurringPaymentId !== '') {
            try {
                $payment = $this->client->getRecurringPayment($credentials, $recurringPaymentId);
                if (($payment['status'] ?? '') === 'paid') {
                    return 'paid';
                }
            } catch (\Throwable $e) {
                Log::debug('LinaOpenxPixAutoService: recurring payment lookup failed', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $requestId = trim((string) ($order->gateway_id ?? ''));
        if ($requestId === '') {
            $requestId = trim((string) ($meta['lina_payment_request_id'] ?? ''));
        }
        if ($requestId === '') {
            return 'pending';
        }

        try {
            $result = $this->client->getAutomaticPaymentRequest($credentials, $requestId);

            return $result['status'] ?? 'pending';
        } catch (\Throwable $e) {
            Log::debug('LinaOpenxPixAutoService: request lookup failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return 'pending';
        }
    }

    /**
     * @return list<array{organisation_id: string, authorisation_server_id: string, name: string}>
     */
    public function participantsForTenant(?int $tenantId): array
    {
        if (! GatewayPluginRequirement::isUnlocked(self::GATEWAY_SLUG)) {
            return [];
        }
        $credentials = $this->credentialsForTenant($tenantId);
        if ($credentials === null) {
            return [];
        }

        $cacheKey = 'linaopenx_pix_auto_participants:v2:'.hash('sha256', (string) $tenantId.'|'.($credentials['client_id'] ?? ''));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $list = $this->client->listRegisteredParticipants($credentials);
        } catch (\Throwable $e) {
            Log::debug('LinaOpenxPixAutoService: participants failed', [
                'tenant_id' => $tenantId,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
        if ($list !== []) {
            Cache::put($cacheKey, $list, now()->addMinutes(5));
        }

        return $list;
    }

    public static function isPixAutoOrder(Order $order): bool
    {
        if ($order->payment_method === 'pix_auto') {
            return true;
        }
        $meta = is_array($order->metadata) ? $order->metadata : [];

        return ($meta['checkout_payment_method'] ?? null) === 'pix_auto'
            || trim((string) ($meta['linaopenx_pix_auto_consent_id'] ?? '')) !== '';
    }

    public static function intervalFromPlan(?string $interval): string
    {
        return match ($interval) {
            SubscriptionPlan::INTERVAL_WEEKLY => 'SEMANAL',
            SubscriptionPlan::INTERVAL_QUARTERLY => 'TRIMESTRAL',
            SubscriptionPlan::INTERVAL_SEMI_ANNUAL => 'SEMESTRAL',
            SubscriptionPlan::INTERVAL_ANNUAL => 'ANUAL',
            default => 'MENSAL',
        };
    }

    public function signedReturnUrl(Order $order): string
    {
        return URL::temporarySignedRoute(
            'checkout.lina.return',
            now()->addHours(24),
            ['order' => $order->id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function credentialsForOrder(Order $order): ?array
    {
        return $this->credentialsForTenant($order->tenant_id, $order);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function credentialsForTenant(?int $tenantId, ?Order $order = null): ?array
    {
        if ($order !== null) {
            $resolved = GatewayPaymentCredentials::resolve($order->tenant_id, self::GATEWAY_SLUG, $order);
            if ($resolved !== null && $resolved !== []) {
                return $resolved;
            }
        }

        $credential = GatewayCredential::resolveForPayment($tenantId, self::GATEWAY_SLUG);
        if ($credential === null) {
            return null;
        }
        $credentials = $credential->getDecryptedCredentials();

        return is_array($credentials) && $credentials !== [] ? $credentials : null;
    }

    private function syncConsentAndMaybeChargeFirst(Order $order): void
    {
        $credentials = $this->credentialsForOrder($order);
        if ($credentials === null) {
            return;
        }

        $requestId = trim((string) ($order->gateway_id ?? ''));
        $meta = is_array($order->metadata) ? $order->metadata : [];
        if ($requestId === '') {
            $requestId = trim((string) ($meta['lina_payment_request_id'] ?? ''));
        }
        if ($requestId === '') {
            return;
        }

        try {
            $result = $this->client->getAutomaticPaymentRequest($credentials, $requestId);
        } catch (\Throwable $e) {
            Log::debug('LinaOpenxPixAutoService: sync request failed', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $consentId = trim((string) ($result['consent_id'] ?? ''));
        if ($consentId !== '') {
            $meta['linaopenx_pix_auto_consent_id'] = $consentId;
        }
        if (($result['consent_status'] ?? null) !== null) {
            $meta['linaopenx_pix_auto_consent_status'] = $result['consent_status'];
        }

        $payments = $result['payments'] ?? [];
        if (is_array($payments) && $payments !== []) {
            $firstId = $this->firstPaymentId($payments);
            if ($firstId !== '') {
                $meta['linaopenx_pix_auto_recurring_payment_id'] = $firstId;
            }
            $order->update(['metadata' => $meta]);

            return;
        }

        $consentStatus = strtoupper(trim((string) ($result['consent_status'] ?? '')));
        $alreadyPosted = ! empty($meta['linaopenx_pix_auto_first_payment_posted']);
        if ($consentStatus !== 'AUTHORISED' || $alreadyPosted) {
            $order->update(['metadata' => $meta]);

            return;
        }

        try {
            $posted = $this->postRecurringPaymentForOrder($order, $credentials, $consentId !== '' ? $consentId : (string) ($meta['linaopenx_pix_auto_consent_id'] ?? ''));
            $meta['linaopenx_pix_auto_first_payment_posted'] = true;
            $meta['linaopenx_pix_auto_first_payment_posted_at'] = now()->toIso8601String();
            if ($posted !== '') {
                $meta['linaopenx_pix_auto_recurring_payment_id'] = $posted;
            }
        } catch (\Throwable $e) {
            Log::info('LinaOpenxPixAutoService: first recurring-payments skipped', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }

        $order->update(['metadata' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function postRecurringPaymentForOrder(Order $order, array $credentials, string $consentId, ?string $date = null): string
    {
        $consentId = trim($consentId);
        if ($consentId === '') {
            throw new \RuntimeException('Lina OpenX: consentId ausente para o débito.');
        }
        $creditor = $this->client->creditorPayload($credentials);
        if ($creditor === null) {
            throw new \RuntimeException('Lina OpenX: credor incompleto para o débito.');
        }

        $payDate = $date ?: now()->addDay()->toDateString();
        if ($payDate <= now()->toDateString()) {
            $payDate = now()->addDay()->toDateString();
        }

        $body = [
            'recurringConsentId' => $consentId,
            'payment' => [
                'amount' => $this->formatAmount((float) $order->amount),
                'currency' => 'BRL',
            ],
            'date' => $payDate,
            'creditorAccount' => [
                'ispb' => $creditor['accountIspb'],
                'issuer' => $creditor['accountIssuer'],
                'number' => $creditor['accountNumber'],
                'accountType' => $creditor['accountType'],
            ],
            'authorisationFlow' => 'HYBRID_FLOW',
            'document' => $this->documentBlock($creditor['cpfCnpj']),
            'localInstrument' => 'AUTO',
        ];

        $created = $this->client->createRecurringPayment($credentials, $body);

        return $created['recurring_payment_id'];
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    private function firstPaymentId(array $payments): string
    {
        foreach ($payments as $row) {
            foreach (['recurringPaymentId', 'recurring_payment_id', 'paymentId', 'id'] as $key) {
                $v = $row[$key] ?? null;
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }

        return '';
    }

    private function contractIdForOrder(Order $order): string
    {
        return 'STK'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{identification: string, rel: string}
     */
    private function documentBlock(string $digits): array
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';

        return [
            'identification' => $digits,
            'rel' => strlen($digits) === 14 ? 'CNPJ' : 'CPF',
        ];
    }

    private function formatAmount(float $amount): string
    {
        return number_format(max(0.01, round($amount, 2)), 2, '.', '');
    }

    private function dispatchPaid(Order $order): void
    {
        $txId = trim((string) ($order->gateway_id ?? ''));
        if ($txId === '') {
            $meta = is_array($order->metadata) ? $order->metadata : [];
            $txId = trim((string) ($meta['linaopenx_pix_auto_recurring_payment_id'] ?? $meta['linaopenx_pix_auto_consent_id'] ?? ''));
        }
        if ($txId === '') {
            return;
        }

        ProcessPaymentWebhook::dispatchSync(
            self::GATEWAY_SLUG,
            $txId,
            'order.paid',
            'paid',
            [
                'source' => 'linaopenx_pix_auto_return_or_poll',
                'webhook_source' => 'linaopenx_api_reconfirm',
            ]
        );
    }
}
