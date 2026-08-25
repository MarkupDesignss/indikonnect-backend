<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'tax_breakdown' => 'array',
        'confirmed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'total_gst' => 'decimal:2',
        'shipping_charge' => 'decimal:2',
        'coin_redeemed' => 'decimal:2',
        'total_payable' => 'decimal:2',
        'amount_paid' => 'decimal:2',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function lines()
    {
        return $this->hasMany(OrderLine::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function billingAddress()
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    public function deliveryAddress()
    {
        return $this->belongsTo(Address::class, 'delivery_address_id');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function returns()
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function coinRedemptions()
    {
        return $this->hasMany(CoinRedemption::class);
    }

    public function commissionEvents()
    {
        return $this->hasMany(CommissionApiEvent::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['delivered', 'confirmed']);
    }

    // Helpers
    public function isCancellable(): bool
    {
        return in_array($this->status, ['pending', 'confirmed', 'processing']);
    }

    public function isReturnable(): bool
    {
        $returnWindow = setting('return_window_days', 30);
        return $this->status === 'delivered'
            && $this->delivered_at
            && $this->delivered_at->diffInDays(now()) <= $returnWindow;
    }

    public function hasPendingReturn(): bool
    {
        return $this->returns()
            ->where('status', 'pending')
            ->exists();
    }

    public function hasApprovedReturn(): bool
    {
        return $this->returns()
            ->whereIn('status', ['approved', 'received', 'completed'])
            ->exists();
    }

     /**
     * Check if order is within cooling-off period (30 days from purchase date).
     * FR-CO-013
     */
    public function isWithinCoolingOff(): bool
    {
        $coolingOffDays = (int) setting('cooling_off_days', 30);
        return $this->created_at->diffInDays(now()) <= $coolingOffDays;
    }

    /**
     * Check if order is within buy-back window (30 days from purchase date).
    */

    public function isWithinBuybackWindow(): bool
    {
        $buybackWindow = (int) setting('buyback_window_days', 30);
        return $this->created_at->diffInDays(now()) <= $buybackWindow;
    }

    /**
     * Get remaining days for cooling-off.
    */
    public function getRemainingCoolingOffDays(): int
    {
        $coolingOffDays = (int) setting('cooling_off_days', 30);
        $daysPassed = $this->created_at->diffInDays(now());
        return max(0, $coolingOffDays - $daysPassed);
    }

    
    /**
     * Get remaining days for buy-back.
    */
    public function getRemainingBuybackDays(): int
    {
        $buybackWindow = (int) setting('buyback_window_days', 30);
        $daysPassed = $this->created_at->diffInDays(now());
        return max(0, $buybackWindow - $daysPassed);
    }
}