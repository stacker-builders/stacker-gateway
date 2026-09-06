<?php

namespace App\Http\Controllers;

use App\Models\MetricsEvent;
use App\Models\Product;
use App\Models\ProductCoproducer;
use App\Services\MetricsTracking\MetricsAnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CoproducerMetricsTrackingController extends Controller
{
    private const BASE_PATH = '/produtos/coproducao/metricas';

    public function __construct(
        private readonly MetricsAnalyticsService $analytics,
    ) {}

    public function index(Request $request): Response
    {
        [$filters, $start, $end, $period, $products] = $this->scope($request);

        return Inertia::render('Produtos/Coproducao/Metrics/Index', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $this->publicFilters($filters),
            'summary' => $this->analytics->summary(null, $start, $end, $filters, true),
            'timeseries' => $this->analytics->timeseries(null, $start, $end, $filters, true),
            'funnel' => $this->analytics->funnel(null, $start, $end, $filters, true),
            'by_source' => array_slice($this->analytics->breakdown(null, $start, $end, 'utm_source', $filters, true), 0, 15),
            'by_campaign' => array_slice($this->analytics->breakdown(null, $start, $end, 'utm_campaign', $filters, true), 0, 15),
            'by_device' => $this->analytics->distribution(null, $start, $end, 'device_type', $filters, true),
            'by_country' => $this->analytics->distribution(null, $start, $end, 'country', $filters, true),
            'products' => $products,
            'tab' => 'dashboard',
            'base_path' => self::BASE_PATH,
        ]);
    }

    public function origins(Request $request): Response
    {
        [$filters, $start, $end, $period, $products] = $this->scope($request);
        $dimension = (string) $request->query('dimension', 'utm_source');

        return Inertia::render('Produtos/Coproducao/Metrics/Origins', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $this->publicFilters($filters),
            'dimension' => $dimension,
            'rows' => $this->analytics->breakdown(null, $start, $end, $dimension, $filters, true),
            'products' => $products,
            'tab' => 'origins',
            'base_path' => self::BASE_PATH,
        ]);
    }

    public function funnel(Request $request): Response
    {
        [$filters, $start, $end, $period, $products] = $this->scope($request);

        return Inertia::render('Produtos/Coproducao/Metrics/Funnel', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $this->publicFilters($filters),
            'funnel' => $this->analytics->funnel(null, $start, $end, $filters, true),
            'summary' => $this->analytics->summary(null, $start, $end, $filters, true),
            'products' => $products,
            'tab' => 'funnel',
            'base_path' => self::BASE_PATH,
        ]);
    }

    public function clicks(Request $request): Response
    {
        [$filters, $start, $end, $period, $products] = $this->scope($request);
        $search = trim((string) $request->query('q', ''));

        $query = $this->analytics->eventsQuery(null, $start, $end, $filters, true)
            ->whereIn('event_name', [
                MetricsEvent::PAGE_VIEW,
                MetricsEvent::CHECKOUT_VIEW,
                MetricsEvent::LINK_CLICKED,
                MetricsEvent::CHECKOUT_STARTED,
                MetricsEvent::PIX_CREATED,
                MetricsEvent::PAYMENT_APPROVED,
            ])
            ->with(['product:id,name'])
            ->orderByDesc('occurred_at');

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(COALESCE(utm_campaign, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(utm_source, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(destination_url, \'\')) LIKE ?', [$like]);
            });
        }

        $paginator = $query->paginate(50)->withQueryString();
        $rows = collect($paginator->items())->map(fn (MetricsEvent $e) => [
            'id' => $e->id,
            'occurred_at' => optional($e->occurred_at)?->toIso8601String(),
            'event_name' => $e->event_name,
            'ip_masked' => $e->ip_masked,
            'product_name' => $e->product?->name,
            'destination_url' => $e->destination_url,
            'utm_source' => $e->utm_source,
            'utm_campaign' => $e->utm_campaign,
            'device_type' => $e->device_type,
            'city' => $e->city,
            'region' => $e->region,
            'affiliate_ref' => $e->affiliate_ref,
            'conversion_status' => $e->conversion_status,
            'amount' => $e->amount !== null ? (float) $e->amount : null,
            'seconds_to_convert' => $e->seconds_to_convert,
        ])->values()->all();

        return Inertia::render('Produtos/Coproducao/Metrics/Clicks', [
            'period' => $period,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'filters' => $this->publicFilters($filters),
            'q' => $search,
            'rows' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'products' => $products,
            'tab' => 'clicks',
            'base_path' => self::BASE_PATH,
        ]);
    }

    /**
     * @return array{0: array<string,mixed>, 1:?\Carbon\Carbon, 2:?\Carbon\Carbon, 3:string, 4:list<array{id:string,name:string}>}
     */
    private function scope(Request $request): array
    {
        $userId = (int) auth()->id();
        $period = $this->analytics->normalizePeriod((string) $request->query('period', '7dias'));
        [$start, $end] = $this->analytics->resolveDateRange($request, $period);
        $filters = $this->analytics->filtersFromRequest($request);

        ProductCoproducer::expireOverdue();

        $rows = ProductCoproducer::query()
            ->where('co_producer_user_id', $userId)
            ->where('status', ProductCoproducer::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->get(['product_id']);

        $productIds = $rows->pluck('product_id')->filter()->unique()->values()->all();
        $filters['product_ids'] = $productIds;

        if (! empty($filters['product_id']) && ! in_array((string) $filters['product_id'], array_map('strval', $productIds), true)) {
            unset($filters['product_id']);
        }

        $products = $productIds === []
            ? []
            : Product::query()->whereIn('id', $productIds)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name])->values()->all();

        return [$filters, $start, $end, $period, $products];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function publicFilters(array $filters): array
    {
        return array_filter([
            'product_id' => $filters['product_id'] ?? null,
            'group_by' => $filters['group_by'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
