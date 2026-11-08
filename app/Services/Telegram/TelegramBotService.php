<?php

namespace App\Services\Telegram;

use App\Models\TelegramBot;
use App\Models\TelegramCommunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TelegramBotService
{
    public function __construct(
        private TelegramApiClient $api,
        private TelegramPolicy $policy,
        private TelegramWebhookService $webhooks,
    ) {}

    /**
     * @return array{bot: TelegramBot, me: array<string, mixed>}
     */
    public function previewToken(int $tenantId, string $token): array
    {
        $this->assertModuleAvailable($tenantId);
        $me = $this->api->getMe($token);
        $telegramBotId = (int) ($me['id'] ?? 0);
        if ($telegramBotId <= 0) {
            throw ValidationException::withMessages([
                'token' => 'A API do Telegram não retornou um ID de bot válido.',
            ]);
        }

        $existing = TelegramBot::query()->where('telegram_bot_id', $telegramBotId)->first();
        if ($existing && (int) $existing->tenant_id !== $tenantId && ! $existing->isDisconnected()) {
            throw ValidationException::withMessages([
                'token' => 'Este bot já está conectado em outra conta.',
            ]);
        }

        return ['bot_preview' => $this->mapMe($me), 'me' => $me, 'telegram_bot_id' => $telegramBotId];
    }

    public function connect(int $tenantId, string $token): TelegramBot
    {
        $this->assertModuleAvailable($tenantId);

        $bot = DB::transaction(function () use ($tenantId, $token) {
            $me = $this->api->getMe($token);
            $telegramBotId = (int) ($me['id'] ?? 0);
            if ($telegramBotId <= 0) {
                throw ValidationException::withMessages([
                    'token' => 'A API do Telegram não retornou um ID de bot válido.',
                ]);
            }

            $existing = TelegramBot::query()
                ->where('telegram_bot_id', $telegramBotId)
                ->lockForUpdate()
                ->first();

            if ($existing && (int) $existing->tenant_id !== $tenantId) {
                throw ValidationException::withMessages([
                    'token' => 'Este bot já está conectado em outra conta.',
                ]);
            }

            if (! $existing) {
                $activeCount = TelegramBot::query()->forTenant($tenantId)->active()->count();
                if (! $this->policy->canCreateBot($tenantId, $activeCount)) {
                    throw ValidationException::withMessages([
                        'token' => 'Limite de bots atingido para esta conta.',
                    ]);
                }
            } elseif ($existing->isDisconnected()) {
                $activeCount = TelegramBot::query()->forTenant($tenantId)->active()->count();
                if (! $this->policy->canCreateBot($tenantId, $activeCount)) {
                    throw ValidationException::withMessages([
                        'token' => 'Limite de bots atingido para esta conta.',
                    ]);
                }
            }

            $bot = $existing ?? new TelegramBot([
                'tenant_id' => $tenantId,
                'telegram_bot_id' => $telegramBotId,
                'webhook_path_secret' => TelegramBot::makePathSecret(),
                'webhook_secret_token' => TelegramBot::makeWebhookSecretToken(),
            ]);

            $bot->fill([
                'tenant_id' => $tenantId,
                'telegram_bot_id' => $telegramBotId,
                'username' => $me['username'] ?? null,
                'first_name' => $me['first_name'] ?? null,
                'can_join_groups' => isset($me['can_join_groups']) ? (bool) $me['can_join_groups'] : null,
                'can_read_all_group_messages' => isset($me['can_read_all_group_messages']) ? (bool) $me['can_read_all_group_messages'] : null,
                'supports_inline_queries' => isset($me['supports_inline_queries']) ? (bool) $me['supports_inline_queries'] : null,
                'token_encrypted' => $token,
                'status' => TelegramBot::STATUS_CONNECTED,
                'last_error' => null,
                'last_checked_at' => now(),
                'connected_at' => now(),
                'disconnected_at' => null,
            ]);

            if (! $bot->webhook_path_secret) {
                $bot->webhook_path_secret = TelegramBot::makePathSecret();
            }
            if (! $bot->webhook_secret_token) {
                $bot->webhook_secret_token = TelegramBot::makeWebhookSecretToken();
            }

            $bot->save();

            return $bot->fresh();
        });

        // Webhook HTTP fora da transação (evita lock longo e falha após commit bem-sucedido).
        try {
            $this->webhooks->syncWebhook($bot);
        } catch (TelegramApiException $e) {
            $bot->forceFill([
                'status' => TelegramBot::STATUS_ERROR,
                'last_error' => 'Bot conectado, mas o webhook falhou: '.$e->getMessage(),
                'last_checked_at' => now(),
            ])->save();
        }

        return $bot->fresh();
    }

    public function updateToken(int $tenantId, TelegramBot $bot, string $token): TelegramBot
    {
        $this->assertOwned($tenantId, $bot);
        $this->assertModuleAvailable($tenantId);

        $me = $this->api->getMe($token);
        $telegramBotId = (int) ($me['id'] ?? 0);
        if ($telegramBotId <= 0) {
            throw ValidationException::withMessages([
                'token' => 'A API do Telegram não retornou um ID de bot válido.',
            ]);
        }

        if ($telegramBotId !== (int) $bot->telegram_bot_id) {
            throw ValidationException::withMessages([
                'token' => 'O token informado pertence a outro bot. Conecte-o como um bot novo.',
            ]);
        }

        $bot->fill([
            'username' => $me['username'] ?? $bot->username,
            'first_name' => $me['first_name'] ?? $bot->first_name,
            'token_encrypted' => $token,
            'status' => TelegramBot::STATUS_CONNECTED,
            'last_error' => null,
            'last_checked_at' => now(),
            'disconnected_at' => null,
            'connected_at' => $bot->connected_at ?? now(),
        ]);
        $bot->webhook_secret_token = TelegramBot::makeWebhookSecretToken();
        $bot->save();

        try {
            $this->webhooks->syncWebhook($bot);
        } catch (TelegramApiException $e) {
            $bot->forceFill([
                'status' => TelegramBot::STATUS_ERROR,
                'last_error' => 'Token atualizado, mas o webhook falhou: '.$e->getMessage(),
            ])->save();
        }

        return $bot->fresh();
    }

    public function testConnection(int $tenantId, TelegramBot $bot): TelegramBot
    {
        $this->assertOwned($tenantId, $bot);
        $token = $bot->plainToken();
        if ($token === null) {
            throw ValidationException::withMessages([
                'bot' => 'Este bot não possui token armazenado.',
            ]);
        }

        try {
            $me = $this->api->getMe($token);
            $bot->forceFill([
                'username' => $me['username'] ?? $bot->username,
                'first_name' => $me['first_name'] ?? $bot->first_name,
                'status' => TelegramBot::STATUS_CONNECTED,
                'last_error' => null,
                'last_checked_at' => now(),
            ])->save();
        } catch (TelegramApiException $e) {
            $bot->forceFill([
                'status' => TelegramBot::STATUS_ERROR,
                'last_error' => $e->getMessage(),
                'last_checked_at' => now(),
            ])->save();

            throw ValidationException::withMessages([
                'bot' => $e->getMessage(),
            ]);
        }

        return $bot->fresh();
    }

    public function disconnect(int $tenantId, TelegramBot $bot): TelegramBot
    {
        $this->assertOwned($tenantId, $bot);

        $activeCommunities = TelegramCommunity::query()
            ->where('telegram_bot_id', $bot->id)
            ->active()
            ->count();

        if ($activeCommunities > 0) {
            throw ValidationException::withMessages([
                'bot' => 'Desvincule as comunidades deste bot antes de desconectá-lo.',
            ]);
        }

        $token = $bot->plainToken();
        if ($token) {
            try {
                $this->api->deleteWebhook($token);
            } catch (TelegramApiException) {
                // Mantém desconexão local mesmo se a API falhar.
            }
        }

        $bot->forceFill([
            'status' => TelegramBot::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
            'last_error' => null,
            'webhook_synced_at' => null,
        ])->save();

        return $bot->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMe(array $me): array
    {
        return [
            'telegram_bot_id' => (int) ($me['id'] ?? 0),
            'username' => $me['username'] ?? null,
            'first_name' => $me['first_name'] ?? null,
            'can_join_groups' => isset($me['can_join_groups']) ? (bool) $me['can_join_groups'] : null,
            'can_read_all_group_messages' => isset($me['can_read_all_group_messages']) ? (bool) $me['can_read_all_group_messages'] : null,
            'supports_inline_queries' => isset($me['supports_inline_queries']) ? (bool) $me['supports_inline_queries'] : null,
        ];
    }

    private function assertModuleAvailable(int $tenantId): void
    {
        if (! $this->policy->moduleAvailable($tenantId)) {
            throw ValidationException::withMessages([
                'telegram' => 'O módulo Telegram não está disponível para esta conta.',
            ]);
        }
    }

    private function assertOwned(int $tenantId, TelegramBot $bot): void
    {
        if ((int) $bot->tenant_id !== $tenantId) {
            abort(404);
        }
    }
}
