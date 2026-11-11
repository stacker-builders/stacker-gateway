<?php

namespace App\Services;

use App\Models\User;
use App\Support\BlockedDocuments;
use App\Support\BrazilianDocuments;
use App\Support\CnpjLookup;
use App\Support\KycRequiredDocuments;
use App\Support\PjConversion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PjConversionService
{
    /**
     * @return array<string, mixed>
     */
    public function start(User $subject, string $cnpjDigits, string $companyName, string $companyLegalNature, ?string $suggestedRazao = null): array
    {
        $this->assertCanStart($subject);

        $cnpj = BrazilianDocuments::digits($cnpjDigits);
        if (! BrazilianDocuments::isValidCnpj($cnpj)) {
            throw ValidationException::withMessages([
                'cnpj' => 'CNPJ inválido.',
            ]);
        }

        $name = trim($companyName);
        if ($name === '') {
            throw ValidationException::withMessages([
                'company_name' => 'Informe a razão social.',
            ]);
        }

        $nature = KycRequiredDocuments::normalizeCompanyNature($companyLegalNature);
        if ($nature === null) {
            throw ValidationException::withMessages([
                'company_legal_nature' => 'Informe se a empresa é MEI ou demais naturezas.',
            ]);
        }

        if ($blocked = BlockedDocuments::messageFor($cnpj)) {
            throw ValidationException::withMessages([
                'cnpj' => $blocked,
            ]);
        }

        if (PjConversion::cnpjTakenByAnotherUser($cnpj, (int) $subject->id)) {
            throw ValidationException::withMessages([
                'cnpj' => 'Este CNPJ já está cadastrado em outra conta.',
            ]);
        }

        $now = now()->toIso8601String();
        $previous = PjConversion::payload($subject);

        $payload = [
            'status' => PjConversion::STATUS_COLLECTING,
            'cnpj' => $cnpj,
            'company_name' => $name,
            'company_legal_nature' => $nature,
            'started_at' => $previous['started_at'] ?? $now,
            'updated_at' => $now,
            'submitted_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'previous_document' => BrazilianDocuments::digits((string) $subject->document),
        ];

        $attrs = [
            'pj_conversion' => $payload,
            'company_legal_nature' => $nature,
        ];
        if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
            $attrs['kyc_needs_document_review'] = false;
        }

        $subject->forceFill($attrs)->save();

        try {
            CnpjLookup::persistForUser($subject->fresh(), $cnpj, $name, $suggestedRazao, false);
        } catch (\Throwable) {
            // Consulta da Receita é auxiliar; a migração segue mesmo se a API falhar.
        }

        return $payload;
    }

    public function updateCompanyNature(User $subject, string $companyLegalNature): void
    {
        if (! PjConversion::allowsDocumentUpload($subject)) {
            return;
        }

        $nature = KycRequiredDocuments::normalizeCompanyNature($companyLegalNature);
        if ($nature === null) {
            return;
        }

        $payload = PjConversion::payload($subject);
        $payload['company_legal_nature'] = $nature;
        $payload['updated_at'] = now()->toIso8601String();

        $subject->forceFill([
            'pj_conversion' => $payload,
            'company_legal_nature' => $nature,
        ])->save();
    }

    public function submitForReview(User $subject): void
    {
        if (! PjConversion::allowsDocumentUpload($subject)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Inicie a migração para CNPJ antes de enviar os documentos.',
            ]);
        }

        if (! KycRequiredDocuments::hasAllRequired($subject)) {
            $list = implode(', ', KycRequiredDocuments::missingLabelsForUser($subject));

            throw ValidationException::withMessages([
                'pj_conversion' => 'Envie todos os documentos da empresa antes de concluir: '.$list.'.',
            ]);
        }

        $payload = PjConversion::payload($subject);
        $payload['status'] = PjConversion::STATUS_PENDING_REVIEW;
        $payload['submitted_at'] = now()->toIso8601String();
        $payload['updated_at'] = now()->toIso8601String();
        $payload['rejection_reason'] = null;

        $attrs = [
            'pj_conversion' => $payload,
        ];
        if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
            $attrs['kyc_needs_document_review'] = true;
        }

        $subject->forceFill($attrs)->save();
    }

    public function cancel(User $subject): void
    {
        if (PjConversion::isPendingReview($subject)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'A migração já está em análise e não pode ser cancelada.',
            ]);
        }
        if (! PjConversion::isCollecting($subject) && ! PjConversion::isRejected($subject)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Não há migração para CNPJ em andamento.',
            ]);
        }

        $attrs = [
            'pj_conversion' => null,
        ];
        if (($subject->person_type ?? '') === 'pf') {
            $attrs['company_legal_nature'] = null;
        }
        if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
            $attrs['kyc_needs_document_review'] = false;
        }

        $subject->forceFill($attrs)->save();
    }

    public function approve(User $subject): void
    {
        if (! PjConversion::isPendingReview($subject)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Não há migração para CNPJ aguardando análise.',
            ]);
        }
        if (($subject->person_type ?? '') !== 'pf') {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Esta conta já não é pessoa física.',
            ]);
        }
        if ($subject->kyc_status !== User::KYC_APPROVED) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'A conta precisa estar com KYC aprovado para migrar sem interromper a operação.',
            ]);
        }

        $cnpj = PjConversion::cnpj($subject);
        if ($cnpj === null || ! BrazilianDocuments::isValidCnpj($cnpj)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'CNPJ da migração inválido.',
            ]);
        }
        if ($blocked = BlockedDocuments::messageFor($cnpj)) {
            throw ValidationException::withMessages([
                'pj_conversion' => $blocked,
            ]);
        }

        if (PjConversion::cnpjTakenByAnotherUser($cnpj, (int) $subject->id)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Este CNPJ já está cadastrado em outra conta.',
            ]);
        }

        $cpf = BrazilianDocuments::digits((string) $subject->document);
        if (! BrazilianDocuments::isValidCpf($cpf)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'O CPF atual da conta é inválido e não pode virar o do responsável legal.',
            ]);
        }

        if (! KycRequiredDocuments::hasAllRequired($subject)) {
            $list = implode(', ', KycRequiredDocuments::missingLabelsForUser($subject));

            throw ValidationException::withMessages([
                'pj_conversion' => 'Documentos obrigatórios ausentes: '.$list.'.',
            ]);
        }

        $companyName = PjConversion::companyName($subject) ?: trim((string) $subject->company_name);
        $nature = PjConversion::companyLegalNature($subject);

        DB::transaction(function () use ($subject, $cnpj, $cpf, $companyName, $nature) {
            $attrs = [
                'person_type' => 'pj',
                'document' => $cnpj,
                'legal_representative_cpf' => $cpf,
                'company_name' => $companyName !== '' ? $companyName : null,
                'company_legal_nature' => $nature,
                'pj_conversion' => null,
                'kyc_status' => User::KYC_APPROVED,
            ];
            if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
                $attrs['kyc_needs_document_review'] = false;
            }
            if (Schema::hasColumn('users', 'kyc_requirements_version')) {
                $attrs['kyc_requirements_version'] = KycRequiredDocuments::VERSION_CURRENT;
            }

            $subject->forceFill($attrs)->save();
        });
    }

    public function reject(User $subject, string $reason): void
    {
        if (! PjConversion::isPendingReview($subject)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Não há migração para CNPJ aguardando análise.',
            ]);
        }

        $payload = PjConversion::payload($subject);
        $payload['status'] = PjConversion::STATUS_REJECTED;
        $payload['rejected_at'] = now()->toIso8601String();
        $payload['updated_at'] = now()->toIso8601String();
        $payload['rejection_reason'] = trim($reason);

        $attrs = [
            'pj_conversion' => $payload,
        ];
        if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
            $attrs['kyc_needs_document_review'] = false;
        }

        $subject->forceFill($attrs)->save();
    }

    private function assertCanStart(User $subject): void
    {
        if (($subject->person_type ?? '') !== 'pf') {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Somente contas pessoa física podem migrar para CNPJ.',
            ]);
        }
        if (! $subject->isMerchantOperationallyApproved()) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Conclua a verificação KYC da conta PF antes de migrar para CNPJ.',
            ]);
        }
        if (PjConversion::isPendingReview($subject)) {
            throw ValidationException::withMessages([
                'pj_conversion' => 'Já existe uma migração para CNPJ em análise.',
            ]);
        }
    }
}
