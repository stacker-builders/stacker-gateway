<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetMail;
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

#[UsePolicy(UserPolicy::class)]
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public const KYC_NOT_SUBMITTED = 'not_submitted';

    public const KYC_PENDING_REVIEW = 'pending_review';

    public const KYC_APPROVED = 'approved';

    public const KYC_REJECTED = 'rejected';

    /** Recusado no KYC com CPF/CNPJ impedidos de novo cadastro. */
    public const KYC_BLOCKED = 'blocked';

    /** @var list<string> */
    public const MONTHLY_REVENUE_RANGES = [
        'up_to_10k',
        '10k_50k',
        '50k_100k',
        '100k_500k',
        'over_500k',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone',
        'username',
        'avatar',
        'password',
        'role',
        'tenant_id',
        'referral_code',
        'referred_by_user_id',
        'referred_at',
        'referral_commission_percent',
        'team_role_id',
        'person_type',
        'document',
        'account_status',
        'merchant_fees',
        'merchant_settlement_overrides',
        'merchant_gateway_order',
        'cajupay_account_id',
        'payout_settings',
        'birth_date',
        'company_name',
        'trade_name',
        'legal_representative_cpf',
        'cnpj_lookup',
        'pj_conversion',
        'address_zip',
        'address_street',
        'address_number',
        'address_complement',
        'address_neighborhood',
        'address_city',
        'address_state',
        'monthly_revenue_range',
        'kyc_status',
        'kyc_rejection_reason',
        'kyc_reviewed_at',
        'kyc_reviewed_by',
        'kyc_needs_document_review',
        'identity_document_type',
        'company_legal_nature',
        'kyc_requirements_version',
        'seller_onboarded_at',
        'privacy_policy_accepted_at',
        'terms_accepted_at',
        'legal_consent_version',
    ];

    /** @deprecated Migração: antigo admin virou infoprodutor; manter só por compatibilidade de dados legados */
    public const ROLE_ADMIN = 'admin';

    public const ROLE_PLATFORM_ADMIN = 'platform_admin';

    public const ROLE_INFOPRODUTOR = 'infoprodutor';

    /** Comprador (área do cliente / compras) */
    public const ROLE_CLIENTE = 'cliente';

    /** @deprecated Use ROLE_CLIENTE */
    public const ROLE_ALUNO = 'aluno';

    public const ROLE_TEAM = 'team';

    /**
     * @return list<string>
     */
    public static function buyerRoleValues(): array
    {
        return [self::ROLE_CLIENTE, self::ROLE_ALUNO];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === self::ROLE_PLATFORM_ADMIN;
    }

    public function isInfoprodutor(): bool
    {
        return $this->role === self::ROLE_INFOPRODUTOR;
    }

    public function isCliente(): bool
    {
        return $this->role === self::ROLE_CLIENTE || $this->role === self::ROLE_ALUNO;
    }

    /** @deprecated Use isCliente() */
    public function isAluno(): bool
    {
        return $this->isCliente();
    }

    public function isTeam(): bool
    {
        return $this->role === self::ROLE_TEAM;
    }

    /**
     * Painel do vendedor (checkout, produtos, vendas).
     * Não inclui platform_admin nem admin global (estes usam /plataforma).
     */
    public function canAccessSellerPanel(): bool
    {
        if ($this->isPlatformAdmin()) {
            return false;
        }
        if ($this->isAdmin()) {
            return false;
        }

        return $this->isInfoprodutor() || $this->isTeam();
    }

    /**
     * Compat: painel clássico Getfy (mesmo que vendedor; operador usa /plataforma).
     */
    public function canAccessPanel(): bool
    {
        return $this->canAccessSellerPanel();
    }

    /**
     * Painel do cliente: compras e produtos adquiridos (ex.: /painel-cliente).
     */
    public function canAccessCustomerPanel(): bool
    {
        if ($this->isPlatformAdmin() || $this->isAdmin()) {
            return false;
        }

        return $this->isCliente() || $this->isInfoprodutor() || $this->isTeam();
    }

    /**
     * Pode alternar para o painel do infoprodutor (cadastro completo ou já é vendedor/equipe).
     */
    public function canSwitchToSellerPanel(): bool
    {
        if ($this->isPlatformAdmin() || $this->isAdmin()) {
            return false;
        }

        return $this->isInfoprodutor() || $this->isTeam();
    }

    /**
     * Precisa completar cadastro de infoprodutor antes de aceder ao painel do vendedor.
     */
    public function needsOnboardingAsSeller(): bool
    {
        if ($this->isPlatformAdmin() || $this->isAdmin()) {
            return false;
        }

        return $this->isCliente() && $this->seller_onboarded_at === null;
    }

    /**
     * URL inicial após login ou ao acessar a raiz autenticado.
     */
    public function defaultAuthenticatedHomeUrl(): string
    {
        if ($this->canAccessPlatformPanel()) {
            return route('plataforma.dashboard');
        }
        if ($this->canAccessSellerPanel()) {
            return '/dashboard';
        }
        if ($this->canAccessCustomerPanel()) {
            return '/painel-cliente';
        }

        return '/painel-cliente';
    }

    /**
     * Destino quando o usuário tenta acessar rotas do painel do vendedor sem permissão.
     */
    public function sellerPanelFallbackUrl(): string
    {
        if ($this->canAccessCustomerPanel()) {
            return '/painel-cliente';
        }

        return route('login');
    }

    /**
     * Painel operador da plataforma (/plataforma): platform_admin sem tenant, ou papel admin global.
     */
    public function canAccessPlatformPanel(): bool
    {
        if ($this->role === self::ROLE_PLATFORM_ADMIN) {
            return $this->tenant_id === null;
        }

        return $this->role === self::ROLE_ADMIN;
    }

    public function teamRole(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TeamRole::class, 'team_role_id');
    }

    public function products(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_user')->withTimestamps();
    }

    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function savedPaymentMethods(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SavedPaymentMethod::class);
    }

    public function kycDocuments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    public function kycReviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'kyc_reviewed_by');
    }

    public function referredBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function accountManager(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AccountManager::class, 'account_manager_id');
    }

    public function referrals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    public function referralWallet(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ReferralWallet::class);
    }

    /**
     * Usuário dono do tenant para checagem de KYC (infoprodutor ou dono quando equipe).
     */
    public function kycSubjectUser(): self
    {
        if ($this->isTeam() && $this->tenant_id) {
            $owner = static::query()->find($this->tenant_id);

            return $owner instanceof self ? $owner : $this;
        }

        return $this;
    }

    public function hasApprovedKyc(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_status')) {
            return true;
        }

        $status = $this->kycSubjectUser()->kyc_status;

        return $status === self::KYC_APPROVED;
    }

    public function isAwaitingKycReview(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_status')) {
            return false;
        }

        return $this->kycSubjectUser()->kyc_status === self::KYC_PENDING_REVIEW;
    }

    public function mustCompleteKycOnboarding(): bool
    {
        if (! $this->canAccessSellerPanel()) {
            return false;
        }

        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_status')) {
            return false;
        }

        $status = $this->kycSubjectUser()->kyc_status;

        return in_array($status, [self::KYC_NOT_SUBMITTED, self::KYC_REJECTED], true);
    }

    public function isMerchantOperationallyApproved(): bool
    {
        if (! $this->canAccessSellerPanel()) {
            return false;
        }

        $subject = $this->kycSubjectUser();

        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_status')
            && $subject->kyc_status !== self::KYC_APPROVED) {
            return false;
        }

        $accountStatus = (string) ($subject->account_status ?? 'approved');

        return $accountStatus === 'approved';
    }

    /**
     * Documentos KYC já enviados (em análise ou aprovados).
     */
    public function hasSubmittedKyc(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_status')) {
            return true;
        }

        $status = $this->kycSubjectUser()->kyc_status;

        return in_array($status, [self::KYC_PENDING_REVIEW, self::KYC_APPROVED], true);
    }

    /**
     * Infoprodutor/equipe precisa enviar documentos antes de usar o painel do vendedor.
     *
     * @deprecated Prefer mustCompleteKycOnboarding() + isMerchantOperationallyApproved()
     */
    public function mustSubmitKycBeforeSellerPanel(): bool
    {
        if (! $this->canAccessSellerPanel()) {
            return false;
        }

        return ! $this->hasSubmittedKyc();
    }

    /**
     * Painel operacional bloqueado até KYC/conta aprovados (exceto rotas de onboarding).
     */
    public function mustStayOnKycOnboardingRoutes(): bool
    {
        if (! $this->canAccessSellerPanel()) {
            return false;
        }

        if ($this->sellerAccountAccessBlocked()) {
            return false;
        }

        return ! $this->isMerchantOperationallyApproved();
    }

    public function sellerPanelRestrictedMessage(): string
    {
        if ($this->mustCompleteKycOnboarding()) {
            return 'Envie seus documentos de verificação de identidade (KYC) para acessar o painel do infoprodutor.';
        }

        if ($this->isAwaitingKycReview()) {
            return 'Documentos em análise. Aguarde a aprovação da plataforma.';
        }

        $subject = $this->kycSubjectUser();
        if ((string) ($subject->account_status ?? 'approved') === 'pending') {
            return 'Sua conta ainda não foi aprovada pela plataforma.';
        }

        return 'Complete a verificação de identidade (KYC) para acessar o painel do infoprodutor.';
    }

    /**
     * Conta do negócio suspensa/bloqueada/rejeitada — equipe segue o titular (kycSubjectUser).
     */
    public function sellerAccountAccessBlocked(): bool
    {
        if (! $this->canAccessSellerPanel()) {
            return false;
        }

        $subject = $this->kycSubjectUser();
        $status = (string) ($subject->account_status ?? 'approved');

        return in_array($status, ['suspended', 'blocked', 'rejected'], true);
    }

    public function getEmailForVerification(): string
    {
        return (string) $this->email;
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $params = [
            'token' => $token,
            'email' => $this->getEmailForPasswordReset(),
        ];
        $redirect = app()->bound('password_reset_redirect') ? app('password_reset_redirect') : null;
        if ($redirect !== null) {
            $params['redirect'] = $redirect;
        }
        \App\Support\PublicAppUrl::forceRoot();
        $url = url(route('password.reset', $params, false));
        $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $tenantId = $this->tenant_id;

        Mail::mailer('smtp')->to($this->getEmailForPasswordReset())->send(
            new PasswordResetMail($url, $expire, is_int($tenantId) ? $tenantId : null)
        );
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = $value === null ? null : strtolower(trim($value));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'merchant_fees' => 'array',
            'merchant_settlement_overrides' => 'array',
            'merchant_gateway_order' => 'array',
            'payout_settings' => 'array',
            'cnpj_lookup' => 'array',
            'pj_conversion' => 'array',
            'birth_date' => 'date',
            'kyc_reviewed_at' => 'datetime',
            'kyc_needs_document_review' => 'boolean',
            'kyc_requirements_version' => 'integer',
            'seller_onboarded_at' => 'datetime',
            'referred_at' => 'datetime',
            'referral_commission_percent' => 'decimal:4',
            'privacy_policy_accepted_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }
}
