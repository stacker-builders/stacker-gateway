<?php

namespace App\Services;

use App\Models\AffiliateCommission;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\SaleOrigin;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AffiliateCommissionQuery
{
    public const PERIODS = ['hoje', 'ontem', '7dias', 'mes', 'ano', 'total', 'personalizado'];

    public static function baseQuery(int $affiliateUserId): Builder
    {
        return AffiliateCommission::query()
            ->forAffiliate($affiliateUserId)
            ->with([
                'order:id,status,payment_method,email,user_id,created_at,public_reference',
                'order.user:id,name,email',
                'product:id,name,image,type,tenant_id,affiliate_hide_customer_data',
                'producer:id,name,email',
            ]);
    }

    public static function applyFilters(Builder $query, Request $request, bool $searchCustomerFields = true): Builder
    {
        $period = $request->query('period', 'total');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'total';
        }
        [$start, $end] = self::resolveDateRange($request, $period);

        if ($start && $end) {
            $query->whereBetween('affiliate_commissions.created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('affiliate_commissions.created_at', '>=', $start);
        } elseif ($end) {
            $query->where('affiliate_commissions.created_at', '<=', $end);
        }

        $productIds = $request->query('product_ids');
        if (is_array($productIds) && $productIds !== []) {
            $ids = array_values(array_filter(array_map(
                fn ($id) => trim((string) $id),
                $productIds
            ), fn ($id) => $id !== ''));
            if ($ids !== []) {
                $query->whereIn('affiliate_commissions.product_id', $ids);
            }
        } else {
            $productId = trim((string) $request->query('product_id', ''));
            if ($productId !== '') {
                $query->where('affiliate_commissions.product_id', $productId);
            }
        }

        $producerId = (int) $request->query('producer_id', 0);
        if ($producerId > 0) {
            $query->where('affiliate_commissions.producer_tenant_id', $producerId);
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && $status !== 'all') {
            $query->where('affiliate_commissions.status', $status);
        }

        $paymentMethod = trim((string) $request->query('payment_method', ''));
        if ($paymentMethod !== '' && $paymentMethod !== 'all') {
            $query->whereHas('order', fn (Builder $q) => $q->where('payment_method', $paymentMethod));
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function (Builder $sub) use ($q, $searchCustomerFields) {
                $sub->where('affiliate_commissions.id', 'like', '%'.$q.'%')
                    ->orWhere('affiliate_commissions.order_id', 'like', '%'.$q.'%')
                    ->orWhere('affiliate_ref', 'like', '%'.$q.'%')
                    ->orWhereHas('order', function (Builder $oq) use ($q, $searchCustomerFields) {
                        $oq->where('public_reference', 'like', '%'.$q.'%');
                        if ($searchCustomerFields) {
                            $oq->orWhere('email', 'like', '%'.$q.'%')
                                ->orWhereHas('user', fn (Builder $uq) => $uq->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'));
                        }
                    })
                    ->orWhereHas('product', fn (Builder $pq) => $pq->where('name', 'like', '%'.$q.'%'));
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public static function statsFor(int $affiliateUserId, Request $request): array
    {
        $base = self::applyFilters(self::baseQuery($affiliateUserId), $request);

        $all = (clone $base)->get();
        $approved = $all->where('status', AffiliateCommission::STATUS_APPROVED);
        $pending = $all->where('status', AffiliateCommission::STATUS_PENDING);
        $cancelled = $all->whereIn('status', [AffiliateCommission::STATUS_CANCELLED]);
        $refunded = $all->where('status', AffiliateCommission::STATUS_REFUNDED);

        $totalSales = $all->count();
        $commissionApproved = (float) $approved->sum('commission_net');
        $commissionPending = (float) $pending->sum('commission_net');
        $commissionCancelled = (float) $cancelled->sum('commission_gross');
        $commissionRefunded = (float) $refunded->sum('commission_gross');
        $ticketMedio = $totalSales > 0 ? $commissionApproved / max(1, $approved->count()) : 0.0;
        $productsSold = $all->pluck('product_id')->unique()->count();

        $clicks = self::countAffiliateClicks($affiliateUserId, $request);
        $conversionRate = $clicks > 0 ? round(($totalSales / $clicks) * 100, 2) : null;

        return [
            'total_vendas' => $totalSales,
            'receita_comissao' => round($commissionApproved, 2),
            'ticket_medio' => round($ticketMedio, 2),
            'comissao_pendente' => round($commissionPending, 2),
            'comissao_aprovada' => round($commissionApproved, 2),
            'comissao_cancelada' => round($commissionCancelled, 2),
            'comissao_estornada' => round($commissionRefunded, 2),
            'produtos_vendidos' => $productsSold,
            'cliques' => $clicks,
            'taxa_conversao' => $conversionRate,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function chartByDay(int $affiliateUserId, Request $request): array
    {
        $rows = self::applyFilters(self::baseQuery($affiliateUserId), $request)
            ->where('status', AffiliateCommission::STATUS_APPROVED)
            ->orderBy('created_at')
            ->get(['commission_net', 'created_at']);

        return $rows
            ->groupBy(fn (AffiliateCommission $c) => $c->created_at?->format('Y-m-d') ?? '')
            ->map(fn (Collection $group, string $date) => [
                'date' => $date,
                'total' => round((float) $group->sum('commission_net'), 2),
                'quantidade' => $group->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function topProducts(int $affiliateUserId, Request $request, int $limit = 10): array
    {
        return self::applyFilters(self::baseQuery($affiliateUserId), $request)
            ->where('status', AffiliateCommission::STATUS_APPROVED)
            ->get()
            ->groupBy('product_id')
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'product_id' => $first->product_id,
                    'product_name' => $first->product?->name ?? '—',
                    'total' => round((float) $group->sum('commission_net'), 2),
                    'quantidade' => $group->count(),
                ];
            })
            ->sortByDesc('total')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function toUnifiedVendaListItem(AffiliateCommission $commission): array
    {
        $data = self::commissionToArrayForAffiliate($commission);
        $data['list_key'] = 'commission:'.$commission->id;
        $data['product_display_name'] = $data['product_name'] ?? '—';
        $data['amount_net'] = $data['commission_net'];
        $data['gateway_label'] = $data['payment_method_label'] ?? '—';

        if (! empty($data['customer_hidden'])) {
            $data['user'] = null;
            $data['email'] = null;
        } else {
            $data['user'] = [
                'name' => $data['customer_name'] ?? null,
                'email' => $data['customer_email'] ?? null,
            ];
            $data['email'] = $data['customer_email'] ?? null;
        }

        return $data;
    }

    /**
     * @return array{vendas_encontradas: int, valor_liquido: float}
     */
    public static function vendasStatsContribution(Collection $commissions): array
    {
        $count = $commissions->count();
        $valorLiquido = (float) $commissions
            ->where('status', AffiliateCommission::STATUS_APPROVED)
            ->sum('commission_net');

        return [
            'vendas_encontradas' => $count,
            'valor_liquido' => round($valorLiquido, 2),
        ];
    }

    /**
     * @return array<string, float>
     */
    public static function approvedCommissionTotalsByDate(int $affiliateUserId, Request $request): array
    {
        $rows = self::applyFilters(self::baseQuery($affiliateUserId), $request)
            ->where('status', AffiliateCommission::STATUS_APPROVED)
            ->get(['commission_net', 'created_at']);

        return $rows
            ->groupBy(fn (AffiliateCommission $c) => $c->created_at?->format('Y-m-d') ?? '')
            ->map(fn (Collection $group) => (float) $group->sum('commission_net'))
            ->all();
    }

    /**
     * @return array<int, float>
     */
    public static function approvedCommissionTotalsByHour(int $affiliateUserId, Request $request): array
    {
        $rows = self::applyFilters(self::baseQuery($affiliateUserId), $request)
            ->where('status', AffiliateCommission::STATUS_APPROVED)
            ->get(['commission_net', 'created_at']);

        $totals = [];
        foreach ($rows as $row) {
            $hour = (int) ($row->created_at?->format('G') ?? 0);
            $totals[$hour] = ($totals[$hour] ?? 0) + (float) $row->commission_net;
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    public static function commissionToArray(AffiliateCommission $commission): array
    {
        return self::commissionToArrayForAffiliate($commission);
    }

    /**
     * @return array<string, mixed>
     */
    public static function commissionToArrayForAffiliate(AffiliateCommission $commission): array
    {
        $order = $commission->order;
        $meta = is_array($commission->metadata) ? $commission->metadata : [];
        $hideCustomer = self::shouldHideCustomerData($commission);

        $data = [
            'id' => $commission->id,
            'order_id' => $commission->order_id,
            'order_public_reference' => $order?->public_reference,
            'status' => $commission->status,
            'status_label' => self::statusLabel($commission->status),
            'sale_origin' => $commission->sale_origin,
            'sale_origin_label' => SaleOrigin::label($commission->sale_origin),
            'product_id' => $commission->product_id,
            'product_name' => $commission->product?->name,
            'product_type' => $commission->product?->type,
            'product_type_label' => Product::typeLabel($commission->product?->type),
            'product_image_url' => $commission->product?->image,
            'sale_gross' => (float) $commission->sale_gross,
            'commission_percent' => (float) $commission->commission_percent,
            'commission_gross' => (float) $commission->commission_gross,
            'commission_fee' => (float) $commission->commission_fee,
            'commission_net' => (float) $commission->commission_net,
            'payment_method' => $order?->payment_method,
            'payment_method_label' => $order?->paymentMethodDisplayLabel(),
            'producer_id' => $commission->producer_tenant_id,
            'producer_name' => $meta['producer_name'] ?? $commission->producer?->name,
            'affiliate_ref' => $commission->affiliate_ref,
            'affiliate_link' => $commission->affiliate_link,
            'created_at' => $commission->created_at?->toIso8601String(),
            'is_affiliate_commission' => true,
        ];

        if ($hideCustomer) {
            $data['customer_hidden'] = true;
        } else {
            $data['customer_name'] = $meta['customer_name'] ?? $order?->user?->name;
            $data['customer_email'] = $meta['customer_email'] ?? $order?->email ?? $order?->user?->email;
        }

        return $data;
    }

    public static function shouldHideCustomerData(AffiliateCommission $commission): bool
    {
        return (bool) ($commission->product?->affiliate_hide_customer_data ?? false);
    }

    public static function userHasApprovedEnrollments(int $userId): bool
    {
        return \App\Models\ProductAffiliateEnrollment::query()
            ->where('affiliate_user_id', $userId)
            ->where('status', \App\Models\ProductAffiliateEnrollment::STATUS_APPROVED)
            ->exists();
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            AffiliateCommission::STATUS_PENDING => 'Pendente',
            AffiliateCommission::STATUS_APPROVED => 'Aprovada',
            AffiliateCommission::STATUS_CANCELLED => 'Cancelada',
            AffiliateCommission::STATUS_REFUNDED => 'Estornada',
            default => $status,
        };
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function resolveDateRange(Request $request, string $period): array
    {
        if ($period === 'personalizado') {
            $from = $request->query('date_from');
            $to = $request->query('date_to');
            $start = $from ? Carbon::parse($from)->startOfDay() : null;
            $end = $to ? Carbon::parse($to)->endOfDay() : null;

            return [$start, $end];
        }

        return match ($period) {
            'hoje' => [now()->startOfDay(), now()->endOfDay()],
            'ontem' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            '7dias' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'mes' => [now()->startOfMonth(), now()->endOfMonth()],
            'ano' => [now()->startOfYear(), now()->endOfYear()],
            default => [null, null],
        };
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public static function producerFilterOptions(int $affiliateUserId): array
    {
        $ids = AffiliateCommission::query()
            ->forAffiliate($affiliateUserId)
            ->distinct()
            ->pluck('producer_tenant_id')
            ->filter(fn ($id) => (int) $id > 0)
            ->values();

        return User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public static function productFilterOptions(int $affiliateUserId): array
    {
        return AffiliateCommission::query()
            ->forAffiliate($affiliateUserId)
            ->with('product:id,name')
            ->get()
            ->pluck('product')
            ->filter()
            ->unique('id')
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->values()
            ->all();
    }

    private static function countAffiliateClicks(int $affiliateUserId, Request $request): int
    {
        $refs = \App\Models\ProductAffiliateEnrollment::query()
            ->where('affiliate_user_id', $affiliateUserId)
            ->whereNotNull('public_ref')
            ->pluck('public_ref')
            ->filter()
            ->values()
            ->all();

        if ($refs === []) {
            return 0;
        }

        $period = $request->query('period', 'total');
        if (! in_array($period, self::PERIODS, true)) {
            $period = 'total';
        }
        [$start, $end] = self::resolveDateRange($request, $period);

        $query = CheckoutSession::query()->whereIn('affiliate_ref', $refs);
        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        } elseif ($start) {
            $query->where('created_at', '>=', $start);
        } elseif ($end) {
            $query->where('created_at', '<=', $end);
        }

        return $query->count();
    }
}
