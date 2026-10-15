<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id', 'id');
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
