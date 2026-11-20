<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Support\CheckoutDisplayCurrency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpsellAfterOfferCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_resolved_config_keeps_product_upsell_when_offer_checkout_disables_it(): void
    {
        $product = new Product;
        $product->forceFill([
            'checkout_config' => [
                'upsell' => [
                    'enabled' => true,
                    'products' => [
                        ['product_id' => 'upsell-1', 'product_offer_id' => null],
                    ],
                ],
                'downsell' => [
                    'enabled' => true,
                    'product_id' => 'down-1',
                ],
                'redirect_after_purchase' => 'https://produto.example/obrigado',
            ],
        ]);

        $offer = new ProductOffer;
        $offer->forceFill([
            'checkout_config' => [
                'template' => 'original',
                'upsell' => ['enabled' => false, 'products' => []],
                'downsell' => ['enabled' => false, 'product_id' => null],
                'redirect_after_purchase' => 'https://oferta.example/obrigado',
            ],
        ]);

        $config = Product::resolvedCheckoutConfig($product, $offer, null);

        $this->assertTrue($config['upsell']['enabled']);
        $this->assertSame('upsell-1', $config['upsell']['products'][0]['product_id']);
        $this->assertTrue($config['downsell']['enabled']);
        $this->assertSame('down-1', $config['downsell']['product_id']);
        $this->assertSame('https://oferta.example/obrigado', $config['redirect_after_purchase']);
    }

    public function test_upsell_page_shows_after_purchase_on_offer_with_its_own_checkout(): void
    {
        $upsellProduct = $this->createTestProduct([
            'name' => 'Oferta extra',
            'price' => 27,
            'approval_status' => Product::APPROVAL_APPROVED,
            'admin_blocked' => false,
            'is_active' => true,
        ]);
        $product = $this->createTestProduct([
            'name' => 'Produto principal',
            'checkout_slug' => 'principal-'.Str::lower(Str::random(4)),
            'approval_status' => Product::APPROVAL_APPROVED,
            'checkout_config' => [
                'upsell' => [
                    'enabled' => true,
                    'products' => [
                        ['product_id' => (string) $upsellProduct->id, 'product_offer_id' => null],
                    ],
                ],
                'downsell' => [
                    'enabled' => true,
                    'product_id' => (string) $upsellProduct->id,
                ],
            ],
        ]);
        $offer = ProductOffer::create([
            'product_id' => $product->id,
            'name' => 'Oferta A',
            'price' => 20,
            'currency' => 'BRL',
            'checkout_slug' => Str::lower(Str::random(7)),
            'position' => 1,
            'checkout_config' => [
                'template' => 'original',
                'upsell' => ['enabled' => false, 'products' => []],
                'downsell' => ['enabled' => false, 'product_id' => null],
            ],
        ]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'product_offer_id' => $offer->id,
            'status' => 'completed',
            'amount' => 20,
            'email' => 'buyer-upsell@test.com',
            'gateway' => 'efi',
        ]);

        $token = Str::random(40);
        Cache::put('upsell_token.'.$token, [
            'order_id' => $order->id,
            'gateway' => 'efi',
        ], now()->addMinutes(60));

        $this->get('/checkout/upsell?token='.$token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Upsell')
                ->where('offers.0.product_id', (string) $upsellProduct->id)
            );

        $downToken = Str::random(40);
        Cache::put('upsell_token.'.$downToken, [
            'order_id' => $order->id,
            'gateway' => 'efi',
        ], now()->addMinutes(60));

        $this->get('/checkout/downsell?token='.$downToken)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Downsell')
                ->where('offer.product_id', (string) $upsellProduct->id)
            );
    }

    public function test_order_status_redirects_to_upsell_when_plan_checkout_has_no_upsell(): void
    {
        $upsellProduct = $this->createTestProduct([
            'name' => 'Upsell plano',
            'approval_status' => Product::APPROVAL_APPROVED,
            'admin_blocked' => false,
            'is_active' => true,
        ]);
        $product = $this->createTestProduct([
            'name' => 'Assinatura',
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'approval_status' => Product::APPROVAL_APPROVED,
            'checkout_config' => [
                'upsell' => [
                    'enabled' => true,
                    'products' => [
                        ['product_id' => (string) $upsellProduct->id],
                    ],
                ],
            ],
        ]);
        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 30,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => Str::lower(Str::random(7)),
            'position' => 0,
            'checkout_config' => [
                'template' => 'original',
                'upsell' => ['enabled' => false, 'products' => []],
            ],
        ]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'completed',
            'amount' => 30,
            'email' => 'buyer-plan@test.com',
            'payment_method' => 'pix',
        ]);

        $token = 'pix-status-'.Str::lower(Str::random(8));
        $response = $this->withSession([
            'pix_display.'.$token => ['order_id' => $order->id],
        ])->getJson('/checkout/order-status?token='.$token);

        $response->assertOk();
        $response->assertJsonPath('status', 'completed');
        $this->assertStringContainsString('/checkout/upsell?token=', (string) $response->json('redirect_url'));
    }

    public function test_upsell_and_downsell_prices_follow_checkout_display_currency(): void
    {
        $upsellProduct = $this->createTestProduct([
            'name' => 'Oferta extra',
            'price' => 50,
            'currency' => 'BRL',
            'approval_status' => Product::APPROVAL_APPROVED,
            'admin_blocked' => false,
            'is_active' => true,
        ]);
        $product = $this->createTestProduct([
            'name' => 'Produto euro',
            'approval_status' => Product::APPROVAL_APPROVED,
            'checkout_config' => [
                'upsell' => [
                    'enabled' => true,
                    'products' => [
                        ['product_id' => (string) $upsellProduct->id, 'product_offer_id' => null],
                    ],
                ],
                'downsell' => [
                    'enabled' => true,
                    'product_id' => (string) $upsellProduct->id,
                ],
            ],
        ]);
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer-eur@test.com',
            'gateway' => 'efi',
            'metadata' => ['display_currency' => 'EUR'],
        ]);

        $expected = CheckoutDisplayCurrency::formatStored(50, 'BRL', 'EUR');
        $this->assertStringNotContainsString('R$', $expected);

        $token = Str::random(40);
        Cache::put('upsell_token.'.$token, [
            'order_id' => $order->id,
            'gateway' => 'efi',
        ], now()->addMinutes(60));

        $this->get('/checkout/upsell?token='.$token)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Upsell')
                ->where('offers.0.price_formatted', $expected)
            );

        $downToken = Str::random(40);
        Cache::put('upsell_token.'.$downToken, [
            'order_id' => $order->id,
            'gateway' => 'efi',
        ], now()->addMinutes(60));

        $this->get('/checkout/downsell?token='.$downToken)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Downsell')
                ->where('offer.price_formatted', $expected)
            );
    }
}
