<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_published' => 'boolean',
        'followers_count' => 'integer',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Accessor for full video URL
    public function getVideoFullUrlAttribute()
    {
        if ($this->video_url) {
            return $this->video_url; // video_url is already a full URL
        }
        return null;
    }

    // Accessor for full video path
    public function getVideoFullPathAttribute()
    {
        if ($this->video_path) {
            return asset('storage/' . $this->video_path); // video_path is a storage path
        }
        return null;
    }

    // Scope for published reels
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    // Scope for reels by product
    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    // Scope for ordered reels
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'desc');
    }
}