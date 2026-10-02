<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SellerDashboardCustomPeriodTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_custom_period_filters_seller_dashboard_by_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
        $seller = $this->infoprodutor();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $inRangeA = $this->createOrder($seller, $buyer, $product, ['amount' => 100]);
        $inRangeB = $this->createOrder($seller, $buyer, $product, ['amount' => 50]);
        $outside = $this->createOrder($seller, $buyer, $product, ['amount' => 200]);

        Order::query()->whereKey($inRangeA->id)->update([
            'created_at' => Carbon::parse('2026-08-10 08:00:00'),
            'updated_at' => Carbon::parse('2026-08-10 08:00:00'),
        ]);
        Order::query()->whereKey($inRangeB->id)->update([
            'created_at' => Carbon::parse('2026-08-15 18:00:00'),
            'updated_at' => Carbon::parse('2026-08-15 18:00:00'),
        ]);
        Order::query()->whereKey($outside->id)->update([
            'created_at' => Carbon::parse('2026-08-21 09:00:00'),
            'updated_at' => Carbon::parse('2026-08-21 09:00:00'),
        ]);

        $this->actingAs($seller)
            ->get('/dashboard?period=personalizado&from=2026-08-10&to=2026-08-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->where('period', 'personalizado')
                ->where('from', '2026-08-10')
                ->where('to', '2026-08-15')
                ->where('chart_granularity', 'day')
                ->where('vendas_totais', 150)
                ->where('quantidade_vendas', 2)
                ->has('grafico_vendas', 6)
                ->where('grafico_vendas', function ($points) {
                    $sum = collect($points)->sum(fn ($point) => (float) ($point['total'] ?? 0));

                    return abs($sum - 150) < 0.01;
                })
            );
    }

    public function test_custom_period_swaps_inverted_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
        $seller = $this->infoprodutor();

        $this->actingAs($seller)
            ->get('/dashboard?period=personalizado&from=2026-08-15&to=2026-08-10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', 'personalizado')
                ->where('from', '2026-08-10')
                ->where('to', '2026-08-15')
                ->where('chart_granularity', 'day')
            );
    }

    public function test_custom_single_day_uses_hourly_chart(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-21 12:00:00'));
        $seller = $this->infoprodutor();
        $buyer = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $product = $this->createTestProduct(['tenant_id' => $seller->id]);

        $order = $this->createOrder($seller, $buyer, $product, ['amount' => 80]);
        Order::query()->whereKey($order->id)->update([
            'created_at' => Carbon::parse('2026-08-10 15:00:00'),
            'updated_at' => Carbon::parse('2026-08-10 15:00:00'),
        ]);

        $this->actingAs($seller)
            ->get('/dashboard?period=personalizado&from=2026-08-10&to=2026-08-10')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('chart_granularity', 'hour')
                ->where('vendas_totais', 80)
                ->where('quantidade_vendas', 1)
                ->has('grafico_vendas', 24)
                ->where('grafico_vendas', function ($points) {
                    $sum = collect($points)->sum(fn ($point) => (float) ($point['total'] ?? 0));
                    $at15 = collect($points)->firstWhere('data', '15');

                    return abs($sum - 80) < 0.01 && abs((float) ($at15['total'] ?? 0) - 80) < 0.01;
                })
            );
    }

    private function infoprodutor(): User
    {
        $seller = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);
        $attrs = ['tenant_id' => $seller->id, 'account_status' => 'approved'];
        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = User::KYC_APPROVED;
        }
        $seller->forceFill($attrs)->save();

        return $seller->fresh();
    }

    private function createOrder(User $seller, User $buyer, $product, array $overrides): Order
    {
        return Order::create(array_merge([
            'tenant_id' => $seller->id,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $buyer->email,
            'payment_method' => 'pix',
            'gateway' => 'efi',
            'metadata' => [],
        ], $overrides));
    }
}
