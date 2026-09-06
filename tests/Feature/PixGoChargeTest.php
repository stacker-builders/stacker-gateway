<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use App\Services\PixGoAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class PixGoChargeTest extends TestCase
{
    private function approvedSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller;
    }

    public function test_pixgo_routes_return_404_when_disabled(): void
    {
        PixGoAccess::setEnabled(false);
        $seller = $this->approvedSeller();

        $this->actingAs($seller)->get('/pixgo')->assertNotFound();
    }

    public function test_pixgo_index_loads_when_enabled(): void
    {
        PixGoAccess::setEnabled(true);
        $seller = $this->approvedSeller();

        $this->actingAs($seller)
            ->get('/pixgo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PixGo/Index')
                ->has('sidebar_label')
                ->has('minimum_charge_brl'));
    }

    public function test_charge_creates_pixgo_order_and_redirects(): void
    {
        PixGoAccess::setEnabled(true);
        $seller = $this->approvedSeller();

        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('createPixPayment')
            ->once()
            ->andReturnUsing(function ($order) {
                $order->update(['gateway' => 'fake', 'gateway_id' => 'tx-pixgo-1']);

                return [
                    'transaction_id' => 'tx-pixgo-1',
                    'gateway' => 'fake',
                    'qrcode' => 'qr-data',
                    'copy_paste' => '00020126580014br.gov.bcb.pix',
                ];
            });
        $this->instance(PaymentService::class, $mock);

        $response = $this->actingAs($seller)->post('/pixgo/cobrar', [
            'amount_cents' => 2590,
            'buyer' => [
                'name' => 'Cliente PixGO',
                'email' => 'cliente@example.com',
            ],
        ]);

        $response->assertRedirect();

        $order = Order::query()->where('tenant_id', $seller->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame('pixgo', $order->metadata['source'] ?? null);
        $this->assertSame('pixgo', $order->sale_origin);
        $this->assertSame('pix', $order->payment_method);
        $this->assertNull($order->product_id);
        $this->assertTrue($order->isPixGoSale());
        $this->assertSame(25.90, (float) $order->amount);
    }

    public function test_status_endpoint_returns_completed_when_order_paid(): void
    {
        PixGoAccess::setEnabled(true);
        $seller = $this->approvedSeller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10.00,
            'email' => 'test@example.com',
            'payment_method' => 'pix',
            'metadata' => ['source' => 'pixgo'],
        ]);

        $token = 'test-token-status';
        Cache::put('pixgo.charge.'.$token, [
            'order_id' => $order->id,
            'tenant_id' => $seller->id,
        ], now()->addHour());

        $this->actingAs($seller)
            ->getJson('/pixgo/status?token='.$token)
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_wallet_credit_uses_pixgo_fee_bucket_not_checkout_pix(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet tables');
        }

        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 5.0, 'fixed' => 0.0],
            'api_pix' => ['percent' => 2.0, 'fixed' => 0.0],
            'pixgo' => ['percent' => 1.0, 'fixed' => 0.50],
            'card' => ['percent' => 0, 'fixed' => 0],
            'apple_pay' => ['percent' => 0, 'fixed' => 0],
            'google_pay' => ['percent' => 0, 'fixed' => 0],
            'boleto' => ['percent' => 0, 'fixed' => 0],
            'withdrawal' => ['percent' => 0, 'fixed' => 0],
        ], null);

        Setting::set('merchant_settlement_rules', [
            'pix' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'card' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'boleto' => ['days_to_available' => 0, 'reserve_percent' => 0],
        ], null);

        $seller = $this->approvedSeller();
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => 'pixgo-buyer@example.com',
            'payment_method' => 'pix',
            'metadata' => [
                'source' => 'pixgo',
                'checkout_payment_method' => 'pix',
            ],
        ]);

        event(new OrderCompleted($order->fresh()));

        $tx = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', WalletTransaction::TYPE_CREDIT_SALE)
            ->first();

        $this->assertNotNull($tx);
        // pixgo = 1% + R$0,50 = R$1,50 (não 5% do checkout)
        $this->assertEqualsWithDelta(1.50, (float) $tx->amount_fee, 0.001);
        $this->assertEqualsWithDelta(98.50, (float) $tx->amount_net, 0.001);
        $meta = is_array($tx->meta) ? $tx->meta : [];
        $this->assertEqualsWithDelta(1.0, (float) ($meta['percent_applied'] ?? 0), 0.0001);
        $this->assertEqualsWithDelta(0.50, (float) ($meta['fixed_applied'] ?? 0), 0.0001);
    }
}
