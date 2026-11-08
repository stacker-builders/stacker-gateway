<?php

namespace App\Services\Telegram;

use App\Models\ProductTelegramSetting;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessEvent;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use App\Models\TelegramDeliveryIncident;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramInviteService
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramAccessEventLogger $events,
        private TelegramDeliveryIncidentService $incidents,
        private TelegramAccessGrantService $grants,
        private TelegramPolicy $policy,
    ) {}

    /**
     * Gera/renova convites para comunidades pendentes. Idempotente por comunidade com link válido.
     */
    public function processGrant(TelegramAccessGrant $grant, bool $forceRegenerate = false): TelegramAccessGrant
    {
        $grant->loadMissing(['bot', 'communities.community', 'product']);

        if (! $this->policy->autoDeliveryEnabled()) {
            $grant->forceFill([
                'last_error' => 'Entrega automática Telegram desabilitada na política global.',
            ])->save();

            return $grant->fresh(['communities.community', 'product']);
        }

        $bot = $grant->bot;
        if (! $bot || $bot->isDisconnected() || $bot->plainToken() === null) {
            $this->failGrantInfrastructure($grant, TelegramDeliveryIncident::REASON_BOT_UNAVAILABLE, 'Bot indisponível ou desconectado.');

            return $this->grants->refreshGrantStatus($grant->fresh());
        }

        $setting = ProductTelegramSetting::query()
            ->where('product_id', (string) $grant->product_id)
            ->first();
        $inviteHours = max(1, (int) ($setting?->invite_expiration_hours
            ?? ($grant->snapshot['invite_expiration_hours'] ?? 24)));

        $retryableErrors = [];

        foreach ($grant->communities as $row) {
            /** @var TelegramAccessCommunity $row */
            if ($row->status === TelegramAccessCommunity::STATUS_JOINED) {
                continue;
            }
            if ($row->status === TelegramAccessCommunity::STATUS_REVOKED) {
                continue;
            }

            if (! $forceRegenerate
                && $row->invite_link
                && $row->invite_link_expires_at
                && $row->invite_link_expires_at->isFuture()
                && in_array($row->status, [
                    TelegramAccessCommunity::STATUS_INVITE_READY,
                    TelegramAccessCommunity::STATUS_AWAITING_JOIN_REQUEST,
                ], true)
            ) {
                continue;
            }

            try {
                $this->generateForCommunity($grant, $row, $bot, $inviteHours);
            } catch (TelegramApiException $e) {
                if ($e->retryable) {
                    $retryableErrors[] = $e;
                }
            }
        }

        $fresh = $this->grants->refreshGrantStatus($grant->fresh(['communities.community', 'product']));

        // Erros transitórios: registra no domínio e relança para retry da fila.
        if ($retryableErrors !== []) {
            throw $retryableErrors[0];
        }

        return $fresh;
    }

    private function generateForCommunity(
        TelegramAccessGrant $grant,
        TelegramAccessCommunity $row,
        TelegramBot $bot,
        int $inviteHours,
    ): void {
        $community = $row->community;
        if (! $community || $community->isUnlinked()) {
            $this->markCommunityFailed(
                $grant,
                $row,
                TelegramDeliveryIncident::REASON_COMMUNITY_INACCESSIBLE,
                'Comunidade inacessível ou desvinculada.'
            );

            return;
        }

        if (! $community->ready_for_delivery) {
            $this->markCommunityFailed(
                $grant,
                $row,
                TelegramDeliveryIncident::REASON_PERMISSIONS_REMOVED,
                'Permissões administrativas insuficientes na comunidade.'
            );

            return;
        }

        try {
            $expireDate = now()->utc()->addHours($inviteHours)->getTimestamp();
            $result = $this->api->createChatInviteLink($bot->plainToken(), $community->telegram_chat_id, [
                'name' => 'Stacker #'.$grant->id.'-'.$row->id,
                'expire_date' => $expireDate,
                'member_limit' => 1,
                'creates_join_request' => false,
            ]);

            $inviteLink = (string) ($result['invite_link'] ?? '');
            if ($inviteLink === '') {
                throw new TelegramApiException('API não retornou invite_link.', 500, true);
            }

            $expiresAt = isset($result['expire_date'])
                ? Carbon::createFromTimestamp((int) $result['expire_date'])->utc()
                : now()->utc()->addHours($inviteHours);

            DB::transaction(function () use ($grant, $row, $inviteLink, $expiresAt) {
                $row->forceFill([
                    'status' => TelegramAccessCommunity::STATUS_INVITE_READY,
                    'invite_link' => $inviteLink,
                    'invite_link_expires_at' => $expiresAt,
                    'invite_generated_at' => now()->utc(),
                    'last_error' => null,
                    'failure_ended_at' => $row->failure_started_at ? now()->utc() : $row->failure_ended_at,
                ])->save();

                $this->events->record($grant, TelegramAccessEvent::INVITE_GENERATED, [
                    'community_id' => $row->telegram_community_id,
                    'invite_expires_at' => $expiresAt->toIso8601String(),
                ], $row);
            });

            if ($row->failure_started_at && ! $row->failure_ended_at) {
                $incident = $this->incidents->openOrRefresh(
                    $grant,
                    TelegramDeliveryIncident::REASON_INVITE_ERROR,
                    'Recuperado após falha de convite.',
                    'invite-fail-'.$grant->id.'-'.$row->id,
                    $row,
                );
                $this->incidents->resolve($incident);
            }
        } catch (TelegramApiException $e) {
            Log::warning('telegram.invite.failed', [
                'grant_id' => $grant->id,
                'community_id' => $row->telegram_community_id,
                'error' => $e->getMessage(),
                'retryable' => $e->retryable,
            ]);

            $reason = $e->retryable
                ? TelegramDeliveryIncident::REASON_INVITE_ERROR
                : TelegramDeliveryIncident::REASON_DELIVERY_PROCESSING;

            $this->markCommunityFailed($grant, $row, $reason, $e->getMessage());

            if ($e->retryable) {
                throw $e;
            }
        }
    }

    private function markCommunityFailed(
        TelegramAccessGrant $grant,
        TelegramAccessCommunity $row,
        string $reasonCode,
        string $detail,
    ): void {
        $row->forceFill([
            'status' => TelegramAccessCommunity::STATUS_FAILED,
            'last_error' => $detail,
            'failure_started_at' => $row->failure_started_at ?? now()->utc(),
        ])->save();

        $this->events->record($grant, TelegramAccessEvent::TECHNICAL_FAILURE, [
            'community_id' => $row->telegram_community_id,
            'reason_code' => $reasonCode,
            'detail' => $detail,
        ], $row);

        $this->incidents->openOrRefresh(
            $grant,
            $reasonCode,
            $detail,
            $reasonCode.'-'.$grant->id.'-'.$row->id.'-'.($row->failure_started_at?->timestamp ?? now()->timestamp),
            $row,
        );
    }

    private function failGrantInfrastructure(TelegramAccessGrant $grant, string $reasonCode, string $detail): void
    {
        $grant->forceFill(['last_error' => $detail])->save();
        foreach ($grant->communities as $row) {
            if ($row->status === TelegramAccessCommunity::STATUS_JOINED) {
                continue;
            }
            $this->markCommunityFailed($grant, $row, $reasonCode, $detail);
        }

        $this->events->record($grant, TelegramAccessEvent::TECHNICAL_FAILURE, [
            'reason_code' => $reasonCode,
            'detail' => $detail,
            'scope' => 'grant',
        ]);
    }

    /**
     * Confirma entrada efetiva (não basta convite gerado).
     */
    public function confirmEntry(
        TelegramAccessGrant $grant,
        TelegramAccessCommunity $row,
        int $telegramUserId,
        ?string $username = null,
        ?string $firstName = null,
        ?Carbon $joinedAt = null,
    ): TelegramAccessGrant {
        $joinedAt = ($joinedAt ?? now())->utc();

        // Entrada comercial exige identidade já vinculada ao comprador do grant.
        if (! $grant->hasIdentity() || (int) $grant->telegram_user_id !== $telegramUserId) {
            Log::info('telegram.entry.rejected_unlinked_identity', [
                'grant_id' => $grant->id,
                'expected' => $grant->telegram_user_id,
                'got' => $telegramUserId,
            ]);
            $this->events->record($grant, TelegramAccessEvent::MANUAL_CORRECTION, [
                'action' => 'entry_rejected_unproven_identity',
                'telegram_user_id' => $telegramUserId,
                'community_id' => $row->telegram_community_id,
            ], $row);

            return $grant->fresh(['communities.community', 'product']);
        }

        DB::transaction(function () use ($grant, $row, $telegramUserId, $joinedAt) {
            if ($row->status !== TelegramAccessCommunity::STATUS_JOINED) {
                $row->forceFill([
                    'status' => TelegramAccessCommunity::STATUS_JOINED,
                    'joined_at' => $row->joined_at ?? $joinedAt,
                    'last_error' => null,
                    'failure_ended_at' => $row->failure_started_at ? now()->utc() : $row->failure_ended_at,
                ])->save();

                $this->events->record($grant, TelegramAccessEvent::ENTRY_CONFIRMED, [
                    'community_id' => $row->telegram_community_id,
                    'telegram_user_id' => $telegramUserId,
                    'joined_at' => $joinedAt->toIso8601String(),
                    // Entrada posterior NÃO altera access_starts_at / access_ends_at.
                    'access_starts_at' => $grant->access_starts_at?->toIso8601String(),
                    'access_ends_at' => $grant->access_ends_at?->toIso8601String(),
                ], $row, $joinedAt);
            }
        });

        $this->maybeSendWelcome($grant->fresh(['bot']), $row->fresh('community'));

        return $this->grants->refreshGrantStatus($grant->fresh(['communities.community', 'product']));
    }

    public function markJoinRequest(
        TelegramAccessGrant $grant,
        TelegramAccessCommunity $row,
        int $telegramUserId,
        ?string $username = null,
    ): void {
        if (! $grant->hasIdentity() || (int) $grant->telegram_user_id !== $telegramUserId) {
            Log::info('telegram.join_request.rejected_unlinked_identity', [
                'grant_id' => $grant->id,
                'telegram_user_id' => $telegramUserId,
            ]);
            $this->events->record($grant, TelegramAccessEvent::MANUAL_CORRECTION, [
                'action' => 'join_request_rejected_unproven_identity',
                'telegram_user_id' => $telegramUserId,
                'community_id' => $row->telegram_community_id,
            ], $row);

            return;
        }

        $row->forceFill([
            'status' => TelegramAccessCommunity::STATUS_AWAITING_JOIN_REQUEST,
            'join_requested_at' => now()->utc(),
        ])->save();

        $bot = $grant->bot;
        if ($bot && $bot->plainToken()) {
            try {
                $this->api->approveChatJoinRequest(
                    $bot->plainToken(),
                    $row->telegram_chat_id,
                    $telegramUserId
                );
                $this->events->record($grant, TelegramAccessEvent::JOIN_REQUEST_APPROVED, [
                    'community_id' => $row->telegram_community_id,
                    'telegram_user_id' => $telegramUserId,
                ], $row);

                try {
                    $this->api->sendMessage(
                        $bot->plainToken(),
                        $telegramUserId,
                        'Solicitação de entrada aprovada automaticamente. Você já pode acessar o grupo/canal.'
                    );
                } catch (TelegramApiException) {
                    // Notificação opcional; a aprovação já ocorreu.
                }
            } catch (TelegramApiException $e) {
                $this->markCommunityFailed(
                    $grant,
                    $row,
                    $e->retryable
                        ? TelegramDeliveryIncident::REASON_INVITE_ERROR
                        : TelegramDeliveryIncident::REASON_DELIVERY_PROCESSING,
                    'Falha ao aprovar solicitação de entrada: '.$e->getMessage().' Confirme se o bot é admin com permissão “Gerenciar solicitações de entrada”.'
                );
                if ($e->retryable) {
                    throw $e;
                }
            }
        }
    }

    private function maybeSendWelcome(TelegramAccessGrant $grant, TelegramAccessCommunity $row): void
    {
        $message = (string) ($grant->snapshot['welcome_message'] ?? '');
        if (trim($message) === '' || ! $grant->telegram_user_id || ! $grant->bot?->plainToken()) {
            return;
        }

        try {
            $this->api->sendMessage(
                $grant->bot->plainToken(),
                $grant->telegram_user_id,
                $message
            );
        } catch (TelegramApiException $e) {
            Log::info('telegram.welcome_failed', [
                'grant_id' => $grant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
