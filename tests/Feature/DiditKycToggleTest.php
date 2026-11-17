<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\User;
use App\Support\DiditKycSettings;
use App\Support\KycRequirementSettings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiditKycToggleTest extends TestCase
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

    public function test_didit_stays_off_until_the_admin_saves_credentials(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->from('/plataforma/configuracoes')
            ->put('/plataforma/configuracoes', [
                'kyc_didit_enabled' => '1',
            ])
            ->assertSessionHasErrors('kyc_didit_api_key');

        $this->assertFalse(DiditKycSettings::isEnabled());

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'kyc_didit_enabled' => '1',
                'kyc_didit_workflow_id' => '11111111-1111-1111-1111-111111111111',
                'kyc_didit_api_key' => 'test-api-key',
                'kyc_didit_webhook_secret' => 'whsec-test',
            ])
            ->assertRedirect();

        $this->assertTrue(DiditKycSettings::isReady());
        $this->assertSame('test-api-key', DiditKycSettings::apiKey());

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'kyc_didit_enabled' => '0',
            ])
            ->assertRedirect();

        $this->assertFalse(DiditKycSettings::isEnabled());
        $this->assertSame('test-api-key', DiditKycSettings::apiKey());
    }

    public function test_seller_opens_didit_when_enabled_and_keeps_manual_upload_when_disabled(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)
            ->post('/kyc/didit')
            ->assertSessionHasErrors('didit');

        $this->enableDidit();

        Http::fake([
            'verification.didit.me/*' => Http::response([
                'session_id' => 'sess-didit-1',
                'url' => 'https://verify.didit.me/session/abc',
                'status' => 'Not Started',
            ], 201),
        ]);

        $this->actingAs($seller)
            ->post('/kyc/didit')
            ->assertRedirect('https://verify.didit.me/session/abc');

        $seller->refresh();
        $this->assertSame(DiditKycSettings::PROVIDER, $seller->kyc_provider);
        $this->assertSame('sess-didit-1', $seller->kyc_external_session_id);
        $this->assertSame(User::KYC_NOT_SUBMITTED, $seller->kyc_status);

        $this->actingAs($seller)
            ->postJson('/kyc/document', ['field' => 'rg_front'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A verificação de identidade desta conta é feita pela Didit. Use o botão de verificação.');
    }

    public function test_signed_webhook_approves_and_ignores_a_later_weaker_status(): void
    {
        $seller = $this->seller();
        $seller->forceFill([
            'kyc_provider' => DiditKycSettings::PROVIDER,
            'kyc_external_session_id' => 'sess-didit-1',
            'account_status' => 'pending',
        ])->save();
        $this->enableDidit();

        $this->postDidit([
            'webhook_type' => 'status.updated',
            'session_id' => 'sess-didit-1',
            'status' => 'Approved',
            'vendor_data' => (string) $seller->id,
            'timestamp' => time(),
        ])->assertOk();

        $seller->refresh();
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);
        $this->assertSame('approved', $seller->account_status);

        $this->postDidit([
            'webhook_type' => 'status.updated',
            'session_id' => 'sess-didit-1',
            'status' => 'In Progress',
            'vendor_data' => (string) $seller->id,
            'timestamp' => time(),
        ])->assertOk();

        $this->assertSame(User::KYC_APPROVED, $seller->fresh()->kyc_status);

        $this->postJson('/webhooks/didit', ['status' => 'Declined'], [
            'X-Timestamp' => (string) time(),
            'X-Signature' => 'deadbeef',
        ])->assertStatus(401);
    }

    public function test_in_review_waits_for_the_platform_and_decline_rejects(): void
    {
        $seller = $this->seller();
        $seller->forceFill([
            'kyc_provider' => DiditKycSettings::PROVIDER,
            'kyc_external_session_id' => 'sess-review',
        ])->save();
        $this->enableDidit();

        $this->postDidit([
            'webhook_type' => 'status.updated',
            'session_id' => 'sess-review',
            'status' => 'In Review',
            'vendor_data' => (string) $seller->id,
            'timestamp' => time(),
        ])->assertOk();

        $this->assertSame(User::KYC_PENDING_REVIEW, $seller->fresh()->kyc_status);

        $this->postDidit([
            'webhook_type' => 'status.updated',
            'session_id' => 'sess-review',
            'status' => 'Declined',
            'vendor_data' => (string) $seller->id,
            'timestamp' => time(),
        ])->assertOk();

        $fresh = $seller->fresh();
        $this->assertSame(User::KYC_REJECTED, $fresh->kyc_status);
        $this->assertNotSame('', (string) $fresh->kyc_rejection_reason);
    }

    public function test_cnpj_didit_approval_waits_for_company_documents(): void
    {
        Storage::fake('local');
        $seller = $this->seller();
        $seller->forceFill([
            'person_type' => 'pj',
            'kyc_provider' => DiditKycSettings::PROVIDER,
            'kyc_external_session_id' => 'sess-cnpj',
            'account_status' => 'pending',
        ])->save();
        $this->enableDidit();
        $seller->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_identity_verified_at'), 'coluna kyc_identity_verified_at ausente');
        $this->assertSame('pj', $seller->person_type);
        $this->assertTrue(DiditKycSettings::isEnabled(), 'didit desligada');
        $this->assertTrue(\App\Support\KycRequiredDocuments::diditLeavesCompanyReview($seller), 'nao deixou analise da empresa');

        $this->postDidit([
            'webhook_type' => 'status.updated',
            'session_id' => 'sess-cnpj',
            'status' => 'Approved',
            'vendor_data' => (string) $seller->id,
            'timestamp' => time(),
        ])->assertOk();

        $seller->refresh();
        $this->assertSame(User::KYC_NOT_SUBMITTED, $seller->kyc_status);
        $this->assertSame('pending', $seller->account_status);
        $this->assertSame(DiditKycSettings::PROVIDER, $seller->kyc_provider);
        $this->assertNotNull($seller->kyc_identity_verified_at);

        $this->actingAs($seller)
            ->postJson('/kyc/document', ['field' => 'rg_front'])
            ->assertStatus(422);

        $this->actingAs($seller)
            ->post('/kyc/document', [
                'field' => 'company_address_proof',
                'company_legal_nature' => 'mei',
                'company_address_proof' => UploadedFile::fake()->image('endereco.jpg', 400, 300),
            ])
            ->assertOk();

        $this->actingAs($seller)
            ->post('/kyc/document', [
                'field' => 'ccmei',
                'company_legal_nature' => 'mei',
                'ccmei' => UploadedFile::fake()->image('ccmei.jpg', 400, 300),
            ])
            ->assertOk();

        $this->actingAs($seller)
            ->post('/kyc/finalize', ['company_legal_nature' => 'mei'])
            ->assertRedirect();

        $fresh = $seller->fresh();
        $this->assertSame(User::KYC_PENDING_REVIEW, $fresh->kyc_status);
        $this->assertSame(DiditKycSettings::PROVIDER, $fresh->kyc_provider);
        $this->assertSame('sess-cnpj', $fresh->kyc_external_session_id);
        $this->assertNotNull($fresh->kyc_identity_verified_at);
    }

    public function test_cnpj_is_fully_approved_when_company_documents_are_not_required(): void
    {
        $seller = $this->seller();
        $seller->forceFill([
            'person_type' => 'pj',
            'kyc_provider' => DiditKycSettings::PROVIDER,
            'kyc_external_session_id' => 'sess-cnpj-full',
            'account_status' => 'pending',
        ])->save();
        $this->enableDidit();
        KycRequirementSettings::persistFromValidated([
            'kyc_require_company_address_proof' => '0',
            'kyc_require_company_constitution' => '0',
        ]);

        $this->postDidit([
            'webhook_type' => 'status.updated',
            'session_id' => 'sess-cnpj-full',
            'status' => 'Approved',
            'vendor_data' => (string) $seller->id,
            'timestamp' => time(),
        ])->assertOk();

        $fresh = $seller->fresh();
        $this->assertSame(User::KYC_APPROVED, $fresh->kyc_status);
        $this->assertNull($fresh->kyc_identity_verified_at);
    }

    private function enableDidit(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put('/plataforma/configuracoes', [
                'kyc_didit_enabled' => '1',
                'kyc_didit_workflow_id' => '11111111-1111-1111-1111-111111111111',
                'kyc_didit_api_key' => 'test-api-key',
                'kyc_didit_webhook_secret' => 'whsec-test',
            ])
            ->assertRedirect();
    }

    private function seller(): User
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'pending',
            'kyc_status' => User::KYC_NOT_SUBMITTED,
        ]);
        $seller->forceFill(['tenant_id' => $seller->id])->save();

        return $seller->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postDidit(array $payload): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', (string) $raw, 'whsec-test');

        return $this->call(
            'POST',
            '/webhooks/didit',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_TIMESTAMP' => (string) time(),
                'HTTP_X_SIGNATURE' => $signature,
            ],
            (string) $raw
        );
    }
}
