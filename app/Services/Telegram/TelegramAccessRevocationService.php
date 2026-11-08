<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramAccessOrderLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TelegramAccessRevocationService
{
    public const REASON_REFUND = 'refund';

    public const REASON_ORDER_REJECTED = 'order_rejected';

    public const REASON_CHARGEBACK = 'chargeback';

    public const REASON_MED_LOST = 'med_lost';

    public const REASON_ADMIN = 'admin_risk';

    public const REASON_CANCEL_IMMEDIATE = 'cancel_immediate';

    public function __construct(
        private TelegramAccessEventLogger $events,
        private TelegramMemberRemovalService $removal,
        private TelegramAccessNotificationService $notifications,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::hasTable('telegram_access_grants');
    }

    /**
     * Reembolso integral / chargeback confirmado / rejeição após grant.
     * Renovação/extensão: encurta o prazo em vez de revogar o direito raiz.
     */
    public function revokeForOrder(Order $order, string $reason, string $detail = ''): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $links = Schema::hasTable('telegram_access_order_links')
            ? TelegramAccessOrderLink::query()->where('order_id', $order->id)->get()
            : collect();

        if ($links->isNotEmpty()) {
            foreach ($links as $link) {
                if (in_array($link->link_type, [
                    TelegramAccessOrderLink::TYPE_RENEWAL,
                    TelegramAccessOrderLink::TYPE_EXTENSION,
                ], true)) {
                    $this->shortenRenewalPeriod((int) $link->telegram_access_grant_id, $order, $reason, $detail);
                } else {
                    $this->revokeGrantById(
                        (int) $link->telegram_access_grant_id,
                        $reason,
                        $detail !== '' ? $detail : $reason,
                        removeMembers: true
                    );
                }
            }

            return;
        }

        $grantIds = TelegramAccessGrant::query()
            ->where(function ($q) use ($order) {
                $q->where('order_id', $order->id)->orWhere('latest_order_id', $order->id);
            })
            ->pluck('id')
            ->all();

        foreach (array_unique($grantIds) as $grantId) {
            $this->revokeGrantById((int) $grantId, $reason, $detail !== '' ? $detail : $reason, removeMembers: true);
        }
    }

    /**
     * Remove os dias adicionados pela renovação; se o prazo já acabou, expira.
     */
    public function shortenRenewalPeriod(int $grantId, Order $order, string $reason, string $detail = ''): void
    {
        $lock = Cache::lock('telegram-revoke-grant-'.$grantId, 30);
        if (! $lock->get()) {
            return;
        }

        try {
            DB::transaction(function () use ($grantId, $order, $reason, $detail) {
                $grant = TelegramAccessGrant::query()->whereKey($grantId)->lockForUpdate()->first();
                if (! $grant || $grant->status === TelegramAccessGrant::STATUS_REVOKED) {
                    return;
                }

                $days = 30;
                $renewEvent = TelegramAccessEvent::query()
                    ->where('telegram_access_grant_id', $grant->id)
                    ->where('event_type', TelegramAccessEvent::ACCESS_RENEWED)
                    ->orderByDesc('id')
                    ->get()
                    ->first(fn (TelegramAccessEvent $e) => (int) ($e->payload['order_id'] ?? 0) === (int) $order->id);
                if ($renewEvent && isset($renewEvent->payload['days_added']) && $renewEvent->payload['days_added'] !== null) {
                    $days = max(1, (int) $renewEvent->payload['days_added']);
                }

                $ends = $grant->utcMoment('access_ends_at');
                if ($grant->is_lifetime || $ends === null) {
                    $this->events->record($grant, TelegramAccessEvent::MANUAL_CORRECTION, [
                        'action' => 'renewal_refund_lifetime_noop',
                        'order_id' => $order->id,
                        'reason' => $reason,
                        'detail' => $detail,
                    ]);

                    return;
                }

                $newEnds = $ends->copy()->subDays($days);
                $now = now()->utc();
                if ($newEnds->lessThanOrEqualTo($now)) {
                    $grant->forceFill([
                        'access_ends_at' => $newEnds->utc()->format('Y-m-d H:i:s'),
                    ])->save();
                    app(TelegramAccessExpiryService::class)->expireGrantById((int) $grant->id);

                    return;
                }

                $grant->forceFill([
                    'access_ends_at' => $newEnds->utc()->format('Y-m-d H:i:s'),
                ])->save();

                $this->events->record($grant, TelegramAccessEvent::MANUAL_CORRECTION, [
                    'action' => 'renewal_period_shortened',
                    'order_id' => $order->id,
                    'reason' => $reason,
                    'detail' => $detail,
                    'days_removed' => $days,
                    'new_ends_at' => $newEnds->toIso8601String(),
                ]);
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Disputa/MED em análise ou reembolso parcial: não remove membros; apenas registra.
     */
    public function markDisputeUnderReview(Order $order): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $linkedIds = Schema::hasTable('telegram_access_order_links')
            ? TelegramAccessOrderLink::query()->where('order_id', $order->id)->pluck('telegram_access_grant_id')->all()
            : [];

        $grants = TelegramAccessGrant::query()
            ->where(function ($q) use ($order, $linkedIds) {
                $q->where('order_id', $order->id)
                    ->orWhere('latest_order_id', $order->id);
                if ($linkedIds !== []) {
                    $q->orWhereIn('id', $linkedIds);
                }
            })
            ->get();

        foreach ($grants as $grant) {
            $this->events->record($grant, TelegramAccessEvent::MANUAL_CORRECTION, [
                'action' => 'dispute_under_review',
                'order_id' => $order->id,
                'order_status' => $order->status,
                'note' => 'Disputa/MED/reembolso parcial em análise — sem expulsão imediata.',
            ]);
        }
    }

    /**
     * Cancelamento de renovação futura sem reembolso: mantém acesso até o fim do período.
     */
    public function cancelFutureRenewalOnly(TelegramAccessGrant $grant, string $detail = ''): void
    {
        $this->events->record($grant, TelegramAccessEvent::MANUAL_CORRECTION, [
            'action' => 'future_renewal_cancelled',
            'detail' => $detail,
            'access_ends_at' => $grant->utcMoment('access_ends_at')?->toIso8601String(),
            'note' => 'Acesso permanece válido até o fim do período contratado.',
        ]);
    }

    /**
     * @return array{revoked: bool, removed: int, skipped: int, failed: int}
     */
    public function revokeGrantById(
        int $grantId,
        string $reason,
        string $detail = '',
        bool $removeMembers = true,
    ): array {
        $lock = Cache::lock('telegram-revoke-grant-'.$grantId, 30);
        if (! $lock->get()) {
            return ['revoked' => false, 'removed' => 0, 'skipped' => 0, 'failed' => 0];
        }

        try {
            return DB::transaction(function () use ($grantId, $reason, $detail, $removeMembers) {
                $grant = TelegramAccessGrant::query()
                    ->whereKey($grantId)
                    ->lockForUpdate()
                    ->with(['communities.community', 'bot', 'product', 'user'])
                    ->first();

                if (! $grant || $grant->status === TelegramAccessGrant::STATUS_REVOKED) {
                    return ['revoked' => false, 'removed' => 0, 'skipped' => 0, 'failed' => 0];
                }

                $grant->forceFill([
                    'status' => TelegramAccessGrant::STATUS_REVOKED,
                    'revoked_at' => now()->utc()->format('Y-m-d H:i:s'),
                    'revoked_reason' => $reason,
                    'revoked_detail' => $detail !== '' ? $detail : null,
                ])->save();

                $this->events->record($grant, TelegramAccessEvent::ACCESS_REVOKED, [
                    'reason' => $reason,
                    'detail' => $detail,
                    'remove_members' => $removeMembers,
                ]);

                $removed = 0;
                $skipped = 0;
                $failed = 0;

                if ($removeMembers) {
                    foreach ($grant->communities as $row) {
                        if ($row->status !== TelegramAccessCommunity::STATUS_JOINED) {
                            $row->forceFill(['status' => TelegramAccessCommunity::STATUS_REVOKED])->save();
                            $skipped++;

                            continue;
                        }
                        $outcome = $this->removal->removeIfNoOtherRights($grant, $row, $reason);
                        if ($outcome['removed']) {
                            $removed++;
                        } elseif ($outcome['skipped']) {
                            $skipped++;
                        } else {
                            $failed++;
                        }
                    }
                }

                try {
                    $this->notifications->accessRevoked($grant->fresh(['product', 'user']), $reason);
                } catch (\Throwable $e) {
                    Log::warning('telegram.revoke.notify_failed', [
                        'grant_id' => $grant->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return [
                    'revoked' => true,
                    'removed' => $removed,
                    'skipped' => $skipped,
                    'failed' => $failed,
                ];
            });
        } finally {
            $lock->release();
        }
    }
}
