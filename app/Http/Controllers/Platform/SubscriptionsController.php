<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\SubscriptionChargeSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionsController extends Controller
{
    private const STATUSES = [
        'all' => 'Todas',
        Subscription::STATUS_ACTIVE => 'Ativas',
        Subscription::STATUS_PAST_DUE => 'Em atraso',
        Subscription::STATUS_CANCELLED => 'Encerradas',
    ];

    public function index(Request $request): Response
    {
        $status = $this->normalizeStatus($request->query('status'));
        $seller = mb_substr(trim((string) $request->query('seller', '')), 0, 190);

        $empty = new LengthAwarePaginator([], 0, 25, 1, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        $counts = [
            'all' => 0,
            Subscription::STATUS_ACTIVE => 0,
            Subscription::STATUS_PAST_DUE => 0,
            Subscription::STATUS_CANCELLED => 0,
        ];

        if (! Schema::hasTable('subscriptions')) {
            return Inertia::render('Platform/Subscriptions/Index', [
                'subscriptions' => $empty,
                'filters' => ['status' => $status, 'seller' => $seller],
                'counts' => $counts,
                'status_options' => $this->statusOptions(),
            ]);
        }

        $filtered = $this->baseQuery($seller);
        foreach (array_keys($counts) as $key) {
            $countQuery = clone $filtered;
            if ($key !== 'all') {
                $countQuery->where('status', $key);
            }
            $counts[$key] = $countQuery->count();
        }

        $list = clone $filtered;
        if ($status !== 'all') {
            $list->where('status', $status);
        }

        $page = $list
            ->orderByDesc('subscriptions.current_period_end')
            ->orderByDesc('subscriptions.id')
            ->paginate(25)
            ->withQueryString();
        $summaries = SubscriptionChargeSummary::forSubscriptions($page->getCollection());
        $subscriptions = $page->through(
            fn (Subscription $subscription) => $this->mapSubscription($subscription, $summaries[$subscription->id] ?? [])
        );

        return Inertia::render('Platform/Subscriptions/Index', [
            'subscriptions' => $subscriptions,
            'filters' => ['status' => $status, 'seller' => $seller],
            'counts' => $counts,
            'status_options' => $this->statusOptions(),
        ]);
    }

    /**
     * @return Builder<Subscription>
     */
    private function baseQuery(string $seller): Builder
    {
        $query = Subscription::query()
            ->with([
                'user:id,name,email',
                'tenantOwner:id,name,email',
                'product:id,name',
                'subscriptionPlan:id,name,interval,price,currency',
            ]);

        if ($seller !== '') {
            $like = '%'.addcslashes($seller, '%_\\').'%';
            $query->whereHas('tenantOwner', function (Builder $owner) use ($like) {
                $owner->where('email', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function mapSubscription(Subscription $subscription, array $summary = []): array
    {
        $plan = $subscription->subscriptionPlan;

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'status_label' => match ($subscription->status) {
                Subscription::STATUS_ACTIVE => 'Ativa',
                Subscription::STATUS_PAST_DUE => 'Em atraso',
                Subscription::STATUS_CANCELLED => 'Encerrada',
                default => $subscription->status,
            },
            'current_period_start' => $subscription->current_period_start?->toDateString(),
            'current_period_end' => $subscription->current_period_end?->toDateString(),
            'purchased_at' => $summary['purchased_at'] ?? $subscription->created_at?->toIso8601String(),
            'next_charge_at' => $summary['next_charge_at'] ?? null,
            'next_charge_amount' => $summary['next_charge_amount'] ?? null,
            'payment_method_label' => $summary['payment_method_label'] ?? '—',
            'charges_count' => $summary['charges_count'] ?? 0,
            'automatic_charges_count' => $summary['automatic_charges_count'] ?? 0,
            'customer_name' => $subscription->user?->name ?? '—',
            'customer_email' => $subscription->user?->email ?? '—',
            'infoprodutor_name' => $subscription->tenantOwner?->name ?? '—',
            'infoprodutor_email' => $subscription->tenantOwner?->email,
            'product_name' => $subscription->product?->name ?? '—',
            'plan_name' => $plan?->name ?? '—',
            'plan_interval' => $plan
                ? (SubscriptionPlan::intervalLabels()[$plan->interval] ?? $plan->interval)
                : null,
            'plan_price' => $plan ? (float) $plan->price : null,
        ];
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = is_string($value) ? $value : 'all';

        return array_key_exists($status, self::STATUSES) ? $status : 'all';
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        $options = [];
        foreach (self::STATUSES as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
