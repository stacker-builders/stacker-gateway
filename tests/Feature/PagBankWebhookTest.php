<?php

namespace Tests\Feature;

use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PagBankWebhookTest extends TestCase
{
    private function seedCredentials(string $token = 'pagbank-token'): void
    {
        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'pagbank',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'token' => $token,
            'public_key' => 'PUB',
            'sandbox' => '0',
        ]);
        $cred->save();
    }

    private function pendingOrder(string $chargeId): Order
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['name' => 'PagBank PIX']);

        return Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 1050,
            'email' => 'buyer@example.com',
            'payment_method' => 'pix',
            'gateway' => 'pagbank',
            'gateway_id' => $chargeId,
        ]);
    }

    public function test_paid_notification_completes_order_after_reconfirm(): void
    {
        $chargeId = 'CHAR_PAID_HOOK';
        $this->seedCredentials();
        $order = $this->pendingOrder($chargeId);

        Http::fake([
            'https://api.pagseguro.com/charges/'.$chargeId => Http::response([
                'id' => $chargeId,
                'status' => 'PAID',
                'amount' => ['summary' => ['refunded' => 0]],
            ], 200),
        ]);

        $this->postJson('/webhooks/gateways/pagbank', [
            'id' => 'ORDE_HOOK',
            'reference_id' => (string) $order->id,
            'charges' => [[
                'id' => $chargeId,
                'status' => 'PAID',
            ]],
        ])->assertOk()->assertJson(['received' => true]);

        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_invalid_authenticity_token_is_rejected(): void
    {
        $chargeId = 'CHAR_BAD_SIG';
        $this->seedCredentials('pagbank-token');
        $this->pendingOrder($chargeId);

        $payload = [
            'id' => $chargeId,
            'status' => 'PAID',
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', '/webhooks/gateways/pagbank', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTHENTICITY_TOKEN' => 'deadbeef',
        ], $raw)->assertUnauthorized();
    }
}
