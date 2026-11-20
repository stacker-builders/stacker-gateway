<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class SellerProductsCategoryFilterTest extends TestCase
{
    private function seller(): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    public function test_seller_lists_every_product_when_category_is_all(): void
    {
        $seller = $this->seller();

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso Tech',
            'category' => 'tecnologia_e_inovacao',
        ]);
        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso Saúde',
            'category' => 'saude_e_bem_estar',
        ]);

        $this->actingAs($seller)
            ->get(route('produtos.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Produtos/Index')
                ->has('produtos.data', 2)
                ->where('filters.category', null)
                ->has('productCategories')
            );
    }

    public function test_seller_can_filter_own_products_by_category(): void
    {
        $seller = $this->seller();
        $other = $this->seller();

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso Tech',
            'category' => 'tecnologia_e_inovacao',
        ]);
        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso Saúde',
            'category' => 'saude_e_bem_estar',
        ]);
        $this->createTestProduct([
            'tenant_id' => $other->id,
            'name' => 'Saúde de outro',
            'category' => 'saude_e_bem_estar',
        ]);

        $this->actingAs($seller)
            ->get(route('produtos.index', ['category' => 'saude_e_bem_estar']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('produtos.data', 1)
                ->where('produtos.data.0.name', 'Curso Saúde')
                ->where('filters.category', 'saude_e_bem_estar')
            );
    }

    public function test_invalid_category_is_ignored(): void
    {
        $seller = $this->seller();

        $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso Tech',
            'category' => 'tecnologia_e_inovacao',
        ]);

        $this->actingAs($seller)
            ->get(route('produtos.index', ['category' => 'nao_existe']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('produtos.data', 1)
                ->where('filters.category', null)
            );
    }
}
