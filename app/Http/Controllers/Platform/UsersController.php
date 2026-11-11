<?php

namespace App\Http\Controllers\Platform;

use App\Events\SellerRejected;
use App\Http\Controllers\Concerns\BuildsMerchantWalletProps;
use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Concerns\ProvidesPlatformGatewayProps;
use App\Http\Controllers\Controller;
use App\Http\Controllers\InfoprodutorRegistrationController;
use App\Services\AdminSettlementAnticipateService;
use App\Services\AdminWalletAdjustmentService;
use App\Gateways\GatewayRegistry;
use App\Models\AccountManager;
use App\Models\CajuPayAccount;
use App\Models\MerchantAdminNote;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AccountManagerAssignmentService;
use App\Services\EffectiveMerchantFees;
use App\Services\MinimumChargeService;
use App\Services\ApiPixAccess;
use App\Services\SellerIntegrationVisibility;
use App\Services\Med\MedPolicyService;
use App\Services\Platform\MerchantRevenueBreakdownService;
use App\Services\Platform\PlatformTotpService;
use App\Services\PlatformAdminDeletionService;
use App\Services\PlatformAuditService;
use App\Services\SalesAchievementsService;
use App\Services\Withdrawal\WithdrawalPolicyService;
use App\Support\BrazilianDocumentDigits;
use App\Support\BrazilianDocuments;
use App\Support\HtmlSanitizer;
use App\Support\MerchantAdminProductsListing;
use App\Support\MerchantAdminWalletMovementsListing;
use App\Support\MerchantProfileSnapshot;
use App\Support\NormalizedEmail;
use App\Support\PlatformConfigContext;
use App\Support\PercentDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    use BuildsMerchantWalletProps;
    use ProvidesPlatformGatewayProps;
    use RequiresPlatformStepUp;

    /** @var list<string> */
    private const ACCOUNT_STATUS_FILTERS = ['approved', 'pending', 'rejected', 'suspended', 'blocked'];

    /** @var list<string> */
    private const ALLOWED_SORT_BY = ['created_at', 'total_sales', 'balance'];

    /** @var list<int> */
    private const ALLOWED_PER_PAGE = [25, 50, 100];

    public function __construct(
        protected SalesAchievementsService $salesAchievements,
        protected MinimumChargeService $minimumChargeService,
        protected MerchantRevenueBreakdownService $merchantRevenueBreakdown,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $legacyEditUserId = $request->query('edit');
        if (is_numeric($legacyEditUserId)) {
            return redirect()->route('plataforma.usuarios.edit', ['user' => (int) $legacyEditUserId]);
        }

        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';
        $search = $search !== '' ? $search : null;

        $statusFilter = $request->query('status');
        $statusFilter = is_string($statusFilter) ? trim($statusFilter) : '';
        $statusFilter = in_array($statusFilter, self::ACCOUNT_STATUS_FILTERS, true) ? $statusFilter : null;

        $sortByRaw = $request->query('sort_by');
        $sortBy = is_string($sortByRaw) && in_array($sortByRaw, self::ALLOWED_SORT_BY, true)
            ? $sortByRaw
            : null;

        $sortDirectionRaw = $request->query('sort_direction');
        $sortDirection = is_string($sortDirectionRaw) && in_array(strtolower($sortDirectionRaw), ['asc', 'desc'], true)
            ? strtolower($sortDirectionRaw)
            : 'asc';

        $perPageRaw = (int) $request->query('per_page', 25);
        $perPage = in_array($perPageRaw, self::ALLOWED_PER_PAGE, true) ? $perPageRaw : 25;

        $usersQuery = User::query()
            ->select('users.*')
            ->where('users.role', User::ROLE_INFOPRODUTOR);

        if ($statusFilter !== null) {
            if ($statusFilter === 'approved') {
                $usersQuery->where(function ($q) {
                    $q->where('users.account_status', 'approved')
                        ->orWhereNull('users.account_status');
                });
            } else {
                $usersQuery->where('users.account_status', $statusFilter);
            }
        }

        if ($search !== null) {
            $this->applyInfoprodutorSearch($usersQuery, $search);
        }

        $dirSql = $sortDirection === 'desc' ? 'desc' : 'asc';

        if ($sortBy === 'created_at') {
            $usersQuery->orderBy('users.created_at', $dirSql)->orderBy('users.name');
        } elseif ($sortBy === 'balance' && Schema::hasTable('tenant_wallets')) {
            $usersQuery->leftJoin('tenant_wallets', function ($join) {
                $join->whereRaw('tenant_wallets.tenant_id = COALESCE(users.tenant_id, users.id)');
            });
            $usersQuery->orderByRaw('COALESCE(tenant_wallets.available_balance, 0) '.$dirSql)
                ->orderBy('users.name');
        } elseif ($sortBy === 'total_sales' && Schema::hasTable('orders')) {
            $salesSub = $this->salesAchievements->validSalesTotalsQuery();
            $usersQuery->leftJoinSub($salesSub, 'merchant_sales', function ($join) {
                $join->whereRaw('merchant_sales.tenant_id = COALESCE(users.tenant_id, users.id)');
            });
            $usersQuery->orderByRaw('COALESCE(merchant_sales.total, 0) '.$dirSql)
                ->orderBy('users.name');
        } else {
            // Sem sort_by válido (ou joins indisponíveis): ordem alfabética atual.
            $usersQuery->orderBy('users.name');
            $sortBy = null;
            $sortDirection = 'asc';
        }

        $paginator = $usersQuery
            ->paginate($perPage)
            ->withQueryString();

        $users = $paginator->getCollection();
        $tenantIds = $users->map(fn (User $u) => (int) ($u->tenant_id ?? $u->id))->filter(fn (int $id) => $id > 0)->unique()->values();
        $userIds = $users->pluck('id');

        $wallets = collect();
        if (Schema::hasTable('tenant_wallets') && $tenantIds->isNotEmpty()) {
            $wallets = TenantWallet::query()
                ->whereIn('tenant_id', $tenantIds)
                ->get()
                ->keyBy('tenant_id');
        }

        $salesTotals = $this->salesAchievements->getValidSalesTotalsForTenants($tenantIds);

        $adminNotesCounts = collect();
        if (Schema::hasTable('merchant_admin_notes') && $userIds->isNotEmpty()) {
            $adminNotesCounts = MerchantAdminNote::query()
                ->whereIn('merchant_user_id', $userIds)
                ->selectRaw('merchant_user_id, count(*) as aggregate')
                ->groupBy('merchant_user_id')
                ->pluck('aggregate', 'merchant_user_id');
        }

        $medTotals = collect();
        if (Schema::hasTable('wallet_transactions') && $tenantIds->isNotEmpty()) {
            $medTotals = WalletTransaction::query()
                ->whereIn('tenant_id', $tenantIds)
                ->where('type', WalletTransaction::TYPE_MED_HOLD)
                ->selectRaw('tenant_id, SUM(amount_net) as aggregate')
                ->groupBy('tenant_id')
                ->pluck('aggregate', 'tenant_id');
        }

        $rows = $users->map(function (User $u) use ($wallets, $salesTotals, $adminNotesCounts, $medTotals) {
            $tid = $u->tenant_id ?? $u->id;
            $tidInt = (int) $tid;
            $w = $wallets->get($tid);
            $medTotal = $tidInt > 0 ? round((float) ($medTotals[$tidInt] ?? 0), 2) : 0.0;

            $walletAdmin = null;
            if ($w && Schema::hasColumn('tenant_wallets', 'admin_withdrawal_blocked')) {
                $walletAdmin = [
                    'admin_withdrawal_blocked' => (bool) $w->admin_withdrawal_blocked,
                    'admin_blocked_amount' => $w->admin_blocked_amount !== null ? (float) $w->admin_blocked_amount : null,
                    'admin_block_until' => $w->admin_block_until?->toIso8601String(),
                    'admin_block_note' => $w->admin_block_note,
                ];
            }

            $chargeLimits = $tidInt > 0 ? $this->chargeLimitsPayloadForTenant($tidInt) : $this->emptyChargeLimitsPayload();

            return [
                'id' => $u->id,
                'name' => $u->name,
                'trade_name' => $u->trade_name,
                'email' => $u->email,
                'phone' => Schema::hasColumn('users', 'phone') ? ($u->phone ?? null) : null,
                'avatar_url' => $u->avatar ? app(\App\Services\StorageService::class)->url($u->avatar) : null,
                'tenant_id' => $u->tenant_id,
                'person_type' => $u->person_type,
                'document_type' => $this->documentTypeLabel($u),
                'document' => $u->document,
                'account_status' => $u->account_status ?? 'approved',
                'merchant_fees' => $u->merchant_fees ?? [],
                'merchant_settlement_overrides' => $u->merchant_settlement_overrides ?? [],
                'merchant_gateway_order' => $u->merchant_gateway_order ?? [],
                'cajupay_account_id' => $u->cajupay_account_id,
                'api_pix_mode' => $tidInt > 0 ? ApiPixAccess::tenantMode($tidInt) : ApiPixAccess::MODE_INHERIT,
                'api_pix_enabled_effective' => $tidInt > 0 ? ApiPixAccess::effectiveForTenant($tidInt) : ApiPixAccess::globalEnabled(),
                'med_zero_enabled' => $tidInt > 0 ? app(MedPolicyService::class)->medZeroForTenant($tidInt) : false,
                'charge_limits' => $chargeLimits,
                'saldo_disponivel' => $w ? (float) $w->available_balance : 0.0,
                'saldo_pix' => $w ? (float) $w->pending_balance : 0.0,
                'vendas_totais' => $salesTotals[$tidInt] ?? 0.0,
                'med_total' => $medTotal,
                'wallet_admin' => $walletAdmin,
                'admin_notes_count' => (int) ($adminNotesCounts[$u->id] ?? 0),
                'totp_enabled' => PlatformTotpService::isEnabledFor($u),
                'referral_commission_percent' => Schema::hasColumn('users', 'referral_commission_percent')
                    ? ($u->referral_commission_percent !== null ? (float) $u->referral_commission_percent : null)
                    : null,
                'created_at' => $u->created_at?->toIso8601String(),
            ];
        });

        $paginator->setCollection($rows);

        $settingsTenantId = PlatformConfigContext::settingsTenantId();

        return Inertia::render('Platform/Users/Index', [
            'users' => $paginator,
            'q' => $search,
            'status' => $statusFilter,
            'sort_by' => $sortBy,
            'sort_direction' => $sortBy !== null ? $sortDirection : null,
            'per_page' => $perPage,
            'status_options' => [
                ['value' => 'approved', 'label' => 'Aprovado'],
                ['value' => 'pending', 'label' => 'Pendente'],
                ['value' => 'rejected', 'label' => 'Rejeitado'],
                ['value' => 'suspended', 'label' => 'Suspenso'],
                ['value' => 'blocked', 'label' => 'Bloqueado'],
            ],
            'gateways' => $this->buildGatewaysListForMerchantPicker(),
            'platform_gateway_order' => $this->buildGatewayOrderForSettings($settingsTenantId),
            'platform_merchant_fees' => $this->formatEffectiveFeesForFrontend(EffectiveMerchantFees::platformDefaults()),
            'platform_referral_commission_percent' => \App\Support\ReferralProgramSettings::commissionPercent(),
            'platform_charge_limits' => [
                'api_pix_minimum_charge_brl' => $this->minimumChargeService->apiPixMinimumBrl(),
                'platform_minimum_charge_brl' => $this->minimumChargeService->platformMinimumBrl(),
            ],
            'platform_api_pix_enabled' => ApiPixAccess::globalEnabled(),
            'cajupay_accounts' => CajuPayAccount::query()
                ->enabled()
                ->connected()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default'])
                ->map(fn (CajuPayAccount $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'is_default' => $a->is_default,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function edit(User $user): Response
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $tenantId = (int) ($user->tenant_id ?? $user->id);
        $wallet = null;
        if (Schema::hasTable('tenant_wallets') && $tenantId > 0) {
            $wallet = TenantWallet::query()->where('tenant_id', $tenantId)->first();
        }

        $walletAdmin = null;
        if ($wallet && Schema::hasColumn('tenant_wallets', 'admin_withdrawal_blocked')) {
            $walletAdmin = [
                'admin_withdrawal_blocked' => (bool) $wallet->admin_withdrawal_blocked,
                'admin_blocked_amount' => $wallet->admin_blocked_amount !== null ? (float) $wallet->admin_blocked_amount : null,
                'admin_block_until' => $wallet->admin_block_until?->toIso8601String(),
                'admin_block_note' => $wallet->admin_block_note,
            ];
        }

        $salesTotals = $this->salesAchievements->getValidSalesTotalsForTenants(collect([$tenantId]));
        $medTotal = 0.0;
        if (Schema::hasTable('wallet_transactions') && $tenantId > 0) {
            $medTotal = round((float) WalletTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('type', WalletTransaction::TYPE_MED_HOLD)
                ->sum('amount_net'), 2);
        }

        $adminNotesCount = 0;
        if (Schema::hasTable('merchant_admin_notes')) {
            $adminNotesCount = MerchantAdminNote::query()->where('merchant_user_id', $user->id)->count();
        }

        $merchant = [
            'id' => $user->id,
            'name' => $user->name,
            'trade_name' => $user->trade_name,
            'email' => $user->email,
            'phone' => Schema::hasColumn('users', 'phone') ? ($user->phone ?? null) : null,
            'avatar_url' => $user->avatar ? app(\App\Services\StorageService::class)->url($user->avatar) : null,
            'tenant_id' => $user->tenant_id,
            'person_type' => $user->person_type,
            'document' => $user->document,
            'account_status' => $user->account_status ?? 'approved',
            'profile' => $this->merchantProfileFormPayload($user),
            'merchant_fees' => $user->merchant_fees ?? [],
            'merchant_settlement_overrides' => $user->merchant_settlement_overrides ?? [],
            'merchant_gateway_order' => $user->merchant_gateway_order ?? [],
            'cajupay_account_id' => $user->cajupay_account_id,
            'api_pix_mode' => $tenantId > 0 ? ApiPixAccess::tenantMode($tenantId) : ApiPixAccess::MODE_INHERIT,
            'integration_modes' => $tenantId > 0 ? SellerIntegrationVisibility::tenantModes($tenantId) : SellerIntegrationVisibility::tenantModes(null),
            'med_zero_enabled' => $tenantId > 0 ? app(MedPolicyService::class)->medZeroForTenant($tenantId) : false,
            'charge_limits' => $tenantId > 0 ? $this->chargeLimitsPayloadForTenant($tenantId) : $this->emptyChargeLimitsPayload(),
            'saldo_disponivel' => $wallet ? (float) $wallet->available_balance : 0.0,
            'saldo_pix' => $wallet ? (float) $wallet->pending_balance : 0.0,
            'vendas_totais' => $salesTotals[$tenantId] ?? 0.0,
            'med_total' => $medTotal,
            'wallet_admin' => $walletAdmin,
            'admin_notes_count' => $adminNotesCount,
            'totp_enabled' => PlatformTotpService::isEnabledFor($user),
            'referral_commission_percent' => Schema::hasColumn('users', 'referral_commission_percent')
                ? ($user->referral_commission_percent !== null ? (float) $user->referral_commission_percent : null)
                : null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];

        $settingsTenantId = PlatformConfigContext::settingsTenantId();

        return Inertia::render('Platform/Users/Edit', [
            'merchant' => $merchant,
            'gateways' => $this->buildGatewaysListForMerchantPicker(),
            'platform_gateway_order' => $this->buildGatewayOrderForSettings($settingsTenantId),
            'platform_merchant_fees' => $this->formatEffectiveFeesForFrontend(EffectiveMerchantFees::platformDefaults()),
            'platform_referral_commission_percent' => \App\Support\ReferralProgramSettings::commissionPercent(),
            'platform_charge_limits' => [
                'api_pix_minimum_charge_brl' => $this->minimumChargeService->apiPixMinimumBrl(),
                'platform_minimum_charge_brl' => $this->minimumChargeService->platformMinimumBrl(),
            ],
            'platform_api_pix_enabled' => ApiPixAccess::globalEnabled(),
            'platform_integrations_enabled' => SellerIntegrationVisibility::globalMap(),
            'platform_integrations' => SellerIntegrationVisibility::catalog(),
            'cajupay_accounts' => CajuPayAccount::query()
                ->enabled()
                ->connected()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default'])
                ->map(fn (CajuPayAccount $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'is_default' => $account->is_default,
                ])
                ->values()
                ->all(),
            'has_manual_approval_pin' => WithdrawalPolicyService::hasManualApprovalPin(),
            'revenue_ranges' => InfoprodutorRegistrationController::revenueRangeOptions(),
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $tenantId = $this->tenantIdForUser($user);

        $tab = (string) $request->query('tab', 'overview');
        if (! in_array($tab, ['overview', 'products', 'wallet', 'achievements'], true)) {
            $tab = 'overview';
        }

        $adminNotes = [];
        if (Schema::hasTable('merchant_admin_notes')) {
            $adminNotes = MerchantAdminNote::query()
                ->where('merchant_user_id', $user->id)
                ->with('author:id,name')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn (MerchantAdminNote $n) => [
                    'id' => $n->id,
                    'body' => $n->body,
                    'created_at' => $n->created_at?->toIso8601String(),
                    'author' => $n->author ? ['id' => $n->author->id, 'name' => $n->author->name] : null,
                ])
                ->values()
                ->all();
        }

        $revenueBreakdown = $this->merchantRevenueBreakdown->forTenant($tenantId);

        $referredBy = null;
        if (Schema::hasColumn('users', 'referred_by_user_id') && $user->referred_by_user_id) {
            $referrer = User::query()->find($user->referred_by_user_id);
            if ($referrer) {
                $referredBy = [
                    'id' => $referrer->id,
                    'name' => $referrer->name,
                    'email' => $referrer->email,
                    'referred_at' => $user->referred_at?->toIso8601String(),
                ];
            }
        }

        // Garante atributos frescos (ex.: phone) antes do snapshot de contato.
        $user->refresh();

        $productsTotal = MerchantAdminProductsListing::totalCount($tenantId);
        $productsPayload = [
            'products' => null,
            'filters' => null,
            'summary' => null,
            'approval_enabled' => Schema::hasTable('products') && Schema::hasColumn('products', 'approval_status'),
            'type_options' => [],
            'category_options' => [],
        ];
        if ($tab === 'products') {
            $productsPayload = MerchantAdminProductsListing::paginateForTenant($tenantId, $request);
        }

        $walletMovements = [
            'wallet_transactions' => MerchantAdminWalletMovementsListing::emptyPaginator($request),
            'filters' => [
                'wallet_type' => 'all',
                'wallet_q' => null,
                'wallet_date_from' => null,
                'wallet_date_to' => null,
                'wallet_per_page' => MerchantAdminWalletMovementsListing::DEFAULT_PER_PAGE,
                'wallet_sort' => 'id',
                'wallet_direction' => 'desc',
            ],
            'type_options' => WalletTransaction::typeLabels(),
        ];
        if ($tab === 'wallet') {
            $walletMovements = MerchantAdminWalletMovementsListing::paginateForTenant($tenantId, $request);
        }

        $achievementsPayload = [
            'progress' => null,
            'unlocks' => [],
        ];
        if ($tab === 'achievements') {
            $achievementsPayload = [
                'progress' => $this->salesAchievements->getProgressForTenant($tenantId),
                'unlocks' => $this->salesAchievements->unlocksPayloadForTenant($tenantId, forAdmin: true),
            ];
        }

        return Inertia::render('Platform/Users/Show', [
            'tab' => $tab,
            'merchant' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => Schema::hasColumn('users', 'phone') ? ($user->phone ?? null) : null,
                'document' => $user->document,
                'person_type' => $user->person_type,
                'account_status' => $user->account_status ?? 'approved',
                'kyc_status' => Schema::hasColumn('users', 'kyc_status') ? ($user->kyc_status ?? User::KYC_NOT_SUBMITTED) : null,
                'created_at' => $user->created_at?->toIso8601String(),
                'tenant_id' => $tenantId,
                'vendas_totais' => $revenueBreakdown['total']['gross'],
                'totp_enabled' => PlatformTotpService::isEnabledFor($user),
                'referral_code' => $user->referral_code,
                'referred_by' => $referredBy,
                'referral_commission_percent' => Schema::hasColumn('users', 'referral_commission_percent')
                    ? ($user->referral_commission_percent !== null ? (float) $user->referral_commission_percent : null)
                    : null,
                'referral_commission_percent_effective' => \App\Support\ReferralProgramSettings::commissionPercentForReferrer($user),
            ],
            'platform_referral_commission_percent' => \App\Support\ReferralProgramSettings::commissionPercent(),
            'revenue_breakdown' => $revenueBreakdown,
            'profile' => MerchantProfileSnapshot::forUser($user, maskDocuments: false),
            'wallet' => $this->walletPayloadForTenant($tenantId),
            'withdrawals' => $tab === 'overview' ? $this->withdrawalsPayloadForTenant($tenantId) : [],
            'wallet_transactions' => $walletMovements['wallet_transactions'],
            'wallet_filters' => $walletMovements['filters'],
            'wallet_transaction_type_labels' => $walletMovements['type_options'],
            'products_total' => $productsTotal,
            'products' => $productsPayload['products'],
            'products_filters' => $productsPayload['filters'],
            'products_summary' => $productsPayload['summary'],
            'products_approval_enabled' => $productsPayload['approval_enabled'],
            'products_type_options' => $productsPayload['type_options'],
            'products_category_options' => $productsPayload['category_options'] ?? [],
            'achievements_progress' => $achievementsPayload['progress'],
            'achievement_unlocks' => $achievementsPayload['unlocks'],
            'effective_merchant_fees' => $this->formatEffectiveFeesForFrontend(
                EffectiveMerchantFees::forTenant($tenantId),
                $user->merchant_fees
            ),
            'api_pix_mode' => ApiPixAccess::tenantMode($tenantId),
            'api_pix_enabled_effective' => ApiPixAccess::effectiveForTenant($tenantId),
            'charge_limits' => $this->chargeLimitsPayloadForTenant($tenantId),
            'platform_charge_limits' => [
                'api_pix_minimum_charge_brl' => $this->minimumChargeService->apiPixMinimumBrl(),
                'platform_minimum_charge_brl' => $this->minimumChargeService->platformMinimumBrl(),
            ],
            'platform_api_pix_enabled' => ApiPixAccess::globalEnabled(),
            'admin_notes' => $adminNotes,
            'account_manager' => $this->accountManagerPayload($user),
            'account_managers_options' => $this->activeAccountManagersOptions(),
            'has_manual_approval_pin' => WithdrawalPolicyService::hasManualApprovalPin(),
        ]);
    }

    public function assignAccountManager(Request $request, User $user, AccountManagerAssignmentService $assignments): RedirectResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $validated = $request->validate([
            'account_manager_id' => ['nullable', 'integer', 'exists:account_managers,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $manager = null;
        if (! empty($validated['account_manager_id'])) {
            $manager = AccountManager::query()->find((int) $validated['account_manager_id']);
        }

        try {
            $assignments->assign(
                $user,
                $manager,
                $request->user(),
                \App\Models\AccountManagerAssignment::SOURCE_MANUAL,
                $validated['reason'] ?? null,
                $request
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $manager ? 'Gerente de conta atualizado.' : 'Vínculo com gerente removido.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accountManagerPayload(User $user): ?array
    {
        if (! AccountManagerAssignmentService::ready() || ! $user->account_manager_id) {
            return null;
        }

        $manager = AccountManager::query()->find($user->account_manager_id);

        return app(AccountManagerAssignmentService::class)->adminPayload($manager);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function activeAccountManagersOptions(): array
    {
        if (! AccountManagerAssignmentService::ready()) {
            return [];
        }

        return AccountManager::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AccountManager $m) => ['id' => $m->id, 'name' => $m->name])
            ->values()
            ->all();
    }

    public function effectiveFees(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $tenantId = $this->tenantIdForUser($user);
        $draft = $request->input('merchant_fees');
        if (is_array($draft)) {
            $normalized = $this->normalizeMerchantFeesOverrides($draft);
            $fees = EffectiveMerchantFees::fromOverrides($normalized);
        } else {
            $fees = EffectiveMerchantFees::forTenant($tenantId);
        }

        return response()->json([
            'fees' => $this->formatEffectiveFeesForFrontend($fees),
        ]);
    }

    public function adjustBalance(Request $request, User $user, AdminWalletAdjustmentService $adjustmentService): RedirectResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $this->validatePlatformStepUp($request);

        $validated = $request->validate([
            'totp_code' => ['nullable', 'string', 'max:16'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'direction' => ['required', 'string', 'in:credit,debit'],
            'bucket' => ['nullable', 'string', 'in:pix,card,boleto'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'note.required' => 'Informe o motivo do ajuste.',
            'note.min' => 'O motivo deve ter pelo menos 3 caracteres.',
        ]);

        $amount = round((float) $validated['amount'], 2);
        $delta = $validated['direction'] === 'credit' ? $amount : -$amount;
        $bucket = $validated['bucket'] ?? 'pix';

        $adjustmentService->adjust(
            $this->tenantIdForUser($user),
            $bucket,
            $delta,
            $validated['note'],
            $request
        );

        $redirectTo = $request->input('redirect_to');
        if (is_string($redirectTo) && str_starts_with($redirectTo, '/plataforma/')) {
            return redirect($redirectTo)->with('success', 'Saldo ajustado com sucesso.');
        }

        return redirect()
            ->route('plataforma.usuarios.show', $user)
            ->with('success', 'Saldo ajustado com sucesso.');
    }

    public function anticipateWalletSale(
        Request $request,
        User $user,
        WalletTransaction $walletTransaction,
        AdminSettlementAnticipateService $anticipateService
    ): RedirectResponse {
        Gate::authorize('manageMerchantForPlatform', $user);

        $this->validateRequiredSecurityStepUp(
            $request,
            'Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro > Saques para antecipar saldo.'
        );

        $request->validate([
            'totp_code' => ['nullable', 'string', 'max:16'],
            'manual_approval_pin' => ['nullable', 'string', 'max:'.WithdrawalPolicyService::MANUAL_APPROVAL_PIN_MAX_LENGTH, 'regex:/^\d*$/'],
        ]);

        $tenantId = $this->tenantIdForUser($user);
        if ((int) $walletTransaction->tenant_id !== $tenantId) {
            abort(404);
        }

        $actor = $request->user();
        if ($actor === null) {
            abort(403);
        }

        try {
            $anticipateService->anticipate($walletTransaction, $actor);
        } catch (InvalidArgumentException $e) {
            return $this->redirectToWalletTab($request, $user)
                ->with('error', $e->getMessage());
        }

        PlatformAuditService::log('platform.wallet.settlement_anticipated', [
            'merchant_user_id' => $user->id,
            'tenant_id' => $tenantId,
            'wallet_transaction_id' => $walletTransaction->id,
            'order_id' => $walletTransaction->order_id,
            'amount_net' => (float) $walletTransaction->amount_net,
            'bucket' => $walletTransaction->bucket,
        ], $request);

        return $this->redirectToWalletTab($request, $user)
            ->with('success', 'Saldo antecipado com sucesso.');
    }

    public function resetTotp(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $this->validateRequiredSecurityStepUp(
            $request,
            'Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro > Saques para resetar o 2FA deste infoprodutor.'
        );

        $request->validate([
            'totp_code' => ['nullable', 'string', 'max:16'],
            'manual_approval_pin' => ['nullable', 'string', 'max:'.WithdrawalPolicyService::MANUAL_APPROVAL_PIN_MAX_LENGTH, 'regex:/^\d*$/'],
        ]);

        $user->refresh();
        if (! PlatformTotpService::isEnabledFor($user) && (! is_string($user->totp_secret) || $user->totp_secret === '')) {
            return redirect()
                ->route('plataforma.usuarios.edit', $user)
                ->with('error', 'Este infoprodutor não possui 2FA ativo.');
        }

        PlatformTotpService::forceDisable($user);

        PlatformAuditService::log('platform.merchant.totp_reset', [
            'merchant_user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
        ], $request);

        return redirect()
            ->route('plataforma.usuarios.edit', $user)
            ->with('success', '2FA desativado. O infoprodutor poderá entrar apenas com a senha e cadastrar um novo 2FA.');
    }

    private function redirectToWalletTab(Request $request, User $user): RedirectResponse
    {
        $query = ['tab' => 'wallet'];
        foreach (['wallet_type', 'wallet_q', 'wallet_date_from', 'wallet_date_to', 'wallet_per_page', 'wallet_sort', 'wallet_direction', 'wallet_page'] as $key) {
            $value = $request->input($key);
            if (is_numeric($value) || (is_string($value) && $value !== '')) {
                $query[$key] = $value;
            }
        }

        return redirect()->route('plataforma.usuarios.show', array_merge(['user' => $user], $query));
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Users/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => NormalizedEmail::normalize($request->input('email'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.unique' => 'Este e-mail já está em uso.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ]);

        $attrs = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_INFOPRODUTOR,
            'account_status' => 'pending',
        ];
        if (Schema::hasColumn('users', 'kyc_status')) {
            $attrs['kyc_status'] = User::KYC_NOT_SUBMITTED;
        }
        $user = User::create($attrs);

        $user->update(['tenant_id' => $user->id]);

        if (Schema::hasTable('tenant_wallets')) {
            TenantWallet::query()->firstOrCreate(
                ['tenant_id' => $user->tenant_id],
                ['available_balance' => 0, 'pending_balance' => 0, 'currency' => 'BRL']
            );
        }

        try {
            app(AccountManagerAssignmentService::class)->autoAssignIfConfigured($user->fresh(), $request);
        } catch (\Throwable) {
            // Não bloqueia o cadastro admin.
        }

        PlatformAuditService::log('platform.merchant.created', ['user_id' => $user->id], $request);

        return redirect()->route('plataforma.usuarios.index')->with('success', 'Infoprodutor cadastrado com sucesso.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        $id = $user->id;

        try {
            PlatformAdminDeletionService::deleteMerchant($user);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('plataforma.usuarios.index')
                ->with('error', $e->getMessage());
        }

        PlatformAuditService::log('platform.merchant.deleted', ['user_id' => $id], $request);

        return redirect()->route('plataforma.usuarios.index')->with('success', 'Usuário excluído.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'exists:users,id'],
            'confirm' => ['accepted'],
            'force' => ['nullable', 'boolean'],
            'totp_code' => ['nullable', 'string', 'max:16'],
        ], [
            'confirm.accepted' => 'Confirme a exclusão em massa.',
        ]);

        $this->validatePlatformStepUp($request);

        $force = $request->boolean('force');
        $deleted = [];
        $skipped = [];

        foreach ($validated['ids'] as $id) {
            $merchant = User::query()->find($id);
            if ($merchant === null) {
                continue;
            }

            try {
                Gate::authorize('manageMerchantForPlatform', $merchant);
                PlatformAdminDeletionService::deleteMerchant($merchant, $force);
                PlatformAuditService::log('platform.merchant.deleted', [
                    'user_id' => $merchant->id,
                    'bulk' => true,
                ], $request);
                $deleted[] = $merchant->id;
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                $skipped[] = ['id' => $id, 'reason' => 'Sem permissão para excluir esta conta.'];
            } catch (InvalidArgumentException $e) {
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        PlatformAuditService::log('platform.merchant.bulk_deleted', [
            'deleted_count' => count($deleted),
            'skipped_count' => count($skipped),
            'deleted_ids' => $deleted,
            'force' => $force,
        ], $request);

        $message = count($deleted) > 0
            ? count($deleted).' conta(s) excluída(s).'
            : 'Nenhuma conta foi excluída.';

        if (count($skipped) > 0) {
            $message .= ' '.count($skipped).' ignorada(s).';
        }

        return redirect()
            ->route('plataforma.usuarios.index', array_filter(['q' => $request->query('q')]))
            ->with('success', $message)
            ->with('bulk_delete_result', [
                'deleted' => $deleted,
                'skipped' => $skipped,
            ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageMerchantForPlatform', $user);

        if ($request->filled('address_zip')) {
            $request->merge([
                'address_zip' => BrazilianDocuments::digits((string) $request->input('address_zip')),
            ]);
        }
        if ($request->has('address_state')) {
            $request->merge([
                'address_state' => strtoupper(trim((string) $request->input('address_state'))),
            ]);
        }

        $prevAccountStatus = $user->account_status;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'birth_date' => ['nullable', 'date', 'before:'.now()->subYears(18)->toDateString()],
            'document' => ['nullable', 'string', 'max:20'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'legal_representative_cpf' => ['nullable', 'string', 'max:20'],
            'address_zip' => ['nullable', 'string', 'regex:/^(\d{8})?$/'],
            'address_street' => ['nullable', 'string', 'max:255'],
            'address_number' => ['nullable', 'string', 'max:32'],
            'address_complement' => ['nullable', 'string', 'max:120'],
            'address_neighborhood' => ['nullable', 'string', 'max:120'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_state' => ['nullable', 'string', 'regex:/^([A-Z]{2})?$/'],
            'monthly_revenue_range' => ['nullable', 'string', 'in:'.implode(',', User::MONTHLY_REVENUE_RANGES)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'account_status' => ['nullable', 'string', 'in:approved,pending,rejected,suspended,blocked'],
            'admin_withdrawal_blocked' => ['nullable', 'boolean'],
            'admin_blocked_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'admin_block_until' => ['nullable', 'date'],
            'admin_block_note' => ['nullable', 'string', 'max:500'],
            'api_pix_mode' => ['nullable', 'string', 'in:inherit,enabled,disabled'],
            'integration_modes' => ['nullable', 'array'],
            'integration_modes.*' => ['nullable', 'string', 'in:inherit,enabled,disabled'],
            'med_zero_enabled' => ['nullable', 'boolean'],
            'api_pix_minimum_charge_brl' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'platform_minimum_charge_brl' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'use_platform_api_pix_minimum' => ['nullable', 'boolean'],
            'use_platform_platform_minimum' => ['nullable', 'boolean'],
            'cajupay_account_id' => ['nullable', 'integer', 'exists:cajupay_accounts,id'],
            'referral_commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'password.confirmed' => 'A confirmação da senha não confere.',
            'birth_date.before' => 'É necessário ter pelo menos 18 anos.',
            'address_zip.regex' => 'Informe o CEP com 8 dígitos.',
            'address_state.regex' => 'Informe a UF com 2 letras.',
            'monthly_revenue_range.in' => 'Selecione uma faixa de faturamento válida.',
        ]);

        $incomingEmail = NormalizedEmail::normalize($validated['email'] ?? '');
        $currentEmail = NormalizedEmail::normalize($user->email);
        if ($incomingEmail !== '' && $incomingEmail !== $currentEmail) {
            throw ValidationException::withMessages([
                'email' => 'O e-mail não pode ser alterado depois do cadastro.',
            ]);
        }

        $user->name = $validated['name'];
        $this->applyMerchantProfileFields($user, $validated);
        if (Schema::hasColumn('users', 'phone') && array_key_exists('phone', $validated)) {
            $rawPhone = trim((string) ($validated['phone'] ?? ''));
            if ($rawPhone === '') {
                $user->phone = null;
            } else {
                $digits = \App\Support\WhatsappPhoneNormalizer::normalize($rawPhone);
                if ($digits === null) {
                    throw ValidationException::withMessages([
                        'phone' => 'Informe um WhatsApp válido com DDI (ex.: +351...) ou DDD brasileiro.',
                    ]);
                }
                $user->phone = $digits;
            }
        }
        if (! empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        if ($request->has('account_status') && isset($validated['account_status'])) {
            $newStatus = $validated['account_status'];
            if ($newStatus === 'approved'
                && Schema::hasColumn('users', 'kyc_status')
                && ($user->kyc_status ?? null) !== User::KYC_APPROVED) {
                throw ValidationException::withMessages([
                    'account_status' => 'Aprove a conta somente após verificação KYC em Verificações KYC.',
                ]);
            }
            $user->account_status = $newStatus;
        }

        $all = $request->all();
        if (array_key_exists('merchant_fees', $all)) {
            $user->merchant_fees = $this->normalizeMerchantFeesOverrides(
                is_array($request->input('merchant_fees')) ? $request->input('merchant_fees') : null
            );
        }

        if (Schema::hasColumn('users', 'referral_commission_percent')
            && array_key_exists('referral_commission_percent', $all)) {
            $raw = $request->input('referral_commission_percent');
            if ($raw === null || $raw === '') {
                $user->referral_commission_percent = null;
            } else {
                $user->referral_commission_percent = max(0, min(100, round((float) $raw, 4)));
            }
        }

        if (array_key_exists('merchant_settlement_overrides', $all)) {
            $user->merchant_settlement_overrides = $this->normalizeMerchantSettlementOverrides(
                is_array($request->input('merchant_settlement_overrides')) ? $request->input('merchant_settlement_overrides') : null
            );
        }

        if (array_key_exists('merchant_gateway_order', $all)) {
            $user->merchant_gateway_order = $this->normalizeMerchantGatewayOrder(
                is_array($request->input('merchant_gateway_order')) ? $request->input('merchant_gateway_order') : null
            );
        }

        if ($request->has('cajupay_account_id')) {
            $accountId = $validated['cajupay_account_id'] ?? null;
            if ($accountId !== null) {
                $account = CajuPayAccount::query()->whereKey($accountId)->enabled()->connected()->first();
                if ($account === null) {
                    throw ValidationException::withMessages([
                        'cajupay_account_id' => 'Conta CajuPay inválida ou indisponível.',
                    ]);
                }
            }
            $user->cajupay_account_id = $accountId;
        }

        $user->save();

        $tenantId = (int) ($user->tenant_id ?? $user->id);
        if ($tenantId > 0) {
            if ($request->has('api_pix_mode') && isset($validated['api_pix_mode'])) {
                $prevMode = ApiPixAccess::tenantMode($tenantId);
                $newMode = $validated['api_pix_mode'];
                if ($prevMode !== $newMode) {
                    ApiPixAccess::setTenantMode($tenantId, $newMode);
                    PlatformAuditService::log('platform.merchant.api_pix_mode', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'from' => $prevMode,
                        'to' => $newMode,
                    ], $request);
                }
            }

            if ($request->has('integration_modes') && is_array($validated['integration_modes'] ?? null)) {
                foreach (SellerIntegrationVisibility::ids() as $integrationId) {
                    if (! array_key_exists($integrationId, $validated['integration_modes'])) {
                        continue;
                    }
                    $newMode = (string) $validated['integration_modes'][$integrationId];
                    if (! in_array($newMode, [
                        SellerIntegrationVisibility::MODE_INHERIT,
                        SellerIntegrationVisibility::MODE_ENABLED,
                        SellerIntegrationVisibility::MODE_DISABLED,
                    ], true)) {
                        continue;
                    }
                    $prevMode = SellerIntegrationVisibility::tenantMode($integrationId, $tenantId);
                    if ($prevMode === $newMode) {
                        continue;
                    }
                    SellerIntegrationVisibility::setTenantMode($integrationId, $tenantId, $newMode);
                    PlatformAuditService::log('platform.merchant.integration_mode', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'integration' => $integrationId,
                        'from' => $prevMode,
                        'to' => $newMode,
                    ], $request);
                }
            }

            if ($request->has('med_zero_enabled')) {
                $prevMedZero = app(MedPolicyService::class)->medZeroForTenant($tenantId);
                $newMedZero = $request->boolean('med_zero_enabled');
                if ($prevMedZero !== $newMedZero) {
                    app(MedPolicyService::class)->setMedZeroForTenant($tenantId, $newMedZero);
                    PlatformAuditService::log('platform.merchant.med_zero', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'from' => $prevMedZero,
                        'to' => $newMedZero,
                    ], $request);
                }
            }

            if ($this->requestTouchesApiPixMinimum($request)) {
                $prevApi = $this->minimumChargeService->tenantApiPixOverride($tenantId);
                $apiMin = $this->normalizeTenantChargeLimitInput(
                    $request,
                    'api_pix_minimum_charge_brl',
                    'use_platform_api_pix_minimum'
                );
                $this->minimumChargeService->setTenantApiPixOverride($tenantId, $apiMin);
                if ($prevApi !== $apiMin) {
                    PlatformAuditService::log('platform.merchant.charge_limits', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'api_pix_minimum_charge_brl' => $apiMin,
                    ], $request);
                }
            }

            if ($this->requestTouchesPlatformMinimum($request)) {
                $prevPlatform = $this->minimumChargeService->tenantPlatformOverride($tenantId);
                $platformMin = $this->normalizeTenantChargeLimitInput(
                    $request,
                    'platform_minimum_charge_brl',
                    'use_platform_platform_minimum'
                );
                $this->minimumChargeService->setTenantPlatformOverride($tenantId, $platformMin);
                if ($prevPlatform !== $platformMin) {
                    PlatformAuditService::log('platform.merchant.charge_limits', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'platform_minimum_charge_brl' => $platformMin,
                    ], $request);
                }
            }
        }

        if (Schema::hasTable('tenant_wallets') && Schema::hasColumn('tenant_wallets', 'admin_withdrawal_blocked')) {
            if ($tenantId > 0) {
                $wallet = TenantWallet::query()->firstOrCreate(
                    ['tenant_id' => $tenantId],
                    ['available_balance' => 0, 'pending_balance' => 0, 'currency' => 'BRL']
                );
                $wallet->admin_withdrawal_blocked = $request->boolean('admin_withdrawal_blocked');
                $amt = $request->input('admin_blocked_amount');
                $wallet->admin_blocked_amount = ($amt === null || $amt === '') ? null : round((float) $amt, 2);
                $until = $request->input('admin_block_until');
                $wallet->admin_block_until = ($until === null || $until === '') ? null : $until;
                $wallet->admin_block_note = $request->input('admin_block_note') ?: null;
                if ($wallet->isDirty(['admin_withdrawal_blocked', 'admin_blocked_amount', 'admin_block_until', 'admin_block_note'])) {
                    $wallet->save();
                    PlatformAuditService::log('platform.merchant.wallet_admin_block', [
                        'merchant_user_id' => $user->id,
                        'tenant_id' => $tenantId,
                        'admin_withdrawal_blocked' => $wallet->admin_withdrawal_blocked,
                        'admin_blocked_amount' => $wallet->admin_blocked_amount,
                        'admin_block_until' => $wallet->admin_block_until?->toIso8601String(),
                    ], $request);
                }
            }
        }

        if (($prevAccountStatus ?? null) !== ($user->account_status ?? null)) {
            PlatformAuditService::log('platform.merchant.account_status', [
                'user_id' => $user->id,
                'from' => $prevAccountStatus,
                'to' => $user->account_status,
            ], $request);

            if (($user->account_status ?? null) === 'rejected' && $user->role === User::ROLE_INFOPRODUTOR) {
                SellerRejected::dispatch($user->fresh());
            }
        }

        PlatformAuditService::log('platform.merchant.updated', ['user_id' => $user->id], $request);

        return redirect()
            ->route('plataforma.usuarios.show', $user)
            ->with('success', 'Infoprodutor atualizado.');
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function normalizeMerchantFeesOverrides(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach (['pix', 'api_pix', 'pixgo', 'open_finance', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal'] as $key) {
            $block = $raw[$key] ?? null;
            if (! is_array($block)) {
                continue;
            }
            $row = [];
            if (array_key_exists('percent', $block) && $block['percent'] !== '' && $block['percent'] !== null) {
                $p = (float) $block['percent'];
                if ($p < 0 || $p > 100) {
                    throw ValidationException::withMessages([
                        "merchant_fees.$key.percent" => 'O percentual deve estar entre 0 e 100.',
                    ]);
                }
                $row['percent'] = PercentDecimal::toFloat(PercentDecimal::normalize($p));
            }
            if (array_key_exists('fixed', $block) && $block['fixed'] !== '' && $block['fixed'] !== null) {
                $f = (float) $block['fixed'];
                if ($f < 0 || $f > 999999) {
                    throw ValidationException::withMessages([
                        "merchant_fees.$key.fixed" => 'Valor fixo inválido.',
                    ]);
                }
                $row['fixed'] = round($f, 2);
            }
            if ($row !== []) {
                $out[$key] = $row;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function normalizeMerchantSettlementOverrides(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach (\App\Services\EffectiveSettlementRules::SETTLEMENT_METHOD_KEYS as $key) {
            $block = $raw[$key] ?? null;
            if (! is_array($block)) {
                continue;
            }
            $row = [];
            if (array_key_exists('days_to_available', $block) && $block['days_to_available'] !== '' && $block['days_to_available'] !== null) {
                $row['days_to_available'] = max(0, min(365, (int) $block['days_to_available']));
            }
            if (array_key_exists('reserve_percent', $block) && $block['reserve_percent'] !== '' && $block['reserve_percent'] !== null) {
                $row['reserve_percent'] = round(min(100, max(0, (float) $block['reserve_percent'])), 2);
            }
            if (array_key_exists('reserve_hold_days', $block) && $block['reserve_hold_days'] !== '' && $block['reserve_hold_days'] !== null) {
                $row['reserve_hold_days'] = max(0, min(365, (int) $block['reserve_hold_days']));
            }
            if ($row !== []) {
                $out[$key] = $row;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function normalizeMerchantGatewayOrder(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $out = [];
        foreach (['pix', 'card', 'boleto', 'pix_auto'] as $method) {
            $list = $raw[$method] ?? null;
            if (! is_array($list)) {
                continue;
            }
            $slugs = [];
            foreach ($list as $s) {
                if (is_string($s) && preg_match('/^[a-z0-9_-]+$/', $s)) {
                    $slugs[] = $s;
                }
            }
            $slugs = GatewayRegistry::filterSlugsToAllowedAcquirers($slugs);
            if ($slugs !== []) {
                $out[$method] = $slugs;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, array{percent: float, fixed: float}>  $fees
     * @param  array<string, mixed>|null  $rawOverrides
     * @return list<array{key: string, label: string, percent: float, fixed: float, has_override: bool}>
     */
    private function formatEffectiveFeesForFrontend(array $fees, ?array $rawOverrides = null): array
    {
        $labels = [
            'pix' => 'PIX (checkout)',
            'api_pix' => 'PIX (API)',
            'pixgo' => 'PixGo',
            'open_finance' => 'Open Finance',
            'card' => 'Cartão',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'boleto' => 'Boleto',
            'withdrawal' => 'Saque',
        ];

        $rows = [];
        foreach (['pix', 'api_pix', 'pixgo', 'open_finance', 'card', 'apple_pay', 'google_pay', 'boleto', 'withdrawal'] as $key) {
            $block = $fees[$key] ?? ['percent' => 0.0, 'fixed' => 0.0];
            $overrideBlock = is_array($rawOverrides) ? ($rawOverrides[$key] ?? null) : null;
            $hasOverride = is_array($overrideBlock) && (
                (array_key_exists('percent', $overrideBlock) && $overrideBlock['percent'] !== '' && $overrideBlock['percent'] !== null)
                || (array_key_exists('fixed', $overrideBlock) && $overrideBlock['fixed'] !== '' && $overrideBlock['fixed'] !== null)
            );
            $rows[] = [
                'key' => $key,
                'label' => $labels[$key],
                'percent' => round((float) ($block['percent'] ?? 0), 4),
                'fixed' => round((float) ($block['fixed'] ?? 0), 2),
                'has_override' => $hasOverride,
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     api_pix_minimum_charge_brl: float|null,
     *     platform_minimum_charge_brl: float|null,
     *     api_pix_minimum_effective_brl: float,
     *     platform_minimum_effective_brl: float
     * }
     */
    private function chargeLimitsPayloadForTenant(int $tenantId): array
    {
        return [
            'api_pix_minimum_charge_brl' => $this->minimumChargeService->tenantApiPixOverride($tenantId),
            'platform_minimum_charge_brl' => $this->minimumChargeService->tenantPlatformOverride($tenantId),
            'api_pix_minimum_effective_brl' => $this->minimumChargeService->apiPixMinimumBrlForTenant($tenantId),
            'platform_minimum_effective_brl' => $this->minimumChargeService->platformMinimumBrlForTenant($tenantId),
        ];
    }

    /**
     * @return array{
     *     api_pix_minimum_charge_brl: null,
     *     platform_minimum_charge_brl: null,
     *     api_pix_minimum_effective_brl: float,
     *     platform_minimum_effective_brl: float
     * }
     */
    private function emptyChargeLimitsPayload(): array
    {
        return [
            'api_pix_minimum_charge_brl' => null,
            'platform_minimum_charge_brl' => null,
            'api_pix_minimum_effective_brl' => $this->minimumChargeService->apiPixMinimumBrl(),
            'platform_minimum_effective_brl' => $this->minimumChargeService->platformMinimumBrl(),
        ];
    }

    private function requestTouchesApiPixMinimum(Request $request): bool
    {
        return $request->hasAny(['api_pix_minimum_charge_brl', 'use_platform_api_pix_minimum']);
    }

    private function requestTouchesPlatformMinimum(Request $request): bool
    {
        return $request->hasAny(['platform_minimum_charge_brl', 'use_platform_platform_minimum']);
    }

    private function normalizeTenantChargeLimitInput(Request $request, string $field, string $inheritFlag): ?float
    {
        if ($request->boolean($inheritFlag)) {
            return null;
        }

        if (! $request->has($field)) {
            return null;
        }

        $raw = $request->input($field);
        if ($raw === null || $raw === '') {
            return null;
        }

        return round(max(0, (float) $raw), 2);
    }

    /**
     * Busca por nome, e-mail, nome fantasia, CPF/CNPJ (com ou sem máscara) e ID.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $usersQuery
     */
    private function applyInfoprodutorSearch($usersQuery, string $search): void
    {
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
        $digits = BrazilianDocumentDigits::onlyDigits($search);
        $digitLike = $digits !== ''
            ? '%'.str_replace(['%', '_'], ['\\%', '\\_'], $digits).'%'
            : null;

        $usersQuery->where(function ($q) use ($like, $search, $digitLike) {
            $q->where('users.name', 'like', $like)
                ->orWhere('users.email', 'like', $like);

            if (Schema::hasColumn('users', 'trade_name')) {
                $q->orWhere('users.trade_name', 'like', $like);
            }

            if (Schema::hasColumn('users', 'document')) {
                $q->orWhere('users.document', 'like', $like);
                if ($digitLike !== null) {
                    $q->orWhere('users.document', 'like', $digitLike);
                    $this->orWhereDocumentDigitsMatch($q, $digitLike);
                }
            }

            if ($digitLike !== null && Schema::hasColumn('users', 'legal_representative_cpf')) {
                $q->orWhere('users.legal_representative_cpf', 'like', $digitLike);
            }

            if (ctype_digit($search)) {
                $id = (int) $search;
                $q->orWhere('users.id', $id)->orWhere('users.tenant_id', $id);
            }
        });
    }

    private function orWhereDocumentDigitsMatch($query, string $digitLike): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            $query->orWhereRaw("regexp_replace(COALESCE(users.document, ''), '[^0-9]', '', 'g') LIKE ?", [$digitLike]);

            return;
        }

        $query->orWhereRaw(
            "REPLACE(REPLACE(REPLACE(COALESCE(users.document, ''), '.', ''), '-', ''), '/', '') LIKE ?",
            [$digitLike]
        );
    }

    /**
     * Campos de cadastro exibidos na aba Dados. Documento preenchido fica travado.
     *
     * @return array<string, mixed>
     */
    private function merchantProfileFormPayload(User $user): array
    {
        $document = BrazilianDocuments::digits((string) ($user->document ?? ''));
        $rep = Schema::hasColumn('users', 'legal_representative_cpf')
            ? BrazilianDocuments::digits((string) ($user->legal_representative_cpf ?? ''))
            : '';

        return [
            'person_type' => ($user->person_type ?? 'pf') === 'pj' ? 'pj' : 'pf',
            'birth_date' => $user->birth_date?->format('Y-m-d') ?? '',
            'document' => $document,
            'document_locked' => $document !== '',
            'company_name' => (string) ($user->company_name ?? ''),
            'trade_name' => (string) ($user->trade_name ?? ''),
            'legal_representative_cpf' => $rep,
            'legal_representative_cpf_locked' => $rep !== '',
            'address_zip' => BrazilianDocuments::digits((string) ($user->address_zip ?? '')),
            'address_street' => (string) ($user->address_street ?? ''),
            'address_number' => (string) ($user->address_number ?? ''),
            'address_complement' => (string) ($user->address_complement ?? ''),
            'address_neighborhood' => (string) ($user->address_neighborhood ?? ''),
            'address_city' => (string) ($user->address_city ?? ''),
            'address_state' => strtoupper(trim((string) ($user->address_state ?? ''))),
            'monthly_revenue_range' => (string) ($user->monthly_revenue_range ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyMerchantProfileFields(User $user, array $validated): void
    {
        $plain = function (?string $value, int $max): ?string {
            $clean = HtmlSanitizer::plainText($value, $max);

            return $clean !== '' ? $clean : null;
        };

        if (Schema::hasColumn('users', 'birth_date') && array_key_exists('birth_date', $validated)) {
            $user->birth_date = $validated['birth_date'] ?: null;
        }
        if (Schema::hasColumn('users', 'monthly_revenue_range') && array_key_exists('monthly_revenue_range', $validated)) {
            $user->monthly_revenue_range = $validated['monthly_revenue_range'] ?: null;
        }
        if (Schema::hasColumn('users', 'trade_name') && array_key_exists('trade_name', $validated)) {
            $user->trade_name = $plain($validated['trade_name'] ?? null, 255);
        }

        $personType = ($user->person_type ?? 'pf') === 'pj' ? 'pj' : 'pf';
        if ($personType === 'pj' && Schema::hasColumn('users', 'company_name') && array_key_exists('company_name', $validated)) {
            $user->company_name = $plain($validated['company_name'] ?? null, 255);
        }

        foreach ([
            'address_street' => 255,
            'address_number' => 32,
            'address_complement' => 120,
            'address_neighborhood' => 120,
            'address_city' => 120,
        ] as $field => $max) {
            if (! Schema::hasColumn('users', $field) || ! array_key_exists($field, $validated)) {
                continue;
            }
            $user->{$field} = $plain(isset($validated[$field]) ? (string) $validated[$field] : null, $max);
        }

        if (Schema::hasColumn('users', 'address_state') && array_key_exists('address_state', $validated)) {
            $state = strtoupper(trim((string) ($validated['address_state'] ?? '')));
            $user->address_state = $state !== '' ? $state : null;
        }
        if (Schema::hasColumn('users', 'address_zip') && array_key_exists('address_zip', $validated)) {
            $zip = BrazilianDocuments::digits((string) ($validated['address_zip'] ?? ''));
            $user->address_zip = $zip !== '' ? $zip : null;
        }

        if (Schema::hasColumn('users', 'document') && BrazilianDocuments::digits((string) ($user->document ?? '')) === '') {
            $incoming = BrazilianDocuments::digits((string) ($validated['document'] ?? ''));
            if ($incoming !== '') {
                if ($personType === 'pj') {
                    if (! BrazilianDocuments::isValidCnpj($incoming)) {
                        throw ValidationException::withMessages(['document' => 'CNPJ inválido.']);
                    }
                } elseif (! BrazilianDocuments::isValidCpf($incoming)) {
                    throw ValidationException::withMessages(['document' => 'CPF inválido.']);
                }
                $this->assertMerchantDocumentAvailable($user, $incoming, 'document');
                $user->document = $incoming;
            }
        }

        if ($personType === 'pj'
            && Schema::hasColumn('users', 'legal_representative_cpf')
            && BrazilianDocuments::digits((string) ($user->legal_representative_cpf ?? '')) === '') {
            $incoming = BrazilianDocuments::digits((string) ($validated['legal_representative_cpf'] ?? ''));
            if ($incoming !== '') {
                if (! BrazilianDocuments::isValidCpf($incoming)) {
                    throw ValidationException::withMessages([
                        'legal_representative_cpf' => 'CPF do representante legal inválido.',
                    ]);
                }
                $this->assertMerchantDocumentAvailable($user, $incoming, 'legal_representative_cpf');
                $user->legal_representative_cpf = $incoming;
            }
        }
    }

    private function assertMerchantDocumentAvailable(User $user, string $digits, string $field): void
    {
        if ($blocked = \App\Support\BlockedDocuments::messageFor($digits)) {
            throw ValidationException::withMessages([
                $field => $blocked,
            ]);
        }

        $taken = User::query()
            ->where('id', '!=', $user->id)
            ->where(function ($q) use ($digits) {
                $q->where('document', $digits);
                if (Schema::hasColumn('users', 'legal_representative_cpf')) {
                    $q->orWhere('legal_representative_cpf', $digits);
                }
            })
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                $field => 'Este documento já está cadastrado em outra conta.',
            ]);
        }
    }

    private function documentTypeLabel(User $user): string
    {
        if (($user->person_type ?? '') === 'pj') {
            return 'CNPJ';
        }
        if (($user->person_type ?? '') === 'pf') {
            return 'CPF';
        }

        $digits = BrazilianDocumentDigits::onlyDigits((string) ($user->document ?? ''));
        if (strlen($digits) === 14) {
            return 'CNPJ';
        }
        if (strlen($digits) === 11) {
            return 'CPF';
        }

        return '—';
    }
}
