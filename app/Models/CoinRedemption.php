<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinRedemption extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'coins_used',
        'amount_redeemed',
        'status',
        'api_authorization_id',
        'authorized_at',
    ];

    protected $casts = [
        'coins_used' => 'integer',
        'amount_redeemed' => 'decimal:2',
        'authorized_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}