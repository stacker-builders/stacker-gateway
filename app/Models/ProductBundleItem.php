<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBundleItem extends Model
{
    protected $fillable = [
        'parent_product_id',
        'included_product_id',
        'position',
    ];

    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id')->withTrashed();
    }

    public function includedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'included_product_id')->withTrashed();
    }
}
