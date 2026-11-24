<?php

namespace App\Gateways\PagBank;

use App\Gateways\Contracts\GatewayDriver;
use App\Support\GatewayWebhookUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PagBank API de Pedidos: PIX, cartão (criptografado no browser) e boleto.
 *
 * @see https://developer.pagbank.com.br/reference/criar-pedido
 */
class PagBankDriver implements GatewayDriver
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    private function baseUrl(array $credentials): string
    {
        $sandbox = filter_var($credentials['sandbox'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $key = $sandbox ? 'sandbox_url' : 'base_url';
        $url = (string) config('services.pagbank.'.$key, $sandbox
            ? 'https://sandbox.api.pagseguro.com'
            : 'https://api.pagseguro.com');

        return rtrim($url, '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function token(array $credentials): string
    {
        return trim((string) ($credentials['token'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function http(array $credentials, int $timeout = 25): \Illuminate\Http\Client\PendingRequest
    {
        $token = $this->token($credentials);
        if ($token === '') {
            throw new \RuntimeException('PagBank: token de integração não configurado.');
        }

        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withHeaders([
                'User-Agent' => config('app.name', 'Checkout').'/PagBank',
            ])
            ->withOptions(['connect_timeout' => min(20, max(2, (int) ceil($timeout / 4)))]);
    }

    /**
     * @param  array{name: string, document: string, email: string, phone?: string}  $consumer
     * @return array<string, mixed>
     */
    private function buildCustomer(array $consumer): array
    {
        $name = trim((string) ($consumer['name'] ?? ''));
        if ($name === '') {
            $name = 'Cliente';
        }
        if (mb_strlen($name) > 120) {
            $name = mb_substr($name, 0, 120);
        }

        $email = trim((string) ($consumer['email'] ?? ''));
        if ($email === '' || ! str_contains($email, '@')) {
            throw new \RuntimeException('PagBank: e-mail do comprador é obrigatório.');
        }

        $document = preg_replace('/\D/', '', (string) ($consumer['document'] ?? '')) ?? '';
        if (! in_array(strlen($document), [11, 14], true)) {
            throw new \RuntimeException('PagBank: CPF ou CNPJ do comprador é obrigatório.');
        }

        return [
            'name' => $name,
            'email' => mb_substr($email, 0, 60),
            'tax_id' => $document,
            'phones' => [$this->phoneFromConsumer((string) ($consumer['phone'] ?? ''))],
        ];
    }

    /**
     * @return array{country: string, area: string, number: string, type: string}
     */
    private function phoneFromConsumer(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) < 10) {
            throw new \RuntimeException('PagBank: telefone do comprador é obrigatório.');
        }
        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }
        if (strlen($digits) === 10) {
            $digits = substr($digits, 0, 2).'9'.substr($digits, 2);
        }

        return [
            'country' => '55',
            'area' => substr($digits, 0, 2),
            'number' => substr($digits, 2),
            'type' => 'MOBILE',
        ];
    }

    /**
     * @param  array{name?: string, document?: string, email?: string, address?: array<string, mixed>}  $consumer
     * @return array<string, string>
     */
    private function requireShippingAddress(array $consumer): array
    {
        $address = $this->shippingAddress($consumer);
        if ($address === null) {
            throw new \RuntimeException('PagBank: endereço de cobrança completo é obrigatório.');
        }

        return $address;
    }

    /**
     * @param  array{address?: array<string, mixed>}  $consumer
     * @return array<string, string>|null
     */
    private function shippingAddress(array $consumer): ?array
    {
        $address = $consumer['address'] ?? null;
        if (! is_array($address)) {
            return null;
        }
        $zip = preg_replace('/\D/', '', (string) ($address['zip_code'] ?? '')) ?? '';
        $street = trim((string) ($address['street_name'] ?? ''));
        $number = trim((string) ($address['street_number'] ?? ''));
        $locality = trim((string) ($address['neighborhood'] ?? ''));
        $city = trim((string) ($address['city'] ?? ''));
        $uf = strtoupper(substr(trim((string) ($address['federal_unit'] ?? '')), 0, 2));
        if (strlen($zip) < 8 || $street === '' || $number === '' || $city === '' || strlen($uf) !== 2) {
            return null;
        }
        if ($locality === '') {
            $locality = 'Centro';
        }

        $out = [
            'street' => mb_substr($street, 0, 160),
            'number' => mb_substr($number, 0, 20),
            'locality' => mb_substr($locality, 0, 60),
            'city' => mb_substr($city, 0, 90),
            'region_code' => $uf,
            'country' => 'BRA',
            'postal_code' => substr($zip, 0, 8),
        ];
        $complement = trim((string) ($address['complement'] ?? ''));
        if ($complement !== '') {
            $out['complement'] = mb_substr($complement, 0, 40);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $shipping
     * @return array<string, string>
     */
    private function boletoHolderAddress(array $shipping): array
    {
        $address = [
            'street' => $shipping['street'],
            'number' => $shipping['number'],
            'postal_code' => $shipping['postal_code'],
            'locality' => $shipping['locality'],
            'city' => $shipping['city'],
            'region' => $shipping['region_code'],
            'region_code' => $shipping['region_code'],
            'country' => 'Brasil',
        ];
        if (! empty($shipping['complement'])) {
            $address['complement'] = $shipping['complement'];
        }

        return $address;
    }

    private function publicNotificationUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '' || ! filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return null;
        }
        if (! str_starts_with(strtolower($trimmed), 'https://')) {
            return null;
        }
        $host = strtolower((string) parse_url($trimmed, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $charge
     * @param  array<string, string>|null  $shipping
     * @return array<string, mixed>
     */
    private function createOrder(
        array $credentials,
        array $customer,
        array $charge,
        string $externalId,
        ?array $shipping,
        ?string $notificationUrl
    ): array {
        $amount = (int) ($charge['amount']['value'] ?? 0);
        if ($amount < 1) {
            throw new \RuntimeException('PagBank: valor inválido.');
        }

        $body = [
            'reference_id' => mb_substr($externalId, 0, 64),
            'customer' => $customer,
            'items' => [[
                'reference_id' => mb_substr('item-'.$externalId, 0, 64),
                'name' => mb_substr('Pedido '.$externalId, 0, 100),
                'quantity' => 1,
                'unit_amount' => $amount,
            ]],
            'charges' => [$charge],
        ];
        if ($shipping !== null) {
            $body['shipping'] = ['address' => $shipping];
        }
        $notify = $notificationUrl !== null ? $this->publicNotificationUrl($notificationUrl) : null;
        if ($notify !== null) {
            $body['notification_urls'] = [$notify];
        }

        $response = $this->http($credentials, 45)->post($this->baseUrl($credentials).'/orders', $body);
        if (! $response->successful()) {
            $this->throwApiError($response, $externalId);
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \RuntimeException('PagBank: resposta inválida.');
        }
        $charges = $data['charges'] ?? null;
        if (! is_array($charges) || ! isset($charges[0]) || ! is_array($charges[0])) {
            throw new \RuntimeException('PagBank: pedido sem cobrança.');
        }
        $created = $charges[0];
        $chargeId = $created['id'] ?? null;
        $orderId = $data['id'] ?? null;
        if (! is_string($chargeId) || $chargeId === '' || ! is_string($orderId) || $orderId === '') {
            throw new \RuntimeException('PagBank: resposta sem identificador da cobrança.');
        }

        return [
            'transaction_id' => $chargeId,
            'order_id' => $orderId,
            'charge' => $created,
            'order' => $data,
        ];
    }

    private function throwApiError(Response $response, string $externalId): void
    {
        $json = $response->json();
        $msg = 'Não foi possível processar o pagamento.';
        if (is_array($json)) {
            $errors = $json['error_messages'] ?? null;
            if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
                $description = trim((string) ($errors[0]['description'] ?? $errors[0]['message'] ?? ''));
                $parameter = trim((string) ($errors[0]['parameter_name'] ?? ''));
                if ($description !== '') {
                    $msg = $parameter !== '' ? $description.' ('.$parameter.')' : $description;
                }
            } elseif (isset($json['message']) && is_string($json['message']) && trim($json['message']) !== '') {
                $msg = trim($json['message']);
            }
        }
        Log::warning('PagBankDriver API error', [
            'order_id' => $externalId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        throw new \RuntimeException('PagBank: '.$msg);
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    public function interpretCharge(array $charge): string
    {
        $refunded = (int) ($charge['amount']['summary']['refunded'] ?? 0);
        if ($refunded > 0) {
            return 'refunded';
        }
        $status = strtoupper(trim((string) ($charge['status'] ?? '')));
        if ($status === 'PAID') {
            return 'paid';
        }
        if (in_array($status, ['DECLINED', 'CANCELED', 'CANCELLED'], true)) {
            return 'cancelled';
        }

        return 'pending';
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function declineMessage(array $charge): string
    {
        $response = $charge['payment_response'] ?? null;
        if (is_array($response)) {
            $message = trim((string) ($response['message'] ?? ''));
            if ($message !== '' && ! in_array(strtoupper($message), ['SUCESSO'], true)) {
                return $message;
            }
        }

        return 'Pagamento não autorizado.';
    }

    public function testConnection(array $credentials): bool
    {
        if ($this->token($credentials) === '') {
            return false;
        }
        try {
            $response = $this->http($credentials, 15)->get(
                $this->baseUrl($credentials).'/orders/ORDE_00000000-0000-0000-0000-000000000000'
            );
            $status = $response->status();

            return ! in_array($status, [401, 403], true) && $status < 500;
        } catch (\Throwable $e) {
            Log::debug('PagBankDriver testConnection', ['message' => $e->getMessage()]);

            return false;
        }
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl,
        array $options = []
    ): array {
        $cents = (int) round($amount * 100);
        if ($cents < 1) {
            throw new \RuntimeException('PagBank: valor inválido.');
        }
        $customer = $this->buildCustomer($consumer);
        $shipping = $this->shippingAddress($consumer);
        $charge = [
            'reference_id' => mb_substr('pix-'.$externalId, 0, 64),
            'description' => mb_substr('PIX '.$externalId, 0, 64),
            'amount' => [
                'value' => $cents,
                'currency' => 'BRL',
            ],
            'payment_method' => [
                'type' => 'PIX',
                'pix' => [
                    'expiration_date' => now()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'),
                ],
            ],
        ];

        $result = $this->createOrder($credentials, $customer, $charge, $externalId, $shipping, $postbackUrl);
        $created = $result['charge'];
        if ($this->interpretCharge($created) === 'cancelled') {
            throw new \RuntimeException('PagBank: '.$this->declineMessage($created));
        }

        $qr = is_array($created['qr_code'] ?? null) ? $created['qr_code'] : [];
        $copy = isset($qr['text']) && is_string($qr['text']) ? $qr['text'] : null;
        $image = $this->qrImageFromLinks(is_array($created['links'] ?? null) ? $created['links'] : []);
        if (($copy === null || $copy === '') && ($image === null || $image === '')) {
            throw new \RuntimeException('PagBank: PIX gerado sem código de pagamento.');
        }

        return [
            'transaction_id' => $result['transaction_id'],
            'qrcode' => $image,
            'copy_paste' => $copy,
            'metadata' => [
                'pagbank_order_id' => $result['order_id'],
                'pagbank_charge_id' => $result['transaction_id'],
            ],
            'raw' => $created,
        ];
    }

    /**
     * @param  array<int, mixed>  $links
     */
    private function qrImageFromLinks(array $links): ?string
    {
        $url = null;
        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }
            if (($link['rel'] ?? '') === 'QRCODE.PNG' && is_string($link['href'] ?? null) && $link['href'] !== '') {
                $url = $link['href'];
                break;
            }
        }
        if ($url === null) {
            return null;
        }
        try {
            $bin = Http::timeout(15)->get($url)->body();
            if ($bin === '') {
                return null;
            }

            return base64_encode($bin);
        } catch (\Throwable) {
            return null;
        }
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $cents = (int) round($amount * 100);
        if ($cents < 1) {
            throw new \RuntimeException('PagBank: valor inválido.');
        }
        $encrypted = $this->encryptedCard($card);
        $installments = isset($card['installments']) ? max(1, min(12, (int) $card['installments'])) : 1;
        $customer = $this->buildCustomer($consumer);
        $shipping = $this->requireShippingAddress($consumer);
        $holderName = trim((string) ($customer['name'] ?? 'Cliente'));
        $holderTax = (string) ($customer['tax_id'] ?? '');

        $charge = [
            'reference_id' => mb_substr('card-'.$externalId, 0, 64),
            'description' => mb_substr('Cartão '.$externalId, 0, 64),
            'amount' => [
                'value' => $cents,
                'currency' => 'BRL',
            ],
            'payment_method' => [
                'type' => 'CREDIT_CARD',
                'installments' => $installments,
                'capture' => true,
                'card' => [
                    'encrypted' => $encrypted,
                    'store' => false,
                ],
                'holder' => [
                    'name' => $holderName,
                    'tax_id' => $holderTax,
                ],
            ],
        ];

        $result = $this->createOrder(
            $credentials,
            $customer,
            $charge,
            $externalId,
            $shipping,
            GatewayWebhookUrl::forGateway('pagbank')
        );
        $created = $result['charge'];
        $outcome = $this->interpretCharge($created);
        if ($outcome === 'cancelled' || $outcome === 'refunded') {
            throw new \RuntimeException('PagBank: '.$this->declineMessage($created));
        }

        return [
            'transaction_id' => $result['transaction_id'],
            'status' => $outcome === 'paid' ? 'paid' : 'pending',
            'metadata' => [
                'pagbank_order_id' => $result['order_id'],
                'pagbank_charge_id' => $result['transaction_id'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $card
     */
    private function encryptedCard(array $card): string
    {
        $tokenRaw = $card['payment_token'] ?? '';
        if (! is_string($tokenRaw) || trim($tokenRaw) === '') {
            throw new \RuntimeException('PagBank: cartão criptografado ausente. Preencha novamente.');
        }
        $decoded = json_decode($tokenRaw, true);
        $encrypted = '';
        if (is_array($decoded)) {
            $encrypted = trim((string) ($decoded['encrypted_card'] ?? $decoded['encrypted'] ?? ''));
        } else {
            $encrypted = trim($tokenRaw);
        }
        if ($encrypted === '' || strlen($encrypted) < 20) {
            throw new \RuntimeException('PagBank: cartão criptografado inválido. Preencha novamente.');
        }

        return $encrypted;
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        $cents = (int) round($amount * 100);
        if ($cents < 20) {
            throw new \RuntimeException('PagBank: boleto exige valor mínimo de R$ 0,20.');
        }
        $customer = $this->buildCustomer($consumer);
        $shipping = $this->requireShippingAddress($consumer);
        $dueDate = now()->addDays(5)->toDateString();
        $holderName = mb_substr(trim((string) ($customer['name'] ?? 'Cliente')), 0, 50);

        $charge = [
            'reference_id' => mb_substr('boleto-'.$externalId, 0, 64),
            'description' => mb_substr('Boleto '.$externalId, 0, 64),
            'amount' => [
                'value' => $cents,
                'currency' => 'BRL',
            ],
            'payment_method' => [
                'type' => 'BOLETO',
                'boleto' => [
                    'template' => 'proposta',
                    'due_date' => $dueDate,
                    'days_until_expiration' => '5',
                    'instruction_lines' => [
                        'line_1' => 'Pagamento ate a data de vencimento',
                        'line_2' => mb_substr('Pedido '.$externalId, 0, 75),
                    ],
                    'holder' => [
                        'name' => $holderName,
                        'tax_id' => (string) ($customer['tax_id'] ?? ''),
                        'email' => (string) ($customer['email'] ?? ''),
                        'address' => $this->boletoHolderAddress($shipping),
                    ],
                ],
            ],
        ];

        $result = $this->createOrder($credentials, $customer, $charge, $externalId, $shipping, $notificationUrl);
        $created = $result['charge'];
        if ($this->interpretCharge($created) === 'cancelled') {
            throw new \RuntimeException('PagBank: '.$this->declineMessage($created));
        }

        $boleto = [];
        $method = $created['payment_method'] ?? null;
        if (is_array($method) && is_array($method['boleto'] ?? null)) {
            $boleto = $method['boleto'];
        }
        $barcode = '';
        foreach (['barcode', 'formatted_barcode'] as $key) {
            if (isset($boleto[$key]) && is_string($boleto[$key]) && $boleto[$key] !== '') {
                $barcode = $key === 'barcode'
                    ? (preg_replace('/\D/', '', $boleto[$key]) ?: $boleto[$key])
                    : $boleto[$key];
                break;
            }
        }
        $pdfUrl = '';
        $links = is_array($created['links'] ?? null) ? $created['links'] : [];
        foreach ($links as $link) {
            if (! is_array($link) || ! is_string($link['href'] ?? null)) {
                continue;
            }
            $media = strtolower((string) ($link['media'] ?? ''));
            $href = $link['href'];
            if ($media === 'application/pdf' || str_ends_with(strtolower($href), '.pdf')) {
                $pdfUrl = $href;
                break;
            }
        }
        $expireAt = is_string($boleto['due_date'] ?? null) && $boleto['due_date'] !== ''
            ? substr($boleto['due_date'], 0, 10)
            : $dueDate;

        return [
            'transaction_id' => $result['transaction_id'],
            'amount' => $amount,
            'expire_at' => $expireAt,
            'barcode' => is_string($barcode) ? $barcode : '',
            'pdf_url' => $pdfUrl,
            'metadata' => [
                'pagbank_order_id' => $result['order_id'],
                'pagbank_charge_id' => $result['transaction_id'],
            ],
            'raw' => $created,
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        $outcome = $this->chargeOutcome($transactionId, $credentials);
        if ($outcome === null) {
            return null;
        }
        if ($outcome === 'paid') {
            return 'paid';
        }
        if ($outcome === 'refunded' || $outcome === 'cancelled') {
            return 'cancelled';
        }

        return 'pending';
    }

    /**
     * paid, pending, cancelled, refunded ou null.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function chargeOutcome(string $transactionId, array $credentials): ?string
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '' || $this->token($credentials) === '') {
            return null;
        }
        try {
            $charge = $this->fetchCharge($transactionId, $credentials);
            if ($charge === null) {
                return null;
            }

            return $this->interpretCharge($charge);
        } catch (\Throwable $e) {
            Log::debug('PagBankDriver chargeOutcome', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>|null
     */
    private function fetchCharge(string $transactionId, array $credentials): ?array
    {
        $base = $this->baseUrl($credentials);
        if (str_starts_with($transactionId, 'ORDE_')) {
            $response = $this->http($credentials)->get($base.'/orders/'.rawurlencode($transactionId));
        } else {
            $response = $this->http($credentials)->get($base.'/charges/'.rawurlencode($transactionId));
        }
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            Log::debug('PagBankDriver fetchCharge HTTP', [
                'transaction_id' => $transactionId,
                'status' => $response->status(),
            ]);

            return null;
        }
        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }
        if (isset($body['charges']) && is_array($body['charges']) && isset($body['charges'][0]) && is_array($body['charges'][0])) {
            return $body['charges'][0];
        }
        if (isset($body['status'])) {
            return $body;
        }

        return null;
    }
}
