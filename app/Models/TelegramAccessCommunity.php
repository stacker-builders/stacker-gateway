<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAccessCommunity extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_INVITE_READY = 'invite_ready';

    public const STATUS_AWAITING_JOIN_REQUEST = 'awaiting_join_request';

    public const STATUS_JOINED = 'joined';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'tenant_id',
        'telegram_access_grant_id',
        'telegram_community_id',
        'telegram_chat_id',
        'status',
        'invite_link',
        'invite_link_expires_at',
        'invite_generated_at',
        'join_requested_at',
        'joined_at',
        'last_error',
        'failure_started_at',
        'failure_ended_at',
    ];

    protected $hidden = [
        // invite_link is shown only via toBuyerArray for the owner
    ];

    protected function casts(): array
    {
        return [
            'telegram_chat_id' => 'integer',
            'invite_link_expires_at' => 'datetime',
            'invite_generated_at' => 'datetime',
            'join_requested_at' => 'datetime',
            'joined_at' => 'datetime',
            'failure_started_at' => 'datetime',
            'failure_ended_at' => 'datetime',
        ];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(TelegramAccessGrant::class, 'telegram_access_grant_id');
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(TelegramCommunity::class, 'telegram_community_id');
    }

    public function isJoined(): bool
    {
        return $this->status === self::STATUS_JOINED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toBuyerArray(): array
    {
        $tz = (string) config('app.timezone', 'UTC');
        $community = $this->relationLoaded('community') ? $this->community : null;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'title' => $community?->title,
            'type' => $community?->type,
            'invite_link' => $this->invite_link,
            'invite_link_expires_at' => $this->invite_link_expires_at?->utc()->toIso8601String(),
            'invite_link_expires_at_label' => $this->invite_link_expires_at?->timezone($tz)->format('d/m/Y H:i'),
            'joined_at' => $this->joined_at?->utc()->toIso8601String(),
            'joined_at_label' => $this->joined_at?->timezone($tz)->format('d/m/Y H:i'),
            'last_error' => $this->last_error,
        ];
    }
}
