<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Exceptions\UazapiRequestException;
use App\Http\Middleware\EnsureInstalled;
use App\Jobs\UazapiSendMessageJob;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\PlatformUazapiSetting;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UazapiRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 18:00:00');
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::UAZAPI, true);
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_queues_first_cart_recovery_step_for_connected_instance(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $this->connectedInstance([
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Primeira {nome}! {link}'],
                ['delay_minutes' => 1440, 'message' => 'Segunda {nome}!'],
            ],
        ]);

        $product = $this->createTestProduct(['checkout_slug' => 'uazapi-recovery-1']);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'uazapi-recovery-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead WA',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->artisan('uazapi:process-cart-recovery')->assertSuccessful();

        Queue::assertPushed(UazapiSendMessageJob::class);
        $dispatch = UazapiMessageDispatch::query()->first();
        $this->assertSame(0, $dispatch->sequence_step);
        $this->assertStringContainsString('Primeira Lead WA', $dispatch->message);
        $this->assertSame(UazapiInstance::EVENT_CART_RECOVERY, $dispatch->event_type);
    }

    public function test_command_skips_when_instance_disconnected(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $this->connectedInstance([
            'status' => UazapiInstance::STATUS_DISCONNECTED,
            'cart_recovery_enabled' => true,
        ]);

        $this->artisan('uazapi:process-cart-recovery')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_pix_generated_queues_whatsapp_when_enabled(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $this->connectedInstance(['pix_recovery_enabled' => true]);

        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
            'metadata' => ['copy_paste' => '00020126580014br.gov.bcb.pix'],
        ]);

        event(new PixGenerated($order, ['copy_paste' => '00020126580014br.gov.bcb.pix']));

        Queue::assertPushed(UazapiSendMessageJob::class);
        $dispatch = UazapiMessageDispatch::query()->first();
        $this->assertSame(UazapiInstance::EVENT_PIX_GENERATED, $dispatch->event_type);
        $this->assertSame($order->id, $dispatch->order_id);
        $this->assertSame(0, $dispatch->sequence_step);
        $this->assertSame('00020126580014br.gov.bcb.pix', $dispatch->payload['pix_copy'] ?? null);
    }

    public function test_command_queues_pix_reminder_after_immediate_step(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $instance = $this->connectedInstance([
            'pix_recovery_enabled' => true,
            'pix_recovery_steps' => [
                ['delay_minutes' => 30, 'message' => 'Lembrete PIX {valor}'],
            ],
        ]);

        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
            'metadata' => ['copy_paste' => '00020126580014br.gov.bcb.pix'],
        ]);

        UazapiMessageDispatch::query()->create([
            'tenant_id' => 1,
            'uazapi_instance_id' => $instance->id,
            'order_id' => $order->id,
            'event_type' => UazapiInstance::EVENT_PIX_GENERATED,
            'sequence_step' => 0,
            'phone' => '5511977665544',
            'message' => 'PIX imediato',
            'status' => UazapiMessageDispatch::STATUS_SENT,
            'sent_at' => now()->subMinutes(40),
        ]);

        $this->artisan('uazapi:process-cart-recovery')->assertSuccessful();

        Queue::assertPushed(UazapiSendMessageJob::class);
        $reminder = UazapiMessageDispatch::query()->where('sequence_step', 1)->first();
        $this->assertNotNull($reminder);
        $this->assertStringContainsString('Lembrete PIX', $reminder->message);
    }

    public function test_inbound_reply_cancels_pending_sequence(): void
    {
        Http::fake();
        $this->seedPlatform();
        $instance = $this->connectedInstance(['cart_recovery_enabled' => true]);

        $dispatch = UazapiMessageDispatch::query()->create([
            'tenant_id' => 1,
            'uazapi_instance_id' => $instance->id,
            'event_type' => UazapiInstance::EVENT_CART_RECOVERY,
            'sequence_step' => 1,
            'phone' => '5511988776655',
            'message' => 'Segunda mensagem',
            'status' => UazapiMessageDispatch::STATUS_PENDING,
        ]);

        $this->postJson('/webhooks/uazapi/'.$instance->webhook_secret, [
            'EventType' => 'messages',
            'message' => [
                'fromMe' => false,
                'wasSentByApi' => false,
                'chatid' => '5511988776655@s.whatsapp.net',
                'sender' => '5511988776655@s.whatsapp.net',
                'text' => 'Oi, ainda quero',
            ],
        ])->assertOk();

        $this->assertSame(UazapiMessageDispatch::STATUS_CANCELED, $dispatch->fresh()->status);
        $this->assertDatabaseHas('uazapi_recovery_stops', [
            'tenant_id' => 1,
            'phone' => '5511988776655',
            'reason' => 'replied',
        ]);
        $this->assertDatabaseMissing('uazapi_opt_outs', [
            'phone' => '5511988776655',
        ]);
    }

    public function test_opt_out_keyword_blocks_future_cart_recovery(): void
    {
        Http::fake();
        $this->seedPlatform();
        $instance = $this->connectedInstance([
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Volte {nome} {link}'],
            ],
        ]);

        $this->postJson('/webhooks/uazapi/'.$instance->webhook_secret, [
            'EventType' => 'messages',
            'message' => [
                'fromMe' => false,
                'sender' => '5511988776655@s.whatsapp.net',
                'text' => 'Parar',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('uazapi_opt_outs', [
            'tenant_id' => 1,
            'phone' => '5511988776655',
        ]);

        Queue::fake();

        $product = $this->createTestProduct(['checkout_slug' => 'uazapi-opt-out']);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'uazapi-opt-out-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead WA',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->artisan('uazapi:process-cart-recovery')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_paid_keyword_cancels_without_opt_out(): void
    {
        Http::fake();
        $this->seedPlatform();
        $instance = $this->connectedInstance(['pix_recovery_enabled' => true]);

        $dispatch = UazapiMessageDispatch::query()->create([
            'tenant_id' => 1,
            'uazapi_instance_id' => $instance->id,
            'event_type' => UazapiInstance::EVENT_PIX_GENERATED,
            'sequence_step' => 0,
            'phone' => '5511977665544',
            'message' => 'Pague o PIX',
            'status' => UazapiMessageDispatch::STATUS_PENDING,
        ]);

        $this->postJson('/webhooks/uazapi/'.$instance->webhook_secret, [
            'EventType' => 'messages',
            'message' => [
                'fromMe' => false,
                'sender' => '5511977665544@s.whatsapp.net',
                'text' => 'Já paguei',
            ],
        ])->assertOk();

        $this->assertSame(UazapiMessageDispatch::STATUS_CANCELED, $dispatch->fresh()->status);
        $this->assertDatabaseHas('uazapi_recovery_stops', [
            'phone' => '5511977665544',
            'reason' => 'paid',
        ]);
        $this->assertDatabaseMissing('uazapi_opt_outs', [
            'phone' => '5511977665544',
        ]);
    }

    public function test_send_job_retries_when_whatsapp_new_conversation_limit_is_reached(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, 'wa_messages_limits')) {
                return Http::response(['can_send_new_messages' => false]);
            }
            if (str_contains($url, '/chat/check')) {
                return Http::response([['isInWhatsapp' => true]]);
            }

            return Http::response(['ok' => true]);
        });

        $this->seedPlatform();
        $instance = $this->connectedInstance(['pix_recovery_enabled' => true]);
        $dispatch = UazapiMessageDispatch::query()->create([
            'tenant_id' => 1,
            'uazapi_instance_id' => $instance->id,
            'event_type' => UazapiInstance::EVENT_PIX_GENERATED,
            'sequence_step' => 0,
            'phone' => '5511977665544',
            'message' => 'Pague o PIX',
            'status' => UazapiMessageDispatch::STATUS_PENDING,
        ]);

        try {
            (new UazapiSendMessageJob($dispatch->id))->handle(app(\App\Services\Uazapi\UazapiClient::class));
            $this->fail('Esperava exceção de limite do WhatsApp.');
        } catch (UazapiRequestException $e) {
            $this->assertTrue($e->retryable);
        }

        $this->assertSame(UazapiMessageDispatch::STATUS_PENDING, $dispatch->fresh()->status);
        $this->assertStringContainsString('Limite', (string) $dispatch->fresh()->error);
    }

    public function test_infoprodutor_can_open_whatsapp_recovery_report(): void
    {
        $this->seedPlatform();
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->get(route('relatorios.whatsapp'))
            ->assertOk();
    }

    public function test_order_completed_cancels_pending_recovery(): void
    {
        Queue::fake();
        Http::fake();
        $this->seedPlatform();
        $instance = $this->connectedInstance(['pix_recovery_enabled' => true]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
        ]);

        $dispatch = UazapiMessageDispatch::query()->create([
            'tenant_id' => 1,
            'uazapi_instance_id' => $instance->id,
            'order_id' => $order->id,
            'event_type' => UazapiInstance::EVENT_PIX_GENERATED,
            'phone' => '5511977665544',
            'message' => 'Pague o PIX',
            'status' => UazapiMessageDispatch::STATUS_PENDING,
        ]);

        event(new OrderCompleted($order));

        $this->assertSame(UazapiMessageDispatch::STATUS_CANCELED, $dispatch->fresh()->status);
    }

    public function test_pix_paid_queues_purchase_message_when_enabled(): void
    {
        Queue::fake();
        Http::fake();
        $this->seedPlatform();
        $this->connectedInstance([
            'order_paid_enabled' => true,
            'message_order_paid' => '{nome}, PIX de {valor} confirmado para {produto}',
        ]);

        $product = $this->createTestProduct(['tenant_id' => 1, 'name' => 'Curso Pix']);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
            'payment_method' => 'pix',
        ]);

        event(new OrderCompleted($order));
        event(new OrderCompleted($order));

        Queue::assertPushed(UazapiSendMessageJob::class, 1);
        $dispatch = UazapiMessageDispatch::query()
            ->where('event_type', UazapiInstance::EVENT_ORDER_PAID)
            ->first();
        $this->assertNotNull($dispatch);
        $this->assertSame($order->id, $dispatch->order_id);
        $this->assertSame(UazapiMessageDispatch::STATUS_PENDING, $dispatch->status);
        $this->assertStringContainsString('PIX de R$ 49,90 confirmado', $dispatch->message);
        $this->assertStringContainsString('Curso Pix', $dispatch->message);
    }

    public function test_order_paid_skips_non_pix_payments(): void
    {
        Queue::fake();
        Http::fake();
        $this->seedPlatform();
        $this->connectedInstance(['order_paid_enabled' => true]);

        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
            'payment_method' => 'card',
        ]);

        event(new OrderCompleted($order));

        Queue::assertNotPushed(UazapiSendMessageJob::class);
        $this->assertSame(0, UazapiMessageDispatch::query()->count());
    }

    public function test_send_job_skips_when_cart_already_converted(): void
    {
        Http::fake();
        $this->seedPlatform();
        $instance = $this->connectedInstance(['cart_recovery_enabled' => true]);
        $product = $this->createTestProduct(['checkout_slug' => 'uazapi-converted']);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'phone' => '11988776655',
        ]);
        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'uazapi-converted-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead',
            'phone' => '11988776655',
            'order_id' => $order->id,
        ]);

        $dispatch = UazapiMessageDispatch::query()->create([
            'tenant_id' => 1,
            'uazapi_instance_id' => $instance->id,
            'checkout_session_id' => $session->id,
            'event_type' => UazapiInstance::EVENT_CART_RECOVERY,
            'sequence_step' => 0,
            'phone' => '5511988776655',
            'message' => 'Volte ao checkout',
            'status' => UazapiMessageDispatch::STATUS_PENDING,
        ]);

        (new UazapiSendMessageJob($dispatch->id))->handle(app(\App\Services\Uazapi\UazapiClient::class));

        $this->assertSame(UazapiMessageDispatch::STATUS_CANCELED, $dispatch->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_webhook_connection_marks_instance_connected(): void
    {
        $this->seedPlatform();
        $instance = $this->connectedInstance([
            'status' => UazapiInstance::STATUS_CONNECTING,
            'qrcode' => 'data:image/png;base64,xxx',
        ]);

        $this->postJson('/webhooks/uazapi/'.$instance->webhook_secret, [
            'EventType' => 'connection',
            'instance' => [
                'status' => 'connected',
                'owner' => '5511988776655:lid',
                'profileName' => 'Loja Teste',
            ],
            'connected' => true,
        ])->assertOk();

        $instance->refresh();
        $this->assertSame(UazapiInstance::STATUS_CONNECTED, $instance->status);
        $this->assertSame('5511988776655', $instance->phone);
        $this->assertNull($instance->qrcode);
    }

    public function test_seller_cannot_open_uazapi_when_hidden(): void
    {
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::UAZAPI, false);
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->getJson(route('integrations.uazapi.show'))
            ->assertForbidden();
    }

    public function test_seller_can_save_own_server_and_token(): void
    {
        Http::fake([
            'https://meu.uazapi.com/*' => Http::response([
                'connected' => false,
                'instance' => ['status' => 'disconnected'],
            ]),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->putJson(route('integrations.uazapi.update'), [
                'server_url' => 'https://meu.uazapi.com',
                'instance_token' => 'seller-instance-token',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('credentials_configured', true)
            ->assertJsonPath('signup_url', 'https://uazapi.dev/')
            ->assertJsonPath('instance.has_credentials', true)
            ->assertJsonPath('instance.has_token', true)
            ->assertJsonPath('accounts.0.has_credentials', true)
            ->assertJsonMissingPath('instance.instance_token');

        $instance = UazapiInstance::forTenant((int) $seller->id);
        $this->assertNotNull($instance);
        $this->assertSame('https://meu.uazapi.com', $instance->server_url);
        $this->assertSame('seller-instance-token', $instance->instance_token);
        $this->assertTrue($instance->is_active);
    }

    public function test_seller_token_strips_quotes_and_bearer_prefix(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($request->hasHeader('token', 'seller-instance-token') && str_contains($request->url(), '/instance/status')) {
                return Http::response(['instance' => ['status' => 'disconnected']]);
            }
            if (str_contains($request->url(), '/webhook')) {
                return Http::response(['ok' => true]);
            }

            return Http::response(['error' => 'unexpected '.$request->url()], 500);
        });

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->putJson(route('integrations.uazapi.update'), [
                'server_url' => 'https://meu.uazapi.com/',
                'instance_token' => 'Bearer "seller-instance-token"',
                'is_active' => true,
            ])
            ->assertOk();

        $this->assertSame('seller-instance-token', UazapiInstance::forTenant((int) $seller->id)?->instance_token);
        $this->assertTrue((bool) UazapiInstance::forTenant((int) $seller->id)?->is_default);
    }

    public function test_seller_can_add_second_account_with_own_server_and_token(): void
    {
        Http::fake([
            'https://principal.uazapi.com/*' => Http::response(['instance' => ['status' => 'disconnected']]),
            'https://backup.uazapi.com/*' => Http::response(['instance' => ['status' => 'disconnected']]),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->putJson(route('integrations.uazapi.update'), [
                'name' => 'Conta principal',
                'server_url' => 'https://principal.uazapi.com',
                'instance_token' => 'token-principal',
                'is_active' => true,
            ])
            ->assertOk();

        $created = $this->actingAs($seller)
            ->postJson(route('integrations.uazapi.store'))
            ->assertOk();
        $secondId = $created->json('instance.id');
        $this->assertNotNull($secondId);

        $this->actingAs($seller)
            ->putJson(route('integrations.uazapi.instance.update', $secondId), [
                'name' => 'Contingência',
                'server_url' => 'https://backup.uazapi.com',
                'instance_token' => 'token-backup',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('instance.server_url', 'https://backup.uazapi.com')
            ->assertJsonPath('instance.has_token', true)
            ->assertJsonMissingPath('instance.instance_token');

        $this->assertSame(2, UazapiInstance::query()->where('tenant_id', $seller->id)->count());
        $backup = UazapiInstance::query()->find($secondId);
        $this->assertSame('https://backup.uazapi.com', $backup?->server_url);
        $this->assertSame('token-backup', $backup?->instance_token);
        $this->assertFalse((bool) $backup?->is_default);
    }

    public function test_cart_recovery_does_not_duplicate_across_two_connected_accounts(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $this->connectedInstance([
            'name' => 'Conta principal',
            'is_default' => true,
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Primeira {nome}! {link}'],
            ],
        ]);
        $this->connectedInstance([
            'name' => 'Contingência',
            'is_default' => false,
            'server_url' => 'https://backup.uazapi.com',
            'instance_token' => 'backup-token',
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Backup {nome}! {link}'],
            ],
        ], createNew: true);

        $product = $this->createTestProduct(['checkout_slug' => 'uazapi-multi']);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'uazapi-multi-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead WA',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->artisan('uazapi:process-cart-recovery')->assertSuccessful();

        $this->assertSame(1, UazapiMessageDispatch::query()->count());
        $this->assertSame(
            UazapiInstance::forTenant(1)?->id,
            UazapiMessageDispatch::query()->first()?->uazapi_instance_id
        );
    }

    public function test_recovery_fails_over_to_second_account_when_default_is_down(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $this->connectedInstance([
            'name' => 'Conta principal',
            'is_default' => true,
            'status' => UazapiInstance::STATUS_DISCONNECTED,
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Principal {nome}'],
            ],
        ]);
        $backup = $this->connectedInstance([
            'name' => 'Contingência',
            'is_default' => false,
            'server_url' => 'https://backup.uazapi.com',
            'instance_token' => 'backup-token',
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Backup {nome}'],
            ],
        ], createNew: true);

        $product = $this->createTestProduct(['checkout_slug' => 'uazapi-failover']);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'uazapi-failover-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead WA',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->artisan('uazapi:process-cart-recovery')->assertSuccessful();

        $dispatch = UazapiMessageDispatch::query()->first();
        $this->assertNotNull($dispatch);
        $this->assertSame($backup->id, $dispatch->uazapi_instance_id);
        $this->assertStringContainsString('Backup Lead WA', $dispatch->message);
    }

    public function test_seller_can_limit_recovery_to_selected_products(): void
    {
        Http::fake([
            'https://meu.uazapi.com/*' => Http::response(['instance' => ['status' => 'disconnected']]),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();
        $product = $this->createTestProduct(['tenant_id' => $seller->id, 'checkout_slug' => 'uazapi-assigned']);

        $this->actingAs($seller)
            ->putJson(route('integrations.uazapi.update'), [
                'server_url' => 'https://meu.uazapi.com',
                'instance_token' => 'seller-instance-token',
                'is_active' => true,
                'product_ids' => [$product->id],
            ])
            ->assertOk()
            ->assertJsonPath('instance.product_ids.0', (string) $product->id);

        $instance = UazapiInstance::forTenant((int) $seller->id);
        $this->assertTrue($instance->appliesToProduct($product->id));
        $this->assertFalse($instance->appliesToProduct('prod-outro'));
    }

    public function test_cart_recovery_skips_products_not_assigned_to_account(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $assigned = $this->createTestProduct(['checkout_slug' => 'uazapi-assigned-cart', 'name' => 'Produto A']);
        $ignored = $this->createTestProduct(['checkout_slug' => 'uazapi-ignored-cart', 'name' => 'Produto B']);
        $instance = $this->connectedInstance([
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Oi {produto}'],
            ],
        ]);
        $instance->products()->sync([$assigned->id]);

        foreach ([$assigned, $ignored] as $product) {
            CheckoutSession::create([
                'tenant_id' => 1,
                'product_id' => $product->id,
                'checkout_slug' => $product->checkout_slug,
                'session_token' => 'uazapi-prod-'.uniqid(),
                'step' => CheckoutSession::STEP_FORM_FILLED,
                'email' => 'lead@example.com',
                'name' => 'Lead WA',
                'phone' => '11988776655',
                'form_started_at' => now()->subMinutes(20),
                'form_filled_at' => now()->subMinutes(15),
            ]);
        }

        $this->artisan('uazapi:process-cart-recovery')->assertSuccessful();

        $this->assertSame(1, UazapiMessageDispatch::query()->count());
        $this->assertSame($assigned->id, CheckoutSession::query()->find(UazapiMessageDispatch::query()->first()->checkout_session_id)?->product_id);
        $this->assertStringContainsString('Produto A', UazapiMessageDispatch::query()->first()->message);
    }

    public function test_seller_admin_token_gets_explicit_error(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if ($request->hasHeader('token')) {
                return Http::response(['error' => 'Invalid token'], 401);
            }
            if ($request->hasHeader('admintoken', 'admin-secret')) {
                return Http::response([['id' => 'i1']]);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->putJson(route('integrations.uazapi.update'), [
                'server_url' => 'https://meu.uazapi.com',
                'instance_token' => 'admin-secret',
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este valor é o admintoken do servidor. No painel uazapi abra a instância e copie o token dela — não o token de administrador.');
    }

    public function test_connect_uses_seller_credentials_and_returns_qr(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, '/instance/create')) {
                return Http::response(['error' => 'admin create não deve ser usado'], 500);
            }
            if (str_contains($url, '/webhook')) {
                return Http::response(['ok' => true]);
            }
            if (str_contains($url, '/instance/connect')) {
                return Http::response([
                    'connected' => false,
                    'instance' => [
                        'status' => 'connecting',
                        'qrcode' => 'data:image/png;base64,qrcode',
                    ],
                ]);
            }

            return Http::response(['error' => 'unexpected '.$url], 500);
        });

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $instance = UazapiInstance::firstOrNewForTenant((int) $seller->id);
        $instance->server_url = 'https://meu.uazapi.com';
        $instance->instance_token = 'seller-instance-token';
        $instance->is_active = true;
        $instance->save();

        $this->actingAs($seller)
            ->postJson(route('integrations.uazapi.connect'))
            ->assertOk()
            ->assertJsonPath('instance.status', 'connecting')
            ->assertJsonPath('instance.qrcode', 'data:image/png;base64,qrcode');

        $this->assertDatabaseHas('uazapi_instances', [
            'tenant_id' => $seller->id,
            'status' => 'connecting',
        ]);
    }

    public function test_campaign_queues_abandoned_cart_and_skips_opt_out(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $instance = $this->connectedInstance(['send_product_image' => false]);

        $product = $this->createTestProduct(['checkout_slug' => 'uazapi-campaign']);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'uazapi-campaign-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead WA',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'uazapi-campaign-opt-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'opt@example.com',
            'name' => 'Opt Out',
            'phone' => '11977665544',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        \App\Models\UazapiOptOut::query()->create([
            'tenant_id' => 1,
            'uazapi_instance_id' => $instance->id,
            'phone' => '5511977665544',
            'source' => 'inbound',
        ]);

        $campaign = app(\App\Services\Uazapi\UazapiCampaignService::class)->launch(
            $instance,
            \App\Models\UazapiCampaign::AUDIENCE_ABANDONED_CART,
            'Oi {nome}! {link}',
            false
        );

        $this->assertSame(1, $campaign->queued_count);
        Queue::assertPushed(UazapiSendMessageJob::class, 1);
        $dispatch = UazapiMessageDispatch::query()->where('event_type', UazapiInstance::EVENT_CAMPAIGN)->first();
        $this->assertSame('5511988776655', $dispatch->phone);
        $this->assertStringContainsString('Lead WA', $dispatch->message);
        $this->assertSame($campaign->id, $dispatch->campaign_id);
    }

    public function test_cart_dispatch_attaches_product_image_url(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $this->connectedInstance([
            'cart_recovery_enabled' => true,
            'send_product_image' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Com imagem {nome}'],
            ],
        ]);

        $product = $this->createTestProduct([
            'checkout_slug' => 'uazapi-image',
            'image' => 'https://cdn.example.com/produto.jpg',
        ]);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'uazapi-image-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead WA',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->artisan('uazapi:process-cart-recovery')->assertSuccessful();

        $dispatch = UazapiMessageDispatch::query()->first();
        $this->assertNotNull($dispatch);
        $this->assertSame('https://cdn.example.com/produto.jpg', $dispatch->payload['image_url'] ?? null);
        $this->assertSame('abandoned', $dispatch->payload['label'] ?? null);
    }

    public function test_seller_can_launch_campaign_via_http(): void
    {
        Queue::fake();
        $this->seedPlatform();
        $this->connectedInstance();

        $product = $this->createTestProduct(['checkout_slug' => 'uazapi-http-campaign']);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'uazapi-http-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead WA',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $seller = User::query()->where('tenant_id', 1)->where('role', User::ROLE_INFOPRODUTOR)->first();
        $seller->forceFill([
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->postJson(route('integrations.uazapi.campaigns.store'), [
                'audience' => 'abandoned_cart',
                'message' => 'Campanha {nome}',
                'include_image' => false,
            ])
            ->assertOk()
            ->assertJsonPath('campaign.queued_count', 1);
    }

    private function seedPlatform(): void
    {
        PlatformUazapiSetting::instance()->update([
            'is_active' => true,
            'server_url' => 'https://stacker.uazapi.com',
            'admin_token' => 'admin-token',
        ]);
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function connectedInstance(array $overrides = [], bool $createNew = false): UazapiInstance
    {
        $instance = $createNew
            ? UazapiInstance::newForTenant(1)
            : UazapiInstance::firstOrNewForTenant(1);
        $instance->fill(array_merge([
            'name' => $instance->name ?: 'Conta principal',
            'server_url' => 'https://stacker.uazapi.com',
            'instance_token' => 'inst-token',
            'instance_id' => 'i-local',
            'status' => UazapiInstance::STATUS_CONNECTED,
            'is_active' => true,
            'is_default' => ! $createNew,
            'cart_recovery_enabled' => false,
            'pix_recovery_enabled' => false,
            'connected_at' => now(),
        ], $overrides));
        $instance->save();

        return $instance;
    }
}
