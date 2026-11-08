<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramDeliveryIncident extends Model
{
    public const COMPENSATION_NONE = 'none';

    public const COMPENSATION_PENDING_REVIEW = 'pending_review';

    public const COMPENSATION_AUTHORIZED = 'authorized';

    public const COMPENSATION_APPLIED = 'applied';

    public const COMPENSATION_REJECTED = 'rejected';

    public const REASON_BOT_UNAVAILABLE = 'bot_unavailable';

    public const REASON_PERMISSIONS_REMOVED = 'permissions_removed';

    public const REASON_INVITE_ERROR = 'invite_error';

    public const REASON_DELIVERY_PROCESSING = 'delivery_processing_failure';

    public const REASON_COMMUNITY_INACCESSIBLE = 'community_inaccessible';

    protected $fillable = [
        'tenant_id',
        'telegram_access_grant_id',
        'telegram_community_id',
        'idempotency_key',
        'reason_code',
        'reason_detail',
        'started_at',
        'ended_at',
        'duration_seconds',
        'affected_user_ids',
        'affected_community_ids',
        'compensation_status',
        'compensation_seconds',
        'compensation_authorized_at',
        'compensation_applied_at',
        'authorized_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'affected_user_ids' => 'array',
            'affected_community_ids' => 'array',
            'compensation_authorized_at' => 'datetime',
            'compensation_applied_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(TelegramAccessGrant::class, 'telegram_access_grant_id');
    }
}
