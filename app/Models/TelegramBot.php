<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TelegramBot extends Model
{
    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'tenant_id',
        'telegram_bot_id',
        'username',
        'first_name',
        'can_join_groups',
        'can_read_all_group_messages',
        'supports_inline_queries',
        'token_encrypted',
        'webhook_path_secret',
        'webhook_secret_token',
        'status',
        'last_error',
        'last_checked_at',
        'webhook_synced_at',
        'connected_at',
        'disconnected_at',
    ];

    protected $hidden = [
        'token_encrypted',
        'webhook_secret_token',
    ];

    protected function casts(): array
    {
        return [
            'telegram_bot_id' => 'integer',
            'token_encrypted' => 'encrypted',
            'can_join_groups' => 'boolean',
            'can_read_all_group_messages' => 'boolean',
            'supports_inline_queries' => 'boolean',
            'last_checked_at' => 'datetime',
            'webhook_synced_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    public function communities(): HasMany
    {
        return $this->hasMany(TelegramCommunity::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(TelegramWebhookEvent::class);
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_CONNECTED, self::STATUS_ERROR]);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function isDisconnected(): bool
    {
        return $this->status === self::STATUS_DISCONNECTED;
    }

    public function plainToken(): ?string
    {
        $token = $this->token_encrypted;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function makePathSecret(): string
    {
        return Str::random(48);
    }

    public static function makeWebhookSecretToken(): string
    {
        return Str::random(48);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $includeCommunities = false): array
    {
        $payload = [
            'id' => $this->id,
            'telegram_bot_id' => $this->telegram_bot_id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'status' => $this->status,
            'last_error' => $this->last_error,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'webhook_synced_at' => $this->webhook_synced_at?->toIso8601String(),
            'connected_at' => $this->connected_at?->toIso8601String(),
            'has_token' => $this->plainToken() !== null,
            'communities_count' => $this->relationLoaded('communities')
                ? $this->communities->where('status', '!=', TelegramCommunity::STATUS_UNLINKED)->count()
                : null,
        ];

        if ($includeCommunities) {
            $payload['communities'] = $this->communities
                ->where('status', '!=', TelegramCommunity::STATUS_UNLINKED)
                ->values()
                ->map(fn (TelegramCommunity $community) => $community->toPublicArray())
                ->all();
        }

        return $payload;
    }
}
