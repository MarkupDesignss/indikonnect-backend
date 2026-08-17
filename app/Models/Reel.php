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
        if ($this->video_path) {
            return asset('storage/' . $this->video_path);
        }
        return $this->video_url;
    }
}
