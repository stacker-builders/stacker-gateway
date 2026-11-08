<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAccessCompensation extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'telegram_access_compensations';

    protected $fillable = [
        'tenant_id',
        'telegram_access_grant_id',
        'telegram_delivery_incident_id',
        'idempotency_key',
        'seconds_added',
        'previous_ends_at',
        'new_ends_at',
        'status',
        'authorized_by',
        'reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'previous_ends_at' => 'datetime',
            'new_ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(TelegramAccessGrant::class, 'telegram_access_grant_id');
    }
}
