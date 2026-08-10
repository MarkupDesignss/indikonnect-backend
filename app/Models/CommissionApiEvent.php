<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionApiEvent extends Model
{
    protected $fillable = [
        'event_type',
        'order_id',
        'payload',
        'status',
        'retry_count',
        'max_retries',
        'last_attempt',
        'error_message',
        'response_data',
    ];

    protected $casts = [
        'payload' => 'array',
        'response_data' => 'array',
        'last_attempt' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}