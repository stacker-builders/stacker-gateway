<?php

namespace Tests\Feature;

use App\Jobs\SendApiApplicationWebhookJob;
use App\Models\ApiApplication;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApiPixAccess;
use App\Support\ApiScopes;
use App\Support\ApiWebhookPayloadBuilder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiBackwardCompatibilityTest extends TestCase
{
    /**
     * @return array{app: ApiApplication, public: string, secret: string, legacy: string}
     */
    private function createLegacyApiApp(int $tenantId): array
    {
        $public = ApiApplication::generatePublicKey();
        $secret = ApiApplication::generateSecretKey();
        $legacy = 'getfy_legacy_compat_'.uniqid();

        $app = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => 'Legacy API',
            'slug' => ApiApplication::generateUniqueSlug($tenantId, 'Legacy'),
            'api_key_hash' => ApiApplication::hashApiKey($legacy),
            'legacy_api_key_sha256' => hash('sha256', $legacy),
            'public_key' => $public,
            'secret_key_hash' => ApiApplication::hashSecretKey($secret),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
            'webhook_url' => 'https://example.com/webhook',
            'webhook_secret' => 'whsec_test',
        ]);

        return compact('app', 'public', 'secret', 'legacy');
    }

    public function test_legacy_bearer_auth_still_works_for_payment_status(): void
    {
        Setting::set('api_pix_enabled', '1', null);
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);

        $resp = $this->withHeaders([
            'Authorization' => 'Bearer '.$keys['legacy'],
        ])->get('/api/v1/payments/missing-order-id');

        $resp->assertStatus(404);
        $resp->assertHeader('X-Request-Id');
    }

    public function test_legacy_application_has_wildcard_scope(): void
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);

        $this->assertTrue($keys['app']->hasScope(ApiScopes::WITHDRAWALS_WRITE));
        $this->assertTrue($keys['app']->hasScope(ApiScopes::PAYMENTS_WRITE));
    }

    public function test_order_completed_webhook_payload_preserves_legacy_fields(): void
    {
        Http::fake(['https://example.com/*' => Http::response('ok', 200)]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $keys = $this->createLegacyApiApp((int) $seller->id);
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'price' => 10]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'api_application_id' => $keys['app']->id,
            'status' => 'completed',
            'amount' => 99.90,
            'email' => 'buyer@example.com',
            'payment_method' => 'pix',
        ]);

        $expected = ApiWebhookPayloadBuilder::orderPayload($order, 'order.completed');
        $this->assertArrayHasKey('event', $expected);
        $this->assertArrayHasKey('order_id', $expected);
        $this->assertArrayHasKey('amount', $expected);
        $this->assertArrayHasKey('amount_net', $expected);
        $this->assertIsFloat($expected['amount_net']);
        $this->assertArrayHasKey('status', $expected);
        $this->assertSame('order.completed', $expected['event']);

        (new SendApiApplicationWebhookJob($order->id, 'order.completed'))->handle();
    }
}
