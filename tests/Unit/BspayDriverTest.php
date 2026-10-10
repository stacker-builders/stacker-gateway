<?php

namespace Tests\Unit;

use App\Gateways\Bspay\BspayDriver;
use App\Gateways\GatewayRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BspayDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_bspay_is_registered_as_pix_acquirer(): void
    {
        $this->assertTrue(GatewayRegistry::isAllowedAcquirer('bspay'));
        $def = GatewayRegistry::get('bspay');
        $this->assertNotNull($def);
        $this->assertContains('pix', $def['methods'] ?? []);
        $keys = array_column($def['credential_keys'] ?? [], 'key');
        $this->assertContains('client_id', $keys);
        $this->assertContains('client_secret', $keys);
        $this->assertContains('webhook_secret', $keys);
        $this->assertInstanceOf(BspayDriver::class, GatewayRegistry::driver('bspay'));
        $this->assertContains('bspay', config('gateways.default_order.pix'));
        $this->assertSame('Brasil, México', $def['country_name'] ?? null);
        $this->assertSame(
            [
                ['flag' => 'brasil.png', 'name' => 'Brasil'],
                ['flag' => 'mexico.png', 'name' => 'México'],
            ],
            $def['countries'] ?? null
        );
    }

    public function test_create_pix_maps_emv_qrcode_to_copy_paste(): void
    {
        $emv = '00020101021226640014br.gov.bcb.pix';
        Http::fake([
            'https://api.bspay.co/v2/oauth/token' => Http::response([
                'access_token' => 'jwt-token',
                'expires_in' => 3600,
            ], 200),
            'https://api.bspay.co/v2/transactions/cashin' => Http::response([
                'success' => true,
                'data' => [
                    'transaction_id' => 'tx-bspay-1',
                    'external_id' => 'order_001',
                    'currency' => 'BRL',
                    'amount' => 10.00,
                    'payment_method' => 'pix',
                    'payment_info' => [
                        'qrcode' => $emv,
                        'expiration' => 3600,
                        'expires_at' => '2026-04-04T11:20:53-03:00',
                    ],
                    'status' => 'pending',
                ],
            ], 200),
        ]);

        $driver = new BspayDriver;
        $result = $driver->createPixPayment(
            ['client_id' => 'id', 'client_secret' => 'secret'],
            10.00,
            ['name' => 'Cliente Teste', 'document' => '52998224725', 'email' => 'a@b.com'],
            'order_001',
            'https://example.test/webhooks/gateways/bspay'
        );

        $this->assertSame('tx-bspay-1', $result['transaction_id']);
        $this->assertSame($emv, $result['copy_paste']);
        $this->assertNull($result['qrcode']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/transactions/cashin')) {
                return false;
            }
            $body = $request->data();

            return ($body['currency'] ?? null) === 'BRL'
                && ($body['amount'] ?? null) === 10.0
                && ($body['external_id'] ?? null) === 'order_001'
                && ($body['postback_url'] ?? null) === 'https://example.test/webhooks/gateways/bspay'
                && ($body['payer']['document'] ?? null) === '52998224725';
        });
    }

    public function test_get_transaction_status_maps_confirmed_to_paid(): void
    {
        Http::fake([
            'https://api.bspay.co/v2/oauth/token' => Http::response([
                'access_token' => 'jwt-token',
                'expires_in' => 3600,
            ], 200),
            'https://api.bspay.co/v2/account/transactions/list' => Http::response([
                'success' => true,
                'data' => [
                    'items' => [
                        [
                            'transaction_id' => 'tx-bspay-1',
                            'status' => 'confirmed',
                            'type' => 'cashin',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $driver = new BspayDriver;
        $status = $driver->getTransactionStatus('tx-bspay-1', [
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);

        $this->assertSame('paid', $status);
    }

    public function test_get_transaction_status_reads_list_array_payload(): void
    {
        Http::fake([
            'https://api.bspay.co/v2/oauth/token' => Http::response([
                'access_token' => 'jwt-token',
                'expires_in' => 3600,
            ], 200),
            'https://api.bspay.co/v2/account/transactions/list' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 'tx-bspay-list',
                        'status' => 'confirmed',
                    ],
                ],
            ], 200),
        ]);

        $driver = new BspayDriver;
        $status = $driver->getTransactionStatus('tx-bspay-list', [
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);

        $this->assertSame('paid', $status);
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/account/transactions/list')) {
                return false;
            }
            $body = $request->data();

            return ($body['page_size'] ?? null) === 50
                && ($body['transaction_id'] ?? null) === 'tx-bspay-list';
        });
    }

    public function test_create_pix_cashout_sends_key_without_hmac_headers(): void
    {
        Http::fake([
            'https://api.bspay.co/v2/oauth/token' => Http::response([
                'access_token' => 'jwt-token',
                'expires_in' => 3600,
            ], 200),
            'https://api.bspay.co/v2/transactions/cashout' => Http::response([
                'transaction_id' => 'cashout-1',
                'status' => 'pending',
            ], 200),
        ]);

        $driver = new BspayDriver;
        $result = $driver->createPixCashout(
            ['client_id' => 'id', 'client_secret' => 'secret'],
            50.0,
            'destino@pix.com',
            'email',
            '77',
            'https://example.test/webhooks/gateways/bspay',
            'Infoprodutor'
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertSame('cashout-1', $result['transaction_id']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/transactions/cashout')) {
                return false;
            }
            $headers = $request->headers();
            $this->assertArrayNotHasKey('X-Signature', $headers);
            $this->assertArrayNotHasKey('X-Nonce', $headers);
            $body = $request->data();

            return ($body['currency'] ?? null) === 'BRL'
                && ($body['amount'] ?? null) === 50.0
                && ($body['key'] ?? null) === 'destino@pix.com'
                && ($body['key_type'] ?? null) === 'email'
                && ($body['external_id'] ?? null) === '77'
                && ($body['postback_url'] ?? null) === 'https://example.test/webhooks/gateways/bspay';
        });
    }
}
