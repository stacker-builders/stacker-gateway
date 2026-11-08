<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAccessOrderLink extends Model
{
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_RENEWAL = 'renewal';

    public const TYPE_EXTENSION = 'extension';

    protected $fillable = [
        'tenant_id',
        'telegram_access_grant_id',
        'order_id',
        'product_id',
        'link_type',
    ];

    public function grant(): BelongsTo
    {
        return $this->belongsTo(TelegramAccessGrant::class, 'telegram_access_grant_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
