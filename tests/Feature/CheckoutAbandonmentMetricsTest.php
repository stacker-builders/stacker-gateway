<?php

namespace Tests\Feature;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;
use App\Services\Checkout\CheckoutAbandonmentMetrics;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CheckoutAbandonmentMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-28 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recent_visit_is_not_abandonment_eligible(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'abvis1']);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'visit-recent-'.uniqid(),
            'step' => CheckoutSession::STEP_VISIT,
        ]);
        $session->created_at = now()->subMinutes(5);
        $session->saveQuietly();

        $this->assertSame(0, CheckoutSession::whereAbandonmentVisitEligible()->count());
    }

    public function test_old_visit_without_order_is_abandonment_eligible(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'abvis2']);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'visit-old-'.uniqid(),
            'step' => CheckoutSession::STEP_VISIT,
        ]);
        $session->created_at = now()->subMinutes(15);
        $session->saveQuietly();

        $this->assertSame(1, CheckoutSession::whereAbandonmentVisitEligible()->count());
    }

    public function test_recent_form_filled_is_not_abandonment_eligible(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'abfrm1']);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'form-recent-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'form_started_at' => now()->subMinutes(2),
            'form_filled_at' => now()->subMinute(),
        ]);

        $this->assertSame(0, CheckoutSession::whereAbandonmentFormEligible()->count());
    }

    public function test_old_form_filled_is_abandonment_eligible(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'abfrm2']);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'form-old-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->assertSame(1, CheckoutSession::whereAbandonmentFormEligible()->count());
    }

    public function test_track_sets_form_timestamps(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'abtrk1']);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'track-ts-'.uniqid(),
            'step' => CheckoutSession::STEP_VISIT,
        ]);

        $this->postJson('/api/checkout/track', [
            'session_token' => $session->session_token,
            'step' => 'form_filled',
            'email' => 'buyer@example.com',
            'name' => 'Comprador',
        ])->assertOk();

        $session->refresh();
        $this->assertNotNull($session->form_started_at);
        $this->assertNotNull($session->form_filled_at);
    }

    public function test_checkout_preview_does_not_create_session(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $this->createTestProduct([
            'checkout_slug' => 'abprev',
            'checkout_config' => ['deliverable_link' => 'https://example.com'],
        ]);

        $countBefore = CheckoutSession::count();

        $this->get(route('checkout.show', ['slug' => 'abprev', 'preview' => '1']))
            ->assertOk();

        $this->assertSame($countBefore, CheckoutSession::count());
    }

    public function test_funnel_conversion_counts_only_completed_orders(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'abconv']);
        $buyer = User::factory()->create();

        $pendingOrder = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'amount' => 99.0,
            'status' => 'pending',
            'payment_method' => 'pix',
        ]);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'conv-pending-'.uniqid(),
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $pendingOrder->id,
        ]);

        $this->assertSame(0, CheckoutSession::whereFunnelConversionCompleted()->count());

        $completedOrder = Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'amount' => 149.0,
            'status' => 'completed',
            'payment_method' => 'pix',
        ]);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'conv-done-'.uniqid(),
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $completedOrder->id,
        ]);

        $this->assertSame(1, CheckoutSession::whereFunnelConversionCompleted()->count());
    }

    public function test_checkout_without_preview_creates_session(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $this->createTestProduct([
            'checkout_slug' => 'abreal',
            'checkout_config' => ['deliverable_link' => 'https://example.com'],
        ]);

        $countBefore = CheckoutSession::count();

        $this->get(route('checkout.show', ['slug' => 'abreal']))
            ->assertOk();

        $this->assertSame($countBefore + 1, CheckoutSession::count());
    }

    public function test_valid_metrics_excludes_visit_without_email(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'abvalid1']);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'visit-noemail-'.uniqid(),
            'step' => CheckoutSession::STEP_VISIT,
        ]);
        $session->created_at = now()->subMinutes(20);
        $session->saveQuietly();

        $metrics = app(CheckoutAbandonmentMetrics::class);
        $this->assertSame(0, $metrics->countValidAbandoned(1, null, null, null));
    }

    public function test_valid_metrics_deduplicates_same_email_and_product(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'abdedup1']);

        foreach (['reload-a', 'reload-b'] as $token) {
            CheckoutSession::create([
                'tenant_id' => 1,
                'product_id' => $product->id,
                'checkout_slug' => $product->checkout_slug,
                'session_token' => $token,
                'step' => CheckoutSession::STEP_FORM_FILLED,
                'email' => 'lead@example.com',
                'form_started_at' => now()->subMinutes(20),
                'form_filled_at' => now()->subMinutes(15),
            ]);
        }

        $metrics = app(CheckoutAbandonmentMetrics::class);
        $this->assertSame(1, $metrics->countValidAbandoned(1, null, null, null));
    }

    public function test_valid_metrics_counts_same_email_on_different_products(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $productA = $this->createTestProduct(['checkout_slug' => 'abdedup-a', 'name' => 'Produto A']);
        $productB = $this->createTestProduct(['checkout_slug' => 'abdedup-b', 'name' => 'Produto B']);

        foreach ([$productA, $productB] as $product) {
            CheckoutSession::create([
                'tenant_id' => 1,
                'product_id' => $product->id,
                'checkout_slug' => $product->checkout_slug,
                'session_token' => 'dedup-'.$product->id,
                'step' => CheckoutSession::STEP_FORM_FILLED,
                'email' => 'lead@example.com',
                'form_started_at' => now()->subMinutes(20),
                'form_filled_at' => now()->subMinutes(15),
            ]);
        }

        $metrics = app(CheckoutAbandonmentMetrics::class);
        $this->assertSame(2, $metrics->countValidAbandoned(1, null, null, null));
    }

    public function test_valid_metrics_uses_last_activity_for_period_not_created_at(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'abperiod1']);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'period-act-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'period@example.com',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);
        $session->created_at = now()->subDays(20);
        $session->saveQuietly();

        $start = now()->startOfDay()->toDateTimeString();
        $end = now()->endOfDay()->toDateTimeString();

        $metrics = app(CheckoutAbandonmentMetrics::class);
        $this->assertSame(1, $metrics->countValidAbandoned(1, null, $start, $end));
    }

    public function test_dashboard_abandono_carrinho_uses_valid_deduplicated_count(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
            'kyc_status' => User::KYC_APPROVED,
        ]);
        $product = $this->createTestProduct(['checkout_slug' => 'abdash1']);

        foreach (['dash-a', 'dash-b'] as $token) {
            CheckoutSession::create([
                'tenant_id' => 1,
                'product_id' => $product->id,
                'checkout_slug' => $product->checkout_slug,
                'session_token' => $token,
                'step' => CheckoutSession::STEP_FORM_FILLED,
                'email' => 'dash@example.com',
                'form_started_at' => now()->subMinutes(20),
                'form_filled_at' => now()->subMinutes(15),
            ]);
        }

        $response = $this->actingAs($seller)
            ->get(route('dashboard', ['period' => 'total']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('abandono_carrinho', 1)
            ->where('taxa_abandono_compras', 100)
            ->where('compras_periodo', 0)
        );
    }
}
