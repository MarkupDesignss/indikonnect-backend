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
        'parent_combo_id' => 'array',
        'product_type' => 'string',
    ];

    public function isCombo(): bool
    {
        return $this->product_type === 'combo';
    }

    public static function normalizeComboGroups($raw): array
    {
        if (is_array($raw)) {
            $data = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $data = is_array($decoded) ? $decoded : [];
        } else {
            return [];
        }

        if (empty($data)) {
            return [];
        }

        if (!is_array(reset($data))) {
            return [array_values(array_map('intval', $data))];
        }

        return array_values(array_map(
            fn($g) => array_values(array_map('intval', (array) $g)),
            array_filter($data, 'is_array')
        ));
    }

    /**
     * Children contained in this product's combo groups (flattened).
     */
    public function comboChildren()
    {
        $groups   = self::normalizeComboGroups($this->parent_combo_id);
        $childIds = collect($groups)->flatten()->map(fn($v) => (int) $v)->unique()->values()->all();

        if (empty($childIds)) {
            return Product::whereRaw('1 = 0');
        }

        return Product::whereIn('id', $childIds)
            ->where('id', '!=', $this->id)
            ->whereNull('deleted_at');
    }

    /**
     * Parents whose parent_combo_id contains this product's id.
     */
    public function comboParents()
    {
        $id = (int) $this->id;

        return Product::query()
            ->where('id', '!=', $id)
            ->whereNotNull('parent_combo_id')
            ->where(function ($q) use ($id) {
                $q->whereRaw("JSON_CONTAINS(parent_combo_id, ?)", [json_encode($id)])
                    ->orWhereRaw("JSON_SEARCH(parent_combo_id, 'one', ?) IS NOT NULL", [(string) $id]);
            });
    }

    protected $attributes = [
        'stock_quantity' => 0,
        'is_published' => false,
    ];

    // Relationships
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    public function subcategory()
    {
        return $this->belongsTo(Subcategory::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function getAvailableStockAttribute(): int
    {
        return max(0, (int) $this->stock_quantity - (int) $this->reserved_quantity);
    }

    public function taxCategory()
    {
        return $this->belongsTo(TaxCategory::class);
    }

    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    // public function variants()
    // {
    //     return $this->hasMany(ProductVariant::class);
    // }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class, 'parent_product_id');
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
    public function scopeUnpublished($query)
    {
        return $query->where('is_published', false);
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

    /**
     * Normalize parent_combo_id to ensure it is always an array of arrays.
     * This prevents "foreach() argument must be of type array|object, int given" errors.
     */
    public function getParentComboIdAttribute($value)
    {
        // Decode if it's a JSON string
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        // If it's not an array, wrap it or return empty array
        if (!is_array($decoded)) {
            return $decoded ? [[$decoded]] : [];
        }

        // Ensure it's an array of arrays. If it's a flat array (e.g. [1,2]), wrap it.
        if (!empty($decoded) && !is_array(reset($decoded))) {
            return [$decoded];
        }

        // Filter out non-array elements
        return array_values(array_filter($decoded, 'is_array'));
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
    public function warehouseStocks()
    {
        return $this->hasMany(WarehouseStock::class, 'product_id');
    }
}
