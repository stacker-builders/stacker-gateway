<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductCoproducer;
use App\Models\User;
use App\Services\TeamAccessService;
use Illuminate\Database\Eloquent\Builder;

class SellerProductListing
{
    /**
     * Lista de /produtos antes dos filtros de categoria e pasta.
     *
     * @return array{query: Builder, coproduction_percents: array<string, float>}
     */
    public static function forUser(User $user): array
    {
        $query = Product::forTenant($user->tenant_id)->orderBy('name');
        if ($user->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor($user);
            $query->whereIn('id', $allowed ?: ['__none__']);
        }

        $coproPercents = [];
        if (! $user->isTeam()) {
            ProductCoproducer::expireOverdue();
            $coproRows = ProductCoproducer::query()
                ->where('co_producer_user_id', $user->id)
                ->where('status', ProductCoproducer::STATUS_ACTIVE)
                ->where(function ($q) {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
                })
                ->where(function ($q) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->get(['product_id', 'commission_percent']);

            $coproIds = $coproRows->pluck('product_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
            foreach ($coproRows as $row) {
                $coproPercents[(string) $row->product_id] = (float) $row->commission_percent;
            }

            if ($coproIds !== []) {
                $ownIds = (clone $query)->pluck('id')->map(fn ($id) => (string) $id)->all();
                $allIds = array_values(array_unique(array_merge($ownIds, $coproIds)));
                $query = Product::query()->whereIn('id', $allIds ?: ['__none__'])->orderBy('name');
            }
        }

        return [
            'query' => $query,
            'coproduction_percents' => $coproPercents,
        ];
    }
}
