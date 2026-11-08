<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Jobs\ProcessTelegramDeliveryJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTelegramSetting;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Models\TelegramWebhookEvent;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use App\Services\Telegram\TelegramAccessExpiryService;
use App\Services\Telegram\TelegramAccessGrantService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramDeliveryReconcileService;
use App\Services\Telegram\TelegramInviteService;
use App\Services\Telegram\TelegramModuleSettings;
use App\Services\Telegram\TelegramWebhookService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramResilienceStage35Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureInstalled::class, ValidateCsrfToken::class]);
        TelegramModuleSettings::ensureStored();
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::TELEGRAM, true);
        config(['telegram.product_sales_operational' => false]);
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
    private function makeTelegramProduct(User $seller, int $communities = 1): array
    {
        $bot = TelegramBot::query()->create([
            'tenant_id' => $seller->tenant_id,
            'telegram_bot_id' => 660000 + (int) $seller->tenant_id,
            'username' => 'res_bot_'.$seller->tenant_id,
            'first_name' => 'Res',
            'token_encrypted' => '660000:AAHresilienceTokenValue',
            'webhook_path_secret' => TelegramBot::makePathSecret(),
            'webhook_secret_token' => TelegramBot::makeWebhookSecretToken(),
            'status' => TelegramBot::STATUS_CONNECTED,
            'connected_at' => now(),
        ]);

        $list = [];
        for ($i = 0; $i < $communities; $i++) {
            $list[] = TelegramCommunity::query()->create([
                'tenant_id' => $seller->tenant_id,
                'telegram_bot_id' => $bot->id,
                'telegram_chat_id' => -1007000 - $i - ((int) $seller->tenant_id * 10),
                'title' => 'Res Comm '.$i,
                'type' => TelegramCommunity::TYPE_SUPERGROUP,
                'status' => TelegramCommunity::STATUS_LINKED,
                'is_admin' => true,
                'ready_for_delivery' => true,
            ]);
        }

        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'Resilience TG',
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
            'checkout_slug' => 'rs'.substr(uniqid(), -5),
        ]);

        ProductTelegramSetting::query()->create([
            'tenant_id' => $seller->tenant_id,
            'product_id' => (string) $product->id,
            'telegram_bot_id' => $bot->id,
            'access_duration' => 30,
            'access_duration_unit' => 'days',
            'is_lifetime' => false,
            'invite_expiration_hours' => 24,
            'auto_remove_on_expiry' => true,
        ]);

        foreach ($list as $community) {
            DB::table('product_telegram_communities')->insert([
                'tenant_id' => $seller->tenant_id,
                'product_id' => (string) $product->id,
                'telegram_community_id' => $community->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$bot, $list, $product];
    }

    private function makePaidOrder(User $seller, User $buyer, Product $product): Order
    {
        return Order::create([
            'tenant_id' => $seller->tenant_id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'test',
            'metadata' => ['paid_at' => now()->utc()->toIso8601String()],
        ]);
    }

    public function test_queue_retry_after_exceeds_job_timeout(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');
        $job = new ProcessTelegramDeliveryJob(1);
        $this->assertGreaterThan($job->timeout, $retryAfter);
        $this->assertSame(180, $retryAfter);
        $this->assertFalse((bool) config('telegram.product_sales_operational'));
    }

    public function test_duplicate_delivery_job_is_unique_per_grant(): void
    {
        $this->assertTrue(is_a(ProcessTelegramDeliveryJob::class, \Illuminate\Contracts\Queue\ShouldBeUnique::class, true));
        $a = new ProcessTelegramDeliveryJob(42);
        $b = new ProcessTelegramDeliveryJob(42);
        $this->assertSame($a->uniqueId(), $b->uniqueId());
    }

    public function test_reconcile_creates_grant_for_paid_order_without_access(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $order->forceFill(['updated_at' => now()->subMinutes(5)])->save();

        $this->assertSame(0, TelegramAccessGrant::query()->where('order_id', $order->id)->count());

        $stats = app(TelegramDeliveryReconcileService::class)->run(50);

        $this->assertGreaterThanOrEqual(1, $stats['orders_without_grant']);
        $this->assertSame(1, TelegramAccessGrant::query()->where('order_id', $order->id)->count());
        Queue::assertPushed(ProcessTelegramDeliveryJob::class);
    }

    public function test_reconcile_requeues_grant_without_completed_delivery(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $grant->forceFill(['updated_at' => now()->subMinutes(10)])->save();

        Queue::fake();
        $stats = app(TelegramDeliveryReconcileService::class)->run(50);

        $this->assertGreaterThanOrEqual(1, $stats['grants_without_delivery']);
        Queue::assertPushed(ProcessTelegramDeliveryJob::class, fn ($job) => $job->grantId === (int) $grant->id);
    }

    public function test_entry_without_invite_link_does_not_complete_from_invite_ready(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities, $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        app(TelegramAccessGrantService::class)->linkIdentity($grant, 111222, 'buyer', 'B');
        $row = $grant->communities()->first();
        $row->forceFill([
            'status' => TelegramAccessCommunity::STATUS_INVITE_READY,
            'invite_link' => 'https://t.me/+Ours',
        ])->save();

        // Sem invite_link: não confirma (pode ser convite externo). Exige AWAITING_JOIN_REQUEST.
        $event = TelegramWebhookEvent::query()->create([
            'tenant_id' => $bot->tenant_id,
            'telegram_bot_id' => $bot->id,
            'update_id' => 910001,
            'event_type' => 'chat_member',
            'status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'payload' => [
                'update_id' => 910001,
                'chat_member' => [
                    'chat' => ['id' => $communities[0]->telegram_chat_id, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'date' => now()->timestamp,
                    'old_chat_member' => ['status' => 'left', 'user' => ['id' => 111222, 'is_bot' => false]],
                    'new_chat_member' => ['status' => 'member', 'user' => ['id' => 111222, 'is_bot' => false]],
                ],
            ],
        ]);

        app(TelegramWebhookService::class)->processEvent($event);
        $this->assertNotSame(TelegramAccessCommunity::STATUS_JOINED, $row->fresh()->status);
    }

    public function test_entry_without_invite_link_completes_after_official_join_request(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities, $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        app(TelegramAccessGrantService::class)->linkIdentity($grant, 111333, 'buyer', 'B');
        $row = $grant->communities()->first();
        $row->forceFill([
            'status' => TelegramAccessCommunity::STATUS_AWAITING_JOIN_REQUEST,
            'invite_link' => 'https://t.me/+Ours',
        ])->save();

        $event = TelegramWebhookEvent::query()->create([
            'tenant_id' => $bot->tenant_id,
            'telegram_bot_id' => $bot->id,
            'update_id' => 910012,
            'event_type' => 'chat_member',
            'status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'payload' => [
                'update_id' => 910012,
                'chat_member' => [
                    'chat' => ['id' => $communities[0]->telegram_chat_id, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'date' => now()->timestamp,
                    'old_chat_member' => ['status' => 'left', 'user' => ['id' => 111333, 'is_bot' => false]],
                    'new_chat_member' => ['status' => 'member', 'user' => ['id' => 111333, 'is_bot' => false]],
                ],
            ],
        ]);

        app(TelegramWebhookService::class)->processEvent($event);
        $this->assertSame(TelegramAccessCommunity::STATUS_JOINED, $row->fresh()->status);
    }

    public function test_identity_deep_link_reuse_fails_after_rotation(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $payload = \App\Services\Telegram\TelegramIdentityLink::payload($grant);
        $parsed = \App\Services\Telegram\TelegramIdentityLink::parse('/start '.$payload);
        $this->assertNotNull($parsed);
        $this->assertTrue(\App\Services\Telegram\TelegramIdentityLink::verify($grant->fresh(), $parsed['nonce'], $parsed['signature']));

        app(TelegramAccessGrantService::class)->linkIdentity($grant, 222333, 'u', 'U');
        $this->assertFalse(\App\Services\Telegram\TelegramIdentityLink::verify($grant->fresh(), $parsed['nonce'], $parsed['signature']));
    }

    public function test_identity_deep_link_expires_by_ttl(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $payload = \App\Services\Telegram\TelegramIdentityLink::payload($grant);
        $parsed = \App\Services\Telegram\TelegramIdentityLink::parse('/start '.$payload);
        $grant->forceFill(['created_at' => now()->subHours(73)])->save();
        config(['telegram.identity_link_ttl_hours' => 72]);
        $this->assertFalse(\App\Services\Telegram\TelegramIdentityLink::verify($grant->fresh(), $parsed['nonce'], $parsed['signature']));
    }

    public function test_third_party_entry_does_not_complete_delivery(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities, $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        app(TelegramAccessGrantService::class)->linkIdentity($grant, 100001, 'buyer', 'B');
        $row = $grant->communities()->first();
        $row->forceFill([
            'status' => TelegramAccessCommunity::STATUS_INVITE_READY,
            'invite_link' => 'https://t.me/+Ours',
        ])->save();

        $event = TelegramWebhookEvent::query()->create([
            'tenant_id' => $bot->tenant_id,
            'telegram_bot_id' => $bot->id,
            'update_id' => 910010,
            'event_type' => 'chat_member',
            'status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'payload' => [
                'update_id' => 910010,
                'chat_member' => [
                    'chat' => ['id' => $communities[0]->telegram_chat_id, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'date' => now()->timestamp,
                    'invite_link' => ['invite_link' => 'https://t.me/+Ours'],
                    'old_chat_member' => ['status' => 'left', 'user' => ['id' => 999999, 'is_bot' => false]],
                    'new_chat_member' => ['status' => 'member', 'user' => ['id' => 999999, 'is_bot' => false]],
                ],
            ],
        ]);

        app(TelegramWebhookService::class)->processEvent($event);
        $this->assertNotSame(TelegramAccessCommunity::STATUS_JOINED, $row->fresh()->status);
        $this->assertNotSame(TelegramAccessGrant::DELIVERY_COMPLETED, $grant->fresh()->delivery_status);
    }

    public function test_entry_without_linked_identity_is_not_completed(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities, $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $row = $grant->communities()->first();
        $row->forceFill([
            'status' => TelegramAccessCommunity::STATUS_INVITE_READY,
            'invite_link' => 'https://t.me/+Ours',
        ])->save();

        $this->assertNull($grant->fresh()->telegram_user_id);

        $event = TelegramWebhookEvent::query()->create([
            'tenant_id' => $bot->tenant_id,
            'telegram_bot_id' => $bot->id,
            'update_id' => 910011,
            'event_type' => 'chat_member',
            'status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'payload' => [
                'update_id' => 910011,
                'chat_member' => [
                    'chat' => ['id' => $communities[0]->telegram_chat_id, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'date' => now()->timestamp,
                    'invite_link' => ['invite_link' => 'https://t.me/+Ours'],
                    'old_chat_member' => ['status' => 'left', 'user' => ['id' => 424242, 'is_bot' => false]],
                    'new_chat_member' => ['status' => 'member', 'user' => ['id' => 424242, 'is_bot' => false]],
                ],
            ],
        ]);

        app(TelegramWebhookService::class)->processEvent($event);
        $this->assertNotSame(TelegramAccessCommunity::STATUS_JOINED, $row->fresh()->status);
    }

    public function test_redis_queue_connection_uses_dedicated_connection(): void
    {
        $connection = (string) config('queue.connections.redis.connection');
        $this->assertSame('queue', $connection);
        $this->assertArrayHasKey('queue', config('database.redis'));
    }

    public function test_mismatched_invite_link_is_rejected(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities, $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        app(TelegramAccessGrantService::class)->linkIdentity($grant, 333444, 'buyer2', 'B');
        $row = $grant->communities()->first();
        $row->forceFill([
            'status' => TelegramAccessCommunity::STATUS_INVITE_READY,
            'invite_link' => 'https://t.me/+Correct',
        ])->save();

        $event = TelegramWebhookEvent::query()->create([
            'tenant_id' => $bot->tenant_id,
            'telegram_bot_id' => $bot->id,
            'update_id' => 910002,
            'event_type' => 'chat_member',
            'status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'payload' => [
                'update_id' => 910002,
                'chat_member' => [
                    'chat' => ['id' => $communities[0]->telegram_chat_id, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'date' => now()->timestamp,
                    'invite_link' => ['invite_link' => 'https://t.me/+Wrong'],
                    'old_chat_member' => ['status' => 'left', 'user' => ['id' => 333444, 'is_bot' => false]],
                    'new_chat_member' => ['status' => 'member', 'user' => ['id' => 333444, 'is_bot' => false]],
                ],
            ],
        ]);

        app(TelegramWebhookService::class)->processEvent($event);
        $this->assertNotSame(TelegramAccessCommunity::STATUS_JOINED, $row->fresh()->status);
    }

    public function test_temporary_api_failure_is_retryable_by_job(): void
    {
        Http::fake([
            'api.telegram.org/bot*/createChatInviteLink' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Too Many Requests',
            ], 429),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        app(TelegramAccessGrantService::class)->linkIdentity($grant, 555666, 'x', 'X');

        $this->expectException(TelegramApiException::class);
        app(TelegramInviteService::class)->processGrant($grant->fresh(['bot', 'communities.community']));
    }

    public function test_renewal_concurrent_with_expiry_still_safe(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'UTC'));
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $grant->forceFill([
            'telegram_user_id' => 777,
            'access_ends_at' => '2026-11-08 12:00:00',
            'status' => TelegramAccessGrant::STATUS_COMPLETED,
            'delivery_status' => TelegramAccessGrant::DELIVERY_COMPLETED,
        ])->save();
        $grant->communities()->update(['status' => TelegramAccessCommunity::STATUS_JOINED]);

        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:01', 'UTC'));
        $renew = $this->makePaidOrder($seller, $buyer, $product);
        $renew->forceFill(['is_renewal' => true, 'metadata' => ['paid_at' => now()->utc()->toIso8601String()]])->save();
        app(TelegramAccessGrantService::class)->grantForOrderProduct($renew, $product, false);

        $stats = app(TelegramAccessExpiryService::class)->expireGrantById((int) $grant->id);
        $this->assertFalse($stats['expired']);
        $this->assertTrue($grant->fresh()->isCurrentlyValid());
        Carbon::setTestNow();
    }

    public function test_two_products_same_community_keep_member_on_one_expiry(): void
    {
        Http::fake([
            'api.telegram.org/bot*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => 'member']]),
            'api.telegram.org/bot*/banChatMember' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/bot*/unbanChatMember' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities] = $this->makeTelegramProduct($seller, 1);
        $productA = Product::query()->where('tenant_id', $seller->tenant_id)->where('type', Product::TYPE_TELEGRAM)->firstOrFail();

        $productB = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'Res B',
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
            'checkout_slug' => 'rb'.substr(uniqid(), -5),
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
            'telegram_community_id' => $communities[0]->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderA = $this->makePaidOrder($seller, $buyer, $productA);
        $orderB = $this->makePaidOrder($seller, $buyer, $productB);
        $grantA = app(TelegramAccessGrantService::class)->grantForOrderProduct($orderA, $productA, false);
        $grantB = app(TelegramAccessGrantService::class)->grantForOrderProduct($orderB, $productB, false);
        foreach ([$grantA, $grantB] as $g) {
            $g->forceFill([
                'telegram_user_id' => 888,
                'status' => TelegramAccessGrant::STATUS_COMPLETED,
                'delivery_status' => TelegramAccessGrant::DELIVERY_COMPLETED,
            ])->save();
            $g->communities()->update(['status' => TelegramAccessCommunity::STATUS_JOINED]);
        }

        $grantA->forceFill([
            'access_ends_at' => now()->utc()->subMinute()->format('Y-m-d H:i:s'),
        ])->save();

        $stats = app(TelegramAccessExpiryService::class)->expireGrantById((int) $grantA->id);
        $this->assertTrue($stats['expired']);
        $this->assertSame(0, $stats['removed']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'banChatMember'));
    }
}
