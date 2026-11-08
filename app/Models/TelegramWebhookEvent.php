<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramWebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'telegram_bot_id',
        'update_id',
        'event_type',
        'status',
        'payload',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }
}
