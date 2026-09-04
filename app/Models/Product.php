<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'retail_price' => 'decimal:2',
        'distributor_price' => 'decimal:2',
        'is_published' => 'boolean',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'is_deal_of_the_day' => 'boolean',
        'deal_of_the_day_starts_at' => 'datetime',
        'deal_of_the_day_ends_at' => 'datetime',
        'is_trending' => 'boolean',
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

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function taxCategory()
    {
        return $this->belongsTo(TaxCategory::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function updateStockFromVariants()
    {
        $totalStock = $this->variants()->sum('stock_quantity');
        $this->stock_quantity = $totalStock;
        $this->save();

        return $totalStock;
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

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function orderLines()
    {
        return $this->hasMany(OrderLine::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
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

    public function scopeOutOfStock($query)
    {
        return $query->where('stock_quantity', '=', 0);
    }

    // Accessors
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

    public function getIsWishlistedAttribute()
    {
        return false;
    }

    public function isActiveDealOfTheDay(): bool
    {
        if (!$this->is_deal_of_the_day) {
            return false;
        }

        $now = now();

        if ($this->deal_of_the_day_starts_at && $this->deal_of_the_day_starts_at > $now) {
            return false;
        }

        if ($this->deal_of_the_day_ends_at && $this->deal_of_the_day_ends_at < $now) {
            return false;
        }

        return true;
    }

    // Boot method
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = $product->generateUniqueSlug();
            }
        });

        static::updating(function ($product) {
            if ($product->isDirty('name') && empty($product->slug)) {
                $product->slug = $product->generateUniqueSlug();
            }
        });
    }

    public function generateUniqueSlug()
    {
        $baseSlug = $this->createSlug($this->name);
        $slug = $baseSlug;
        $counter = 1;

        while (self::where('slug', $slug)->where('id', '!=', $this->id)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function createSlug($text)
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9-]/', '-', $text);
        $text = preg_replace('/-+/', '-', $text);
        return trim($text, '-');
    }

    public function getProductUrlAttribute()
    {
        return url("/product/{$this->slug}");
    }
}
