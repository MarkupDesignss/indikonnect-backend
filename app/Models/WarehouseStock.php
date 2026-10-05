<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStock extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'variant_id',
        'quantity',
    ];

    protected $casts = [
        'warehouse_id' => 'integer',
        'product_id'   => 'integer',
        'variant_id'   => 'integer',
        'quantity'     => 'integer',
    ];

    // ============================================================
    // RELATIONSHIPS
    // ============================================================

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
