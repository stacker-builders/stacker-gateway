<?php

namespace Tests\Feature;

use App\Models\CajuPayAccount;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Services\Platform\PlatformRevenueKpis;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformDashboardRevenueTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_faturamento_counts_credit_sale_only_when_pending_and_sale_exist(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-04-13 14:00:00'));

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'efi',
            'metadata' => [],
        ]);

        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            'amount_gross' => 100,
            'amount_fee' => 10.00,
            'amount_net' => 90,
            'meta' => ['released_at' => Carbon::now()->toIso8601String()],
        ]);
        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 100,
            'amount_fee' => 10.00,
            'amount_net' => 90,
            'meta' => [],
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.faturamento_taxas_cobradas', 10));
    }

    public function test_faturamento_sums_pending_parts_when_no_credit_sale(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-04-13 14:00:00'));

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'efi',
            'metadata' => [],
        ]);

        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            'amount_gross' => 60,
            'amount_fee' => 4.00,
            'amount_net' => 56,
            'meta' => ['portion' => 'main'],
        ]);
        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE_PENDING,
            'amount_gross' => 40,
            'amount_fee' => 6.00,
            'amount_net' => 34,
            'meta' => ['portion' => 'reserve'],
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.faturamento_taxas_cobradas', 10));
    }

    public function test_faturamento_respects_period_filter(): void
    {
        if (! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('wallet tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-04-13 14:00:00'));

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'efi',
            'metadata' => [],
        ]);
        Order::query()->whereKey($order->id)->update([
            'created_at' => Carbon::now()->subDay(),
            'updated_at' => Carbon::now()->subDay(),
        ]);

        WalletTransaction::query()->create([
            'tenant_id' => $seller->id,
            'order_id' => $order->id,
            'bucket' => 'pix',
            'type' => WalletTransaction::TYPE_CREDIT_SALE,
            'amount_gross' => 50,
            'amount_fee' => 99.00,
            'amount_net' => 40,
            'meta' => [],
        ]);

        $this->actingAs($admin)
            ->get('/plataforma/dashboard?period=hoje')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kpis.faturamento_taxas_cobradas', 0));
    }

    public function test_adq_vendas_includes_stripe_card_entry_fee(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('gateway_credentials')) {
            $this->markTestSkipped('wallet or gateway tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-04-13 14:00:00'));

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'stripe',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'secret_key' => 'sk_test',
            'publishable_key' => 'pk_test',
            'stripe_admin_fee_pix_brl' => '0,50',
            'stripe_admin_fee_pix_percent' => '1',
        ]);
        $cred->save();

        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => $buyer->email,
            'payment_method' => 'card',
            'gateway' => 'stripe',
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);

        // 100 * 1% + 0,50 = 1,50
        $kpis = PlatformRevenueKpis::compute(null, null);

        $this->assertSame(1.5, $kpis['faturamento_custo_adquirente_vendas']);
    }

    public function test_adq_vendas_excludes_non_stripe_card_entry_fee(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('gateway_credentials')) {
            $this->markTestSkipped('wallet or gateway tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-04-13 14:00:00'));

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $cred = GatewayCredential::query()->firstOrNew([
            'tenant_id' => null,
            'gateway_slug' => 'efi',
        ]);
        $cred->is_connected = true;
        $cred->setEncryptedCredentials([
            'efi_admin_fee_pix_brl' => '0,50',
            'efi_admin_fee_pix_percent' => '1',
        ]);
        $cred->save();

        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => $buyer->email,
            'payment_method' => 'card',
            'gateway' => 'efi',
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);

        $kpis = PlatformRevenueKpis::compute(null, null);

        $this->assertSame(0.0, $kpis['faturamento_custo_adquirente_vendas']);
    }

    public function test_adq_vendas_uses_order_cajupay_account_fees_not_only_default(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('cajupay_accounts')) {
            $this->markTestSkipped('wallet or cajupay_accounts tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-04-13 14:00:00'));

        $default = CajuPayAccount::create([
            'name' => 'Default zero fee',
            'is_default' => true,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $default->setEncryptedCredentials([
            'public_key' => 'gpk_default',
            'secret_key' => 'gsk_default',
            'cajupay_admin_fee_pix_brl' => '0',
            'cajupay_admin_fee_pix_percent' => '0',
        ]);
        $default->save();

        $secondary = CajuPayAccount::create([
            'name' => 'Secondary with fee',
            'is_default' => false,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $secondary->setEncryptedCredentials([
            'public_key' => 'gpk_b',
            'secret_key' => 'gsk_b',
            'cajupay_admin_fee_pix_brl' => '1,00',
            'cajupay_admin_fee_pix_percent' => '2',
        ]);
        $secondary->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'cajupay_account_id' => $secondary->id,
        ])->save();
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'cajupay_account_id' => $secondary->id,
            'metadata' => [],
        ]);

        // Conta da cobrança: 100 * 2% + 1,00 = 3,00 (default tem 0)
        $kpis = PlatformRevenueKpis::compute(null, null);

        $this->assertSame(3.0, $kpis['faturamento_custo_adquirente_vendas']);
    }

    public function test_adq_saques_uses_withdrawal_cajupay_account_fees_not_only_default(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('withdrawals') || ! Schema::hasTable('cajupay_accounts')) {
            $this->markTestSkipped('wallet/withdrawals/cajupay tables');
        }

        Carbon::setTestNow(Carbon::parse('2026-04-13 14:00:00'));

        $default = CajuPayAccount::create([
            'name' => 'Default zero payout fee',
            'is_default' => true,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $default->setEncryptedCredentials([
            'public_key' => 'gpk_default',
            'secret_key' => 'gsk_default',
            'cajupay_admin_fee_payout_brl' => '0',
            'cajupay_admin_fee_payout_percent' => '0',
        ]);
        $default->save();

        $secondary = CajuPayAccount::create([
            'name' => 'Secondary payout fee',
            'is_default' => false,
            'is_connected' => true,
            'is_enabled' => true,
        ]);
        $secondary->setEncryptedCredentials([
            'public_key' => 'gpk_b',
            'secret_key' => 'gsk_b',
            'cajupay_admin_fee_payout_brl' => '2,50',
            'cajupay_admin_fee_payout_percent' => '0',
        ]);
        $secondary->save();

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'cajupay_account_id' => $secondary->id,
        ])->save();

        Withdrawal::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $seller->id,
            'amount' => 102.50,
            'fee_amount' => 0,
            'net_amount' => 100.00,
            'bucket' => 'pix',
            'status' => MerchantWithdrawalService::STATUS_PAID,
            'currency' => 'BRL',
            'payout_provider' => 'cajupay',
            'payout_meta' => ['cajupay_account_id' => $secondary->id],
        ]);

        // Conta do payout: fixo 2,50 (default tem 0)
        $kpis = PlatformRevenueKpis::compute(null, null);

        $this->assertSame(2.5, $kpis['faturamento_custo_adquirente_saques']);
    }
}
