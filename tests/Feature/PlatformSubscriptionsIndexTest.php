<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformSubscriptionsIndexTest extends TestCase
{
    private function platformAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function seller(string $email): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => $email,
            'account_status' => 'approved',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    private function subscription(User $seller, string $status): Subscription
    {
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
        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        return Subscription::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => $status,
            'current_period_start' => now()->toDateString(),
            'current_period_end' => now()->addMonth()->toDateString(),
        ]);
    }

    public function test_platform_admin_lists_subscriptions_and_filters_by_seller_email_and_status(): void
    {
        if (! Schema::hasTable('subscriptions') || ! Schema::hasTable('subscription_plans')) {
            $this->markTestSkipped('Tabelas de assinatura indisponíveis.');
        }

        $admin = $this->platformAdmin();
        $sellerA = $this->seller('ativo.assinatura@example.com');
        $sellerB = $this->seller('encerrado.assinatura@example.com');
        $active = $this->subscription($sellerA, Subscription::STATUS_ACTIVE);
        $cancelled = $this->subscription($sellerB, Subscription::STATUS_CANCELLED);

        $this->actingAs($admin)
            ->get(route('plataforma.assinaturas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Subscriptions/Index')
                ->where('filters.status', 'all')
                ->has('subscriptions.data', 2)
            );

        $this->actingAs($admin)
            ->get(route('plataforma.assinaturas.index', [
                'seller' => 'ativo.assinatura@example.com',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $active->id)
                ->where('subscriptions.data.0.infoprodutor_email', 'ativo.assinatura@example.com')
            );

        $this->actingAs($admin)
            ->get(route('plataforma.assinaturas.index', ['status' => 'cancelled']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('subscriptions.data', 1)
                ->where('subscriptions.data.0.id', $cancelled->id)
                ->where('subscriptions.data.0.status_label', 'Encerrada')
            );
    }

    public function test_transaction_listing_marks_subscription_and_one_time_orders(): void
    {
        if (! Schema::hasTable('orders')) {
            $this->markTestSkipped('Tabela de pedidos indisponível.');
        }

        $admin = $this->platformAdmin();
        $seller = $this->seller('tipo.pedido@example.com');
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);
        $plan = SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 20,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
        ]);
        $customer = User::factory()->create([
            'role' => User::ROLE_CLIENTE,
            'tenant_id' => null,
        ]);

        $subscriptionOrder = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'completed',
            'amount' => 20,
            'gateway' => 'demo',
            'payment_method' => 'pix',
            'email' => $customer->email,
            'approved_manually' => false,
        ]);
        $oneTimeOrder = Order::query()->create([
            'tenant_id' => $seller->id,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 15,
            'gateway' => 'demo',
            'payment_method' => 'pix',
            'email' => $customer->email,
            'approved_manually' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('plataforma.transacoes.index', ['q' => $customer->email]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Transactions/Index')
                ->where('orders.data', function ($rows) use ($subscriptionOrder, $oneTimeOrder) {
                    $byId = collect($rows)->keyBy('id');

                    return ($byId[$subscriptionOrder->id]['sale_type_label'] ?? null) === 'Assinatura'
                        && ($byId[$oneTimeOrder->id]['sale_type_label'] ?? null) === 'Único';
                })
            );
    }
}
