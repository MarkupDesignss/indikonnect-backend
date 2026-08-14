<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'tax_breakdown' => 'array',
        'confirmed_at' => 'datetime',
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
        return $this->status === 'delivered'
            && $this->created_at->diffInDays(now()) <= 30; // Return window
    }
}
