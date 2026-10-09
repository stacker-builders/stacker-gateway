<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Http\Middleware\EnsureInstalled;
use App\Jobs\EvolutionSendMessageJob;
use App\Jobs\UazapiSendMessageJob;
use App\Models\CheckoutSession;
use App\Models\EvolutionInstance;
use App\Models\EvolutionMessageDispatch;
use App\Models\Order;
use App\Models\UazapiInstance;
use App\Models\UazapiMessageDispatch;
use App\Models\User;
use App\Services\SellerIntegrationVisibility;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EvolutionRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-21 20:00:00');
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::EVOLUTION, true);
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
        $this->seedSeller();
        $this->connectedInstance([
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Primeira {nome}! {link}'],
            ],
        ]);

        $product = $this->createTestProduct(['checkout_slug' => 'evo-recovery-1']);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'evo-recovery-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead Evo',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->artisan('evolution:process-cart-recovery')->assertSuccessful();

        Queue::assertPushed(EvolutionSendMessageJob::class);
        $dispatch = EvolutionMessageDispatch::query()->first();
        $this->assertSame(0, $dispatch->sequence_step);
        $this->assertStringContainsString('Primeira Lead Evo', $dispatch->message);
        $this->assertSame(EvolutionInstance::EVENT_CART_RECOVERY, $dispatch->event_type);
    }

    public function test_command_skips_cart_recovery_when_phone_already_bought_product(): void
    {
        Queue::fake();
        $this->seedSeller();
        $this->connectedInstance([
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Primeira {nome}! {link}'],
            ],
        ]);

        $product = $this->createTestProduct(['checkout_slug' => 'evo-owned-1']);
        Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97,
            'email' => 'buyer@example.com',
            'phone' => '11988776655',
        ]);

        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'evo-owned-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead Evo',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->artisan('evolution:process-cart-recovery')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(0, EvolutionMessageDispatch::query()->count());
    }

    public function test_command_skips_when_instance_disconnected(): void
    {
        Queue::fake();
        $this->seedSeller();
        $this->connectedInstance([
            'status' => EvolutionInstance::STATUS_DISCONNECTED,
            'cart_recovery_enabled' => true,
        ]);

        $this->artisan('evolution:process-cart-recovery')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_pix_generated_queues_whatsapp_when_enabled(): void
    {
        Queue::fake();
        $this->seedSeller();
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

        Queue::assertPushed(EvolutionSendMessageJob::class);
        $dispatch = EvolutionMessageDispatch::query()->first();
        $this->assertSame(EvolutionInstance::EVENT_PIX_GENERATED, $dispatch->event_type);
        $this->assertSame($order->id, $dispatch->order_id);
        $this->assertSame('00020126580014br.gov.bcb.pix', $dispatch->payload['pix_copy'] ?? null);
    }

    public function test_order_completed_cancels_pending_recovery(): void
    {
        Queue::fake();
        $this->seedSeller();
        $instance = $this->connectedInstance(['cart_recovery_enabled' => true]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
        ]);

        EvolutionMessageDispatch::query()->create([
            'tenant_id' => 1,
            'evolution_instance_id' => $instance->id,
            'order_id' => $order->id,
            'event_type' => EvolutionInstance::EVENT_PIX_GENERATED,
            'phone' => '5511977665544',
            'message' => 'pague',
            'status' => EvolutionMessageDispatch::STATUS_PENDING,
        ]);

        event(new OrderCompleted($order));

        $this->assertSame(
            EvolutionMessageDispatch::STATUS_CANCELED,
            EvolutionMessageDispatch::query()->first()->status
        );
    }

    public function test_pix_paid_queues_purchase_message_when_enabled(): void
    {
        Queue::fake();
        $this->seedSeller();
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

        Queue::assertPushed(EvolutionSendMessageJob::class, 1);
        $dispatch = EvolutionMessageDispatch::query()
            ->where('event_type', EvolutionInstance::EVENT_ORDER_PAID)
            ->first();
        $this->assertNotNull($dispatch);
        $this->assertSame($order->id, $dispatch->order_id);
        $this->assertSame(EvolutionMessageDispatch::STATUS_PENDING, $dispatch->status);
        $this->assertStringContainsString('PIX de R$ 49,90 confirmado', $dispatch->message);
        $this->assertStringContainsString('Curso Pix', $dispatch->message);
    }

    public function test_order_paid_skips_non_pix_payments(): void
    {
        Queue::fake();
        $this->seedSeller();
        $this->connectedInstance(['order_paid_enabled' => true]);

        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
            'payment_method' => 'card',
        ]);

        event(new OrderCompleted($order));

        Queue::assertNotPushed(EvolutionSendMessageJob::class);
        $this->assertSame(0, EvolutionMessageDispatch::query()->count());
    }

    public function test_send_job_sends_order_paid_when_order_is_completed(): void
    {
        Http::fake([
            'https://stacker.evo.com/chat/whatsappNumbers/*' => Http::response([['exists' => true]]),
            'https://stacker.evo.com/message/sendText/*' => Http::response(['key' => ['id' => 'evo-paid-1']]),
            'https://stacker.evo.com/*' => Http::response(['ok' => true]),
        ]);

        $this->seedSeller();
        $instance = $this->connectedInstance(['order_paid_enabled' => true]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
            'payment_method' => 'pix',
        ]);

        $dispatch = EvolutionMessageDispatch::query()->create([
            'tenant_id' => 1,
            'evolution_instance_id' => $instance->id,
            'order_id' => $order->id,
            'event_type' => EvolutionInstance::EVENT_ORDER_PAID,
            'sequence_step' => 0,
            'phone' => '5511977665544',
            'message' => 'Compra confirmada',
            'payload' => [],
            'status' => EvolutionMessageDispatch::STATUS_PENDING,
        ]);

        (new EvolutionSendMessageJob($dispatch->id))->handle(app(\App\Services\Evolution\EvolutionClient::class));

        $dispatch->refresh();
        $this->assertSame(EvolutionMessageDispatch::STATUS_SENT, $dispatch->status);
        $this->assertNull($dispatch->error);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/'));
    }

    public function test_send_job_cancels_order_paid_when_order_refunded(): void
    {
        Http::fake();
        $this->seedSeller();
        $instance = $this->connectedInstance(['order_paid_enabled' => true]);
        $product = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'status' => 'refunded',
            'amount' => 49.90,
            'email' => 'buyer@example.com',
            'phone' => '11977665544',
            'payment_method' => 'pix',
        ]);

        $dispatch = EvolutionMessageDispatch::query()->create([
            'tenant_id' => 1,
            'evolution_instance_id' => $instance->id,
            'order_id' => $order->id,
            'event_type' => EvolutionInstance::EVENT_ORDER_PAID,
            'sequence_step' => 0,
            'phone' => '5511977665544',
            'message' => 'Compra confirmada',
            'status' => EvolutionMessageDispatch::STATUS_PENDING,
        ]);

        (new EvolutionSendMessageJob($dispatch->id))->handle(app(\App\Services\Evolution\EvolutionClient::class));

        $dispatch->refresh();
        $this->assertSame(EvolutionMessageDispatch::STATUS_CANCELED, $dispatch->status);
        $this->assertStringContainsString('reembolsado ou cancelado', (string) $dispatch->error);
        Http::assertNothingSent();
    }

    public function test_seller_cannot_open_evolution_when_hidden(): void
    {
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::EVOLUTION, false);
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->getJson(route('integrations.evolution.show'))
            ->assertForbidden();
    }

    public function test_seller_can_save_own_server_name_and_token(): void
    {
        Http::fake([
            'https://meu.evo.com/*' => Http::response([
                'instance' => ['instanceName' => 'loja-1', 'state' => 'close'],
            ]),
        ]);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->putJson(route('integrations.evolution.update'), [
                'server_url' => 'https://meu.evo.com',
                'instance_name' => 'loja-1',
                'instance_token' => 'seller-instance-apikey',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('instance.server_url', 'https://meu.evo.com')
            ->assertJsonPath('instance.instance_name', 'loja-1')
            ->assertJsonMissingPath('instance.instance_token');

        $instance = EvolutionInstance::forTenant((int) $seller->id);
        $this->assertSame('https://meu.evo.com', $instance->server_url);
        $this->assertSame('loja-1', $instance->instance_name);
        $this->assertSame('seller-instance-apikey', $instance->instance_token);
        $this->assertStringNotContainsString('apikey', json_encode($instance->toPublicArray()));
    }

    public function test_connect_uses_seller_credentials_and_returns_qr(): void
    {
        Http::fake([
            'https://meu.evo.com/instance/connect/*' => Http::response([
                'pairingCode' => null,
                'code' => '2@exemple',
                'base64' => 'data:image/png;base64,abc',
            ]),
            'https://meu.evo.com/*' => Http::response(['success' => true]),
        ]);

        $this->seedSeller();
        $instance = EvolutionInstance::firstOrNewForTenant(1);
        $instance->server_url = 'https://meu.evo.com';
        $instance->instance_name = 'loja-1';
        $instance->instance_token = 'seller-instance-apikey';
        $instance->status = EvolutionInstance::STATUS_DISCONNECTED;
        $instance->save();

        $seller = User::query()->where('tenant_id', 1)->where('role', User::ROLE_INFOPRODUTOR)->first();
        $seller->forceFill([
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $this->actingAs($seller)
            ->postJson(route('integrations.evolution.connect'))
            ->assertOk()
            ->assertJsonPath('instance.qrcode', 'data:image/png;base64,abc');
    }

    public function test_cart_recovery_skips_products_not_assigned_to_account(): void
    {
        Queue::fake();
        $this->seedSeller();
        $assigned = $this->createTestProduct(['checkout_slug' => 'evo-assigned-cart', 'name' => 'Produto A']);
        $ignored = $this->createTestProduct(['checkout_slug' => 'evo-ignored-cart', 'name' => 'Produto B']);
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
                'session_token' => 'evo-prod-'.uniqid(),
                'step' => CheckoutSession::STEP_FORM_FILLED,
                'email' => 'lead@example.com',
                'name' => 'Lead Evo',
                'phone' => '11988776655',
                'form_started_at' => now()->subMinutes(20),
                'form_filled_at' => now()->subMinutes(15),
            ]);
        }

        $this->artisan('evolution:process-cart-recovery')->assertSuccessful();

        $this->assertSame(1, EvolutionMessageDispatch::query()->count());
        $this->assertStringContainsString('Produto A', EvolutionMessageDispatch::query()->first()->message);
    }

    public function test_does_not_duplicate_when_uazapi_already_queued_same_cart(): void
    {
        Queue::fake();
        $this->seedSeller();
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::UAZAPI, true);

        $product = $this->createTestProduct(['checkout_slug' => 'evo-overlap']);
        $session = CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'evo-overlap-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead Evo',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->connectedInstance([
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Evo {nome}'],
            ],
        ]);

        $uazapi = UazapiInstance::firstOrNewForTenant(1);
        $uazapi->fill([
            'server_url' => 'https://stacker.uazapi.com',
            'instance_token' => 'inst-token',
            'status' => UazapiInstance::STATUS_CONNECTED,
            'is_active' => true,
            'is_default' => true,
            'cart_recovery_enabled' => true,
            'connected_at' => now(),
        ]);
        $uazapi->save();

        UazapiMessageDispatch::query()->create([
            'tenant_id' => 1,
            'uazapi_instance_id' => $uazapi->id,
            'checkout_session_id' => $session->id,
            'event_type' => UazapiInstance::EVENT_CART_RECOVERY,
            'sequence_step' => 0,
            'phone' => '5511988776655',
            'message' => 'Uazapi',
            'status' => UazapiMessageDispatch::STATUS_PENDING,
        ]);

        $this->artisan('evolution:process-cart-recovery')->assertSuccessful();

        Queue::assertNotPushed(EvolutionSendMessageJob::class);
        $this->assertSame(0, EvolutionMessageDispatch::query()->count());
        Queue::assertNotPushed(UazapiSendMessageJob::class);
    }

    public function test_inbound_opt_out_blocks_future_cart_recovery(): void
    {
        Http::fake();
        $this->seedSeller();
        $instance = $this->connectedInstance([
            'cart_recovery_enabled' => true,
            'cart_recovery_steps' => [
                ['delay_minutes' => 10, 'message' => 'Oi {nome}'],
            ],
        ]);

        $this->postJson('/webhooks/evolution/'.$instance->webhook_secret, [
            'event' => 'MESSAGES_UPSERT',
            'apikey' => 'should-not-be-stored',
            'data' => [
                'key' => ['remoteJid' => '5511988776655@s.whatsapp.net', 'fromMe' => false],
                'message' => ['conversation' => 'parar'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('evolution_opt_outs', [
            'tenant_id' => 1,
            'phone' => '5511988776655',
        ]);

        Queue::fake();

        $product = $this->createTestProduct(['checkout_slug' => 'evo-opt-out']);
        CheckoutSession::create([
            'tenant_id' => 1,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'evo-opt-out-'.uniqid(),
            'step' => CheckoutSession::STEP_FORM_FILLED,
            'email' => 'lead@example.com',
            'name' => 'Lead Evo',
            'phone' => '11988776655',
            'form_started_at' => now()->subMinutes(20),
            'form_filled_at' => now()->subMinutes(15),
        ]);

        $this->artisan('evolution:process-cart-recovery')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_platform_whatsapp_page_lists_uazapi_and_evolution(): void
    {
        $this->seedSeller();
        $this->connectedInstance(['cart_recovery_enabled' => true]);

        $uazapi = UazapiInstance::firstOrNewForTenant(1);
        $uazapi->fill([
            'name' => 'Conta Uazapi',
            'server_url' => 'https://stacker.uazapi.com',
            'instance_token' => 'inst-token',
            'status' => UazapiInstance::STATUS_CONNECTED,
            'is_active' => true,
            'is_default' => true,
            'cart_recovery_enabled' => true,
        ]);
        $uazapi->save();

        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.uazapi.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Uazapi/Index')
                ->has('uazapi.instances', 1)
                ->has('evolution.instances', 1)
                ->has('platform.channel')
                ->where('uazapi.instances.0.name', 'Conta Uazapi')
                ->where('evolution.instances.0.instance_name', 'loja-1')
            );
    }

    public function test_infoprodutor_can_open_whatsapp_recovery_report_with_evolution_only(): void
    {
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::UAZAPI, false);
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::EVOLUTION, true);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $instance = EvolutionInstance::firstOrNewForTenant((int) $seller->id);
        $instance->fill([
            'name' => 'Conta principal',
            'server_url' => 'https://stacker.evo.com',
            'instance_name' => 'loja-1',
            'instance_token' => 'inst-token',
            'status' => EvolutionInstance::STATUS_CONNECTED,
            'is_active' => true,
            'is_default' => true,
            'cart_recovery_enabled' => true,
            'connected_at' => now(),
        ]);
        $instance->save();

        EvolutionMessageDispatch::query()->create([
            'tenant_id' => $seller->id,
            'evolution_instance_id' => $instance->id,
            'event_type' => EvolutionInstance::EVENT_CART_RECOVERY,
            'sequence_step' => 0,
            'phone' => '5511999999999',
            'message' => 'Oi',
            'status' => EvolutionMessageDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($seller)
            ->get(route('relatorios.whatsapp'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Relatorios/Whatsapp')
                ->where('campaigns_available', false)
                ->where('metrics.sent', 1)
                ->where('metrics.cart_sent', 1)
                ->has('recent', 1)
            );
    }

    public function test_report_can_resend_failed_evolution_cart_recovery(): void
    {
        Queue::fake();
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::UAZAPI, false);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $instance = EvolutionInstance::firstOrNewForTenant((int) $seller->id);
        $instance->fill([
            'name' => 'Conta principal',
            'server_url' => 'https://stacker.evo.com',
            'instance_name' => 'loja-1',
            'instance_token' => 'inst-token',
            'status' => EvolutionInstance::STATUS_CONNECTED,
            'is_active' => true,
            'is_default' => true,
            'cart_recovery_enabled' => true,
            'connected_at' => now(),
        ]);
        $instance->save();

        $dispatch = EvolutionMessageDispatch::query()->create([
            'tenant_id' => $seller->id,
            'evolution_instance_id' => $instance->id,
            'event_type' => EvolutionInstance::EVENT_CART_RECOVERY,
            'sequence_step' => 0,
            'phone' => '5511988776655',
            'message' => 'Oi {nome}! Finalize: {link}',
            'status' => EvolutionMessageDispatch::STATUS_FAILED,
            'error' => 'Evolution HTTP 500: temporary',
        ]);

        $this->actingAs($seller)
            ->get(route('relatorios.whatsapp'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Relatorios/Whatsapp')
                ->where('recent.0.can_resend', true)
                ->where('recent.0.provider', 'evolution')
            );

        $this->actingAs($seller)
            ->postJson(route('relatorios.whatsapp.resend', [
                'provider' => 'evolution',
                'dispatch' => $dispatch->id,
            ]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $fresh = $dispatch->fresh();
        $this->assertSame(EvolutionMessageDispatch::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->error);
        Queue::assertPushed(EvolutionSendMessageJob::class, fn ($job) => $job->dispatchId === $dispatch->id);
    }

    public function test_report_rejects_resend_for_sent_dispatch(): void
    {
        Queue::fake();
        SellerIntegrationVisibility::setGlobal(SellerIntegrationVisibility::UAZAPI, false);

        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $seller->forceFill([
            'tenant_id' => $seller->id,
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
        ])->save();

        $instance = EvolutionInstance::firstOrNewForTenant((int) $seller->id);
        $instance->fill([
            'name' => 'Conta principal',
            'server_url' => 'https://stacker.evo.com',
            'instance_name' => 'loja-1',
            'instance_token' => 'inst-token',
            'status' => EvolutionInstance::STATUS_CONNECTED,
            'is_active' => true,
            'is_default' => true,
            'connected_at' => now(),
        ]);
        $instance->save();

        $dispatch = EvolutionMessageDispatch::query()->create([
            'tenant_id' => $seller->id,
            'evolution_instance_id' => $instance->id,
            'event_type' => EvolutionInstance::EVENT_CART_RECOVERY,
            'sequence_step' => 0,
            'phone' => '5511988776655',
            'message' => 'Oi',
            'status' => EvolutionMessageDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $this->actingAs($seller)
            ->postJson(route('relatorios.whatsapp.resend', [
                'provider' => 'evolution',
                'dispatch' => $dispatch->id,
            ]))
            ->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame(EvolutionMessageDispatch::STATUS_SENT, $dispatch->fresh()->status);
    }

    private function seedSeller(): void
    {
        User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function connectedInstance(array $overrides = []): EvolutionInstance
    {
        $instance = EvolutionInstance::firstOrNewForTenant(1);
        $instance->fill(array_merge([
            'name' => $instance->name ?: 'Conta principal',
            'server_url' => 'https://stacker.evo.com',
            'instance_name' => 'loja-1',
            'instance_token' => 'inst-token',
            'status' => EvolutionInstance::STATUS_CONNECTED,
            'is_active' => true,
            'is_default' => true,
            'cart_recovery_enabled' => false,
            'pix_recovery_enabled' => false,
            'connected_at' => now(),
        ], $overrides));
        $instance->save();

        return $instance;
    }
}
