<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    public const SOURCE_PRODUCT = 'product';

    public const SOURCE_ORDER_BUMP = 'order_bump';

    public const SOURCE_BUNDLE = 'bundle';

    protected $fillable = [
        'order_id',
        'product_id',
        'product_offer_id',
        'subscription_plan_id',
        'amount',
        'position',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function productOffer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function isBundleItem(): bool
    {
        return $this->source === self::SOURCE_BUNDLE;
    }
}
