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
    public function review()
    {
        return $this->hasOne(ProductReview::class);
    }
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Check if line item can be returned
    // public function isReturnable(): bool
    // {
    //     return $this->quantity > $this->returned_quantity;
    // }

    // // Get available quantity for return
    // public function getAvailableForReturnAttribute(): int
    // {
    //     return $this->quantity - $this->returned_quantity;
    // }

    public function isDelivered(): bool
    {
        return $this->delivery_status === 'delivered';
    }

    /**
     * Check if this item is returnable.
     */
    public function isReturnable()
    {
        // Only delivered items can be returned
        if ($this->delivery_status !== 'delivered') {
            return false;
        }

        // Check if return window hasn't expired (30 days from delivery)
        $returnWindow = setting('return_window_days', 30);
        if ($this->delivered_at && now()->diffInDays($this->delivered_at) > $returnWindow) {
        return false;
        }

        // Check if item hasn't been returned already
        if ($this->return_status === 'returned') {
            return false;
        }

        // Check if there's no pending or approved return
        if (in_array($this->return_status, ['pending', 'approved'])) {
            return false;
        }

        // Check product returnability flag
        return $this->is_returnable;
    }

    /**
     * Get available quantity for return.
     */
    public function getAvailableForReturnAttribute(): int
    {
        $purchased = (int) $this->quantity;
        $alreadyReturned = (int) ($this->returned_quantity ?? 0);

        // Only delivered items can be returned
        if ($this->delivery_status !== 'delivered') {
            return 0;
        }

        // If already fully returned
        if ($this->return_status === 'returned') {
            return 0;
        }

        return max(0, $purchased - $alreadyReturned);
    }
}