<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionReminderService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class SellerSubscriptionsManageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            EnsureStackerLicense::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_seller_filters_subscriptions_by_subscriber_email(): void
    {
        [$seller, $product, $plan] = $this->sellerWithPlan();
        $this->subscribe($seller, $product, $plan, 'ana.assinante@example.com');
        $this->subscribe($seller, $product, $plan, 'outro.assinante@example.com');

        $this->actingAs($seller)
            ->get('/vendas/assinaturas?email=ana.assinante')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Assinaturas/Index')
                ->where('email_filter', 'ana.assinante')
                ->has('assinaturas.data', 1)
                ->where('assinaturas.data.0.user.email', 'ana.assinante@example.com')
            );
    }

    public function test_seller_changes_plan_without_creating_a_charge(): void
    {
        [$seller, $product, $plan] = $this->sellerWithPlan();
        $annual = SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Anual',
            'price' => 399,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_ANNUAL,
        ]);
        $subscription = $this->subscribe($seller, $product, $plan, 'troca@example.com');
        $ordersBefore = Order::query()->count();

        $this->actingAs($seller)
            ->post('/vendas/assinaturas/'.$subscription->id.'/plano', [
                'subscription_plan_id' => $annual->id,
            ])
            ->assertRedirect('/vendas/assinaturas/'.$subscription->id);

        $this->assertSame($annual->id, (int) $subscription->fresh()->subscription_plan_id);
        $this->assertSame($ordersBefore, Order::query()->count());
    }

    public function test_seller_cannot_move_subscription_to_another_product_plan(): void
    {
        [$seller, $product, $plan] = $this->sellerWithPlan();
        $otherProduct = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'billing_type' => 'subscription',
            'name' => 'Outro produto',
        ]);
        $foreign = SubscriptionPlan::query()->create([
            'product_id' => $otherProduct->id,
            'name' => 'Mensal alheio',
            'price' => 10,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
        ]);
        $subscription = $this->subscribe($seller, $product, $plan, 'fora@example.com');

        $this->actingAs($seller)
            ->from('/vendas/assinaturas/'.$subscription->id)
            ->post('/vendas/assinaturas/'.$subscription->id.'/plano', [
                'subscription_plan_id' => $foreign->id,
            ])
            ->assertRedirect('/vendas/assinaturas/'.$subscription->id)
            ->assertSessionHas('error');

        $this->assertSame($plan->id, (int) $subscription->fresh()->subscription_plan_id);
    }

    public function test_seller_resends_the_renewal_email(): void
    {
        [$seller, $product, $plan] = $this->sellerWithPlan();
        $subscription = $this->subscribe($seller, $product, $plan, 'reenvio@example.com');

        $this->mock(SubscriptionReminderService::class, function ($mock) use ($subscription) {
            $mock->shouldReceive('sendManualReminder')
                ->once()
                ->withArgs(fn (Subscription $sent) => (int) $sent->id === (int) $subscription->id);
        });

        $this->actingAs($seller)
            ->post('/vendas/assinaturas/'.$subscription->id.'/reenviar-cobranca')
            ->assertRedirect('/vendas/assinaturas/'.$subscription->id)
            ->assertSessionHas('success');
    }

    /**
     * @return array{0: User, 1: \App\Models\Product, 2: SubscriptionPlan}
     */
    private function sellerWithPlan(): array
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();
        $seller = $seller->fresh();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'billing_type' => 'subscription',
            'name' => 'Produto assinatura',
        ]);
        $plan = SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 49.9,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
        ]);

        return [$seller, $product, $plan];
    }

    private function subscribe(User $seller, $product, SubscriptionPlan $plan, string $email): Subscription
    {
        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'email' => $email,
            'tenant_id' => null,
        ]);

        return Subscription::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->toDateString(),
            'current_period_end' => now()->addMonth()->toDateString(),
        ]);
    }
}
