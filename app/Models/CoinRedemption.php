<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoinRedemption extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'order_id',
        'coins_used',
        'amount_redeemed',
        'status',
        'api_authorization_id',
        'authorized_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'coins_used' => 'integer',
        'amount_redeemed' => 'decimal:2',
        'authorized_at' => 'datetime',
    ];

    /**
     * Get the user who redeemed the coins.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the order this redemption is associated with.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Check if redemption is still pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if redemption is authorized.
     */
    public function isAuthorized(): bool
    {
        return $this->status === 'authorized';
    }

    /**
     * Check if redemption is reversed.
     */
    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    /**
     * Scope a query to only include authorized redemptions.
     */
    public function scopeAuthorized($query)
    {
        return $query->where('status', 'authorized');
    }
}