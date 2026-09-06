<?php

namespace Tests\Unit;

use App\Models\ProductAffiliateEnrollment;
use App\Models\ProductOffer;
use App\Support\AffiliateCheckoutLinks;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AffiliateCheckoutLinksTest extends TestCase
{
    public function test_links_include_main_and_shared_offers_only(): void
    {
        if (! Schema::hasTable('product_offers') || ! Schema::hasColumn('product_offers', 'affiliate_share_enabled')) {
            $this->markTestSkipped('affiliate_share_enabled');
        }

        $product = $this->createTestProduct([
            'checkout_slug' => 'prodslug',
            'price' => 100,
            'currency' => 'BRL',
        ]);

        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Compartilhada',
            'price' => 80,
            'currency' => 'BRL',
            'checkout_slug' => 'share'.substr(uniqid('', true), 0, 8),
            'position' => 0,
            'affiliate_share_enabled' => true,
        ]);
        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Exclusiva',
            'price' => 50,
            'currency' => 'BRL',
            'checkout_slug' => 'offslug1',
            'position' => 1,
            'affiliate_share_enabled' => true,
        ]);
        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Oculta',
            'price' => 30,
            'currency' => 'BRL',
            'checkout_slug' => 'hidden'.substr(uniqid('', true), 0, 8),
            'position' => 2,
            'affiliate_share_enabled' => false,
        ]);

        $enrollment = new ProductAffiliateEnrollment([
            'product_id' => $product->id,
            'public_ref' => 'abcREF',
        ]);

        $links = AffiliateCheckoutLinks::linksForEnrollment($product->fresh(['offers']), $enrollment);

        $this->assertCount(3, $links);
        $this->assertSame('main', $links[0]['type']);
        $this->assertStringContainsString('/c/prodslug?ref=abcREF', $links[0]['url']);
        $this->assertSame('offer', $links[1]['type']);
        $this->assertStringContainsString('ref=abcREF', $links[1]['url']);
        $this->assertSame('offer', $links[2]['type']);
        $this->assertStringContainsString('/c/offslug1?ref=abcREF', $links[2]['url']);
        $this->assertFalse(collect($links)->contains(fn ($l) => ($l['label'] ?? '') === 'Oculta'));
    }

    public function test_links_include_all_offers_when_none_were_explicitly_shared(): void
    {
        if (! Schema::hasTable('product_offers') || ! Schema::hasColumn('product_offers', 'affiliate_share_enabled')) {
            $this->markTestSkipped('affiliate_share_enabled');
        }

        $product = $this->createTestProduct([
            'checkout_slug' => 'alloff',
            'price' => 100,
            'currency' => 'BRL',
        ]);

        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Oferta A',
            'price' => 80,
            'currency' => 'BRL',
            'checkout_slug' => 'offa'.substr(uniqid('', true), 0, 8),
            'position' => 0,
            'affiliate_share_enabled' => false,
        ]);
        ProductOffer::query()->create([
            'product_id' => $product->id,
            'name' => 'Oferta B',
            'price' => 50,
            'currency' => 'BRL',
            'checkout_slug' => 'offb'.substr(uniqid('', true), 0, 8),
            'position' => 1,
            'affiliate_share_enabled' => false,
        ]);

        $enrollment = new ProductAffiliateEnrollment([
            'product_id' => $product->id,
            'public_ref' => 'refAll',
        ]);

        $links = AffiliateCheckoutLinks::linksForEnrollment($product->fresh(['offers']), $enrollment);
        $offerLabels = collect($links)->where('type', 'offer')->pluck('label')->all();

        $this->assertSame('main', $links[0]['type']);
        $this->assertEqualsCanonicalizing(['Oferta A', 'Oferta B'], $offerLabels);
    }

    public function test_links_include_subscription_plans(): void
    {
        if (! Schema::hasTable('subscription_plans')) {
            $this->markTestSkipped('subscription_plans');
        }

        $product = $this->createTestProduct([
            'checkout_slug' => 'subprod',
            'billing_type' => 'subscription',
            'price' => 97,
            'currency' => 'BRL',
        ]);

        \App\Models\SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 97,
            'currency' => 'BRL',
            'interval' => 'monthly',
            'checkout_slug' => 'mens'.substr(uniqid('', true), 0, 8),
            'position' => 0,
        ]);
        \App\Models\SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Anual',
            'price' => 970,
            'currency' => 'BRL',
            'interval' => 'annual',
            'checkout_slug' => 'anu'.substr(uniqid('', true), 0, 8),
            'position' => 1,
        ]);

        $enrollment = new ProductAffiliateEnrollment([
            'product_id' => $product->id,
            'public_ref' => 'refPlan',
        ]);

        $links = AffiliateCheckoutLinks::linksForEnrollment($product->fresh(['subscriptionPlans']), $enrollment);
        $planLinks = collect($links)->where('type', 'plan');

        $this->assertSame(2, $planLinks->count());
        $this->assertTrue($planLinks->contains(fn ($l) => str_contains((string) $l['label'], 'Mensal')));
        $this->assertTrue($planLinks->contains(fn ($l) => str_contains((string) $l['label'], 'Anual')));
        $this->assertTrue($planLinks->every(fn ($l) => str_contains((string) $l['url'], 'ref=refPlan')));
    }

    public function test_append_ref_uses_ampersand_when_query_exists(): void
    {
        $url = AffiliateCheckoutLinks::appendRef('https://example.test/c/x?offer_id=9', 'r1');
        $this->assertSame('https://example.test/c/x?offer_id=9&ref=r1', $url);
    }
}
