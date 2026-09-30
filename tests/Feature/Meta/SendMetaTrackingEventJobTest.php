<?php

namespace Tests\Feature\Meta;

use App\Jobs\Meta\SendMetaTrackingEventJob;
use App\Jobs\MetaConversionsSendPurchaseJob;
use App\Models\CheckoutSession;
use App\Models\MetaTrackingEvent;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendMetaTrackingEventJobTest extends TestCase
{
    public function test_send_job_marks_record_and_order_on_success(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
        ]);

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '123456789', 'access_token' => 'tok_secret'],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@test.com',
            'payment_method' => 'credit_card',
            'metadata' => [
                'fbp' => 'fb.1.test',
                'fbc' => 'fb.1.click',
            ],
        ]);

        $record = MetaTrackingEvent::create([
            'tenant_id' => 1,
            'event_name' => 'Purchase',
            'event_id' => 'order:'.$order->id,
            'context_type' => MetaTrackingEvent::CONTEXT_ORDER,
            'context_id' => $order->id,
            'pixel_id' => '123456789',
            'status' => MetaTrackingEvent::STATUS_PENDING,
            'attempts' => 0,
        ]);

        (new SendMetaTrackingEventJob($record->id))->handle(app(MetaTrackingService::class));

        $record->refresh();
        $this->assertSame(MetaTrackingEvent::STATUS_SENT, $record->status);
        $this->assertNotNull($record->sent_at);

        $order->refresh();
        $this->assertTrue($order->metadata['meta_capi_sent_purchase'] ?? false);
    }

    public function test_purchase_job_dispatches_to_meta_tracking_queue(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '999', 'access_token' => 'tok'],
                    ],
                ],
            ],
        ]);

        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
        ]);

        MetaConversionsSendPurchaseJob::dispatch($order->id);

        Queue::assertPushed(MetaConversionsSendPurchaseJob::class, function ($job) use ($order) {
            return $job->orderId === $order->id && $job->queue === 'meta-tracking';
        });
    }

    public function test_checkout_pixel_events_endpoint_queues_session_event(): void
    {
        Queue::fake();

        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'checkout_slug' => 'metaevt1',
            'conversion_pixels' => [
                'meta' => [
                    'enabled' => true,
                    'entries' => [
                        ['pixel_id' => '111222333', 'access_token' => 'capitok'],
                    ],
                ],
            ],
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => 'metaevt1',
            'session_token' => 'sess-meta-test-token',
            'step' => CheckoutSession::STEP_VISIT,
            'customer_ip' => '127.0.0.1',
        ]);

        $response = $this->postJson('/checkout/pixel/events', [
            'checkout_session_token' => $session->session_token,
            'event_name' => 'InitiateCheckout',
            'event_id' => 'chk:'.$session->session_token,
            'fbp' => 'fb.1.123.456',
            'fbc' => 'fb.1.123.click',
            'user_agent' => 'PHPUnit',
            'event_source_url' => 'https://example.test/c/metaevt1?fbclid=abc',
            'value' => 99.9,
            'currency' => 'BRL',
            'content_ids' => ['metaevt1'],
            'content_name' => 'Produto teste',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $session->refresh();
        $this->assertSame('fb.1.123.456', $session->meta_fbp);
        $this->assertSame('fb.1.123.click', $session->meta_fbc);
        $this->assertSame('https://example.test/c/metaevt1?fbclid=abc', $session->meta_page_url);

        Queue::assertPushed(SendMetaTrackingEventJob::class);
    }
}
