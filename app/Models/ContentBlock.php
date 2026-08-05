<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentBlock extends Model
{
    protected $fillable = ['content_id', 'heading', 'short_description', 'description', 'sort_order'];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function media()
    {
        return $this->hasMany(ContentMedia::class)->orderBy('sort_order');
    }

    public function images()
    {
        return $this->media()->where('type', 'image');
    }

    public function videos()
    {
        return $this->media()->where('type', 'video');
    }

    public function primaryImage()
    {
        return $this->media()->where('is_primary', true)->where('type', 'image')->first();
    }
}
