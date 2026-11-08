<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramApiClient
{
    /**
     * @return array<string, mixed>
     */
    public function getMe(string $token): array
    {
        return $this->call($token, 'getMe');
    }

    /**
     * @return array<string, mixed>
     */
    public function getChat(string $token, int|string $chatId): array
    {
        return $this->call($token, 'getChat', ['chat_id' => $chatId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getChatMember(string $token, int|string $chatId, int|string $userId): array
    {
        return $this->call($token, 'getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function setWebhook(string $token, array $params): array
    {
        return $this->call($token, 'setWebhook', $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhookInfo(string $token): array
    {
        return $this->call($token, 'getWebhookInfo');
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteWebhook(string $token): array
    {
        return $this->call($token, 'deleteWebhook', ['drop_pending_updates' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeChatInviteLink(string $token, int|string $chatId, string $inviteLink): array
    {
        return $this->call($token, 'revokeChatInviteLink', [
            'chat_id' => $chatId,
            'invite_link' => $inviteLink,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function createChatInviteLink(string $token, int|string $chatId, array $params = []): array
    {
        return $this->call($token, 'createChatInviteLink', array_merge([
            'chat_id' => $chatId,
        ], $params));
    }

    /**
     * @return array<string, mixed>
     */
    public function approveChatJoinRequest(string $token, int|string $chatId, int|string $userId): array
    {
        return $this->call($token, 'approveChatJoinRequest', [
            'chat_id' => $chatId,
            'user_id' => $userId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(string $token, int|string $chatId, string $text): array
    {
        return $this->call($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function banChatMember(string $token, int|string $chatId, int|string $userId, array $params = []): array
    {
        return $this->call($token, 'banChatMember', array_merge([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], $params));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function unbanChatMember(string $token, int|string $chatId, int|string $userId, array $params = []): array
    {
        return $this->call($token, 'unbanChatMember', array_merge([
            'chat_id' => $chatId,
            'user_id' => $userId,
        ], $params));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function call(string $token, string $method, array $params = []): array
    {
        $token = trim($token);
        if ($token === '' || ! preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token)) {
            throw new TelegramApiException('Token do bot inválido.', 400, false);
        }

        $base = rtrim((string) config('telegram.api_base', 'https://api.telegram.org'), '/');
        $url = $base.'/bot'.$token.'/'.$method;
        $timeout = max(3, (int) config('telegram.http_timeout', 12));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($url, $params);
        } catch (ConnectionException $e) {
            throw new TelegramApiException(
                'Falha temporária ao contatar a API do Telegram. Tente novamente.',
                null,
                true,
                ['exception' => $e->getMessage()]
            );
        } catch (RequestException $e) {
            throw new TelegramApiException(
                'A API do Telegram recusou a requisição.',
                $e->response?->status(),
                ($e->response?->status() ?? 0) >= 500,
                ['exception' => $e->getMessage()]
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new TelegramApiException(
                'Resposta inválida da API do Telegram.',
                $response->status(),
                $response->serverError(),
            );
        }

        if (! ($json['ok'] ?? false)) {
            $description = (string) ($json['description'] ?? 'Erro na API do Telegram.');
            $code = isset($json['error_code']) ? (int) $json['error_code'] : $response->status();
            $retryable = in_array($code, [429, 500, 502, 503, 504], true);

            Log::warning('telegram.api.error', [
                'method' => $method,
                'error_code' => $code,
                'description' => $description,
            ]);

            throw new TelegramApiException($description, $code, $retryable, $json);
        }

        $result = $json['result'] ?? [];
        if (! is_array($result)) {
            return ['value' => $result];
        }

        return $result;
    }
}
