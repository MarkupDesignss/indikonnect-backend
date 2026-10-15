<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{

    protected $guarded = [];

    protected $casts = [
        'discount_percentage' => 'integer',
    ];

    // Accessor for full logo URL
    public function getLogoUrlAttribute()
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }

    // Accessor for full banner URL
    public function getBannerUrlAttribute()
    {
        return $this->banner ? asset('storage/' . $this->banner) : null;
    }
    public function products()
    {
        return $this->hasMany(Product::class, 'brand_id');
    }
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
