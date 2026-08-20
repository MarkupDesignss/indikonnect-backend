<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenealogyPlacement extends Model
{
    protected $fillable = [
        'user_id',
        'sponsor_id',
        'level',
        'position',
        'status',
    ];

    protected $casts = [
        'level' => 'integer',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    // Helper to get children (direct downline)
    public function children()
    {
        return $this->hasMany(GenealogyPlacement::class, 'sponsor_id', 'user_id');
    }

    public function leftChild()
    {
        return $this->hasOne(GenealogyPlacement::class, 'sponsor_id', 'user_id')
            ->where('position', 'left');
    }

    public function rightChild()
    {
        return $this->hasOne(GenealogyPlacement::class, 'sponsor_id', 'user_id')
            ->where('position', 'right');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeLeftLeg($query)
    {
        return $query->where('position', 'left');
    }

    public function scopeRightLeg($query)
    {
        return $query->where('position', 'right');
    }
}