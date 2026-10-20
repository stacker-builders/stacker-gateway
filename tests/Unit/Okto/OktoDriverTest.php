<?php

namespace Tests\Unit\Okto;

use App\Gateways\GatewayRegistry;
use App\Gateways\Okto\OktoDriver;
use App\Services\Platform\AcquirerWalletBalanceService;
use App\Support\GatewayApiCredentials;
use App\Support\GatewayWebhookUrl;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\OktoRsaFixture;
use Tests\TestCase;

class OktoDriverTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function credentials(bool $sandbox = true): array
    {
        return [
            'access_token' => 'okto-token-test',
            'sandbox' => $sandbox,
        ];
    }

    public function test_okto_is_registered_as_pix_acquirer(): void
    {
        $this->assertTrue(GatewayRegistry::isAllowedAcquirer('okto'));
        $def = GatewayRegistry::get('okto');
        $this->assertNotNull($def);
        $this->assertContains('pix', $def['methods'] ?? []);
        $this->assertNotContains('card', $def['methods'] ?? []);
        $this->assertSame('images/gateways/okto_payments.svg', $def['image'] ?? null);
        $this->assertSame('Mauricio', $def['support_contacts'][0]['name'] ?? null);
        $this->assertSame('Gerente de contas', $def['support_contacts'][0]['role'] ?? null);
        $this->assertSame('5511970790960', $def['support_contacts'][0]['whatsapp'] ?? null);
        $this->assertInstanceOf(OktoDriver::class, GatewayRegistry::driver('okto'));
        $this->assertContains('okto', config('gateways.default_order.pix'));
        $this->assertTrue(GatewayApiCredentials::isReadyForGateway('okto', $this->credentials()));
        $this->assertFalse(GatewayApiCredentials::isReadyForGateway('okto', ['access_token' => '']));
    }

    public function test_webhook_url_is_public(): void
    {
        config([
            'app.url' => 'http://localhost:8085',
            'getfy.webhook_public_url' => 'https://pay.exemplo.com',
        ]);

        $this->assertSame(
            'https://pay.exemplo.com/webhooks/gateways/okto',
            GatewayWebhookUrl::forGateway('okto')
        );
    }

    public function test_test_connection_uses_balance_endpoint(): void
    {
        Http::fake([
            'demo-pix.oktopay.eu/operator/v2/balance' => Http::response([
                'availableBalance' => 29800.00,
                'totalBalance' => 30000.00,
                'pendingBalance' => -200.00,
            ], 200),
        ]);

        $this->assertTrue((new OktoDriver)->testConnection($this->credentials()));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://demo-pix.oktopay.eu/operator/v2/balance'
                && $request->method() === 'GET'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_create_pix_sends_cents_and_maps_brcode(): void
    {
        Http::fake([
            'demo-pix.oktopay.eu/transactions/deposit' => Http::response([
                'id' => '019e3cd1-a244-77ce-9389-a562d3df6fb0',
                'status' => 'created',
                'amount' => 1990,
                'brcode' => '00020101br.gov.bcb.pix...',
                'externalId' => '42',
            ], 200),
        ]);

        $result = (new OktoDriver)->createPixPayment(
            $this->credentials(),
            19.90,
            ['name' => 'Cliente Exemplo', 'document' => '123.456.789-09', 'email' => 'c@ex.com'],
            '516042b1-a804-49f9-a252-1222c8273b46',
            'https://pay.exemplo.com/webhooks/gateways/okto'
        );

        $this->assertSame('019e3cd1-a244-77ce-9389-a562d3df6fb0', $result['transaction_id']);
        $this->assertSame('00020101br.gov.bcb.pix...', $result['copy_paste']);
        $this->assertSame('516042b1-a804-49f9-a252-1222c8273b46', $result['metadata']['okto_external_id'] ?? null);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/transactions/deposit') || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return ($body['amount'] ?? null) === 1990
                && ($body['externalId'] ?? null) === '516042b1-a804-49f9-a252-1222c8273b46'
                && ($body['taxId'] ?? null) === '123.456.789-09'
                && ($body['name'] ?? null) === 'Cliente Exemplo';
        });
    }

    public function test_create_pix_upgrades_numeric_external_id_and_fetches_brcode(): void
    {
        Http::fake([
            'demo-pix.oktopay.eu/transactions/deposit' => Http::response([
                'id' => 'dep-99',
                'status' => 'created',
            ], 200),
            'demo-pix.oktopay.eu/transactions/qrcode/dep-99*' => Http::response('00020101021226890014br.gov.bcb.pix', 200),
        ]);

        $result = (new OktoDriver)->createPixPayment(
            $this->credentials(),
            12,
            ['name' => 'Marion', 'document' => '60418443734'],
            '42',
            'https://pay.exemplo.com/webhooks/gateways/okto'
        );

        $this->assertSame('dep-99', $result['transaction_id']);
        $this->assertSame('00020101021226890014br.gov.bcb.pix', $result['copy_paste']);
        $this->assertTrue(\Illuminate\Support\Str::isUuid((string) ($result['metadata']['okto_external_id'] ?? '')));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/transactions/deposit') || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return ($body['taxId'] ?? null) === '604.184.437-34'
                && ($body['name'] ?? null) === 'Marion Checkout'
                && ! ctype_digit((string) ($body['externalId'] ?? ''));
        });
    }

    public function test_create_pix_surfaces_okto_error_code(): void
    {
        Http::fake([
            'demo-pix.oktopay.eu/transactions/deposit' => Http::response([
                'errors' => [
                    ['code' => 2010, 'message' => 'Invalid external id.', 'detailedMessage' => 'externalId must be unique and url-safe'],
                ],
            ], 400),
        ]);

        try {
            (new OktoDriver)->createPixPayment(
                $this->credentials(),
                10,
                ['name' => 'Marion Schuppe', 'document' => '604.184.437-34'],
                '42',
                'https://pay.exemplo.com/webhooks/gateways/okto'
            );
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('2010', $e->getMessage());
            $this->assertStringContainsString('url-safe', $e->getMessage());
        }
    }

    public function test_diagnose_pix_deposit_returns_api_error(): void
    {
        Http::fake([
            'demo-pix.oktopay.eu/transactions/deposit' => Http::response([
                'errorList' => [
                    ['code' => 2001, 'message' => 'The tax id is invalid.', 'detailedMessage' => 'taxId rejected'],
                ],
            ], 400),
        ]);

        $result = (new OktoDriver)->diagnosePixDeposit($this->credentials());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('2001', $result['message']);
        $this->assertStringContainsString('demo-pix.oktopay.eu', $result['message']);
    }

    public function test_create_pix_rejects_cnpj_payer(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CPF');

        (new OktoDriver)->createPixPayment(
            $this->credentials(),
            10,
            ['name' => 'Empresa', 'document' => '12.345.678/0001-95'],
            '1',
            'https://pay.exemplo.com/webhooks/gateways/okto'
        );
    }

    public function test_withdraw_sends_dict_key_without_bank_account(): void
    {
        Http::fake([
            'demo-pix.oktopay.eu/transactions/withdraw' => Http::response([
                'externalId' => 'saque-9',
                'status' => 'created',
            ], 200),
        ]);

        $result = (new OktoDriver)->createTransfer(
            $this->credentials(),
            5000,
            'destino@pix.com',
            'email',
            'saque-9',
            '60418443734'
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('saque-9', $result['transaction_id']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/transactions/withdraw') || $request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();

            return ($body['dictKey'] ?? null) === 'destino@pix.com'
                && ($body['amount'] ?? null) === 5000
                && ($body['taxId'] ?? null) === '60418443734'
                && ! array_key_exists('bankAccount', $body);
        });
    }

    public function test_withdraw_rejects_cnpj_key(): void
    {
        $result = (new OktoDriver)->createTransfer(
            $this->credentials(),
            1000,
            '12345678000195',
            'cnpj',
            'saque-1'
        );

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('CNPJ', (string) ($result['error'] ?? ''));
    }

    public function test_balance_parser_uses_reais(): void
    {
        $service = app(AcquirerWalletBalanceService::class);
        $this->assertSame(29800.0, $service->parseOktoAvailable([
            'availableBalance' => 29800.00,
            'totalBalance' => 30000.00,
        ]));
    }

    public function test_rsa_payload_signature_roundtrip(): void
    {
        $keys = OktoRsaFixture::pair();
        $body = '{"Invoice":{"status":"paid"}}';
        $rawSig = '';
        $this->assertTrue(openssl_sign($body, $rawSig, $keys['private'], OPENSSL_ALGO_SHA256));
        $this->assertTrue(OktoDriver::verifyPayloadSignature($body, base64_encode($rawSig), $keys['public']));
        $this->assertFalse(OktoDriver::verifyPayloadSignature($body, base64_encode($rawSig), $keys['public'].'x'));
    }
}
