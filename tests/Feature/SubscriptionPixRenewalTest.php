<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Events\SubscriptionRenewed;
use App\Gateways\Stripe\StripeDriver;
use App\Http\Middleware\EnsureInstalled;
use App\Jobs\ProcessPaymentWebhook;
use App\Mail\SubscriptionReminderMail;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\OrderManualApprovalService;
use App\Services\SubscriptionReminderService;
use App\Services\SubscriptionRenewalService;
use App\Services\TenantMailConfigService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionPixRenewalTest extends TestCase
{
    /**
     * @return array{0: User, 1: User, 2: Product, 3: SubscriptionPlan, 4: Subscription}
     */
    private function pastDueSubscriptionContext(): array
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $buyer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => $seller->id,
            'email' => 'assinante@test.com',
        ]);

        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Área VIP',
            'type' => Product::TYPE_AREA_MEMBROS,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'checkout_slug' => 'vip-'.uniqid(),
        ]);

        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 29.9,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => 'p-'.uniqid(),
            'position' => 1,
        ]);

        $subscription = Subscription::create([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_PAST_DUE,
            'current_period_start' => now()->subMonth()->startOfDay(),
            'current_period_end' => now()->subDay()->startOfDay(),
        ]);

        $buyer->products()->syncWithoutDetaching([(string) $product->id]);

        return [$seller, $buyer, $product, $plan, $subscription];
    }

    private function makeRenewalOrder(User $buyer, Product $product, SubscriptionPlan $plan, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'tenant_id' => $product->tenant_id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'pending',
            'amount' => 29.9,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'stripe',
            'gateway_id' => 'pi_renewal_'.uniqid(),
            'is_renewal' => true,
            'period_start' => now()->startOfDay(),
            'period_end' => now()->addMonth()->startOfDay(),
        ], $overrides));
    }

    public function test_pix_webhook_reactivates_past_due_subscription_and_grants_access(): void
    {
        Event::fake([SubscriptionRenewed::class, OrderCompleted::class]);

        [, $buyer, $product, $plan, $subscription] = $this->pastDueSubscriptionContext();
        $order = $this->makeRenewalOrder($buyer, $product, $plan);

        $this->mockStripePaid();
        $this->saveStripeCredential((int) $product->tenant_id);

        ProcessPaymentWebhook::dispatchSync('stripe', (string) $order->gateway_id, 'payment_intent.succeeded', 'paid', []);

        $subscription->refresh();
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->current_period_end->gte(now()->startOfDay()));
        $this->assertTrue($product->fresh()->hasMemberAreaAccess($buyer));
        $this->assertSame(1, Subscription::query()->where('user_id', $buyer->id)->where('product_id', $product->id)->count());
        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_pix_webhook_without_period_dates_still_reactivates(): void
    {
        Event::fake([OrderCompleted::class, SubscriptionRenewed::class]);
        [, $buyer, $product, $plan, $subscription] = $this->pastDueSubscriptionContext();
        $order = $this->makeRenewalOrder($buyer, $product, $plan, [
            'period_start' => null,
            'period_end' => null,
        ]);

        $this->mockStripePaid();
        $this->saveStripeCredential((int) $product->tenant_id);

        ProcessPaymentWebhook::dispatchSync('stripe', (string) $order->gateway_id, 'payment_intent.succeeded', 'paid', []);

        $subscription->refresh();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertTrue($product->fresh()->hasMemberAreaAccess($buyer));
    }

    public function test_checkout_style_order_without_is_renewal_flag_reactivates_existing_subscription(): void
    {
        Event::fake([OrderCompleted::class, SubscriptionRenewed::class]);
        [, $buyer, $product, $plan, $subscription] = $this->pastDueSubscriptionContext();
        $order = $this->makeRenewalOrder($buyer, $product, $plan, [
            'is_renewal' => false,
        ]);

        $this->mockStripePaid();
        $this->saveStripeCredential((int) $product->tenant_id);

        ProcessPaymentWebhook::dispatchSync('stripe', (string) $order->gateway_id, 'payment_intent.succeeded', 'paid', []);

        $this->assertTrue($order->fresh()->is_renewal);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertSame(1, Subscription::query()->where('user_id', $buyer->id)->where('product_id', $product->id)->count());
        $this->assertTrue($product->fresh()->hasMemberAreaAccess($buyer));
    }

    public function test_manual_approval_reactivates_past_due_subscription(): void
    {
        Event::fake([OrderCompleted::class, SubscriptionRenewed::class]);
        [, $buyer, $product, $plan, $subscription] = $this->pastDueSubscriptionContext();
        $order = $this->makeRenewalOrder($buyer, $product, $plan, [
            'gateway' => 'manual',
            'gateway_id' => null,
        ]);

        OrderManualApprovalService::approve($order);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertTrue($product->fresh()->hasMemberAreaAccess($buyer));
    }

    public function test_with_renewal_flag_marks_payload_when_past_due_exists(): void
    {
        [, $buyer, $product] = $this->pastDueSubscriptionContext();

        $payload = app(SubscriptionRenewalService::class)->withRenewalFlag([
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'is_renewal' => false,
        ]);

        $this->assertTrue($payload['is_renewal']);
    }

    public function test_customer_panel_collapses_subscription_renewal_into_one_card(): void
    {
        $this->withoutMiddleware([EnsureInstalled::class]);
        [, $buyer, $product, $plan] = $this->pastDueSubscriptionContext();

        $first = Order::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'completed',
            'amount' => 29.9,
            'email' => $buyer->email,
            'is_renewal' => false,
        ]);
        $renewal = Order::create([
            'tenant_id' => $product->tenant_id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'completed',
            'amount' => 29.9,
            'email' => $buyer->email,
            'is_renewal' => true,
        ]);

        $response = $this->actingAs($buyer)->get('/painel-cliente');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cliente/Index')
            ->has('purchases', 1)
            ->where('purchases.0.product_name', 'Área VIP')
            ->where('purchases.0.order_id', $renewal->id)
            ->where('purchases.0.is_renewal', true)
            ->where('purchases.0.renewal_count', 1)
        );
        $this->assertNotSame($first->id, $renewal->id);
    }

    public function test_reminder_service_sends_mail_for_overdue_subscription(): void
    {
        Mail::fake();
        $this->mock(TenantMailConfigService::class, function ($mock) {
            $mock->shouldReceive('isEmailConfigured')->andReturn(true);
            $mock->shouldReceive('applyMailerConfigForTenant')->andReturnNull();
            $mock->shouldReceive('applyPlatformGlobalMailerConfig')->andReturnNull();
            $mock->shouldReceive('assertSmtpHostIsConfigured')->andReturnNull();
        });

        config([
            'app.url' => 'http://localhost',
            'getfy.webhook_public_url' => 'https://loja.exemplo.com',
        ]);

        [, $buyer, , , $subscription] = $this->pastDueSubscriptionContext();

        $sent = app(SubscriptionReminderService::class)->sendForSubscription(
            $subscription,
            'd+1',
            now()->startOfDay()
        );

        $this->assertTrue($sent);
        $subscription->refresh();
        $expectedUrl = 'https://loja.exemplo.com/renovar/'.$subscription->renewal_token;
        Mail::assertSent(SubscriptionReminderMail::class, function (SubscriptionReminderMail $mail) use ($buyer, $expectedUrl) {
            return $mail->hasTo($buyer->email)
                && str_contains($mail->htmlBody, 'href="'.e($expectedUrl).'"')
                && str_contains($mail->htmlBody, e($expectedUrl))
                && ! str_contains($mail->htmlBody, 'localhost');
        });
    }

    private function mockStripePaid(): void
    {
        $this->mock(StripeDriver::class, function ($mock) {
            $mock->shouldReceive('getTransactionStatus')->andReturn('paid');
        });
    }

    private function saveStripeCredential(int $tenantId = 1): void
    {
        $cred = new GatewayCredential([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'stripe',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['secret_key' => 'sk_test_fake']);
        $cred->save();
    }
}
