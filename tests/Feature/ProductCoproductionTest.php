<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\PanelNotification;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductCoproductionTest extends TestCase
{
    public function test_coproducer_invite_rejects_when_total_commission_exceeds_100(): void
    {
        if (! Schema::hasTable('product_coproducers')) {
            $this->markTestSkipped('product_coproducers table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        ProductCoproducer::query()->create([
            'product_id' => $product->id,
            'inviter_user_id' => $seller->id,
            'email' => 'a@test.com',
            'status' => ProductCoproducer::STATUS_PENDING,
            'token' => str_repeat('a', 48),
            'commission_percent' => 60,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
        ]);

        $response = $this->actingAs($seller)->post(route('produtos.coproducers.store', $product->id), [
            'email' => 'b@test.com',
            'commission_percent' => 50,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
        ]);

        $response->assertSessionHasErrors('commission_percent');
    }

    public function test_order_completed_splits_wallet_between_seller_and_coproducer(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('product_coproducers')) {
            $this->markTestSkipped('wallet or coproducers');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $coproducer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $coproducer->forceFill(['tenant_id' => $coproducer->id])->save();

        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        ProductCoproducer::query()->create([
            'product_id' => $product->id,
            'inviter_user_id' => $seller->id,
            'co_producer_user_id' => $coproducer->id,
            'email' => $coproducer->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'token' => str_repeat('b', 48),
            'commission_percent' => 30,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'accepted_at' => now()->subMinute(),
        ]);

        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        event(new OrderCompleted($order->fresh()));

        $sellerTx = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('tenant_id', $seller->id)
            ->whereIn('type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING])
            ->get();

        $coproducerTx = WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('tenant_id', $coproducer->id)
            ->whereIn('type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING])
            ->get();

        $this->assertGreaterThan(0, $sellerTx->count());
        $this->assertGreaterThan(0, $coproducerTx->count());

        $sellerGross = round((float) $sellerTx->sum('amount_gross'), 2);
        $coproducerGross = round((float) $coproducerTx->sum('amount_gross'), 2);
        $this->assertEquals(100.0, $sellerGross + $coproducerGross);
        $this->assertEqualsWithDelta(30.0, $coproducerGross, 0.02);
        $this->assertEqualsWithDelta(70.0, $sellerGross, 0.02);
    }

    public function test_order_completed_notifies_coproducer(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('product_coproducers') || ! Schema::hasTable('panel_notifications')) {
            $this->markTestSkipped('wallet, coproducers or panel_notifications');
        }

        $fixture = $this->createActiveCoproductionFixture();
        $order = $this->completeCoproductionOrder($fixture['seller'], $fixture['coproducer'], $fixture['product']);

        $this->assertTrue(
            PanelNotification::query()
                ->where('user_id', $fixture['coproducer']->id)
                ->where('type', 'coproduction_sale_approved')
                ->where('event_key', 'coproduction_sale_'.$order->id.'_'.$fixture['coproducer']->id)
                ->exists()
        );
    }

    public function test_coproducer_sees_commission_in_vendas(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('product_coproducers')) {
            $this->markTestSkipped('wallet or coproducers');
        }

        $fixture = $this->createActiveCoproductionFixture();
        $this->completeCoproductionOrder($fixture['seller'], $fixture['coproducer'], $fixture['product']);

        $response = $this->actingAs($fixture['coproducer'])->get(route('vendas.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Vendas/Index')
            ->where('has_coproduction', true)
            ->has('vendas.data', 1)
            ->where('vendas.data.0.is_coproduction_commission', true)
            ->where('vendas.data.0.commission_percent', 30)
        );
    }

    public function test_coproducer_can_view_but_not_edit_product(): void
    {
        if (! Schema::hasTable('product_coproducers')) {
            $this->markTestSkipped('product_coproducers table');
        }

        $fixture = $this->createActiveCoproductionFixture();
        $product = $fixture['product'];
        $coproducer = $fixture['coproducer'];

        $this->actingAs($coproducer)->get(route('produtos.edit', $product->id))->assertOk();

        $this->actingAs($coproducer)->get(route('produtos.index'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('produtos.data', 1)
                ->where('produtos.data.0.is_coproduction', true)
            );

        $this->actingAs($coproducer)
            ->put(route('produtos.update', $product->id), [
                'name' => 'Hacked name',
                'price' => 10,
                'type' => $product->type,
                'billing_type' => $product->billing_type,
            ])
            ->assertForbidden();
    }

    /**
     * @return array{seller: User, coproducer: User, product: \App\Models\Product}
     */
    private function createActiveCoproductionFixture(): array
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $coproducer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $coproducer->forceFill(['tenant_id' => $coproducer->id])->save();

        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Curso Co-prod']);

        ProductCoproducer::query()->create([
            'product_id' => $product->id,
            'inviter_user_id' => $seller->id,
            'co_producer_user_id' => $coproducer->id,
            'email' => $coproducer->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'token' => str_repeat('c', 48),
            'commission_percent' => 30,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'accepted_at' => now()->subMinute(),
        ]);

        return compact('seller', 'coproducer', 'product');
    }

    public function test_overdue_coproduction_is_expired_and_hidden_from_participations(): void
    {
        if (! Schema::hasTable('product_coproducers')) {
            $this->markTestSkipped('product_coproducers table');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $coproducer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $coproducer->forceFill(['tenant_id' => $coproducer->id])->save();

        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Curso Expirado']);

        $row = ProductCoproducer::query()->create([
            'product_id' => $product->id,
            'inviter_user_id' => $seller->id,
            'co_producer_user_id' => $coproducer->id,
            'email' => $coproducer->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'token' => str_repeat('e', 48),
            'commission_percent' => 40,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => '30',
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subDay(),
            'accepted_at' => now()->subDays(40),
        ]);

        $this->assertSame(1, ProductCoproducer::expireOverdue());
        $this->assertSame(ProductCoproducer::STATUS_EXPIRED, $row->fresh()->status);

        $this->actingAs($coproducer)->get(route('coproducao.index', ['tab' => 'participacoes']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Coproducao/Index')
                ->has('participations', 0)
                ->where('participation_counts.active', 0)
            );

        $this->actingAs($coproducer)->get(route('produtos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('produtos.data', 0));

        $this->actingAs($coproducer)->get(route('produtos.edit', $product->id))->assertForbidden();
    }

    public function test_expired_coproduction_does_not_block_new_invite_or_wallet_split(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('product_coproducers')) {
            $this->markTestSkipped('wallet or coproducers');
        }

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $oldCoproducer = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $oldCoproducer->forceFill(['tenant_id' => $oldCoproducer->id])->save();

        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        ProductCoproducer::query()->create([
            'product_id' => $product->id,
            'inviter_user_id' => $seller->id,
            'co_producer_user_id' => $oldCoproducer->id,
            'email' => $oldCoproducer->email,
            'status' => ProductCoproducer::STATUS_ACTIVE,
            'token' => str_repeat('f', 48),
            'commission_percent' => 60,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => '30',
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subDay(),
            'accepted_at' => now()->subDays(40),
        ]);

        $this->actingAs($seller)->post(route('produtos.coproducers.store', $product->id), [
            'email' => 'novo@test.com',
            'commission_percent' => 50,
            'commission_on_direct_sales' => true,
            'commission_on_affiliate_sales' => false,
            'duration_preset' => ProductCoproducer::DURATION_ETERNAL,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ProductCoproducer::STATUS_EXPIRED,
            ProductCoproducer::query()->where('email', $oldCoproducer->email)->value('status')
        );

        $this->artisan('coproduction:expire')->assertSuccessful();

        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);
        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        event(new OrderCompleted($order->fresh()));

        $oldCoproducerGross = round((float) WalletTransaction::query()
            ->where('order_id', $order->id)
            ->where('tenant_id', $oldCoproducer->id)
            ->whereIn('type', [WalletTransaction::TYPE_CREDIT_SALE, WalletTransaction::TYPE_CREDIT_SALE_PENDING])
            ->sum('amount_gross'), 2);

        $this->assertEquals(0.0, $oldCoproducerGross);
    }

    private function completeCoproductionOrder(User $seller, User $coproducer, $product): Order
    {
        $buyer = User::factory()->create(['role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'metadata' => [],
        ]);

        event(new OrderCompleted($order->fresh()));

        return $order->fresh();
    }
}
