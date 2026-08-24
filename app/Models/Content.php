<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Content extends Model
{
    protected $guarded = [];

    // Add this flag to control slug generation
    public $disableSlugGeneration = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($content) {

            if (empty($content->slug) || $content->disableSlugGeneration === false) {
                // If slug is provided, use it without modifying
                if (!empty($content->slug)) {
                    // Don't modify the slug - use it as-is
                    return;
                }

                // Only generate slug if it's completely empty
                if (empty($content->slug)) {
                    $content->slug = Str::slug($content->title);
                }
            }
        });
    }

    public function blocks()
    {
        return $this->hasMany(ContentBlock::class)->orderBy('sort_order');
    }
}
