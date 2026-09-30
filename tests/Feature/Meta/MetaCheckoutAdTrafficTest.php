<?php

namespace Tests\Feature\Meta;

use App\Jobs\Meta\SendMetaTrackingEventJob;
use App\Models\CheckoutSession;
use App\Models\MetaTrackingEvent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaCheckoutAdTrafficTest extends TestCase
{
    public function test_checkout_get_with_fbclid_stores_meta_attribution_on_session(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $this->createTestProduct([
            'name' => 'Produto meta ads',
            'checkout_slug' => 'metaad1',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '123456789', 'access_token' => 'capitok'],
                    ],
                ],
            ],
        ]);

        $response = $this->get('/c/metaad1?utm_source=facebook&utm_campaign=test&fbclid=CLICK_ID_XYZ');
        $response->assertOk();

        $session = CheckoutSession::query()
            ->where('checkout_slug', 'metaad1')
            ->where('utm_source', 'facebook')
            ->where('utm_campaign', 'test')
            ->latest('id')
            ->first();

        $this->assertNotNull($session);
        $this->assertSame('CLICK_ID_XYZ', $session->meta_fbclid);
        $this->assertNotEmpty($session->meta_fbc);
        $this->assertStringContainsString('CLICK_ID_XYZ', $session->meta_fbc);
        $this->assertNotEmpty($session->meta_page_url);
        $this->assertStringContainsString('/c/metaad1', $session->meta_page_url);
    }

    public function test_checkout_get_with_ad_params_queues_pageview_and_initiate_checkout(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $this->createTestProduct([
            'checkout_slug' => 'metaad2',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '987654321', 'access_token' => 'server_tok'],
                    ],
                ],
            ],
        ]);

        $this->get('/c/metaad2?utm_source=ig&fbclid=ad_click_99')->assertOk();

        $session = CheckoutSession::query()->where('checkout_slug', 'metaad2')->latest('id')->first();
        $this->assertNotNull($session);
        $this->assertNotEmpty($session->meta_page_url);
        $this->assertStringContainsString('/c/metaad2', $session->meta_page_url);

        $this->assertDatabaseHas('meta_tracking_events', [
            'event_name' => 'PageView',
            'event_id' => 'pv:'.$session->session_token,
            'pixel_id' => '987654321',
        ]);
        $this->assertDatabaseHas('meta_tracking_events', [
            'event_name' => 'InitiateCheckout',
            'event_id' => 'chk:'.$session->session_token,
            'pixel_id' => '987654321',
        ]);

        Queue::assertPushed(SendMetaTrackingEventJob::class, 2);

        $context = app(\App\Services\Meta\MetaEventContextResolver::class)->forCheckoutSession($session);
        $payload = app(\App\Services\Meta\MetaTrackingService::class)->buildPayload(
            'PageView',
            'pv:'.$session->session_token,
            $context,
        );
        $this->assertSame($session->meta_page_url, $payload['data'][0]['event_source_url'] ?? null);
    }

    public function test_checkout_get_without_ad_params_does_not_queue_landing_backup(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $this->createTestProduct([
            'checkout_slug' => 'metaad3',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '111222333', 'access_token' => 'tok'],
                    ],
                ],
            ],
        ]);

        $this->get('/c/metaad3')->assertOk();

        $this->assertSame(0, MetaTrackingEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_meta_event_context_resolver_uses_meta_fbclid_when_fbc_missing(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'metaad4']);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'metaad4',
            'session_token' => 'sess-fbclid-fallback',
            'step' => CheckoutSession::STEP_VISIT,
            'meta_fbclid' => 'fallback_click_id',
            'meta_fbc' => null,
        ]);

        $context = app(\App\Services\Meta\MetaEventContextResolver::class)->forCheckoutSession($session);

        $this->assertNotNull($context->fbc);
        $this->assertStringContainsString('fallback_click_id', $context->fbc);
    }

    public function test_checkout_pixel_events_accepts_post_without_csrf_when_session_token_valid(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'checkout_slug' => 'metaad5',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '444555666', 'access_token' => 'capitok'],
                    ],
                ],
            ],
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'metaad5',
            'session_token' => 'sess-no-csrf-token-test',
            'step' => CheckoutSession::STEP_VISIT,
            'customer_ip' => '127.0.0.1',
        ]);

        $response = $this->post('/checkout/pixel/events', [
            'checkout_session_token' => $session->session_token,
            'event_name' => 'PageView',
            'event_id' => 'pv:'.$session->session_token,
            'fbp' => 'fb.1.123.456',
            'fbc' => 'fb.1.123.click',
            'user_agent' => 'PHPUnit',
            'event_source_url' => 'https://example.test/c/metaad5?fbclid=abc',
            'value' => 49.9,
            'currency' => 'BRL',
            'content_ids' => ['metaad5'],
            'content_name' => 'Produto teste',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        Queue::assertPushed(SendMetaTrackingEventJob::class);
    }

    public function test_checkout_pixel_events_purchase_mirror_queues_capi_for_completed_order(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'checkout_slug' => 'metapurchase1',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '777888999', 'access_token' => 'capitok'],
                    ],
                ],
            ],
        ]);

        $order = \App\Models\Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 79.9,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'metapurchase1',
            'session_token' => 'sess-purchase-mirror',
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $order->id,
            'customer_ip' => '127.0.0.1',
        ]);

        $response = $this->post('/checkout/pixel/events', [
            'checkout_session_token' => $session->session_token,
            'event_name' => 'Purchase',
            'event_id' => 'order:'.$order->id,
            'order_id' => $order->id,
            'fbp' => 'fb.1.123.456',
            'fbc' => 'fb.1.123.click',
            'user_agent' => 'PHPUnit',
            'value' => 79.9,
            'currency' => 'BRL',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'queued' => 1]);

        $this->assertDatabaseHas('meta_tracking_events', [
            'event_name' => 'Purchase',
            'event_id' => 'order:'.$order->id,
            'pixel_id' => '777888999',
            'context_type' => MetaTrackingEvent::CONTEXT_ORDER,
            'context_id' => $order->id,
        ]);

        Queue::assertPushed(SendMetaTrackingEventJob::class);
    }

    public function test_checkout_pixel_events_purchase_rejects_pending_order(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['checkout_slug' => 'metapurchase2']);

        $order = \App\Models\Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'metapurchase2',
            'session_token' => 'sess-purchase-pending',
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'order_id' => $order->id,
        ]);

        $response = $this->post('/checkout/pixel/events', [
            'checkout_session_token' => $session->session_token,
            'event_name' => 'Purchase',
            'event_id' => 'order:'.$order->id,
            'order_id' => $order->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, MetaTrackingEvent::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_checkout_get_with_realistic_long_ad_url(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);

        $this->createTestProduct([
            'checkout_slug' => 'metaad6',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '777888999', 'access_token' => 'tok'],
                    ],
                ],
            ],
        ]);

        $fbclid = 'IwY2xjawSk_g1leHRuA2FlbQEwAGFkaWQBqzWv-mMj5XNydGMGYXBwX2lkEDIyMjAzOTE3ODgyMDA4OTIAAR5v0DMdyhoI6KQ05WOGKyYcAeQAKc2nt9TfR7_dHrF70JRIiXtSv2Erea9upQ_aem_Tqn742jtrxDLUzrQzkv7xA';
        $sck = 'FBhQwK21wXxR17/06 ABO Sem1%|120248844898790293hQwK21wXxR17/06 Sem1% MLP Dep/NaoAg|120248844898840293hQwK21wXxRDep — Cópia|120248844975690293hQwK21wXxRFacebook_Desktop_Feed';
        $utmContent = 'Dep — Cópia|120248844975690293::'.$fbclid.'::';

        $qs = http_build_query([
            'fbclid' => $fbclid,
            'sck' => $sck,
            'utm_source' => 'FB',
            'utm_medium' => '17/06 Sem1% MLP Dep/NaoAg|120248844898840293',
            'utm_campaign' => '17/06 ABO Sem1%|120248844898790293',
            'utm_content' => $utmContent,
            'utm_term' => 'Facebook_Desktop_Feed',
        ]);

        $this->get('/c/metaad6?'.$qs)->assertOk();

        $session = CheckoutSession::query()
            ->where('checkout_slug', 'metaad6')
            ->latest('id')
            ->first();

        $this->assertNotNull($session);
        $this->assertSame('FB', $session->utm_source);
        $this->assertSame($fbclid, $session->meta_fbclid);
        $this->assertNotEmpty($session->meta_fbc);
        $this->assertStringContainsString($fbclid, $session->meta_fbc);
        $this->assertSame($sck, $session->sck);
        $this->assertSame($utmContent, $session->utm_content);
    }

    public function test_tracking_from_query_truncates_varchar_utm_fields(): void
    {
        $long = str_repeat('A', 300);
        $request = \Illuminate\Http\Request::create('/c/test', 'GET', [
            'utm_source' => $long,
            'utm_medium' => $long,
            'utm_campaign' => $long,
            'utm_content' => $long,
        ]);

        $tracking = CheckoutSession::trackingFromQuery($request);

        $this->assertSame(255, mb_strlen($tracking['utm_source'] ?? ''));
        $this->assertSame(255, mb_strlen($tracking['utm_medium'] ?? ''));
        $this->assertSame(255, mb_strlen($tracking['utm_campaign'] ?? ''));
        $this->assertSame(300, mb_strlen($tracking['utm_content'] ?? ''));
    }
}
