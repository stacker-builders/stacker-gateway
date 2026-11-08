<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccessGrant;
use App\Models\TelegramBot;
use Illuminate\Support\Str;

/**
 * Deep link /start seguro: link_<grantId>_<nonce>_<hmac>.
 * Evita sequestro por enumeração de IDs sequenciais.
 */
class TelegramIdentityLink
{
    public static function ensureNonce(TelegramAccessGrant $grant): string
    {
        if (is_string($grant->identity_link_nonce) && $grant->identity_link_nonce !== '') {
            return $grant->identity_link_nonce;
        }

        $nonce = Str::lower(Str::random(24));
        $grant->forceFill(['identity_link_nonce' => $nonce])->save();

        return $nonce;
    }

    public static function payload(TelegramAccessGrant $grant): string
    {
        $nonce = self::ensureNonce($grant);
        $sig = self::signature((int) $grant->id, (int) $grant->tenant_id, (int) $grant->user_id, $nonce);

        return 'link_'.$grant->id.'_'.$nonce.'_'.$sig;
    }

    public static function botUrl(TelegramAccessGrant $grant, ?TelegramBot $bot = null): ?string
    {
        $bot = $bot ?? $grant->bot;
        $username = trim((string) ($bot?->username ?? ''));
        if ($username === '') {
            return null;
        }

        return 'https://t.me/'.$username.'?start='.self::payload($grant);
    }

    /**
     * @return array{grant_id: int, nonce: string}|null
     */
    public static function parse(string $text): ?array
    {
        if (! preg_match('/^\/start(?:@\w+)?\s+link_(\d+)_([a-z0-9]+)_([a-f0-9]{16,64})$/i', trim($text), $m)) {
            return null;
        }

        return [
            'grant_id' => (int) $m[1],
            'nonce' => (string) $m[2],
            'signature' => strtolower((string) $m[3]),
        ];
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

        $expected = self::signature((int) $grant->id, (int) $grant->tenant_id, (int) $grant->user_id, $nonce);

        return hash_equals($expected, strtolower($signature));
    }

    private static function signature(int $grantId, int $tenantId, int $userId, string $nonce): string
    {
        $raw = $grantId.'|'.$tenantId.'|'.$userId.'|'.$nonce;

        return substr(hash_hmac('sha256', $raw, (string) config('app.key')), 0, 32);
    }
}
