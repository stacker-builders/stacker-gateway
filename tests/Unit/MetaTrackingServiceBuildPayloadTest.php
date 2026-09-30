<?php

namespace Tests\Unit;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\User;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaTrackingServiceBuildPayloadTest extends TestCase
{
    public function test_send_purchase_uses_event_id_and_meta_tokens(): void
    {
        Http::fake([
            'graph.facebook.com/*/events' => Http::response(['events_received' => 1], 200),
        ]);

        $order = new Order([
            'tenant_id' => 1,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'customer_ip' => '127.0.0.1',
            'metadata' => [
                'fbp' => 'fb.1.1234567890.1111111111',
                'fbc' => 'fb.1.1234567890.AbCdEfGhIj',
                'user_agent' => 'UnitTest UA',
                'event_source_url' => 'https://example.test/c/produto?fbclid=abc',
            ],
        ]);
        $order->id = 999;
        $order->created_at = now();
        $order->updated_at = now();

        $order->setRelation('product', new \App\Models\Product([
            'tenant_id' => 1,
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '123', 'access_token' => 'tok_abc'],
                    ],
                ],
            ],
        ]));
        $order->setRelation('checkoutSession', null);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect());

        $svc = app(MetaTrackingService::class);
        $context = app(\App\Services\Meta\MetaEventContextResolver::class)->forOrder($order);
        $payload = $svc->buildPayload('Purchase', 'order:999', $context);

        $this->assertSame('Purchase', $payload['data'][0]['event_name'] ?? null);
        $this->assertSame('order:999', $payload['data'][0]['event_id'] ?? null);
        $this->assertSame('https://example.test/c/produto?fbclid=abc', $payload['data'][0]['event_source_url'] ?? null);
        $ud = $payload['data'][0]['user_data'] ?? [];
        $this->assertSame('fb.1.1234567890.1111111111', $ud['fbp'] ?? null);
        $this->assertSame('fb.1.1234567890.AbCdEfGhIj', $ud['fbc'] ?? null);
    }

    public function test_queue_session_event_persists_event_source_url_for_later_send(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'checkout_slug' => 'meta-url-persist',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '555666777', 'access_token' => 'tok'],
                    ],
                ],
            ],
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'meta-url-persist',
            'session_token' => 'sess-url-persist',
            'step' => CheckoutSession::STEP_VISIT,
            'customer_ip' => '127.0.0.1',
        ]);

        $svc = app(MetaTrackingService::class);
        $svc->queueSessionEvent($session, 'PageView', 'pv:sess-url-persist', [
            'event_source_url' => 'https://example.test/c/meta-url-persist?fbclid=xyz',
            'user_agent' => 'PHPUnit UA',
        ]);

        $session->refresh();
        $this->assertSame('https://example.test/c/meta-url-persist?fbclid=xyz', $session->meta_page_url);
        $this->assertSame('PHPUnit UA', $session->meta_user_agent);

        // Later mirrors (thank-you page) must not overwrite the checkout landing URL.
        $svc->persistSessionAttribution($session, [
            'event_source_url' => 'https://example.test/obrigado',
        ]);
        $session->refresh();
        $this->assertSame('https://example.test/c/meta-url-persist?fbclid=xyz', $session->meta_page_url);

        $context = app(\App\Services\Meta\MetaEventContextResolver::class)->forCheckoutSession($session);
        $payload = $svc->buildPayload('PageView', 'pv:sess-url-persist', $context);
        $this->assertSame(
            'https://example.test/c/meta-url-persist?fbclid=xyz',
            $payload['data'][0]['event_source_url'] ?? null
        );
    }

    public function test_merge_session_attribution_copies_event_source_url(): void
    {
        $session = new CheckoutSession([
            'meta_fbp' => 'fb.1.1',
            'meta_fbc' => 'fb.1.2',
            'meta_user_agent' => 'UA',
            'meta_page_url' => 'https://example.test/c/x',
        ]);

        $merged = app(MetaTrackingService::class)->mergeSessionAttributionIntoOrder($session, []);

        $this->assertSame('https://example.test/c/x', $merged['event_source_url'] ?? null);
        $this->assertSame('fb.1.1', $merged['fbp'] ?? null);
    }
}
