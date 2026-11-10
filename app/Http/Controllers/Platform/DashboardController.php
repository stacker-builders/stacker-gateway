<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Platform\AcquirerWalletBalanceService;
use App\Services\Platform\PlatformAiReportService;
use App\Services\Platform\PlatformDashboardAnalytics;
use App\Support\Demo\DemoPlatformData;
use App\Support\DemoMode;
use App\Support\PlatformAiReportSettings;
use App\Support\PlatformDashboardPeriod;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class DashboardController extends Controller
{
    private const CACHE_TTL_SECONDS = 120;

    public function __invoke(Request $request, AcquirerWalletBalanceService $acquirerWallets): Response
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
        }

        [$start, $end] = PlatformDashboardPeriod::range($period, $from, $to);

        if (DemoMode::isEnabled()) {
            $payload = DemoPlatformData::dashboard($period);
            $payload['from'] = $from;
            $payload['to'] = $to;
            $payload['ai_report_available'] = PlatformAiReportSettings::isReady();

            return Inertia::render('Platform/Dashboard', $payload);
        }

        $resolver = function () use ($period, $start, $end): array {
            $analytics = PlatformDashboardAnalytics::compute($period, $start, $end);
            $analytics['period'] = $period;
            $analytics['from'] = $start ? Carbon::parse($start)->toDateString() : null;
            $analytics['to'] = $end ? Carbon::parse($end)->toDateString() : null;
            $analytics['grafico_vendas'] = collect($analytics['grafico']['points'] ?? [])
                ->map(fn (array $p) => [
                    'data' => $p['key'],
                    'total' => $p['volume'],
                ])
                ->values()
                ->all();
            $analytics['ultimas_transacoes'] = self::latestTransactions();

            return $analytics;
        };

        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            $payload = $resolver();
        } else {
            $cacheKey = 'platform-dashboard:v3:'.$period.':'.md5((string) $start.'|'.(string) $end);
            $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, $resolver);
        }

        $payload['from'] = $from;
        $payload['to'] = $to;
        $payload['acquirer_wallets'] = $acquirerWallets->list();
        $payload['ai_report_available'] = PlatformAiReportSettings::isReady();

        return Inertia::render('Platform/Dashboard', $payload);
    }

    public function aiReport(Request $request, PlatformAiReportService $reports): JsonResponse
    {
        $period = PlatformDashboardPeriod::normalize($request->input('period', 'hoje'));
        $from = PlatformDashboardPeriod::normalizeDate($request->input('from'));
        $to = PlatformDashboardPeriod::normalizeDate($request->input('to'));

        try {
            $analysis = $reports->analyze($period, $from, $to);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'analysis' => $analysis,
            'provider' => PlatformAiReportSettings::provider(),
            'provider_label' => PlatformAiReportSettings::providerLabel(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function latestTransactions(): array
    {
        return Order::query()
            ->with(['product:id,name', 'tenantOwner:id,name'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function (Order $o) {
                $methodKey = $o->paymentMethodReportKey();
                $raw = strtolower(trim((string) ($o->payment_method ?? '')));
                if (in_array($raw, ['apple_pay', 'google_pay'], true)) {
                    $methodLabel = $raw === 'apple_pay' ? 'Apple Pay' : 'Google Pay';
                } else {
                    $methodLabel = Order::paymentMethodReportLabel($methodKey);
                }

                return [
                    'id' => $o->id,
                    'email' => $o->email,
                    'product_name' => $o->product?->name,
                    'seller_name' => $o->tenantOwner?->name,
                    'amount' => (float) $o->amount,
                    'status' => $o->status,
                    'gateway' => $o->gateway,
                    'gateway_label' => $o->acquirerDisplayName(),
                    'payment_method' => $methodLabel,
                    'created_at' => $o->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }
}
