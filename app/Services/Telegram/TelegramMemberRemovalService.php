<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use Illuminate\Support\Facades\Log;

/**
 * Remoção segura: ban + unban para encerrar acesso sem banimento permanente.
 */
class TelegramMemberRemovalService
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramAccessEventLogger $events,
        private TelegramPolicy $policy,
    ) {}

    /**
     * @return array{removed: bool, skipped: bool, reason: string|null}
     */
    public function removeIfNoOtherRights(
        TelegramAccessGrant $grant,
        TelegramAccessCommunity $row,
        string $reason,
    ): array {
        $grant->loadMissing('bot');
        $row->loadMissing('community');

        if ((int) $grant->tenant_id !== (int) $row->tenant_id) {
            return $this->skip($grant, $row, 'tenant_mismatch');
        }

        $telegramUserId = (int) ($grant->telegram_user_id ?? 0);
        if ($telegramUserId <= 0) {
            return $this->skip($grant, $row, 'missing_telegram_user_id');
        }

        $community = $row->community;
        if (! $community || $community->isUnlinked()) {
            return $this->skip($grant, $row, 'community_unavailable');
        }

        if ((int) $community->id !== (int) $row->telegram_community_id) {
            return $this->skip($grant, $row, 'community_mismatch');
        }

        if ($this->hasOtherValidRight($grant, $row, $telegramUserId)) {
            $this->events->record($grant, TelegramAccessEvent::MEMBER_REMOVAL_SKIPPED, [
                'community_id' => $row->telegram_community_id,
                'reason' => 'other_active_right',
                'trigger' => $reason,
            ], $row);

            return ['removed' => false, 'skipped' => true, 'reason' => 'other_active_right'];
        }

        if (! $this->policy->expiryRemovalEnabled() && $reason === 'expired') {
            return $this->skip($grant, $row, 'expiry_removal_disabled');
        }

        $bot = $grant->bot;
        if (! $bot || $bot->isDisconnected() || $bot->plainToken() === null) {
            return $this->fail($grant, $row, 'bot_unavailable', $reason);
        }

        if (! $community->ready_for_delivery || ! $community->is_admin) {
            return $this->fail($grant, $row, 'bot_permissions_missing', $reason);
        }

        try {
            $privileged = $this->isPrivilegedMember($bot, $community, $telegramUserId);
            if ($privileged === null) {
                return $this->skip($grant, $row, 'member_status_unknown');
            }
            if ($privileged) {
                return $this->skip($grant, $row, 'privileged_member');
            }

            // Encerrar acesso sem ban permanente: banChatMember + unbanChatMember.
            $this->api->banChatMember($bot->plainToken(), $community->telegram_chat_id, $telegramUserId, [
                'until_date' => now()->addMinute()->getTimestamp(),
                'revoke_messages' => false,
            ]);
            $this->api->unbanChatMember($bot->plainToken(), $community->telegram_chat_id, $telegramUserId, [
                'only_if_banned' => true,
            ]);

            $row->forceFill([
                'status' => TelegramAccessCommunity::STATUS_REVOKED,
                'last_error' => null,
            ])->save();

            $this->events->record($grant, TelegramAccessEvent::MEMBER_REMOVED, [
                'community_id' => $row->telegram_community_id,
                'telegram_user_id' => $telegramUserId,
                'trigger' => $reason,
                'method' => 'ban_then_unban',
            ], $row);

            return ['removed' => true, 'skipped' => false, 'reason' => null];
        } catch (TelegramApiException $e) {
            return $this->fail($grant, $row, $e->getMessage(), $reason);
        }
    }

    public function hasOtherValidRight(
        TelegramAccessGrant $grant,
        TelegramAccessCommunity $row,
        int $telegramUserId,
    ): bool {
        return TelegramAccessGrant::query()
            ->where('tenant_id', $grant->tenant_id)
            ->where('id', '!=', $grant->id)
            ->where(function ($q) use ($grant, $telegramUserId) {
                $q->where('user_id', $grant->user_id)
                    ->orWhere('telegram_user_id', $telegramUserId);
            })
            ->whereNotIn('status', [
                TelegramAccessGrant::STATUS_REVOKED,
                TelegramAccessGrant::STATUS_EXPIRED,
            ])
            ->where(function ($q) {
                $q->where('is_lifetime', true)
                    ->orWhereNull('access_ends_at')
                    ->orWhere('access_ends_at', '>', now()->utc()->format('Y-m-d H:i:s'));
            })
            ->whereHas('communities', function ($q) use ($row) {
                $q->where('telegram_community_id', $row->telegram_community_id)
                    ->where('status', TelegramAccessCommunity::STATUS_JOINED);
            })
            ->exists();
    }

    /**
     * @return bool|null true=privilegiado, false=membro comum, null=status desconhecido
     */
    private function isPrivilegedMember(TelegramBot $bot, TelegramCommunity $community, int $telegramUserId): ?bool
    {
        try {
            $member = $this->api->getChatMember($bot->plainToken(), $community->telegram_chat_id, $telegramUserId);
            $status = (string) ($member['status'] ?? '');

            return in_array($status, ['creator', 'administrator'], true);
        } catch (TelegramApiException $e) {
            Log::info('telegram.removal.member_check_failed', [
                'chat_id' => $community->telegram_chat_id,
                'telegram_user_id' => $telegramUserId,
                'error' => $e->getMessage(),
            ]);

            // Fail-closed: sem confirmação, não remove.
            return null;
        }
    }

    /**
     * @return array{removed: bool, skipped: bool, reason: string|null}
     */
    private function skip(TelegramAccessGrant $grant, TelegramAccessCommunity $row, string $reason): array
    {
        $this->events->record($grant, TelegramAccessEvent::MEMBER_REMOVAL_SKIPPED, [
            'community_id' => $row->telegram_community_id,
            'reason' => $reason,
        ], $row);

        return ['removed' => false, 'skipped' => true, 'reason' => $reason];
    }

    /**
     * @return array{removed: bool, skipped: bool, reason: string|null}
     */
    private function fail(TelegramAccessGrant $grant, TelegramAccessCommunity $row, string $detail, string $trigger): array
    {
        $row->forceFill(['last_error' => $detail])->save();
        $this->events->record($grant, TelegramAccessEvent::MEMBER_REMOVAL_FAILED, [
            'community_id' => $row->telegram_community_id,
            'detail' => $detail,
            'trigger' => $trigger,
        ], $row);

        return ['removed' => false, 'skipped' => false, 'reason' => $detail];
    }
}
