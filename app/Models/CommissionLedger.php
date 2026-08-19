<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionLedger extends Model
{
    protected $table = 'commission_ledger';

    protected $fillable = [
        'distributor_id', 'period', 'entry_type',
        'gross_amount', 'tds_amount', 'net_amount',
        'order_reference', 'status'
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'tds_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    // Scope for released entries
    public function scopeReleased($query)
    {
        return $query->where('status', 'released');
    }

    // Scope for pending entries
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}