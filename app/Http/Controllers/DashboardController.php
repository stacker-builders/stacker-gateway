<?php

namespace App\Http\Controllers;

use App\Events\DashboardLoading;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Support\DashboardBannerSettings;
use App\Support\PlatformDashboardPeriod;
use App\Support\SqlDialect;
use Carbon\Carbon;
use App\Services\AffiliateCommissionQuery;
use App\Services\Checkout\CheckoutAbandonmentMetrics;
use App\Services\TeamAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    public function __invoke(Request $request): Response
    {
        $period = PlatformDashboardPeriod::normalize($request->query('period', 'hoje'));
        $from = PlatformDashboardPeriod::normalizeDate($request->query('from'));
        $to = PlatformDashboardPeriod::normalizeDate($request->query('to'));
        if ($period === 'personalizado') {
            $today = Carbon::now()->toDateString();
            $from = $from ?? $today;
            $to = $to ?? $today;
            if ($to < $from) {
                [$from, $to] = [$to, $from];
            }
        } else {
            $from = null;
            $to = null;
        }

        [$start, $end] = PlatformDashboardPeriod::range($period, $from, $to);
        $chartGranularity = $period === 'personalizado'
            ? PlatformDashboardPeriod::granularity($period, $start, $end)
            : (in_array($period, ['hoje', 'ontem'], true) ? 'hour' : 'day');

        $tenantId = auth()->user()->tenant_id;
        $userId = (int) auth()->id();
        $hasAffiliateEnrollments = AffiliateCommissionQuery::userHasApprovedEnrollments($userId);
        $cacheKey = 'dashboard:v7:'.($tenantId ?? 'global').':'.$userId.':'.$period.':'.($from ?? '').':'.($to ?? '');

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($tenantId, $period, $userId, $hasAffiliateEnrollments, $start, $end, $from, $to, $chartGranularity) {

            $ordersQuery = Order::forTenant($tenantId);
            if (auth()->user()?->isTeam()) {
                $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
                $ordersQuery->whereIn('product_id', $allowed ?: ['__none__']);
            }
        if ($start && $end) {
            $ordersQuery->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $ordersQuery->where('created_at', '>=', $start);
        } elseif ($end) {
            $ordersQuery->where('created_at', '<=', $end);
        }

        $ordersCompleted = (clone $ordersQuery)->where('status', 'completed');
        $ordersPending = (clone $ordersQuery)->where('status', 'pending');
        $ordersRefunded = (clone $ordersQuery)->where('status', 'refunded');

        $vendasTotais = (float) $ordersCompleted->sum('amount');
        $quantidadeVendas = $ordersCompleted->count();
        // Compras próprias (sem comissões de afiliado) para a métrica abandono / compras.
        $comprasProprias = $quantidadeVendas;
        $vendasPendentes = (float) $ordersPending->sum('amount');
        $reembolsosCount = $ordersRefunded->count();
        $reembolsosTotal = (float) (clone $ordersQuery)->where('status', 'refunded')->sum('amount');

        if ($hasAffiliateEnrollments) {
            $affiliateRequest = $this->affiliatePeriodRequest($period, $from, $to);
            $affiliateApproved = AffiliateCommissionQuery::applyFilters(
                AffiliateCommissionQuery::baseQuery($userId),
                $affiliateRequest,
            )
                ->where('status', \App\Models\AffiliateCommission::STATUS_APPROVED)
                ->get(['commission_net']);

            $affiliateTotal = (float) $affiliateApproved->sum('commission_net');
            $affiliateCount = $affiliateApproved->count();

            $vendasTotais += $affiliateTotal;
            $quantidadeVendas += $affiliateCount;
        }

        $ticketMedio = $quantidadeVendas > 0 ? $vendasTotais / $quantidadeVendas : 0.0;

        $formasPagamentoRows = (clone $ordersQuery)
            ->where('status', 'completed')
            ->select(['payment_method', 'metadata', 'gateway', 'amount'])
            ->get();

        $formasPagamento = $formasPagamentoRows
            ->groupBy(fn (Order $o) => $o->paymentMethodReportKey())
            ->map(function ($rows, $method) {
                return [
                    'metodo' => $method,
                    'label' => Order::paymentMethodReportLabel($method),
                    'total' => (float) $rows->sum(fn (Order $o) => (float) $o->amount),
                    'quantidade' => (int) $rows->count(),
                    '_sort' => Order::paymentMethodReportSort($method),
                ];
            })
            ->sortBy('_sort')
            ->map(function (array $row) {
                unset($row['_sort']);
                return $row;
            })
            ->values()
            ->all();

        $graficoVendas = $this->buildGraficoVendas($tenantId, $period, $start, $end, $hasAffiliateEnrollments ? $userId : null, $chartGranularity, $from, $to);

        $productsQuery = Product::forTenant($tenantId);
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $productsQuery->whereIn('id', $allowed ?: ['__none__']);
        }
        $quantidadeProdutos = $productsQuery->count();

            $funnel = $this->checkoutFunnelStats($tenantId, $start, $end);
            $abandonados = $funnel['abandono_carrinho'];
            $baseAbandonoCompras = $abandonados + $comprasProprias;
            $taxaAbandonoCompras = $baseAbandonoCompras > 0
                ? round((float) $abandonados / $baseAbandonoCompras * 100, 1)
                : 0.0;

            return [
                'period' => $period,
                'vendas_totais' => round($vendasTotais, 2),
                'vendas_pendentes' => round($vendasPendentes, 2),
                'quantidade_vendas' => $quantidadeVendas,
                'ticket_medio' => round($ticketMedio, 2),
                'formas_pagamento' => $formasPagamento,
                'taxa_conversao' => $funnel['taxa_conversao'],
                'abandono_carrinho' => $abandonados,
                'taxa_abandono_compras' => $taxaAbandonoCompras,
                'compras_periodo' => $comprasProprias,
                'reembolsos_count' => $reembolsosCount,
                'reembolsos_total' => round($reembolsosTotal, 2),
                'quantidade_produtos' => $quantidadeProdutos,
                'grafico_vendas' => $graficoVendas,
            ];
        });

        $payload['period'] = $period;
        $payload['from'] = $from;
        $payload['to'] = $to;
        $payload['chart_granularity'] = $chartGranularity;

        $data = new \ArrayObject($payload);
        $data['dashboard_banners'] = DashboardBannerSettings::banners(activeOnly: true, resolveUrls: true);
        $data['has_affiliate_enrollments'] = $hasAffiliateEnrollments;
        $data['affiliate_stats'] = null;
        $data['affiliate_recent_sales'] = [];

        event(new DashboardLoading($data));

        return Inertia::render('Dashboard/Index', $data->getArrayCopy());
    }

    /**
     * Abandono: sessões válidas deduplicadas (e-mail + produto, form com e-mail, após graça).
     * Taxa de conversão: sessões com pedido completed / total de sessões no período (created_at).
     * Taxa abandono/compras (no payload): abandonados / (abandonados + compras completed) × 100.
     *
     * @return array{taxa_conversao: float, abandono_carrinho: int}
     */
    private function checkoutFunnelStats(?int $tenantId, ?string $start, ?string $end): array
    {
        $productIds = null;
        $sessionsQuery = CheckoutSession::forTenant($tenantId);
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $productIds = $allowed ?: ['__none__'];
            $sessionsQuery->whereIn('product_id', $productIds);
        }

        if ($start && $end) {
            $sessionsQuery->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $sessionsQuery->where('created_at', '>=', $start);
        } elseif ($end) {
            $sessionsQuery->where('created_at', '<=', $end);
        }

        $converted = (clone $sessionsQuery)
            ->whereFunnelConversionCompleted()
            ->count();

        $abandonadosTotal = app(CheckoutAbandonmentMetrics::class)
            ->countValidAbandoned($tenantId, $productIds, $start, $end);

        $totalSessions = (clone $sessionsQuery)->count();
        $taxaConversao = $totalSessions > 0 ? round((float) $converted / $totalSessions * 100, 1) : 0.0;

        return [
            'taxa_conversao' => $taxaConversao,
            'abandono_carrinho' => $abandonadosTotal,
        ];
    }

    private function affiliatePeriodRequest(string $period, ?string $from, ?string $to): Request
    {
        $params = ['period' => $period];
        if ($period === 'personalizado') {
            $params['date_from'] = $from;
            $params['date_to'] = $to;
        }

        return Request::create('/', 'GET', $params);
    }

    /**
     * @return list<string>
     */
    private function chartKeys(string $granularity, string $start, string $end): array
    {
        if ($granularity === 'hour') {
            return array_map('strval', range(0, 23));
        }

        if ($granularity === 'month') {
            $cursor = Carbon::parse($start)->startOfMonth();
            $last = Carbon::parse($end)->startOfMonth();
            $keys = [];
            while ($cursor->lte($last)) {
                $keys[] = $cursor->format('Y-m');
                $cursor->addMonth();
            }

            return $keys;
        }

        $cursor = Carbon::parse($start)->startOfDay();
        $last = Carbon::parse($end)->startOfDay();
        $keys = [];
        while ($cursor->lte($last)) {
            $keys[] = $cursor->format('Y-m-d');
            $cursor->addDay();
        }

        return $keys;
    }

    private function buildGraficoVendas(?int $tenantId, string $period, ?string $start, ?string $end, ?int $affiliateUserId = null, string $chartGranularity = 'day', ?string $from = null, ?string $to = null): array
    {
        $query = Order::forTenant($tenantId)->where('status', 'completed');
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $query->whereIn('product_id', $allowed ?: ['__none__']);
        }

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('created_at', '>=', $start);
        } elseif ($end) {
            $query->where('created_at', '<=', $end);
        }

        $affiliateRequest = $affiliateUserId
            ? $this->affiliatePeriodRequest($period, $from, $to)
            : null;

        if ($chartGranularity === 'hour') {
            $hour = SqlDialect::hourExpression('created_at');
            $rows = $query
                ->selectRaw($hour.' as hora, SUM(amount) as total')
                ->groupBy('hora')
                ->orderBy('hora')
                ->get()
                ->keyBy('hora');

            $affiliateByHour = $affiliateUserId
                ? AffiliateCommissionQuery::approvedCommissionTotalsByHour($affiliateUserId, $affiliateRequest)
                : [];

            $result = [];
            for ($h = 0; $h <= 23; $h++) {
                $result[] = [
                    'data' => (string) $h,
                    'total' => (float) ($rows->get($h)?->total ?? 0) + ($affiliateByHour[$h] ?? 0),
                ];
            }

            return $result;
        }

        $dateExpr = $chartGranularity === 'month'
            ? SqlDialect::monthExpression('created_at')
            : SqlDialect::dateExpression('created_at');
        $rows = $query
            ->selectRaw($dateExpr.' as data, SUM(amount) as total')
            ->groupBy('data')
            ->orderBy('data')
            ->get()
            ->keyBy('data');

        $affiliateByDate = $affiliateUserId
            ? AffiliateCommissionQuery::approvedCommissionTotalsByDate($affiliateUserId, $affiliateRequest)
            : [];

        if ($chartGranularity === 'month' && $affiliateByDate !== []) {
            $byMonth = [];
            foreach ($affiliateByDate as $date => $total) {
                $key = substr((string) $date, 0, 7);
                $byMonth[$key] = ($byMonth[$key] ?? 0) + (float) $total;
            }
            $affiliateByDate = $byMonth;
        }

        if ($period === 'personalizado' && $start && $end) {
            return array_map(function (string $key) use ($rows, $affiliateByDate) {
                return [
                    'data' => $key,
                    'total' => (float) ($rows->get($key)?->total ?? 0) + (float) ($affiliateByDate[$key] ?? 0),
                ];
            }, $this->chartKeys($chartGranularity, $start, $end));
        }

        $dates = collect($rows->keys())->merge(array_keys($affiliateByDate))->unique()->sort()->values();

        if ($dates->isEmpty()) {
            return [];
        }

        return $dates->map(function (string $date) use ($rows, $affiliateByDate) {
            return [
                'data' => $date,
                'total' => (float) ($rows->get($date)?->total ?? 0) + ($affiliateByDate[$date] ?? 0),
            ];
        })->values()->all();
    }

}
