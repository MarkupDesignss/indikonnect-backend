<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'completed_at' => 'datetime',
        'deduction_breakdown' => 'array',
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
