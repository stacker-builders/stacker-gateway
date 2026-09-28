<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class ProductOfferCheckoutDeleteTest extends TestCase
{
    private function createApprovedSeller(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        return $seller;
    }

    public function test_removing_exclusive_offer_checkout_clears_slug(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $seller = $this->createApprovedSeller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'billing_type' => Product::BILLING_ONE_TIME,
        ]);
        $offer = ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Oferta existente',
            'price' => 19.90,
            'currency' => 'BRL',
            'checkout_slug' => 'ofr'.substr(uniqid(), -4),
            'position' => 1,
        ]);

        $response = $this->actingAs($seller)->delete(
            route('produtos.checkout.remove-slug', $product),
            ['type' => 'offer', 'offer_id' => $offer->id]
        );

        $response->assertRedirect();
        $this->assertNull($offer->fresh()->checkout_slug);
    }

    public function test_two_offers_can_share_null_checkout_slug_but_not_the_same_slug(): void
    {
        $product = $this->createTestProduct([
            'billing_type' => Product::BILLING_ONE_TIME,
        ]);

        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Sem checkout exclusivo A',
            'price' => 10,
            'currency' => 'BRL',
            'checkout_slug' => null,
            'position' => 1,
        ]);
        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Sem checkout exclusivo B',
            'price' => 20,
            'currency' => 'BRL',
            'checkout_slug' => null,
            'position' => 2,
        ]);

        $this->assertSame(2, ProductOffer::query()->where('product_id', $product->id)->whereNull('checkout_slug')->count());

        $slug = 'same'.substr(uniqid(), -3);
        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Com slug',
            'price' => 30,
            'currency' => 'BRL',
            'checkout_slug' => $slug,
            'position' => 3,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Slug duplicado',
            'price' => 40,
            'currency' => 'BRL',
            'checkout_slug' => $slug,
            'position' => 4,
        ]);
    }

    public function test_destroying_offer_removes_the_row(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $seller = $this->createApprovedSeller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'billing_type' => Product::BILLING_ONE_TIME,
        ]);
        $offer = ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Oferta para excluir',
            'price' => 15,
            'currency' => 'BRL',
            'checkout_slug' => 'del'.substr(uniqid(), -4),
            'position' => 1,
        ]);

        $response = $this->actingAs($seller)->delete(
            route('produtos.offers.destroy', [$product, $offer])
        );

        $response->assertRedirect();
        $this->assertDatabaseMissing('product_offers', ['id' => $offer->id]);
    }

    public function test_removing_exclusive_plan_checkout_clears_slug(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);

        $seller = $this->createApprovedSeller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
        ]);
        $plan = SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Plano mensal',
            'price' => 29.90,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'pln'.substr(uniqid(), -4),
            'position' => 1,
        ]);

        $response = $this->actingAs($seller)->delete(
            route('produtos.checkout.remove-slug', $product),
            ['type' => 'plan', 'plan_id' => $plan->id]
        );

        $response->assertRedirect();
        $this->assertNull($plan->fresh()->checkout_slug);
    }
};
