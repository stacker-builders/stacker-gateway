<?php

namespace App\Services;

use App\Events\KycApproved;
use App\Events\KycRejected;
use App\Models\User;
use App\Support\DiditKycSettings;
use App\Support\DiditWebhookSignature;
use App\Support\KycRequiredDocuments;
use Illuminate\Support\Facades\Schema;

class DiditKycSync
{
    public function __construct(
        protected PlatformEmailNotifications $platformEmailNotifications,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function apply(array $payload, string $trust): void
    {
        $status = trim((string) ($payload['status'] ?? ''));
        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        if ($status === '' || $sessionId === '') {
            return;
        }

        $type = (string) ($payload['webhook_type'] ?? 'status.updated');
        if (! in_array($type, ['status.updated', 'data.updated'], true)) {
            return;
        }

        $user = $this->findUser($payload, $sessionId, $trust);
        if ($user === null) {
            return;
        }

        if ((string) ($user->kyc_provider ?? '') === DiditKycSettings::MANUAL) {
            return;
        }

        $storedSession = (string) ($user->kyc_external_session_id ?? '');
        if ($storedSession !== '' && ! hash_equals($storedSession, $sessionId)) {
            return;
        }

        if ($user->kyc_status === User::KYC_BLOCKED) {
            return;
        }

        match ($status) {
            'Approved' => $this->approve($user, $sessionId),
            'Declined' => $this->reject($user, $sessionId, 'A verificação de identidade não foi aprovada. Você pode tentar de novo.'),
            'Kyc Expired' => $this->reject($user, $sessionId, 'O documento verificado venceu. Faça a verificação de novo.'),
            'In Review' => $this->markInReview($user, $sessionId),
            default => $this->rememberSession($user, $sessionId),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findUser(array $payload, string $sessionId, string $trust): ?User
    {
        if (! Schema::hasColumn('users', 'kyc_external_session_id')) {
            return null;
        }

        $bySession = User::query()
            ->where('kyc_external_session_id', $sessionId)
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->first();
        if ($bySession !== null) {
            return $bySession;
        }

        if ($trust !== DiditWebhookSignature::FULL) {
            return null;
        }

        $vendor = trim((string) ($payload['vendor_data'] ?? ''));
        if ($vendor === '' || ! ctype_digit($vendor)) {
            return null;
        }

        $user = User::query()->find((int) $vendor);

        return $user !== null && $user->role === User::ROLE_INFOPRODUTOR ? $user : null;
    }

    private function approve(User $user, string $sessionId): void
    {
        if ($user->kyc_status === User::KYC_APPROVED && ! KycRequiredDocuments::mayUpgradeLegacyDocuments($user)) {
            $this->rememberSession($user, $sessionId);

            return;
        }

        if (Schema::hasColumn('users', 'kyc_identity_verified_at')
            && KycRequiredDocuments::diditLeavesCompanyReview($user)) {
            $this->releaseForCompanyDocuments($user, $sessionId);

            return;
        }

        $attrs = [
            'kyc_status' => User::KYC_APPROVED,
            'kyc_provider' => DiditKycSettings::PROVIDER,
            'kyc_external_session_id' => $sessionId,
            'kyc_rejection_reason' => null,
            'kyc_reviewed_at' => now(),
            'kyc_reviewed_by' => null,
        ];
        if (in_array((string) ($user->account_status ?? ''), ['', 'pending', 'rejected'], true)) {
            $attrs['account_status'] = 'approved';
        }
        if (Schema::hasColumn('users', 'kyc_requirements_version')) {
            $attrs['kyc_requirements_version'] = KycRequiredDocuments::VERSION_CURRENT;
        }
        if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
            $attrs['kyc_needs_document_review'] = false;
        }

        $user->forceFill($attrs)->save();
        $fresh = $user->fresh();

        PlatformAuditService::log('platform.kyc.didit_approved', [
            'merchant_id' => $fresh->id,
            'session_id' => $sessionId,
        ]);
        $this->platformEmailNotifications->kycApproved($fresh);
        KycApproved::dispatch($fresh);
    }

    private function reject(User $user, string $sessionId, string $reason): void
    {
        if ($user->kyc_status === User::KYC_REJECTED && (string) $user->kyc_rejection_reason === $reason) {
            $this->rememberSession($user, $sessionId);

            return;
        }

        $attrs = [
            'kyc_status' => User::KYC_REJECTED,
            'kyc_provider' => DiditKycSettings::PROVIDER,
            'kyc_external_session_id' => $sessionId,
            'kyc_rejection_reason' => $reason,
            'kyc_reviewed_at' => now(),
            'kyc_reviewed_by' => null,
        ];
        if (Schema::hasColumn('users', 'kyc_identity_verified_at')) {
            $attrs['kyc_identity_verified_at'] = null;
        }

        $user->forceFill($attrs)->save();
        $fresh = $user->fresh();

        PlatformAuditService::log('platform.kyc.didit_rejected', [
            'merchant_id' => $fresh->id,
            'session_id' => $sessionId,
        ]);
        $this->platformEmailNotifications->kycRejected($fresh, $reason);
        KycRejected::dispatch($fresh, $reason);
    }

    private function markInReview(User $user, string $sessionId): void
    {
        if ($user->kyc_status === User::KYC_APPROVED || $user->kyc_identity_verified_at !== null) {
            return;
        }

        $wasPending = $user->kyc_status === User::KYC_PENDING_REVIEW;
        $attrs = [
            'kyc_provider' => DiditKycSettings::PROVIDER,
            'kyc_external_session_id' => $sessionId,
        ];
        if (! $wasPending) {
            $attrs['kyc_status'] = User::KYC_PENDING_REVIEW;
            $attrs['kyc_rejection_reason'] = null;
            $attrs['kyc_reviewed_at'] = null;
            $attrs['kyc_reviewed_by'] = null;
            if (Schema::hasColumn('users', 'kyc_requirements_version')) {
                $attrs['kyc_requirements_version'] = KycRequiredDocuments::VERSION_CURRENT;
            }
        }

        $user->forceFill($attrs)->save();
        if (! $wasPending) {
            $this->platformEmailNotifications->kycSubmitted($user->fresh());
        }
    }

    private function releaseForCompanyDocuments(User $user, string $sessionId): void
    {
        if ($user->kyc_identity_verified_at !== null && $user->kyc_status === User::KYC_PENDING_REVIEW) {
            $this->rememberSession($user, $sessionId);

            return;
        }

        $attrs = [
            'kyc_provider' => DiditKycSettings::PROVIDER,
            'kyc_external_session_id' => $sessionId,
            'kyc_identity_verified_at' => $user->kyc_identity_verified_at ?? now(),
            'kyc_rejection_reason' => null,
            'kyc_reviewed_at' => null,
            'kyc_reviewed_by' => null,
        ];
        if ($user->kyc_status !== User::KYC_NOT_SUBMITTED) {
            $attrs['kyc_status'] = User::KYC_NOT_SUBMITTED;
        }

        $user->forceFill($attrs)->save();
    }

    private function rememberSession(User $user, string $sessionId): void
    {
        if ((string) ($user->kyc_external_session_id ?? '') === $sessionId
            && (string) ($user->kyc_provider ?? '') === DiditKycSettings::PROVIDER) {
            return;
        }

        $user->forceFill([
            'kyc_provider' => DiditKycSettings::PROVIDER,
            'kyc_external_session_id' => $sessionId,
        ])->save();
    }
}
