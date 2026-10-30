<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\EffectiveMerchantFees;
use App\Services\OrderFeeBreakdownService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LowTicketFeesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_platform_admin_can_save_low_ticket_fee_table(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)->put(route('plataforma.financeiro.taxas.update'), [
            'merchant_fee_rules' => [
                'pix' => ['percent' => 5.0, 'fixed' => 1.0],
                'api_pix' => ['percent' => 5.0, 'fixed' => 1.0],
                'pixgo' => ['percent' => 5.0, 'fixed' => 1.0],
                'open_finance' => ['percent' => 5.0, 'fixed' => 1.0],
                'card' => ['percent' => 6.0, 'fixed' => 1.0],
                'apple_pay' => ['percent' => 6.0, 'fixed' => 1.0],
                'google_pay' => ['percent' => 6.0, 'fixed' => 1.0],
                'boleto' => ['percent' => 5.0, 'fixed' => 1.0],
                'withdrawal' => ['percent' => 1.0, 'fixed' => 0.0],
            ],
            'low_ticket_enabled' => true,
            'low_ticket_max_amount' => 20,
            'low_ticket_max_order_amount' => 50,
            'low_ticket_fee_rules' => [
                'pix' => ['percent' => 1.5, 'fixed' => 0.2],
                'api_pix' => ['percent' => 1.5, 'fixed' => 0.2],
                'card' => ['percent' => 2.0, 'fixed' => 0.3],
            ],
            'api_pix_enabled' => true,
        ])->assertRedirect();

        $defaults = EffectiveMerchantFees::platformDefaults();
        $this->assertTrue($defaults['low_ticket']['enabled']);
        $this->assertSame(20.0, $defaults['low_ticket']['max_amount']);
        $this->assertSame(50.0, $defaults['low_ticket']['max_order_amount']);
        $this->assertSame(1.5, $defaults['low_ticket']['pix']['percent']);
        $this->assertSame(0.2, $defaults['low_ticket']['pix']['fixed']);
        $this->assertSame(2.0, $defaults['low_ticket']['card']['percent']);
        $this->assertTrue($defaults['low_ticket']['max_order_amount_enabled']);
        $this->assertTrue($defaults['low_ticket']['pix']['enabled']);
        $this->assertTrue($defaults['low_ticket']['card']['enabled']);
    }

    public function test_platform_admin_can_save_order_cap_and_method_toggles(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)->put(route('plataforma.financeiro.taxas.update'), [
            'merchant_fee_rules' => [
                'pix' => ['percent' => 5.0, 'fixed' => 1.0],
                'api_pix' => ['percent' => 5.0, 'fixed' => 1.0],
                'pixgo' => ['percent' => 5.0, 'fixed' => 1.0],
                'open_finance' => ['percent' => 5.0, 'fixed' => 1.0],
                'card' => ['percent' => 6.0, 'fixed' => 1.0],
                'apple_pay' => ['percent' => 6.0, 'fixed' => 1.0],
                'google_pay' => ['percent' => 6.0, 'fixed' => 1.0],
                'boleto' => ['percent' => 5.0, 'fixed' => 1.0],
                'withdrawal' => ['percent' => 1.0, 'fixed' => 0.0],
            ],
            'low_ticket_enabled' => true,
            'low_ticket_max_amount' => 20,
            'low_ticket_max_order_amount' => 50,
            'low_ticket_max_order_amount_enabled' => false,
            'low_ticket_fee_rules' => [
                'pix' => ['percent' => 1.5, 'fixed' => 0.2, 'enabled' => true],
                'api_pix' => ['percent' => 1.5, 'fixed' => 0.2, 'enabled' => true],
                'card' => ['percent' => 2.0, 'fixed' => 0.3, 'enabled' => false],
                'boleto' => ['percent' => 1.0, 'fixed' => 0.1, 'enabled' => false],
            ],
            'api_pix_enabled' => true,
        ])->assertRedirect();

        $defaults = EffectiveMerchantFees::platformDefaults();
        $this->assertTrue($defaults['low_ticket']['enabled']);
        $this->assertSame(50.0, $defaults['low_ticket']['max_order_amount']);
        $this->assertFalse($defaults['low_ticket']['max_order_amount_enabled']);
        $this->assertTrue($defaults['low_ticket']['pix']['enabled']);
        $this->assertFalse($defaults['low_ticket']['card']['enabled']);
        $this->assertFalse($defaults['low_ticket']['boleto']['enabled']);
        $this->assertTrue($defaults['low_ticket']['apple_pay']['enabled']);
    }

    public function test_low_ticket_uses_product_amount_not_order_total(): void
    {
        $this->seedLowTicketRules();
        $seller = $this->infoprodutor();

        $withBump = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 45.0, 'checkout', null, 15.0);
        $this->assertSame(1.5, $withBump['percent']);
        $this->assertSame(0.2, $withBump['fixed']);
        $this->assertEqualsWithDelta(0.88, $withBump['fee'], 0.01);

        $aboveCap = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 45.0, 'checkout', null, 25.0);
        $this->assertSame(5.0, $aboveCap['percent']);
        $this->assertSame(1.0, $aboveCap['fixed']);
        $this->assertEqualsWithDelta(3.25, $aboveCap['fee'], 0.01);
    }

    public function test_low_ticket_ignored_without_product_amount(): void
    {
        $this->seedLowTicketRules();
        $seller = $this->infoprodutor();

        $calc = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 15.0);
        $this->assertSame(5.0, $calc['percent']);
        $this->assertSame(1.0, $calc['fixed']);
    }

    public function test_credited_order_keeps_original_fee_after_low_ticket_is_enabled(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet tables');
        }

        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 5.0, 'fixed' => 1.0],
            'api_pix' => ['percent' => 5.0, 'fixed' => 1.0],
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

        $seller = $this->infoprodutor();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'price' => 15]);
        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 15,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'efi',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'amount' => 15,
            'position' => 0,
        ]);

        event(new OrderCompleted($order->fresh()));

        $before = OrderFeeBreakdownService::forOrder($order->fresh());
        $this->assertTrue($before['from_wallet']);
        $this->assertEqualsWithDelta(1.75, $before['fee'], 0.01);

        $this->seedLowTicketRules();

        $after = OrderFeeBreakdownService::forOrder($order->fresh());
        $this->assertTrue($after['from_wallet']);
        $this->assertEqualsWithDelta($before['fee'], $after['fee'], 0.01);
        $this->assertEqualsWithDelta($before['net'], $after['net'], 0.01);
    }

    public function test_new_order_with_bump_uses_low_ticket_when_product_is_under_cap(): void
    {
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('tenant_wallets')) {
            $this->markTestSkipped('wallet tables');
        }
        if (! Schema::hasTable('order_items')) {
            $this->markTestSkipped('order_items table');
        }

        $this->seedLowTicketRules();
        Setting::set('merchant_settlement_rules', [
            'pix' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'card' => ['days_to_available' => 0, 'reserve_percent' => 0],
            'boleto' => ['days_to_available' => 0, 'reserve_percent' => 0],
        ], null);

        $seller = $this->infoprodutor();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'price' => 15]);
        $bump = $this->createTestProduct(['tenant_id' => $seller->id, 'price' => 30]);
        $order = Order::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 45,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'efi',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'amount' => 15,
            'position' => 0,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $bump->id,
            'amount' => 30,
            'position' => 1,
        ]);

        $this->assertSame(15.0, $order->fresh()->mainProductAmount());
        $this->assertSame(45.0, $order->fresh()->mainPlusBumpsAmount());

        event(new OrderCompleted($order->fresh()));

        $breakdown = OrderFeeBreakdownService::forOrder($order->fresh());
        $this->assertTrue($breakdown['from_wallet']);
        $this->assertEqualsWithDelta(45.0, $breakdown['gross'], 0.01);
        $this->assertEqualsWithDelta(0.88, $breakdown['fee'], 0.01);
    }

    public function test_low_ticket_stays_when_product_plus_bumps_is_below_order_cap(): void
    {
        $this->seedLowTicketRules(50.0);
        $seller = $this->infoprodutor();

        $calc = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 45.0, 'checkout', null, 15.0, 45.0);
        $this->assertSame(1.5, $calc['percent']);
        $this->assertSame(0.2, $calc['fixed']);
        $this->assertEqualsWithDelta(0.88, $calc['fee'], 0.01);
    }

    public function test_normal_fee_when_product_plus_bumps_reaches_order_cap(): void
    {
        $this->seedLowTicketRules(50.0);
        $seller = $this->infoprodutor();

        $atCap = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 50.0, 'checkout', null, 15.0, 50.0);
        $this->assertSame(5.0, $atCap['percent']);
        $this->assertSame(1.0, $atCap['fixed']);
        $this->assertEqualsWithDelta(3.50, $atCap['fee'], 0.01);

        $aboveCap = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 55.0, 'checkout', null, 15.0, 55.0);
        $this->assertSame(5.0, $aboveCap['percent']);
        $this->assertSame(1.0, $aboveCap['fixed']);
    }

    public function test_order_cap_zero_does_not_limit_bumps(): void
    {
        $this->seedLowTicketRules(0.0);
        $seller = $this->infoprodutor();

        $calc = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 80.0, 'checkout', null, 15.0, 80.0);
        $this->assertSame(1.5, $calc['percent']);
        $this->assertSame(0.2, $calc['fixed']);
    }

    public function test_order_cap_ignored_when_toggle_is_off(): void
    {
        $this->seedLowTicketRules(50.0, false);
        $seller = $this->infoprodutor();

        $calc = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 80.0, 'checkout', null, 15.0, 80.0);
        $this->assertSame(1.5, $calc['percent']);
        $this->assertSame(0.2, $calc['fixed']);
        $this->assertEqualsWithDelta(1.40, $calc['fee'], 0.01);
    }

    public function test_disabled_payment_method_keeps_normal_fee(): void
    {
        $this->seedLowTicketRules(0.0, null, [
            'pix' => ['percent' => 1.5, 'fixed' => 0.2, 'enabled' => true],
            'card' => ['percent' => 2.0, 'fixed' => 0.3, 'enabled' => false],
        ]);
        $seller = $this->infoprodutor();

        $pix = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'pix', 15.0, 'checkout', null, 15.0, 15.0);
        $this->assertSame(1.5, $pix['percent']);
        $this->assertSame(0.2, $pix['fixed']);

        $card = EffectiveMerchantFees::calculateSaleFee((int) $seller->id, 'card', 15.0, 'checkout', null, 15.0, 15.0);
        $this->assertSame(6.0, $card['percent']);
        $this->assertSame(1.0, $card['fixed']);
    }

    public function test_legacy_low_ticket_config_keeps_methods_and_order_cap_on(): void
    {
        $this->seedLowTicketRules(50.0);
        $defaults = EffectiveMerchantFees::platformDefaults();

        $this->assertTrue($defaults['low_ticket']['max_order_amount_enabled']);
        $this->assertTrue($defaults['low_ticket']['pix']['enabled']);
        $this->assertTrue($defaults['low_ticket']['card']['enabled']);
        $this->assertTrue($defaults['low_ticket']['boleto']['enabled']);
    }

    /**
     * @param  array<string, array{percent?: float, fixed?: float, enabled?: bool}>  $methodOverrides
     */
    private function seedLowTicketRules(float $maxOrderAmount = 0.0, ?bool $maxOrderAmountEnabled = null, array $methodOverrides = []): void
    {
        $lowTicket = [
            'enabled' => true,
            'max_amount' => 20.0,
            'max_order_amount' => $maxOrderAmount,
            'pix' => ['percent' => 1.5, 'fixed' => 0.2],
            'api_pix' => ['percent' => 1.5, 'fixed' => 0.2],
            'card' => ['percent' => 2.0, 'fixed' => 0.3],
        ];
        if ($maxOrderAmountEnabled !== null) {
            $lowTicket['max_order_amount_enabled'] = $maxOrderAmountEnabled;
        }
        foreach ($methodOverrides as $key => $block) {
            $lowTicket[$key] = array_merge($lowTicket[$key] ?? [], $block);
        }

        Setting::set('merchant_fee_rules', [
            'pix' => ['percent' => 5.0, 'fixed' => 1.0],
            'api_pix' => ['percent' => 5.0, 'fixed' => 1.0],
            'pixgo' => ['percent' => 5.0, 'fixed' => 1.0],
            'open_finance' => ['percent' => 5.0, 'fixed' => 1.0],
            'card' => ['percent' => 6.0, 'fixed' => 1.0],
            'apple_pay' => ['percent' => 6.0, 'fixed' => 1.0],
            'google_pay' => ['percent' => 6.0, 'fixed' => 1.0],
            'boleto' => ['percent' => 5.0, 'fixed' => 1.0],
            'withdrawal' => ['percent' => 1.0, 'fixed' => 0.0],
            'low_ticket' => $lowTicket,
        ], null);
    }

    private function infoprodutor(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'account_status' => 'approved',
        ])->save();

        return $seller->fresh();
    }
}
