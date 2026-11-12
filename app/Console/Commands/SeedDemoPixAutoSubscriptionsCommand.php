<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\LinaOpenx\LinaOpenxPixAutoService;
use Carbon\Carbon;
use Database\Seeders\LocalDevAccessSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SeedDemoPixAutoSubscriptionsCommand extends Command
{
    protected $signature = 'demo:assinaturas-pix-auto';

    protected $description = 'Cria compras fictícias de assinatura com Pix automático para o infoprodutor local';

    private const FIXTURE = 'local-pix-auto-demo';

    private const PRODUCT_SLUG = 'clube-demo-pix-automatico';

    public function handle(): int
    {
        $seller = User::query()->where('email', LocalDevAccessSeeder::EMAIL)->first();
        if (! $seller || ! $seller->isInfoprodutor()) {
            $this->error('Infoprodutor local não encontrado: '.LocalDevAccessSeeder::EMAIL);

            return self::FAILURE;
        }

        $tenantId = (int) ($seller->tenant_id ?: $seller->id);
        $product = $this->product($tenantId);
        $plan = $this->plan($product);
        $this->clearPrevious($product);

        $today = now()->startOfDay();
        $price = (float) $plan->price;

        $this->scenario($tenantId, $product, $plan, [
            'name' => 'Ana Costa',
            'email' => 'ana.demo.assinatura@exemplo.local',
            'status' => Subscription::STATUS_ACTIVE,
            'purchased_at' => $today->copy()->subMonths(3),
            'charges' => [
                $this->charge($today->copy()->subMonths(3), false, 'completed'),
                $this->charge($today->copy()->subMonths(2), true, 'completed'),
                $this->charge($today->copy()->subMonth(), true, 'completed'),
                $this->charge($today->copy(), true, 'completed'),
            ],
            'period_start' => $today->copy(),
            'period_end' => $today->copy()->addMonth(),
        ], $price);

        $nextBruno = $today->copy()->addDays(10);
        $this->scenario($tenantId, $product, $plan, [
            'name' => 'Bruno Lima',
            'email' => 'bruno.demo.assinatura@exemplo.local',
            'status' => Subscription::STATUS_ACTIVE,
            'purchased_at' => $today->copy()->subDays(20),
            'charges' => [
                $this->charge($today->copy()->subDays(20), false, 'completed'),
                $this->charge($today->copy(), true, 'pending', $nextBruno, $nextBruno->copy()->addMonth()),
            ],
            'period_start' => $today->copy()->subDays(20),
            'period_end' => $nextBruno,
        ], $price);

        $overdue = $today->copy()->subDays(8);
        $this->scenario($tenantId, $product, $plan, [
            'name' => 'Carla Souza',
            'email' => 'carla.demo.assinatura@exemplo.local',
            'status' => Subscription::STATUS_PAST_DUE,
            'purchased_at' => $today->copy()->subDays(70),
            'charges' => [
                $this->charge($today->copy()->subDays(70), false, 'completed'),
                $this->charge($today->copy()->subDays(40), true, 'completed'),
                $this->charge($overdue, true, 'pending', $overdue, $overdue->copy()->addMonth()),
            ],
            'period_start' => $today->copy()->subDays(40),
            'period_end' => $overdue,
        ], $price);

        $this->scenario($tenantId, $product, $plan, [
            'name' => 'Diego Martins',
            'email' => 'diego.demo.assinatura@exemplo.local',
            'status' => Subscription::STATUS_CANCELLED,
            'purchased_at' => $today->copy()->subDays(100),
            'charges' => [
                $this->charge($today->copy()->subDays(100), false, 'completed'),
                $this->charge($today->copy()->subDays(70), true, 'completed'),
            ],
            'period_start' => $today->copy()->subDays(70),
            'period_end' => $today->copy()->subDays(40),
        ], $price);

        foreach (['hoje', 'ontem', '7dias', 'mes', 'ano', 'total'] as $period) {
            Cache::forget('dashboard:v8:'.$tenantId.':'.$seller->id.':'.$period.'::');
        }

        $this->info('Assinaturas fictícias de Pix automático criadas para '.$seller->email.'.');
        $this->line('Produto: '.$product->name.' · plano '.$plan->name.' · R$ '.number_format($price, 2, ',', '.'));
        $this->line('Ana Costa: 4 cobranças pagas, a última hoje, próxima em 1 mês.');
        $this->line('Bruno Lima: compra há 20 dias e cobrança automática pendente, próxima em 10 dias.');
        $this->line('Carla Souza: em atraso, cobrança automática pendente há 8 dias.');
        $this->line('Diego Martins: encerrada, sem próxima cobrança.');

        return self::SUCCESS;
    }

    private function product(int $tenantId): Product
    {
        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', self::PRODUCT_SLUG)
            ->first();

        if ($product) {
            return $product;
        }

        $config = Product::defaultCheckoutConfig();
        $config['payment_methods_enabled']['pix_auto'] = true;
        $config['payment_methods_enabled']['pix'] = false;

        return Product::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Clube Demo Pix Automático',
            'slug' => self::PRODUCT_SLUG,
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'price' => 97,
            'currency' => 'BRL',
            'is_active' => true,
            'description' => 'Produto fictício para visualizar assinaturas com Pix automático.',
            'checkout_config' => $config,
        ]);
    }

    private function plan(Product $product): SubscriptionPlan
    {
        $plan = SubscriptionPlan::query()
            ->where('product_id', $product->id)
            ->where('name', 'Mensal Pix automático')
            ->first();

        if ($plan) {
            return $plan;
        }

        return SubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'name' => 'Mensal Pix automático',
            'price' => 97,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'position' => 1,
        ]);
    }

    private function clearPrevious(Product $product): void
    {
        $emails = [
            'ana.demo.assinatura@exemplo.local',
            'bruno.demo.assinatura@exemplo.local',
            'carla.demo.assinatura@exemplo.local',
            'diego.demo.assinatura@exemplo.local',
        ];
        $userIds = User::query()->whereIn('email', $emails)->pluck('id');
        if ($userIds->isEmpty()) {
            return;
        }

        Order::query()
            ->where('product_id', $product->id)
            ->whereIn('user_id', $userIds)
            ->delete();

        Subscription::query()
            ->where('product_id', $product->id)
            ->whereIn('user_id', $userIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    private function scenario(int $tenantId, Product $product, SubscriptionPlan $plan, array $scenario, float $price): void
    {
        $customer = User::query()->updateOrCreate(
            ['email' => $scenario['email']],
            [
                'name' => $scenario['name'],
                'password' => Str::random(32),
                'role' => User::ROLE_CLIENTE,
                'tenant_id' => null,
                'email_verified_at' => now(),
            ]
        );

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => $scenario['status'],
            'current_period_start' => $scenario['period_start']->toDateString(),
            'current_period_end' => $scenario['period_end']->toDateString(),
        ]);
        $subscription->forceFill([
            'created_at' => $scenario['purchased_at'],
            'updated_at' => $scenario['purchased_at'],
        ])->saveQuietly();

        foreach ($scenario['charges'] as $charge) {
            /** @var Carbon $at */
            $at = $charge['at'];
            $periodStart = $charge['period_start'];
            $periodEnd = $charge['period_end'];

            $order = Order::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $customer->id,
                'product_id' => $product->id,
                'subscription_plan_id' => $plan->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'is_renewal' => $charge['renewal'],
                'amount' => $price,
                'email' => $customer->email,
                'status' => $charge['status'],
                'gateway' => LinaOpenxPixAutoService::GATEWAY_SLUG,
                'gateway_id' => 'demo-'.Str::lower(Str::random(12)),
                'payment_method' => 'pix_auto',
                'approved_manually' => false,
                'metadata' => [
                    'fixture' => self::FIXTURE,
                    'checkout_payment_method' => 'pix_auto',
                    'subscription_id' => $subscription->id,
                    'subscription_auto_renewal' => (bool) $charge['renewal'],
                ],
            ]);
            $order->forceFill([
                'created_at' => $at,
                'updated_at' => $at,
            ])->saveQuietly();
        }
    }

    /**
     * @return array{at: Carbon, renewal: bool, status: string, period_start: Carbon, period_end: Carbon}
     */
    private function charge(Carbon $at, bool $renewal, string $status, ?Carbon $periodStart = null, ?Carbon $periodEnd = null): array
    {
        return [
            'at' => $at,
            'renewal' => $renewal,
            'status' => $status,
            'period_start' => $periodStart ?? $at->copy(),
            'period_end' => $periodEnd ?? $at->copy()->addMonth(),
        ];
    }
}
