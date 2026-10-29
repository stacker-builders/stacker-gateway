<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductBundleService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class ProductBundleDeliveryTest extends TestCase
{
    private function seller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller->fresh();
    }

    private function digitalProduct(User $seller, string $name): Product
    {
        return $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => $name,
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_ONE_TIME,
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);
    }

    public function test_seller_can_attach_included_products_on_create_and_update(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);
        $seller = $this->seller();
        $courseA = $this->digitalProduct($seller, 'Curso A');
        $courseB = $this->digitalProduct($seller, 'Curso B');

        $this->actingAs($seller)
            ->post('/produtos', [
                'name' => 'Combo AB',
                'type' => Product::TYPE_AREA_MEMBROS,
                'billing_type' => Product::BILLING_ONE_TIME,
                'price' => 197,
                'currency' => 'BRL',
                'is_active' => true,
                'bundle_product_ids' => [(string) $courseA->id, (string) $courseB->id],
            ])
            ->assertRedirect(route('produtos.index'));

        $combo = Product::query()->where('tenant_id', $seller->id)->where('name', 'Combo AB')->first();
        $this->assertNotNull($combo);
        $this->assertEqualsCanonicalizing(
            [(string) $courseA->id, (string) $courseB->id],
            app(ProductBundleService::class)->includedProductIds($combo)
        );

        $this->actingAs($seller)
            ->put(route('produtos.update', $combo->id), [
                'name' => $combo->name,
                'type' => $combo->type,
                'billing_type' => $combo->billing_type,
                'price' => 197,
                'currency' => 'BRL',
                'is_active' => true,
                'bundle_product_ids' => [(string) $courseA->id],
            ])
            ->assertRedirect();

        $this->assertSame(
            [(string) $courseA->id],
            app(ProductBundleService::class)->includedProductIds($combo->fresh())
        );
    }

    public function test_cannot_nest_combos_or_include_foreign_products(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);
        $seller = $this->seller();
        $other = $this->seller();
        $own = $this->digitalProduct($seller, 'Próprio');
        $nestedParent = $this->digitalProduct($seller, 'Já é combo');
        app(ProductBundleService::class)->syncIncluded($nestedParent, [(string) $own->id]);
        $foreign = $this->digitalProduct($other, 'De outro tenant');

        $combo = $this->digitalProduct($seller, 'Combo');

        $this->actingAs($seller)
            ->put(route('produtos.update', $combo->id), [
                'name' => $combo->name,
                'type' => $combo->type,
                'billing_type' => $combo->billing_type,
                'price' => 50,
                'currency' => 'BRL',
                'is_active' => true,
                'bundle_product_ids' => [(string) $nestedParent->id],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('bundle_product_ids');

        $this->actingAs($seller)
            ->put(route('produtos.update', $combo->id), [
                'name' => $combo->name,
                'type' => $combo->type,
                'billing_type' => $combo->billing_type,
                'price' => 50,
                'currency' => 'BRL',
                'is_active' => true,
                'bundle_product_ids' => [(string) $foreign->id],
            ])
            ->assertSessionHasErrors('bundle_product_ids');
    }

    public function test_paid_combo_grants_missing_products_and_refund_keeps_prior_purchase(): void
    {
        $seller = $this->seller();
        $included = $this->digitalProduct($seller, 'Curso incluso');
        $combo = $this->digitalProduct($seller, 'Combo');
        app(ProductBundleService::class)->syncIncluded($combo, [(string) $included->id]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => $seller->id,
        ]);

        $prior = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $included->id,
            'status' => 'completed',
            'amount' => 97,
            'email' => $buyer->email,
        ]);
        OrderItem::query()->create([
            'order_id' => $prior->id,
            'product_id' => $included->id,
            'amount' => 97,
            'position' => 0,
        ]);
        $included->users()->syncWithoutDetaching([$buyer->id]);

        $comboOrder = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $combo->id,
            'status' => 'completed',
            'amount' => 197,
            'email' => $buyer->email,
        ]);
        OrderItem::query()->create([
            'order_id' => $comboOrder->id,
            'product_id' => $combo->id,
            'amount' => 197,
            'position' => 0,
        ]);
        app(ProductBundleService::class)->appendIncludedOrderItems($comboOrder, $combo);
        $comboOrder->grantPurchasedProductAccessToBuyer();

        $this->assertTrue($combo->users()->where('users.id', $buyer->id)->exists());
        $this->assertTrue($included->users()->where('users.id', $buyer->id)->exists());
        $this->assertTrue(
            $comboOrder->orderItems()->where('product_id', $included->id)->where('source', OrderItem::SOURCE_BUNDLE)->exists()
        );

        $comboOrder->revokePurchasedProductAccessFromBuyer();

        $this->assertFalse($combo->users()->where('users.id', $buyer->id)->exists());
        $this->assertTrue($included->users()->where('users.id', $buyer->id)->exists());
    }

    public function test_refund_revokes_included_product_when_it_came_only_from_the_combo(): void
    {
        $seller = $this->seller();
        $included = $this->digitalProduct($seller, 'Só no combo');
        $combo = $this->digitalProduct($seller, 'Combo');
        app(ProductBundleService::class)->syncIncluded($combo, [(string) $included->id]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => $seller->id,
        ]);

        $comboOrder = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $combo->id,
            'status' => 'completed',
            'amount' => 197,
            'email' => $buyer->email,
        ]);
        OrderItem::query()->create([
            'order_id' => $comboOrder->id,
            'product_id' => $combo->id,
            'amount' => 197,
            'position' => 0,
        ]);
        app(ProductBundleService::class)->appendIncludedOrderItems($comboOrder, $combo);
        $comboOrder->grantPurchasedProductAccessToBuyer();

        $comboOrder->revokePurchasedProductAccessFromBuyer();

        $this->assertFalse($combo->users()->where('users.id', $buyer->id)->exists());
        $this->assertFalse($included->users()->where('users.id', $buyer->id)->exists());
    }

    public function test_order_bump_combo_also_snapshots_included_products(): void
    {
        $seller = $this->seller();
        $main = $this->digitalProduct($seller, 'Principal');
        $included = $this->digitalProduct($seller, 'Incluso no bump');
        $bumpCombo = $this->digitalProduct($seller, 'Bump combo');
        app(ProductBundleService::class)->syncIncluded($bumpCombo, [(string) $included->id]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => $seller->id,
        ]);

        $order = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 150,
            'email' => $buyer->email,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 100,
            'position' => 0,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $bumpCombo->id,
            'amount' => 50,
            'position' => 1,
            'source' => OrderItem::SOURCE_ORDER_BUMP,
        ]);

        app(ProductBundleService::class)->snapshotIncludedProducts($order);

        $this->assertTrue(
            $order->orderItems()->where('product_id', $included->id)->where('source', OrderItem::SOURCE_BUNDLE)->exists()
        );
    }
}
