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
        // O firstPayment liquida na autorização. A âncora do ciclo não pode cair no mesmo dia.
        $firstDate = now()->toDateString();
        $referenceStartDate = now()->addDay()->startOfDay()->utc()->format('Y-m-d\TH:i:s\Z');
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
            'expirationDateTime' => self::consentExpiration($order),
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
        // businessEntity é a empresa do pagador. Conta PF não envia o campo.
        // O CNPJ da plataforma fica só em creditors.
        if (strlen($payerDocument) === 14) {
            $recurringConsent['businessEntity'] = [
                'document' => $this->documentBlock($payerDocument),
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
            if (($result['status'] ?? '') === 'paid') {
                return 'paid';
            }

            $consentId = trim((string) ($meta['linaopenx_pix_auto_consent_id'] ?? $result['consent_id'] ?? ''));
            if ($consentId !== '') {
                $consent = $this->client->getRecurringConsent($credentials, $consentId);
                if (($consent['status'] ?? '') === 'paid') {
                    return 'paid';
                }
                $consentPaymentId = trim((string) ($consent['payment_id'] ?? ''));
                if ($consentPaymentId !== '' && $consentPaymentId !== $recurringPaymentId) {
                    $payment = $this->client->getRecurringPayment($credentials, $consentPaymentId);
                    if (($payment['status'] ?? '') === 'paid') {
                        return 'paid';
                    }
                }
                $consentPay = trim((string) ($consent['payment_status'] ?? ''));
                $knownPay = $consentPay !== '' ? $consentPay : trim((string) ($result['payment_status'] ?? ''));
                if (! $this->paymentStatusBlocksFirstCharge($knownPay)
                    && $this->immediateFirstChargeAccepted($order, [
                        'consent_status' => $consent['consent_status'] ?? $result['consent_status'] ?? null,
                        'payment_status' => $knownPay,
                    ], true)) {
                    return 'paid';
                }
            } elseif ($this->immediateFirstChargeAccepted($order, $result, true)) {
                return 'paid';
            }

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

    /**
     * Fim da autorização na Lina. Sem prazo no produto, mantém 120 meses.
     */
    public static function consentExpiration(Order $order): string
    {
        $order->loadMissing('product');
        $months = (int) ($order->product?->subscription_recurrence_months ?? 0);
        if ($months < 1) {
            $months = 120;
        }
        $months = min(120, $months);

        return now()->addMonths($months)->utc()->format('Y-m-d\TH:i:s\Z');
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

        $raw = is_array($result['raw'] ?? null) ? $result['raw'] : [];
        $signal = $this->client->paymentSignal($raw);
        $this->rememberPaymentId($meta, (string) ($signal['payment_id'] ?? ''));
        if (($result['status'] ?? '') === 'paid' || $this->client->isSettledPaymentStatus($signal['status'] ?? null)) {
            $meta['linaopenx_pix_auto_first_payment_settled'] = true;
            $order->update(['metadata' => $meta]);

            return;
        }

        if ($this->immediateFirstChargeAccepted($order, $result)) {
            $this->markImmediateFirstChargeSettled($order, $meta, $result);

            return;
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

        if ($this->client->isInflightPaymentStatus($signal['status'] ?? null)) {
            $order->update(['metadata' => $meta]);

            return;
        }

        if ($consentId !== '') {
            try {
                $consent = $this->client->getRecurringConsent($credentials, $consentId);
            } catch (\Throwable $e) {
                Log::debug('LinaOpenxPixAutoService: consent lookup failed', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
                $consent = null;
            }
            if (is_array($consent)) {
                $this->rememberPaymentId($meta, (string) ($consent['payment_id'] ?? ''));
                if (($consent['status'] ?? '') === 'paid' || $this->client->isSettledPaymentStatus($consent['payment_status'] ?? null)) {
                    $meta['linaopenx_pix_auto_first_payment_settled'] = true;
                    $order->update(['metadata' => $meta]);

                    return;
                }
                if ($this->immediateFirstChargeAccepted($order, [
                    'consent_status' => $consent['consent_status'] ?? $result['consent_status'] ?? null,
                    'payment_status' => $consent['payment_status'] ?? null,
                ])) {
                    $this->markImmediateFirstChargeSettled($order, $meta, $result);

                    return;
                }
                if ($this->client->isInflightPaymentStatus($consent['payment_status'] ?? null)) {
                    $order->update(['metadata' => $meta]);

                    return;
                }
            }
        }

        $knownPay = trim((string) ($result['payment_status'] ?? ''));
        if (! $this->paymentStatusBlocksFirstCharge($knownPay)
            && $this->immediateFirstChargeAccepted($order, $result, true)) {
            $this->markImmediateFirstChargeSettled($order, $meta, $result);

            return;
        }

        $consentStatus = strtoupper(trim((string) ($result['consent_status'] ?? '')));
        $alreadyPosted = ! empty($meta['linaopenx_pix_auto_first_payment_posted']);
        if ($consentStatus !== 'AUTHORISED' || $alreadyPosted) {
            $order->update(['metadata' => $meta]);

            return;
        }

        try {
            $posted = $this->postRecurringPaymentForOrder(
                $order,
                $credentials,
                $consentId !== '' ? $consentId : (string) ($meta['linaopenx_pix_auto_consent_id'] ?? ''),
                now()->toDateString(),
                true
            );
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
    public function postRecurringPaymentForOrder(Order $order, array $credentials, string $consentId, ?string $date = null, bool $keepDate = false): string
    {
        $consentId = trim($consentId);
        if ($consentId === '') {
            throw new \RuntimeException('Lina OpenX: consentId ausente para o débito.');
        }
        $creditor = $this->client->creditorPayload($credentials);
        if ($creditor === null) {
            throw new \RuntimeException('Lina OpenX: credor incompleto para o débito.');
        }

        $payDate = trim((string) $date);
        if ($payDate === '') {
            $payDate = $keepDate ? now()->toDateString() : now()->addDay()->toDateString();
        }
        if (! $keepDate && $payDate <= now()->toDateString()) {
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
     * O firstPayment do contrato liquida quando o banco autoriza.
     * A Lina pode continuar em EM_PROCESSAMENTO e nunca virar PAGO.
     * Renovação não usa este atalho: o consentimento segue AUTHORISED o ciclo inteiro.
     *
     * @param  array<string, mixed>  $result
     */
    private function immediateFirstChargeAccepted(Order $order, array $result, bool $allowWithoutPaymentStatus = false): bool
    {
        if ($order->is_renewal) {
            return false;
        }

        $consentStatus = strtoupper(trim((string) ($result['consent_status'] ?? '')));
        if (! in_array($consentStatus, ['AUTHORISED', 'CONSUMED', 'CONSUMIDO'], true)) {
            return false;
        }

        $pay = strtoupper(trim((string) ($result['payment_status'] ?? '')));
        if ($pay === '') {
            return $allowWithoutPaymentStatus;
        }
        if ($this->client->isSettledPaymentStatus($pay)) {
            return true;
        }

        return in_array($pay, ['RECEBIDO', 'EM_PROCESSAMENTO', 'PROCESSING'], true);
    }

    private function paymentStatusBlocksFirstCharge(?string $status): bool
    {
        $pay = strtoupper(trim((string) $status));
        if ($pay === '' || $this->client->isSettledPaymentStatus($pay)) {
            return false;
        }

        return ! in_array($pay, ['RECEBIDO', 'EM_PROCESSAMENTO', 'PROCESSING'], true);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $result
     */
    private function markImmediateFirstChargeSettled(Order $order, array &$meta, array $result): void
    {
        if (empty($meta['linaopenx_pix_auto_first_payment_settled'])) {
            Log::info('LinaOpenxPixAutoService: primeiro débito aceito na autorização', [
                'order_id' => $order->id,
                'consent_status' => $result['consent_status'] ?? null,
                'payment_status' => $result['payment_status'] ?? null,
            ]);
        }
        $meta['linaopenx_pix_auto_first_payment_settled'] = true;
        $order->update(['metadata' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function rememberPaymentId(array &$meta, string $paymentId): void
    {
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            return;
        }
        if (trim((string) ($meta['linaopenx_pix_auto_recurring_payment_id'] ?? '')) !== '') {
            return;
        }
        $meta['linaopenx_pix_auto_recurring_payment_id'] = $paymentId;
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
