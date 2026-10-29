<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class McpOauthAccessToken extends Model
{
    public const ACCESS_PREFIX = 'mcp_at_';

    public const REFRESH_PREFIX = 'mcp_rt_';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'public_id',
        'token_hash',
        'token_suffix',
        'refresh_public_id',
        'refresh_hash',
        'expires_at',
        'refresh_expires_at',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
        'refresh_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return array{public_id: string, plain: string, suffix: string}
     */
    public static function issueAccessParts(): array
    {
        do {
            $publicId = Str::lower(Str::random(16));
        } while (static::query()->where('public_id', $publicId)->exists());

        $secret = Str::random(32);
        $plain = self::ACCESS_PREFIX.$publicId.'_'.$secret;

        return [
            'public_id' => $publicId,
            'plain' => $plain,
            'suffix' => substr($plain, -4),
        ];
    }

    /**
     * @return array{public_id: string, plain: string}
     */
    public static function issueRefreshParts(): array
    {
        do {
            $publicId = Str::lower(Str::random(16));
        } while (static::query()->where('refresh_public_id', $publicId)->exists());

        return [
            'public_id' => $publicId,
            'plain' => self::REFRESH_PREFIX.$publicId.'_'.Str::random(32),
        ];
    }

    public static function hashToken(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function parseAccessPublicId(string $plain): ?string
    {
        if (! preg_match('/^mcp_at_([a-z0-9]{16})_[A-Za-z0-9]{32,}$/', $plain, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public static function parseRefreshPublicId(string $plain): ?string
    {
        if (! preg_match('/^mcp_rt_([a-z0-9]{16})_[A-Za-z0-9]{32,}$/', $plain, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public function isAccessExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isRefreshExpired(): bool
    {
        return $this->refresh_expires_at === null || $this->refresh_expires_at->isPast();
    }

    public function verifyAccess(string $plain): bool
    {
        return is_string($this->token_hash)
            && $this->token_hash !== ''
            && password_verify($plain, $this->token_hash);
    }

    public function verifyRefresh(string $plain): bool
    {
        return is_string($this->refresh_hash)
            && $this->refresh_hash !== ''
            && password_verify($plain, $this->refresh_hash);
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
