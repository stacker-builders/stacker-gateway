<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\PlatformEmailNotifications;
use App\Services\PjConversionService;
use App\Services\SellerActivityLogService;
use App\Support\KycRequiredDocuments;
use App\Support\KycRequirementSettings;
use App\Support\KycUpload;
use App\Support\PjConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SellerKycController extends Controller
{
    use LogsSellerActivity;

    public function __construct(
        protected PlatformEmailNotifications $platformEmailNotifications,
        protected PjConversionService $pjConversionService,
    ) {}

    private static function financeiroKycTabUrl(): string
    {
        return '/financeiro?tab=seus-dados';
    }

    /** @var array<string, string> */
    private const FIELD_TO_KIND = [
        'rg_front' => KycDocument::KIND_RG_FRONT,
        'rg_back' => KycDocument::KIND_RG_BACK,
        'address_proof' => KycDocument::KIND_ADDRESS_PROOF,
        'selfie_with_document' => KycDocument::KIND_SELFIE_WITH_DOCUMENT,
        'company_address_proof' => KycDocument::KIND_COMPANY_ADDRESS_PROOF,
        'ccmei' => KycDocument::KIND_CCMEI,
        'social_contract' => KycDocument::KIND_SOCIAL_CONTRACT,
        // Legado (apenas leitura/histórico — upload bloqueado abaixo)
        'company_document' => KycDocument::KIND_COMPANY_DOCUMENT,
    ];

    public function show(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $subject = $user->kycSubjectUser();
        if ($subject->kyc_status === User::KYC_APPROVED && ! KycRequiredDocuments::mayUpgradeLegacyDocuments($subject)) {
            return redirect()->route('dashboard')->with('success', 'Sua conta já está verificada.');
        }

        return redirect(self::financeiroKycTabUrl());
    }

    /**
     * Persiste tipo de documento / natureza jurídica (MEI) antes ou durante o upload.
     */
    public function updatePreferences(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        if (! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $subject = $user->kycSubjectUser();
        if ($blocked = $this->kycMutationBlockedMessage($subject)) {
            return response()->json(['message' => $blocked], 422);
        }

        $isPj = $this->treatAsPjForDocuments($subject);
        $conversionUpload = PjConversion::allowsDocumentUpload($subject);

        $rules = [
            'identity_document_type' => [$conversionUpload ? 'nullable' : 'required', 'string', Rule::in(KycRequiredDocuments::IDENTITY_TYPES)],
        ];
        $cfg = KycRequirementSettings::resolved();
        if ($isPj && $cfg['require_company_constitution']) {
            $rules['company_legal_nature'] = ['required', 'string', Rule::in(KycRequiredDocuments::COMPANY_NATURES)];
        } elseif ($isPj) {
            $rules['company_legal_nature'] = ['nullable', 'string', Rule::in(KycRequiredDocuments::COMPANY_NATURES)];
        }

        $validated = $request->validate($rules);

        $attrs = [];
        if (! $conversionUpload) {
            $identityType = KycRequiredDocuments::normalizeIdentityType($validated['identity_document_type'] ?? null);
            if ($identityType === null || ! KycRequirementSettings::isIdentityTypeAllowed($identityType)) {
                throw ValidationException::withMessages([
                    'identity_document_type' => 'Tipo de documento não permitido pela plataforma.',
                ]);
            }
            $attrs['identity_document_type'] = $identityType;
        }

        if ($isPj && array_key_exists('company_legal_nature', $validated) && $validated['company_legal_nature'] !== null) {
            $nature = KycRequiredDocuments::normalizeCompanyNature($validated['company_legal_nature']);
            $attrs['company_legal_nature'] = $nature;
            if ($conversionUpload && $nature !== null) {
                $this->pjConversionService->updateCompanyNature($subject, $nature);
            }
        }

        if ($attrs !== []) {
            $subject->forceFill($attrs)->save();
        }

        if ($request->header('X-Inertia') || ! $request->expectsJson()) {
            return redirect($this->kycReturnUrl($subject))->with('success', 'Preferências de documentos salvas.');
        }

        return response()->json([
            'ok' => true,
            'identity_document_type' => $subject->identity_document_type,
            'company_legal_nature' => $subject->company_legal_nature,
        ]);
    }

    /**
     * Envia um documento por vez (evita POST gigante com vários arquivos).
     */
    public function uploadDocument(Request $request): JsonResponse|RedirectResponse
    {
        if ($message = KycUpload::detectPostTooLarge()) {
            throw ValidationException::withMessages(['upload' => $message]);
        }

        $user = $request->user();
        if (! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $subject = $user->kycSubjectUser();
        if ($blocked = $this->kycMutationBlockedMessage($subject)) {
            return response()->json(['message' => $blocked], 422);
        }

        $isPj = $this->treatAsPjForDocuments($subject);
        $conversionUpload = PjConversion::allowsDocumentUpload($subject);

        $allowedFields = $conversionUpload
            ? ['company_address_proof', 'ccmei', 'social_contract']
            : [
                'rg_front',
                'rg_back',
                'address_proof',
                'selfie_with_document',
                'company_address_proof',
                'ccmei',
                'social_contract',
            ];

        $validated = $request->validate([
            'field' => ['required', 'string', Rule::in($allowedFields)],
            'identity_document_type' => ['nullable', 'string', Rule::in(KycRequiredDocuments::IDENTITY_TYPES)],
            'company_legal_nature' => ['nullable', 'string', Rule::in(KycRequiredDocuments::COMPANY_NATURES)],
        ]);

        $field = $validated['field'];

        if (! $conversionUpload && isset($validated['identity_document_type'])) {
            $subject->forceFill([
                'identity_document_type' => KycRequiredDocuments::normalizeIdentityType($validated['identity_document_type']),
            ])->save();
            $subject->refresh();
        }
        if ($isPj && isset($validated['company_legal_nature'])) {
            $nature = KycRequiredDocuments::normalizeCompanyNature($validated['company_legal_nature']);
            $subject->forceFill([
                'company_legal_nature' => $nature,
            ])->save();
            $subject->refresh();
            if ($conversionUpload && $nature !== null) {
                $this->pjConversionService->updateCompanyNature($subject, $nature);
                $subject->refresh();
            }
        }

        $this->assertFieldAllowedForSubject($subject, $field, $isPj);

        if (! $request->hasFile($field)) {
            throw ValidationException::withMessages([
                $field => KycUpload::messageForUploadError(UPLOAD_ERR_NO_FILE),
            ]);
        }

        $file = $request->file($field);
        KycUpload::assertValid($file, $field);

        $kind = self::FIELD_TO_KIND[$field];
        $disk = Storage::disk('local');
        $baseDir = 'kyc/'.$subject->id;

        try {
            $this->supersedeActiveKind($subject, $kind);
            $this->storeFile($subject, $file, $kind, $disk, $baseDir);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                $field => 'Não foi possível processar o arquivo. Use imagem (JPG, PNG, WebP ou HEIC) ou PDF, máx. 20 MB.',
            ]);
        }

        $this->logSellerActivity(SellerActivityLogService::KYC_DOCUMENT_UPLOADED, $subject, [
            'kind' => $kind,
        ]);

        if ($request->header('X-Inertia')) {
            return redirect($this->kycReturnUrl($subject))->with('success', 'Arquivo enviado. Envie os demais e clique em "Enviar para análise".');
        }

        return response()->json([
            'ok' => true,
            'field' => $field,
            'message' => 'Arquivo recebido.',
        ]);
    }

    /**
     * Envia todos os documentos obrigatórios de uma vez (legado / fallback).
     */
    public function store(Request $request): RedirectResponse
    {
        if ($message = KycUpload::detectPostTooLarge()) {
            return redirect(self::financeiroKycTabUrl())->with('error', $message);
        }

        $user = $request->user();
        if (! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $subject = $user->kycSubjectUser();
        if ($blocked = $this->kycMutationBlockedMessage($subject)) {
            return redirect($this->kycReturnUrl($subject))->with('error', $blocked);
        }

        $isPj = $this->treatAsPjForDocuments($subject);
        $conversionUpload = PjConversion::allowsDocumentUpload($subject);

        $prefRules = [
            'identity_document_type' => [$conversionUpload ? 'nullable' : 'required', 'string', Rule::in(KycRequiredDocuments::IDENTITY_TYPES)],
        ];
        $cfg = KycRequirementSettings::resolved();
        if ($isPj && $cfg['require_company_constitution']) {
            $prefRules['company_legal_nature'] = ['required', 'string', Rule::in(KycRequiredDocuments::COMPANY_NATURES)];
        } elseif ($isPj) {
            $prefRules['company_legal_nature'] = ['nullable', 'string', Rule::in(KycRequiredDocuments::COMPANY_NATURES)];
        }
        $prefs = $request->validate($prefRules);
        if (! $conversionUpload) {
            $identityType = KycRequiredDocuments::normalizeIdentityType($prefs['identity_document_type'] ?? null);
            if ($identityType === null || ! KycRequirementSettings::isIdentityTypeAllowed($identityType)) {
                return redirect($this->kycReturnUrl($subject))->with('error', 'Tipo de documento não permitido pela plataforma.');
            }
            $subject->forceFill([
                'identity_document_type' => $identityType,
            ])->save();
        }
        if ($isPj && ! empty($prefs['company_legal_nature'])) {
            $nature = KycRequiredDocuments::normalizeCompanyNature($prefs['company_legal_nature']);
            $subject->forceFill(['company_legal_nature' => $nature])->save();
            if ($conversionUpload && $nature !== null) {
                $this->pjConversionService->updateCompanyNature($subject, $nature);
            }
        }
        $subject->refresh();

        $kycFile = ['required', 'file', 'max:'.KycUpload::MAX_FILE_KB, 'mimes:jpg,jpeg,png,webp,heic,heif,pdf'];
        $requiredKinds = $conversionUpload
            ? KycRequiredDocuments::conversionCompanyKinds($subject)
            : KycRequiredDocuments::kindsForUser($subject);
        $rules = [];
        $messages = [];
        foreach ($requiredKinds as $kind) {
            $field = $kind;
            $rules[$field] = $kycFile;
            $messages[$field.'.max'] = 'O arquivo não pode ser maior que 20 MB.';
            $messages[$field.'.uploaded'] = KycUpload::messageForUploadError(UPLOAD_ERR_NO_FILE);
        }

        foreach (array_keys($rules) as $field) {
            if (! $request->hasFile($field)) {
                throw ValidationException::withMessages([
                    $field => KycUpload::messageForUploadError(UPLOAD_ERR_NO_FILE),
                ]);
            }
            KycUpload::assertValid($request->file($field), $field);
        }

        $request->validate($rules, $messages);

        $disk = Storage::disk('local');
        $baseDir = 'kyc/'.$subject->id;

        try {
            foreach ($requiredKinds as $kind) {
                $this->supersedeActiveKind($subject, $kind);
                $this->storeFile($subject, $request->file($kind), $kind, $disk, $baseDir);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return redirect($this->kycReturnUrl($subject))->with('error', 'Não foi possível processar os arquivos. Use imagem (JPG, PNG, WebP ou HEIC) ou PDF, máx. 20 MB por arquivo.');
        }

        return $this->completeKycSubmission($subject);
    }

    public function finalize(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user->canAccessSellerPanel()) {
            abort(403);
        }

        $subject = $user->kycSubjectUser();
        if ($blocked = $this->kycMutationBlockedMessage($subject)) {
            $key = str_contains($blocked, 'análise') ? 'info' : 'error';

            return redirect($this->kycReturnUrl($subject))->with($key, $blocked);
        }

        if ($request->filled('identity_document_type') || $request->filled('company_legal_nature')) {
            $isPj = $this->treatAsPjForDocuments($subject);
            $conversionUpload = PjConversion::allowsDocumentUpload($subject);
            $prefRules = [
                'identity_document_type' => ['nullable', 'string', Rule::in(KycRequiredDocuments::IDENTITY_TYPES)],
            ];
            if ($isPj) {
                $prefRules['company_legal_nature'] = ['nullable', 'string', Rule::in(KycRequiredDocuments::COMPANY_NATURES)];
            }
            $prefs = $request->validate($prefRules);
            $attrs = [];
            if (! $conversionUpload && ! empty($prefs['identity_document_type'])) {
                $attrs['identity_document_type'] = KycRequiredDocuments::normalizeIdentityType($prefs['identity_document_type']);
            }
            if ($isPj && ! empty($prefs['company_legal_nature'])) {
                $attrs['company_legal_nature'] = KycRequiredDocuments::normalizeCompanyNature($prefs['company_legal_nature']);
            }
            if ($attrs !== []) {
                $subject->forceFill($attrs)->save();
                $subject->refresh();
            }
            if ($conversionUpload && ! empty($prefs['company_legal_nature'])) {
                $this->pjConversionService->updateCompanyNature($subject, $prefs['company_legal_nature']);
                $subject->refresh();
            }
        }

        if (! KycRequiredDocuments::hasAllRequired($subject)) {
            $list = implode(', ', KycRequiredDocuments::missingLabelsForUser($subject));

            return redirect($this->kycReturnUrl($subject))->with('error', 'Envie todos os documentos antes de concluir: '.$list.'.');
        }

        return $this->completeKycSubmission($subject);
    }

    private function completeKycSubmission(User $subject): RedirectResponse
    {
        if (PjConversion::allowsDocumentUpload($subject)) {
            $this->pjConversionService->submitForReview($subject);
            $this->logSellerActivity(SellerActivityLogService::PJ_CONVERSION_SUBMITTED, $subject);

            return redirect($this->kycReturnUrl($subject->fresh()))->with('success', 'Documentos da empresa enviados. Sua conta PF continua operando enquanto a migração para CNPJ é analisada.');
        }

        return $this->markPendingReview($subject);
    }

    private function kycMutationBlockedMessage(User $subject): ?string
    {
        if ($subject->kyc_status === User::KYC_BLOCKED) {
            return 'Esta conta está bloqueada e não pode enviar documentos.';
        }
        if ($subject->kyc_status === User::KYC_PENDING_REVIEW) {
            return 'Documentos em análise. Aguarde a conclusão.';
        }
        if ($subject->kyc_status !== User::KYC_APPROVED) {
            return null;
        }
        if (PjConversion::isPendingReview($subject)) {
            return 'A migração para CNPJ está em análise. Aguarde a conclusão.';
        }
        if (PjConversion::allowsDocumentUpload($subject)) {
            return null;
        }
        if (KycRequiredDocuments::mayUpgradeLegacyDocuments($subject)) {
            return null;
        }

        return 'Conta já verificada.';
    }

    private function treatAsPjForDocuments(User $subject): bool
    {
        return ($subject->person_type ?? '') === 'pj' || PjConversion::allowsDocumentUpload($subject);
    }

    private function kycReturnUrl(User $subject): string
    {
        if (PjConversion::isCollectingOrPending($subject) || PjConversion::isRejected($subject)) {
            return route('profile.index');
        }

        return self::financeiroKycTabUrl();
    }

    private function markPendingReview(User $subject): RedirectResponse
    {
        $attrs = [
            'kyc_status' => User::KYC_PENDING_REVIEW,
            'kyc_rejection_reason' => null,
            'kyc_reviewed_at' => null,
            'kyc_reviewed_by' => null,
        ];
        if (Schema::hasColumn('users', 'kyc_requirements_version')) {
            $attrs['kyc_requirements_version'] = KycRequiredDocuments::VERSION_CURRENT;
        }
        if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
            $attrs['kyc_needs_document_review'] = false;
        }

        $subject->forceFill($attrs)->save();

        $this->platformEmailNotifications->kycSubmitted($subject->fresh());

        $this->logSellerActivity(SellerActivityLogService::KYC_SUBMITTED, $subject);

        return redirect($this->kycReturnUrl($subject))->with('success', 'Documentos enviados. Aguarde a análise da plataforma.');
    }

    private function assertFieldAllowedForSubject(User $subject, string $field, bool $isPj): void
    {
        $cfg = KycRequirementSettings::resolved();
        $identityType = KycRequiredDocuments::normalizeIdentityType($subject->identity_document_type ?? null);

        if (in_array($field, ['rg_front', 'rg_back'], true)) {
            if ($identityType === null) {
                throw ValidationException::withMessages([
                    $field => 'Selecione o tipo de documento de identificação antes de enviar.',
                ]);
            }
            if (! KycRequirementSettings::isIdentityTypeAllowed($identityType)) {
                throw ValidationException::withMessages([
                    $field => 'Tipo de documento não permitido pela plataforma.',
                ]);
            }
            $allowedIdentity = KycRequiredDocuments::identityKindsForType($identityType);
            $kind = self::FIELD_TO_KIND[$field];
            if (! in_array($kind, $allowedIdentity, true)) {
                throw ValidationException::withMessages([
                    $field => 'Este arquivo não se aplica ao tipo de documento selecionado.',
                ]);
            }

            return;
        }

        if (in_array($field, ['address_proof', 'selfie_with_document'], true)) {
            if ($isPj) {
                throw ValidationException::withMessages([
                    $field => 'Este documento aplica-se apenas a pessoa física.',
                ]);
            }
            if ($field === 'address_proof' && ! $cfg['require_address_proof']) {
                throw ValidationException::withMessages([
                    $field => 'Comprovante de residência não é exigido nesta plataforma.',
                ]);
            }
            if ($field === 'selfie_with_document' && ! $cfg['require_selfie_with_document']) {
                throw ValidationException::withMessages([
                    $field => 'Selfie com documento não é exigida nesta plataforma.',
                ]);
            }

            return;
        }

        if ($field === 'company_address_proof') {
            if (! $isPj) {
                throw ValidationException::withMessages([
                    $field => 'Documento da empresa só se aplica a contas PJ.',
                ]);
            }
            if (! $cfg['require_company_address_proof']) {
                throw ValidationException::withMessages([
                    $field => 'Comprovante de endereço da empresa não é exigido nesta plataforma.',
                ]);
            }

            return;
        }

        if (in_array($field, ['ccmei', 'social_contract'], true)) {
            if (! $isPj) {
                throw ValidationException::withMessages([
                    $field => 'Documento da empresa só se aplica a contas PJ.',
                ]);
            }
            if (! $cfg['require_company_constitution']) {
                throw ValidationException::withMessages([
                    $field => 'Documento de constituição não é exigido nesta plataforma.',
                ]);
            }
            $nature = KycRequiredDocuments::normalizeCompanyNature($subject->company_legal_nature ?? null);
            if ($nature === null) {
                throw ValidationException::withMessages([
                    $field => 'Informe se a empresa é MEI ou demais naturezas antes de enviar.',
                ]);
            }
            if ($field === 'ccmei' && $nature !== KycRequiredDocuments::COMPANY_NATURE_MEI) {
                throw ValidationException::withMessages([
                    $field => 'CCMEI aplica-se apenas a MEI.',
                ]);
            }
            if ($field === 'social_contract' && $nature !== KycRequiredDocuments::COMPANY_NATURE_OTHER) {
                throw ValidationException::withMessages([
                    $field => 'Contrato social / ato constitutivo aplica-se a empresas que não são MEI.',
                ]);
            }
        }
    }

    private function supersedeActiveKind(User $subject, string $kind): void
    {
        KycDocument::query()
            ->where('user_id', $subject->id)
            ->where('kind', $kind)
            ->active()
            ->get()
            ->each(function (KycDocument $old) {
                $old->forceFill(['superseded_at' => now()])->save();
            });
    }

    private function storeFile(User $subject, \Illuminate\Http\UploadedFile $file, string $kind, \Illuminate\Contracts\Filesystem\Filesystem $disk, string $baseDir): void
    {
        KycUpload::assertValid($file, $kind);

        $mime = KycUpload::normalizeMime($file);
        $ext = KycUpload::extensionForMime($mime);
        $name = Str::uuid()->toString().'.'.$ext;
        $storedPath = $disk->putFileAs($baseDir, $file, $name);
        if (! is_string($storedPath) || $storedPath === '') {
            throw new \RuntimeException('Falha ao gravar arquivo.');
        }

        KycDocument::query()->create([
            'user_id' => $subject->id,
            'kind' => $kind,
            'disk_path' => $storedPath,
            'original_mime' => $mime,
            'size_bytes' => (int) $file->getSize(),
        ]);
    }
}
