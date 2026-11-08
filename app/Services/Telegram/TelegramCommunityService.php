<?php

namespace App\Services\Telegram;

use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TelegramCommunityService
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramPolicy $policy,
        private TelegramPermissionChecker $permissions,
    ) {}

    public function link(int $tenantId, TelegramBot $bot, string $chatIdentifier): TelegramCommunity
    {
        $this->assertModuleAvailable($tenantId);
        $this->assertOwnedBot($tenantId, $bot);

        if ($bot->isDisconnected()) {
            throw ValidationException::withMessages([
                'chat_id' => 'Reconecte o bot antes de vincular uma comunidade.',
            ]);
        }

        $token = $bot->plainToken();
        if ($token === null) {
            throw ValidationException::withMessages([
                'chat_id' => 'Bot sem token disponível.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $bot, $chatIdentifier, $token) {
            $chat = $this->api->getChat($token, $this->normalizeChatId($chatIdentifier));
            $chatId = (int) ($chat['id'] ?? 0);
            $type = (string) ($chat['type'] ?? '');

            if ($chatId === 0 || ! in_array($type, [
                TelegramCommunity::TYPE_GROUP,
                TelegramCommunity::TYPE_SUPERGROUP,
                TelegramCommunity::TYPE_CHANNEL,
            ], true)) {
                throw ValidationException::withMessages([
                    'chat_id' => 'Informe um grupo, supergrupo ou canal privado válido.',
                ]);
            }

            $existing = TelegramCommunity::query()
                ->where('telegram_bot_id', $bot->id)
                ->where('telegram_chat_id', $chatId)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                $activeCount = TelegramCommunity::query()->forTenant($tenantId)->active()->count();
                if (! $this->policy->canCreateCommunity($tenantId, $activeCount)) {
                    throw ValidationException::withMessages([
                        'chat_id' => 'Limite de grupos/canais atingido para esta conta.',
                    ]);
                }
            }

            $community = $existing ?? new TelegramCommunity([
                'tenant_id' => $tenantId,
                'telegram_bot_id' => $bot->id,
                'telegram_chat_id' => $chatId,
            ]);

            $community->fill([
                'tenant_id' => $tenantId,
                'telegram_bot_id' => $bot->id,
                'telegram_chat_id' => $chatId,
                'title' => $chat['title'] ?? null,
                'type' => $type,
                'username' => $chat['username'] ?? null,
                'status' => TelegramCommunity::STATUS_LINKED,
                'last_error' => null,
                'unlinked_at' => null,
            ]);
            $community->save();

            return $this->refreshPermissions($tenantId, $community->fresh(['bot']));
        });
    }

    public function refreshPermissions(int $tenantId, TelegramCommunity $community): TelegramCommunity
    {
        $this->assertOwnedCommunity($tenantId, $community);
        $bot = $community->bot;
        if (! $bot || (int) $bot->tenant_id !== $tenantId) {
            abort(404);
        }

        $token = $bot->plainToken();
        if ($token === null) {
            throw ValidationException::withMessages([
                'community' => 'Bot sem token disponível.',
            ]);
        }

        try {
            $member = $this->api->getChatMember($token, $community->telegram_chat_id, $bot->telegram_bot_id);
            $evaluated = $this->permissions->evaluate($community->type, $member);

            $community->forceFill([
                'is_admin' => $evaluated['is_admin'],
                'permissions' => $evaluated['permissions'],
                'ready_for_delivery' => $evaluated['ready_for_delivery'],
                'status' => $evaluated['ready_for_delivery'] || $evaluated['is_admin']
                    ? TelegramCommunity::STATUS_LINKED
                    : TelegramCommunity::STATUS_ERROR,
                'last_error' => $evaluated['ready_for_delivery']
                    ? null
                    : 'Permissões essenciais ausentes. Ajuste as permissões do bot e verifique novamente.',
                'last_checked_at' => now(),
            ])->save();
        } catch (TelegramApiException $e) {
            $community->forceFill([
                'status' => TelegramCommunity::STATUS_ERROR,
                'ready_for_delivery' => false,
                'last_error' => $e->getMessage(),
                'last_checked_at' => now(),
            ])->save();

            if (! $e->retryable) {
                throw ValidationException::withMessages([
                    'community' => $e->getMessage(),
                ]);
            }

            throw ValidationException::withMessages([
                'community' => $e->getMessage(),
            ]);
        }

        return $community->fresh(['bot']);
    }

    public function refreshInfo(int $tenantId, TelegramCommunity $community): TelegramCommunity
    {
        $this->assertOwnedCommunity($tenantId, $community);
        $bot = $community->bot;
        if (! $bot) {
            abort(404);
        }
        $token = $bot->plainToken();
        if ($token === null) {
            throw ValidationException::withMessages([
                'community' => 'Bot sem token disponível.',
            ]);
        }

        try {
            $chat = $this->api->getChat($token, $community->telegram_chat_id);
            $community->forceFill([
                'title' => $chat['title'] ?? $community->title,
                'type' => $chat['type'] ?? $community->type,
                'username' => $chat['username'] ?? null,
                'last_error' => null,
            ])->save();
        } catch (TelegramApiException $e) {
            $community->forceFill([
                'status' => TelegramCommunity::STATUS_ERROR,
                'last_error' => $e->getMessage(),
                'last_checked_at' => now(),
            ])->save();

            throw ValidationException::withMessages([
                'community' => $e->getMessage(),
            ]);
        }

        return $this->refreshPermissions($tenantId, $community->fresh(['bot']));
    }

    public function unlink(int $tenantId, TelegramCommunity $community): TelegramCommunity
    {
        $this->assertOwnedCommunity($tenantId, $community);

        $community->forceFill([
            'status' => TelegramCommunity::STATUS_UNLINKED,
            'ready_for_delivery' => false,
            'unlinked_at' => now(),
            'last_error' => null,
        ])->save();

        return $community->fresh(['bot']);
    }

    private function normalizeChatId(string $value): int|string
    {
        $value = trim($value);
        if ($value === '') {
            throw ValidationException::withMessages([
                'chat_id' => 'Informe o ID ou @username da comunidade.',
            ]);
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        if (! str_starts_with($value, '@')) {
            $value = '@'.$value;
        }

        return $value;
    }

    private function assertModuleAvailable(int $tenantId): void
    {
        if (! $this->policy->moduleAvailable($tenantId)) {
            throw ValidationException::withMessages([
                'telegram' => 'O módulo Telegram não está disponível para esta conta.',
            ]);
        }
    }

    private function assertOwnedBot(int $tenantId, TelegramBot $bot): void
    {
        if ((int) $bot->tenant_id !== $tenantId) {
            abort(404);
        }
    }

    private function assertOwnedCommunity(int $tenantId, TelegramCommunity $community): void
    {
        if ((int) $community->tenant_id !== $tenantId) {
            abort(404);
        }
    }
}
