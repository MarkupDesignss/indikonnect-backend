<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HeritageSite extends Model
{
    protected $guarded = [];
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the formatted location with state.
     */
    public function getFullLocationAttribute(): string
    {
        return $this->location . ', ' . $this->state;
    }

    /**
     * Get the complete URL for the image.
     */
    public function getImageUrlAttribute()
    {
        if (!$this->image) {
            return null;
        }

        return asset('storage/' . $this->image);
    }

    /**
     * Scope a query to only include heritage sites from a specific state.
     */
    public function scopeByState($query, $state)
    {
        return $query->where('state', $state);
    }

    /**
     * Scope a query to only include heritage sites with a specific category.
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }
}
