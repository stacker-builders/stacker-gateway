<?php

namespace App\Http\Controllers;

use App\Events\SubscriptionCancelled;
use App\Http\Controllers\Concerns\LogsSellerActivity;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\SellerActivityLogService;
use App\Services\TeamAccessService;
use App\Support\SubscriptionChargeSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssinaturasController extends Controller
{
    use LogsSellerActivity;

    private const STATUS_FILTERS = ['active', 'past_due', 'cancelled', 'all'];

    private function normalizeStatusFilter(?string $value): string
    {
        $v = is_string($value) ? $value : 'active';

        return in_array($v, self::STATUS_FILTERS, true) ? $v : 'active';
    }

    public function index(Request $request): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $statusFilter = $this->normalizeStatusFilter($request->query('status'));

        $baseQuery = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->forTenant($tenantId);

        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $baseQuery->whereIn('product_id', $allowed ?: ['__none__']);
        }

        $statsQuery = clone $baseQuery;
        $ativas = (clone $statsQuery)->where('status', Subscription::STATUS_ACTIVE)->count();
        $clientes = (clone $statsQuery)->where('status', Subscription::STATUS_ACTIVE)->distinct('user_id')->count('user_id');

        $mrrQuery = Subscription::forTenant($tenantId)
            ->where('status', Subscription::STATUS_ACTIVE)
            ->join('subscription_plans', 'subscriptions.subscription_plan_id', '=', 'subscription_plans.id')
            ->where('subscription_plans.interval', '!=', 'lifetime');
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $mrrQuery->whereIn('subscriptions.product_id', $allowed ?: ['__none__']);
        }
        $mrr = round((float) $mrrQuery->sum('subscription_plans.price'), 2);

        $listQuery = clone $baseQuery;
        if ($statusFilter !== 'all') {
            $listQuery->where('status', $statusFilter);
        }

        $page = $listQuery->orderByDesc('subscriptions.current_period_end')
            ->paginate(20)
            ->withQueryString();
        $summaries = SubscriptionChargeSummary::forSubscriptions($page->getCollection());
        $assinaturas = $page->through(function ($s) use ($summaries) {
            $summary = $summaries[$s->id] ?? [];

            return [
                'id' => $s->id,
                'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name, 'email' => $s->user->email] : null,
                'product' => $s->product ? ['id' => $s->product->id, 'name' => $s->product->name] : null,
                'plan' => $s->subscriptionPlan ? [
                    'id' => $s->subscriptionPlan->id,
                    'name' => $s->subscriptionPlan->name,
                    'interval' => $s->subscriptionPlan->interval,
                    'interval_label' => \App\Models\SubscriptionPlan::intervalLabels()[$s->subscriptionPlan->interval] ?? $s->subscriptionPlan->interval,
                    'price' => (float) $s->subscriptionPlan->price,
                ] : null,
                'current_period_end' => $s->current_period_end?->toDateString(),
                'purchased_at' => $summary['purchased_at'] ?? $s->created_at?->toIso8601String(),
                'next_charge_at' => $summary['next_charge_at'] ?? null,
                'next_charge_amount' => $summary['next_charge_amount'] ?? null,
                'payment_method_label' => $summary['payment_method_label'] ?? '—',
                'charges_count' => $summary['charges_count'] ?? 0,
                'automatic_charges_count' => $summary['automatic_charges_count'] ?? 0,
                'status' => $s->status,
            ];
        });

        return Inertia::render('Assinaturas/Index', [
            'stats' => [
                'ativas' => $ativas,
                'clientes' => $clientes,
                'mrr' => $mrr,
            ],
            'assinaturas' => $assinaturas,
            'status_filter' => $statusFilter,
        ]);
    }

    public function show(Request $request, Subscription $subscription): Response
    {
        $this->authorizeSubscription($subscription);

        $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);
        $summary = SubscriptionChargeSummary::forSubscriptions([$subscription])[$subscription->id] ?? [];
        $plan = $subscription->subscriptionPlan;
        $paidPeriodsCount = Order::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('status', 'completed')
            ->count();

        $recentOrders = Order::query()
            ->where('tenant_id', $subscription->tenant_id)
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'public_reference' => $o->public_reference,
                'status' => $o->status,
                'amount' => (float) $o->amount,
                'is_renewal' => (bool) $o->is_renewal,
                'charge_label' => $o->is_renewal ? 'Cobrança automática' : 'Compra',
                'payment_method' => $o->payment_method,
                'payment_method_label' => $o->paymentMethodDisplayLabel(),
                'period_start' => $o->period_start?->toDateString(),
                'period_end' => $o->period_end?->toDateString(),
                'created_at' => $o->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Assinaturas/Show', [
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start?->toDateString(),
                'current_period_end' => $subscription->current_period_end?->toDateString(),
                'renewal_token' => $subscription->renewal_token,
                'saved_payment_method_id' => $subscription->saved_payment_method_id,
                'gateway_subscription_id' => $subscription->gateway_subscription_id,
                'user' => $subscription->user ? [
                    'id' => $subscription->user->id,
                    'name' => $subscription->user->name,
                    'email' => $subscription->user->email,
                ] : null,
                'product' => $subscription->product ? [
                    'id' => $subscription->product->id,
                    'name' => $subscription->product->name,
                ] : null,
                'plan' => $plan ? [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'interval' => $plan->interval,
                    'interval_label' => \App\Models\SubscriptionPlan::intervalLabels()[$plan->interval] ?? $plan->interval,
                    'price' => (float) $plan->price,
                    'currency' => $plan->getCurrencyOrDefault(),
                ] : null,
                'paid_periods_count' => $paidPeriodsCount,
                'purchased_at' => $summary['purchased_at'] ?? $subscription->created_at?->toIso8601String(),
                'next_charge_at' => $summary['next_charge_at'] ?? null,
                'next_charge_amount' => $summary['next_charge_amount'] ?? null,
                'payment_method_label' => $summary['payment_method_label'] ?? '—',
                'automatic_charges_count' => $summary['automatic_charges_count'] ?? 0,
            ],
            'recent_orders' => $recentOrders,
            'cancel_grace_days' => (int) config('getfy.subscriptions.cancel_grace_days_after_period_end', 14),
        ]);
    }

    public function cancel(Request $request, Subscription $subscription): RedirectResponse
    {
        $this->authorizeSubscription($subscription);

        if (! in_array($subscription->status, [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE], true)) {
            return redirect()->back()->with('error', 'Esta assinatura já está cancelada ou não pode ser cancelada.');
        }

        $subscription->update(['status' => Subscription::STATUS_CANCELLED]);
        event(new SubscriptionCancelled($subscription->fresh()));

        $this->logSellerActivity(SellerActivityLogService::SUBSCRIPTION_CANCELLED, $subscription, [
            'order_id' => $subscription->order_id,
            'product_id' => $subscription->product_id,
        ]);

        return redirect()->route('assinaturas.show', $subscription)->with('success', 'Assinatura cancelada.');
    }

    private function authorizeSubscription(Subscription $subscription): void
    {
        if ((int) $subscription->tenant_id !== (int) auth()->user()->tenant_id) {
            abort(404);
        }
        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            if (! in_array($subscription->product_id, $allowed, true)) {
                abort(403);
            }
        }
    }
}
