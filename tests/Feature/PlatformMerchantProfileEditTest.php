<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

class PlatformMerchantProfileEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureInstalled::class,
            ValidateCsrfToken::class,
        ]);
    }

    public function test_admin_can_adjust_address_and_revenue_but_not_email_or_existing_cpf(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'name' => 'Seller Original',
            'email' => 'seller.original@example.com',
            'person_type' => 'pf',
            'document' => '11144477735',
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $this->actingAs($admin)
            ->put(route('plataforma.usuarios.update', $seller), [
                'name' => 'Seller Ajustado',
                'email' => 'outro.email@example.com',
                'document' => '52998224725',
                'birth_date' => '1990-05-10',
                'monthly_revenue_range' => '10k_50k',
                'trade_name' => 'Loja Ajustada',
                'address_zip' => '01310-100',
                'address_street' => 'Avenida Paulista',
                'address_number' => '1000',
                'address_neighborhood' => 'Bela Vista',
                'address_city' => 'São Paulo',
                'address_state' => 'sp',
            ])
            ->assertSessionHasErrors('email');

        $seller->refresh();
        $this->assertSame('seller.original@example.com', $seller->email);
        $this->assertSame('11144477735', $seller->document);
        $this->assertSame('Seller Original', $seller->name);

        $this->actingAs($admin)
            ->put(route('plataforma.usuarios.update', $seller), [
                'name' => 'Seller Ajustado',
                'email' => 'seller.original@example.com',
                'document' => '52998224725',
                'birth_date' => '1990-05-10',
                'monthly_revenue_range' => '10k_50k',
                'trade_name' => 'Loja Ajustada',
                'address_zip' => '01310-100',
                'address_street' => 'Avenida Paulista',
                'address_number' => '1000',
                'address_neighborhood' => 'Bela Vista',
                'address_city' => 'São Paulo',
                'address_state' => 'sp',
            ])
            ->assertRedirect();

        $seller->refresh();
        $this->assertSame('seller.original@example.com', $seller->email);
        $this->assertSame('11144477735', $seller->document);
        $this->assertSame('Seller Ajustado', $seller->name);
        $this->assertSame('1990-05-10', $seller->birth_date?->format('Y-m-d'));
        $this->assertSame('10k_50k', $seller->monthly_revenue_range);
        $this->assertSame('Loja Ajustada', $seller->trade_name);
        $this->assertSame('01310100', $seller->address_zip);
        $this->assertSame('Avenida Paulista', $seller->address_street);
        $this->assertSame('SP', $seller->address_state);
    }

    public function test_admin_can_fill_cpf_only_when_missing_and_not_when_taken(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN]);
        $taken = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'document' => '52998224725',
            'person_type' => 'pf',
        ]);
        $taken->forceFill(['tenant_id' => $taken->id])->save();

        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'email' => 'sem.cpf@example.com',
            'person_type' => 'pf',
            'document' => null,
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        $this->actingAs($admin)
            ->from(route('plataforma.usuarios.edit', $seller))
            ->put(route('plataforma.usuarios.update', $seller), [
                'name' => $seller->name,
                'email' => $seller->email,
                'document' => '529.982.247-25',
            ])
            ->assertRedirect(route('plataforma.usuarios.edit', $seller))
            ->assertSessionHasErrors('document');

        $this->assertNull($seller->fresh()->document);

        $this->actingAs($admin)
            ->put(route('plataforma.usuarios.update', $seller), [
                'name' => $seller->name,
                'email' => $seller->email,
                'document' => '390.533.447-05',
            ])
            ->assertSessionDoesntHaveErrors('document');

        $this->assertSame('39053344705', $seller->fresh()->document);
    }
}
