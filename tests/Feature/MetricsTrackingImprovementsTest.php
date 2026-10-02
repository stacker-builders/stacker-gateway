<?php

namespace Tests\Feature;

use App\Events\BoletoGenerated;
use App\Http\Middleware\EnsureInstalled;
use App\Models\CheckoutSession;
use App\Models\MetricsEvent;
use App\Models\MetricsSession;
use App\Models\Order;
use App\Models\User;
use App\Services\MetricsTracking\MetricsAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetricsTrackingImprovementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_collect_deduplicates_checkout_view_by_event_id(): void
    {
        $sessionKey = (string) Str::uuid();
        $visitorKey = (string) Str::uuid();
        $eventId = 'chk-view:'.(string) Str::uuid();

        $payload = [
            'event_name' => MetricsEvent::CHECKOUT_VIEW,
            'event_id' => $eventId,
            'session_key' => $sessionKey,
            'visitor_key' => $visitorKey,
            'product_id' => (string) Str::uuid(),
            'tenant_id' => 1,
        ];

        $this->postJson('/api/metrics/collect', $payload)->assertOk();
        $this->postJson('/api/metrics/collect', $payload)->assertOk();

        $this->assertSame(1, MetricsEvent::query()->where('event_id', $eventId)->count());
        $this->assertSame(1, MetricsEvent::query()->where('event_name', MetricsEvent::CHECKOUT_VIEW)->count());
    }

    public function test_checkout_view_is_not_counted_as_click(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'America/Sao_Paulo'));

        $tenantId = 91;
        $sessionKey = (string) Str::uuid();
        $visitorKey = (string) Str::uuid();

        MetricsSession::query()->create([
            'session_key' => $sessionKey,
            'visitor_key' => $visitorKey,
            'tenant_id' => $tenantId,
            'first_touch_at' => now(),
            'last_touch_at' => now(),
            'clicks_count' => 9,
        ]);

        $this->insertEvent($tenantId, $sessionKey, $visitorKey, MetricsEvent::CHECKOUT_VIEW, now());
        $this->insertEvent($tenantId, $sessionKey, $visitorKey, MetricsEvent::PAGE_VIEW, now());
        $this->insertEvent($tenantId, $sessionKey, $visitorKey, MetricsEvent::LINK_CLICKED, now());

        $service = app(MetricsAnalyticsService::class);
        $request = Request::create('/', 'GET', ['period' => 'hoje']);
        [$start, $end] = $service->resolveDateRange($request, 'hoje');
        $summary = $service->summary($tenantId, $start, $end, []);

        $this->assertSame(2, $summary['clicks']);
        $this->assertSame(1, $summary['checkout_views']);
        $this->assertSame(1, $summary['unique_visitors']);
    }

    public function test_funnel_counts_unique_visitors_per_step_and_keeps_card_payments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'America/Sao_Paulo'));

        $tenantId = 92;
        $visitorA = (string) Str::uuid();
        $visitorB = (string) Str::uuid();
        $sessionA = (string) Str::uuid();
        $sessionB = (string) Str::uuid();

        foreach ([$sessionA => $visitorA, $sessionB => $visitorB] as $sessionKey => $visitorKey) {
            MetricsSession::query()->create([
                'session_key' => $sessionKey,
                'visitor_key' => $visitorKey,
                'tenant_id' => $tenantId,
                'first_touch_at' => now(),
                'last_touch_at' => now(),
            ]);
        }

        $this->insertEvent($tenantId, $sessionA, $visitorA, MetricsEvent::CHECKOUT_VIEW, now());
        $this->insertEvent($tenantId, $sessionA, $visitorA, MetricsEvent::CHECKOUT_VIEW, now()->copy()->addMinute());
        $this->insertEvent($tenantId, $sessionA, $visitorA, MetricsEvent::CHECKOUT_FORM_STARTED, now());
        $this->insertEvent($tenantId, $sessionA, $visitorA, MetricsEvent::CHECKOUT_STARTED, now());
        $this->insertEvent($tenantId, $sessionA, $visitorA, MetricsEvent::PAYMENT_PENDING, now());
        $this->insertEvent($tenantId, $sessionA, $visitorA, MetricsEvent::PAYMENT_APPROVED, now(), 80);

        $this->insertEvent($tenantId, $sessionB, $visitorB, MetricsEvent::CHECKOUT_VIEW, now());
        $this->insertEvent($tenantId, $sessionB, $visitorB, MetricsEvent::CHECKOUT_FORM_STARTED, now());
        $this->insertEvent($tenantId, $sessionB, $visitorB, MetricsEvent::CHECKOUT_STARTED, now());
        $this->insertEvent($tenantId, $sessionB, $visitorB, MetricsEvent::PIX_CREATED, now());
        $this->insertEvent($tenantId, $sessionB, $visitorB, MetricsEvent::PAYMENT_APPROVED, now(), 50);

        $service = app(MetricsAnalyticsService::class);
        $request = Request::create('/', 'GET', ['period' => 'hoje']);
        [$start, $end] = $service->resolveDateRange($request, 'hoje');
        $funnel = $service->funnel($tenantId, $start, $end, []);
        $byKey = collect($funnel['steps'])->keyBy('key');

        $this->assertSame(2, $byKey['visitors']['value']);
        $this->assertSame(2, $byKey['checkout_views']['value']);
        $this->assertSame(2, $byKey['checkouts_form_started']['value']);
        $this->assertSame(2, $byKey['checkouts_started']['value']);
        $this->assertSame(2, $byKey['payments_initiated']['value']);
        $this->assertSame(1, $byKey['pix_created']['value']);
        $this->assertSame(2, $byKey['approved']['value']);
        $this->assertArrayNotHasKey('clicks', $byKey->all());
        $this->assertEquals(100.0, $funnel['final_conversion_rate']);
    }

    public function test_summary_exposes_pix_conversion_and_card_approval_rates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-12 12:00:00', 'America/Sao_Paulo'));

        $tenantId = 93;
        $visitor = (string) Str::uuid();
        $session = (string) Str::uuid();

        MetricsSession::query()->create([
            'session_key' => $session,
            'visitor_key' => $visitor,
            'tenant_id' => $tenantId,
            'first_touch_at' => now(),
            'last_touch_at' => now(),
        ]);

        // 2 PIX gerados, 1 pago
        $this->insertEvent($tenantId, $session, $visitor, MetricsEvent::PIX_CREATED, now(), null, ['payment_method' => 'pix']);
        $this->insertEvent($tenantId, $session, $visitor, MetricsEvent::PIX_CREATED, now()->copy()->addSecond(), null, ['payment_method' => 'pix']);
        $this->insertEvent($tenantId, $session, $visitor, MetricsEvent::PAYMENT_APPROVED, now(), 50, ['payment_method' => 'pix']);

        // Cartão: 2 aprovados, 1 recusado → 66.67%
        $this->insertEvent($tenantId, $session, $visitor, MetricsEvent::PAYMENT_APPROVED, now(), 80, ['payment_method' => 'card']);
        $this->insertEvent($tenantId, $session, $visitor, MetricsEvent::PAYMENT_APPROVED, now(), 90, ['payment_method' => 'credit_card']);
        $this->insertEvent($tenantId, $session, $visitor, MetricsEvent::PAYMENT_REFUSED, now(), 70, ['payment_method' => 'card']);

        $service = app(MetricsAnalyticsService::class);
        $request = Request::create('/', 'GET', ['period' => 'hoje']);
        [$start, $end] = $service->resolveDateRange($request, 'hoje');
        $summary = $service->summary($tenantId, $start, $end, []);

        $this->assertSame(2, $summary['pix_created']);
        $this->assertSame(1, $summary['pix_paid']);
        $this->assertEquals(50.0, $summary['pix_conversion_rate']);
        $this->assertSame(2, $summary['card_approved']);
        $this->assertSame(1, $summary['card_refused']);
        $this->assertSame(3, $summary['card_attempts']);
        $this->assertEquals(66.67, $summary['card_approval_rate']);
        $this->assertSame(1, $summary['payments_refused']);
    }

    public function test_form_started_creates_deduped_metrics_event(): void
    {
        Queue::fake();

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'checkout_slug' => 'formtrk1',
        ]);
        $sessionKey = (string) Str::uuid();

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'form-token-'.uniqid(),
            'step' => CheckoutSession::STEP_VISIT,
            'metrics_session_key' => $sessionKey,
        ]);

        $this->postJson('/api/checkout/track', [
            'session_token' => $session->session_token,
            'step' => 'form_started',
            'email' => 'buyer@example.com',
            'name' => 'Comprador Teste',
        ])->assertOk();

        $this->postJson('/api/checkout/track', [
            'session_token' => $session->session_token,
            'step' => 'form_started',
            'email' => 'buyer@example.com',
            'name' => 'Comprador Teste',
        ])->assertOk();

        $this->assertSame(1, MetricsEvent::query()
            ->where('event_name', MetricsEvent::CHECKOUT_FORM_STARTED)
            ->where('event_id', 'chk-form:'.$session->session_token)
            ->count());
    }

    public function test_boleto_generated_records_metrics_event(): void
    {
        Queue::fake();

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $sessionKey = (string) Str::uuid();
        $visitorKey = (string) Str::uuid();

        MetricsSession::query()->create([
            'session_key' => $sessionKey,
            'visitor_key' => $visitorKey,
            'tenant_id' => 1,
            'product_id' => $product->id,
            'first_touch_at' => now(),
            'last_touch_at' => now(),
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 29.9,
            'email' => 'boleto@example.com',
            'payment_method' => 'boleto',
            'metadata' => ['metrics_session_key' => $sessionKey],
        ]);
        if (\Illuminate\Support\Facades\Schema::hasColumn('orders', 'metrics_session_key')) {
            $order->forceFill(['metrics_session_key' => $sessionKey])->save();
        }

        event(new BoletoGenerated($order, ['barcode' => '123']));

        $this->assertSame(1, MetricsEvent::query()
            ->where('event_name', MetricsEvent::BOLETO_CREATED)
            ->where('order_id', $order->id)
            ->count());
    }

    public function test_checkout_reload_reuses_session_token_and_view_event(): void
    {
        Queue::fake();

        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'checkout_slug' => 'reuse01',
            'checkout_config' => [
                'customer_fields' => [
                    'name' => false,
                    'cpf' => false,
                    'phone' => false,
                    'coupon' => false,
                ],
            ],
        ]);

        $this->get('/c/'.$product->checkout_slug)->assertOk();

        $first = CheckoutSession::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($first);
        $this->assertNotEmpty($first->metrics_session_key);
        $this->assertSame(1, MetricsEvent::query()->where('event_name', MetricsEvent::CHECKOUT_VIEW)->count());

        $this->withCookie('gf_msid', $first->metrics_session_key)
            ->get('/c/'.$product->checkout_slug)
            ->assertOk();

        $this->assertSame(1, CheckoutSession::query()->where('product_id', $product->id)->count());
        $this->assertSame(1, MetricsEvent::query()->where('event_name', MetricsEvent::CHECKOUT_VIEW)->count());
        $this->assertSame($first->session_token, CheckoutSession::query()->where('product_id', $product->id)->value('session_token'));
    }

    private function insertEvent(
        int $tenantId,
        string $sessionKey,
        string $visitorKey,
        string $eventName,
        Carbon $occurredAt,
        ?float $amount = null,
        ?array $properties = null,
    ): void {
        MetricsEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'event_name' => $eventName,
            'session_key' => $sessionKey,
            'visitor_key' => $visitorKey,
            'tenant_id' => $tenantId,
            'amount' => $amount,
            'properties' => $properties,
            'occurred_at' => $occurredAt,
        ]);
    }
}
