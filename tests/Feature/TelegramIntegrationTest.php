<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Jobs\ProcessTelegramWebhookJob;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Models\TelegramWebhookEvent;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use App\Services\Telegram\TelegramModuleSettings;
use App\Services\Telegram\TelegramPolicy;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TelegramIntegrationTest extends TestCase
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
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGetMe(array $overrides = []): void
    {
        $me = array_merge([
            'id' => 991122,
            'is_bot' => true,
            'first_name' => 'Stacker Bot',
            'username' => 'stacker_test_bot',
            'can_join_groups' => true,
            'can_read_all_group_messages' => false,
            'supports_inline_queries' => false,
        ], $overrides);

        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response(['ok' => true, 'result' => $me]),
            'api.telegram.org/bot*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/bot*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/bot*/getChat' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => -100555,
                    'type' => 'supergroup',
                    'title' => 'Grupo VIP',
                ],
            ]),
            'api.telegram.org/bot*/getChatMember' => Http::response([
                'ok' => true,
                'result' => [
                    'status' => 'administrator',
                    'user' => ['id' => 991122, 'is_bot' => true],
                    'can_invite_users' => true,
                    'can_restrict_members' => true,
                    'can_manage_join_requests' => true,
                    'can_post_messages' => false,
                ],
            ]),
        ]);
    }

    public function test_valid_token_connects_bot_with_encrypted_token(): void
    {
        $this->fakeGetMe();
        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->post(route('integrations.telegram.bots.store'), [
                'token' => '991122:AAHsecretTokenValue_123',
            ])
            ->assertRedirect();

        $bot = TelegramBot::query()->forTenant((int) $seller->tenant_id)->first();
        $this->assertNotNull($bot);
        $this->assertSame(991122, (int) $bot->telegram_bot_id);
        $this->assertSame('stacker_test_bot', $bot->username);
        $this->assertSame(TelegramBot::STATUS_CONNECTED, $bot->status);
        $this->assertSame('991122:AAHsecretTokenValue_123', $bot->plainToken());
        $this->assertNotSame('991122:AAHsecretTokenValue_123', $bot->getRawOriginal('token_encrypted'));

        $this->actingAs($seller)
            ->get(route('integrations.telegram.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Integrations/Telegram/Index')
                ->has('bots', 1)
                ->where('bots.0.has_token', true)
                ->missing('bots.0.token')
                ->missing('bots.0.token_encrypted')
                ->where('wizard.steps.0.done', true)
            );
    }

    public function test_invalid_token_is_rejected(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 401,
                'description' => 'Unauthorized',
            ], 401),
        ]);

        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->from(route('integrations.telegram.index'))
            ->post(route('integrations.telegram.bots.store'), [
                'token' => '1:invalid',
            ])
            ->assertRedirect(route('integrations.telegram.index'))
            ->assertSessionHasErrors('token');

        $this->assertSame(0, TelegramBot::query()->count());
    }

    public function test_bot_cannot_be_connected_by_another_tenant(): void
    {
        $this->fakeGetMe();
        $owner = $this->makeSeller();
        $intruder = $this->makeSeller();

        $this->actingAs($owner)
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHowner'])
            ->assertRedirect();

        Http::fake([
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 991122,
                    'is_bot' => true,
                    'first_name' => 'Stacker Bot',
                    'username' => 'stacker_test_bot',
                ],
            ]),
        ]);

        $this->actingAs($intruder)
            ->from(route('integrations.telegram.index'))
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHintruder'])
            ->assertSessionHasErrors('token');

        $this->assertSame(1, TelegramBot::query()->where('telegram_bot_id', 991122)->count());
        $this->assertSame((int) $owner->tenant_id, (int) TelegramBot::query()->first()->tenant_id);
    }

    public function test_seller_blocked_cannot_open_telegram_integration(): void
    {
        $seller = $this->makeSeller();
        SellerIntegrationVisibility::setTenantMode(
            SellerIntegrationVisibility::TELEGRAM,
            (int) $seller->tenant_id,
            SellerIntegrationVisibility::MODE_DISABLED
        );

        $this->actingAs($seller)
            ->get(route('integrations.telegram.index'))
            ->assertForbidden();
    }

    public function test_admin_bot_limit_is_enforced(): void
    {
        $seller = $this->makeSeller();
        TelegramModuleSettings::save(['bot_limit' => 1]);
        $this->assertSame(1, TelegramModuleSettings::get()['bot_limit']);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/getMe')) {
                $id = str_contains($request->url(), '/bot11:') ? 11 : 22;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'id' => $id,
                        'is_bot' => true,
                        'first_name' => 'Bot '.$id,
                        'username' => 'bot_'.$id,
                    ],
                ]);
            }
            if (str_contains($request->url(), '/setWebhook')) {
                return Http::response(['ok' => true, 'result' => true]);
            }

            return Http::response(['ok' => false, 'description' => 'unexpected'], 500);
        });

        $this->actingAs($seller)
            ->post(route('integrations.telegram.bots.store'), ['token' => '11:AAA'])
            ->assertRedirect();

        $this->actingAs($seller)
            ->from(route('integrations.telegram.index'))
            ->post(route('integrations.telegram.bots.store'), ['token' => '22:BBB'])
            ->assertSessionHasErrors('token');

        $this->assertSame(1, TelegramBot::query()->forTenant((int) $seller->tenant_id)->active()->count());
        $this->assertFalse(app(TelegramPolicy::class)->canCreateBot((int) $seller->tenant_id, 1));
    }

    public function test_community_link_permission_check_and_duplicate_guard(): void
    {
        $this->fakeGetMe();
        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHsecret'])
            ->assertRedirect();

        $bot = TelegramBot::query()->first();

        $this->actingAs($seller)
            ->post(route('integrations.telegram.communities.store'), [
                'telegram_bot_id' => $bot->id,
                'chat_id' => '-100555',
            ])
            ->assertRedirect();

        $community = TelegramCommunity::query()->first();
        $this->assertTrue($community->ready_for_delivery);
        $this->assertTrue($community->is_admin);
        $this->assertSame('supergroup', $community->type);

        $this->actingAs($seller)
            ->post(route('integrations.telegram.communities.store'), [
                'telegram_bot_id' => $bot->id,
                'chat_id' => '-100555',
            ])
            ->assertRedirect();

        $this->assertSame(1, TelegramCommunity::query()->count());
    }

    public function test_community_without_essential_permissions_is_not_ready(): void
    {
        $seller = $this->makeSeller();

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), '/getMe')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'id' => 991122,
                        'is_bot' => true,
                        'first_name' => 'Stacker Bot',
                        'username' => 'stacker_test_bot',
                    ],
                ]);
            }
            if (str_contains($request->url(), '/setWebhook')) {
                return Http::response(['ok' => true, 'result' => true]);
            }
            if (str_contains($request->url(), '/getChat')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['id' => -100777, 'type' => 'channel', 'title' => 'Canal'],
                ]);
            }
            if (str_contains($request->url(), '/getChatMember')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'status' => 'administrator',
                        'user' => ['id' => 991122, 'is_bot' => true],
                        'can_invite_users' => false,
                        'can_post_messages' => false,
                        'can_restrict_members' => false,
                    ],
                ]);
            }

            return Http::response(['ok' => false, 'description' => 'unexpected'], 500);
        });

        $this->actingAs($seller)
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHsecret'])
            ->assertRedirect();
        $bot = TelegramBot::query()->first();

        $this->actingAs($seller)
            ->post(route('integrations.telegram.communities.store'), [
                'telegram_bot_id' => $bot->id,
                'chat_id' => '-100777',
            ])
            ->assertRedirect();

        $community = TelegramCommunity::query()->first();
        $this->assertFalse($community->ready_for_delivery);
        $this->assertContains('red', collect($community->permissions)->pluck('status')->all());
    }

    public function test_tenant_isolation_on_bot_actions(): void
    {
        $this->fakeGetMe();
        $owner = $this->makeSeller();
        $other = $this->makeSeller();

        $this->actingAs($owner)
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHsecret'])
            ->assertRedirect();
        $bot = TelegramBot::query()->first();

        $this->actingAs($other)
            ->post(route('integrations.telegram.bots.test', $bot))
            ->assertNotFound();
    }

    public function test_temporary_api_failure_surfaces_retryable_message(): void
    {
        Http::fake([
            'api.telegram.org/*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            },
        ]);

        $seller = $this->makeSeller();
        $this->actingAs($seller)
            ->from(route('integrations.telegram.index'))
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHsecret'])
            ->assertSessionHasErrors('token');
    }

    public function test_webhook_rejects_invalid_secret_and_deduplicates_updates(): void
    {
        Queue::fake();
        $this->fakeGetMe();
        $seller = $this->makeSeller();
        $this->actingAs($seller)
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHsecret'])
            ->assertRedirect();

        $bot = TelegramBot::query()->first();

        $this->postJson(route('webhooks.telegram', ['secret' => $bot->webhook_path_secret]), [
            'update_id' => 50,
            'message' => ['text' => 'hi'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ])->assertOk()->assertJson(['ignored' => true]);

        $this->postJson(route('webhooks.telegram', ['secret' => $bot->webhook_path_secret]), [
            'update_id' => 50,
            'message' => ['text' => 'hi'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $bot->webhook_secret_token,
        ])->assertOk()->assertJson(['processed' => true]);

        $this->postJson(route('webhooks.telegram', ['secret' => $bot->webhook_path_secret]), [
            'update_id' => 50,
            'message' => ['text' => 'hi again'],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => $bot->webhook_secret_token,
        ])->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, TelegramWebhookEvent::query()->count());
    }

    public function test_telegram_webhook_is_csrf_exempt(): void
    {
        Queue::fake();
        $this->fakeGetMe();
        $seller = $this->makeSeller();
        $this->actingAs($seller)
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHsecret'])
            ->assertRedirect();

        $bot = TelegramBot::query()->first();

        // POST web (com sessão) sem _token — deve aceitar (antes caía em 419 e o bot ficava mudo).
        $this->actingAs($seller)
            ->post(route('webhooks.telegram', ['secret' => $bot->webhook_path_secret]), [
                'update_id' => 77,
                'message' => [
                    'text' => '/start',
                    'from' => ['id' => 4242, 'username' => 'buyer'],
                ],
            ], [
                'X-Telegram-Bot-Api-Secret-Token' => $bot->webhook_secret_token,
            ])
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_safe_disconnect_and_token_update(): void
    {
        $this->fakeGetMe();
        $seller = $this->makeSeller();
        $this->actingAs($seller)
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHsecret'])
            ->assertRedirect();
        $bot = TelegramBot::query()->first();

        $this->actingAs($seller)
            ->post(route('integrations.telegram.communities.store'), [
                'telegram_bot_id' => $bot->id,
                'chat_id' => '-100555',
            ])
            ->assertRedirect();

        $this->actingAs($seller)
            ->from(route('integrations.telegram.index'))
            ->delete(route('integrations.telegram.bots.destroy', $bot))
            ->assertSessionHasErrors('bot');

        $community = TelegramCommunity::query()->first();
        $this->actingAs($seller)
            ->delete(route('integrations.telegram.communities.destroy', $community))
            ->assertRedirect();

        Http::fake([
            'api.telegram.org/bot*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/bot*/getMe' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 991122,
                    'is_bot' => true,
                    'first_name' => 'Stacker Bot',
                    'username' => 'stacker_test_bot',
                ],
            ]),
            'api.telegram.org/bot*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->actingAs($seller)
            ->delete(route('integrations.telegram.bots.destroy', $bot))
            ->assertRedirect();

        $bot->refresh();
        $this->assertSame(TelegramBot::STATUS_DISCONNECTED, $bot->status);
        $this->assertNotNull($bot->disconnected_at);

        $this->actingAs($seller)
            ->put(route('integrations.telegram.bots.token', $bot), [
                'token' => '991122:AAHnewToken',
            ])
            ->assertRedirect();

        $bot->refresh();
        $this->assertSame(TelegramBot::STATUS_CONNECTED, $bot->status);
        $this->assertSame('991122:AAHnewToken', $bot->plainToken());
    }

    public function test_wizard_progress_resumes_from_real_data_and_permission_revocation(): void
    {
        $this->fakeGetMe();
        $seller = $this->makeSeller();
        $this->actingAs($seller)
            ->post(route('integrations.telegram.bots.store'), ['token' => '991122:AAHsecret'])
            ->assertRedirect();
        $bot = TelegramBot::query()->first();
        $this->actingAs($seller)
            ->post(route('integrations.telegram.communities.store'), [
                'telegram_bot_id' => $bot->id,
                'chat_id' => '-100555',
            ])
            ->assertRedirect();

        $this->actingAs($seller)
            ->get(route('integrations.telegram.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('wizard.ready_for_sales_prep', true)
                ->where('wizard.steps.3.done', true)
            );

        $community = TelegramCommunity::query()->first();
        $this->assertTrue($community->ready_for_delivery);

        $community->forceFill([
            'is_admin' => false,
            'ready_for_delivery' => false,
            'permissions' => app(\App\Services\Telegram\TelegramPermissionChecker::class)
                ->evaluate($community->type, ['status' => 'member', 'user' => ['id' => 991122]])['permissions'],
            'status' => TelegramCommunity::STATUS_ERROR,
            'last_error' => 'Permissões essenciais ausentes. Ajuste as permissões do bot e verifique novamente.',
            'last_checked_at' => now(),
        ])->save();

        $this->actingAs($seller)
            ->get(route('integrations.telegram.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('wizard.ready_for_sales_prep', false)
                ->where('wizard.steps.2.done', false)
                ->where('communities.0.ready_for_delivery', false)
            );
    }

    public function test_verify_endpoint_marks_community_not_ready_when_bot_loses_admin(): void
    {
        $seller = $this->makeSeller();
        $bot = TelegramBot::query()->create([
            'tenant_id' => $seller->tenant_id,
            'telegram_bot_id' => 424242,
            'username' => 'verify_bot',
            'first_name' => 'Verify',
            'token_encrypted' => '424242:AAHverify',
            'webhook_path_secret' => TelegramBot::makePathSecret(),
            'webhook_secret_token' => TelegramBot::makeWebhookSecretToken(),
            'status' => TelegramBot::STATUS_CONNECTED,
            'connected_at' => now(),
        ]);
        $community = TelegramCommunity::query()->create([
            'tenant_id' => $seller->tenant_id,
            'telegram_bot_id' => $bot->id,
            'telegram_chat_id' => -100999,
            'title' => 'Grupo',
            'type' => TelegramCommunity::TYPE_SUPERGROUP,
            'status' => TelegramCommunity::STATUS_LINKED,
            'is_admin' => true,
            'ready_for_delivery' => true,
            'permissions' => [],
            'last_checked_at' => now(),
        ]);

        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => [
                'status' => 'member',
                'user' => ['id' => 424242, 'is_bot' => true],
            ],
        ]));

        $this->actingAs($seller)
            ->post(route('integrations.telegram.communities.verify', $community))
            ->assertRedirect();

        $community->refresh();
        $this->assertFalse($community->ready_for_delivery);
        $this->assertFalse($community->is_admin);
    }

    public function test_integrations_card_lists_telegram_when_visible(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller)
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('visible_integrations', fn ($ids) => collect($ids)->contains('telegram'))
                ->where('telegram.configured', false)
            );
    }

    public function test_multiple_bots_and_communities_for_same_tenant(): void
    {
        $seller = $this->makeSeller();

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'getMe')) {
                $id = str_contains($request->url(), '111:') ? 111 : 222;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'id' => $id,
                        'is_bot' => true,
                        'first_name' => 'Bot '.$id,
                        'username' => 'bot_'.$id,
                    ],
                ]);
            }
            if (str_contains($request->url(), 'setWebhook')) {
                return Http::response(['ok' => true, 'result' => true]);
            }
            if (str_contains($request->url(), 'getChat')) {
                $chatId = str_contains((string) json_encode($request->data()), '-1001') ? -1001 : -1002;

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'id' => $chatId,
                        'type' => 'supergroup',
                        'title' => 'Grupo '.$chatId,
                    ],
                ]);
            }
            if (str_contains($request->url(), 'getChatMember')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'status' => 'administrator',
                        'can_invite_users' => true,
                        'can_restrict_members' => true,
                        'can_manage_join_requests' => true,
                    ],
                ]);
            }

            return Http::response(['ok' => false, 'description' => 'unexpected'], 500);
        });

        $this->actingAs($seller)->post(route('integrations.telegram.bots.store'), ['token' => '111:AAA'])->assertRedirect();
        $this->actingAs($seller)->post(route('integrations.telegram.bots.store'), ['token' => '222:BBB'])->assertRedirect();

        $bots = TelegramBot::query()->forTenant((int) $seller->tenant_id)->orderBy('id')->get();
        $this->assertCount(2, $bots);

        $this->actingAs($seller)->post(route('integrations.telegram.communities.store'), [
            'telegram_bot_id' => $bots[0]->id,
            'chat_id' => '-1001',
        ])->assertRedirect();
        $this->actingAs($seller)->post(route('integrations.telegram.communities.store'), [
            'telegram_bot_id' => $bots[1]->id,
            'chat_id' => '-1002',
        ])->assertRedirect();

        $this->assertSame(2, TelegramCommunity::query()->forTenant((int) $seller->tenant_id)->active()->count());
    }
}
