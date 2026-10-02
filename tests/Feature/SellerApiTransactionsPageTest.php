<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\ApiApplication;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\ApiScopes;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SellerApiTransactionsPageTest extends TestCase
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

    public function test_api_transactions_lists_only_api_pix_orders_when_enabled(): void
    {
        Setting::set('api_pix_enabled', true, null);
        $seller = $this->infoprodutor();
        $tenantId = (int) $seller->id;
        $product = $this->createTestProduct(['tenant_id' => $tenantId]);
        $apiApp = ApiApplication::create([
            'tenant_id' => $tenantId,
            'name' => 'API PIX',
            'slug' => ApiApplication::generateUniqueSlug($tenantId, 'API PIX'),
            'api_key_hash' => hash('sha256', 'key'),
            'public_key' => ApiApplication::generatePublicKey(),
            'secret_key_hash' => hash('sha256', 'sec'),
            'payment_gateways' => ApiApplication::defaultPaymentGateways(),
            'allowed_ips' => [],
            'is_active' => true,
            'is_legacy' => true,
            'scopes' => ApiScopes::legacyDefaults(),
        ]);

        $checkoutOrder = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'c@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
        ]);

        $apiOrder = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $seller->id,
            'product_id' => $product->id,
            'api_application_id' => $apiApp->id,
            'status' => 'completed',
            'amount' => 20,
            'email' => 'a@example.com',
            'payment_method' => 'pix',
            'gateway' => 'cajupay',
            'metadata' => ['source' => 'api'],
        ]);

        $this->actingAs($seller)
            ->get(route('transacoes-api.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vendas/Index')
                ->where('listing_mode', 'api_transactions')
                ->where('list_base_path', '/transacoes-api')
                ->where('filters.sale_channel', 'api_pix')
                ->has('vendas.data', 1)
                ->where('vendas.data.0.id', $apiOrder->id)
            );

        $this->assertNotSame($checkoutOrder->id, $apiOrder->id);
    }

    public function test_api_transactions_hidden_when_api_pix_disabled(): void
    {
        Setting::set('api_pix_enabled', false, null);
        $seller = $this->infoprodutor();

        $this->actingAs($seller)
            ->get(route('transacoes-api.index'))
            ->assertNotFound();
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
}
