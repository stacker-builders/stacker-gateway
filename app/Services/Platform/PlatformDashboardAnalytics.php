<?php

namespace App\Services\Platform;

use App\Gateways\GatewayRegistry;
use App\Models\ApiWebhookDelivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\TenantWallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\MerchantWithdrawalService;
use App\Support\PlatformDashboardPeriod;
use App\Support\SqlDialect;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlatformDashboardAnalytics
{
    /**
     * @return array<string, mixed>
     */
    public static function compute(string $period, ?string $start, ?string $end): array
    {
        [$prevStart, $prevEnd] = PlatformDashboardPeriod::previousRange($start, $end);
        $compare = $period !== 'total';

        $currentSales = self::salesSnapshot($start, $end);
        $previousSales = $compare ? self::salesSnapshot($prevStart, $prevEnd) : self::emptySalesSnapshot();

        $revenue = PlatformRevenueKpis::compute($start, $end);
        $prevRevenue = $compare ? PlatformRevenueKpis::compute($prevStart, $prevEnd) : [
            'faturamento_taxas_cobradas' => 0.0,
            'faturamento_taxas_saque' => 0.0,
            'faturamento_custo_adquirente_vendas' => 0.0,
            'faturamento_custo_adquirente_saques' => 0.0,
            'faturamento_liquido' => 0.0,
        ];

        $withdrawals = self::withdrawalsSnapshot($start, $end);
        $funnel = self::funnel($start, $end);
        $growth = self::growthSnapshot($start, $end, $funnel);
        $prevGrowth = $compare ? self::growthSnapshot($prevStart, $prevEnd, self::funnel($prevStart, $prevEnd)) : null;

        $kpis = [
            'wallet_available' => self::walletSum('available_balance'),
            'wallet_pending' => self::walletSum('pending_balance'),
            'vendas_totais' => $currentSales['volume'],
            'quantidade_vendas' => $currentSales['quantidade'],
            'ticket_medio' => $currentSales['ticket'],
            'withdrawals_total' => $withdrawals['paid_amount'],
            'withdrawals_pending' => $withdrawals['pending_amount'],
            'withdrawals_paid_count' => $withdrawals['paid_count'],
            'withdrawals_pending_count' => $withdrawals['pending_count'],
            'infoprodutores_count' => $growth['infoprodutores_total'],
            'faturamento_taxas_cobradas' => $revenue['faturamento_taxas_cobradas'],
            'faturamento_taxas_saque' => $revenue['faturamento_taxas_saque'],
            'faturamento_custo_adquirente_vendas' => $revenue['faturamento_custo_adquirente_vendas'],
            'faturamento_custo_adquirente_saques' => $revenue['faturamento_custo_adquirente_saques'],
            'faturamento_liquido' => $revenue['faturamento_liquido'],
        ];

        return [
            'kpis' => $kpis,
            'growth' => $growth,
            'comparisons' => $compare ? [
                'vendas_totais' => self::comparison($currentSales['volume'], $previousSales['volume']),
                'quantidade_vendas' => self::comparison((float) $currentSales['quantidade'], (float) $previousSales['quantidade']),
                'ticket_medio' => self::comparison($currentSales['ticket'], $previousSales['ticket']),
                'faturamento_liquido' => self::comparison($revenue['faturamento_liquido'], $prevRevenue['faturamento_liquido']),
                'novos_infoprodutores' => self::comparison((float) $growth['novos_infoprodutores'], (float) ($prevGrowth['novos_infoprodutores'] ?? 0)),
                'novos_compradores' => self::comparison((float) $growth['novos_compradores'], (float) ($prevGrowth['novos_compradores'] ?? 0)),
                'produtos_criados' => self::comparison((float) $growth['produtos_criados'], (float) ($prevGrowth['produtos_criados'] ?? 0)),
            ] : null,
            'funnel' => $funnel,
            'payment_methods' => self::paymentMethods($start, $end),
            'acquirers' => self::acquirers($start, $end),
            'top_sellers' => self::topSellers($start, $end),
            'top_products' => self::topProducts($start, $end),
            'alerts' => self::alerts(),
            'grafico' => self::chart($period, $start, $end, $prevStart, $prevEnd, $compare),
            'census' => self::census(),
        ];
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function applyPeriod(Builder $query, ?string $start, ?string $end, string $column = 'created_at'): Builder
    {
        if ($start && $end) {
            $query->whereBetween($column, [$start, $end]);
        } elseif ($start) {
            $query->where($column, '>=', $start);
        } elseif ($end) {
            $query->where($column, '<=', $end);
        }

        return $query;
    }

    /**
     * @return array{volume: float, quantidade: int, ticket: float}
     */
    private static function salesSnapshot(?string $start, ?string $end): array
    {
        $row = self::applyPeriod(Order::query()->where('status', 'completed'), $start, $end)
            ->selectRaw('COALESCE(SUM(amount), 0) as volume, COUNT(*) as quantidade')
            ->first();

        $volume = round((float) ($row->volume ?? 0), 2);
        $quantidade = (int) ($row->quantidade ?? 0);

        return [
            'volume' => $volume,
            'quantidade' => $quantidade,
            'ticket' => $quantidade > 0 ? round($volume / $quantidade, 2) : 0.0,
        ];
    }

    /**
     * @return array{volume: float, quantidade: int, ticket: float}
     */
    private static function emptySalesSnapshot(): array
    {
        return ['volume' => 0.0, 'quantidade' => 0, 'ticket' => 0.0];
    }

    /**
     * @return array{paid_amount: float, paid_count: int, pending_amount: float, pending_count: int}
     */
    private static function withdrawalsSnapshot(?string $start, ?string $end): array
    {
        $empty = [
            'paid_amount' => 0.0,
            'paid_count' => 0,
            'pending_amount' => 0.0,
            'pending_count' => 0,
        ];
        if (! Schema::hasTable('withdrawals')) {
            return $empty;
        }

        $paidAmountColumn = Schema::hasColumn('withdrawals', 'net_amount') ? 'net_amount' : 'amount';
        $paid = self::applyPeriod(
            Withdrawal::query()->where('status', MerchantWithdrawalService::STATUS_PAID),
            $start,
            $end
        )->selectRaw("COALESCE(SUM({$paidAmountColumn}), 0) as total, COUNT(*) as quantidade")->first();

        $pending = Withdrawal::query()
            ->whereIn('status', [
                MerchantWithdrawalService::STATUS_PENDING,
                MerchantWithdrawalService::STATUS_PROCESSING,
            ])
            ->selectRaw('COALESCE(SUM(amount), 0) as total, COUNT(*) as quantidade')
            ->first();

        return [
            'paid_amount' => round((float) ($paid->total ?? 0), 2),
            'paid_count' => (int) ($paid->quantidade ?? 0),
            'pending_amount' => round((float) ($pending->total ?? 0), 2),
            'pending_count' => (int) ($pending->quantidade ?? 0),
        ];
    }

    private static function walletSum(string $column): float
    {
        if (! Schema::hasTable('tenant_wallets') || ! Schema::hasColumn('tenant_wallets', $column)) {
            return 0.0;
        }

        return round((float) TenantWallet::query()->sum($column), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private static function growthSnapshot(?string $start, ?string $end, array $funnel): array
    {
        $totalInfoprodutores = (int) User::query()->where('role', User::ROLE_INFOPRODUTOR)->count();
        $ativos = self::activeInfoprodutoresCount();

        $novos = User::query()->where('role', User::ROLE_INFOPRODUTOR);
        self::applyPeriod($novos, $start, $end);

        $comVendas = 0;
        if (Schema::hasTable('orders')) {
            $comVendas = (int) self::applyPeriod(Order::query()->where('status', 'completed'), $start, $end)
                ->whereNotNull('tenant_id')
                ->selectRaw('COUNT(DISTINCT tenant_id) as c')
                ->value('c');
        }

        $produtosCriados = 0;
        if (Schema::hasTable('products')) {
            $produtos = Product::query();
            self::applyPeriod($produtos, $start, $end);
            $produtosCriados = (int) $produtos->count();
        }

        return [
            'novos_infoprodutores' => (int) $novos->count(),
            'infoprodutores_ativos' => $ativos,
            'infoprodutores_total' => $totalInfoprodutores,
            'infoprodutores_com_vendas' => $comVendas,
            'novos_compradores' => self::newBuyersCount($start, $end),
            'compradores_recorrentes' => self::returningBuyersCount($start, $end),
            'produtos_criados' => $produtosCriados,
            'taxa_aprovacao' => $funnel['taxa_aprovacao'],
        ];
    }

    /**
     * Totais do cadastro, independentes do filtro de data da dashboard.
     *
     * @return array<string, int|float>
     */
    private static function census(): array
    {
        $clientes = 0;
        $recompraram = 0;
        $pedidos = 0;
        $pagos = 0;
        $naoPagos = 0;

        if (Schema::hasTable('orders')) {
            $clientes = (int) Order::query()
                ->where('status', 'completed')
                ->whereNotNull('user_id')
                ->selectRaw('COUNT(DISTINCT user_id) as c')
                ->value('c');
            $recompraram = self::returningBuyersCount(null, null);
            $pedidos = (int) Order::query()->count();
            $pagos = (int) Order::query()->where('status', 'completed')->count();
            $naoPagos = (int) Order::query()
                ->whereIn('status', ['pending', 'cancelled', 'rejected'])
                ->count();
        }

        return [
            'clientes_totais' => $clientes,
            'clientes_recompraram' => $recompraram,
            'clientes_recompra_percent' => $clientes > 0 ? round(($recompraram / $clientes) * 100, 1) : 0.0,
            'infoprodutores_totais' => (int) User::query()->where('role', User::ROLE_INFOPRODUTOR)->count(),
            'infoprodutores_ativos' => self::activeInfoprodutoresCount(),
            'infoprodutores_bloqueados' => self::blockedInfoprodutoresCount(),
            'pedidos_totais' => $pedidos,
            'pedidos_pagos' => $pagos,
            'pedidos_nao_pagos' => $naoPagos,
        ];
    }

    private static function blockedInfoprodutoresCount(): int
    {
        if (! Schema::hasColumn('users', 'account_status')) {
            return 0;
        }

        return (int) User::query()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->where('account_status', 'blocked')
            ->count();
    }

    private static function activeInfoprodutoresCount(): int
    {
        $q = User::query()->where('role', User::ROLE_INFOPRODUTOR);

        if (Schema::hasColumn('users', 'kyc_status')) {
            $q->where('kyc_status', User::KYC_APPROVED);
        }

        if (Schema::hasColumn('users', 'account_status')) {
            $q->where(function ($sub) {
                $sub->where('account_status', 'approved')->orWhereNull('account_status');
            });
        }

        return (int) $q->count();
    }

    private static function newBuyersCount(?string $start, ?string $end): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        $base = Order::query()
            ->where('status', 'completed')
            ->whereNotNull('user_id');

        if (! $start && ! $end) {
            return (int) $base->selectRaw('COUNT(DISTINCT user_id) as c')->value('c');
        }

        $grouped = (clone $base)
            ->selectRaw('user_id, MIN(created_at) as first_at')
            ->groupBy('user_id');

        if ($start && $end) {
            $grouped->havingRaw('MIN(created_at) >= ? AND MIN(created_at) <= ?', [$start, $end]);
        } elseif ($start) {
            $grouped->havingRaw('MIN(created_at) >= ?', [$start]);
        } elseif ($end) {
            $grouped->havingRaw('MIN(created_at) <= ?', [$end]);
        }

        return (int) DB::query()->fromSub($grouped, 'first_purchases')->count();
    }

    private static function returningBuyersCount(?string $start, ?string $end): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        $completed = Order::query()
            ->where('status', 'completed')
            ->whereNotNull('user_id');

        if (! $start && ! $end) {
            $grouped = (clone $completed)
                ->select('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1');

            return (int) DB::query()->fromSub($grouped, 'repeat_buyers')->count();
        }

        $inPeriod = self::applyPeriod(clone $completed, $start, $end);

        if ($start) {
            $inPeriod->whereIn('user_id', function ($sub) use ($start) {
                $sub->select('user_id')
                    ->from('orders')
                    ->where('status', 'completed')
                    ->whereNotNull('user_id')
                    ->where('created_at', '<', $start);
            });
        }

        return (int) $inPeriod->selectRaw('COUNT(DISTINCT user_id) as c')->value('c');
    }

    /**
     * @return array<string, mixed>
     */
    private static function funnel(?string $start, ?string $end): array
    {
        $emptyCounts = [
            'completed' => 0,
            'rejected' => 0,
            'pending' => 0,
            'cancelled' => 0,
            'refunded' => 0,
            'disputed' => 0,
            'refund_pending' => 0,
        ];

        if (! Schema::hasTable('orders')) {
            return self::funnelFromCounts($emptyCounts);
        }

        $rows = self::applyPeriod(Order::query(), $start, $end)
            ->selectRaw('status, COUNT(*) as quantidade')
            ->groupBy('status')
            ->pluck('quantidade', 'status');

        foreach ($emptyCounts as $status => $_) {
            $emptyCounts[$status] = (int) ($rows[$status] ?? 0);
        }

        return self::funnelFromCounts($emptyCounts);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private static function funnelFromCounts(array $counts): array
    {
        $aprovadas = $counts['completed'];
        $recusadas = $counts['rejected'];
        $pendentes = $counts['pending'];
        $canceladas = $counts['cancelled'];
        $reembolsadas = $counts['refunded'];

        // Taxa real: aprovadas ÷ (aprovadas + recusadas + pendentes + canceladas + reembolsadas + demais eventos).
        $eventos = array_sum($counts);
        $taxaAprovacao = $eventos > 0
            ? round($aprovadas / $eventos * 100, 1)
            : 0.0;

        $pct = static fn (int $n): float => $eventos > 0 ? round($n / $eventos * 100, 1) : 0.0;

        return [
            'eventos' => $eventos,
            'taxa_aprovacao' => $taxaAprovacao,
            'items' => [
                ['key' => 'completed', 'label' => 'Aprovadas', 'quantidade' => $aprovadas, 'percent' => $pct($aprovadas)],
                ['key' => 'rejected', 'label' => 'Recusadas', 'quantidade' => $recusadas, 'percent' => $pct($recusadas)],
                ['key' => 'pending', 'label' => 'Pendentes', 'quantidade' => $pendentes, 'percent' => $pct($pendentes)],
                ['key' => 'cancelled', 'label' => 'Canceladas', 'quantidade' => $canceladas, 'percent' => $pct($canceladas)],
                ['key' => 'refunded', 'label' => 'Reembolsadas', 'quantidade' => $reembolsadas, 'percent' => $pct($reembolsadas)],
                ['key' => 'disputed', 'label' => 'Em disputa', 'quantidade' => $counts['disputed'], 'percent' => $pct($counts['disputed'])],
                ['key' => 'refund_pending', 'label' => 'Reembolso pendente', 'quantidade' => $counts['refund_pending'], 'percent' => $pct($counts['refund_pending'])],
            ],
        ];
    }

    /**
     * @return list<array{metodo: string, label: string, total: float, quantidade: int, percent: float}>
     */
    private static function paymentMethods(?string $start, ?string $end): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        $bucketSql = self::paymentMethodBucketSql();
        $rows = self::applyPeriod(Order::query()->where('status', 'completed'), $start, $end)
            ->selectRaw("{$bucketSql} as metodo, COALESCE(SUM(amount), 0) as total, COUNT(*) as quantidade")
            ->groupByRaw($bucketSql)
            ->get();

        $volume = (float) $rows->sum(fn ($r) => (float) $r->total);
        $labels = [
            'pix' => 'PIX',
            'card' => 'Cartão',
            'boleto' => 'Boleto',
            'wallet' => 'Carteira',
            'outro' => 'Outro',
        ];
        $sort = ['pix' => 1, 'card' => 2, 'wallet' => 3, 'boleto' => 4, 'outro' => 99];

        return $rows
            ->map(function ($row) use ($volume, $labels, $sort) {
                $method = (string) $row->metodo;
                $total = round((float) $row->total, 2);

                return [
                    'metodo' => $method,
                    'label' => $labels[$method] ?? 'Outro',
                    'total' => $total,
                    'quantidade' => (int) $row->quantidade,
                    'percent' => $volume > 0 ? round($total / $volume * 100, 1) : 0.0,
                    '_sort' => $sort[$method] ?? 99,
                ];
            })
            ->sortBy('_sort')
            ->map(function (array $row) {
                unset($row['_sort']);

                return $row;
            })
            ->values()
            ->all();
    }

    private static function paymentMethodBucketSql(): string
    {
        return "CASE
            WHEN LOWER(COALESCE(payment_method, '')) IN ('pix', 'pix_auto', 'open_finance') THEN 'pix'
            WHEN LOWER(COALESCE(payment_method, '')) IN ('card', 'credit_card', 'creditcard') THEN 'card'
            WHEN LOWER(COALESCE(payment_method, '')) IN ('apple_pay', 'google_pay') THEN 'wallet'
            WHEN LOWER(COALESCE(payment_method, '')) = 'boleto' THEN 'boleto'
            ELSE 'outro'
        END";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function acquirers(?string $start, ?string $end): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        $rows = self::applyPeriod(Order::query(), $start, $end)
            ->whereNotNull('gateway')
            ->where('gateway', '!=', '')
            ->selectRaw("
                gateway,
                COUNT(*) as transacoes,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as aprovadas,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as recusadas,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) as volume
            ")
            ->groupBy('gateway')
            ->orderByDesc('volume')
            ->limit(12)
            ->get();

        return $rows->map(function ($row) {
            $aprovadas = (int) $row->aprovadas;
            $recusadas = (int) $row->recusadas;
            $transacoes = (int) $row->transacoes;

            return [
                'slug' => (string) $row->gateway,
                'nome' => self::acquirerLabel((string) $row->gateway),
                'volume' => round((float) $row->volume, 2),
                'transacoes' => $transacoes,
                'aprovadas' => $aprovadas,
                'recusadas' => $recusadas,
                'taxa_aprovacao' => $transacoes > 0 ? round($aprovadas / $transacoes * 100, 1) : 0.0,
            ];
        })->values()->all();
    }

    private static function acquirerLabel(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || $slug === 'manual') {
            return $slug === 'manual' ? 'Manual' : '—';
        }
        $def = GatewayRegistry::get($slug);
        $name = is_array($def) ? trim((string) ($def['name'] ?? '')) : '';
        if ($name !== '') {
            return $name;
        }

        return Str::headline(str_replace(['_', '-'], ' ', $slug));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function topSellers(?string $start, ?string $end, int $limit = 10): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        $rows = self::applyPeriod(Order::query()->where('status', 'completed'), $start, $end)
            ->whereNotNull('tenant_id')
            ->selectRaw('tenant_id, COALESCE(SUM(amount), 0) as volume, COUNT(*) as quantidade')
            ->groupBy('tenant_id')
            ->orderByDesc('volume')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $userColumns = ['id', 'name', 'email'];
        if (Schema::hasColumn('users', 'trade_name')) {
            $userColumns[] = 'trade_name';
        }
        $users = User::query()
            ->whereIn('id', $rows->pluck('tenant_id')->all())
            ->get($userColumns)
            ->keyBy('id');

        return $rows->map(function ($row) use ($users) {
            $user = $users->get((int) $row->tenant_id);
            $quantidade = (int) $row->quantidade;
            $volume = round((float) $row->volume, 2);

            return [
                'tenant_id' => (int) $row->tenant_id,
                'nome' => ($user && Schema::hasColumn('users', 'trade_name') && $user->trade_name)
                    ? $user->trade_name
                    : ($user?->name ?: 'Infoprodutor #'.$row->tenant_id),
                'email' => $user?->email,
                'quantidade' => $quantidade,
                'volume' => $volume,
                'ticket_medio' => $quantidade > 0 ? round($volume / $quantidade, 2) : 0.0,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function topProducts(?string $start, ?string $end, int $limit = 10): array
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('products')) {
            return [];
        }

        $query = Order::query()
            ->from('orders')
            ->where('orders.status', 'completed')
            ->whereNotNull('orders.product_id');
        self::applyPeriod($query, $start, $end, 'orders.created_at');

        $hasTradeName = Schema::hasColumn('users', 'trade_name');
        $tradeSelect = $hasTradeName ? 'users.trade_name as seller_trade_name' : 'NULL as seller_trade_name';
        $groupCols = ['orders.product_id', 'products.name', 'orders.tenant_id', 'users.name'];
        if ($hasTradeName) {
            $groupCols[] = 'users.trade_name';
        }

        $rows = $query
            ->leftJoin('products', 'orders.product_id', '=', 'products.id')
            ->leftJoin('users', 'orders.tenant_id', '=', 'users.id')
            ->selectRaw("
                orders.product_id as product_id,
                products.name as product_name,
                orders.tenant_id as tenant_id,
                users.name as seller_name,
                {$tradeSelect},
                COALESCE(SUM(orders.amount), 0) as volume,
                COUNT(*) as quantidade
            ")
            ->groupBy(...$groupCols)
            ->orderByDesc('volume')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'product_id' => $row->product_id,
            'produto' => $row->product_name ?: 'Produto #'.$row->product_id,
            'seller' => $row->seller_trade_name ?: $row->seller_name ?: '—',
            'quantidade' => (int) $row->quantidade,
            'volume' => round((float) $row->volume, 2),
        ])->values()->all();
    }

    /**
     * @return list<array{key: string, label: string, count: int, href: string}>
     */
    private static function alerts(): array
    {
        $counts = app(PlatformAdminAlertCounts::class);
        $items = [];

        $saques = $counts->withdrawalsAttentionCount();
        if ($saques > 0) {
            $items[] = [
                'key' => 'saques',
                'label' => $saques === 1 ? 'saque pendente ou com falha' : 'saques pendentes ou com falha',
                'count' => $saques,
                'href' => '/plataforma/saques',
            ];
        }

        $reembolsos = $counts->refundRequestsPendingCount();
        if ($reembolsos > 0) {
            $items[] = [
                'key' => 'reembolsos',
                'label' => $reembolsos === 1 ? 'reembolso pendente' : 'reembolsos pendentes',
                'count' => $reembolsos,
                'href' => '/plataforma/transacoes?status=refund_requests',
            ];
        }

        $disputas = $counts->medDisputesOpenCount();
        if ($disputas > 0) {
            $items[] = [
                'key' => 'disputas',
                'label' => $disputas === 1 ? 'disputa MED aberta' : 'disputas MED abertas',
                'count' => $disputas,
                'href' => '/plataforma/disputas',
            ];
        }

        $kyc = $counts->kycPendingCount();
        if ($kyc > 0) {
            $items[] = [
                'key' => 'kyc',
                'label' => $kyc === 1 ? 'KYC aguardando análise' : 'KYCs aguardando análise',
                'count' => $kyc,
                'href' => '/plataforma/verificacoes-kyc',
            ];
        }

        if (Schema::hasTable('orders')) {
            $pendentes = (int) Order::query()->where('status', 'pending')->count();
            if ($pendentes > 0) {
                $items[] = [
                    'key' => 'pendentes',
                    'label' => $pendentes === 1 ? 'transação pendente' : 'transações pendentes',
                    'count' => $pendentes,
                    'href' => '/plataforma/transacoes?status=pending',
                ];
            }
        }

        $webhooks = 0;
        if (Schema::hasTable('api_webhook_deliveries')) {
            $webhooks += (int) ApiWebhookDelivery::query()
                ->where('status', ApiWebhookDelivery::STATUS_FAILED)
                ->count();
        }
        if ($webhooks > 0) {
            $items[] = [
                'key' => 'webhooks',
                'label' => $webhooks === 1 ? 'webhook com falha' : 'webhooks com falha',
                'count' => $webhooks,
                'href' => '/plataforma/webhooks',
            ];
        }

        return $items;
    }

    /**
     * @return array{granularity: string, compare: bool, points: list<array<string, mixed>>}
     */
    private static function chart(
        string $period,
        ?string $start,
        ?string $end,
        ?string $prevStart,
        ?string $prevEnd,
        bool $compare,
    ): array {
        $granularity = PlatformDashboardPeriod::granularity($period, $start, $end);
        $keys = self::chartKeys($period, $granularity, $start, $end);
        $current = self::chartSeries($granularity, $start, $end);
        $previous = $compare ? self::chartSeries($granularity, $prevStart, $prevEnd) : [
            'volume' => collect(),
            'count' => collect(),
            'revenue' => collect(),
            'refunds' => collect(),
        ];

        $prevKeys = $compare ? array_keys($previous['volume']->all() + $previous['count']->all() + $previous['refunds']->all()) : [];
        if ($compare && $prevKeys !== [] && count($keys) === 0) {
            $keys = array_map('strval', $prevKeys);
        }

        $prevVolumeList = array_values($previous['volume']->all());
        $prevCountList = array_values($previous['count']->all());
        $prevRevenueList = array_values($previous['revenue']->all());
        $prevRefundsList = array_values($previous['refunds']->all());

        $points = [];
        foreach (array_values($keys) as $i => $key) {
            $volume = (float) ($current['volume'][$key] ?? 0);
            $count = (int) ($current['count'][$key] ?? 0);
            $revenue = (float) ($current['revenue'][$key] ?? 0);
            $refunds = (float) ($current['refunds'][$key] ?? 0);
            $points[] = [
                'key' => (string) $key,
                'label' => self::chartLabel($granularity, (string) $key),
                'volume' => round($volume, 2),
                'count' => $count,
                'ticket' => $count > 0 ? round($volume / $count, 2) : 0.0,
                'revenue' => round($revenue, 2),
                'refunds' => round($refunds, 2),
                'prev_volume' => round((float) ($prevVolumeList[$i] ?? $previous['volume'][$key] ?? 0), 2),
                'prev_count' => (int) ($prevCountList[$i] ?? $previous['count'][$key] ?? 0),
                'prev_ticket' => 0.0,
                'prev_revenue' => round((float) ($prevRevenueList[$i] ?? $previous['revenue'][$key] ?? 0), 2),
                'prev_refunds' => round((float) ($prevRefundsList[$i] ?? $previous['refunds'][$key] ?? 0), 2),
            ];
            $prevCount = $points[$i]['prev_count'];
            $points[$i]['prev_ticket'] = $prevCount > 0
                ? round($points[$i]['prev_volume'] / $prevCount, 2)
                : 0.0;
        }

        return [
            'granularity' => $granularity,
            'compare' => $compare,
            'points' => $points,
        ];
    }

    /**
     * @return list<string>
     */
    private static function chartKeys(string $period, string $granularity, ?string $start, ?string $end): array
    {
        if ($granularity === 'hour') {
            return array_map('strval', range(0, 23));
        }

        if ($period === 'total') {
            $cursor = Carbon::now()->startOfMonth()->subMonths(11);
            $keys = [];
            for ($i = 0; $i < 12; $i++) {
                $keys[] = $cursor->copy()->addMonths($i)->format('Y-m');
            }

            return $keys;
        }

        if ($granularity === 'month' && $start && $end) {
            $cursor = Carbon::parse($start)->startOfMonth();
            $last = Carbon::parse($end)->startOfMonth();
            $keys = [];
            while ($cursor->lte($last)) {
                $keys[] = $cursor->format('Y-m');
                $cursor->addMonth();
            }

            return $keys;
        }

        if ($start && $end) {
            $cursor = Carbon::parse($start)->startOfDay();
            $last = Carbon::parse($end)->startOfDay();
            $keys = [];
            while ($cursor->lte($last)) {
                $keys[] = $cursor->format('Y-m-d');
                $cursor->addDay();
            }

            return $keys;
        }

        return [];
    }

    private static function chartLabel(string $granularity, string $key): string
    {
        if ($granularity === 'hour') {
            return $key.'h';
        }
        if ($granularity === 'month') {
            $parts = explode('-', $key);

            return isset($parts[1]) ? $parts[1].'/'.$parts[0] : $key;
        }
        $parts = explode('-', $key);

        return isset($parts[2], $parts[1]) ? $parts[2].'/'.$parts[1] : $key;
    }

    /**
     * @return array{volume: \Illuminate\Support\Collection, count: \Illuminate\Support\Collection, revenue: \Illuminate\Support\Collection, refunds: \Illuminate\Support\Collection}
     */
    private static function chartSeries(string $granularity, ?string $start, ?string $end): array
    {
        $empty = [
            'volume' => collect(),
            'count' => collect(),
            'revenue' => collect(),
            'refunds' => collect(),
        ];
        if (! Schema::hasTable('orders')) {
            return $empty;
        }

        $bucket = SqlDialect::bucketExpression($granularity, 'created_at');
        $sales = self::applyPeriod(Order::query()->where('status', 'completed'), $start, $end)
            ->selectRaw("{$bucket} as bucket, COALESCE(SUM(amount), 0) as volume, COUNT(*) as quantidade")
            ->groupBy('bucket')
            ->get();

        $volume = collect();
        $count = collect();
        foreach ($sales as $row) {
            $key = (string) $row->bucket;
            $volume[$key] = (float) $row->volume;
            $count[$key] = (int) $row->quantidade;
        }

        $refunds = collect();
        $refundRows = self::applyPeriod(Order::query()->where('status', 'refunded'), $start, $end)
            ->selectRaw("{$bucket} as bucket, COALESCE(SUM(amount), 0) as volume")
            ->groupBy('bucket')
            ->get();
        foreach ($refundRows as $row) {
            $refunds[(string) $row->bucket] = (float) $row->volume;
        }

        return [
            'volume' => $volume,
            'count' => $count,
            'revenue' => self::chartRevenue($granularity, $start, $end),
            'refunds' => $refunds,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<string, float>
     */
    private static function chartRevenue(string $granularity, ?string $start, ?string $end)
    {
        $out = collect();
        if (! Schema::hasTable('wallet_transactions') || ! Schema::hasTable('orders')) {
            return $out;
        }

        $bucket = SqlDialect::bucketExpression($granularity, 'o.created_at');
        $sale = \App\Models\WalletTransaction::TYPE_CREDIT_SALE;
        $pending = \App\Models\WalletTransaction::TYPE_CREDIT_SALE_PENDING;

        $fees = Order::query()
            ->from('orders as o')
            ->join('wallet_transactions as wt', 'wt.order_id', '=', 'o.id')
            ->where('o.status', 'completed')
            ->whereIn('wt.type', [$sale, $pending]);
        self::applyPeriod($fees, $start, $end, 'o.created_at');

        $perOrder = $fees
            ->selectRaw("
                o.id as order_id,
                {$bucket} as bucket,
                SUM(CASE WHEN wt.type = '{$sale}' THEN wt.amount_fee ELSE 0 END) as sale_fee,
                SUM(CASE WHEN wt.type = '{$pending}' THEN wt.amount_fee ELSE 0 END) as pending_fee
            ")
            ->groupByRaw("o.id, {$bucket}")
            ->get();

        foreach ($perOrder as $row) {
            $key = (string) $row->bucket;
            $fee = (float) $row->sale_fee > 0 ? (float) $row->sale_fee : (float) $row->pending_fee;
            $out[$key] = round((float) ($out[$key] ?? 0) + $fee, 2);
        }

        return $out;
    }

    /**
     * @return array{current: float, previous: float, delta_percent: float|null}
     */
    private static function comparison(float $current, float $previous): array
    {
        return [
            'current' => round($current, 2),
            'previous' => round($previous, 2),
            'delta_percent' => PlatformDashboardPeriod::deltaPercent($current, $previous),
        ];
    }
}
