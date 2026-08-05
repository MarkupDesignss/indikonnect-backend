<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Content extends Model
{
    protected $fillable = ['title', 'slug', 'status'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($content) {
            if (empty($content->slug)) {
                $content->slug = Str::slug($content->title);
            }

            // Make slug unique
            $originalSlug = $content->slug;
            $count = 1;
            while (static::where('slug', $content->slug)->exists()) {
                $content->slug = $originalSlug . '-' . $count++;
            }
        });
    }

    public function blocks()
    {
        return $this->hasMany(ContentBlock::class)->orderBy('sort_order');
    }
}
