<?php

namespace Tests\Feature;

use App\Models\ApiApplication;
use App\Models\MedDispute;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BspayMedWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config([
            'queue.default' => 'sync',
            'getfy.api.inbound_webhooks_async' => false,
        ]);
    }

    public function test_chargeback_opened_creates_platform_dispute_without_marking_checkout_order(): void
    {
        if (! Schema::hasTable('med_disputes')) {
            $this->markTestSkipped('med_disputes table');
        }

        [$order] = $this->makeBspayPaidOrder();

        $this->postSignedBspayWebhook($this->openedPayload('tx-bspay-med-1'), 'unused', event: 'chargeback.opened')
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'order_id' => $order->id,
            'cajupay_dispute_id' => 'bspay:abc-uuid-1234',
            'status' => MedDispute::STATUS_OPEN,
            'responsible_party' => MedDispute::PARTY_PLATFORM,
            'reason_code' => 'FRAUD',
        ]);
    }

    public function test_chargeback_opened_marks_api_pix_order_disputed(): void
    {
        if (! Schema::hasTable('med_disputes')) {
            $this->markTestSkipped('med_disputes table');
        }

        [$order] = $this->makeBspayPaidOrder(apiPix: true);

        $this->postSignedBspayWebhook($this->openedPayload('tx-bspay-med-1'), 'unused', event: 'chargeback.opened')
            ->assertOk();

        $this->assertSame('disputed', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'order_id' => $order->id,
            'cajupay_dispute_id' => 'bspay:abc-uuid-1234',
            'responsible_party' => MedDispute::PARTY_TENANT,
        ]);
    }

    public function test_chargeback_won_closes_open_dispute(): void
    {
        if (! Schema::hasTable('med_disputes')) {
            $this->markTestSkipped('med_disputes table');
        }

        [$order] = $this->makeBspayPaidOrder();
        $this->postSignedBspayWebhook($this->openedPayload('tx-bspay-med-1'), 'unused', event: 'chargeback.opened');

        $this->postSignedBspayWebhook([
            'event' => 'chargeback.won',
            'transaction_id' => 'tx-bspay-med-1',
            'data' => [
                'infraction_id' => 'abc-uuid-1234',
                'transaction_id' => 'tx-bspay-med-1',
                'amount' => '25.00',
                'currency' => 'BRL',
            ],
        ], 'unused', event: 'chargeback.won')->assertOk();

        $this->assertDatabaseHas('med_disputes', [
            'cajupay_dispute_id' => 'bspay:abc-uuid-1234',
            'status' => MedDispute::STATUS_RESOLVED_WON,
            'outcome' => 'won',
        ]);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_chargeback_lost_refunds_tenant_managed_order(): void
    {
        if (! Schema::hasTable('med_disputes') || ! Schema::hasTable('wallet_transactions')) {
            $this->markTestSkipped('med or wallet tables');
        }

        [$order] = $this->makeBspayPaidOrder(apiPix: true);
        $this->postSignedBspayWebhook($this->openedPayload('tx-bspay-med-1'), 'unused', event: 'chargeback.opened');
        $this->assertSame('disputed', $order->fresh()->status);

        $this->postSignedBspayWebhook([
            'event' => 'chargeback.lost',
            'transaction_id' => 'tx-bspay-med-1',
            'data' => [
                'infraction_id' => 'abc-uuid-1234',
                'transaction_id' => 'tx-bspay-med-1',
                'amount' => '25.00',
                'amount_refunded' => '25.00',
                'currency' => 'BRL',
            ],
        ], 'unused', event: 'chargeback.lost')->assertOk();

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertDatabaseHas('med_disputes', [
            'cajupay_dispute_id' => 'bspay:abc-uuid-1234',
            'status' => MedDispute::STATUS_RESOLVED_LOST,
            'outcome' => 'lost',
        ]);
    }

    public function test_chargeback_opened_is_idempotent(): void
    {
        if (! Schema::hasTable('med_disputes')) {
            $this->markTestSkipped('med_disputes table');
        }

        [$order] = $this->makeBspayPaidOrder();
        $payload = $this->openedPayload('tx-bspay-med-1');
        $this->postSignedBspayWebhook($payload, 'unused', event: 'chargeback.opened')->assertOk();
        $this->postSignedBspayWebhook($payload, 'unused', event: 'chargeback.opened')->assertOk();

        $this->assertSame(1, MedDispute::query()->where('order_id', $order->id)->count());
    }

    /**
     * @return array{0: Order, 1: User}
     */
    private function makeBspayPaidOrder(bool $apiPix = false): array
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        $meta = [];
        $apiApplicationId = null;
        if ($apiPix) {
            $app = ApiApplication::create([
                'tenant_id' => 1,
                'name' => 'API MED',
                'slug' => ApiApplication::generateUniqueSlug(1, 'API MED'),
                'api_key_hash' => hash('sha256', 'k'),
                'public_key' => ApiApplication::generatePublicKey(),
                'secret_key_hash' => hash('sha256', 's'),
                'payment_gateways' => ApiApplication::defaultPaymentGateways(),
                'allowed_ips' => [],
                'is_active' => true,
                'is_legacy' => true,
                'scopes' => [],
            ]);
            $apiApplicationId = $app->id;
            $meta['source'] = 'api';
        }

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'api_application_id' => $apiApplicationId,
            'status' => 'completed',
            'amount' => 25,
            'email' => 'buyer@test.com',
            'payment_method' => 'pix',
            'gateway' => 'bspay',
            'gateway_id' => 'tx-bspay-med-1',
            'metadata' => $meta,
        ]);

        return [$order, $user];
    }

    /**
     * @return array<string, mixed>
     */
    private function openedPayload(string $transactionId): array
    {
        return [
            'event' => 'chargeback.opened',
            'transaction_id' => $transactionId,
            'data' => [
                'infraction_id' => 'abc-uuid-1234',
                'transaction_id' => $transactionId,
                'type' => 'FRAUD',
                'status' => 'open',
                'amount' => '25.00',
                'currency' => 'BRL',
                'e2e_id' => 'E17028875202604301400ABC123',
                'deadline_at' => '2026-05-08T13:00:00Z',
                'reason' => 'Customer disputed — alleged unauthorized payment',
            ],
        ];
    }
}
