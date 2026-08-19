<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReviewImage extends Model
{
    protected $fillable = [
        'product_review_id',
        'image_path',
        'sort_order'
    ];

    protected $appends = ['image_url'];

    public function productReview(): BelongsTo
    {
        return $this->belongsTo(ProductReview::class);
    }

    public function getImageUrlAttribute(): string
    {
        return asset('storage/' . $this->image_path);
    }
}
