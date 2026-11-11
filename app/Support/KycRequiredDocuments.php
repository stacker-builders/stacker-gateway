<?php

namespace App\Support;

use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class KycRequiredDocuments
{
    public const VERSION_LEGACY = 1;

    public const VERSION_CURRENT = 2;

    public const IDENTITY_RG = 'rg';

    public const IDENTITY_CNH = 'cnh';

    public const IDENTITY_PASSPORT = 'passport';

    /** @var list<string> */
    public const IDENTITY_TYPES = [
        self::IDENTITY_RG,
        self::IDENTITY_CNH,
        self::IDENTITY_PASSPORT,
    ];

    public const COMPANY_NATURE_MEI = 'mei';

    public const COMPANY_NATURE_OTHER = 'other';

    /** @var list<string> */
    public const COMPANY_NATURES = [
        self::COMPANY_NATURE_MEI,
        self::COMPANY_NATURE_OTHER,
    ];

    /**
     * Conta aprovada antes das regras atuais: pode reenviar o conjunto vigente
     * para voltar à análise e, depois da aprovação, solicitar saque.
     */
    public static function mayUpgradeLegacyDocuments(User $user): bool
    {
        if ((string) ($user->kyc_status ?? '') !== User::KYC_APPROVED) {
            return false;
        }

        if (PjConversion::isCollectingOrPending($user)) {
            return false;
        }

        return self::effectiveVersion($user) < self::VERSION_CURRENT;
    }

    /**
     * Versão efetiva das regras de documentos.
     * Contas já em análise/aprovadas mantêm a versão gravada (legado = 1).
     * Novos envios (not_submitted / rejected) usam a versão atual.
     * Aprovadas no legado podem reenviar pela versão atual (mayUpgradeLegacyDocuments).
     */
    public static function effectiveVersion(User $user): int
    {
        $status = (string) ($user->kyc_status ?? User::KYC_NOT_SUBMITTED);
        if (in_array($status, [User::KYC_PENDING_REVIEW, User::KYC_APPROVED], true)) {
            if (! Schema::hasColumn('users', 'kyc_requirements_version')) {
                return self::VERSION_LEGACY;
            }

            $stored = (int) ($user->kyc_requirements_version ?? self::VERSION_LEGACY);

            return $stored > 0 ? $stored : self::VERSION_LEGACY;
        }

        return self::VERSION_CURRENT;
    }

    /**
     * @return list<string>
     */
    public static function kindsForUser(User $user): array
    {
        if (PjConversion::isCollectingOrPending($user)) {
            return self::conversionKindsForUser($user);
        }

        if (self::effectiveVersion($user) === self::VERSION_LEGACY && ! self::mayUpgradeLegacyDocuments($user)) {
            return self::legacyKindsForUser($user);
        }

        return self::currentKindsForUser($user);
    }

    /**
     * Docs da migração PF→PJ: identidade já enviada + documentos atuais da empresa.
     *
     * @return list<string>
     */
    public static function conversionKindsForUser(User $user): array
    {
        $identityType = self::normalizeIdentityType($user->identity_document_type ?? null);
        $identity = $identityType !== null
            ? self::identityKindsForType($identityType)
            : [KycDocument::KIND_RG_FRONT, KycDocument::KIND_RG_BACK];

        return array_values(array_unique(array_merge($identity, self::conversionCompanyKinds($user))));
    }

    /**
     * @return list<string>
     */
    public static function conversionCompanyKinds(User $user): array
    {
        $cfg = KycRequirementSettings::resolved();
        $kinds = [];
        if ($cfg['require_company_address_proof']) {
            $kinds[] = KycDocument::KIND_COMPANY_ADDRESS_PROOF;
        }
        if ($cfg['require_company_constitution']) {
            $nature = self::normalizeCompanyNature(
                PjConversion::companyLegalNature($user) ?? $user->company_legal_nature ?? null
            );
            if ($nature === self::COMPANY_NATURE_MEI) {
                $kinds[] = KycDocument::KIND_CCMEI;
            } elseif ($nature === self::COMPANY_NATURE_OTHER) {
                $kinds[] = KycDocument::KIND_SOCIAL_CONTRACT;
            }
        }

        return $kinds;
    }

    /**
     * @return list<string>
     */
    public static function legacyKindsForUser(User $user): array
    {
        $kinds = [KycDocument::KIND_RG_FRONT, KycDocument::KIND_RG_BACK];
        if ($user->person_type === 'pj') {
            $kinds[] = KycDocument::KIND_COMPANY_DOCUMENT;
        }

        return $kinds;
    }

    /**
     * @return list<string>
     */
    public static function currentKindsForUser(User $user): array
    {
        $cfg = KycRequirementSettings::resolved();
        $identityType = self::normalizeIdentityType($user->identity_document_type ?? null);

        if ($identityType !== null && ! in_array($identityType, $cfg['allowed_identity_types'], true)) {
            $identityType = $cfg['allowed_identity_types'][0] ?? self::IDENTITY_RG;
        }

        $kinds = self::identityKindsForType($identityType);

        if ($user->person_type === 'pj') {
            if ($cfg['require_company_address_proof']) {
                $kinds[] = KycDocument::KIND_COMPANY_ADDRESS_PROOF;
            }
            if ($cfg['require_company_constitution']) {
                $nature = self::normalizeCompanyNature($user->company_legal_nature ?? null);
                if ($nature === self::COMPANY_NATURE_MEI) {
                    $kinds[] = KycDocument::KIND_CCMEI;
                } elseif ($nature === self::COMPANY_NATURE_OTHER) {
                    $kinds[] = KycDocument::KIND_SOCIAL_CONTRACT;
                }
            }
        } else {
            if ($cfg['require_address_proof']) {
                $kinds[] = KycDocument::KIND_ADDRESS_PROOF;
            }
            if ($cfg['require_selfie_with_document']) {
                $kinds[] = KycDocument::KIND_SELFIE_WITH_DOCUMENT;
            }
        }

        return $kinds;
    }

    /**
     * @return list<string>
     */
    public static function identityKindsForType(?string $type): array
    {
        $type = self::normalizeIdentityType($type) ?? self::IDENTITY_RG;

        return match ($type) {
            self::IDENTITY_CNH, self::IDENTITY_PASSPORT => [KycDocument::KIND_RG_FRONT],
            default => [KycDocument::KIND_RG_FRONT, KycDocument::KIND_RG_BACK],
        };
    }

    public static function normalizeIdentityType(mixed $value): ?string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';
        if ($v === '' || ! in_array($v, self::IDENTITY_TYPES, true)) {
            return null;
        }

        return $v;
    }

    public static function normalizeCompanyNature(mixed $value): ?string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';
        if ($v === '' || ! in_array($v, self::COMPANY_NATURES, true)) {
            return null;
        }

        return $v;
    }

    /**
     * Sugere MEI a partir do snapshot BrasilAPI (quando disponível).
     */
    public static function suggestCompanyNatureFromLookup(User $user): ?string
    {
        $lookup = is_array($user->cnpj_lookup) ? $user->cnpj_lookup : [];
        $natureza = (string) ($lookup['natureza_juridica'] ?? '');
        if ($natureza === '') {
            return null;
        }

        $upper = mb_strtoupper($natureza);
        if (str_contains($upper, 'MEI')
            || str_contains($upper, 'MICROEMPREENDEDOR')
            || str_contains($upper, '213-5')
            || str_contains($upper, '2135')) {
            return self::COMPANY_NATURE_MEI;
        }

        return self::COMPANY_NATURE_OTHER;
    }

    /**
     * @return list<string> kinds faltantes (documentos ativos)
     */
    public static function missingKindsForUser(User $user): array
    {
        $required = self::kindsForUser($user);
        $existing = KycDocument::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereIn('kind', $required)
            ->pluck('kind')
            ->all();

        return array_values(array_diff($required, $existing));
    }

    public static function hasAllRequired(User $user): bool
    {
        if (PjConversion::isCollectingOrPending($user)) {
            return self::hasAllRequiredForPjConversion($user);
        }

        if (self::usesCurrentCompleteness($user)) {
            $cfg = KycRequirementSettings::resolved();
            $identityType = self::normalizeIdentityType($user->identity_document_type ?? null);
            if ($identityType === null || ! in_array($identityType, $cfg['allowed_identity_types'], true)) {
                return false;
            }
            if ($user->person_type === 'pj'
                && $cfg['require_company_constitution']
                && self::normalizeCompanyNature($user->company_legal_nature ?? null) === null) {
                return false;
            }
        }

        return self::missingKindsForUser($user) === [];
    }

    public static function hasAllRequiredForPjConversion(User $user): bool
    {
        $cfg = KycRequirementSettings::resolved();
        if ($cfg['require_company_constitution']
            && self::normalizeCompanyNature(PjConversion::companyLegalNature($user) ?? $user->company_legal_nature ?? null) === null) {
            return false;
        }

        return self::missingKindsForUser($user) === [];
    }

    /**
     * @return list<string> labels legíveis dos documentos/campos faltantes
     */
    public static function missingLabelsForUser(User $user): array
    {
        $labels = [];

        if (PjConversion::isCollectingOrPending($user)) {
            $cfg = KycRequirementSettings::resolved();
            if ($cfg['require_company_constitution']
                && self::normalizeCompanyNature(PjConversion::companyLegalNature($user) ?? $user->company_legal_nature ?? null) === null) {
                $labels[] = 'natureza jurídica da empresa (MEI ou demais)';
            }
        } elseif (self::usesCurrentCompleteness($user)) {
            $cfg = KycRequirementSettings::resolved();
            $identityType = self::normalizeIdentityType($user->identity_document_type ?? null);
            if ($identityType === null || ! in_array($identityType, $cfg['allowed_identity_types'], true)) {
                $labels[] = 'tipo de documento de identificação';
            }
            if ($user->person_type === 'pj'
                && $cfg['require_company_constitution']
                && self::normalizeCompanyNature($user->company_legal_nature ?? null) === null) {
                $labels[] = 'natureza jurídica da empresa (MEI ou demais)';
            }
        }

        foreach (self::missingKindsForUser($user) as $kind) {
            $labels[] = self::labelForKind($kind, $user);
        }

        return array_values(array_unique($labels));
    }

    private static function usesCurrentCompleteness(User $user): bool
    {
        return self::effectiveVersion($user) === self::VERSION_CURRENT
            || self::mayUpgradeLegacyDocuments($user);
    }

    public static function labelForKind(string $kind, ?User $user = null): string
    {
        $identityType = $user
            ? self::normalizeIdentityType($user->identity_document_type ?? null)
            : null;

        return match ($kind) {
            KycDocument::KIND_RG_FRONT => match ($identityType) {
                self::IDENTITY_CNH => 'CNH',
                self::IDENTITY_PASSPORT => 'página de identificação do passaporte',
                default => 'documento de identificação (frente)',
            },
            KycDocument::KIND_RG_BACK => 'documento de identificação (verso)',
            KycDocument::KIND_ADDRESS_PROOF => 'comprovante de residência',
            KycDocument::KIND_SELFIE_WITH_DOCUMENT => 'selfie com documento',
            KycDocument::KIND_COMPANY_ADDRESS_PROOF => 'comprovante de endereço da empresa',
            KycDocument::KIND_CCMEI => 'CCMEI',
            KycDocument::KIND_SOCIAL_CONTRACT => 'contrato social / ato constitutivo',
            KycDocument::KIND_COMPANY_DOCUMENT => 'documento da empresa (legado)',
            KycDocument::KIND_CNPJ_CARD => 'cartão CNPJ (legado)',
            default => $kind,
        };
    }

    /**
     * Kinds legados ainda visíveis no admin (histórico).
     *
     * @return list<string>
     */
    public static function legacyKindValues(): array
    {
        return [
            KycDocument::KIND_COMPANY_DOCUMENT,
            KycDocument::KIND_CNPJ_CARD,
        ];
    }
}
