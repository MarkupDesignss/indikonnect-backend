<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Beneficiary extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'full_name',
        'relationship',
        'contact_number',
        'email',
        'share_percentage',
        'is_primary',
        'address',
        'confirmed_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'share_percentage' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeConfirmed($query)
    {
        return $query->whereNotNull('confirmed_at');
    }

    // Helper Methods
    public function isConfirmed(): bool
    {
        return !is_null($this->confirmed_at);
    }

    /**
     * Check if total shares for a user sum to 100%
     */
    public static function validateTotalShare(int $userId, ?int $excludeId = null): bool
    {
        $query = self::where('user_id', $userId);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        $total = $query->sum('share_percentage');
        return round($total, 2) <= 100.00;
    }
}