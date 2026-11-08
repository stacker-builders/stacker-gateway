<?php

namespace App\Services\Telegram;

use App\Jobs\ProcessTelegramWebhookJob;
use App\Models\TelegramAccessCommunity;
use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use App\Models\TelegramWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TelegramWebhookService
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramInviteService $invites,
        private TelegramAccessGrantService $grants,
        private TelegramCommunityService $communities,
    ) {}

    public function syncWebhook(TelegramBot $bot): void
    {
        $token = $bot->plainToken();
        if ($token === null) {
            throw new TelegramApiException('Bot sem token para registrar webhook.');
        }

        $url = route('webhooks.telegram', ['secret' => $bot->webhook_path_secret], absolute: true);
        $this->api->setWebhook($token, [
            'url' => $url,
            'secret_token' => $bot->webhook_secret_token,
            'allowed_updates' => [
                'message',
                'chat_member',
                'my_chat_member',
                'chat_join_request',
            ],
            'drop_pending_updates' => false,
        ]);

        $bot->forceFill([
            'webhook_synced_at' => now(),
            'last_error' => null,
            'status' => TelegramBot::STATUS_CONNECTED,
        ])->save();
    }

    public function handleInbound(Request $request, string $secret): array
    {
        $bot = TelegramBot::query()
            ->where('webhook_path_secret', $secret)
            ->whereIn('status', [TelegramBot::STATUS_CONNECTED, TelegramBot::STATUS_ERROR])
            ->first();

        if (! $bot) {
            return ['received' => true, 'ignored' => true];
        }

        $headerSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($headerSecret === '' || ! hash_equals((string) $bot->webhook_secret_token, $headerSecret)) {
            Log::warning('telegram.webhook.invalid_secret', [
                'bot_id' => $bot->id,
                'tenant_id' => $bot->tenant_id,
            ]);

            return ['received' => true, 'ignored' => true];
        }

        $payload = $request->all();
        if (! is_array($payload) || $payload === []) {
            return ['received' => true, 'ignored' => true];
        }

        $updateId = isset($payload['update_id']) ? (int) $payload['update_id'] : 0;
        if ($updateId <= 0) {
            return ['received' => true, 'ignored' => true];
        }

        $eventType = $this->detectEventType($payload);

        try {
            $event = TelegramWebhookEvent::query()->create([
                'tenant_id' => $bot->tenant_id,
                'telegram_bot_id' => $bot->id,
                'update_id' => $updateId,
                'event_type' => $eventType,
                'status' => TelegramWebhookEvent::STATUS_RECEIVED,
                'payload' => $this->safePayload($payload),
            ]);
        } catch (QueryException) {
            return ['received' => true, 'duplicate' => true];
        }

        ProcessTelegramWebhookJob::dispatch((int) $event->id);

        return ['received' => true, 'queued' => true];
    }

    public function processEvent(TelegramWebhookEvent $event): void
    {
        $bot = $event->bot;
        if (! $bot || $bot->isDisconnected()) {
            $event->forceFill([
                'status' => TelegramWebhookEvent::STATUS_IGNORED,
                'processed_at' => now(),
            ])->save();

            return;
        }

        $payload = is_array($event->payload) ? $event->payload : [];

        try {
            match ($event->event_type) {
                'chat_member' => $this->handleChatMember($bot, $payload['chat_member'] ?? []),
                'chat_join_request' => $this->handleJoinRequest($bot, $payload['chat_join_request'] ?? []),
                'my_chat_member' => $this->handleMyChatMember($bot, $payload['my_chat_member'] ?? []),
                'message' => $this->handleMessage($bot, $payload['message'] ?? []),
                default => null,
            };

            $event->forceFill([
                'status' => TelegramWebhookEvent::STATUS_PROCESSED,
                'processed_at' => now(),
                'error' => null,
            ])->save();
        } catch (\Throwable $e) {
            $event->forceFill([
                'status' => TelegramWebhookEvent::STATUS_FAILED,
                'processed_at' => now(),
                'error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $chatMember
     */
    private function handleChatMember(TelegramBot $bot, array $chatMember): void
    {
        if ($chatMember === []) {
            return;
        }

        $chatId = (int) ($chatMember['chat']['id'] ?? 0);
        $user = is_array($chatMember['new_chat_member']['user'] ?? null)
            ? $chatMember['new_chat_member']['user']
            : [];
        $telegramUserId = (int) ($user['id'] ?? 0);
        $newStatus = (string) ($chatMember['new_chat_member']['status'] ?? '');
        $oldStatus = (string) ($chatMember['old_chat_member']['status'] ?? '');

        if ($chatId === 0 || $telegramUserId <= 0) {
            return;
        }

        $joinedStatuses = ['member', 'administrator', 'creator', 'restricted'];
        $isNowInside = in_array($newStatus, $joinedStatuses, true);
        $wasInside = in_array($oldStatus, $joinedStatuses, true);
        // Entrada efetiva: transição para "dentro" do chat (eventos fora de ordem ainda confirmam).
        if (! $isNowInside || $wasInside) {
            return;
        }

        $date = isset($chatMember['date'])
            ? Carbon::createFromTimestamp((int) $chatMember['date'])->utc()
            : now()->utc();

        $inviteLinkUsed = null;
        if (is_array($chatMember['invite_link'] ?? null)) {
            $inviteLinkUsed = (string) ($chatMember['invite_link']['invite_link'] ?? '');
            if ($inviteLinkUsed === '') {
                $inviteLinkUsed = null;
            }
        }

        $rows = $this->authorizedPendingRowsForEntry(
            (int) $bot->tenant_id,
            $bot->id,
            $chatId,
            $telegramUserId,
            $inviteLinkUsed,
        );

        if ($rows->isEmpty()) {
            Log::info('telegram.entry.unproven_identity', [
                'tenant_id' => $bot->tenant_id,
                'bot_id' => $bot->id,
                'chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
                'has_invite_link' => $inviteLinkUsed !== null,
                // Sem invite_link: pode ser convite externo, preexistente (já filtrado) ou evento incompleto.
                // Não confirma entrega comercial e não remove o usuário.
            ]);

            return;
        }

        foreach ($rows as $row) {
            $grant = $row->grant;
            if (! $grant) {
                continue;
            }
            $this->invites->confirmEntry(
                $grant,
                $row,
                $telegramUserId,
                isset($user['username']) ? (string) $user['username'] : null,
                isset($user['first_name']) ? (string) $user['first_name'] : null,
                $date,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $joinRequest
     */
    private function handleJoinRequest(TelegramBot $bot, array $joinRequest): void
    {
        if ($joinRequest === []) {
            return;
        }

        $chatId = (int) ($joinRequest['chat']['id'] ?? 0);
        $user = is_array($joinRequest['from'] ?? null) ? $joinRequest['from'] : [];
        $telegramUserId = (int) ($user['id'] ?? 0);
        if ($chatId === 0 || $telegramUserId <= 0) {
            return;
        }

        $inviteLinkUsed = null;
        if (is_array($joinRequest['invite_link'] ?? null)) {
            $inviteLinkUsed = (string) ($joinRequest['invite_link']['invite_link'] ?? '');
            if ($inviteLinkUsed === '') {
                $inviteLinkUsed = null;
            }
        }

        $rows = $this->authorizedPendingRowsForEntry(
            (int) $bot->tenant_id,
            $bot->id,
            $chatId,
            $telegramUserId,
            $inviteLinkUsed,
        );

        if ($rows->isEmpty()) {
            Log::info('telegram.join_request.unproven_identity', [
                'tenant_id' => $bot->tenant_id,
                'chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
            ]);

            return;
        }

        foreach ($rows as $row) {
            $grant = $row->grant;
            if (! $grant) {
                continue;
            }
            $this->invites->markJoinRequest(
                $grant,
                $row,
                $telegramUserId,
                isset($user['username']) ? (string) $user['username'] : null,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $myChatMember
     */
    private function handleMyChatMember(TelegramBot $bot, array $myChatMember): void
    {
        $chat = is_array($myChatMember['chat'] ?? null) ? $myChatMember['chat'] : [];
        $chatId = (int) ($chat['id'] ?? 0);
        if ($chatId === 0) {
            return;
        }

        $newStatus = (string) ($myChatMember['new_chat_member']['status'] ?? '');
        $joinedStatuses = ['member', 'administrator', 'creator', 'restricted'];
        $leftStatuses = ['left', 'kicked'];

        if (in_array($newStatus, $joinedStatuses, true)) {
            $this->communities->rememberMembership($bot, $chat, $newStatus);
        } elseif (in_array($newStatus, $leftStatuses, true)) {
            $this->communities->forgetMembership($bot, $chatId);
        }

        // Mudanças de permissão do bot: reprocessamento de convites pendentes do chat.
        if (! in_array($newStatus, ['administrator', 'creator'], true)) {
            return;
        }

        $grantIds = TelegramAccessCommunity::query()
            ->where('tenant_id', $bot->tenant_id)
            ->where('telegram_chat_id', $chatId)
            ->whereIn('status', [
                TelegramAccessCommunity::STATUS_PENDING,
                TelegramAccessCommunity::STATUS_FAILED,
            ])
            ->pluck('telegram_access_grant_id')
            ->unique()
            ->all();

        foreach ($grantIds as $grantId) {
            $grant = TelegramAccessGrant::query()->with(['bot', 'communities.community'])->find($grantId);
            if ($grant && (int) $grant->telegram_bot_id === (int) $bot->id) {
                $this->invites->processGrant($grant, true);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleMessage(TelegramBot $bot, array $message): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $telegramUserId = (int) ($from['id'] ?? 0);
        if ($telegramUserId <= 0 || $text === '') {
            return;
        }

        // /start link_<grantId>_<nonce>_<hmac> — deep link assinado (não enumerável).
        $parsed = TelegramIdentityLink::parse($text);
        if ($parsed === null) {
            if (preg_match('/^\/start(?:@\w+)?(?:\s|$)/i', $text) === 1) {
                $this->notifyBuyer(
                    $bot,
                    $telegramUserId,
                    'Para vincular sua compra, volte à página de acesso e toque em “Abrir bot do Telegram” (o Start precisa vir com o link da compra).'
                );
            }

            return;
        }

        $grant = TelegramAccessGrant::query()
            ->where('tenant_id', $bot->tenant_id)
            ->where('telegram_bot_id', $bot->id)
            ->whereKey($parsed['grant_id'])
            ->first();

        if (! $grant || ! TelegramIdentityLink::verify($grant, $parsed['nonce'], $parsed['signature'])) {
            $this->notifyBuyer(
                $bot,
                $telegramUserId,
                'Não foi possível vincular este Start à sua compra. Abra novamente o bot pelo botão da página de acesso.'
            );

            return;
        }

        if (in_array($grant->status, [
            TelegramAccessGrant::STATUS_REVOKED,
            TelegramAccessGrant::STATUS_EXPIRED,
        ], true)) {
            $this->notifyBuyer($bot, $telegramUserId, 'Este acesso não está mais ativo.');

            return;
        }

        $this->grants->linkIdentity(
            $grant,
            $telegramUserId,
            isset($from['username']) ? (string) $from['username'] : null,
            isset($from['first_name']) ? (string) $from['first_name'] : null,
        );

        $this->invites->processGrant($grant->fresh(['bot', 'communities.community']));

        $this->notifyBuyer(
            $bot,
            $telegramUserId,
            'Conta vinculada com sucesso. Volte à página da compra: a tela atualiza sozinha e aí você pode entrar no grupo/canal. Se o Telegram pedir aprovação, o bot libera automaticamente (precisa estar como admin com permissão de solicitações).'
        );
    }

    private function notifyBuyer(TelegramBot $bot, int $telegramUserId, string $text): void
    {
        $token = $bot->plainToken();
        if ($token === null || $telegramUserId <= 0 || trim($text) === '') {
            return;
        }

        try {
            $this->api->sendMessage($token, $telegramUserId, $text);
        } catch (TelegramApiException $e) {
            Log::info('telegram.buyer_notify_failed', [
                'bot_id' => $bot->id,
                'telegram_user_id' => $telegramUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Só autoriza entrega comercial com identidade comprovada (comprador ↔ grant ↔ telegram_user_id).
     *
     * - Com invite_link no evento: deve coincidir com o convite do direito.
     * - Sem invite_link: NÃO presume autorização. Só confirma se já aprovamos
     *   join request oficial (STATUS_AWAITING_JOIN_REQUEST), o que prova o fluxo Stacker.
     * - Preexistentes (já member) não chegam aqui (filtro wasInside).
     * - Convite externo / entrada sem evidência: log + sem confirmação; sem remoção.
     *
     * @return \Illuminate\Support\Collection<int, TelegramAccessCommunity>
     */
    private function authorizedPendingRowsForEntry(
        int $tenantId,
        int $botId,
        int $chatId,
        int $telegramUserId,
        ?string $inviteLinkUsed,
    ) {
        return TelegramAccessCommunity::query()
            ->where('tenant_id', $tenantId)
            ->where('telegram_chat_id', $chatId)
            ->whereIn('status', [
                TelegramAccessCommunity::STATUS_PENDING,
                TelegramAccessCommunity::STATUS_INVITE_READY,
                TelegramAccessCommunity::STATUS_AWAITING_JOIN_REQUEST,
                TelegramAccessCommunity::STATUS_FAILED,
            ])
            ->whereHas('grant', function ($q) use ($botId, $telegramUserId) {
                $q->where('telegram_bot_id', $botId)
                    ->whereNotIn('status', [
                        TelegramAccessGrant::STATUS_REVOKED,
                        TelegramAccessGrant::STATUS_EXPIRED,
                    ])
                    ->where('telegram_user_id', $telegramUserId);
            })
            ->with('grant')
            ->orderBy('id')
            ->get()
            ->filter(function (TelegramAccessCommunity $row) use ($inviteLinkUsed) {
                if ($inviteLinkUsed === null) {
                    return $row->status === TelegramAccessCommunity::STATUS_AWAITING_JOIN_REQUEST
                        && filled($row->invite_link);
                }

                return filled($row->invite_link) && hash_equals((string) $row->invite_link, $inviteLinkUsed);
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function detectEventType(array $payload): string
    {
        foreach ([
            'message',
            'edited_message',
            'channel_post',
            'my_chat_member',
            'chat_member',
            'chat_join_request',
            'callback_query',
        ] as $key) {
            if (isset($payload[$key])) {
                return $key;
            }
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function safePayload(array $payload): array
    {
        unset($payload['token'], $payload['secret_token']);

        return $payload;
    }
}
