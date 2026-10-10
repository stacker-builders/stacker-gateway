<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BspayWebhookCashinConfirmedTest extends TestCase
{
    private const WEBHOOK_SECRET = 'bspay-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
        config([
            'queue.default' => 'sync',
            'getfy.api.inbound_webhooks_async' => false,
        ]);
        Event::fake();
    }

    public function test_webhook_completes_order_when_cashin_confirmed_and_api_confirms(): void
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
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        $cred->save();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'bspay',
            'gateway_id' => 'tx-bspay-1',
        ]);

        $response = $this->postSignedBspayWebhook([
            'success' => true,
            'data' => [
                'transaction_id' => 'tx-bspay-1',
                'status' => 'confirmed',
            ],
        ], self::WEBHOOK_SECRET);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_webhook_does_not_complete_order_when_list_api_does_not_confirm(): void
    {
        Http::fake([
            'https://api.bspay.co/v2/oauth/token' => Http::response([
                'access_token' => 'jwt-token',
                'expires_in' => 3600,
            ], 200),
            'https://api.bspay.co/v2/account/transactions/list' => Http::response([
                'success' => true,
                'data' => ['items' => []],
            ], 200),
        ]);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        $cred->save();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'bspay',
            'gateway_id' => 'tx-bspay-empty-list',
        ]);

        $response = $this->postSignedBspayWebhook([
            'success' => true,
            'data' => [
                'transaction_id' => 'tx-bspay-empty-list',
                'status' => 'confirmed',
            ],
        ], self::WEBHOOK_SECRET);

        $response->assertOk()->assertJson(['received' => true]);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_webhook_rejects_unsigned_cashin(): void
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        $cred->save();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'bspay',
            'gateway_id' => 'tx-bspay-unsigned',
        ]);

        $raw = json_encode([
            'data' => [
                'transaction_id' => 'tx-bspay-unsigned',
                'external_id' => (string) $order->id,
                'status' => 'confirmed',
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->call('POST', '/webhooks/gateways/bspay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BSPAY_EVENT' => 'cashin.confirmed',
        ], $raw);

        $response->assertUnauthorized();
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_webhook_rejects_invalid_hmac(): void
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'bspay',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'webhook_secret' => 'real-secret',
        ]);
        $cred->save();

        Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@test.com',
            'gateway' => 'bspay',
            'gateway_id' => 'tx-bspay-1',
        ]);

        $response = $this->postSignedBspayWebhook([
            'data' => ['transaction_id' => 'tx-bspay-1', 'status' => 'confirmed'],
        ], 'wrong-secret');

        $response->assertUnauthorized();
    }
}
