<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\ProductTelegramSetting;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use App\Services\Telegram\TelegramModuleSettings;
use App\Services\Telegram\TelegramProductDeliveryService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TelegramProductDeliverableTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $botOverrides
     * @param  array<string, mixed>  $communityOverrides
     * @return array{0: TelegramBot, 1: TelegramCommunity}
     */
    private function makeBotWithCommunity(int $tenantId, array $botOverrides = [], array $communityOverrides = []): array
    {
        $bot = TelegramBot::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'telegram_bot_id' => 700000 + $tenantId,
            'username' => 'stacker_bot_'.$tenantId,
            'first_name' => 'Stacker',
            'token_encrypted' => '700000:AAHtoken_for_tests',
            'webhook_path_secret' => TelegramBot::makePathSecret(),
            'webhook_secret_token' => TelegramBot::makeWebhookSecretToken(),
            'status' => TelegramBot::STATUS_CONNECTED,
            'connected_at' => now(),
        ], $botOverrides));

        $community = TelegramCommunity::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'telegram_bot_id' => $bot->id,
            'telegram_chat_id' => -1001000 - $tenantId,
            'title' => 'Grupo VIP '.$tenantId,
            'type' => TelegramCommunity::TYPE_SUPERGROUP,
            'status' => TelegramCommunity::STATUS_LINKED,
            'is_admin' => true,
            'permissions' => [
                ['key' => 'invite_links', 'status' => 'ok', 'required' => true],
            ],
            'ready_for_delivery' => true,
        ], $communityOverrides));

        return [$bot, $community];
    }

    public function test_telegram_type_appears_in_product_create_options(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->get(route('produtos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Produtos/Index')
                ->has('productTypes')
                ->where('productTypes', function ($types) {
                    $values = collect($types)->pluck('value')->all();

                    return in_array(Product::TYPE_TELEGRAM, $values, true)
                        && in_array(Product::TYPE_LINK, $values, true)
                        && in_array(Product::TYPE_AREA_MEMBROS, $values, true)
                        && in_array(Product::TYPE_AREA_MEMBROS_EXTERNA, $values, true)
                        && in_array(Product::TYPE_LINK_PAGAMENTO, $values, true);
                })
                ->has('telegram_delivery.bots')
            );
    }

    public function test_can_create_telegram_product_as_draft_with_communities(): void
    {
        $seller = $this->makeSeller();
        [$bot, $community] = $this->makeBotWithCommunity((int) $seller->tenant_id);
        $second = TelegramCommunity::query()->create([
            'tenant_id' => $seller->tenant_id,
            'telegram_bot_id' => $bot->id,
            'telegram_chat_id' => -1002000,
            'title' => 'Canal Extra',
            'type' => TelegramCommunity::TYPE_CHANNEL,
            'status' => TelegramCommunity::STATUS_LINKED,
            'is_admin' => true,
            'ready_for_delivery' => true,
        ]);

        $response = $this->actingAs($seller)->post(route('produtos.store'), [
            'name' => 'Acesso Telegram VIP',
            'description' => 'Grupo privado',
            'category' => 'tecnologia_e_inovacao',
            'type' => Product::TYPE_TELEGRAM,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 97,
            'currency' => 'BRL',
            'is_active' => '1',
            'telegram' => [
                'bot_id' => $bot->id,
                'community_ids' => [$community->id, $second->id],
                'access_duration_preset' => '30',
                'invite_expiration_hours' => 24,
                'welcome_message' => 'Bem-vindo ao grupo!',
                'auto_remove_on_expiry' => '1',
            ],
        ]);

        $response->assertRedirect(route('produtos.index'));

        $product = Product::query()->where('tenant_id', $seller->tenant_id)->where('name', 'Acesso Telegram VIP')->first();
        $this->assertNotNull($product);
        $this->assertSame(Product::TYPE_TELEGRAM, $product->type);
        $this->assertFalse((bool) $product->is_active);

        $setting = ProductTelegramSetting::query()->where('product_id', (string) $product->id)->first();
        $this->assertNotNull($setting);
        $this->assertSame((int) $bot->id, (int) $setting->telegram_bot_id);
        $this->assertSame(30, (int) $setting->access_duration);
        $this->assertFalse((bool) $setting->is_lifetime);
        $this->assertSame(24, (int) $setting->invite_expiration_hours);
        $this->assertSame('Bem-vindo ao grupo!', $setting->welcome_message);

        $linked = DB::table('product_telegram_communities')
            ->where('product_id', (string) $product->id)
            ->pluck('telegram_community_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        $this->assertSame([(int) $community->id, (int) $second->id], $linked);
    }

    public function test_can_edit_telegram_settings_and_lifetime_custom_duration(): void
    {
        $seller = $this->makeSeller();
        [$bot, $community] = $this->makeBotWithCommunity((int) $seller->tenant_id);
        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'Telegram Edit',
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
        ]);

        app(TelegramProductDeliveryService::class)->syncForProduct($product, (int) $seller->tenant_id, [
            'bot_id' => $bot->id,
            'community_ids' => [$community->id],
            'access_duration_preset' => '7',
            'invite_expiration_hours' => 12,
        ]);

        $this->actingAs($seller)->put(route('produtos.update', $product), [
            'name' => 'Telegram Edit',
            'description' => '',
            'category' => 'tecnologia_e_inovacao',
            'type' => Product::TYPE_TELEGRAM,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 50,
            'currency' => 'BRL',
            'is_active' => '0',
            'telegram' => [
                'bot_id' => $bot->id,
                'community_ids' => [$community->id],
                'access_duration_preset' => 'lifetime',
                'is_lifetime' => '1',
                'invite_expiration_hours' => 48,
                'welcome_message' => 'Vitalício',
                'auto_remove_on_expiry' => '0',
            ],
        ])->assertRedirect();

        $setting = ProductTelegramSetting::query()->where('product_id', (string) $product->id)->first();
        $this->assertTrue((bool) $setting->is_lifetime);
        $this->assertNull($setting->access_duration);
        $this->assertSame(48, (int) $setting->invite_expiration_hours);

        $this->actingAs($seller)->put(route('produtos.update', $product), [
            'name' => 'Telegram Edit',
            'description' => '',
            'category' => 'tecnologia_e_inovacao',
            'type' => Product::TYPE_TELEGRAM,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 50,
            'currency' => 'BRL',
            'is_active' => '0',
            'telegram' => [
                'bot_id' => $bot->id,
                'community_ids' => [$community->id],
                'access_duration_preset' => 'custom',
                'access_duration_days' => 45,
                'invite_expiration_hours' => 6,
                'welcome_message' => 'Custom',
                'auto_remove_on_expiry' => '1',
            ],
        ])->assertRedirect();

        $setting = $setting->fresh();
        $this->assertFalse((bool) $setting->is_lifetime);
        $this->assertSame(45, (int) $setting->access_duration);
    }

    public function test_seller_without_bot_can_still_create_draft(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)->post(route('produtos.store'), [
            'name' => 'Telegram sem bot',
            'category' => 'tecnologia_e_inovacao',
            'type' => Product::TYPE_TELEGRAM,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 20,
            'currency' => 'BRL',
            'is_active' => '0',
            'telegram' => [
                'access_duration_preset' => '15',
                'invite_expiration_hours' => 24,
            ],
        ])->assertRedirect(route('produtos.index'));

        $product = Product::query()->where('name', 'Telegram sem bot')->first();
        $this->assertNotNull($product);
        $this->assertFalse((bool) $product->is_active);
    }

    public function test_disconnected_bot_cannot_be_linked(): void
    {
        $seller = $this->makeSeller();
        [$bot, $community] = $this->makeBotWithCommunity((int) $seller->tenant_id, [
            'status' => TelegramBot::STATUS_DISCONNECTED,
        ]);
        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
        ]);

        $this->actingAs($seller)->put(route('produtos.update', $product), [
            'name' => $product->name,
            'category' => 'tecnologia_e_inovacao',
            'type' => Product::TYPE_TELEGRAM,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 20,
            'currency' => 'BRL',
            'is_active' => '0',
            'telegram' => [
                'bot_id' => $bot->id,
                'community_ids' => [$community->id],
                'access_duration_preset' => '30',
                'invite_expiration_hours' => 24,
            ],
        ])->assertSessionHas('error');
    }

    public function test_community_without_permissions_blocks_readiness_not_draft_save(): void
    {
        $seller = $this->makeSeller();
        [$bot, $community] = $this->makeBotWithCommunity((int) $seller->tenant_id, [], [
            'ready_for_delivery' => false,
            'title' => 'Sem permissão',
        ]);
        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
        ]);

        app(TelegramProductDeliveryService::class)->syncForProduct($product, (int) $seller->tenant_id, [
            'bot_id' => $bot->id,
            'community_ids' => [$community->id],
            'access_duration_preset' => '30',
            'invite_expiration_hours' => 24,
        ]);

        $readiness = app(TelegramProductDeliveryService::class)->evaluateReadiness((int) $seller->tenant_id, $product);
        $this->assertFalse($readiness['ready_for_sale']);
        $this->assertTrue(collect($readiness['blocking_reasons'])->contains(
            fn ($r) => str_contains($r, 'permissões')
        ));
    }

    public function test_multi_tenant_isolation_for_bot_and_community(): void
    {
        $sellerA = $this->makeSeller();
        $sellerB = $this->makeSeller();
        [$botB, $communityB] = $this->makeBotWithCommunity((int) $sellerB->tenant_id);

        $product = $this->createTestProduct([
            'tenant_id' => $sellerA->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
        ]);

        $this->actingAs($sellerA)->put(route('produtos.update', $product), [
            'name' => $product->name,
            'category' => 'tecnologia_e_inovacao',
            'type' => Product::TYPE_TELEGRAM,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 20,
            'currency' => 'BRL',
            'is_active' => '0',
            'telegram' => [
                'bot_id' => $botB->id,
                'community_ids' => [$communityB->id],
                'access_duration_preset' => '30',
                'invite_expiration_hours' => 24,
            ],
        ])->assertSessionHas('error');
    }

    public function test_admin_sales_disabled_keeps_edit_allowed_but_blocks_publish_readiness(): void
    {
        $seller = $this->makeSeller();
        [$bot, $community] = $this->makeBotWithCommunity((int) $seller->tenant_id);
        TelegramModuleSettings::save([
            'sales_enabled' => false,
        ]);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'is_active' => false,
            'category' => 'tecnologia_e_inovacao',
        ]);

        $this->actingAs($seller)->put(route('produtos.update', $product), [
            'name' => 'Mantém edição',
            'category' => 'tecnologia_e_inovacao',
            'type' => Product::TYPE_TELEGRAM,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 33,
            'currency' => 'BRL',
            'is_active' => '0',
            'telegram' => [
                'bot_id' => $bot->id,
                'community_ids' => [$community->id],
                'access_duration_preset' => '90',
                'invite_expiration_hours' => 24,
                'welcome_message' => 'ok',
            ],
        ])->assertRedirect();

        $this->assertSame('Mantém edição', $product->fresh()->name);
        $readiness = app(TelegramProductDeliveryService::class)->evaluateReadiness((int) $seller->tenant_id, $product->fresh());
        $this->assertFalse($readiness['ready_for_sale']);
    }

    public function test_publication_and_checkout_blocked_for_telegram_products(): void
    {
        $seller = $this->makeSeller();
        [$bot, $community] = $this->makeBotWithCommunity((int) $seller->tenant_id);
        $product = $this->createTestProduct([
            'tenant_id' => $seller->tenant_id,
            'type' => Product::TYPE_TELEGRAM,
            'name' => 'Checkout Telegram',
            'is_active' => false,
            'approval_status' => Product::APPROVAL_APPROVED,
            'category' => 'tecnologia_e_inovacao',
            'checkout_slug' => 'tgchk01',
        ]);

        app(TelegramProductDeliveryService::class)->syncForProduct($product, (int) $seller->tenant_id, [
            'bot_id' => $bot->id,
            'community_ids' => [$community->id],
            'access_duration_preset' => '30',
            'invite_expiration_hours' => 24,
        ]);

        $this->actingAs($seller)->put(route('produtos.update', $product), [
            'name' => $product->name,
            'category' => 'tecnologia_e_inovacao',
            'type' => Product::TYPE_TELEGRAM,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 40,
            'currency' => 'BRL',
            'is_active' => '1',
            'telegram' => [
                'bot_id' => $bot->id,
                'community_ids' => [$community->id],
                'access_duration_preset' => '30',
                'invite_expiration_hours' => 24,
            ],
        ])->assertSessionHas('error');

        // Mesmo forçando ativo no banco, checkout deve 404 na Etapa 3.1.
        $product->forceFill(['is_active' => true])->save();
        $this->assertFalse($product->fresh()->isAvailableForPurchase());
        $this->get(route('checkout.show', ['slug' => 'tgchk01']))->assertNotFound();
    }

    public function test_existing_deliverable_types_still_work(): void
    {
        $seller = $this->makeSeller();

        foreach ([
            Product::TYPE_LINK,
            Product::TYPE_LINK_PAGAMENTO,
            Product::TYPE_AREA_MEMBROS,
            Product::TYPE_AREA_MEMBROS_EXTERNA,
        ] as $type) {
            $this->actingAs($seller)->post(route('produtos.store'), [
                'name' => 'Produto '.$type,
                'category' => 'tecnologia_e_inovacao',
                'type' => $type,
                'billing_type' => Product::BILLING_ONE_TIME,
                'price' => 10,
                'currency' => 'BRL',
                'is_active' => '0',
            ])->assertRedirect(route('produtos.index'));

            $this->assertDatabaseHas('products', [
                'tenant_id' => $seller->tenant_id,
                'name' => 'Produto '.$type,
                'type' => $type,
            ]);
        }
    }

    public function test_module_disabled_hides_telegram_type_for_new_products(): void
    {
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::TELEGRAM, false);
        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->get(route('produtos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('productTypes', function ($types) {
                    return ! collect($types)->pluck('value')->contains(Product::TYPE_TELEGRAM);
                })
            );
    }
}
