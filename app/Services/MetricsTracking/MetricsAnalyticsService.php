<?php

namespace App\Services\MetricsTracking;

use App\Models\MetricsDailyStat;
use App\Models\MetricsEvent;
use App\Models\MetricsSession;
use App\Models\Product;
use App\Models\User;
use App\Support\SqlDialect;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MetricsAnalyticsService
{
    private const PERIODS = [
        'hoje', 'ontem', '7dias', '30dias', 'mes', 'mes_anterior', 'personalizado',
    ];

    /**
     * @return array{0:?Carbon,1:?Carbon}
     */
    public function resolveDateRange(Request $request, string $period): array
    {
        $now = now();
        return match ($period) {
            'ontem' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            '7dias' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            '30dias' => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
            'mes' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'mes_anterior' => [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            'personalizado' => [
                $request->filled('date_from') ? Carbon::parse($request->query('date_from'))->startOfDay() : null,
                $request->filled('date_to') ? Carbon::parse($request->query('date_to'))->endOfDay() : null,
            ],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    public function normalizePeriod(string $period): string
    {
        return in_array($period, self::PERIODS, true) ? $period : 'hoje';
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersFromRequest(Request $request): array
    {
        return [
            'product_id' => $request->query('product_id'),
            'offer_id' => $request->integer('offer_id') ?: null,
            'affiliate_user_id' => $request->integer('affiliate_user_id') ?: null,
            'utm_source' => $request->query('utm_source'),
            'utm_medium' => $request->query('utm_medium'),
            'utm_campaign' => $request->query('utm_campaign'),
            'device_type' => $request->query('device_type'),
            'country' => $request->query('country'),
            'region' => $request->query('region'),
            'city' => $request->query('city'),
            'conversion_status' => $request->query('conversion_status'),
            'group_by' => in_array($request->query('group_by'), ['hour', 'day', 'week', 'month'], true)
                ? $request->query('group_by')
                : 'day',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function eventsQuery(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters = [], bool $platformScope = false): Builder
    {
        $q = MetricsEvent::query();
        if ($platformScope) {
            if ($tenantId !== null) {
                $q->where('tenant_id', $tenantId);
            }
        } else {
            $q->forTenant($tenantId);
        }
        if ($start && $end) {
            $q->whereBetween('occurred_at', [$start, $end]);
        } elseif ($start) {
            $q->where('occurred_at', '>=', $start);
        } elseif ($end) {
            $q->where('occurred_at', '<=', $end);
        }

        return $this->applyFilters($q, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function sessionsQuery(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters = [], bool $platformScope = false): Builder
    {
        $q = MetricsSession::query();
        if ($platformScope) {
            if ($tenantId !== null) {
                $q->where('tenant_id', $tenantId);
            }
        } else {
            $q->forTenant($tenantId);
        }
        if ($start && $end) {
            $q->whereBetween('first_touch_at', [$start, $end]);
        } elseif ($start) {
            $q->where('first_touch_at', '>=', $start);
        } elseif ($end) {
            $q->where('first_touch_at', '<=', $end);
        }

        return $this->applyFilters($q, $filters, true);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $q, array $filters, bool $sessionTable = false): Builder
    {
        if (array_key_exists('product_ids', $filters) && is_array($filters['product_ids'])) {
            // Escopo explícito de produtos (ex.: coprodutor). Lista vazia ou sentinel => zero resultados.
            $ids = array_values(array_filter(
                $filters['product_ids'],
                fn ($id) => $id !== null && $id !== '' && $id !== '__none__'
            ));
            if ($ids === []) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn('product_id', $ids);
            }
        } elseif (! empty($filters['product_id'])) {
            if ($filters['product_id'] === '__none__') {
                $q->whereRaw('1 = 0');
            } else {
                $q->where('product_id', $filters['product_id']);
            }
        }
        if (! empty($filters['offer_id'])) {
            $q->where('offer_id', $filters['offer_id']);
        }
        if (! empty($filters['affiliate_scope_user_id'])) {
            $uid = (int) $filters['affiliate_scope_user_id'];
            $refs = array_values(array_filter(
                is_array($filters['affiliate_refs'] ?? null) ? $filters['affiliate_refs'] : [],
                fn ($r) => is_string($r) && $r !== ''
            ));
            $q->where(function ($w) use ($uid, $refs) {
                $w->where('affiliate_user_id', $uid);
                if ($refs !== []) {
                    $w->orWhereIn('affiliate_ref', $refs);
                }
            });
        } elseif (! empty($filters['affiliate_user_id'])) {
            $q->where('affiliate_user_id', $filters['affiliate_user_id']);
        }
        if (! empty($filters['coproducer_user_id'])) {
            $q->where('coproducer_user_id', (int) $filters['coproducer_user_id']);
        }
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'device_type', 'country', 'region', 'city'] as $key) {
            if (! empty($filters[$key])) {
                $q->where($key, $filters[$key]);
            }
        }
        if (! $sessionTable && ! empty($filters['conversion_status'])) {
            $q->where('conversion_status', $filters['conversion_status']);
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters = [], bool $platformScope = false): array
    {
        $summary = null;
        if ($this->shouldUseDailyStats($filters, $start, $end)) {
            $fromDaily = $this->summaryFromDailyStats($tenantId, $start, $end, $filters, $platformScope);
            if ($fromDaily !== null) {
                $summary = $fromDaily;
            }
        }

        if ($summary === null) {
            $summary = $this->summaryFromLive($tenantId, $start, $end, $filters, $platformScope);
        }

        // Taxas por método usam properties/order ao vivo (daily stats não quebram por método).
        return $this->withPaymentMethodRates(
            $summary,
            $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope)
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function summaryFromLive(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters = [], bool $platformScope = false): array
    {
        $sessions = $this->sessionsQuery($tenantId, $start, $end, $filters, $platformScope);
        $events = $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope);

        $uniqueVisitors = $this->distinctVisitorCount($events);
        $sessionsCount = (int) (clone $events)->whereNotNull('session_key')->distinct()->count('session_key');
        if ($sessionsCount === 0) {
            $sessionsCount = (clone $sessions)->count();
        }
        if ($uniqueVisitors === 0) {
            $uniqueVisitors = (int) (clone $sessions)->whereNotNull('visitor_key')->distinct()->count('visitor_key');
        }

        $clicks = (clone $events)->whereIn('event_name', MetricsEvent::clickEventNames())->count();
        $checkoutViews = (clone $events)->where('event_name', MetricsEvent::CHECKOUT_VIEW)->count();
        $checkoutsFormStarted = (clone $events)->where('event_name', MetricsEvent::CHECKOUT_FORM_STARTED)->count();
        $checkoutsStarted = (clone $events)->where('event_name', MetricsEvent::CHECKOUT_STARTED)->count();
        $pixCreated = (clone $events)->where('event_name', MetricsEvent::PIX_CREATED)->count();
        $paymentsInitiated = (clone $events)->whereIn('event_name', MetricsEvent::paymentInitiatedEventNames())->count();
        $approved = (clone $events)->where('event_name', MetricsEvent::PAYMENT_APPROVED)->count();
        $refunded = (clone $events)->where('event_name', MetricsEvent::PAYMENT_REFUNDED)->count();
        $refused = (clone $events)->where('event_name', MetricsEvent::PAYMENT_REFUSED)->count();

        $gross = (float) (clone $events)->where('event_name', MetricsEvent::PAYMENT_APPROVED)->sum('amount');
        $refundAmount = (float) (clone $events)->where('event_name', MetricsEvent::PAYMENT_REFUNDED)->sum('amount');
        $net = max(0, $gross - $refundAmount);

        $avgSeconds = (float) (clone $events)
            ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
            ->whereNotNull('seconds_to_convert')
            ->avg('seconds_to_convert');

        return $this->formatSummary(
            $uniqueVisitors,
            $sessionsCount,
            $clicks,
            $checkoutViews,
            $checkoutsStarted,
            $pixCreated,
            $approved,
            $refused,
            $refunded,
            $gross,
            $net,
            $avgSeconds,
            $checkoutsFormStarted,
            $paymentsInitiated,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    private function summaryFromDailyStats(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters, bool $platformScope): ?array
    {
        if (! $start || ! $end) {
            return null;
        }

        $today = now()->startOfDay();
        $rangeStart = $start->copy()->startOfDay();
        $rangeEnd = $end->copy()->startOfDay();
        $closedEnd = $rangeEnd->lt($today) ? $rangeEnd->copy() : $today->copy()->subDay();

        $daily = $this->emptyMetricBag();
        $hasDaily = false;

        if ($rangeStart->lte($closedEnd)) {
            $rows = $this->dailyStatsQuery($tenantId, $rangeStart, $closedEnd, $filters, $platformScope)->get();
            if ($rows->isEmpty() && $rangeStart->diffInDays($closedEnd) >= 0) {
                // Sem cobertura → fallback live completo
                return null;
            }
            $expectedDays = $rangeStart->diffInDays($closedEnd) + 1;
            $coveredDays = $rows->pluck('stat_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->count();
            // Exige cobertura razoável (≥80%) para usar agregados
            if ($expectedDays > 0 && $coveredDays / $expectedDays < 0.8) {
                return null;
            }
            $hasDaily = $rows->isNotEmpty();
            foreach ($rows as $r) {
                $daily['unique_visitors'] += (int) $r->unique_visitors;
                $daily['sessions'] += (int) $r->sessions;
                $daily['clicks'] += (int) $r->clicks;
                $daily['checkout_views'] += (int) $r->checkout_views;
                $daily['checkouts_form_started'] += (int) ($r->checkouts_form_started ?? 0);
                $daily['checkouts_started'] += (int) $r->checkouts_started;
                $daily['pix_created'] += (int) $r->pix_created;
                $daily['payments_initiated'] += (int) ($r->payments_initiated ?? 0);
                $daily['conversions_approved'] += (int) $r->payments_approved;
                $daily['payments_refused'] += (int) $r->payments_refused;
                $daily['refunds'] += (int) $r->refunds;
                $daily['gross_revenue'] += (float) $r->gross_revenue;
                $daily['net_revenue'] += (float) $r->net_revenue;
                $daily['seconds_sum'] += (int) $r->seconds_to_convert_sum;
                $daily['seconds_count'] += (int) $r->seconds_to_convert_count;
            }
        }

        $liveStart = $hasDaily ? $closedEnd->copy()->addDay()->startOfDay() : $start->copy();
        $liveEnd = $end->copy();
        $needLive = $liveStart->lte($liveEnd) && ($liveStart->gte($today) || ! $hasDaily);

        // Sempre recalcular visitantes únicos no intervalo (soma diária superestima em multi-dia)
        $multiDay = $rangeStart->toDateString() !== $rangeEnd->toDateString();
        $liveUnique = null;
        if ($multiDay || $needLive) {
            $liveUnique = $this->distinctVisitorCount(
                $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope)
            );
        }

        if ($needLive && $hasDaily) {
            $live = $this->summaryFromLive($tenantId, $liveStart, $liveEnd, $filters, $platformScope);
            $merged = [
                'unique_visitors' => $liveUnique ?? ((int) $daily['unique_visitors'] + (int) $live['unique_visitors']),
                'sessions' => $daily['sessions'] + $live['sessions'],
                'clicks' => $daily['clicks'] + $live['clicks'],
                'checkout_views' => $daily['checkout_views'] + $live['checkout_views'],
                'checkouts_form_started' => $daily['checkouts_form_started'] + (int) ($live['checkouts_form_started'] ?? 0),
                'checkouts_started' => $daily['checkouts_started'] + $live['checkouts_started'],
                'pix_created' => $daily['pix_created'] + $live['pix_created'],
                'payments_initiated' => $daily['payments_initiated'] + (int) ($live['payments_initiated'] ?? 0),
                'conversions_approved' => $daily['conversions_approved'] + $live['conversions_approved'],
                'payments_refused' => $daily['payments_refused'] + $live['payments_refused'],
                'refunds' => $daily['refunds'] + $live['refunds'],
                'gross_revenue' => $daily['gross_revenue'] + $live['gross_revenue'],
                'net_revenue' => $daily['net_revenue'] + $live['net_revenue'],
            ];
            $secSum = $daily['seconds_sum'];
            $secCount = $daily['seconds_count'];
            if ($live['avg_seconds_to_convert'] > 0 && $live['conversions_approved'] > 0) {
                // aproximação: peso pelas conversões live restantes não está no daily; usa média composta
                $approxLiveSum = (int) $live['avg_seconds_to_convert'] * (int) $live['conversions_approved'];
                $secSum += $approxLiveSum;
                $secCount += (int) $live['conversions_approved'];
            }
            $avgSeconds = $secCount > 0 ? $secSum / $secCount : 0.0;

            return $this->formatSummary(
                $merged['unique_visitors'],
                $merged['sessions'],
                $merged['clicks'],
                $merged['checkout_views'],
                $merged['checkouts_started'],
                $merged['pix_created'],
                $merged['conversions_approved'],
                $merged['payments_refused'],
                $merged['refunds'],
                $merged['gross_revenue'],
                $merged['net_revenue'],
                $avgSeconds,
                $merged['checkouts_form_started'],
                $merged['payments_initiated'],
            );
        }

        if (! $hasDaily) {
            return null;
        }

        $avgSeconds = $daily['seconds_count'] > 0 ? $daily['seconds_sum'] / $daily['seconds_count'] : 0.0;

        return $this->formatSummary(
            $liveUnique ?? $daily['unique_visitors'],
            $daily['sessions'],
            $daily['clicks'],
            $daily['checkout_views'],
            $daily['checkouts_started'],
            $daily['pix_created'],
            $daily['conversions_approved'],
            $daily['payments_refused'],
            $daily['refunds'],
            $daily['gross_revenue'],
            $daily['net_revenue'],
            $avgSeconds,
            $daily['checkouts_form_started'],
            $daily['payments_initiated'],
        );
    }

    /**
     * @return array<string, int|float>
     */
    private function emptyMetricBag(): array
    {
        return [
            'unique_visitors' => 0,
            'sessions' => 0,
            'clicks' => 0,
            'checkout_views' => 0,
            'checkouts_form_started' => 0,
            'checkouts_started' => 0,
            'pix_created' => 0,
            'payments_initiated' => 0,
            'conversions_approved' => 0,
            'payments_refused' => 0,
            'refunds' => 0,
            'gross_revenue' => 0.0,
            'net_revenue' => 0.0,
            'seconds_sum' => 0,
            'seconds_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSummary(
        int $uniqueVisitors,
        int $sessionsCount,
        int $clicks,
        int $checkoutViews,
        int $checkoutsStarted,
        int $pixCreated,
        int $approved,
        int $refused,
        int $refunded,
        float $gross,
        float $net,
        float $avgSeconds,
        int $checkoutsFormStarted = 0,
        int $paymentsInitiated = 0,
    ): array {
        $conversionRate = $uniqueVisitors > 0 ? round(($approved / $uniqueVisitors) * 100, 2) : 0.0;
        $ticket = $approved > 0 ? round($gross / $approved, 2) : 0.0;
        $revPerVisitor = $uniqueVisitors > 0 ? round($gross / $uniqueVisitors, 2) : 0.0;
        $revPerClick = $clicks > 0 ? round($gross / $clicks, 2) : 0.0;

        return [
            'unique_visitors' => $uniqueVisitors,
            'sessions' => $sessionsCount,
            'clicks' => $clicks,
            'checkout_views' => $checkoutViews,
            'checkouts_form_started' => $checkoutsFormStarted,
            'checkouts_started' => $checkoutsStarted,
            'pix_created' => $pixCreated,
            'payments_initiated' => $paymentsInitiated,
            'conversions_approved' => $approved,
            'payments_refused' => $refused,
            'refunds' => $refunded,
            'conversion_rate' => $conversionRate,
            'gross_revenue' => round($gross, 2),
            'net_revenue' => round($net, 2),
            'avg_ticket' => $ticket,
            'avg_seconds_to_convert' => (int) round($avgSeconds),
            'revenue_per_visitor' => $revPerVisitor,
            'revenue_per_click' => $revPerClick,
            'pix_paid' => 0,
            'pix_conversion_rate' => 0.0,
            'card_approved' => 0,
            'card_refused' => 0,
            'card_attempts' => 0,
            'card_approval_rate' => 0.0,
        ];
    }

    /**
     * Anexa conversão PIX (pagos / gerados) e aprovação de cartão (aprovados / tentativas).
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function withPaymentMethodRates(array $summary, Builder $events): array
    {
        $pixCreated = (int) ($summary['pix_created'] ?? 0);
        $pixPaid = $this->countEventsByPaymentBucket($events, MetricsEvent::PAYMENT_APPROVED, 'pix');
        $cardApproved = $this->countEventsByPaymentBucket($events, MetricsEvent::PAYMENT_APPROVED, 'card');
        $cardRefused = $this->countEventsByPaymentBucket($events, MetricsEvent::PAYMENT_REFUSED, 'card');
        $cardAttempts = $cardApproved + $cardRefused;

        $summary['pix_paid'] = $pixPaid;
        $summary['pix_conversion_rate'] = $pixCreated > 0
            ? round(($pixPaid / $pixCreated) * 100, 2)
            : 0.0;
        $summary['card_approved'] = $cardApproved;
        $summary['card_refused'] = $cardRefused;
        $summary['card_attempts'] = $cardAttempts;
        $summary['card_approval_rate'] = $cardAttempts > 0
            ? round(($cardApproved / $cardAttempts) * 100, 2)
            : 0.0;

        return $summary;
    }

    /**
     * Conta eventos filtrando pelo método em properties JSON ou na order relacionada.
     *
     * @param  'pix'|'card'  $bucket
     */
    private function countEventsByPaymentBucket(Builder $events, string $eventName, string $bucket): int
    {
        $methods = $this->paymentMethodsForBucket($bucket);
        if ($methods === []) {
            return 0;
        }

        return (int) (clone $events)
            ->where('event_name', $eventName)
            ->where(function ($outer) use ($methods) {
                $outer->where(function ($pq) use ($methods) {
                    foreach ($methods as $method) {
                        $pq->orWhere('properties->payment_method', $method);
                    }
                })->orWhereHas('order', function ($oq) use ($methods) {
                    $oq->where(function ($inner) use ($methods) {
                        foreach ($methods as $method) {
                            $inner->orWhereRaw('LOWER(payment_method) = ?', [$method]);
                        }
                    });
                });
            })
            ->count();
    }

    /**
     * @param  'pix'|'card'  $bucket
     * @return list<string>
     */
    private function paymentMethodsForBucket(string $bucket): array
    {
        return match ($bucket) {
            'pix' => ['pix', 'pix_auto'],
            'card' => ['card', 'credit_card', 'creditcard', 'apple_pay', 'google_pay'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function shouldUseDailyStats(array $filters, ?Carbon $start, ?Carbon $end): bool
    {
        if (! config('metrics_tracking.daily_stats_enabled', true)) {
            return false;
        }
        if (! config('metrics_tracking.prefer_daily_stats', true)) {
            return false;
        }
        if (! $start || ! $end) {
            return false;
        }

        $advanced = ['offer_id', 'affiliate_user_id', 'affiliate_scope_user_id', 'affiliate_refs', 'coproducer_user_id', 'product_ids', 'utm_source', 'utm_medium', 'utm_campaign', 'device_type', 'country', 'region', 'city', 'conversion_status'];
        foreach ($advanced as $key) {
            if (! empty($filters[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function dailyStatsQuery(?int $tenantId, Carbon $from, Carbon $to, array $filters, bool $platformScope): Builder
    {
        $q = MetricsDailyStat::query()
            ->whereBetween('stat_date', [$from->toDateString(), $to->toDateString()]);

        if (! empty($filters['product_id'])) {
            $q->where('dimension', 'product')
                ->where('product_id', $filters['product_id']);
        } else {
            $q->where('dimension', 'total')->whereNull('product_id');
        }

        if ($platformScope) {
            if ($tenantId !== null) {
                $q->where('tenant_id', $tenantId);
            }
        } else {
            if ($tenantId === null) {
                $q->whereNull('tenant_id');
            } else {
                $q->where('tenant_id', $tenantId);
            }
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function timeseries(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters = [], bool $platformScope = false): array
    {
        $filters = $this->applyTimeseriesGroupBy($filters, $start, $end);
        $groupBy = $filters['group_by'] ?? 'day';
        if ($groupBy === 'day' && $this->shouldUseDailyStats($filters, $start, $end)) {
            $fromDaily = $this->timeseriesFromDailyStats($tenantId, $start, $end, $filters, $platformScope);
            if ($fromDaily !== null) {
                return $fromDaily;
            }
        }

        $rows = $this->timeseriesFromLive($tenantId, $start, $end, $filters, $platformScope);

        if ($groupBy === 'hour' && $this->isSingleDayRange($start, $end)) {
            return $this->fillHourlyBuckets($rows);
        }

        return $rows;
    }

    /**
     * Hoje/ontem (e qualquer intervalo de 1 dia civil) agrupam por hora para o gráfico ter 24 pontos.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function applyTimeseriesGroupBy(array $filters, ?Carbon $start, ?Carbon $end): array
    {
        $groupBy = $filters['group_by'] ?? 'day';
        if ($groupBy === 'day' && $this->isSingleDayRange($start, $end)) {
            $filters['group_by'] = 'hour';
        }

        return $filters;
    }

    private function isSingleDayRange(?Carbon $start, ?Carbon $end): bool
    {
        if (! $start || ! $end) {
            return false;
        }

        $tz = (string) config('app.timezone', 'America/Sao_Paulo');

        return $start->copy()->timezone($tz)->toDateString()
            === $end->copy()->timezone($tz)->toDateString();
    }

    /**
     * Completa 0h–23h para o gráfico de um único dia não colapsar em 1 ponto.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function fillHourlyBuckets(array $rows): array
    {
        $byHour = [];
        foreach ($rows as $row) {
            $hour = $this->parseHourBucket((string) ($row['bucket'] ?? ''));
            if ($hour === null) {
                continue;
            }
            $byHour[$hour] = $row;
        }

        $filled = [];
        for ($h = 0; $h <= 23; $h++) {
            $row = $byHour[$h] ?? [
                'visitors' => 0,
                'clicks' => 0,
                'conversions' => 0,
                'revenue' => 0.0,
                'pix_created' => 0,
            ];
            $visitors = (int) ($row['visitors'] ?? 0);
            $conversions = (int) ($row['conversions'] ?? 0);
            $filled[] = [
                'bucket' => $h.'h',
                'visitors' => $visitors,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'conversions' => $conversions,
                'revenue' => round((float) ($row['revenue'] ?? 0), 2),
                'pix_created' => (int) ($row['pix_created'] ?? 0),
                'conversion_rate' => $visitors > 0
                    ? round(($conversions / $visitors) * 100, 2)
                    : 0.0,
            ];
        }

        return $filled;
    }

    private function parseHourBucket(string $bucket): ?int
    {
        $bucket = trim($bucket);
        if (preg_match('/\b(\d{1,2})h\b/i', $bucket, $m) === 1) {
            $hour = (int) $m[1];

            return ($hour >= 0 && $hour <= 23) ? $hour : null;
        }
        if (preg_match('/\s(\d{1,2}):/', $bucket, $m) === 1) {
            $hour = (int) $m[1];

            return ($hour >= 0 && $hour <= 23) ? $hour : null;
        }
        if (preg_match('/^\d{1,2}$/', $bucket) === 1) {
            $hour = (int) $bucket;

            return ($hour >= 0 && $hour <= 23) ? $hour : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>|null
     */
    private function timeseriesFromDailyStats(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters, bool $platformScope): ?array
    {
        if (! $start || ! $end) {
            return null;
        }

        $today = now()->startOfDay();
        $rangeStart = $start->copy()->startOfDay();
        $rangeEnd = $end->copy()->startOfDay();
        $closedEnd = $rangeEnd->lt($today) ? $rangeEnd->copy() : $today->copy()->subDay();

        $map = [];

        if ($rangeStart->lte($closedEnd)) {
            $rows = $this->dailyStatsQuery($tenantId, $rangeStart, $closedEnd, $filters, $platformScope)
                ->orderBy('stat_date')
                ->get();

            if ($rows->isEmpty()) {
                return null;
            }

            foreach ($rows as $r) {
                $b = Carbon::parse($r->stat_date)->toDateString();
                if (! isset($map[$b])) {
                    $map[$b] = [
                        'bucket' => $b,
                        'visitors' => 0,
                        'clicks' => 0,
                        'conversions' => 0,
                        'revenue' => 0.0,
                        'pix_created' => 0,
                    ];
                }
                $map[$b]['visitors'] += (int) $r->unique_visitors;
                $map[$b]['clicks'] += (int) $r->clicks;
                $map[$b]['conversions'] += (int) $r->payments_approved;
                $map[$b]['revenue'] += (float) $r->gross_revenue;
                $map[$b]['pix_created'] += (int) $r->pix_created;
            }
        }

        $liveStart = ! empty($map) ? $closedEnd->copy()->addDay()->startOfDay() : $start->copy();
        if ($liveStart->lte($end)) {
            $live = $this->timeseriesFromLive($tenantId, $liveStart, $end, array_merge($filters, ['group_by' => 'day']), $platformScope);
            foreach ($live as $row) {
                $map[$row['bucket']] = $row;
            }
        }

        if ($map === []) {
            return null;
        }

        ksort($map);

        return array_values(array_map(function (array $row) {
            $row['conversion_rate'] = $row['visitors'] > 0
                ? round(($row['conversions'] / $row['visitors']) * 100, 2)
                : 0.0;
            $row['revenue'] = round((float) $row['revenue'], 2);

            return $row;
        }, $map));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function timeseriesFromLive(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters = [], bool $platformScope = false): array
    {
        $groupBy = $filters['group_by'] ?? 'day';
        $driver = DB::connection()->getDriverName();
        $col = match ($groupBy) {
            'hour' => SqlDialect::hourExpression('occurred_at'),
            'week' => $driver === 'pgsql'
                ? "to_char(date_trunc('week', occurred_at), 'YYYY-MM-DD')"
                : "DATE_FORMAT(DATE_SUB(occurred_at, INTERVAL WEEKDAY(occurred_at) DAY), '%Y-%m-%d')",
            'month' => $driver === 'pgsql'
                ? "to_char(occurred_at, 'YYYY-MM')"
                : "DATE_FORMAT(occurred_at, '%Y-%m')",
            default => SqlDialect::dateExpression('occurred_at'),
        };

        $raw = $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope)
            ->selectRaw("{$col} as bucket")
            ->selectRaw('event_name')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(amount),0) as revenue')
            ->groupBy('bucket', 'event_name')
            ->orderBy('bucket')
            ->get();

        $map = [];
        foreach ($raw as $row) {
            $b = (string) $row->bucket;
            if (! isset($map[$b])) {
                $map[$b] = [
                    'bucket' => $b,
                    'visitors' => 0,
                    'clicks' => 0,
                    'conversions' => 0,
                    'revenue' => 0.0,
                    'pix_created' => 0,
                ];
            }
            $name = (string) $row->event_name;
            $total = (int) $row->total;
            if (in_array($name, MetricsEvent::clickEventNames(), true)) {
                $map[$b]['clicks'] += $total;
            }
            if ($name === MetricsEvent::PAYMENT_APPROVED) {
                $map[$b]['conversions'] += $total;
                $map[$b]['revenue'] += (float) $row->revenue;
            }
            if ($name === MetricsEvent::PIX_CREATED) {
                $map[$b]['pix_created'] += $total;
            }
        }

        $visitorRows = $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope)
            ->selectRaw("{$col} as bucket")
            ->selectRaw('COUNT(DISTINCT visitor_key) as visitors')
            ->whereNotNull('visitor_key')
            ->groupBy('bucket')
            ->get();

        foreach ($visitorRows as $row) {
            $b = (string) $row->bucket;
            if (! isset($map[$b])) {
                $map[$b] = [
                    'bucket' => $b,
                    'visitors' => 0,
                    'clicks' => 0,
                    'conversions' => 0,
                    'revenue' => 0.0,
                    'pix_created' => 0,
                ];
            }
            $map[$b]['visitors'] = (int) $row->visitors;
        }

        ksort($map);

        return array_values(array_map(function (array $row) {
            $row['conversion_rate'] = $row['visitors'] > 0
                ? round(($row['conversions'] / $row['visitors']) * 100, 2)
                : 0.0;
            $row['revenue'] = round((float) $row['revenue'], 2);

            return $row;
        }, $map));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int|float>
     */
    public function funnel(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters = [], bool $platformScope = false): array
    {
        $events = $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope);

        $visitors = $this->distinctVisitorCount($events);
        $approvedUnique = $this->distinctVisitorCount($events, [MetricsEvent::PAYMENT_APPROVED]);

        $steps = [
            ['key' => 'visitors', 'label' => 'Visitantes', 'value' => $visitors],
            ['key' => 'checkout_views', 'label' => 'Visualizações de checkout', 'value' => $this->distinctVisitorCount($events, [MetricsEvent::CHECKOUT_VIEW])],
            ['key' => 'checkouts_form_started', 'label' => 'Checkouts iniciados', 'value' => $this->distinctVisitorCount($events, [MetricsEvent::CHECKOUT_FORM_STARTED])],
            ['key' => 'checkouts_started', 'label' => 'Pedidos submetidos', 'value' => $this->distinctVisitorCount($events, [MetricsEvent::CHECKOUT_STARTED])],
            ['key' => 'payments_initiated', 'label' => 'Pagamentos iniciados', 'value' => $this->distinctVisitorCount($events, MetricsEvent::paymentInitiatedEventNames())],
            ['key' => 'pix_created', 'label' => 'PIX gerados', 'value' => $this->distinctVisitorCount($events, [MetricsEvent::PIX_CREATED])],
            ['key' => 'approved', 'label' => 'Pagamentos aprovados', 'value' => $approvedUnique],
        ];

        $out = [];
        $prev = null;
        foreach ($steps as $step) {
            $drop = null;
            if ($prev !== null && $prev > 0) {
                $drop = round((1 - ($step['value'] / $prev)) * 100, 2);
            }
            $out[] = [
                ...$step,
                'percent_of_first' => $steps[0]['value'] > 0
                    ? round(($step['value'] / $steps[0]['value']) * 100, 2)
                    : 0.0,
                'dropoff_percent' => $drop,
            ];
            $prev = $step['value'];
        }

        return [
            'steps' => $out,
            'final_conversion_rate' => $visitors > 0
                ? round(($approvedUnique / $visitors) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * @param  list<string>|null  $eventNames
     */
    private function distinctVisitorCount(Builder $events, ?array $eventNames = null): int
    {
        $q = clone $events;
        if ($eventNames !== null) {
            $q->whereIn('event_name', $eventNames);
        }

        return (int) $q->whereNotNull('visitor_key')->distinct()->count('visitor_key');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function breakdown(?int $tenantId, ?Carbon $start, ?Carbon $end, string $dimension, array $filters = [], bool $platformScope = false): array
    {
        $allowed = [
            'utm_source' => 'utm_source',
            'utm_medium' => 'utm_medium',
            'utm_campaign' => 'utm_campaign',
            'utm_content' => 'utm_content',
            'utm_term' => 'utm_term',
            'referrer' => 'referrer',
            'product_id' => 'product_id',
            'device_type' => 'device_type',
            'browser_name' => 'browser_name',
            'os_name' => 'os_name',
            'country' => 'country',
            'region' => 'region',
            'city' => 'city',
            'affiliate_user_id' => 'affiliate_user_id',
            'affiliate_ref' => 'affiliate_ref',
            'campaign_code' => 'campaign_code',
            'tenant_id' => 'tenant_id',
        ];
        if (! isset($allowed[$dimension])) {
            $dimension = 'utm_source';
        }
        $col = $allowed[$dimension];
        $driver = DB::connection()->getDriverName();
        $dimExpr = $driver === 'pgsql'
            ? "COALESCE({$col}::text, '(direto)')"
            : "COALESCE(CAST({$col} AS CHAR), '(direto)')";

        $sessionRows = $this->sessionsQuery($tenantId, $start, $end, $filters, $platformScope)
            ->selectRaw("{$dimExpr} as dim")
            ->selectRaw('COUNT(DISTINCT visitor_key) as visitors')
            ->selectRaw('COUNT(*) as sessions')
            ->groupBy('dim')
            ->get()
            ->keyBy('dim');

        $clickPlaceholders = implode(',', array_fill(0, count(MetricsEvent::clickEventNames()), '?'));
        $eventRows = $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope)
            ->selectRaw("{$dimExpr} as dim")
            ->selectRaw('COUNT(DISTINCT visitor_key) as visitors')
            ->selectRaw("SUM(CASE WHEN event_name IN ({$clickPlaceholders}) THEN 1 ELSE 0 END) as clicks", MetricsEvent::clickEventNames())
            ->selectRaw('SUM(CASE WHEN event_name = ? THEN 1 ELSE 0 END) as checkouts_form_started', [MetricsEvent::CHECKOUT_FORM_STARTED])
            ->selectRaw('SUM(CASE WHEN event_name = ? THEN 1 ELSE 0 END) as checkouts_started', [MetricsEvent::CHECKOUT_STARTED])
            ->selectRaw('SUM(CASE WHEN event_name = ? THEN 1 ELSE 0 END) as pix_created', [MetricsEvent::PIX_CREATED])
            ->selectRaw('SUM(CASE WHEN event_name = ? THEN 1 ELSE 0 END) as approved', [MetricsEvent::PAYMENT_APPROVED])
            ->selectRaw('SUM(CASE WHEN event_name = ? THEN COALESCE(amount,0) ELSE 0 END) as revenue', [MetricsEvent::PAYMENT_APPROVED])
            ->groupBy('dim')
            ->get()
            ->keyBy('dim');

        $keys = collect($sessionRows->keys())->merge($eventRows->keys())->unique()->values();
        $productNames = [];
        if ($dimension === 'product_id') {
            $ids = $keys->filter(fn ($k) => $k !== '(direto)')->values()->all();
            if ($ids) {
                $productNames = Product::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
            }
        }

        $sellerNames = [];
        if ($dimension === 'tenant_id') {
            $ids = $keys->filter(fn ($k) => $k !== '(direto)' && is_numeric($k))->map(fn ($k) => (int) $k)->values()->all();
            if ($ids) {
                $sellerNames = User::query()->whereIn('id', $ids)->get(['id', 'name', 'email'])
                    ->mapWithKeys(fn ($u) => [$u->id => ($u->name ?: $u->email).' #'.$u->id])
                    ->all();
            }
        }

        $out = [];
        foreach ($keys as $key) {
            $s = $sessionRows->get($key);
            $e = $eventRows->get($key);
            $visitors = (int) ($e?->visitors ?? $s?->visitors ?? 0);
            $clicks = (int) ($e?->clicks ?? 0);
            $approved = (int) ($e?->approved ?? 0);
            $revenue = (float) ($e?->revenue ?? 0);
            $label = (string) $key;
            if ($dimension === 'product_id' && isset($productNames[$key])) {
                $label = (string) $productNames[$key];
            }
            if ($dimension === 'tenant_id' && isset($sellerNames[(int) $key])) {
                $label = (string) $sellerNames[(int) $key];
            }
            $out[] = [
                'key' => (string) $key,
                'label' => $label,
                'visitors' => $visitors,
                'clicks' => $clicks,
                'checkouts_started' => (int) ($e?->checkouts_started ?? 0),
                'pix_created' => (int) ($e?->pix_created ?? 0),
                'approved' => $approved,
                'conversion_rate' => $visitors > 0 ? round(($approved / $visitors) * 100, 2) : 0.0,
                'revenue' => round($revenue, 2),
                'avg_ticket' => $approved > 0 ? round($revenue / $approved, 2) : 0.0,
                'revenue_per_click' => $clicks > 0 ? round($revenue / $clicks, 2) : 0.0,
            ];
        }

        usort($out, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function distribution(?int $tenantId, ?Carbon $start, ?Carbon $end, string $field, array $filters = [], bool $platformScope = false): array
    {
        $allowed = ['device_type', 'browser_name', 'os_name', 'country', 'region', 'city'];
        if (! in_array($field, $allowed, true)) {
            $field = 'device_type';
        }

        return $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope)
            ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
            ->selectRaw("COALESCE({$field}, '—') as label")
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(amount),0) as revenue')
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'label' => (string) $r->label,
                'total' => (int) $r->total,
                'revenue' => round((float) $r->revenue, 2),
            ])
            ->all();
    }

    /**
     * Pontos de mapa (lat/lng) + rankings regionais.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *   points: list<array<string,mixed>>,
     *   by_city: list<array<string,mixed>>,
     *   by_region: list<array<string,mixed>>,
     *   by_country: list<array<string,mixed>>,
     *   totals: array{with_coords:int,without_coords:int,conversions:int,revenue:float}
     * }
     */
    public function geoMap(?int $tenantId, ?Carbon $start, ?Carbon $end, array $filters = [], bool $platformScope = false): array
    {
        $base = $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope);

        $withCoords = (clone $base)->whereNotNull('latitude')->whereNotNull('longitude')->count();
        $withoutCoords = (clone $base)->where(function ($q) {
            $q->whereNull('latitude')->orWhereNull('longitude');
        })->count();

        $geoEvents = (clone $base)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderByDesc('occurred_at')
            ->limit(25000)
            ->get([
                'latitude',
                'longitude',
                'city',
                'region',
                'country',
                'event_name',
                'amount',
            ]);

        $buckets = [];
        foreach ($geoEvents as $e) {
            $lat = round((float) $e->latitude, 2);
            $lng = round((float) $e->longitude, 2);
            $key = $lat.'|'.$lng;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'lat' => $lat,
                    'lng' => $lng,
                    'city' => $e->city ?: null,
                    'region' => $e->region ?: null,
                    'country' => $e->country ?: null,
                    'events' => 0,
                    'conversions' => 0,
                    'revenue' => 0.0,
                ];
            }
            $buckets[$key]['events']++;
            if ($e->event_name === MetricsEvent::PAYMENT_APPROVED) {
                $buckets[$key]['conversions']++;
                $buckets[$key]['revenue'] += (float) ($e->amount ?? 0);
            }
            if (! $buckets[$key]['city'] && $e->city) {
                $buckets[$key]['city'] = $e->city;
            }
            if (! $buckets[$key]['region'] && $e->region) {
                $buckets[$key]['region'] = $e->region;
            }
            if (! $buckets[$key]['country'] && $e->country) {
                $buckets[$key]['country'] = $e->country;
            }
        }

        $points = array_values(array_map(function (array $p) {
            $parts = array_filter([$p['city'], $p['region'], $p['country']]);

            return [
                ...$p,
                'revenue' => round($p['revenue'], 2),
                'label' => $parts ? implode(', ', $parts) : sprintf('%.2f, %.2f', $p['lat'], $p['lng']),
            ];
        }, $buckets));

        usort($points, function ($a, $b) {
            if ($b['conversions'] !== $a['conversions']) {
                return $b['conversions'] <=> $a['conversions'];
            }

            return $b['events'] <=> $a['events'];
        });

        $points = array_slice($points, 0, 500);

        $byCity = $this->geoRank($tenantId, $start, $end, $filters, $platformScope, 'city');
        $byRegion = $this->geoRank($tenantId, $start, $end, $filters, $platformScope, 'region');
        $byCountry = $this->geoRank($tenantId, $start, $end, $filters, $platformScope, 'country');

        $conversionStats = $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope)
            ->where('event_name', MetricsEvent::PAYMENT_APPROVED)
            ->selectRaw('COUNT(*) as conversions')
            ->selectRaw('COALESCE(SUM(amount),0) as revenue')
            ->first();

        return [
            'points' => $points,
            'by_city' => $byCity,
            'by_region' => $byRegion,
            'by_country' => $byCountry,
            'totals' => [
                'with_coords' => (int) $withCoords,
                'without_coords' => (int) $withoutCoords,
                'conversions' => (int) ($conversionStats->conversions ?? 0),
                'revenue' => round((float) ($conversionStats->revenue ?? 0), 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function geoRank(
        ?int $tenantId,
        ?Carbon $start,
        ?Carbon $end,
        array $filters,
        bool $platformScope,
        string $field,
    ): array {
        $allowed = ['city', 'region', 'country'];
        if (! in_array($field, $allowed, true)) {
            $field = 'city';
        }

        $sessions = $this->sessionsQuery($tenantId, $start, $end, $filters, $platformScope)
            ->selectRaw("COALESCE({$field}, '—') as label")
            ->selectRaw('COUNT(DISTINCT visitor_key) as visitors')
            ->groupBy('label')
            ->pluck('visitors', 'label');

        $events = $this->eventsQuery($tenantId, $start, $end, $filters, $platformScope)
            ->selectRaw("COALESCE({$field}, '—') as label")
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('SUM(CASE WHEN event_name = ? THEN 1 ELSE 0 END) as conversions', [MetricsEvent::PAYMENT_APPROVED])
            ->selectRaw('COALESCE(SUM(CASE WHEN event_name = ? THEN amount ELSE 0 END),0) as revenue', [MetricsEvent::PAYMENT_APPROVED])
            ->groupBy('label')
            ->get()
            ->keyBy('label');

        $labels = collect($sessions->keys())->merge($events->keys())->unique();
        $out = [];
        foreach ($labels as $label) {
            $row = $events->get($label);
            $visitors = (int) ($sessions[$label] ?? 0);
            $conversions = (int) ($row->conversions ?? 0);
            $out[] = [
                'label' => (string) $label,
                'visitors' => $visitors,
                'events' => (int) ($row->events ?? 0),
                'conversions' => $conversions,
                'revenue' => round((float) ($row->revenue ?? 0), 2),
                'conversion_rate' => $visitors > 0 ? round(($conversions / $visitors) * 100, 2) : 0.0,
            ];
        }

        usort($out, fn ($a, $b) => $b['revenue'] <=> $a['revenue'] ?: $b['conversions'] <=> $a['conversions']);

        return array_slice($out, 0, 30);
    }
}
