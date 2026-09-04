<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $fillable = [
        'order_id',
        'order_line_id',
        'return_id',
        'amount',
        'gateway_reference',
        'status',
        'completed_at',
        'failure_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function return()
    {
        return $this->belongsTo(OrderReturn::class);
    }
    
    public function creditNote(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CreditNote::class);
    }
}