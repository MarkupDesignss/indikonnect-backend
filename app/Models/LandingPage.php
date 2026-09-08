<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LandingPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'section',
        'section_title',
        'section_subtitle',
        'description',
        'images',
        'color',
        'content_data',
        'order',
        'is_active'
    ];

    protected $casts = [
        'images' => 'array',
        'content_data' => 'array',
        'is_active' => 'boolean'
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order', 'asc');
    }
}
