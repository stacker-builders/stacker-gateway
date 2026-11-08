<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramCommunity extends Model
{
    public const TYPE_GROUP = 'group';

    public const TYPE_SUPERGROUP = 'supergroup';

    public const TYPE_CHANNEL = 'channel';

    public const STATUS_LINKED = 'linked';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNLINKED = 'unlinked';

    protected $fillable = [
        'tenant_id',
        'telegram_bot_id',
        'telegram_chat_id',
        'title',
        'type',
        'username',
        'status',
        'is_admin',
        'permissions',
        'ready_for_delivery',
        'last_error',
        'last_checked_at',
        'unlinked_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_chat_id' => 'integer',
            'is_admin' => 'boolean',
            'permissions' => 'array',
            'ready_for_delivery' => 'boolean',
            'last_checked_at' => 'datetime',
            'unlinked_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_LINKED, self::STATUS_ERROR]);
    }

    public function isUnlinked(): bool
    {
        return $this->status === self::STATUS_UNLINKED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'telegram_bot_id' => $this->telegram_bot_id,
            'telegram_chat_id' => (string) $this->telegram_chat_id,
            'title' => $this->title,
            'type' => $this->type,
            'username' => $this->username,
            'status' => $this->status,
            'is_admin' => $this->is_admin,
            'permissions' => $this->permissions ?? [],
            'ready_for_delivery' => $this->ready_for_delivery,
            'last_error' => $this->last_error,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'bot' => $this->relationLoaded('bot') && $this->bot
                ? [
                    'id' => $this->bot->id,
                    'username' => $this->bot->username,
                    'first_name' => $this->bot->first_name,
                    'status' => $this->bot->status,
                ]
                : null,
        ];
    }
}
