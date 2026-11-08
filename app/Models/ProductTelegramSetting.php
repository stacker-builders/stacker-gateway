<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductTelegramSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'product_id',
        'telegram_bot_id',
        'access_duration',
        'access_duration_unit',
        'is_lifetime',
        'invite_expiration_hours',
        'welcome_message',
        'auto_remove_on_expiry',
    ];

    protected function casts(): array
    {
        return [
            'access_duration' => 'integer',
            'is_lifetime' => 'boolean',
            'invite_expiration_hours' => 'integer',
            'auto_remove_on_expiry' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'telegram_bot_id');
    }

    public function communities(): BelongsToMany
    {
        return $this->belongsToMany(
            TelegramCommunity::class,
            'product_telegram_communities',
            'product_id',
            'telegram_community_id',
            'product_id',
            'id'
        )->withTimestamps();
    }
}
