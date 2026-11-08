<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Http\Middleware\EnsureInstalled;
use App\Jobs\ProcessTelegramDeliveryJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTelegramSetting;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Models\TelegramDeliveryIncident;
use App\Models\TelegramWebhookEvent;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use App\Services\Telegram\TelegramAccessGrantService;
use App\Services\Telegram\TelegramInviteService;
use App\Services\Telegram\TelegramModuleSettings;
use App\Services\Telegram\TelegramWebhookService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramAccessDeliveryTest extends TestCase
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
        config(['app.timezone' => 'America/Sao_Paulo']);
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
    private function makeTelegramProduct(User $seller, int $communityCount = 2, bool $lifetime = false, int $days = 30): array
    {
        $bot = TelegramBot::query()->create([
            'tenant_id' => $seller->tenant_id,
            'telegram_bot_id' => 880000 + (int) $seller->tenant_id,
            'username' => 'delivery_bot_'.$seller->tenant_id,
            'first_name' => 'Delivery',
            'token_encrypted' => '880000:AAHdeliveryTokenValue',
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
                'telegram_chat_id' => -1009000 - $i - ((int) $seller->tenant_id * 10),
                'title' => 'Comunidade '.$i,
                'type' => $i === 0 ? TelegramCommunity::TYPE_SUPERGROUP : TelegramCommunity::TYPE_CHANNEL,
                'status' => TelegramCommunity::STATUS_LINKED,
                'is_admin' => true,
                'ready_for_delivery' => true,
            ]);
        }

        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'Grupo VIP Telegram',
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
            'checkout_slug' => 'tg'.substr(uniqid(), -5),
        ]);

        $setting = ProductTelegramSetting::query()->create([
            'tenant_id' => $seller->tenant_id,
            'product_id' => (string) $product->id,
            'telegram_bot_id' => $bot->id,
            'access_duration' => $lifetime ? null : $days,
            'access_duration_unit' => 'days',
            'is_lifetime' => $lifetime,
            'invite_expiration_hours' => 24,
            'welcome_message' => 'Bem-vindo!',
            'auto_remove_on_expiry' => true,
        ]);

        foreach ($communities as $community) {
            \Illuminate\Support\Facades\DB::table('product_telegram_communities')->insert([
                'tenant_id' => $seller->tenant_id,
                'product_id' => (string) $product->id,
                'telegram_community_id' => $community->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $setting->setRelation('communities', collect($communities));

        return [$bot, $communities, $product];
    }

    private function makePaidOrder(User $seller, User $buyer, Product $product, ?Carbon $paidAt = null): Order
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
            'metadata' => [
                'paid_at' => $paidAt->toIso8601String(),
            ],
        ]);
    }

    private function fakeInviteApi(): void
    {
        Http::fake([
            'api.telegram.org/bot*/createChatInviteLink' => Http::response([
                'ok' => true,
                'result' => [
                    'invite_link' => 'https://t.me/+InviteTestLink',
                    'expire_date' => now()->addDay()->timestamp,
                ],
            ]),
            'api.telegram.org/bot*/approveChatJoinRequest' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    public function test_access_window_starts_at_payment_confirmation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 14:00:00', 'America/Sao_Paulo')->utc());
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, false, 30);
        $paidAt = Carbon::parse('2026-11-08 14:00:00', 'America/Sao_Paulo')->utc();
        $order = $this->makePaidOrder($seller, $buyer, $product, $paidAt);

        Queue::fake();
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);

        $this->assertSame($paidAt->utc()->format('Y-m-d H:i:s'), $grant->getAttributes()['access_starts_at']);
        $this->assertSame(
            $paidAt->copy()->addDays(30)->utc()->format('Y-m-d H:i:s'),
            $grant->getAttributes()['access_ends_at']
        );
        $this->assertFalse($grant->is_lifetime);
        $this->assertSame('08/12/2026 14:00', $grant->displayInAppTimezone('access_ends_at'));

        Carbon::setTestNow();
    }

    public function test_later_entry_does_not_change_access_end(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities, $product] = $this->makeTelegramProduct($seller, 1, false, 30);
        $paidAt = Carbon::parse('2026-11-08 17:00:00', 'UTC');
        $order = $this->makePaidOrder($seller, $buyer, $product, $paidAt);

        Queue::fake();
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        app(TelegramAccessGrantService::class)->linkIdentity($grant, 555001, 'buyer_tg', 'Buyer');
        $grant->refresh();
        $endsRaw = $grant->getAttributes()['access_ends_at'] ?? null;
        $startsRaw = $grant->getAttributes()['access_starts_at'] ?? null;

        $row = $grant->communities()->first();
        app(TelegramInviteService::class)->confirmEntry(
            $grant,
            $row,
            555001,
            'buyer_tg',
            'Buyer',
            Carbon::parse('2026-11-20 12:00:00', 'UTC'),
        );

        $grant->refresh();
        $this->assertSame($endsRaw, $grant->getAttributes()['access_ends_at'] ?? null);
        $this->assertSame($startsRaw, $grant->getAttributes()['access_starts_at'] ?? null);
        $this->assertSame($paidAt->utc()->format('Y-m-d H:i:s'), $startsRaw);
        $this->assertSame(TelegramAccessGrant::DELIVERY_COMPLETED, $grant->delivery_status);
        $this->assertSame(555001, (int) $grant->telegram_user_id);
    }

    public function test_lifetime_has_no_automatic_end(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, true);
        $order = $this->makePaidOrder($seller, $buyer, $product);

        Queue::fake();
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);

        $this->assertTrue($grant->is_lifetime);
        $this->assertNull($grant->access_ends_at);
    }

    public function test_partial_delivery_with_multiple_communities(): void
    {
        $this->fakeInviteApi();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities, $product] = $this->makeTelegramProduct($seller, 2);
        $order = $this->makePaidOrder($seller, $buyer, $product);

        Queue::fake();
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        app(TelegramAccessGrantService::class)->linkIdentity($grant, 777001, 'partial_user', 'Partial');
        $grant = app(TelegramInviteService::class)->processGrant($grant->fresh(['bot', 'communities.community', 'product']));

        $this->assertCount(2, $grant->communities);
        $this->assertSame(TelegramAccessGrant::DELIVERY_PENDING, $grant->delivery_status);

        $first = $grant->communities->first();
        app(TelegramInviteService::class)->confirmEntry($grant, $first, 777001, 'partial_user');

        $grant->refresh();
        $this->assertSame(TelegramAccessGrant::DELIVERY_PARTIAL, $grant->delivery_status);
        $this->assertSame(TelegramAccessGrant::STATUS_PARTIAL, $grant->status);
        $this->assertTrue(
            TelegramAccessEvent::query()
                ->where('telegram_access_grant_id', $grant->id)
                ->where('event_type', TelegramAccessEvent::DELIVERY_PARTIAL)
                ->exists()
        );

        $second = $grant->communities()->where('id', '!=', $first->id)->first();
        app(TelegramInviteService::class)->confirmEntry($grant, $second, 777001, 'partial_user');
        $grant->refresh();
        $this->assertSame(TelegramAccessGrant::DELIVERY_COMPLETED, $grant->delivery_status);
    }

    public function test_invite_is_not_considered_completed_delivery(): void
    {
        $this->fakeInviteApi();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1);
        $order = $this->makePaidOrder($seller, $buyer, $product);

        Queue::fake();
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $grant = app(TelegramInviteService::class)->processGrant($grant);

        $row = $grant->communities->first();
        $this->assertSame(TelegramAccessCommunity::STATUS_INVITE_READY, $row->status);
        $this->assertNotEmpty($row->invite_link);
        $this->assertNotSame(TelegramAccessGrant::DELIVERY_COMPLETED, $grant->delivery_status);
    }

    public function test_persistent_invite_failure_opens_incident_without_auto_compensation(): void
    {
        Http::fake([
            'api.telegram.org/bot*/createChatInviteLink' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: not enough rights',
            ], 200),
        ]);

        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1);
        $order = $this->makePaidOrder($seller, $buyer, $product);

        Queue::fake();
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        app(TelegramInviteService::class)->processGrant($grant);

        $incident = TelegramDeliveryIncident::query()->where('telegram_access_grant_id', $grant->id)->first();
        $this->assertNotNull($incident);
        $this->assertSame(TelegramDeliveryIncident::COMPENSATION_NONE, $incident->compensation_status);
        $this->assertNull($incident->compensation_applied_at);
        $this->assertTrue(
            TelegramAccessEvent::query()
                ->where('telegram_access_grant_id', $grant->id)
                ->where('event_type', TelegramAccessEvent::TECHNICAL_FAILURE)
                ->exists()
        );
    }

    public function test_reprocess_is_idempotent_and_does_not_duplicate_grant(): void
    {
        $this->fakeInviteApi();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1);
        $order = $this->makePaidOrder($seller, $buyer, $product);

        Queue::fake();
        $service = app(TelegramAccessGrantService::class);
        $first = $service->grantForOrderProduct($order, $product, false);
        $second = $service->grantForOrderProduct($order, $product, false);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TelegramAccessGrant::query()->where('order_id', $order->id)->count());

        event(new OrderCompleted($order));
        $this->assertSame(1, TelegramAccessGrant::query()->where('order_id', $order->id)->count());
        Queue::assertPushed(ProcessTelegramDeliveryJob::class);
    }

    public function test_timezone_display_uses_platform_timezone_while_storage_is_utc(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1, false, 7);
        $paidAt = Carbon::parse('2026-11-08 17:00:00', 'UTC');
        $order = $this->makePaidOrder($seller, $buyer, $product, $paidAt);

        Queue::fake();
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $payload = $grant->toBuyerArray();

        $this->assertSame('08/11/2026 14:00', $payload['access_starts_at_label']);
        $this->assertSame('2026-11-08T17:00:00+00:00', $payload['access_starts_at']);
    }

    public function test_out_of_order_entry_event_still_confirms_join(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities, $product] = $this->makeTelegramProduct($seller, 1);
        $order = $this->makePaidOrder($seller, $buyer, $product);

        Queue::fake();
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $row = $grant->communities()->first();
        $this->assertSame(TelegramAccessCommunity::STATUS_PENDING, $row->status);

        // Identidade deve existir antes da confirmação comercial de entrada.
        app(TelegramAccessGrantService::class)->linkIdentity($grant, 424242, 'late_joiner', 'Late');
        $row->forceFill([
            'status' => TelegramAccessCommunity::STATUS_INVITE_READY,
            'invite_link' => 'https://t.me/+InviteAuth',
            'invite_link_expires_at' => now()->addDay(),
        ])->save();

        $event = TelegramWebhookEvent::query()->create([
            'tenant_id' => $bot->tenant_id,
            'telegram_bot_id' => $bot->id,
            'update_id' => 900001,
            'event_type' => 'chat_member',
            'status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'payload' => [
                'update_id' => 900001,
                'chat_member' => [
                    'chat' => ['id' => $communities[0]->telegram_chat_id, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'date' => now()->timestamp,
                    'invite_link' => ['invite_link' => 'https://t.me/+InviteAuth'],
                    'old_chat_member' => ['status' => 'left', 'user' => ['id' => 424242, 'is_bot' => false]],
                    'new_chat_member' => ['status' => 'member', 'user' => ['id' => 424242, 'username' => 'late_joiner', 'is_bot' => false]],
                ],
            ],
        ]);

        app(TelegramWebhookService::class)->processEvent($event);

        $row->refresh();
        $grant->refresh();
        $this->assertSame(TelegramAccessCommunity::STATUS_JOINED, $row->status);
        $this->assertSame(424242, (int) $grant->telegram_user_id);
        $this->assertSame(TelegramAccessGrant::DELIVERY_COMPLETED, $grant->delivery_status);
    }

    public function test_third_party_entry_without_identity_does_not_complete_delivery(): void
    {
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [$bot, $communities, $product] = $this->makeTelegramProduct($seller, 1);
        $order = $this->makePaidOrder($seller, $buyer, $product);

        Queue::fake();
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);
        $row = $grant->communities()->first();
        $row->forceFill([
            'status' => TelegramAccessCommunity::STATUS_INVITE_READY,
            'invite_link' => 'https://t.me/+OnlyBuyer',
        ])->save();

        $event = TelegramWebhookEvent::query()->create([
            'tenant_id' => $bot->tenant_id,
            'telegram_bot_id' => $bot->id,
            'update_id' => 900002,
            'event_type' => 'chat_member',
            'status' => TelegramWebhookEvent::STATUS_RECEIVED,
            'payload' => [
                'update_id' => 900002,
                'chat_member' => [
                    'chat' => ['id' => $communities[0]->telegram_chat_id, 'type' => 'supergroup'],
                    'from' => ['id' => 1, 'is_bot' => false],
                    'date' => now()->timestamp,
                    'old_chat_member' => ['status' => 'left', 'user' => ['id' => 999001, 'is_bot' => false]],
                    'new_chat_member' => ['status' => 'member', 'user' => ['id' => 999001, 'is_bot' => false]],
                ],
            ],
        ]);

        app(TelegramWebhookService::class)->processEvent($event);

        $row->refresh();
        $grant->refresh();
        $this->assertNotSame(TelegramAccessCommunity::STATUS_JOINED, $row->status);
        $this->assertNull($grant->telegram_user_id);
    }

    public function test_order_completed_listener_creates_grant_and_audit_history(): void
    {
        Queue::fake();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 2);
        $order = $this->makePaidOrder($seller, $buyer, $product);

        event(new OrderCompleted($order));

        $grant = TelegramAccessGrant::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($grant);
        $this->assertSame(2, $grant->communities()->count());
        $this->assertTrue(
            TelegramAccessEvent::query()
                ->where('telegram_access_grant_id', $grant->id)
                ->where('event_type', TelegramAccessEvent::PAYMENT_CONFIRMED)
                ->exists()
        );
        $this->assertTrue(
            TelegramAccessEvent::query()
                ->where('telegram_access_grant_id', $grant->id)
                ->where('event_type', TelegramAccessEvent::ACCESS_GRANTED)
                ->exists()
        );
        Queue::assertPushed(ProcessTelegramDeliveryJob::class, fn ($job) => $job->grantId === (int) $grant->id);
    }

    public function test_buyer_delivery_page_and_checkout_notice_payload(): void
    {
        Queue::fake();
        $seller = $this->makeSeller();
        $buyer = $this->makeBuyer();
        [, , $product] = $this->makeTelegramProduct($seller, 1);
        $order = $this->makePaidOrder($seller, $buyer, $product);
        $grant = app(TelegramAccessGrantService::class)->grantForOrderProduct($order, $product, false);

        $this->actingAs($buyer)
            ->get(route('painel-cliente.telegram.show', ['grant' => $grant->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Cliente/TelegramAccess')
                ->where('grant.id', $grant->id)
                ->where('grant.access_starts_notice', TelegramAccessGrant::accessStartsNotice())
            );

        // Vendas públicas ainda bloqueadas, mas o aviso está pronto no payload quando o produto for Telegram.
        $product->forceFill([
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ])->save();
        config(['telegram.product_sales_operational' => true]);
        $this->get(route('checkout.show', ['slug' => $product->checkout_slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('telegram_access_notice', config('telegram.access_starts_notice'))
            );
        config(['telegram.product_sales_operational' => false]);
    }

    public function test_sales_remain_non_operational_by_default(): void
    {
        $this->assertFalse((bool) config('telegram.product_sales_operational'));
        $seller = $this->makeSeller();
        [, , $product] = $this->makeTelegramProduct($seller, 1);
        $product->forceFill([
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ])->save();

        $this->assertFalse($product->fresh()->isAvailableForPurchase());
    }
}
