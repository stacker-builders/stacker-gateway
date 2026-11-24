<?php

namespace App\Gateways\Asaas;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AsaasDriver implements GatewayDriver
{
    private const BASE_URL_PRODUCTION = 'https://api.asaas.com/v3';

    private const BASE_URL_SANDBOX = 'https://api-sandbox.asaas.com/v3';

    private function getBaseUrl(array $credentials): string
    {
        $sandbox = isset($credentials['sandbox']) && filter_var($credentials['sandbox'], FILTER_VALIDATE_BOOLEAN);

        return $sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PRODUCTION;
    }

    private function getApiKey(array $credentials): ?string
    {
        $key = trim($credentials['api_key'] ?? '');
        if ($key === '') {
            return null;
        }
        return $key;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function http(array $credentials, int $timeout = 20): \Illuminate\Http\Client\PendingRequest
    {
        $apiKey = $this->getApiKey($credentials);
        if ($apiKey === null) {
            throw new \RuntimeException('Asaas: API Key não configurada.');
        }
        return Http::withHeaders([
            'access_token' => $apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => config('app.name', 'Checkout'),
        ])->acceptJson()->timeout($timeout)->withOptions(['connect_timeout' => min(60, max(2, (int) ceil($timeout / 4)))]);
    }

    /**
     * Normaliza telefone para o formato Asaas: apenas dígitos, 11 caracteres (DDD + 9 + 8 dígitos) para celular.
     * Remove código do país 55, converte fixo (10 dígitos) em celular (insere 9 após DDD) e retorna vazio se inválido.
     */
    private function normalizePhoneForAsaas(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) > 11 && substr($digits, 0, 2) === '55') {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }
        if (strlen($digits) < 10) {
            return '';
        }
        // Celular no Brasil: 11 dígitos = DDD (2) + 9 + 8 dígitos. Se veio com 10 (fixo), insere 9 após DDD.
        if (strlen($digits) === 10) {
            $digits = substr($digits, 0, 2) . '9' . substr($digits, 2);
        }
        return $digits;
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string, phone?: string, address?: array}  $consumer
     */
    private function ensureCustomer(array $credentials, array $consumer, string $externalId): string
    {
        $baseUrl = $this->getBaseUrl($credentials);
        $document = preg_replace('/\D/', '', $consumer['document'] ?? '') ?? '';
        if (strlen($document) !== 11 && strlen($document) !== 14) {
            throw new \RuntimeException('Asaas: CPF ou CNPJ válido é obrigatório.');
        }

        $existingId = $this->findCustomerIdByDocument($credentials, $baseUrl, $document);
        if ($existingId !== null) {
            return $existingId;
        }

        $mobilePhone = $this->normalizePhoneForAsaas($consumer['phone'] ?? '');
        $body = [
            'name' => trim($consumer['name'] ?? '') ?: 'Cliente',
            'cpfCnpj' => $document,
            'email' => $consumer['email'] ?? '',
            'externalReference' => 'order_' . $externalId,
        ];
        if ($mobilePhone !== '') {
            $body['mobilePhone'] = $mobilePhone;
        }
        $address = $consumer['address'] ?? null;
        if (is_array($address)) {
            if (! empty($address['zip_code'])) {
                $body['postalCode'] = preg_replace('/\D/', '', $address['zip_code']);
                if (strlen($body['postalCode']) > 8) {
                    $body['postalCode'] = substr($body['postalCode'], 0, 8);
                }
            }
            if (! empty($address['street_name'])) {
                $body['address'] = $address['street_name'];
            }
            if (isset($address['street_number'])) {
                $body['addressNumber'] = (string) $address['street_number'];
            }
            if (! empty($address['neighborhood'])) {
                $body['province'] = $address['neighborhood'];
            }
        }
        $response = $this->http($credentials)->post($baseUrl . '/customers', $body);
        if (! $response->successful()) {
            $err = $response->json('errors', []);
            $msg = is_array($err) && isset($err[0]['description']) ? $err[0]['description'] : $response->json('error', 'Não foi possível criar o cliente.');
            Log::warning('AsaasDriver ensureCustomer failed', ['status' => $response->status(), 'order_id' => $externalId]);
            throw new \RuntimeException('Asaas: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        $data = $response->json();
        $id = $data['id'] ?? null;
        if (empty($id)) {
            throw new \RuntimeException('Asaas: resposta sem ID do cliente.');
        }

        return (string) $id;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function findCustomerIdByDocument(array $credentials, string $baseUrl, string $document): ?string
    {
        try {
            $response = $this->http($credentials)->get($baseUrl . '/customers', [
                'cpfCnpj' => $document,
                'limit' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::debug('AsaasDriver findCustomerIdByDocument error', ['message' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $id = $response->json('data.0.id');
        if (! is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    /**
     * Mapeia status da cobrança Asaas para o status interno do checkout.
     */
    public static function mapPaymentStatus(?string $status, bool $deleted = false): ?string
    {
        if ($deleted) {
            return 'cancelled';
        }
        if (! is_string($status) || $status === '') {
            return null;
        }
        $s = strtoupper($status);
        if (in_array($s, ['CONFIRMED', 'RECEIVED', 'RECEIVED_IN_CASH'], true)) {
            return 'paid';
        }
        if (in_array($s, ['REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE'], true)) {
            return 'cancelled';
        }
        if (in_array($s, ['PENDING', 'OVERDUE', 'AWAITING_RISK_ANALYSIS', 'AWAITING_CHARGEBACK_REVERSAL', 'DUNNING_REQUESTED', 'DUNNING_RECEIVED'], true)) {
            return 'pending';
        }

        return 'pending';
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function testConnection(array $credentials): bool
    {
        $apiKey = $this->getApiKey($credentials);
        if ($apiKey === null) {
            return false;
        }
        $baseUrl = $this->getBaseUrl($credentials);
        try {
            $response = $this->http($credentials)->get($baseUrl . '/customers', ['limit' => 1]);
            if ($response->successful()) {
                return true;
            }
            if ($response->status() === 401) {
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::debug('AsaasDriver testConnection error', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{transaction_id: string, qrcode?: string, copy_paste?: string, raw?: array}
     */
    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        $baseUrl = $this->getBaseUrl($credentials);
        $customerId = $this->ensureCustomer($credentials, $consumer, $externalId);
        $dueDate = now()->addDays(3)->format('Y-m-d');
        $body = [
            'customer' => $customerId,
            'billingType' => 'PIX',
            'value' => round($amount, 2),
            'dueDate' => $dueDate,
            'externalReference' => $externalId,
        ];
        $response = $this->http($credentials)->post($baseUrl . '/lean/payments', $body);
        if (! $response->successful()) {
            $err = $response->json('errors', []);
            $msg = is_array($err) && isset($err[0]['description']) ? $err[0]['description'] : 'Não foi possível gerar o PIX.';
            Log::warning('AsaasDriver createPixPayment failed', ['status' => $response->status(), 'order_id' => $externalId]);
            throw new \RuntimeException('Asaas: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        $data = $response->json();
        $paymentId = $data['id'] ?? null;
        if (empty($paymentId)) {
            throw new \RuntimeException('Asaas: resposta sem identificador do pagamento PIX.');
        }
        $qrResponse = $this->http($credentials)->get($baseUrl . '/payments/' . $paymentId . '/pixQrCode');
        $qrcode = null;
        $copyPaste = null;
        if ($qrResponse->successful()) {
            $qrData = $qrResponse->json();
            $qrcode = $qrData['encodedImage'] ?? null;
            $copyPaste = $qrData['payload'] ?? null;
        }
        return [
            'transaction_id' => (string) $paymentId,
            'qrcode' => $qrcode,
            'copy_paste' => $copyPaste,
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string}  $consumer
     * @return array{transaction_id: string, amount: float, expire_at: string, barcode: string, pdf_url: string, raw?: array}
     */
    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        $baseUrl = $this->getBaseUrl($credentials);
        $customerId = $this->ensureCustomer($credentials, $consumer, $externalId);
        $dueDate = now()->addDays(5)->format('Y-m-d');
        $body = [
            'customer' => $customerId,
            'billingType' => 'BOLETO',
            'value' => round($amount, 2),
            'dueDate' => $dueDate,
            'externalReference' => $externalId,
        ];
        $response = $this->http($credentials)->post($baseUrl . '/lean/payments', $body);
        if (! $response->successful()) {
            $err = $response->json('errors', []);
            $msg = is_array($err) && isset($err[0]['description']) ? $err[0]['description'] : 'Não foi possível gerar o boleto.';
            Log::warning('AsaasDriver createBoletoPayment failed', ['status' => $response->status(), 'order_id' => $externalId]);
            throw new \RuntimeException('Asaas: ' . (is_string($msg) ? $msg : json_encode($msg)));
        }
        $data = $response->json();
        $paymentId = $data['id'] ?? null;
        if (empty($paymentId)) {
            throw new \RuntimeException('Asaas: resposta sem identificador do boleto.');
        }
        $pdfUrl = is_string($data['bankSlipUrl'] ?? null) ? $data['bankSlipUrl'] : '';
        $dueDateStr = $data['dueDate'] ?? $dueDate;
        if (is_string($dueDateStr) && strlen($dueDateStr) >= 10) {
            $dueDateStr = substr($dueDateStr, 0, 10);
        }
        if ($pdfUrl === '') {
            $fullPayment = $this->http($credentials)->get($baseUrl . '/payments/' . $paymentId);
            if ($fullPayment->successful()) {
                $fullData = $fullPayment->json();
                $pdfUrl = is_string($fullData['bankSlipUrl'] ?? null)
                    ? $fullData['bankSlipUrl']
                    : (is_string($fullData['invoiceUrl'] ?? null) ? $fullData['invoiceUrl'] : '');
            }
        }
        $barcode = '';
        $idFieldResponse = $this->http($credentials)->get($baseUrl . '/payments/' . $paymentId . '/identificationField');
        if ($idFieldResponse->successful()) {
            $idFieldData = $idFieldResponse->json();
            $barcode = $idFieldData['identificationField'] ?? $idFieldData['barCode'] ?? '';
        }
        return [
            'transaction_id' => (string) $paymentId,
            'amount' => (float) ($data['value'] ?? $amount),
            'expire_at' => $dueDateStr,
            'barcode' => $barcode,
            'pdf_url' => $pdfUrl,
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     * @param  array{name: string, document: string, email: string, address?: array}  $consumer
     * @param  array{payment_token?: string, card_mask?: string, installments?: int, card_holder_name?: string, card_number?: string, card_expiry_month?: string, card_expiry_year?: string, card_ccv?: string}  $card
     * @return array{transaction_id: string, status?: string}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $baseUrl = $this->getBaseUrl($credentials);
        $customerId = $this->ensureCustomer($credentials, $consumer, $externalId);
        $address = $consumer['address'] ?? null;
        if (! is_array($address) || empty($address['zip_code']) || ! isset($address['street_number'])) {
            throw new \RuntimeException('Asaas: endereço completo é obrigatório para pagamento com cartão.');
        }
        $document = preg_replace('/\D/', '', $consumer['document'] ?? '') ?? '';
        if (strlen($document) !== 11 && strlen($document) !== 14) {
            throw new \RuntimeException('Asaas: CPF ou CNPJ válido é obrigatório.');
        }
        $postalCode = preg_replace('/\D/', '', $address['zip_code'] ?? '');
        if (strlen($postalCode) > 8) {
            $postalCode = substr($postalCode, 0, 8);
        }
        $phoneNormalized = $this->normalizePhoneForAsaas($consumer['phone'] ?? '');
        $holderInfo = [
            'name' => trim($consumer['name'] ?? '') ?: 'Titular',
            'email' => $consumer['email'] ?? '',
            'cpfCnpj' => $document,
            'postalCode' => $postalCode,
            'addressNumber' => (string) ($address['street_number'] ?? ''),
            'addressComplement' => trim((string) ($address['complement'] ?? '')) ?: null,
            'phone' => $phoneNormalized !== '' ? $phoneNormalized : null,
            'mobilePhone' => $phoneNormalized !== '' ? $phoneNormalized : null,
        ];
        $expYear = (string) ($card['card_expiry_year'] ?? '');
        if (strlen($expYear) === 2 && is_numeric($expYear)) {
            $expYear = '20' . $expYear;
        }
        $creditCard = [
            'holderName' => trim((string) ($card['card_holder_name'] ?? '')),
            'number' => preg_replace('/\D/', '', (string) ($card['card_number'] ?? '')),
            'expiryMonth' => str_pad((string) ($card['card_expiry_month'] ?? ''), 2, '0', STR_PAD_LEFT),
            'expiryYear' => $expYear,
            'ccv' => (string) ($card['card_ccv'] ?? ''),
        ];
        if ($creditCard['number'] === '' || $creditCard['ccv'] === '') {
            throw new \RuntimeException('Asaas: dados do cartão incompletos.');
        }
        $installments = isset($card['installments']) ? max(1, min(12, (int) $card['installments'])) : 1;
        $body = [
            'customer' => $customerId,
            'billingType' => 'CREDIT_CARD',
            'dueDate' => now()->format('Y-m-d'),
            'externalReference' => $externalId,
            'creditCard' => $creditCard,
            'creditCardHolderInfo' => $holderInfo,
            'remoteIp' => request()->ip() ?? '127.0.0.1',
        ];
        if ($installments === 1) {
            $body['value'] = round($amount, 2);
        } else {
            $body['installmentCount'] = $installments;
            $body['installmentValue'] = round($amount / $installments, 2);
        }
        $response = $this->http($credentials, 65)->post($baseUrl . '/lean/payments', $body);
        if (! $response->successful()) {
            $err = $response->json('errors', []);
            $desc = is_array($err) && isset($err[0]['description']) ? $err[0]['description'] : '';
            Log::warning('AsaasDriver createCardPayment failed', [
                'status' => $response->status(),
                'order_id' => $externalId,
                'description' => $desc,
            ]);
            $userMsg = 'Asaas: não foi possível processar o cartão. Verifique os dados e tente novamente.';
            if (stripos($desc, 'autoriz') !== false || stripos($desc, 'recusad') !== false) {
                $userMsg = 'Cartão recusado. Verifique os dados ou tente outro cartão.';
            }
            throw new \RuntimeException($userMsg);
        }
        $data = $response->json();
        $paymentId = $data['id'] ?? null;
        if (empty($paymentId)) {
            throw new \RuntimeException('Asaas: resposta sem identificador do pagamento.');
        }
        $challengeUrl = $data['threeDSecureChallengeUrl'] ?? null;
        if (is_string($challengeUrl) && trim($challengeUrl) !== '') {
            return [
                'transaction_id' => (string) $paymentId,
                'status' => 'requires_action',
                'redirect_url' => trim($challengeUrl),
            ];
        }
        $mapped = self::mapPaymentStatus(is_string($data['status'] ?? null) ? $data['status'] : null) ?? 'pending';

        return [
            'transaction_id' => (string) $paymentId,
            'status' => $mapped === 'cancelled' ? 'rejected' : $mapped,
        ];
    }

    /**
     * Saldo disponível da conta. GET /v3/finance/balance → { "balance": 5210.96 }.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function fetchAccountBalance(array $credentials): array
    {
        $response = $this->http($credentials, 8)->get($this->getBaseUrl($credentials).'/finance/balance');
        if (! $response->successful()) {
            throw new \RuntimeException('Asaas: falha ao consultar saldo (HTTP '.$response->status().').');
        }

        $data = $response->json();
        if (! is_array($data) || ! is_numeric($data['balance'] ?? null)) {
            throw new \RuntimeException('Asaas: resposta de saldo inválida.');
        }

        return $data;
    }

    /**
     * Jornada 3: QR imediato + autorização. As cobranças seguintes ficam no modo MANUAL.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array{name?: string, document?: string, email?: string, phone?: string, address?: array}  $consumer
     * @return array{authorization_id: string, customer_id: string, copy_paste: ?string, qrcode: ?string, status: string, payment_id: ?string}
     */
    public function createPixAutomaticAuthorization(
        array $credentials,
        array $consumer,
        string $externalId,
        float $amount,
        string $frequency,
        string $startDate,
        string $description
    ): array {
        $baseUrl = $this->getBaseUrl($credentials);
        $customerId = $this->ensureCustomer($credentials, $consumer, $externalId);
        $contractId = substr(preg_replace('/[^A-Za-z0-9\-]/', '', 'A'.$externalId) ?? '', 0, 35);
        if ($contractId === '') {
            $contractId = substr('A'.$externalId, 0, 35);
        }
        $label = mb_substr(trim($description) !== '' ? trim($description) : 'Assinatura', 0, 35);
        $body = [
            'frequency' => $frequency,
            'contractId' => $contractId,
            'startDate' => $startDate,
            'customerId' => $customerId,
            'value' => round($amount, 2),
            'description' => $label,
            'paymentCreationMode' => 'MANUAL',
            'retryPolicy' => 'ALLOW_THREE_IN_SEVEN_DAYS',
            'immediateQrCode' => [
                'expirationSeconds' => 86400,
                'originalValue' => round($amount, 2),
                'description' => mb_substr('Pedido '.$externalId, 0, 35),
            ],
        ];
        $response = $this->http($credentials)->post($baseUrl.'/pix/automatic/authorizations', $body);
        if (! $response->successful()) {
            throw new \RuntimeException('Asaas: '.$this->errorDescription($response, 'Não foi possível criar o PIX automático.'));
        }
        $data = $response->json();
        $authorizationId = trim((string) ($data['id'] ?? ''));
        if ($authorizationId === '') {
            throw new \RuntimeException('Asaas: resposta sem identificador da autorização.');
        }

        return [
            'authorization_id' => $authorizationId,
            'customer_id' => (string) ($data['customerId'] ?? $customerId),
            'copy_paste' => is_string($data['payload'] ?? null) ? $data['payload'] : null,
            'qrcode' => is_string($data['encodedImage'] ?? null) ? $data['encodedImage'] : null,
            'status' => strtoupper((string) ($data['status'] ?? 'CREATED')),
            'payment_id' => $this->findImmediatePixPaymentId($credentials, $customerId, $amount),
        ];
    }

    /**
     * Cobrança do ciclo seguinte, vinculada a uma autorização ACTIVE.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function createPixAutomaticPayment(
        array $credentials,
        string $customerId,
        string $authorizationId,
        float $amount,
        string $dueDate,
        string $externalId
    ): string {
        $baseUrl = $this->getBaseUrl($credentials);
        $response = $this->http($credentials)->post($baseUrl.'/payments', [
            'customer' => $customerId,
            'billingType' => 'PIX',
            'value' => round($amount, 2),
            'dueDate' => $dueDate,
            'pixAutomaticAuthorizationId' => $authorizationId,
            'externalReference' => $externalId,
            'description' => mb_substr('Renovação '.$externalId, 0, 35),
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Asaas: '.$this->errorDescription($response, 'Não foi possível agendar o PIX automático.'));
        }
        $paymentId = trim((string) ($response->json('id') ?? ''));
        if ($paymentId === '') {
            throw new \RuntimeException('Asaas: resposta sem identificador da cobrança recorrente.');
        }

        return $paymentId;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function getPixAutomaticAuthorization(array $credentials, string $authorizationId): array
    {
        $response = $this->http($credentials, 8)->get(
            $this->getBaseUrl($credentials).'/pix/automatic/authorizations/'.rawurlencode($authorizationId)
        );
        if (! $response->successful()) {
            throw new \RuntimeException('Asaas: '.$this->errorDescription($response, 'Não foi possível consultar a autorização.'));
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('Asaas: resposta de autorização inválida.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function cancelPixAutomaticAuthorization(array $credentials, string $authorizationId): void
    {
        $response = $this->http($credentials, 8)->delete(
            $this->getBaseUrl($credentials).'/pix/automatic/authorizations/'.rawurlencode($authorizationId)
        );
        if (! $response->successful() && $response->status() !== 404) {
            throw new \RuntimeException('Asaas: '.$this->errorDescription($response, 'Não foi possível cancelar a autorização.'));
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function findImmediatePixPaymentId(array $credentials, string $customerId, float $amount): ?string
    {
        try {
            $response = $this->http($credentials, 8)->get($this->getBaseUrl($credentials).'/payments', [
                'customer' => $customerId,
                'billingType' => 'PIX',
                'limit' => 10,
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $rows = $response->json('data');
        if (! is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $value = $row['value'] ?? null;
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || ! is_numeric($value) || abs(((float) $value) - $amount) > 0.009) {
                continue;
            }
            $status = strtoupper((string) ($row['status'] ?? ''));
            if (in_array($status, ['PENDING', 'AWAITING_RISK_ANALYSIS', ''], true)) {
                return $id;
            }
        }

        return null;
    }

    private function errorDescription(\Illuminate\Http\Client\Response $response, string $fallback): string
    {
        $err = $response->json('errors');
        $msg = is_array($err) && isset($err[0]['description']) ? $err[0]['description'] : $fallback;

        return is_string($msg) && $msg !== '' ? $msg : $fallback;
    }

    /**
     * @param  array<string, string>  $credentials
     */
    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $baseUrl = $this->getBaseUrl($credentials);
        try {
            $response = $this->http($credentials)->get($baseUrl . '/lean/payments/' . $transactionId);
            if (! $response->successful()) {
                if ($response->status() === 404) {
                    return null;
                }
                Log::debug('AsaasDriver getTransactionStatus error', [
                    'transaction_id' => $transactionId,
                    'status' => $response->status(),
                ]);
                return null;
            }
            $data = $response->json();
            $deleted = ($data['deleted'] ?? false) === true;

            return self::mapPaymentStatus(is_string($data['status'] ?? null) ? $data['status'] : null, $deleted);
        } catch (\Throwable $e) {
            Log::debug('AsaasDriver getTransactionStatus error', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

}
