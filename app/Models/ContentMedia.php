<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class ContentMedia extends Model
{
    protected $fillable = ['content_block_id', 'type', 'path', 'alt_text', 'is_primary', 'sort_order'];

    public function contentBlock()
    {
        return $this->belongsTo(ContentBlock::class);
    }
    protected function path(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $value
                ? asset('storage/' . ltrim($value, '/'))
                : null,
        );
    }
}
