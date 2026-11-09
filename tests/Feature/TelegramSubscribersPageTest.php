<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Models\User;
use App\Services\Telegram\TelegramSellerSubscribersService;
use Tests\TestCase;

class TelegramSubscribersPageTest extends TestCase
{
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

    public function test_subscribers_service_lists_username_and_filters_telegram_products(): void
    {
        $seller = $this->makeSeller();
        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'name' => 'Maria Compradora',
            'email' => 'maria.tg@test.com',
            'email_verified_at' => now(),
        ]);

        $bot = TelegramBot::query()->create([
            'tenant_id' => $seller->tenant_id,
            'telegram_bot_id' => 990001,
            'username' => 'subs_bot',
            'first_name' => 'Subs',
            'token_encrypted' => '990001:AAHsubscribersTokenValue',
            'webhook_path_secret' => TelegramBot::makePathSecret(),
            'webhook_secret_token' => TelegramBot::makeWebhookSecretToken(),
            'status' => TelegramBot::STATUS_CONNECTED,
            'connected_at' => now(),
        ]);

        $community = TelegramCommunity::query()->create([
            'tenant_id' => $seller->tenant_id,
            'telegram_bot_id' => $bot->id,
            'telegram_chat_id' => -100990001,
            'title' => 'Grupo Assinantes',
            'type' => TelegramCommunity::TYPE_SUPERGROUP,
            'status' => TelegramCommunity::STATUS_LINKED,
            'is_admin' => true,
            'ready_for_delivery' => true,
        ]);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'VIP Telegram',
            'is_active' => true,
            'checkout_slug' => 'tg-subs-'.substr(uniqid(), -5),
        ]);

        $otherProduct = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'Outro Telegram',
            'is_active' => true,
            'checkout_slug' => 'tg-subs2-'.substr(uniqid(), -5),
        ]);

        $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_LINK,
            'name' => 'Nao Telegram',
            'is_active' => true,
            'checkout_slug' => 'link-'.substr(uniqid(), -5),
        ]);

        $order = Order::create([
            'tenant_id' => $seller->tenant_id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'test',
        ]);

        $grant = TelegramAccessGrant::query()->create([
            'tenant_id' => $seller->tenant_id,
            'order_id' => $order->id,
            'latest_order_id' => $order->id,
            'product_id' => (string) $product->id,
            'user_id' => $buyer->id,
            'telegram_bot_id' => $bot->id,
            'status' => TelegramAccessGrant::STATUS_COMPLETED,
            'delivery_status' => TelegramAccessGrant::DELIVERY_COMPLETED,
            'paid_at' => now()->utc()->format('Y-m-d H:i:s'),
            'access_starts_at' => now()->utc()->format('Y-m-d H:i:s'),
            'access_ends_at' => now()->addDays(30)->utc()->format('Y-m-d H:i:s'),
            'is_lifetime' => false,
            'telegram_user_id' => 555001,
            'telegram_username' => 'maria_tg',
            'identity_linked_at' => now()->utc()->format('Y-m-d H:i:s'),
        ]);

        TelegramAccessCommunity::query()->create([
            'tenant_id' => $seller->tenant_id,
            'telegram_access_grant_id' => $grant->id,
            'telegram_community_id' => $community->id,
            'telegram_chat_id' => $community->telegram_chat_id,
            'status' => TelegramAccessCommunity::STATUS_JOINED,
            'joined_at' => now()->subDay(),
        ]);

        $service = app(TelegramSellerSubscribersService::class);

        $this->assertTrue($service->tenantHasActiveTelegramProduct((int) $seller->tenant_id, $seller));

        $products = $service->telegramProductsForTenant((int) $seller->tenant_id, $seller);
        $this->assertCount(2, $products);
        $this->assertTrue(collect($products)->every(fn ($p) => in_array($p['name'], ['VIP Telegram', 'Outro Telegram'], true)));

        $page = $service->paginate((int) $seller->tenant_id, null, null, null, 20, $seller);
        $this->assertCount(1, $page->items());
        $row = $page->items()[0];
        $this->assertSame('@maria_tg', $row['telegram_username_label']);
        $this->assertSame('maria.tg@test.com', $row['buyer_email']);
        $this->assertSame('VIP Telegram', $row['product']['name']);
        $this->assertNotEmpty($row['paid_at_label']);
        $this->assertNotEmpty($row['entry_at_label']);
        $this->assertSame('joined', $row['entry_kind']);
        $this->assertNotEmpty($row['access_ends_at_label']);

        $byEmail = $service->paginate((int) $seller->tenant_id, null, 'maria.tg', null, 20, $seller);
        $this->assertCount(1, $byEmail->items());

        $byName = $service->paginate((int) $seller->tenant_id, null, null, 'Maria', 20, $seller);
        $this->assertCount(1, $byName->items());

        $byOtherProduct = $service->paginate((int) $seller->tenant_id, (string) $otherProduct->id, null, null, 20, $seller);
        $this->assertCount(0, $byOtherProduct->items());
    }

    public function test_subscribers_menu_hidden_without_active_telegram_product(): void
    {
        $seller = $this->makeSeller();
        $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_LINK,
            'name' => 'So link',
            'is_active' => true,
            'checkout_slug' => 'link-'.substr(uniqid(), -5),
        ]);

        $service = app(TelegramSellerSubscribersService::class);
        $this->assertFalse($service->tenantHasActiveTelegramProduct((int) $seller->tenant_id, $seller));
        $this->assertSame([], $service->telegramProductsForTenant((int) $seller->tenant_id, $seller));
    }
}
