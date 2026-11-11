<?php

namespace App\Services;

use App\Models\User;
use App\Support\KycRequiredDocuments;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MerchantOperationalGuard
{
    public static function resolveTenantOwner(int $tenantId): ?User
    {
        if ($tenantId < 1) {
            return null;
        }

        $owner = User::query()
            ->where('id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();

        if ($owner !== null) {
            return $owner;
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
    }

    public static function tenantIsOperationallyApproved(int $tenantId): bool
    {
        $owner = self::resolveTenantOwner($tenantId);

        return $owner !== null && $owner->isMerchantOperationallyApproved();
    }

    /**
     * @throws HttpException
     */
    public static function assertCanOperateSellerPanel(User $user): void
    {
        if (! $user->canAccessSellerPanel()) {
            abort(403, 'Acesso não autorizado.');
        }

        if ($user->sellerAccountAccessBlocked()) {
            abort(403, 'Conta suspensa ou bloqueada.');
        }

        if ($user->isMerchantOperationallyApproved()) {
            return;
        }

        abort(403, $user->sellerPanelRestrictedMessage());
    }

    /**
     * @throws HttpException
     */
    public static function assertCanAcceptPayments(int $tenantId): void
    {
        if (! Schema::hasColumn('users', 'kyc_status')) {
            return;
        }

        $owner = self::resolveTenantOwner($tenantId);
        if ($owner === null) {
            abort(403, 'Conta do infoprodutor não encontrada.');
        }

        if ($owner->sellerAccountAccessBlocked()) {
            abort(403, 'Conta do infoprodutor suspensa ou bloqueada.');
        }

        if (! $owner->isMerchantOperationallyApproved()) {
            abort(403, 'Conta do infoprodutor aguarda verificação de identidade (KYC).');
        }
    }

    /**
     * @throws ValidationException
     */
    public static function assertCanRequestWithdrawal(User $user): void
    {
        if (! $user->isInfoprodutor()) {
            abort(403, 'Apenas o titular da conta pode solicitar saques.');
        }

        $subject = $user->kycSubjectUser();

        if (Schema::hasColumn('users', 'kyc_status') && ! $subject->hasApprovedKyc()) {
            throw ValidationException::withMessages([
                'amount' => 'Conclua a verificação de identidade (KYC) para solicitar saques.',
            ]);
        }

        if (Schema::hasColumn('users', 'kyc_status') && KycRequiredDocuments::mayUpgradeLegacyDocuments($subject)) {
            throw ValidationException::withMessages([
                'amount' => 'Envie os documentos de verificação atualizados e aguarde a aprovação da plataforma para solicitar saques.',
            ]);
        }

        if ($subject->sellerAccountAccessBlocked()) {
            throw ValidationException::withMessages([
                'amount' => 'Conta suspensa ou bloqueada. Contate o suporte.',
            ]);
        }

        if (! $subject->isMerchantOperationallyApproved()) {
            throw ValidationException::withMessages([
                'amount' => 'Sua conta ainda não foi aprovada pela plataforma.',
            ]);
        }
    }
}
