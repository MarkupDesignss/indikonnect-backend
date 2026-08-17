<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'commissionable_volume' => 'decimal:2',
        'returned_quantity' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Check if line item can be returned
    public function isReturnable(): bool
    {
        return $this->quantity > $this->returned_quantity;
    }

    // Get available quantity for return
    public function getAvailableForReturnAttribute(): int
    {
        return $this->quantity - $this->returned_quantity;
    }
}
