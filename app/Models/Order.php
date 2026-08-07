<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_reference', 'user_id', 'billing_address_id',
        'delivery_address_id', 'order_type', 'subtotal',
        'total_gst', 'shipping_charge', 'coin_redeemed',
        'total_payable', 'amount_paid', 'status',
        'payment_gateway', 'gateway_transaction_id',
        'confirmed_at', 'tax_breakdown'
    ];

    protected $casts = [
        'tax_breakdown' => 'array',
        'confirmed_at' => 'datetime',
    ];

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
}