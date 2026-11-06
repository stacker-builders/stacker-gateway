<?php

namespace Tests\Feature;

use App\Gateways\Contracts\GatewayDriver;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReconcilePendingPaymentsTest extends TestCase
{
    public function test_reconcile_pending_approves_order_when_gateway_reports_paid(): void
    {
        Event::fake();

        GatewayRegistry::register([
            'slug' => 'fake',
            'name' => 'Fake',
            'image' => '',
            'methods' => ['pix', 'boleto', 'card'],
            'scope' => 'national',
            'signup_url' => '',
            'driver' => FakeGatewayDriver::class,
            'credential_keys' => [],
        ]);

        $user = User::factory()->create();

        $productId = DB::table('products')->insertGetId([
            'tenant_id' => null,
            'name' => 'Produto Teste',
            'slug' => 'produto-teste',
            'description' => 'x',
            'type' => Product::TYPE_LINK_PAGAMENTO,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 10.00,
            'currency' => 'BRL',
            'is_active' => true,
            'checkout_slug' => 'abcdefg',
            'checkout_config' => json_encode(Product::defaultCheckoutConfig(), JSON_UNESCAPED_UNICODE),
            'conversion_pixels' => json_encode(Product::defaultConversionPixels(), JSON_UNESCAPED_UNICODE),
            'member_area_config' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = Order::create([
            'tenant_id' => null,
            'user_id' => $user->id,
            'product_id' => $productId,
            'status' => 'pending',
            'amount' => 10.00,
            'email' => $user->email,
            'gateway' => 'fake',
            'gateway_id' => 'tx_1',
            'metadata' => [],
        ]);

        $credential = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'fake',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $credential->setEncryptedCredentials(['k' => 'v']);
        $credential->save();

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 10,
            '--days' => 30,
            '--min-age-minutes' => 0,
        ]);

        $order->refresh();
        $this->assertSame('completed', $order->status);
    }

    public function test_disconnected_acquirer_is_not_reconciled_or_logged(): void
    {
        Event::fake();

        GatewayRegistry::register([
            'slug' => 'fake',
            'name' => 'Fake',
            'image' => '',
            'methods' => ['pix'],
            'scope' => 'national',
            'signup_url' => '',
            'driver' => FakeGatewayDriver::class,
            'credential_keys' => [],
        ]);

        $user = User::factory()->create();
        $product = $this->createTestProduct(['tenant_id' => $user->id]);

        $disconnected = Order::create([
            'tenant_id' => $user->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 20.00,
            'email' => $user->email,
            'gateway' => 'cielo',
            'gateway_id' => 'cielo_tx',
            'metadata' => [],
        ]);
        $disconnected->forceFill(['updated_at' => now()])->save();

        $live = Order::create([
            'tenant_id' => $user->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10.00,
            'email' => $user->email,
            'gateway' => 'fake',
            'gateway_id' => 'tx_1',
            'metadata' => [],
        ]);
        $live->forceFill(['updated_at' => now()->subMinute()])->save();

        $off = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'cielo',
            'credentials' => '',
            'is_connected' => false,
            'is_enabled' => false,
        ]);
        $off->setEncryptedCredentials(['merchant_id' => 'x', 'merchant_key' => 'y']);
        $off->save();

        $on = GatewayCredential::create([
            'tenant_id' => null,
            'gateway_slug' => 'fake',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $on->setEncryptedCredentials(['k' => 'v']);
        $on->save();

        Artisan::call('payments:reconcile-pending', [
            '--limit' => 1,
            '--days' => 30,
            '--min-age-minutes' => 0,
        ]);

        $this->assertSame('pending', $disconnected->fresh()->status);
        $this->assertSame('completed', $live->fresh()->status);
        $this->assertStringContainsString('Checados: 1', Artisan::output());
        $this->assertStringContainsString('Sem credencial: 0', Artisan::output());
    }
}

class FakeGatewayDriver implements GatewayDriver
{
    public function testConnection(array $credentials): bool
    {
        return true;
    }

    public function createPixPayment(array $credentials, float $amount, array $consumer, string $externalId, string $postbackUrl, array $options = []): array
    {
        return ['transaction_id' => 'tx_1'];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        return 'paid';
    }

    public function createCardPayment(array $credentials, float $amount, array $consumer, string $externalId, array $card): array
    {
        return ['transaction_id' => 'tx_1', 'status' => 'paid'];
    }

    public function createBoletoPayment(array $credentials, float $amount, array $consumer, string $externalId, string $notificationUrl): array
    {
        return [
            'transaction_id' => 'tx_1',
            'amount' => $amount,
            'expire_at' => now()->addDays(3)->toDateString(),
            'barcode' => '123',
            'pdf_url' => 'https://example.com/boleto.pdf',
        ];
    }
}
