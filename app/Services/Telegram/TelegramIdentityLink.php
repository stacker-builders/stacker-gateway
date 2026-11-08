<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deep link /start seguro.
 *
 * Telegram limita o parâmetro start a 64 caracteres. Formato compacto:
 * t{grantId}_{nonce8}_{sig16}
 *
 * Mantém parse/verify do formato legado link_{id}_{nonce24}_{sig32}.
 */
class TelegramIdentityLink
{
    public const START_PARAM_MAX = 64;

    public static function ensureNonce(TelegramAccessGrant $grant): string
    {
        // Nonce curto (8) para caber no limite do Telegram.
        if (is_string($grant->identity_link_nonce) && preg_match('/^[a-z0-9]{8}$/', $grant->identity_link_nonce) === 1) {
            return $grant->identity_link_nonce;
        }

        $nonce = Str::lower(Str::random(8));
        $grant->forceFill(['identity_link_nonce' => $nonce])->save();

        return $nonce;
    }

    public static function payload(TelegramAccessGrant $grant): string
    {
        $nonce = self::ensureNonce($grant);
        $sig = self::signature((int) $grant->id, (int) $grant->tenant_id, (int) $grant->user_id, $nonce, 16);
        $payload = 't'.$grant->id.'_'.$nonce.'_'.$sig;

        if (strlen($payload) > self::START_PARAM_MAX) {
            Log::error('telegram.identity_link.payload_too_long', [
                'grant_id' => $grant->id,
                'length' => strlen($payload),
                'payload' => $payload,
            ]);
        }

        return $payload;
    }

    public static function botUrl(TelegramAccessGrant $grant, ?TelegramBot $bot = null): ?string
    {
        $bot = $bot ?? $grant->bot;
        $username = trim((string) ($bot?->username ?? ''));
        if ($username === '') {
            return null;
        }

        // rawurlencode evita clientes que quebram o querystring; Telegram aceita A-Z a-z 0-9 _ -
        return 'https://t.me/'.$username.'?start='.rawurlencode(self::payload($grant));
    }

    /**
     * Código curto (nonce) para o comprador enviar manualmente se o deep link falhar.
     */
    public static function manualCode(TelegramAccessGrant $grant): string
    {
        return self::ensureNonce($grant);
    }

    /**
     * Extrai código de 8 chars de "/start CODE", "/vincular CODE" ou mensagem só com o código.
     */
    public static function extractManualCode(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/^\/(?:start|vincular)(?:@\w+)?\s+([a-z0-9]{8})$/i', $text, $m) === 1) {
            return Str::lower($m[1]);
        }
        if (preg_match('/^[a-z0-9]{8}$/i', $text) === 1) {
            return Str::lower($text);
        }

        return null;
    }

    /**
     * @return array{grant_id: int, nonce: string, signature: string}|null
     */
    public static function parse(string $text): ?array
    {
        $text = trim($text);

        // Formato compacto atual: t{id}_{nonce8}_{sig16+}
        if (preg_match('/^\/start(?:@\w+)?\s+t(\d+)_([a-z0-9]{6,16})_([a-f0-9]{8,64})$/i', $text, $m) === 1) {
            return [
                'grant_id' => (int) $m[1],
                'nonce' => (string) $m[2],
                'signature' => strtolower((string) $m[3]),
            ];
        }

        // Legado: link_{id}_{nonce}_{sig}
        if (preg_match('/^\/start(?:@\w+)?\s+link_(\d+)_([a-z0-9]+)_([a-f0-9]{16,64})$/i', $text, $m) === 1) {
            return [
                'grant_id' => (int) $m[1],
                'nonce' => (string) $m[2],
                'signature' => strtolower((string) $m[3]),
            ];
        }

        return null;
    }

    public static function verify(TelegramAccessGrant $grant, string $nonce, string $signature): bool
    {
        if (! is_string($grant->identity_link_nonce) || $grant->identity_link_nonce === '') {
            return false;
        }
        if (! hash_equals($grant->identity_link_nonce, $nonce)) {
            return false;
        }

        $ttlHours = (int) config('telegram.identity_link_ttl_hours', 72);
        if ($ttlHours > 0 && $grant->created_at && $grant->created_at->lte(now()->subHours($ttlHours))) {
            return false;
        }

        $signature = strtolower($signature);
        $expected16 = self::signature((int) $grant->id, (int) $grant->tenant_id, (int) $grant->user_id, $nonce, 16);
        $expected32 = self::signature((int) $grant->id, (int) $grant->tenant_id, (int) $grant->user_id, $nonce, 32);

        return hash_equals($expected16, $signature) || hash_equals($expected32, $signature);
    }

    private static function signature(int $grantId, int $tenantId, int $userId, string $nonce, int $length = 16): string
    {
        $raw = $grantId.'|'.$tenantId.'|'.$userId.'|'.$nonce;

        return substr(hash_hmac('sha256', $raw, (string) config('app.key')), 0, $length);
    }
}
