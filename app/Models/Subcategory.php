<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Subcategory extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'image',
        'status'
    ];

    protected $casts = [
        'status' => 'boolean'
    ];

    // Relationship with Category
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    public function products()
    {
        return $this->hasMany(Product::class, 'subcategory_id');
    }

    // Helper method to get status text
    public function getStatusTextAttribute()
    {
        return $this->status ? 'Active' : 'Inactive';
    }

    // Scope for active subcategories
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    // Scope for inactive subcategories
    public function scopeInactive($query)
    {
        return $query->where('status', false);
    }

    // Auto-generate slug from name if not provided
    public static function boot()
    {
        parent::boot();

        static::creating(function ($subcategory) {
            if (empty($subcategory->slug)) {
                $subcategory->slug = Str::slug($subcategory->name);
            }
        });

        static::updating(function ($subcategory) {
            if (empty($subcategory->slug)) {
                $subcategory->slug = Str::slug($subcategory->name);
            }
        });
    }
}
