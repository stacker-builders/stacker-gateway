<?php

namespace Tests\Unit\Gateways;

use App\Gateways\Asaas\AsaasDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AsaasDriverTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        return [
            'api_key' => '$aact_hmlg_test',
            'sandbox' => true,
        ];
    }

    public function test_map_payment_status_overdue_stays_pending(): void
    {
        $this->assertSame('pending', AsaasDriver::mapPaymentStatus('OVERDUE'));
        $this->assertSame('paid', AsaasDriver::mapPaymentStatus('CONFIRMED'));
        $this->assertSame('paid', AsaasDriver::mapPaymentStatus('RECEIVED'));
        $this->assertSame('cancelled', AsaasDriver::mapPaymentStatus('REFUNDED'));
        $this->assertSame('cancelled', AsaasDriver::mapPaymentStatus('PENDING', true));
    }

    public function test_create_pix_reuses_existing_customer_and_fetches_qr(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/customers')) {
                return Http::response(['data' => [['id' => 'cus_existing']]], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/lean/payments')) {
                return Http::response(['id' => 'pay_pix_1', 'status' => 'PENDING'], 200);
            }
            if (str_contains($url, '/pixQrCode')) {
                return Http::response([
                    'encodedImage' => 'iVBORw0KGgo=',
                    'payload' => '00020101021226880014br.gov.bcb.pix',
                ], 200);
            }

            return Http::response(['errors' => [['description' => 'unexpected '.$url]]], 500);
        });

        $result = (new AsaasDriver)->createPixPayment(
            $this->credentials(),
            10.50,
            ['name' => 'Maria', 'document' => '123.456.789-09', 'email' => 'm@test.com'],
            '42',
            'https://pay.exemplo.com/webhooks/gateways/asaas'
        );

        $this->assertSame('pay_pix_1', $result['transaction_id']);
        $this->assertSame('00020101021226880014br.gov.bcb.pix', $result['copy_paste']);
        $this->assertSame('iVBORw0KGgo=', $result['qrcode']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/lean/payments')
                && ($request->data()['customer'] ?? null) === 'cus_existing'
                && ($request->data()['billingType'] ?? null) === 'PIX';
        });
        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST' && str_contains($request->url(), '/customers');
        });
    }

    public function test_create_pix_rejects_invalid_document(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CPF ou CNPJ válido é obrigatório');

        (new AsaasDriver)->createPixPayment(
            $this->credentials(),
            10.00,
            ['name' => 'Maria', 'document' => '123', 'email' => 'm@test.com'],
            '42',
            'https://pay.exemplo.com/webhooks/gateways/asaas'
        );
    }

    public function test_create_boleto_uses_official_identification_field_endpoint(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/customers')) {
                return Http::response(['data' => []], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/customers')) {
                return Http::response(['id' => 'cus_new'], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/lean/payments')) {
                return Http::response([
                    'id' => 'pay_bol_1',
                    'value' => 25.00,
                    'dueDate' => '2026-09-22',
                    'bankSlipUrl' => 'https://www.asaas.com/b/pay_bol_1',
                ], 200);
            }
            if (str_contains($url, '/payments/pay_bol_1/identificationField')) {
                return Http::response([
                    'identificationField' => '00190000090275928800021932978170187890000005000',
                    'barCode' => '00191878900000050000000002759288002193297817',
                ], 200);
            }

            return Http::response(['errors' => [['description' => 'unexpected '.$url]]], 500);
        });

        $result = (new AsaasDriver)->createBoletoPayment(
            $this->credentials(),
            25.00,
            ['name' => 'Maria', 'document' => '12345678909', 'email' => 'm@test.com'],
            '42',
            'https://pay.exemplo.com/webhooks/gateways/asaas'
        );

        $this->assertSame('pay_bol_1', $result['transaction_id']);
        $this->assertSame('00190000090275928800021932978170187890000005000', $result['barcode']);
        $this->assertSame('https://www.asaas.com/b/pay_bol_1', $result['pdf_url']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/payments/pay_bol_1/identificationField')
            && ! str_contains($request->url(), '/lean/payments/pay_bol_1/identificationField'));
    }

    public function test_create_card_returns_requires_action_when_3ds_challenge_present(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/customers')) {
                return Http::response(['data' => [['id' => 'cus_1']]], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/lean/payments')) {
                return Http::response([
                    'id' => 'pay_card_3ds',
                    'status' => 'PENDING',
                    'threeDSecureChallengeUrl' => 'https://acs.example/challenge',
                ], 200);
            }

            return Http::response(['errors' => [['description' => 'unexpected '.$url]]], 500);
        });

        $result = (new AsaasDriver)->createCardPayment(
            $this->credentials(),
            50.00,
            [
                'name' => 'Maria',
                'document' => '12345678909',
                'email' => 'm@test.com',
                'phone' => '11999999999',
                'address' => [
                    'zip_code' => '01310-000',
                    'street_number' => '150',
                ],
            ],
            '42',
            [
                'card_holder_name' => 'MARIA SILVA',
                'card_number' => '4111111111111111',
                'card_expiry_month' => '12',
                'card_expiry_year' => '2030',
                'card_ccv' => '123',
            ]
        );

        $this->assertSame('pay_card_3ds', $result['transaction_id']);
        $this->assertSame('requires_action', $result['status']);
        $this->assertSame('https://acs.example/challenge', $result['redirect_url']);
    }

    public function test_get_transaction_status_maps_overdue_to_pending(): void
    {
        Http::fake([
            'api-sandbox.asaas.com/v3/lean/payments/pay_1' => Http::response([
                'id' => 'pay_1',
                'status' => 'OVERDUE',
            ], 200),
        ]);

        $this->assertSame('pending', (new AsaasDriver)->getTransactionStatus('pay_1', $this->credentials()));
    }

    public function test_fetch_account_balance_reads_reais(): void
    {
        Http::fake([
            'api-sandbox.asaas.com/v3/finance/balance' => Http::response(['balance' => 5210.96], 200),
        ]);

        $payload = (new AsaasDriver)->fetchAccountBalance($this->credentials());

        $this->assertSame(5210.96, $payload['balance']);
    }

    public function test_pix_automatic_authorization_uses_manual_mode_and_links_pending_payment(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/customers')) {
                return Http::response(['data' => []], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/customers')) {
                return Http::response(['id' => 'cus_auto'], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/pix/automatic/authorizations')) {
                return Http::response([
                    'id' => 'auth-1',
                    'customerId' => 'cus_auto',
                    'status' => 'CREATED',
                    'payload' => '000201',
                    'encodedImage' => 'aW1n',
                ], 200);
            }
            if ($request->method() === 'GET' && str_contains($url, '/payments')) {
                return Http::response([
                    'data' => [
                        ['id' => 'pay_first', 'value' => 49.9, 'status' => 'PENDING'],
                    ],
                ], 200);
            }

            return Http::response(['errors' => [['description' => 'unexpected '.$url]]], 500);
        });

        $result = (new AsaasDriver)->createPixAutomaticAuthorization(
            $this->credentials(),
            ['name' => 'Maria', 'document' => '12345678901', 'email' => 'maria@test.com', 'phone' => '11999999999'],
            '88',
            49.9,
            'MONTHLY',
            now()->addMonth()->toDateString(),
            'Plano mensal'
        );

        $this->assertSame('auth-1', $result['authorization_id']);
        $this->assertSame('pay_first', $result['payment_id']);
        $this->assertSame('000201', $result['copy_paste']);
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/pix/automatic/authorizations')) {
                return false;
            }
            $body = $request->data();

            return ($body['paymentCreationMode'] ?? null) === 'MANUAL'
                && ($body['frequency'] ?? null) === 'MONTHLY'
                && ($body['immediateQrCode']['originalValue'] ?? null) === 49.9
                && ($body['retryPolicy'] ?? null) === 'ALLOW_THREE_IN_SEVEN_DAYS';
        });
    }
}
