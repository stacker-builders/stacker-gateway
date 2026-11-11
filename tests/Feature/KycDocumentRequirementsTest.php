<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureStackerLicense;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\MerchantOperationalGuard;
use App\Support\KycRequiredDocuments;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycDocumentRequirementsTest extends TestCase
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

    private function createSeller(array $overrides = []): User
    {
        $seller = User::query()->create(array_merge([
            'name' => 'Seller Req',
            'email' => 'kyc-req-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_INFOPRODUTOR,
            'person_type' => 'pf',
            'kyc_status' => User::KYC_NOT_SUBMITTED,
            'account_status' => 'pending',
            'kyc_requirements_version' => 1,
        ], $overrides));
        $seller->update(['tenant_id' => $seller->id]);

        return $seller->fresh();
    }

    private function seedPlatformAdmin(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-kyc-req-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null,
        ]);
    }

    private function img(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 300);
    }

    public function test_pending_kyc_seller_can_save_document_preferences(): void
    {
        $seller = $this->createSeller(['person_type' => 'pf']);

        $this->actingAs($seller)
            ->postJson(route('kyc.preferences'), [
                'identity_document_type' => 'cnh',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'identity_document_type' => 'cnh',
            ]);

        $this->assertSame('cnh', $seller->fresh()->identity_document_type);
    }

    public function test_new_pf_submission_requires_v2_documents(): void
    {
        $seller = $this->createSeller(['person_type' => 'pf']);

        $this->assertSame(KycRequiredDocuments::VERSION_CURRENT, KycRequiredDocuments::effectiveVersion($seller));
        $this->assertFalse(KycRequiredDocuments::hasAllRequired($seller));

        $seller->forceFill(['identity_document_type' => 'rg'])->save();
        $kinds = KycRequiredDocuments::kindsForUser($seller->fresh());
        $this->assertEqualsCanonicalizing([
            KycDocument::KIND_RG_FRONT,
            KycDocument::KIND_RG_BACK,
            KycDocument::KIND_ADDRESS_PROOF,
            KycDocument::KIND_SELFIE_WITH_DOCUMENT,
        ], $kinds);
    }

    public function test_cnh_requires_single_identity_file(): void
    {
        $seller = $this->createSeller([
            'person_type' => 'pf',
            'identity_document_type' => 'cnh',
        ]);

        $this->assertEqualsCanonicalizing([
            KycDocument::KIND_RG_FRONT,
            KycDocument::KIND_ADDRESS_PROOF,
            KycDocument::KIND_SELFIE_WITH_DOCUMENT,
        ], KycRequiredDocuments::kindsForUser($seller));
    }

    public function test_pj_mei_requires_ccmei_not_company_document(): void
    {
        $seller = $this->createSeller([
            'person_type' => 'pj',
            'identity_document_type' => 'rg',
            'company_legal_nature' => 'mei',
        ]);

        $kinds = KycRequiredDocuments::kindsForUser($seller);
        $this->assertContains(KycDocument::KIND_CCMEI, $kinds);
        $this->assertContains(KycDocument::KIND_COMPANY_ADDRESS_PROOF, $kinds);
        $this->assertNotContains(KycDocument::KIND_COMPANY_DOCUMENT, $kinds);
        $this->assertNotContains(KycDocument::KIND_SELFIE_WITH_DOCUMENT, $kinds);
    }

    public function test_pending_review_legacy_stays_on_v1_requirements(): void
    {
        $seller = $this->createSeller([
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'kyc_requirements_version' => 1,
            'person_type' => 'pj',
        ]);

        $this->assertSame(KycRequiredDocuments::VERSION_LEGACY, KycRequiredDocuments::effectiveVersion($seller));
        $this->assertEqualsCanonicalizing([
            KycDocument::KIND_RG_FRONT,
            KycDocument::KIND_RG_BACK,
            KycDocument::KIND_COMPANY_DOCUMENT,
        ], KycRequiredDocuments::kindsForUser($seller));
    }

    public function test_approved_legacy_merchant_remains_operational_without_v2_docs(): void
    {
        $seller = $this->createSeller([
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
            'kyc_requirements_version' => 1,
        ]);

        $this->assertTrue($seller->isMerchantOperationallyApproved());
        $this->assertSame(KycRequiredDocuments::VERSION_LEGACY, KycRequiredDocuments::effectiveVersion($seller));
        $this->assertTrue(KycRequiredDocuments::mayUpgradeLegacyDocuments($seller));
        $this->assertContains(KycDocument::KIND_SELFIE_WITH_DOCUMENT, KycRequiredDocuments::kindsForUser($seller));

        try {
            MerchantOperationalGuard::assertCanRequestWithdrawal($seller);
            $this->fail('Saque de conta legada deveria exigir os documentos atuais.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
    }

    public function test_approved_legacy_merchant_can_submit_current_documents_and_then_withdraw(): void
    {
        Storage::fake('local');
        $admin = $this->seedPlatformAdmin();
        $seller = $this->createSeller([
            'kyc_status' => User::KYC_APPROVED,
            'account_status' => 'approved',
            'kyc_requirements_version' => 1,
            'identity_document_type' => 'rg',
        ]);

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'identity_document_type' => 'rg',
                'rg_front' => $this->img('rg_front.jpg'),
            ])
            ->assertOk();

        $this->actingAs($seller)
            ->post(route('kyc.finalize'), ['identity_document_type' => 'rg'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $seller->refresh();
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);

        foreach (['rg_back', 'address_proof', 'selfie_with_document'] as $field) {
            $this->actingAs($seller)
                ->postJson(route('kyc.document'), [
                    'field' => $field,
                    'identity_document_type' => 'rg',
                    $field => $this->img($field.'.jpg'),
                ])
                ->assertOk();
        }

        $this->actingAs($seller)
            ->post(route('kyc.finalize'), ['identity_document_type' => 'rg'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $seller->refresh();
        $this->assertSame(User::KYC_PENDING_REVIEW, $seller->kyc_status);
        $this->assertSame(2, (int) $seller->kyc_requirements_version);
        $this->assertFalse($seller->isMerchantOperationallyApproved());

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.approve', $seller))
            ->assertRedirect(route('plataforma.kyc.show', $seller));

        $seller->refresh();
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);
        $this->assertSame('approved', $seller->account_status);
        $this->assertFalse(KycRequiredDocuments::mayUpgradeLegacyDocuments($seller));
        MerchantOperationalGuard::assertCanRequestWithdrawal($seller);
    }

    public function test_reupload_supersedes_previous_file_instead_of_deleting(): void
    {
        Storage::fake('local');

        $seller = $this->createSeller(['identity_document_type' => 'rg']);

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'identity_document_type' => 'rg',
                'rg_front' => $this->img('a.jpg'),
            ])
            ->assertOk();

        $first = KycDocument::query()->where('user_id', $seller->id)->where('kind', 'rg_front')->first();
        $this->assertNotNull($first);
        $this->assertNull($first->superseded_at);

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'rg_front',
                'identity_document_type' => 'rg',
                'rg_front' => $this->img('b.jpg'),
            ])
            ->assertOk();

        $first->refresh();
        $this->assertNotNull($first->superseded_at);

        $active = KycDocument::query()->where('user_id', $seller->id)->where('kind', 'rg_front')->active()->count();
        $this->assertSame(1, $active);
        $this->assertSame(2, KycDocument::query()->where('user_id', $seller->id)->where('kind', 'rg_front')->count());
    }

    public function test_finalize_pf_v2_and_admin_can_approve(): void
    {
        Storage::fake('local');
        $admin = $this->seedPlatformAdmin();
        $seller = $this->createSeller(['identity_document_type' => 'rg']);

        foreach (['rg_front', 'rg_back', 'address_proof', 'selfie_with_document'] as $field) {
            $this->actingAs($seller)
                ->postJson(route('kyc.document'), [
                    'field' => $field,
                    'identity_document_type' => 'rg',
                    $field => $this->img($field.'.jpg'),
                ])
                ->assertOk();
        }

        $this->actingAs($seller)
            ->post(route('kyc.finalize'), ['identity_document_type' => 'rg'])
            ->assertRedirect();

        $seller->refresh();
        $this->assertSame(User::KYC_PENDING_REVIEW, $seller->kyc_status);
        $this->assertSame(2, (int) $seller->kyc_requirements_version);

        $this->actingAs($admin)
            ->post(route('plataforma.kyc.approve', $seller))
            ->assertRedirect(route('plataforma.kyc.show', $seller));

        $seller->refresh();
        $this->assertSame(User::KYC_APPROVED, $seller->kyc_status);
    }

    public function test_company_document_upload_is_rejected_for_new_flow(): void
    {
        Storage::fake('local');
        $seller = $this->createSeller([
            'person_type' => 'pj',
            'identity_document_type' => 'rg',
            'company_legal_nature' => 'mei',
        ]);

        $this->actingAs($seller)
            ->postJson(route('kyc.document'), [
                'field' => 'company_document',
                'company_document' => $this->img('company.jpg'),
            ])
            ->assertUnprocessable();
    }
}
