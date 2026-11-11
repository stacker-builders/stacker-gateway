<?php

namespace Tests\Feature;

use App\Mail\KycRejectedMail;
use App\Models\BlockedDocument;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class KycRejectAndBlockTest extends TestCase
{
    private function seedAdmin(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-block@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function createPendingSeller(array $overrides = []): User
    {
        $seller = User::query()->create(array_merge([
            'name' => 'Laranja',
            'email' => 'laranja@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'person_type' => 'pf',
            'document' => '52998224725',
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'account_status' => 'pending',
        ], $overrides));
        $seller->update(['tenant_id' => $seller->id]);

        return $seller->fresh();
    }

    /**
     * @return array<string, string>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'person_type' => 'pf',
            'name' => 'Outra Pessoa',
            'email' => 'outra-pessoa@example.com',
            'phone' => '11987654321',
            'birth_date' => '1990-05-15',
            'document' => '52998224725',
            'company_name' => null,
            'legal_representative_cpf' => null,
            'address_zip' => '01310100',
            'address_street' => 'Av Paulista',
            'address_number' => '1000',
            'address_complement' => '',
            'address_neighborhood' => 'Bela Vista',
            'address_city' => 'São Paulo',
            'address_state' => 'SP',
            'monthly_revenue_range' => 'up_to_10k',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_terms_privacy' => '1',
        ], $overrides);
    }

    public function test_recusar_e_bloquear_impede_novo_cadastro_com_o_mesmo_cpf(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $seller = $this->createPendingSeller();

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.reject-block', $seller), [
                'reason' => 'Cadastro de laranja.',
            ])
            ->assertRedirect(route('plataforma.kyc.show', $seller))
            ->assertSessionHas('success');

        $seller->refresh();
        $this->assertSame(User::KYC_BLOCKED, $seller->kyc_status);
        $this->assertSame('blocked', $seller->account_status);
        $this->assertTrue(
            BlockedDocument::query()->where('document', '52998224725')->where('kind', 'cpf')->exists()
        );
        Mail::assertNothingSent();

        $this->post('/logout');

        $this->postJson('/cadastro/validar-documento', [
            'person_type' => 'pf',
            'document' => '52998224725',
        ])->assertOk()->assertJson([
            'available' => false,
            'field' => 'document',
            'message' => 'Este CPF já existe na plataforma e está bloqueado.',
        ]);

        $this->post('/cadastro', $this->registrationPayload())
            ->assertSessionHasErrors([
                'document' => 'Este CPF já existe na plataforma e está bloqueado.',
            ]);

        $this->assertNull(User::query()->where('email', 'outra-pessoa@example.com')->first());

        $this->post('/login', [
            'email' => 'laranja@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_rejeitar_sem_bloquear_nao_grava_documento(): void
    {
        Mail::fake();
        Setting::set('smtp_host', 'smtp.example.com', null);
        $admin = $this->seedAdmin();
        $seller = $this->createPendingSeller();

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.reject', $seller), [
                'reason' => 'Documento ilegível.',
            ])
            ->assertRedirect(route('plataforma.kyc.show', $seller));

        $seller->refresh();
        $this->assertSame(User::KYC_REJECTED, $seller->kyc_status);
        $this->assertSame(0, BlockedDocument::query()->count());
        Mail::assertSent(KycRejectedMail::class);
    }

    public function test_recusar_e_bloquear_pj_trava_cnpj_e_cpf_do_responsavel(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $seller = $this->createPendingSeller([
            'email' => 'empresa-laranja@example.com',
            'person_type' => 'pj',
            'document' => '11222333000181',
            'company_name' => 'Empresa Laranja Ltda',
            'legal_representative_cpf' => '39053344705',
        ]);

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.reject-block', $seller), [
                'reason' => 'CNPJ de laranja.',
            ])
            ->assertRedirect(route('plataforma.kyc.show', $seller));

        $this->assertTrue(BlockedDocument::query()->where('document', '11222333000181')->where('kind', 'cnpj')->exists());
        $this->assertTrue(BlockedDocument::query()->where('document', '39053344705')->where('kind', 'cpf')->exists());

        $this->post('/logout');

        $this->postJson('/cadastro/validar-documento', [
            'person_type' => 'pj',
            'document' => '11222333000181',
            'legal_representative_cpf' => '11144477735',
        ])->assertOk()->assertJson([
            'available' => false,
            'message' => 'Este CNPJ já existe na plataforma e está bloqueado.',
        ]);

        $this->postJson('/cadastro/validar-documento', [
            'person_type' => 'pj',
            'document' => '04252011000110',
            'legal_representative_cpf' => '39053344705',
        ])->assertOk()->assertJson([
            'available' => false,
            'field' => 'legal_representative_cpf',
            'message' => 'Este CPF já existe na plataforma e está bloqueado.',
        ]);

        $this->post('/cadastro', $this->registrationPayload([
            'person_type' => 'pj',
            'document' => '11222333000181',
            'company_name' => 'Outra Empresa',
            'legal_representative_cpf' => '11144477735',
            'email' => 'outra-empresa@example.com',
        ]))->assertSessionHasErrors([
            'document' => 'Este CNPJ já existe na plataforma e está bloqueado.',
        ]);
    }

    public function test_bloqueio_permanece_mesmo_sem_a_conta_original(): void
    {
        BlockedDocument::query()->create([
            'document' => '52998224725',
            'kind' => 'cpf',
            'reason' => 'Conta removida depois do bloqueio.',
        ]);

        $this->seedAdmin();

        $this->postJson('/cadastro/validar-documento', [
            'person_type' => 'pf',
            'document' => '529.982.247-25',
        ])->assertOk()->assertJson([
            'available' => false,
            'message' => 'Este CPF já existe na plataforma e está bloqueado.',
        ]);
    }
}
