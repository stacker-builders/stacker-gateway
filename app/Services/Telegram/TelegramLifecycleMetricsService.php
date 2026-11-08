<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Models\TelegramDeliveryIncident;
use Illuminate\Support\Facades\Schema;

class TelegramLifecycleMetricsService
{
    /**
     * Métricas reais da plataforma (Admin). Sem números fictícios.
     *
     * @return array<string, mixed>
     */
    public function platform(): array
    {
        if (! Schema::hasTable('telegram_bots')) {
            return [
                'ready' => false,
                'message' => 'Tabelas Telegram ainda não migradas.',
            ];
        }

        $now = now()->utc()->format('Y-m-d H:i:s');

        return [
            'ready' => true,
            'bots_connected' => TelegramBot::query()->where('status', TelegramBot::STATUS_CONNECTED)->count(),
            'communities_linked' => Schema::hasTable('telegram_communities')
                ? TelegramCommunity::query()->where('status', TelegramCommunity::STATUS_LINKED)->count()
                : 0,
            'subscribers_active' => $this->activeGrantsCount(null, $now),
            'accesses_expired' => Schema::hasTable('telegram_access_grants')
                ? TelegramAccessGrant::query()->where('status', TelegramAccessGrant::STATUS_EXPIRED)->count()
                : 0,
            'deliveries_pending' => Schema::hasTable('telegram_access_grants')
                ? TelegramAccessGrant::query()
                    ->whereIn('delivery_status', [
                        TelegramAccessGrant::DELIVERY_PENDING,
                        TelegramAccessGrant::DELIVERY_PARTIAL,
                    ])
                    ->whereNotIn('status', [
                        TelegramAccessGrant::STATUS_REVOKED,
                        TelegramAccessGrant::STATUS_EXPIRED,
                    ])
                    ->count()
                : 0,
            'delivery_failures' => Schema::hasTable('telegram_access_grants')
                ? TelegramAccessGrant::query()->where('delivery_status', TelegramAccessGrant::DELIVERY_FAILED)->count()
                : 0,
            'technical_incidents_open' => Schema::hasTable('telegram_delivery_incidents')
                ? TelegramDeliveryIncident::query()->whereNull('ended_at')->count()
                : 0,
            'renewals_processed' => Schema::hasTable('telegram_access_events')
                ? TelegramAccessEvent::query()->where('event_type', TelegramAccessEvent::ACCESS_RENEWED)->count()
                : 0,
            'product_sales_operational' => (bool) config('telegram.product_sales_operational', false),
            'auto_compensation_enabled' => (bool) (TelegramModuleSettings::get()['auto_compensation_enabled'] ?? false),
        ];
    }

    /**
     * Painel do seller (isolado por tenant).
     *
     * @return array<string, mixed>
     */
    public function forTenant(int $tenantId, ?string $buyerQ = null, ?string $productId = null, ?int $communityId = null): array
    {
        if (! Schema::hasTable('telegram_access_grants') || $tenantId <= 0) {
            return [
                'ready' => false,
                'counts' => [],
                'grants' => [],
            ];
        }

        $now = now()->utc()->format('Y-m-d H:i:s');

        $base = TelegramAccessGrant::query()->forTenant($tenantId);

        if ($productId) {
            $base->where('product_id', $productId);
        }
        if ($buyerQ) {
            $q = '%'.addcslashes($buyerQ, '%_\\').'%';
            $base->where(function ($query) use ($q) {
                $query->where('telegram_username', 'like', $q)
                    ->orWhereHas('user', function ($u) use ($q) {
                        $u->where('name', 'like', $q)->orWhere('email', 'like', $q);
                    });
            });
        }
        if ($communityId) {
            $base->whereHas('communities', fn ($c) => $c->where('telegram_community_id', $communityId));
        }

        $counts = [
            'active' => (clone $base)->whereNotIn('status', [
                TelegramAccessGrant::STATUS_REVOKED,
                TelegramAccessGrant::STATUS_EXPIRED,
            ])->where(function ($q) use ($now) {
                $q->where('is_lifetime', true)
                    ->orWhereNull('access_ends_at')
                    ->orWhere('access_ends_at', '>', $now);
            })->count(),
            'expired' => (clone $base)->where('status', TelegramAccessGrant::STATUS_EXPIRED)->count(),
            'pending' => (clone $base)->whereIn('status', [
                TelegramAccessGrant::STATUS_PENDING_IDENTITY,
                TelegramAccessGrant::STATUS_PENDING_INVITE,
                TelegramAccessGrant::STATUS_PENDING_ENTRY,
            ])->count(),
            'partial' => (clone $base)->where('delivery_status', TelegramAccessGrant::DELIVERY_PARTIAL)->count(),
            'renewals' => Schema::hasTable('telegram_access_events')
                ? TelegramAccessEvent::query()
                    ->where('tenant_id', $tenantId)
                    ->where('event_type', TelegramAccessEvent::ACCESS_RENEWED)
                    ->count()
                : 0,
            'delivery_failures' => (clone $base)->where('delivery_status', TelegramAccessGrant::DELIVERY_FAILED)->count(),
        ];

        $grants = (clone $base)
            ->with(['product:id,name', 'user:id,name,email', 'communities.community'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (TelegramAccessGrant $g) => $this->mapGrantRow($g))
            ->values()
            ->all();

        return [
            'ready' => true,
            'counts' => $counts,
            'grants' => $grants,
            'filters' => [
                'buyer' => $buyerQ,
                'product_id' => $productId,
                'community_id' => $communityId,
            ],
        ];
    }

    private function activeGrantsCount(?int $tenantId, string $now): int
    {
        if (! Schema::hasTable('telegram_access_grants')) {
            return 0;
        }

        $q = TelegramAccessGrant::query()
            ->whereNotIn('status', [
                TelegramAccessGrant::STATUS_REVOKED,
                TelegramAccessGrant::STATUS_EXPIRED,
            ])
            ->where(function ($query) use ($now) {
                $query->where('is_lifetime', true)
                    ->orWhereNull('access_ends_at')
                    ->orWhere('access_ends_at', '>', $now);
            });

        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }

        return $q->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapGrantRow(TelegramAccessGrant $g): array
    {
        return [
            'id' => $g->id,
            'status' => $g->status,
            'delivery_status' => $g->delivery_status,
            'is_lifetime' => $g->is_lifetime,
            'renewal_count' => (int) $g->renewal_count,
            'access_ends_at_label' => $g->is_lifetime ? 'Vitalício' : $g->displayInAppTimezone('access_ends_at'),
            'telegram_username' => $g->telegram_username,
            'telegram_user_id' => $g->telegram_user_id,
            'product' => $g->product ? ['id' => $g->product->id, 'name' => $g->product->name] : null,
            'buyer' => $g->user ? ['id' => $g->user->id, 'name' => $g->user->name, 'email' => $g->user->email] : null,
            'communities' => $g->communities->map(fn ($c) => [
                'id' => $c->telegram_community_id,
                'title' => $c->community?->title,
                'status' => $c->status,
            ])->values()->all(),
        ];
    }
}
