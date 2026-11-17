<?php

namespace App\Services\Platform;

use App\Models\MedDispute;
use App\Models\Order;
use App\Models\PlatformAdminMenuView;
use App\Models\Product;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Support\KycEnforcement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class PlatformAdminAlertCounts
{
    public const KEY_KYC = 'kyc';

    /** Aprovações de KYC ainda não vistas na lista. */
    public const KEY_KYC_APROVADOS = 'kyc_aprovados';

    public const KEY_APROVACAO = 'aprovacao';

    public const KEY_PRODUTOS = 'produtos';

    public const KEY_SAQUES = 'saques';

    public const KEY_FINANCEIRO = 'financeiro';

    public const KEY_DISPUTAS = 'disputas';

    public const KEY_TRANSACOES = 'transacoes';

    /**
     * Contadores para badges do menu do operador (/plataforma).
     * KYC e Produtos permanecem enquanto houver item sem análise.
     * Os demais zeram ao abrir a página até surgir um evento novo.
     *
     * @return array<string, int>
     */
    public function sidebarBadges(?User $user = null): array
    {
        return [
            self::KEY_KYC => $this->kycPendingCount($user) + $this->kycApprovedUnseenCount($user),
            self::KEY_APROVACAO => $this->pendingAccountApprovalCount($user),
            self::KEY_PRODUTOS => $this->productsPendingCount($user),
            self::KEY_SAQUES => $this->withdrawalsAttentionCount($user),
            self::KEY_FINANCEIRO => $this->withdrawalsPendingCount($user),
            self::KEY_DISPUTAS => $this->medDisputesOpenCount($user),
            self::KEY_TRANSACOES => $this->refundRequestsPendingCount($user),
        ];
    }

    public function menuKeyForRequest(Request $request): ?string
    {
        if (! $request->isMethod('GET')) {
            return null;
        }

        $name = (string) $request->route()?->getName();

        return match ($name) {
            'plataforma.transacoes.index' => self::KEY_TRANSACOES,
            'plataforma.saques.index' => self::KEY_SAQUES,
            'plataforma.financeiro.index' => self::KEY_FINANCEIRO,
            'plataforma.kyc.index', 'plataforma.kyc.show' => self::KEY_KYC,
            'plataforma.produtos.index' => self::KEY_PRODUTOS,
            'plataforma.disputas.index', 'plataforma.disputas.show' => self::KEY_DISPUTAS,
            default => null,
        };
    }

    /**
     * KYC e Produtos contam a fila real; visitar a página não zera o badge.
     */
    public function clearsBadgeOnVisit(string $menuKey): bool
    {
        return in_array($menuKey, [
            self::KEY_SAQUES,
            self::KEY_FINANCEIRO,
            self::KEY_DISPUTAS,
            self::KEY_TRANSACOES,
        ], true);
    }

    public function markViewed(User $user, string $menuKey): void
    {
        if (! in_array($menuKey, $this->menuKeys(), true) || ! $this->viewsTableReady()) {
            return;
        }

        PlatformAdminMenuView::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'menu_key' => $menuKey,
            ],
            [
                'viewed_at' => now(),
            ]
        );
    }

    public function kycPendingCount(?User $user = null): int
    {
        if (! Schema::hasColumn('users', 'kyc_status')) {
            return 0;
        }

        $query = User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereNotNull('tenant_id')
            ->where(function ($sub) {
                $sub->where('kyc_status', User::KYC_PENDING_REVIEW);
                if (Schema::hasColumn('users', 'kyc_needs_document_review')) {
                    $sub->orWhere('kyc_needs_document_review', true);
                }
            });

        return (int) $query->count();
    }

    /**
     * Aprovações de KYC desde a última visita à lista.
     * A primeira leitura só marca o ponto de partida, para o histórico antigo não acender o balão.
     */
    public function kycApprovedUnseenCount(?User $user = null): int
    {
        if ($user === null || ! Schema::hasColumn('users', 'kyc_status') || ! Schema::hasColumn('users', 'kyc_reviewed_at')) {
            return 0;
        }

        $since = $this->viewedAt($user, self::KEY_KYC_APROVADOS);
        if ($since === null) {
            $this->markViewed($user, self::KEY_KYC_APROVADOS);

            return 0;
        }

        return (int) User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->whereNotNull('tenant_id')
            ->where('kyc_status', User::KYC_APPROVED)
            ->where('kyc_reviewed_at', '>', $since)
            ->count();
    }

    public function pendingAccountApprovalCount(?User $user = null): int
    {
        if (KycEnforcement::mode() !== KycEnforcement::WITHDRAWAL) {
            return 0;
        }

        if (! Schema::hasColumn('users', 'account_status')) {
            return 0;
        }

        return (int) User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where('account_status', 'pending')
            ->count();
    }

    public function productsPendingCount(?User $user = null): int
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'approval_status')) {
            return 0;
        }

        return (int) Product::query()
            ->where('approval_status', Product::APPROVAL_PENDING)
            ->count();
    }

    public function withdrawalsPendingCount(?User $user = null): int
    {
        if (! Schema::hasTable('withdrawals')) {
            return 0;
        }

        $query = Withdrawal::query()
            ->where('status', MerchantWithdrawalService::STATUS_PENDING);

        $this->applyUnseenFilter($query, $user, self::KEY_FINANCEIRO);

        return (int) $query->count();
    }

    public function withdrawalsFailedCount(?User $user = null): int
    {
        if (! Schema::hasTable('withdrawals')) {
            return 0;
        }

        $query = Withdrawal::query()
            ->where('status', MerchantWithdrawalService::STATUS_FAILED);

        $this->applyUnseenFilter($query, $user, self::KEY_SAQUES);

        return (int) $query->count();
    }

    public function withdrawalsAttentionCount(?User $user = null): int
    {
        if (! Schema::hasTable('withdrawals')) {
            return 0;
        }

        $query = Withdrawal::query()
            ->whereIn('status', [
                MerchantWithdrawalService::STATUS_PENDING,
                MerchantWithdrawalService::STATUS_FAILED,
            ]);

        $this->applyUnseenFilter($query, $user, self::KEY_SAQUES);

        return (int) $query->count();
    }

    public function medDisputesOpenCount(?User $user = null): int
    {
        if (! Schema::hasTable('med_disputes')) {
            return 0;
        }

        $query = MedDispute::query()->open();
        if (Schema::hasColumn('med_disputes', 'responsible_party')) {
            $query->platformManaged();
        }

        $this->applyUnseenFilter($query, $user, self::KEY_DISPUTAS);

        return (int) $query->count();
    }

    public function refundRequestsPendingCount(?User $user = null): int
    {
        $pendingRequests = 0;
        if (Schema::hasTable('refund_requests')) {
            $query = RefundRequest::query()->pending();
            $this->applyUnseenFilter($query, $user, self::KEY_TRANSACOES);
            $pendingRequests = (int) $query->count();
        }

        return $pendingRequests + $this->refundPendingOrdersCount();
    }

    /**
     * Pedidos aguardando efetivação no adquirente (ex.: estorno de cartão CajuPay no painel).
     * O badge permanece até o webhook confirmar — visitar Transações não zera.
     */
    public function refundPendingOrdersCount(): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        return (int) Order::query()->where('status', 'refund_pending')->count();
    }

    /**
     * @return list<string>
     */
    private function menuKeys(): array
    {
        return [
            self::KEY_KYC,
            self::KEY_KYC_APROVADOS,
            self::KEY_PRODUTOS,
            self::KEY_SAQUES,
            self::KEY_FINANCEIRO,
            self::KEY_DISPUTAS,
            self::KEY_TRANSACOES,
        ];
    }

    private function viewsTableReady(): bool
    {
        return Schema::hasTable('platform_admin_menu_views');
    }

    private function viewedAt(?User $user, string $menuKey): ?Carbon
    {
        if ($user === null || ! $this->viewsTableReady()) {
            return null;
        }

        $viewedAt = PlatformAdminMenuView::query()
            ->where('user_id', $user->id)
            ->where('menu_key', $menuKey)
            ->value('viewed_at');

        return $viewedAt ? Carbon::parse($viewedAt) : null;
    }

    private function applyUnseenFilter(Builder $query, ?User $user, string $menuKey, string $column = 'updated_at'): void
    {
        $since = $this->viewedAt($user, $menuKey);
        if ($since === null) {
            return;
        }

        $query->where($column, '>', $since);
    }
}
