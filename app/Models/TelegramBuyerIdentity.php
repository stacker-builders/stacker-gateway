<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramBuyerIdentity extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'telegram_user_id',
        'telegram_username',
        'telegram_first_name',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'linked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
