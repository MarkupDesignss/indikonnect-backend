<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HdfcPaymentAttempt extends Model
{
    protected $fillable = [
        'order_id', 'user_id', 'gateway', 'gateway_order_id', 'session_id',
        'payment_link', 'status', 'gateway_status', 'transaction_id', 'amount',
        'currency', 'expires_at', 'cancel_requested_at', 'completed_at',
    ];

    protected $casts = [
        'payment_link' => 'encrypted',
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(Order::class); }
}
