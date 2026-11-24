<?php

namespace Tests\Unit\PagBank;

use App\Gateways\PagBank\PagBankDriver;
use App\Support\CardInstallments;
use App\Support\GatewayApiCredentials;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PagBankDriverTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'token' => 'pagbank-token',
            'public_key' => 'PUB_KEY',
            'sandbox' => '0',
        ];
    }

    /**
     * @return array{name: string, document: string, email: string, phone: string, address: array<string, string>}
     */
    private function consumer(): array
    {
        return [
            'name' => 'Jose da Silva',
            'document' => '12345678909',
            'email' => 'jose@example.com',
            'phone' => '11999999999',
            'address' => [
                'zip_code' => '01452002',
                'street_name' => 'Avenida Brigadeiro Faria Lima',
                'street_number' => '1384',
                'neighborhood' => 'Pinheiros',
                'city' => 'Sao Paulo',
                'federal_unit' => 'SP',
            ],
        ];
    }

    public function test_credentials_and_installments_are_recognized(): void
    {
        $this->assertTrue(GatewayApiCredentials::isReadyForGateway('pagbank', $this->credentials()));
        $this->assertFalse(GatewayApiCredentials::isReadyForGateway('pagbank', ['token' => '']));
        $this->assertTrue(CardInstallments::gatewaySupports('pagbank'));
    }

    public function test_connection_accepts_authenticated_not_found(): void
    {
        Http::fake([
            'https://api.pagseguro.com/orders/*' => Http::response(['error_messages' => []], 404),
        ]);

        $this->assertTrue((new PagBankDriver)->testConnection($this->credentials()));
    }

    public function test_connection_rejects_invalid_token(): void
    {
        Http::fake([
            'https://api.pagseguro.com/orders/*' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $this->assertFalse((new PagBankDriver)->testConnection($this->credentials()));
    }

    public function test_create_pix_sends_charge_and_returns_copy_paste(): void
    {
        Http::fake([
            'https://api.pagseguro.com/orders' => Http::response([
                'id' => 'ORDE_PIX',
                'charges' => [[
                    'id' => 'CHAR_PIX',
                    'status' => 'WAITING',
                    'qr_code' => ['id' => 'QRCO_1', 'text' => '000201PIX'],
                    'links' => [],
                ]],
            ], 201),
        ]);

        $result = (new PagBankDriver)->createPixPayment(
            $this->credentials(),
            10.5,
            $this->consumer(),
            '91',
            'https://loja.example.com/webhooks/gateways/pagbank'
        );

        $this->assertSame('CHAR_PIX', $result['transaction_id']);
        $this->assertSame('000201PIX', $result['copy_paste']);
        $this->assertSame('ORDE_PIX', $result['metadata']['pagbank_order_id']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://api.pagseguro.com/orders'
                && $request->hasHeader('Authorization', 'Bearer pagbank-token')
                && ($body['charges'][0]['payment_method']['type'] ?? null) === 'PIX'
                && ($body['charges'][0]['amount']['value'] ?? null) === 1050
                && ($body['notification_urls'][0] ?? null) === 'https://loja.example.com/webhooks/gateways/pagbank'
                && ($body['customer']['tax_id'] ?? null) === '12345678909';
        });
    }

    public function test_create_card_sends_encrypted_card_and_installments(): void
    {
        Http::fake([
            'https://api.pagseguro.com/orders' => Http::response([
                'id' => 'ORDE_CARD',
                'charges' => [[
                    'id' => 'CHAR_CARD',
                    'status' => 'PAID',
                    'payment_response' => ['code' => '20000', 'message' => 'SUCESSO'],
                ]],
            ], 201),
        ]);

        $result = (new PagBankDriver)->createCardPayment(
            $this->credentials(),
            20,
            $this->consumer(),
            '92',
            [
                'payment_token' => json_encode(['encrypted_card' => str_repeat('A', 40), 'installments' => 3]),
                'installments' => 3,
            ]
        );

        $this->assertSame('CHAR_CARD', $result['transaction_id']);
        $this->assertSame('paid', $result['status']);

        Http::assertSent(function ($request) {
            $method = $request->data()['charges'][0]['payment_method'] ?? [];

            return ($method['type'] ?? null) === 'CREDIT_CARD'
                && ($method['installments'] ?? null) === 3
                && ($method['capture'] ?? null) === true
                && ($method['card']['encrypted'] ?? null) === str_repeat('A', 40);
        });
    }

    public function test_declined_card_throws(): void
    {
        Http::fake([
            'https://api.pagseguro.com/orders' => Http::response([
                'id' => 'ORDE_NO',
                'charges' => [[
                    'id' => 'CHAR_NO',
                    'status' => 'DECLINED',
                    'payment_response' => ['message' => 'NAO AUTORIZADO PELO PAGSEGURO'],
                ]],
            ], 201),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NAO AUTORIZADO');

        (new PagBankDriver)->createCardPayment(
            $this->credentials(),
            20,
            $this->consumer(),
            '93',
            ['payment_token' => json_encode(['encrypted_card' => str_repeat('B', 40)])]
        );
    }

    public function test_create_boleto_returns_barcode_and_pdf(): void
    {
        Http::fake([
            'https://api.pagseguro.com/orders' => Http::response([
                'id' => 'ORDE_BOL',
                'charges' => [[
                    'id' => 'CHAR_BOL',
                    'status' => 'WAITING',
                    'payment_method' => [
                        'type' => 'BOLETO',
                        'boleto' => [
                            'barcode' => '08197081080010000001701152240436612400000034343',
                            'formatted_barcode' => '08197.08108 00100.000017',
                            'due_date' => '2026-12-01',
                        ],
                    ],
                    'links' => [[
                        'rel' => 'SELF',
                        'href' => 'https://boleto.pagseguro.com.br/abc.pdf',
                        'media' => 'application/pdf',
                        'type' => 'GET',
                    ]],
                ]],
            ], 201),
        ]);

        $result = (new PagBankDriver)->createBoletoPayment(
            $this->credentials(),
            34.34,
            $this->consumer(),
            '94',
            'https://loja.example.com/webhooks/gateways/pagbank'
        );

        $this->assertSame('CHAR_BOL', $result['transaction_id']);
        $this->assertSame('08197081080010000001701152240436612400000034343', $result['barcode']);
        $this->assertSame('https://boleto.pagseguro.com.br/abc.pdf', $result['pdf_url']);
        $this->assertSame('2026-12-01', $result['expire_at']);

        Http::assertSent(function ($request) {
            $boleto = $request->data()['charges'][0]['payment_method']['boleto'] ?? [];

            return ($request->data()['charges'][0]['payment_method']['type'] ?? null) === 'BOLETO'
                && ($boleto['due_date'] ?? null) !== null
                && ($boleto['holder']['tax_id'] ?? null) === '12345678909';
        });
    }

    public function test_transaction_status_maps_paid_and_refund(): void
    {
        Http::fake([
            'https://api.pagseguro.com/charges/CHAR_PAID' => Http::response([
                'id' => 'CHAR_PAID',
                'status' => 'PAID',
                'amount' => ['summary' => ['refunded' => 0]],
            ], 200),
            'https://api.pagseguro.com/charges/CHAR_REF' => Http::response([
                'id' => 'CHAR_REF',
                'status' => 'CANCELED',
                'amount' => ['summary' => ['refunded' => 1000]],
            ], 200),
        ]);

        $driver = new PagBankDriver;
        $this->assertSame('paid', $driver->getTransactionStatus('CHAR_PAID', $this->credentials()));
        $this->assertSame('refunded', $driver->chargeOutcome('CHAR_REF', $this->credentials()));
        $this->assertSame('cancelled', $driver->getTransactionStatus('CHAR_REF', $this->credentials()));
    }
}
