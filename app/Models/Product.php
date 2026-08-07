<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_code',
        'name',
        'slug',
        'description',
        'specification',
        'category_id',
        'tax_category_id',
        'retail_price',
        'distributor_price',
        'stock_quantity',
        'low_stock_threshold',
        'is_published',
    ];

    protected $casts = [
        'retail_price' => 'decimal:2',
        'distributor_price' => 'decimal:2',
        'is_published' => 'boolean',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
    ];

    protected $attributes = [
        'stock_quantity' => 0,
        'is_published' => false,
    ];

    // Relationships
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function taxCategory()
    {
        return $this->belongsTo(TaxCategory::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class, 'product_id')
            ->orderBy('sort_order');
    }
    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    // Accessors
    public function getImageUrlsAttribute()
    {
        if ($this->images && is_array($this->images)) {
            return array_map(function ($image) {
                return asset('storage/' . $image);
            }, $this->images);
        }
        return [];
    }

    // Scopes
    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    }

    public function getStockStatusAttribute()
    {
        if ($this->stock_quantity <= 0) {
            return 'out_of_stock';
        }

        if ($this->stock_quantity <= $this->low_stock_threshold) {
            return 'low_stock';
        }

        return 'in_stock';
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock_quantity', '=', 0);
    }

    // In Product.php, add this relationship
    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    // Add accessor for is_wishlisted
    protected $appends = ['is_wishlisted'];

    public function getIsWishlistedAttribute()
    {
        // This will be set dynamically based on the logged-in user
        // We'll set it manually in the controller
        return false;
    }
}