<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $fillable = [
        'video_path',
        'video_title',
        'person_name',
        'heading',
        'rating',
        'text',
        'is_active',
        'display_order'
    ];

    protected $casts = [
        'rating' => 'decimal:1',
        'is_active' => 'boolean',
        'display_order' => 'integer'
    ];

    // Scope for active testimonials
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Scope for ordered testimonials
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('created_at', 'desc');
    }
}
