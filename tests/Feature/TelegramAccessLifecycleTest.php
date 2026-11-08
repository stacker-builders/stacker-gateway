<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Events\OrderRefunded;
use App\Events\OrderRejected;
use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTelegramSetting;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessCompensation;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramAccessOrderLink;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Models\TelegramDeliveryIncident;
use App\Models\User;
use App\Services\PlatformOrderAdminService;
use App\Services\SellerIntegrationVisibility;
use App\Services\Telegram\TelegramAccessExpiryService;
use App\Services\Telegram\TelegramAccessGrantService;
use App\Services\Telegram\TelegramAccessRevocationService;
use App\Services\Telegram\TelegramDeliveryIncidentService;
use App\Services\Telegram\TelegramMemberRemovalService;
use App\Services\Telegram\TelegramModuleSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramAccessLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
        TelegramModuleSettings::ensureStored();
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::TELEGRAM, true);
        config(['telegram.product_sales_operational' => false]);
        Mail::fake();
        Queue::fake();
    }

    private function makeSeller(): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
        ]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'seller_onboarded_at' => now(),
        ])->save();

        return $seller->fresh();
    }

    private function makeBuyer(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @return array{0: TelegramBot, 1: list<TelegramCommunity>, 2: Product}
     */
    private function makeTelegramProduct(User $seller, int $communityCount = 1, bool $lifetime = false, int $days = 30): array
    {
        $bot = TelegramBot::query()->create([
            'tenant_id' => $seller->tenant_id,
            'telegram_bot_id' => 770000 + (int) $seller->tenant_id,
            'username' => 'life_bot_'.$seller->tenant_id,
            'first_name' => 'Life',
            'token_encrypted' => '770000:AAHlifecycleTokenValue',
            'webhook_path_secret' => TelegramBot::makePathSecret(),
            'webhook_secret_token' => TelegramBot::makeWebhookSecretToken(),
            'status' => TelegramBot::STATUS_CONNECTED,
            'connected_at' => now(),
        ]);

        $communities = [];
        for ($i = 0; $i < $communityCount; $i++) {
            $communities[] = TelegramCommunity::query()->create([
                'tenant_id' => $seller->tenant_id,
                'telegram_bot_id' => $bot->id,
                'telegram_chat_id' => -1008000 - $i - ((int) $seller->tenant_id * 10),
                'title' => 'Life Comm '.$i,
                'type' => TelegramCommunity::TYPE_SUPERGROUP,
                'status' => TelegramCommunity::STATUS_LINKED,
                'is_admin' => true,
                'ready_for_delivery' => true,
            ]);
        }

        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'Acesso Life',
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
            'checkout_slug' => 'lf'.substr(uniqid(), -5),
        ]);

        ProductTelegramSetting::query()->create([
            'tenant_id' => $seller->tenant_id,
            'product_id' => (string) $product->id,
            'telegram_bot_id' => $bot->id,
            'access_duration' => $lifetime ? null : $days,
            'access_duration_unit' => 'days',
            'is_lifetime' => $lifetime,
            'invite_expiration_hours' => 24,
            'welcome_message' => 'Oi',
            'auto_remove_on_expiry' => true,
        ]);

        foreach ($communities as $community) {
            DB::table('product_telegram_communities')->insert([
                'tenant_id' => $seller->tenant_id,
                'product_id' => (string) $product->id,
                'telegram_community_id' => $community->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$bot, $communities, $product];
    }

    private function makePaidOrder(User $seller, User $buyer, Product $product, ?Carbon $paidAt = null, bool $renewal = false): Order
    {
        $paidAt = ($paidAt ?? now())->utc();

        return Order::create([
            'tenant_id' => $seller->tenant_id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'test',
            'is_renewal' => $renewal,
            'metadata' => ['paid_at' => $paidAt->toIso8601String()],
        ]);
    }

    private function markJoined(TelegramAccessGrant $grant, int $telegramUserId = 424242): void
    {
        $grant->forceFill([
            'telegram_user_id' => $telegramUserId,
            'telegram_username' => 'buyer_life',
            'identity_linked_at' => now()->utc(),
            'status' => TelegramAccessGrant::STATUS_COMPLETED,
            'delivery_status' => TelegramAccessGrant::DELIVERY_COMPLETED,
        ])->save();

        foreach ($grant->communities as $row) {
            $row->forceFill(['status' => TelegramAccessCommunity::STATUS_JOINED])->save();
        }
    }

    public function test_renewal_before_expiry_extends_from_current_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, false, 30);

        $order1 = $this->makePaidOrder($seller, $buyer, $product, Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order1, $product, false);
        $this->markJoined($grant);
        $originalEnds = $grant->getAttributes()['access_ends_at'];

        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        $order2 = $this->makePaidOrder($seller, $buyer, $product, Carbon::parse('2026-11-20 12:00:00', 'UTC'), true);
        $renewed = app(TelegramAccessGrantService::class)->grantForOrderProduct($order2, $product, false);

        $this->assertSame($grant->id, $renewed->id);
        $this->assertSame(1, (int) $renewed->renewal_count);
        $this->assertSame(
            Carbon::parse($originalEnds, 'UTC')->addDays(30)->format('Y-m-d H:i:s'),
            $renewed->getAttributes()['access_ends_at']
        );
        $this->assertSame((string) $grant->telegram_user_id, (string) $renewed->telegram_user_id);
        $this->assertTrue(
            TelegramAccessEvent::query()
                ->where('telegram_access_grant_id', $grant->id)
                ->where('event_type', TelegramAccessEvent::ACCESS_RENEWED)
                ->exists()
        );

        Carbon::setTestNow();
    }

    public function test_renewal_after_expiry_starts_from_payment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, false, 10);

        $order1 = $this->makePaidOrder($seller, $buyer, $product, Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order1, $product, false);
        $this->markJoined($grant);
        $grant->communities()->update(['status' => TelegramAccessCommunity::STATUS_REVOKED]);
        $grant->forceFill([
            'status' => TelegramAccessGrant::STATUS_EXPIRED,
            'expired_at' => '2026-11-18 12:00:00',
            'access_ends_at' => '2026-11-18 12:00:00',
        ])->save();

        $paidAt = Carbon::parse('2026-11-25 12:00:00', 'UTC');
        Carbon::setTestNow($paidAt);
        $order2 = $this->makePaidOrder($seller, $buyer, $product, $paidAt, true);
        $renewed = app(TelegramAccessGrantService::class)->grantForOrderProduct($order2, $product, false);

        $this->assertSame($grant->id, $renewed->id);
        $this->assertSame(
            $paidAt->copy()->addDays(10)->format('Y-m-d H:i:s'),
            $renewed->getAttributes()['access_ends_at']
        );
        $this->assertNull($renewed->expired_at);
        $this->assertSame(
            TelegramAccessCommunity::STATUS_PENDING,
            $renewed->communities()->first()->status
        );

        Carbon::setTestNow();
    }

    public function test_signed_identity_link_rejects_enumeration(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);

        $payload = \App\Services\Telegram\TelegramIdentityLink::payload($grant);
        $parsed = \App\Services\Telegram\TelegramIdentityLink::parse('/start '.$payload);
        $this->assertNotNull($parsed);
        $this->assertTrue(\App\Services\Telegram\TelegramIdentityLink::verify(
            $grant->fresh(),
            $parsed['nonce'],
            $parsed['signature']
        ));
        $this->assertNull(\App\Services\Telegram\TelegramIdentityLink::parse('/start link_'.$grant->id));
        $this->assertFalse(\App\Services\Telegram\TelegramIdentityLink::verify(
            $grant->fresh(),
            $parsed['nonce'],
            'deadbeefdeadbeefdeadbeefdeadbeef'
        ));
    }

    public function test_refresh_status_does_not_resurrect_revoked_grant(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $grant->communities()->update([
            'status' => TelegramAccessCommunity::STATUS_REVOKED,
            'invite_link' => 'https://t.me/+stale',
        ]);
        $grant->forceFill(['status' => TelegramAccessGrant::STATUS_REVOKED])->save();

        $fresh = app(TelegramAccessGrantService::class)->refreshGrantStatus($grant->fresh(['communities']));
        $this->assertSame(TelegramAccessGrant::STATUS_REVOKED, $fresh->status);
    }

    public function test_renewal_refund_shortens_instead_of_full_revoke(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, false, 30);

        $order1 = $this->makePaidOrder($seller, $buyer, $product, Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order1, $product, false);
        $this->markJoined($grant);

        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'UTC'));
        $order2 = $this->makePaidOrder($seller, $buyer, $product, Carbon::parse('2026-11-20 12:00:00', 'UTC'), true);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order2, $product, false);
        $endsAfterRenewal = $grant->getAttributes()['access_ends_at'];

        app(\App\Services\Telegram\TelegramAccessRevocationService::class)
            ->revokeForOrder($order2, \App\Services\Telegram\TelegramAccessRevocationService::REASON_REFUND, 'renewal refund');

        $grant->refresh();
        $this->assertNotSame(TelegramAccessGrant::STATUS_REVOKED, $grant->status);
        $this->assertSame(
            Carbon::parse($endsAfterRenewal, 'UTC')->subDays(30)->format('Y-m-d H:i:s'),
            $grant->getAttributes()['access_ends_at']
        );

        Carbon::setTestNow();
    }

    public function test_duplicate_webhook_order_is_idempotent(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);

        $g1 = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $g2 = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);

        $this->assertSame($g1->id, $g2->id);
        $this->assertSame(1, TelegramAccessGrant::query()->where('user_id', $buyer->id)->count());
        $this->assertSame(1, TelegramAccessOrderLink::query()->where('order_id', $order->id)->count());
    }

    public function test_concurrent_renewal_beats_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, false, 5);

        $order1 = $this->makePaidOrder($seller, $buyer, $product, Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order1, $product, false);
        $this->markJoined($grant);

        // Simula vencimento iminente + renovação no mesmo instante.
        $grant->forceFill(['access_ends_at' => '2026-11-08 12:00:00'])->save();
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:01', 'UTC'));

        $renewOrder = $this->makePaidOrder($seller, $buyer, $product, Carbon::parse('2026-11-08 12:00:01', 'UTC'), true);
        app(TelegramAccessGrantService::class)->grantForOrderProduct($renewOrder, $product, false);

        $stats = app(TelegramAccessExpiryService::class)->expireGrantById((int) $grant->id);
        $grant->refresh();

        $this->assertFalse($stats['expired']);
        $this->assertNotSame(TelegramAccessGrant::STATUS_EXPIRED, $grant->status);
        $this->assertTrue($grant->isCurrentlyValid());

        Carbon::setTestNow();
    }

    public function test_two_products_same_community_keeps_member(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member'],
            ]),
            'api.telegram.org/bot*/banChatMember' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/bot*/unbanChatMember' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities] = $this->makeTelegramProduct($seller, 1, false, 30);
        $community = $communities[0];

        $productB = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'Produto B',
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
            'checkout_slug' => 'lb'.substr(uniqid(), -5),
        ]);
        ProductTelegramSetting::query()->create([
            'tenant_id' => $seller->tenant_id,
            'product_id' => (string) $productB->id,
            'telegram_bot_id' => $bot->id,
            'access_duration' => 30,
            'access_duration_unit' => 'days',
            'is_lifetime' => false,
            'invite_expiration_hours' => 24,
            'auto_remove_on_expiry' => true,
        ]);
        DB::table('product_telegram_communities')->insert([
            'tenant_id' => $seller->tenant_id,
            'product_id' => (string) $productB->id,
            'telegram_community_id' => $community->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productA = Product::query()->where('tenant_id', $seller->tenant_id)->where('name', 'Acesso Life')->firstOrFail();
        $orderA = $this->makePaidOrder($seller, $buyer, $productA);
        $orderB = $this->makePaidOrder($seller, $buyer, $productB);
        $grantA = app(TelegramAccessGrantService::class)->grantForOrderProduct($orderA, $productA, false);
        $grantB = app(TelegramAccessGrantService::class)->grantForOrderProduct($orderB, $productB, false);
        $this->markJoined($grantA, 111);
        $this->markJoined($grantB, 111);

        $rowA = $grantA->communities()->first();
        $outcome = app(TelegramMemberRemovalService::class)->removeIfNoOtherRights($grantA->fresh(['communities', 'bot']), $rowA->fresh('community'), 'expired');

        $this->assertTrue($outcome['skipped']);
        $this->assertSame('other_active_right', $outcome['reason']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'banChatMember'));
    }

    public function test_lifetime_grant_is_never_expired_by_job(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, true);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);

        $stats = app(TelegramAccessExpiryService::class)->processDue();
        $grant->refresh();

        $this->assertSame(0, $stats['expired']);
        $this->assertTrue($grant->is_lifetime);
        $this->assertNotSame(TelegramAccessGrant::STATUS_EXPIRED, $grant->status);
    }

    public function test_full_refund_revokes_and_attempts_removal(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member'],
            ]),
            'api.telegram.org/bot*/banChatMember' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/bot*/unbanChatMember' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $this->markJoined($grant);

        event(new OrderRefunded($order->fresh()));
        $grant->refresh();

        $this->assertSame(TelegramAccessGrant::STATUS_REVOKED, $grant->status);
        $this->assertSame(TelegramAccessRevocationService::REASON_REFUND, $grant->revoked_reason);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'banChatMember'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'unbanChatMember'));
    }

    public function test_dispute_under_review_does_not_revoke(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $this->markJoined($grant);

        PlatformOrderAdminService::markDisputed($order->fresh());
        $grant->refresh();

        $this->assertSame(TelegramAccessGrant::STATUS_COMPLETED, $grant->status);
        $this->assertTrue(
            TelegramAccessEvent::query()
                ->where('telegram_access_grant_id', $grant->id)
                ->where('event_type', TelegramAccessEvent::MANUAL_CORRECTION)
                ->exists()
        );
    }

    public function test_order_rejected_revokes_access(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);

        event(new OrderRejected($order->fresh()));
        $grant->refresh();

        $this->assertSame(TelegramAccessGrant::STATUS_REVOKED, $grant->status);
    }

    public function test_api_failure_on_removal_is_recorded(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member'],
            ]),
            'api.telegram.org/bot*/banChatMember' => Http::response(['ok' => false, 'description' => 'BOT_PERMISSIONS'], 403),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $this->markJoined($grant);
        $row = $grant->communities()->first();

        $outcome = app(TelegramMemberRemovalService::class)->removeIfNoOtherRights(
            $grant->fresh(['communities', 'bot']),
            $row->fresh('community'),
            'expired'
        );

        $this->assertFalse($outcome['removed']);
        $this->assertFalse($outcome['skipped']);
        $this->assertTrue(
            TelegramAccessEvent::query()
                ->where('telegram_access_grant_id', $grant->id)
                ->where('event_type', TelegramAccessEvent::MEMBER_REMOVAL_FAILED)
                ->exists()
        );
    }

    public function test_compensation_from_incident_is_idempotent(): void
    {
        TelegramModuleSettings::save(['auto_compensation_enabled' => false]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, false, 30);
        $order = $this->makePaidOrder($seller, $buyer, $product, Carbon::parse('2026-11-01 12:00:00', 'UTC'));
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $endsBefore = $grant->getAttributes()['access_ends_at'];

        $incidents = app(TelegramDeliveryIncidentService::class);
        $incident = $incidents->openOrRefresh(
            $grant,
            TelegramDeliveryIncident::REASON_INVITE_ERROR,
            'Falha técnica de convite',
            'inc-life-'.$grant->id,
        );
        $incident->forceFill([
            'started_at' => Carbon::parse('2026-11-01 12:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-11-02 12:00:00', 'UTC'),
            'duration_seconds' => 86400,
            'compensation_seconds' => 86400,
            'compensation_status' => TelegramDeliveryIncident::COMPENSATION_AUTHORIZED,
        ])->save();

        $c1 = $incidents->applyCompensation($incident->fresh(), (int) $seller->id);
        $c2 = $incidents->applyCompensation($incident->fresh(), (int) $seller->id);

        $this->assertSame($c1->id, $c2->id);
        $this->assertSame(1, TelegramAccessCompensation::query()->where('telegram_access_grant_id', $grant->id)->count());
        $grant->refresh();
        $this->assertSame(
            Carbon::parse($endsBefore, 'UTC')->addSeconds(86400)->format('Y-m-d H:i:s'),
            $grant->getAttributes()['access_ends_at']
        );
    }

    public function test_multi_tenant_isolation_on_metrics(): void
    {
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $productA] = $this->makeTelegramProduct($sellerA);
        [, , $productB] = $this->makeTelegramProduct($sellerB);

        $orderA = $this->makePaidOrder($sellerA, $buyer, $productA);
        $orderB = $this->makePaidOrder($sellerB, $buyer, $productB);
        app(TelegramAccessGrantService::class)->grantForOrderProduct($orderA, $productA, false);
        app(TelegramAccessGrantService::class)->grantForOrderProduct($orderB, $productB, false);

        $metrics = app(\App\Services\Telegram\TelegramLifecycleMetricsService::class);
        $a = $metrics->forTenant((int) $sellerA->tenant_id);
        $b = $metrics->forTenant((int) $sellerB->tenant_id);

        $this->assertCount(1, $a['grants']);
        $this->assertCount(1, $b['grants']);
        $this->assertSame((int) $sellerA->tenant_id, (int) TelegramAccessGrant::query()->find($a['grants'][0]['id'])->tenant_id);
        $this->assertNotSame($a['grants'][0]['id'], $b['grants'][0]['id']);
    }

    public function test_product_sales_operational_remains_false(): void
    {
        $this->assertFalse((bool) config('telegram.product_sales_operational'));
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        event(new OrderCompleted($this->makePaidOrder($seller, $buyer, $product)));
        $this->assertFalse((bool) config('telegram.product_sales_operational'));
    }

    public function test_expiry_job_marks_and_removes(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member'],
            ]),
            'api.telegram.org/bot*/banChatMember' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/bot*/unbanChatMember' => Http::response(['ok' => true, 'result' => true]),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, false, 1);
        $order = $this->makePaidOrder($seller, $buyer, $product, Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $this->markJoined($grant);

        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        $stats = app(TelegramAccessExpiryService::class)->processDue();
        $grant->refresh();

        $this->assertSame(1, $stats['expired']);
        $this->assertSame(TelegramAccessGrant::STATUS_EXPIRED, $grant->status);
        $this->assertSame(1, $stats['removed']);

        // Reprocessamento idempotente
        $again = app(TelegramAccessExpiryService::class)->expireGrantById((int) $grant->id);
        $this->assertFalse($again['expired']);

        Carbon::setTestNow();
    }

    public function test_privileged_member_is_not_removed(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getChatMember' => Http::response([
                'ok' => true,
                'result' => ['status' => 'administrator'],
            ]),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $this->markJoined($grant);
        $row = $grant->communities()->first();

        $outcome = app(TelegramMemberRemovalService::class)->removeIfNoOtherRights(
            $grant->fresh(['communities', 'bot']),
            $row->fresh('community'),
            'expired'
        );

        $this->assertTrue($outcome['skipped']);
        $this->assertSame('privileged_member', $outcome['reason']);
    }
}
