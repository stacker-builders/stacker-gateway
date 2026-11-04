<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\Product;
use App\Models\User;
use App\Support\MarketplaceSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PublicMarketplaceTest extends TestCase
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

    public function test_public_marketplace_is_closed_by_default(): void
    {
        $this->assertFalse(MarketplaceSettings::publicEnabled());

        $this->get('/marketplace')->assertNotFound();
    }

    public function test_public_marketplace_groups_products_by_category_when_enabled(): void
    {
        MarketplaceSettings::setPublicEnabled(true);

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'name' => 'Loja Pública',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso aberto',
            'checkout_slug' => 'mktpub1',
            'category' => 'educacao_e_cursos',
            'price' => 30,
            'show_in_marketplace' => true,
            'marketplace_approved' => true,
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);
        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso escondido',
            'checkout_slug' => 'mktpub2',
            'category' => 'saude_e_bem_estar',
            'show_in_marketplace' => true,
            'marketplace_approved' => false,
            'is_active' => true,
            'approval_status' => Product::APPROVAL_APPROVED,
        ]);

        $this->get('/marketplace')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketplace/Public')
                ->has('sections', 1)
                ->where('sections.0.key', 'educacao_e_cursos')
                ->where('sections.0.label', 'Educação e Cursos')
                ->where('sections.0.products.0.name', 'Curso aberto')
                ->where('sections.0.products.0.checkout_url', url('/c/mktpub1').'?origem=marketplace')
            );
    }

    public function test_admin_can_turn_the_public_link_off(): void
    {
        MarketplaceSettings::setPublicEnabled(true);
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($admin)
            ->post('/plataforma/marketplace/link-publico', ['public_enabled' => '0'])
            ->assertRedirect();

        $this->assertFalse(MarketplaceSettings::publicEnabled());
        $this->get('/marketplace')->assertNotFound();
    }
}
