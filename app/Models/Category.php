<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'image',
        'status'
    ];

    protected $casts = [
        'status' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Auto-generate slug from title
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->title);
            }
        });

        static::updating(function ($category) {
            if ($category->isDirty('title') && !$category->isDirty('slug')) {
                $category->slug = Str::slug($category->title);
            }
        });
    }

    // Accessor for full image URL
    public function getImageUrlAttribute()
    {
        if ($this->image) {
            return asset('storage/categories/' . $this->image);
        }
        return null;
    }

    // Scope for active categories
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Scope for inactive categories
    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
