<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TelegramAccessExpiryService
{
    public function __construct(
        private TelegramAccessEventLogger $events,
        private TelegramMemberRemovalService $removal,
        private TelegramPolicy $policy,
        private TelegramAccessNotificationService $notifications,
    ) {}

    public function tablesReady(): bool
    {
        return Schema::hasTable('telegram_access_grants');
    }

    /**
     * @return array{expired: int, removed: int, skipped: int, failed: int}
     */
    public function processDue(int $limit = 200): array
    {
        $stats = ['expired' => 0, 'removed' => 0, 'skipped' => 0, 'failed' => 0];

        if (! $this->tablesReady() || ! $this->policy->expiryControlEnabled()) {
            return $stats;
        }

        $now = now()->utc()->format('Y-m-d H:i:s');
        $ids = TelegramAccessGrant::query()
            ->where('is_lifetime', false)
            ->whereNotNull('access_ends_at')
            ->where('access_ends_at', '<=', $now)
            ->whereNotIn('status', [
                TelegramAccessGrant::STATUS_REVOKED,
                TelegramAccessGrant::STATUS_EXPIRED,
            ])
            ->orderBy('access_ends_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $result = $this->expireGrantById((int) $id);
            $stats['expired'] += $result['expired'] ? 1 : 0;
            $stats['removed'] += $result['removed'];
            $stats['skipped'] += $result['skipped'];
            $stats['failed'] += $result['failed'];
        }

        return $stats;
    }

    /**
     * @return array{expired: bool, removed: int, skipped: int, failed: int}
     */
    public function expireGrantById(int $grantId): array
    {
        $lock = Cache::lock('telegram-expire-grant-'.$grantId, 30);
        if (! $lock->get()) {
            return ['expired' => false, 'removed' => 0, 'skipped' => 0, 'failed' => 0];
        }

        try {
            return DB::transaction(function () use ($grantId) {
                $grant = TelegramAccessGrant::query()
                    ->whereKey($grantId)
                    ->lockForUpdate()
                    ->with(['communities.community', 'bot'])
                    ->first();

                if (! $grant) {
                    return ['expired' => false, 'removed' => 0, 'skipped' => 0, 'failed' => 0];
                }

                if (in_array($grant->status, [
                    TelegramAccessGrant::STATUS_REVOKED,
                    TelegramAccessGrant::STATUS_EXPIRED,
                ], true)) {
                    return ['expired' => false, 'removed' => 0, 'skipped' => 0, 'failed' => 0];
                }

                if ($grant->isCurrentlyValid()) {
                    // Renovação concorrente estendeu o prazo sob o lock.
                    return ['expired' => false, 'removed' => 0, 'skipped' => 0, 'failed' => 0];
                }

                $grant->forceFill([
                    'status' => TelegramAccessGrant::STATUS_EXPIRED,
                    'expired_at' => now()->utc()->format('Y-m-d H:i:s'),
                ])->save();

                $this->events->record($grant, TelegramAccessEvent::ACCESS_EXPIRED, [
                    'access_ends_at' => $grant->utcMoment('access_ends_at')?->toIso8601String(),
                ]);

                $removed = 0;
                $skipped = 0;
                $failed = 0;

                foreach ($grant->communities as $row) {
                    /** @var TelegramAccessCommunity $row */
                    if ($row->status !== TelegramAccessCommunity::STATUS_JOINED) {
                        if ($row->status !== TelegramAccessCommunity::STATUS_REVOKED) {
                            $row->forceFill(['status' => TelegramAccessCommunity::STATUS_REVOKED])->save();
                        }
                        $skipped++;

                        continue;
                    }

                    $autoRemove = (bool) ($grant->snapshot['auto_remove_on_expiry'] ?? true);
                    if (! $autoRemove) {
                        $this->events->record($grant, TelegramAccessEvent::MEMBER_REMOVAL_SKIPPED, [
                            'community_id' => $row->telegram_community_id,
                            'reason' => 'product_auto_remove_disabled',
                        ], $row);
                        $skipped++;

                        continue;
                    }

                    $outcome = $this->removal->removeIfNoOtherRights($grant, $row, 'expired');
                    if ($outcome['removed']) {
                        $removed++;
                    } elseif ($outcome['skipped']) {
                        $skipped++;
                    } else {
                        $failed++;
                    }
                }

                try {
                    $this->notifications->accessExpired($grant->fresh(['product', 'user']));
                } catch (\Throwable $e) {
                    Log::warning('telegram.expiry.notify_failed', [
                        'grant_id' => $grant->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                return [
                    'expired' => true,
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
