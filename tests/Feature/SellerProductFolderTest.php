<?php

namespace Tests\Feature;

use App\Models\ProductFolder;
use App\Models\User;
use Tests\TestCase;

class SellerProductFolderTest extends TestCase
{
    private function seller(): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'approved',
            'kyc_status' => User::KYC_APPROVED,
            'email_verified_at' => now(),
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    public function test_seller_creates_folder_and_filters_products_inside_it(): void
    {
        $seller = $this->seller();
        $kept = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso dentro',
            'category' => 'educacao_e_cursos',
        ]);
        $outside = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Curso fora',
            'category' => 'educacao_e_cursos',
        ]);

        $this->actingAs($seller)
            ->post(route('produtos.folders.store'), [
                'name' => 'Lançamento',
                'product_ids' => [(string) $kept->id],
            ])
            ->assertRedirect();

        $folder = ProductFolder::query()->where('tenant_id', $seller->id)->first();
        $this->assertNotNull($folder);
        $this->assertSame('Lançamento', $folder->name);

        $this->actingAs($seller)
            ->get(route('produtos.index', ['folder' => $folder->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('produtos.data', 1)
                ->where('produtos.data.0.name', 'Curso dentro')
                ->where('filters.folder', $folder->id)
                ->has('productFolders', 1)
                ->where('productFolders.0.name', 'Lançamento')
                ->where('productFolders.0.products_count', 1)
                ->where('folderProducts', fn ($options) => collect($options)->pluck('name')->sort()->values()->all() === ['Curso dentro', 'Curso fora'])
            );

        $this->assertNotSame((string) $outside->id, (string) $kept->id);
    }

    public function test_folder_filter_combines_with_category(): void
    {
        $seller = $this->seller();
        $health = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Saúde na pasta',
            'category' => 'saude_e_bem_estar',
        ]);
        $tech = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Tech na pasta',
            'category' => 'tecnologia_e_inovacao',
        ]);

        $this->actingAs($seller)
            ->post(route('produtos.folders.store'), [
                'name' => 'Seleção',
                'product_ids' => [(string) $health->id, (string) $tech->id],
                'return_category' => 'saude_e_bem_estar',
            ])
            ->assertRedirect(route('produtos.index', [
                'folder' => ProductFolder::query()->where('tenant_id', $seller->id)->value('id'),
                'category' => 'saude_e_bem_estar',
            ]));

        $folderId = (int) ProductFolder::query()->where('tenant_id', $seller->id)->value('id');

        $this->actingAs($seller)
            ->get(route('produtos.index', [
                'folder' => $folderId,
                'category' => 'saude_e_bem_estar',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('produtos.data', 1)
                ->where('produtos.data.0.name', 'Saúde na pasta')
                ->where('filters.category', 'saude_e_bem_estar')
                ->where('filters.folder', $folderId)
            );
    }

    public function test_seller_can_rename_folder_and_change_its_products(): void
    {
        $seller = $this->seller();
        $first = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Primeiro']);
        $second = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Segundo']);

        $this->actingAs($seller)->post(route('produtos.folders.store'), [
            'name' => 'Rascunho',
            'product_ids' => [(string) $first->id],
        ])->assertRedirect();

        $folder = ProductFolder::query()->where('tenant_id', $seller->id)->firstOrFail();

        $this->actingAs($seller)
            ->put(route('produtos.folders.update', $folder), [
                'name' => 'Publicados',
                'product_ids' => [(string) $second->id],
            ])
            ->assertRedirect(route('produtos.index', ['folder' => $folder->id]));

        $this->actingAs($seller)
            ->get(route('produtos.index', ['folder' => $folder->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('produtos.data', 1)
                ->where('produtos.data.0.name', 'Segundo')
                ->where('productFolders.0.name', 'Publicados')
            );
    }

    public function test_deleting_folder_keeps_products_and_category_filter(): void
    {
        $seller = $this->seller();
        $product = $this->createTestProduct([
            'tenant_id' => $seller->id,
            'name' => 'Permanece',
            'category' => 'religiao',
        ]);

        $this->actingAs($seller)->post(route('produtos.folders.store'), [
            'name' => 'Temporária',
            'product_ids' => [(string) $product->id],
        ])->assertRedirect();

        $folder = ProductFolder::query()->where('tenant_id', $seller->id)->firstOrFail();

        $this->actingAs($seller)
            ->delete(route('produtos.folders.destroy', ['folder' => $folder->id, 'category' => 'religiao']))
            ->assertRedirect(route('produtos.index', ['category' => 'religiao']));

        $this->assertDatabaseMissing('product_folders', ['id' => $folder->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_seller_cannot_use_another_sellers_folder_or_products(): void
    {
        $seller = $this->seller();
        $other = $this->seller();
        $own = $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Meu']);
        $foreign = $this->createTestProduct(['tenant_id' => $other->id, 'name' => 'De outro']);

        $this->actingAs($other)->post(route('produtos.folders.store'), [
            'name' => 'Privada',
            'product_ids' => [(string) $foreign->id],
        ])->assertRedirect();

        $foreignFolder = ProductFolder::query()->where('tenant_id', $other->id)->firstOrFail();

        $this->actingAs($seller)
            ->put(route('produtos.folders.update', $foreignFolder), [
                'name' => 'Invadida',
                'product_ids' => [(string) $own->id],
            ])
            ->assertNotFound();

        $this->actingAs($seller)
            ->delete(route('produtos.folders.destroy', $foreignFolder))
            ->assertNotFound();

        $this->actingAs($seller)
            ->post(route('produtos.folders.store'), [
                'name' => 'Minha',
                'product_ids' => [(string) $foreign->id],
            ])
            ->assertSessionHasErrors('product_ids');

        $this->assertDatabaseMissing('product_folders', [
            'tenant_id' => $seller->id,
            'name' => 'Minha',
        ]);

        $this->actingAs($seller)
            ->get(route('produtos.index', ['folder' => $foreignFolder->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('produtos.data', 1)
                ->where('produtos.data.0.name', 'Meu')
                ->where('filters.folder', null)
                ->has('productFolders', 0)
            );
    }

    public function test_duplicate_folder_name_is_rejected(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)->post(route('produtos.folders.store'), [
            'name' => '  Campanha   atual  ',
            'product_ids' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('product_folders', [
            'tenant_id' => $seller->id,
            'name' => 'Campanha atual',
        ]);

        $this->actingAs($seller)->post(route('produtos.folders.store'), [
            'name' => 'Campanha atual',
            'product_ids' => [],
        ])->assertSessionHasErrors('name');
    }

    public function test_unknown_folder_filter_is_ignored(): void
    {
        $seller = $this->seller();
        $this->createTestProduct(['tenant_id' => $seller->id, 'name' => 'Único']);

        $this->actingAs($seller)
            ->get(route('produtos.index', ['folder' => 9999]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('produtos.data', 1)
                ->where('filters.folder', null)
            );
    }
}
