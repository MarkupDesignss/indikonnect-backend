<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantImage;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Traits\AuditLogTrait;

class ProductController extends Controller
{
    use AuditLogTrait;
    /**
     * Get user's wishlist product IDs
     */
    protected function getUserWishlistIds($userId = null)
    {
        if ($userId) {
            return Wishlist::where('user_id', $userId)
                ->pluck('product_id')
                ->toArray();
        }

        if (auth('sanctum')->check()) {
            return Wishlist::where('user_id', auth('sanctum')->id())
                ->pluck('product_id')
                ->toArray();
        }

        return [];
    }

    /**
     * Format a single product with variants
     */
    protected function formatProduct($product, $wishlistIds = [])
    {
        $isWishlisted = in_array($product->id, $wishlistIds);
        $isActiveDeal = $product->isActiveDealOfTheDay();

        $primaryImage = $product->images->where('is_primary', true)->first()
            ?? $product->images->first();

        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'specification' => $product->specification,
            'hsn_code' => $product->hsn_code,
            'uom' => $product->uom,
            'category_id' => $product->category_id,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->title,
                'slug' => $product->category->slug,
                'description' => $product->category->description,
            ] : null,
            'tax_category_id' => $product->tax_category_id,
            'tax_category' => $product->taxCategory ? [
                'id' => $product->taxCategory->id,
                'name' => $product->taxCategory->name,
                'rate' => $product->taxCategory->rate,
            ] : null,

            // Retail pricing
            'retail_mrp' => $product->retail_mrp,
            'retail_price' => $product->retail_price,
            'retail_discount_type' => $product->retail_discount_type,
            'retail_discount_value' => $product->retail_discount_value,
            'retail_discount_amount' => $product->retail_mrp - $product->retail_price,
            'retail_discount_percentage' => $product->retail_mrp > 0
                ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
                : 0,

            // Distributor pricing
            'distributor_mrp' => $product->distributor_mrp,
            'distributor_price' => $product->distributor_price,
            'distributor_discount_type' => $product->distributor_discount_type,
            'distributor_discount_value' => $product->distributor_discount_value,
            'distributor_discount_amount' => $product->distributor_mrp && $product->distributor_price
                ? $product->distributor_mrp - $product->distributor_price
                : null,
            'distributor_discount_percentage' => $product->distributor_mrp && $product->distributor_price && $product->distributor_mrp > 0
                ? round((($product->distributor_mrp - $product->distributor_price) / $product->distributor_mrp) * 100, 2)
                : null,

            'stock_quantity' => (int) $product->stock_quantity,
            'low_stock_threshold' => (int) $product->low_stock_threshold,
            'is_published' => (bool) $product->is_published,
            'is_trending' => (bool) $product->is_trending,
            'trending_sort_order' => (int) $product->trending_sort_order,
            'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
            'is_active_deal' => $isActiveDeal,
            'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
            'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),
            'sale_type' => $product->sale_type,
            'status' => $this->getProductStatus($product),
            'is_wishlisted' => $isWishlisted,

            // Product Images
            'images' => $product->images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'image' => $image->image,
                    'image_url' => asset('storage/' . $image->image),
                    'sort_order' => $image->sort_order,
                    'is_primary' => (bool) $image->is_primary,
                ];
            })->values()->toArray(),
            'primary_image' => $primaryImage ? $primaryImage->image : null,
            'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

            // Product Variants
            'variants' => $this->formatVariants($product->variants, $product->id, $wishlistIds),

            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }
    protected function getVariantStatus($variant)
    {
        if (!$variant->is_active) return 'inactive';
        if ($variant->stock_quantity <= 0) return 'out_of_stock';
        if ($variant->stock_quantity <= $variant->low_stock_threshold) return 'low_stock';
        return 'active';
    }

    protected function getProductImageUrl($product)
    {
        // Check product images first
        $primaryImage = $product->images->where('is_primary', true)->first()
            ?? $product->images->first();

        if ($primaryImage) {
            return asset('storage/' . $primaryImage->image);
        }

        // Fallback to variant images
        $firstVariant = $product->variants->first();
        if ($firstVariant) {
            $variantImage = $firstVariant->images->where('is_primary', true)->first()
                ?? $firstVariant->images->first();
            if ($variantImage) {
                return asset('storage/' . $variantImage->image);
            }
        }

        return null;
    }
    /**
     * Format product variants
     */
    protected function formatVariants($variants, $productId, $wishlistIds = [])
    {
        return $variants->map(function ($variant) use ($productId, $wishlistIds) {
            $primaryImage = $variant->images->where('is_primary', true)->first()
                ?? $variant->images->first();

            return [
                'id' => $variant->id,
                'product_id' => $variant->product_id,
                'sku' => $variant->sku,
                'attributes' => $variant->attributes,
                // 'attribute_string' => $variant->attribute_string,

                'retail_mrp' => $variant->retail_mrp,
                'retail_price' => $variant->retail_price,
                'retail_discount_type' => $variant->retail_discount_type,
                'retail_discount_value' => $variant->retail_discount_value,
                'retail_discount_amount' => $variant->retail_mrp - $variant->retail_price,
                'retail_discount_percentage' => $variant->retail_mrp > 0
                    ? round((($variant->retail_mrp - $variant->retail_price) / $variant->retail_mrp) * 100, 2)
                    : 0,

                'distributor_mrp' => $variant->distributor_mrp,
                'distributor_price' => $variant->distributor_price,
                'distributor_discount_type' => $variant->distributor_discount_type,
                'distributor_discount_value' => $variant->distributor_discount_value,
                'distributor_discount_amount' => $variant->distributor_mrp && $variant->distributor_price
                    ? $variant->distributor_mrp - $variant->distributor_price
                    : null,
                'distributor_discount_percentage' => $variant->distributor_mrp && $variant->distributor_price && $variant->distributor_mrp > 0
                    ? round((($variant->distributor_mrp - $variant->distributor_price) / $variant->distributor_mrp) * 100, 2)
                    : null,

                'stock_quantity' => (int) $variant->stock_quantity,
                'low_stock_threshold' => (int) $variant->low_stock_threshold,
                'stock_status' => $variant->stock_status,
                'sort_order' => (int) $variant->sort_order,
                'is_active' => (bool) $variant->is_active,

                'images' => $variant->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image' => $image->image,
                        'image_url' => asset('storage/' . $image->image),
                        'sort_order' => $image->sort_order,
                        'is_primary' => (bool) $image->is_primary,
                    ];
                })->values()->toArray(),
                'primary_image' => $primaryImage ? $primaryImage->image : null,
                'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

                'created_at' => $variant->created_at?->toISOString(),
                'updated_at' => $variant->updated_at?->toISOString(),
            ];
        })->values()->toArray();
    }

    /**
     * Get all products with filtering and pagination
     */
    // public function index(Request $request)
    // {
    //     $query = Product::with(['category', 'taxCategory', 'images', 'variants.images']);

    //     // Filter by multiple categories
    //     if ($request->has('category_ids') && $request->category_ids) {
    //         $categoryIds = is_array($request->category_ids)
    //             ? $request->category_ids
    //             : explode(',', $request->category_ids);

    //         $query->whereIn('category_id', $categoryIds);
    //     }

    //     // Alternative: Filter by single category (backward compatibility)
    //     if ($request->has('category_id') && $request->category_id && !$request->has('category_ids')) {
    //         $query->where('category_id', $request->category_id);
    //     }

    //     // Filter by price range (retail_price)
    //     if ($request->has('min_price') && is_numeric($request->min_price)) {
    //         $query->where('retail_price', '>=', $request->min_price);
    //     }

    //     if ($request->has('max_price') && is_numeric($request->max_price)) {
    //         $query->where('retail_price', '<=', $request->max_price);
    //     }

    //     // Filter by published status
    //     if ($request->has('is_published')) {
    //         $query->where('is_published', $request->boolean('is_published'));
    //     }

    //     // Filter by stock status (considering both product and variants)
    //     if ($request->has('stock_status')) {
    //         $stockStatus = $request->stock_status;

    //         if (is_array($stockStatus)) {
    //             $query->where(function ($q) use ($stockStatus) {
    //                 $q->where(function ($sub) use ($stockStatus) {
    //                     // In Stock: stock_quantity > low_stock_threshold
    //                     if (in_array('in_stock', $stockStatus)) {
    //                         $sub->orWhereColumn('stock_quantity', '>', 'low_stock_threshold');
    //                     }
    //                     // Low Stock: stock_quantity between 1 and low_stock_threshold
    //                     if (in_array('low_stock', $stockStatus)) {
    //                         $sub->orWhere(function ($q) {
    //                             $q->where('stock_quantity', '>', 0)
    //                                 ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    //                         });
    //                     }
    //                     // Out of Stock: stock_quantity = 0
    //                     if (in_array('out_of_stock', $stockStatus)) {
    //                         $sub->orWhere('stock_quantity', '=', 0);
    //                     }
    //                 });
    //             });
    //         } else {
    //             switch ($stockStatus) {
    //                 case 'in_stock':
    //                     $query->whereColumn('stock_quantity', '>', 'low_stock_threshold');
    //                     break;
    //                 case 'low_stock':
    //                     $query->where('stock_quantity', '>', 0)
    //                         ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    //                     break;
    //                 case 'out_of_stock':
    //                     $query->where('stock_quantity', '=', 0);
    //                     break;
    //             }
    //         }
    //     }

    //     // Legacy: Backward compatibility for old filters
    //     if ($request->has('in_stock') && $request->boolean('in_stock')) {
    //         $query->whereColumn('stock_quantity', '>', 'low_stock_threshold');
    //     }

    //     if ($request->has('low_stock') && $request->boolean('low_stock')) {
    //         $query->where('stock_quantity', '>', 0)
    //             ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    //     }

    //     // NEW ARRIVALS FILTER - Last 30 days products
    //     if ($request->has('new-arrivals')) {
    //         $query->where('created_at', '>=', now()->subDays(30));
    //     }

    //     // Alternative: New arrivals with custom days parameter
    //     if ($request->has('new_arrival_days') && is_numeric($request->new_arrival_days)) {
    //         $days = (int) $request->new_arrival_days;
    //         $query->where('created_at', '>=', now()->subDays($days));
    //     }

    //     // Search by name or product code
    //     if ($request->has('search') && $request->search) {
    //         $search = $request->search;
    //         $query->where(function ($q) use ($search) {
    //             $q->where('name', 'LIKE', "%{$search}%")
    //                 ->orWhere('product_code', 'LIKE', "%{$search}%")
    //                 ->orWhere('slug', 'LIKE', "%{$search}%")
    //                 ->orWhereHas('variants', function ($variantQuery) use ($search) {
    //                     $variantQuery->where('sku', 'LIKE', "%{$search}%");
    //                 });
    //         });
    //     }

    //     // Sort
    //     $sortField = $request->get('sort_by', 'created_at');
    //     $sortDirection = $request->get('sort_direction', 'desc');

    //     $allowedSortFields = ['id', 'name', 'product_code', 'retail_price', 'stock_quantity', 'created_at', 'updated_at'];
    //     if (!in_array($sortField, $allowedSortFields)) {
    //         $sortField = 'created_at';
    //     }

    //     $query->orderBy($sortField, $sortDirection);

    //     // Pagination
    //     $perPage = $request->get('per_page', 15);
    //     $products = $query->paginate($perPage);

    //     // Get price range for filters
    //     $priceRange = $this->getPriceRange();

    //     // Get wishlist IDs for authenticated user
    //     $userId = $request->query('user_id');
    //     $wishlistIds = $this->getUserWishlistIds();

    //     return response()->json([
    //         'data' => $this->formatProductCollection($products, $wishlistIds),
    //         'pagination' => [
    //             'total' => $products->total(),
    //             'per_page' => $products->perPage(),
    //             'current_page' => $products->currentPage(),
    //             'last_page' => $products->lastPage(),
    //             'from' => $products->firstItem(),
    //             'to' => $products->lastItem(),
    //         ],
    //         'filters' => [
    //             'price_range' => $priceRange,
    //         ],
    //     ]);
    // }
    public function index(Request $request)
    {
        $query = Product::with(['category', 'taxCategory', 'images', 'variants.images', 'brand'])
            ->whereHas('brand', function ($q) {
                $q->where('status', true);
            });

        // Filter by multiple categories
        if ($request->has('category_ids') && $request->category_ids) {
            $categoryIds = is_array($request->category_ids)
                ? $request->category_ids
                : explode(',', $request->category_ids);

            $query->whereIn('category_id', $categoryIds);
        }

        // Alternative: Filter by single category (backward compatibility)
        if ($request->has('category_id') && $request->category_id && !$request->has('category_ids')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by multiple brands
        if ($request->has('brand_ids') && $request->brand_ids) {
            $brandIds = is_array($request->brand_ids)
                ? $request->brand_ids
                : explode(',', $request->brand_ids);

            $query->whereIn('brand_id', $brandIds);
        }
        // Filter by price range (retail_price)
        if ($request->has('min_price') && is_numeric($request->min_price)) {
            $query->where('retail_price', '>=', $request->min_price);
        }

        if ($request->has('max_price') && is_numeric($request->max_price)) {
            $query->where('retail_price', '<=', $request->max_price);
        }

        // Filter by published status
        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        // Filter by stock status (considering both product and variants)
        if ($request->has('stock_status')) {
            $stockStatus = $request->stock_status;

            if (is_array($stockStatus)) {
                $query->where(function ($q) use ($stockStatus) {
                    $q->where(function ($sub) use ($stockStatus) {
                        // In Stock: stock_quantity > low_stock_threshold
                        if (in_array('in_stock', $stockStatus)) {
                            $sub->orWhereColumn('stock_quantity', '>', 'low_stock_threshold');
                        }
                        // Low Stock: stock_quantity between 1 and low_stock_threshold
                        if (in_array('low_stock', $stockStatus)) {
                            $sub->orWhere(function ($q) {
                                $q->where('stock_quantity', '>', 0)
                                    ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
                            });
                        }
                        // Out of Stock: stock_quantity = 0
                        if (in_array('out_of_stock', $stockStatus)) {
                            $sub->orWhere('stock_quantity', '=', 0);
                        }
                    });
                });
            } else {
                switch ($stockStatus) {
                    case 'in_stock':
                        $query->whereColumn('stock_quantity', '>', 'low_stock_threshold');
                        break;
                    case 'low_stock':
                        $query->where('stock_quantity', '>', 0)
                            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
                        break;
                    case 'out_of_stock':
                        $query->where('stock_quantity', '=', 0);
                        break;
                }
            }
        }

        // Legacy: Backward compatibility for old filters
        if ($request->has('in_stock') && $request->boolean('in_stock')) {
            $query->whereColumn('stock_quantity', '>', 'low_stock_threshold');
        }

        if ($request->has('low_stock') && $request->boolean('low_stock')) {
            $query->where('stock_quantity', '>', 0)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
        }

        // NEW ARRIVALS FILTER - Last 30 days products
        if ($request->has('new-arrivals')) {
            $query->where('created_at', '>=', now()->subDays(30));
        }

        // Alternative: New arrivals with custom days parameter
        if ($request->has('new_arrival_days') && is_numeric($request->new_arrival_days)) {
            $days = (int) $request->new_arrival_days;
            $query->where('created_at', '>=', now()->subDays($days));
        }

        // Search by name or product code
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('product_code', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%")
                    ->orWhereHas('variants', function ($variantQuery) use ($search) {
                        $variantQuery->where('sku', 'LIKE', "%{$search}%");
                    });
            });
        }

        // Sort
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        $allowedSortFields = ['id', 'name', 'product_code', 'retail_price', 'stock_quantity', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields)) {
            $sortField = 'created_at';
        }

        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $products = $query->paginate($perPage);

        // Get price range for filters
        $priceRange = $this->getPriceRange();

        // Get wishlist IDs for authenticated user
        $userId = $request->query('user_id');
        $wishlistIds = $this->getUserWishlistIds();

        return response()->json([
            'data' => $this->formatProductCollection($products, $wishlistIds),
            'pagination' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
            'filters' => [
                'price_range' => $priceRange,
            ],
        ]);
    }

    /**
     * Format product collection
     */
    // protected function formatProductCollection($products, $wishlistIds = [])
    // {
    //     return $products->map(function ($product) use ($wishlistIds) {
    //         $isWishlisted = in_array($product->id, $wishlistIds);
    //         $isActiveDeal = $product->isActiveDealOfTheDay();

    //         // Get product reviews
    //         $averageRating = ProductReview::where('product_id', $product->id)
    //             ->where('status', 'approved')
    //             ->avg('rating');

    //         $totalReviews = ProductReview::where('product_id', $product->id)
    //             ->where('status', 'approved')
    //             ->count();

    //         $primaryImage = $product->images->where('is_primary', true)->first()
    //             ?? $product->images->first();

    //         return [
    //             'id' => $product->id,
    //             'product_code' => $product->product_code,
    //             'name' => $product->name,
    //             'slug' => $product->slug,
    //             'description' => $product->description,
    //             'specification' => $product->specification,
    //             'category_id' => $product->category_id,
    //             'category' => $product->category ? [
    //                 'id' => $product->category->id,
    //                 'name' => $product->category->title,
    //                 'slug' => $product->category->slug,
    //             ] : null,
    //             'tax_category_id' => $product->tax_category_id,
    //             'tax_category' => $product->taxCategory ? [
    //                 'id' => $product->taxCategory->id,
    //                 'name' => $product->taxCategory->name,
    //                 'rate' => $product->taxCategory->rate,
    //             ] : null,

    //             'retail_mrp' => $product->retail_mrp,
    //             'retail_price' => $product->retail_price,
    //             'retail_discount_percentage' => $product->retail_mrp > 0
    //                 ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
    //                 : 0,

    //             'distributor_mrp' => $product->distributor_mrp,
    //             'distributor_price' => $product->distributor_price,

    //             'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
    //             'is_active_deal' => $isActiveDeal,
    //             'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
    //             'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),
    //             'sale_type' => $product->sale_type,

    //             'stock_quantity' => (int) $product->stock_quantity,
    //             'low_stock_threshold' => (int) $product->low_stock_threshold,
    //             'stock_status' => $this->getProductStatus($product),
    //             'is_published' => (bool) $product->is_published,
    //             'is_trending' => (bool) $product->is_trending,
    //             'trending_sort_order' => (int) $product->trending_sort_order,
    //             'is_wishlisted' => $isWishlisted,

    //             // Product Reviews Summary
    //             'reviews_summary' => [
    //                 'average_rating' => round($averageRating, 1),
    //                 'total_reviews' => $totalReviews,
    //             ],

    //             // Images
    //             'images' => $product->images->map(function ($image) {
    //                 return [
    //                     'id' => $image->id,
    //                     'image_url' => asset('storage/' . $image->image),
    //                     'is_primary' => (bool) $image->is_primary,
    //                     'sort_order' => $image->sort_order,
    //                 ];
    //             })->values()->toArray(),
    //             'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

    //             // Variants summary (min/max prices)
    //             'variants_summary' => $this->getVariantsSummary($product->variants),
    //         ];
    //     })->values()->toArray();
    // }

    protected function formatProductCollection($products, $wishlistIds = [])
    {
        return $products->map(function ($product) use ($wishlistIds) {
            $isWishlisted = in_array($product->id, $wishlistIds);
            $isActiveDeal = $product->isActiveDealOfTheDay();

            // Get product reviews
            $averageRating = ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->avg('rating');

            $totalReviews = ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->count();

            $primaryImage = $product->images->where('is_primary', true)->first()
                ?? $product->images->first();

            return [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'brand_id' => $product->brand_id ?? null,
                'brand_name' => $product->brand->title,
                'brand_logo' => $product->brand?->logo
                    ? asset('storage/' . $product->brand->logo)
                    : null,
                'brand_banner' => $product->brand?->banner
                    ? asset('storage/' . $product->brand->banner)
                    : null,
                'slug' => $product->slug,
                'description' => $product->description,
                'specification' => $product->specification,
                'category_id' => $product->category_id,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->title,
                    'slug' => $product->category->slug,
                ] : null,
                'tax_category_id' => $product->tax_category_id,
                'tax_category' => $product->taxCategory ? [
                    'id' => $product->taxCategory->id,
                    'name' => $product->taxCategory->name,
                    'rate' => $product->taxCategory->rate,
                ] : null,

                'retail_mrp' => $product->retail_mrp,
                'retail_price' => $product->retail_price,
                'retail_discount_percentage' => $product->retail_mrp > 0
                    ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
                    : 0,

                'distributor_mrp' => $product->distributor_mrp,
                'distributor_price' => $product->distributor_price,

                'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
                'is_active_deal' => $isActiveDeal,
                'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
                'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),
                'sale_type' => $product->sale_type,

                'stock_quantity' => (int) $product->stock_quantity,
                'low_stock_threshold' => (int) $product->low_stock_threshold,
                'stock_status' => $this->getProductStatus($product),
                'is_published' => (bool) $product->is_published,
                'is_trending' => (bool) $product->is_trending,
                'trending_sort_order' => (int) $product->trending_sort_order,
                'is_wishlisted' => $isWishlisted,

                // Product Reviews Summary
                'reviews_summary' => [
                    'average_rating' => round($averageRating, 1),
                    'total_reviews' => $totalReviews,
                ],

                // Images
                'images' => $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => asset('storage/' . $image->image),
                        'is_primary' => (bool) $image->is_primary,
                        'sort_order' => $image->sort_order,
                    ];
                })->values()->toArray(),
                'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

                // Full variants with their images
                'variants' => $product->variants->map(function ($variant) {
                    // Get primary variant image
                    $primaryVariantImage = $variant->images->where('is_primary', true)->first()
                        ?? $variant->images->first();

                    // Parse attributes if it's stored as JSON string
                    $attributes = $variant->attributes;
                    if (is_string($attributes)) {
                        $attributes = json_decode($attributes, true);
                    }

                    return [
                        'id' => $variant->id,
                        'product_id' => $variant->product_id,
                        'sku' => $variant->sku,
                        'attributes' => $attributes,
                        'retail_price' => $variant->retail_price,
                        'retail_mrp' => $variant->retail_mrp,
                        'retail_discount_type' => $variant->retail_discount_type,
                        'retail_discount_value' => $variant->retail_discount_value,
                        'distributor_price' => $variant->distributor_price,
                        'distributor_mrp' => $variant->distributor_mrp,
                        'distributor_discount_type' => $variant->distributor_discount_type,
                        'distributor_discount_value' => $variant->distributor_discount_value,
                        'stock_quantity' => (int) $variant->stock_quantity,
                        'low_stock_threshold' => (int) $variant->low_stock_threshold,
                        'sort_order' => (int) $variant->sort_order,
                        'is_active' => (bool) $variant->is_active,
                        'images' => $variant->images->map(function ($image) {
                            return [
                                'id' => $image->id,
                                'variant_id' => $image->variant_id,
                                'image_url' => asset('storage/' . $image->image),
                                'sort_order' => $image->sort_order,
                                'is_primary' => (bool) $image->is_primary,
                            ];
                        })->values()->toArray(),
                        'primary_image_url' => $primaryVariantImage ? asset('storage/' . $primaryVariantImage->image) : null,
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();
    }

    /**
     * Get variants summary (min/max prices)
     */
    protected function getVariantsSummary($variants)
    {
        if ($variants->isEmpty()) {
            return null;
        }

        $activeVariants = $variants->where('is_active', true);

        return [
            'count' => $activeVariants->count(),
            'min_retail_mrp' => $activeVariants->min('retail_mrp'),
            'min_retail_price' => $activeVariants->min('retail_price'),
            'max_retail_mrp' => $activeVariants->max('retail_mrp'),
            'max_retail_price' => $activeVariants->max('retail_price'),
            'min_distributor_price' => $activeVariants->min('distributor_price'),
            'min_distributor_mrp' => $activeVariants->min('distributor_mrp'),
            'max_distributor_price' => $activeVariants->max('distributor_price'),
            'max_distributor_mrp' => $activeVariants->max('distributor_mrp'),
            'attributes' => $this->getVariantAttributes($activeVariants),
        ];
    }

    /**
     * Get unique attribute keys from variants
     */
    protected function getVariantAttributes($variants)
    {
        $attributes = [];

        foreach ($variants as $variant) {
            // Check if attributes exist and is not empty
            if (!empty($variant->attributes)) {
                // Get attributes and decode if it's a string
                $variantAttributes = $variant->attributes;

                // If it's a string, decode it to array
                if (is_string($variantAttributes)) {
                    $variantAttributes = json_decode($variantAttributes, true);
                }

                // Skip if it's not an array after decoding
                if (!is_array($variantAttributes) || empty($variantAttributes)) {
                    continue;
                }

                // Now loop through the attributes
                foreach ($variantAttributes as $key => $value) {
                    if (!isset($attributes[$key])) {
                        $attributes[$key] = [];
                    }
                    if (!in_array($value, $attributes[$key])) {
                        $attributes[$key][] = $value;
                    }
                }
            }
        }

        return $attributes;
    }

    /**
     * Store a new product with variants
     */
    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'product_code' => ['required', 'string', 'max:255', Rule::unique('products')],
    //         'name' => ['required', 'string', 'max:255'],
    //         'slug' => ['nullable', 'string', 'max:255', Rule::unique('products')],
    //         'description' => ['nullable', 'string'],
    //         'specification' => ['nullable', 'string'],
    //         'hsn_code' => ['nullable', 'string', 'max:50'],
    //         'uom' => ['nullable', 'string', 'max:50'],
    //         'category_id' => ['required', 'exists:categories,id'],
    //         'tax_category_id' => ['nullable', 'exists:tax_categories,id'],
    //         'retail_mrp' => ['required', 'numeric', 'min:0'],
    //         'retail_discount_type' => ['nullable', 'in:percentage,fixed'],
    //         'retail_discount_value' => ['nullable', 'numeric', 'min:0'],
    //         'distributor_mrp' => ['nullable', 'numeric', 'min:0'],
    //         'distributor_discount_type' => ['nullable', 'in:percentage,fixed'],
    //         'distributor_discount_value' => ['nullable', 'numeric', 'min:0'],
    //         'stock_quantity' => ['required', 'integer', 'min:0'],
    //         'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
    //         'is_published' => ['nullable', 'boolean'],
    //         'is_trending' => ['nullable', 'boolean'],
    //         'trending_sort_order' => ['nullable', 'integer', 'min:0'],
    //         'sale_type' => ['nullable', 'string', 'in:today_best,limited'],
    //         'product_images' => ['nullable', 'array'],
    //         'product_images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,avif'],
    //         'product_images.*.sort_order' => ['nullable', 'integer'],
    //         'product_images.*.is_primary' => ['nullable', 'boolean'],

    //         // Variants validation
    //         'variants' => ['nullable', 'array'],
    //         'variants.*.sku' => ['required', 'string', 'max:255'],
    //         'variants.*.attributes' => ['required', 'array'],
    //         'variants.*.retail_mrp' => ['required', 'numeric', 'min:0'],
    //         'variants.*.retail_discount_type' => ['nullable', 'in:percentage,fixed'],
    //         'variants.*.retail_discount_value' => ['nullable', 'numeric', 'min:0'],
    //         'variants.*.distributor_mrp' => ['nullable', 'numeric', 'min:0'],
    //         'variants.*.distributor_discount_type' => ['nullable', 'in:percentage,fixed'],
    //         'variants.*.distributor_discount_value' => ['nullable', 'numeric', 'min:0'],
    //         'variants.*.stock_quantity' => ['required', 'integer', 'min:0'],
    //         'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
    //         'variants.*.sort_order' => ['nullable', 'integer', 'min:0'],
    //         'variants.*.is_active' => ['nullable', 'boolean'],
    //         'variants.*.images' => ['nullable', 'array'],
    //         'variants.*.images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,avif'],
    //         'variants.*.images.*.sort_order' => ['nullable', 'integer'],
    //         'variants.*.images.*.is_primary' => ['nullable', 'boolean'],
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => $validator->errors()], 422);
    //     }

    //     DB::beginTransaction();
    //     // try {
    //     $validated = $validator->validated();

    //     // Extract product data
    //     $productData = collect($validated)->except(['product_images', 'variants'])->toArray();

    //     // Calculate retail price
    //     $productData['retail_price'] = $this->calculatePrice(
    //         $productData['retail_mrp'],
    //         $productData['retail_discount_type'] ?? null,
    //         $productData['retail_discount_value'] ?? null
    //     );

    //     // Calculate distributor price
    //     if (!empty($productData['distributor_mrp'])) {
    //         $productData['distributor_price'] = $this->calculatePrice(
    //             $productData['distributor_mrp'],
    //             $productData['distributor_discount_type'] ?? null,
    //             $productData['distributor_discount_value'] ?? null
    //         );
    //     } else {
    //         $productData['distributor_price'] = null;
    //         $productData['distributor_mrp'] = null;
    //         $productData['distributor_discount_type'] = null;
    //         $productData['distributor_discount_value'] = null;
    //     }

    //     // Generate slug if not provided
    //     if (empty($productData['slug'])) {
    //         $productData['slug'] = Str::slug($productData['name']);
    //     }
    //     $productData['slug'] = $this->generateUniqueSlug($productData['slug']);
    //     $productData['is_published'] = $productData['is_published'] ?? false;
    //     $productData['low_stock_threshold'] = $productData['low_stock_threshold'] ?? 5;

    //     // Create product
    //     $product = Product::create($productData);

    //     // Handle product images
    //     $this->handleProductImages($request, $product);

    //     // Handle variants
    //     if (!empty($validated['variants'])) {
    //         foreach ($validated['variants'] as $variantData) {
    //             $variant = $this->createVariant($product, $variantData);
    //         }
    //     }

    //     DB::commit();
    //     $product->load(['category', 'taxCategory', 'images', 'variants.images']);

    //     return response()->json($this->formatProduct($product), 201);
    //     // } catch (\Exception $e) {
    //     //     DB::rollBack();
    //     //     Log::error('Product creation failed:', [
    //     //         'error' => $e->getMessage(),
    //     //         'trace' => $e->getTraceAsString()
    //     //     ]);
    //     //     return response()->json([
    //     //         'message' => 'Failed to create product',
    //     //         'error' => $e->getMessage()
    //     //     ], 500);
    //     // }
    // }
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_code' => ['required', 'string', 'max:255', Rule::unique('products')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products')],
            'description' => ['nullable', 'string'],
            'specification' => ['nullable', 'string'],
            'brand_id' => ['nullable'],
            'hsn_code' => ['nullable', 'string', 'max:50'],
            'uom' => ['nullable', 'string', 'max:50'],
            'category_id' => ['required', 'exists:categories,id'],
            'tax_category_id' => ['nullable', 'exists:tax_categories,id'],
            'retail_mrp' => ['required', 'numeric', 'min:0'],
            'retail_discount_type' => ['nullable', 'in:percentage,fixed'],
            'retail_discount_value' => ['nullable', 'numeric', 'min:0'],
            'distributor_mrp' => ['nullable', 'numeric', 'min:0'],
            'distributor_discount_type' => ['nullable', 'in:percentage,fixed'],
            'distributor_discount_value' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['required_if:variants,null', 'nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
            'is_trending' => ['nullable', 'boolean'],
            'trending_sort_order' => ['nullable', 'integer', 'min:0'],
            'sale_type' => ['nullable', 'string', 'in:today_best,limited'],
            'product_images' => ['nullable', 'array'],
            'product_images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,avif'],
            'product_images.*.sort_order' => ['nullable', 'integer'],
            'product_images.*.is_primary' => ['nullable', 'boolean'],

            // Variants validation
            'variants' => ['nullable', 'array'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.attributes' => ['required_with:variants'],
            'variants.*.retail_mrp' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.retail_discount_type' => ['nullable', 'in:percentage,fixed'],
            'variants.*.retail_discount_value' => ['nullable', 'numeric', 'min:0'],
            'variants.*.distributor_mrp' => ['nullable', 'numeric', 'min:0'],
            'variants.*.distributor_discount_type' => ['nullable', 'in:percentage,fixed'],
            'variants.*.distributor_discount_value' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock_quantity' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'variants.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.images' => ['nullable', 'array'],
            'variants.*.images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,avif'],
            'variants.*.images.*.sort_order' => ['nullable', 'integer'],
            'variants.*.images.*.is_primary' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $validator->validated();

            // Extract product data
            $productData = collect($validated)->except(['product_images', 'variants'])->toArray();

            // Calculate retail price
            $productData['retail_price'] = $this->calculatePrice(
                $productData['retail_mrp'],
                $productData['retail_discount_type'] ?? null,
                $productData['retail_discount_value'] ?? null
            );

            // Calculate distributor price
            if (!empty($productData['distributor_mrp'])) {
                $productData['distributor_price'] = $this->calculatePrice(
                    $productData['distributor_mrp'],
                    $productData['distributor_discount_type'] ?? null,
                    $productData['distributor_discount_value'] ?? null
                );
            } else {
                $productData['distributor_price'] = null;
                $productData['distributor_mrp'] = null;
                $productData['distributor_discount_type'] = null;
                $productData['distributor_discount_value'] = null;
            }

            // Generate slug if not provided
            if (empty($productData['slug'])) {
                $productData['slug'] = Str::slug($productData['name']);
            }
            $productData['slug'] = $this->generateUniqueSlug($productData['slug']);
            $productData['is_published'] = $productData['is_published'] ?? false;
            $productData['low_stock_threshold'] = $productData['low_stock_threshold'] ?? 5;

            // Check if variants exist
            $hasVariants = !empty($validated['variants']);

            // If variants exist, product stock will be calculated from variants
            if ($hasVariants) {
                // Remove stock_quantity from product data if variants exist
                // It will be calculated from variants sum
                unset($productData['stock_quantity']);
            } else {
                // If no variants, use the provided stock_quantity
                // Ensure stock_quantity is set
                if (!isset($productData['stock_quantity'])) {
                    $productData['stock_quantity'] = 0;
                }
            }

            // Create product
            $product = Product::create($productData);

            // Handle product images
            $this->handleProductImages($request, $product);

            // Handle variants with their images
            $variantDetails = [];
            if ($hasVariants) {
                $totalStock = 0;

                foreach ($validated['variants'] as $variantData) {
                    // Extract images from variant data
                    $variantImages = $variantData['images'] ?? [];
                    unset($variantData['images']);

                    // Create the variant
                    $variant = $this->createVariant($product, $variantData);

                    // Add variant stock to total
                    $totalStock += $variantData['stock_quantity'] ?? 0;

                    // Handle variant images with file uploads
                    if (!empty($variantImages)) {
                        $this->handleVariantImages($request, $variant, $variantImages);
                    }
                    $variantDetails[] = [
                        'sku' => $variantData['sku'],
                        'attributes' => $variantData['attributes'],
                        'retail_mrp' => $variantData['retail_mrp'],
                        'distributor_mrp' => $variantData['distributor_mrp'] ?? null,
                        'stock_quantity' => $variantData['stock_quantity'] ?? 0,
                        'is_active' => $variantData['is_active'] ?? true,
                    ];
                }

                // Update product stock with total from variants
                $product->update(['stock_quantity' => $totalStock]);
            }

            DB::commit();
            $product->load(['category', 'taxCategory', 'images', 'variants.images']);
            $this->logAudit(
                'product_create',
                'catalogue',
                null,
                [
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'category_id' => $product->category_id,
                    'category_name' => $product->category?->name,
                    'tax_category_id' => $product->tax_category_id,
                    'retail_mrp' => $product->retail_mrp,
                    'retail_price' => $product->retail_price,
                    'distributor_mrp' => $product->distributor_mrp,
                    'distributor_price' => $product->distributor_price,
                    'stock_quantity' => $product->stock_quantity,
                    'low_stock_threshold' => $product->low_stock_threshold,
                    'is_published' => $product->is_published,
                    'is_trending' => $product->is_trending,
                    'sale_type' => $product->sale_type,
                    'has_variants' => $hasVariants,
                    'variants_count' => count($variantDetails),
                    'variants' => $variantDetails,
                    'created_by' => $this->getAdminId(),
                    'created_at' => now()->toDateTimeString(),
                ]
            );


            return response()->json($this->formatProduct($product), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Product creation failed:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle variant images with proper file uploads
     */
    protected function handleVariantImages($request, $variant, $variantImages)
    {
        $imageCount = 0;
        $hasPrimary = false;

        foreach ($variantImages as $index => $imageData) {
            // Get the file from the request - use the correct path
            $imageFile = $request->file("variants.{$index}.images.{$imageCount}.image");

            // Or if you have a different structure, you might need to access it differently
            // For nested array structure, you might need to use a different approach

            if ($imageFile && $imageFile->isValid()) {
                $path = $imageFile->store('variants', 'public');

                $isPrimary = $imageData['is_primary'] ?? false;
                if (!$hasPrimary && $imageCount === 0) {
                    $isPrimary = true;
                }

                VariantImage::create([
                    'variant_id' => $variant->id,
                    'image' => $path,
                    'is_primary' => $isPrimary,
                    'sort_order' => $imageData['sort_order'] ?? $imageCount,
                ]);

                if ($isPrimary) {
                    $hasPrimary = true;
                }
            } elseif (isset($imageData['image_url'])) {
                // Handle URL-based images
                $isPrimary = $imageData['is_primary'] ?? false;
                if (!$hasPrimary && $imageCount === 0) {
                    $isPrimary = true;
                }

                VariantImage::create([
                    'variant_id' => $variant->id,
                    'image' => $imageData['image_url'],
                    'is_primary' => $isPrimary,
                    'sort_order' => $imageData['sort_order'] ?? $imageCount,
                ]);

                if ($isPrimary) {
                    $hasPrimary = true;
                }
            }
            $imageCount++;
        }

        // If no primary was set but we have images, set the first one as primary
        if (!$hasPrimary && $imageCount > 0) {
            $firstImage = VariantImage::where('variant_id', $variant->id)
                ->orderBy('sort_order')
                ->first();
            if ($firstImage) {
                $firstImage->update(['is_primary' => true]);
            }
        }
    }

    /**
     * Handle product images
     */
    protected function handleProductImages($request, $product)
    {
        $productImages = $request->input('product_images', []);
        $imageCount = 0;
        $hasPrimary = false;

        if (!empty($productImages)) {
            foreach ($productImages as $index => $imageData) {
                $imageFile = $request->file("product_images.{$index}.image");

                if ($imageFile && $imageFile->isValid()) {
                    $path = $imageFile->store('products', 'public');
                    $sortOrder = isset($imageData['sort_order']) ? (int) $imageData['sort_order'] : $imageCount;

                    $isPrimary = false;
                    if (isset($imageData['is_primary'])) {
                        $isPrimary = (bool) $imageData['is_primary'];
                    } elseif (!$hasPrimary && $imageCount === 0) {
                        $isPrimary = true;
                    }

                    ProductImage::create([
                        'product_id' => $product->id,
                        'image' => $path,
                        'is_primary' => $isPrimary,
                        'sort_order' => $sortOrder,
                    ]);

                    if ($isPrimary) {
                        $hasPrimary = true;
                    }

                    $imageCount++;
                }
            }

            if (!$hasPrimary && $imageCount > 0) {
                $firstImage = ProductImage::where('product_id', $product->id)
                    ->orderBy('sort_order')
                    ->first();
                if ($firstImage) {
                    $firstImage->update(['is_primary' => true]);
                }
            }
        }
    }

    public function updateTrendingStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'is_trending' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $product = Product::findOrFail($id);

            $product->update([
                'is_trending' => (bool) $request->is_trending,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Product trending status updated successfully.',
                'data' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'is_trending' => (bool) $product->is_trending,
                ],
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to update trending status.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a product variant
     */
    // protected function createVariant($product, $variantData)
    // {
    //     // Calculate variant prices
    //     $variantData['retail_price'] = $this->calculatePrice(
    //         $variantData['retail_mrp'],
    //         $variantData['retail_discount_type'] ?? null,
    //         $variantData['retail_discount_value'] ?? null
    //     );

    //     if (!empty($variantData['distributor_mrp'])) {
    //         $variantData['distributor_price'] = $this->calculatePrice(
    //             $variantData['distributor_mrp'],
    //             $variantData['distributor_discount_type'] ?? null,
    //             $variantData['distributor_discount_value'] ?? null
    //         );
    //     } else {
    //         $variantData['distributor_price'] = null;
    //         $variantData['distributor_mrp'] = null;
    //         $variantData['distributor_discount_type'] = null;
    //         $variantData['distributor_discount_value'] = null;
    //     }

    //     $variantData['low_stock_threshold'] = $variantData['low_stock_threshold'] ?? 5;
    //     $variantData['is_active'] = $variantData['is_active'] ?? true;
    //     $variantData['product_id'] = $product->id;
    //     // Create variant
    //     $variant = ProductVariant::create($variantData);

    //     // Handle variant images
    //     if (!empty($variantData['images'])) {
    //         $imageCount = 0;
    //         $hasPrimary = false;

    //         // We need to handle image files for variants differently
    //         // Since we can't easily access files by index in nested arrays,
    //         // we'll store them with a different approach
    //         // This would need to be modified based on your frontend implementation

    //         // For now, we'll just create variant images without files
    //         // You would need to handle file uploads for variant images separately
    //         if (isset($variantData['images']) && is_array($variantData['images'])) {
    //             foreach ($variantData['images'] as $imageData) {
    //                 // This would need actual file handling
    //                 // For now, just create placeholder
    //                 if (isset($imageData['image_url'])) {
    //                     VariantImage::create([
    //                         'variant_id' => $variant->id,
    //                         'image' => $imageData['image_url'],
    //                         'is_primary' => $imageData['is_primary'] ?? false,
    //                         'sort_order' => $imageData['sort_order'] ?? $imageCount,
    //                     ]);
    //                 }
    //                 $imageCount++;
    //             }
    //         }
    //     }

    //     return $variant;
    // }

    protected function createVariant($product, $variantData)
    {
        // Calculate variant prices
        $variantData['retail_price'] = $this->calculatePrice(
            $variantData['retail_mrp'],
            $variantData['retail_discount_type'] ?? null,
            $variantData['retail_discount_value'] ?? null
        );

        if (!empty($variantData['distributor_mrp'])) {
            $variantData['distributor_price'] = $this->calculatePrice(
                $variantData['distributor_mrp'],
                $variantData['distributor_discount_type'] ?? null,
                $variantData['distributor_discount_value'] ?? null
            );
        } else {
            $variantData['distributor_price'] = null;
            $variantData['distributor_mrp'] = null;
            $variantData['distributor_discount_type'] = null;
            $variantData['distributor_discount_value'] = null;
        }

        $variantData['low_stock_threshold'] = $variantData['low_stock_threshold'] ?? 5;
        $variantData['is_active'] = $variantData['is_active'] ?? true;
        $variantData['product_id'] = $product->id;
        $variantData['sort_order'] = $variantData['sort_order'] ?? 0;

        // Remove images from variant data - they'll be handled separately
        unset($variantData['images']);

        // Ensure stock_quantity is set
        if (!isset($variantData['stock_quantity'])) {
            $variantData['stock_quantity'] = 0;
        }

        // Create variant
        return ProductVariant::create($variantData);
    }
    /**
     * Update a product with variants
     */
    public function update(Request $request, $id)
    {
        $product = Product::where('id', $id)->first();
        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'product_code' => ['sometimes', 'required', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products')->ignore($product->id)],
            'description' => ['nullable', 'string'],
            'specification' => ['nullable', 'string'],
            'brand_id' => ['nullable'],
            'hsn_code' => ['nullable', 'string', 'max:50'],
            'uom' => ['nullable', 'string', 'max:50'],
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'tax_category_id' => ['nullable', 'exists:tax_categories,id'],
            'retail_mrp' => ['sometimes', 'required', 'numeric', 'min:0'],
            'retail_discount_type' => ['nullable', 'in:percentage,fixed'],
            'retail_discount_value' => ['nullable', 'numeric', 'min:0'],
            'distributor_mrp' => ['nullable', 'numeric', 'min:0'],
            'distributor_discount_type' => ['nullable', 'in:percentage,fixed'],
            'distributor_discount_value' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
            'is_trending' => ['nullable', 'boolean'],
            'trending_sort_order' => ['nullable', 'integer', 'min:0'],
            'sale_type' => ['nullable', 'string', 'in:today_best,limited'],
            'product_images' => ['nullable', 'array'],
            'product_images.*.image' => ['nullable', 'mimes:jpg,jpeg,png,webp,avif'],
            'product_images.*.sort_order' => ['nullable', 'integer'],
            'product_images.*.is_primary' => ['nullable', 'boolean'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['exists:product_images,id'],

            // Variants
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'exists:product_variants,id'],
            'variants.*.sku' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.attributes' => ['required_with:variants'],
            'variants.*.retail_mrp' => ['required_with:variants', 'numeric', 'min:0'],
            'variants.*.retail_discount_type' => ['nullable', 'in:percentage,fixed'],
            'variants.*.retail_discount_value' => ['nullable', 'numeric', 'min:0'],
            'variants.*.distributor_mrp' => ['nullable', 'numeric', 'min:0'],
            'variants.*.distributor_discount_type' => ['nullable', 'in:percentage,fixed'],
            'variants.*.distributor_discount_value' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock_quantity' => ['required_with:variants', 'integer', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'variants.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'remove_variants' => ['nullable', 'array'],
            'remove_variants.*' => ['exists:product_variants,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $validator->validated();
            $oldValues = [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'slug' => $product->slug,
                'category_id' => $product->category_id,
                'tax_category_id' => $product->tax_category_id,
                'retail_mrp' => $product->retail_mrp,
                'retail_price' => $product->retail_price,
                'retail_discount_type' => $product->retail_discount_type,
                'retail_discount_value' => $product->retail_discount_value,
                'distributor_mrp' => $product->distributor_mrp,
                'distributor_price' => $product->distributor_price,
                'distributor_discount_type' => $product->distributor_discount_type,
                'distributor_discount_value' => $product->distributor_discount_value,
                'stock_quantity' => $product->stock_quantity,
                'low_stock_threshold' => $product->low_stock_threshold,
                'is_published' => $product->is_published,
                'is_trending' => $product->is_trending,
                'sale_type' => $product->sale_type,
                'description' => $product->description,
                'specification' => $product->specification,
                'hsn_code' => $product->hsn_code,
                'uom' => $product->uom,
            ];
            // Update product data
            $productData = collect($validated)->except(['product_images', 'variants', 'remove_images', 'remove_variants'])->toArray();

            if (isset($productData['retail_mrp'])) {
                $productData['retail_price'] = $this->calculatePrice(
                    $productData['retail_mrp'],
                    $productData['retail_discount_type'] ?? null,
                    $productData['retail_discount_value'] ?? null
                );
            }

            if (isset($productData['distributor_mrp']) && !empty($productData['distributor_mrp'])) {
                $productData['distributor_price'] = $this->calculatePrice(
                    $productData['distributor_mrp'],
                    $productData['distributor_discount_type'] ?? null,
                    $productData['distributor_discount_value'] ?? null
                );
            } elseif (isset($productData['distributor_mrp']) && empty($productData['distributor_mrp'])) {
                $productData['distributor_price'] = null;
                $productData['distributor_mrp'] = null;
                $productData['distributor_discount_type'] = null;
                $productData['distributor_discount_value'] = null;
            }

            // Handle slug
            if (isset($productData['slug']) || isset($productData['name'])) {
                if (empty($productData['slug']) && isset($productData['name'])) {
                    $productData['slug'] = Str::slug($productData['name']);
                }
                if (isset($productData['slug']) && $productData['slug'] !== $product->slug) {
                    $productData['slug'] = $this->generateUniqueSlug($productData['slug'], $product->id);
                }
            }

            // Check if variants exist in request
            $hasVariants = isset($validated['variants']) && !empty($validated['variants']);

            // If variants exist, remove stock_quantity from product data
            // It will be calculated from variants sum
            if ($hasVariants) {
                // Don't unset stock_quantity if it's not in the array
                if (array_key_exists('stock_quantity', $productData)) {
                    unset($productData['stock_quantity']);
                }
            }

            // Remove the dd() debug statement
            // dd($productData);

            $product->update($productData);

            // Handle product images
            $this->handleProductImageUpdates($request, $product);
            $variantUpdateDetails = [];

            // Handle variants
            if ($hasVariants) {
                $totalStock = $this->handleVariantUpdates($product, $validated);
                $product->update(['stock_quantity' => $totalStock]);
                // Get updated variants for audit
                $updatedVariants = $product->variants()->get();
                foreach ($updatedVariants as $variant) {
                    $variantUpdateDetails[] = [
                        'id' => $variant->id,
                        'sku' => $variant->sku,
                        'attributes' => $variant->attributes,
                        'retail_mrp' => $variant->retail_mrp,
                        'distributor_mrp' => $variant->distributor_mrp,
                        'stock_quantity' => $variant->stock_quantity,
                        'is_active' => $variant->is_active,
                    ];
                }
            } else {
                // If no variants, ensure stock_quantity is preserved from the request
                if (isset($validated['stock_quantity'])) {
                    $product->update(['stock_quantity' => $validated['stock_quantity']]);
                }
            }

            DB::commit();
            $product->load(['category', 'taxCategory', 'images', 'variants.images']);
            $newValues = [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'slug' => $product->slug,
                'category_id' => $product->category_id,
                'category_name' => $product->category?->name,
                'tax_category_id' => $product->tax_category_id,
                'retail_mrp' => $product->retail_mrp,
                'retail_price' => $product->retail_price,
                'retail_discount_type' => $product->retail_discount_type,
                'retail_discount_value' => $product->retail_discount_value,
                'distributor_mrp' => $product->distributor_mrp,
                'distributor_price' => $product->distributor_price,
                'distributor_discount_type' => $product->distributor_discount_type,
                'distributor_discount_value' => $product->distributor_discount_value,
                'stock_quantity' => $product->stock_quantity,
                'low_stock_threshold' => $product->low_stock_threshold,
                'is_published' => $product->is_published,
                'is_trending' => $product->is_trending,
                'trending_sort_order' => $product->trending_sort_order,
                'sale_type' => $product->sale_type,
                'description' => $product->description,
                'specification' => $product->specification,
                'hsn_code' => $product->hsn_code,
                'uom' => $product->uom,
                'has_variants' => $hasVariants,
                'variants_count' => count($variantUpdateDetails),
                'variants' => $variantUpdateDetails,
                'updated_by' => $this->getAdminId(),
                'updated_at' => now()->toDateTimeString(),
            ];
            $this->logAudit(
                'product_update',
                'catalogue',
                $oldValues,
                $newValues
            );
            return response()->json($this->formatProduct($product));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update product:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Failed to update product',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Handle product image updates
     */
    protected function handleProductImageUpdates($request, $product)
    {
        // Remove specified images
        $removeImages = $request->input('remove_images', []);
        if (!empty($removeImages)) {
            $imagesToRemove = ProductImage::whereIn('id', $removeImages)
                ->where('product_id', $product->id)
                ->get();

            foreach ($imagesToRemove as $image) {
                if (Storage::disk('public')->exists($image->image)) {
                    Storage::disk('public')->delete($image->image);
                }
                $image->delete();
            }
        }

        // Add new images
        $productImages = $request->input('product_images', []);
        if (!empty($productImages)) {
            $existingCount = $product->images()->count();
            $hasPrimary = $product->images()->where('is_primary', true)->exists();
            $imageCount = 0;

            foreach ($productImages as $index => $imageData) {
                $imageFile = $request->file("product_images.{$index}.image");

                if ($imageFile && $imageFile->isValid()) {
                    $path = $imageFile->store('products', 'public');

                    $sortOrder = isset($imageData['sort_order'])
                        ? (int) $imageData['sort_order']
                        : $existingCount + $imageCount;

                    $isPrimary = false;
                    if (isset($imageData['is_primary'])) {
                        $isPrimary = (bool) $imageData['is_primary'];
                    } elseif (!$hasPrimary && $imageCount === 0) {
                        $isPrimary = true;
                    }

                    ProductImage::create([
                        'product_id' => $product->id,
                        'image' => $path,
                        'is_primary' => $isPrimary,
                        'sort_order' => $sortOrder,
                    ]);

                    if ($isPrimary) {
                        $hasPrimary = true;
                    }

                    $imageCount++;
                }
            }

            if (!$hasPrimary && $imageCount > 0) {
                $firstImage = ProductImage::where('product_id', $product->id)
                    ->orderBy('sort_order')
                    ->first();
                if ($firstImage) {
                    $firstImage->update(['is_primary' => true]);
                }
            }
        }
    }

    /**
     * Handle variant updates
     */
    protected function handleVariantUpdates($product, $validated)
    {
        $totalStock = 0;

        // Remove specified variants
        $removeVariants = $validated['remove_variants'] ?? [];
        if (!empty($removeVariants)) {
            $variantsToRemove = ProductVariant::whereIn('id', $removeVariants)
                ->where('product_id', $product->id)
                ->get();

            foreach ($variantsToRemove as $variant) {
                // Delete variant images
                foreach ($variant->images as $image) {
                    if (Storage::disk('public')->exists($image->image)) {
                        Storage::disk('public')->delete($image->image);
                    }
                    $image->delete();
                }
                $variant->delete();
            }
        }

        // Update or create variants
        $variants = $validated['variants'] ?? [];
        $existingVariantIds = [];

        foreach ($variants as $variantData) {
            // Get stock quantity for this variant
            $variantStock = $variantData['stock_quantity'] ?? 0;

            // Extract images from variant data
            $variantImages = $variantData['images'] ?? [];
            unset($variantData['images']);

            if (isset($variantData['id'])) {
                // Update existing variant
                $variant = ProductVariant::where('id', $variantData['id'])
                    ->where('product_id', $product->id)
                    ->first();

                if ($variant) {
                    $existingVariantIds[] = $variant->id;

                    // Calculate prices
                    $variantData['retail_price'] = $this->calculatePrice(
                        $variantData['retail_mrp'],
                        $variantData['retail_discount_type'] ?? null,
                        $variantData['retail_discount_value'] ?? null
                    );

                    if (!empty($variantData['distributor_mrp'])) {
                        $variantData['distributor_price'] = $this->calculatePrice(
                            $variantData['distributor_mrp'],
                            $variantData['distributor_discount_type'] ?? null,
                            $variantData['distributor_discount_value'] ?? null
                        );
                    } else {
                        $variantData['distributor_price'] = null;
                        $variantData['distributor_mrp'] = null;
                        $variantData['distributor_discount_type'] = null;
                        $variantData['distributor_discount_value'] = null;
                    }

                    $variantData['low_stock_threshold'] = $variantData['low_stock_threshold'] ?? 5;
                    $variantData['is_active'] = $variantData['is_active'] ?? true;
                    $variantData['sort_order'] = $variantData['sort_order'] ?? 0;

                    // Remove id from data before update
                    unset($variantData['id']);

                    $variant->update($variantData);
                }
            } else {
                // Create new variant
                // Remove any id that might be present
                unset($variantData['id']);

                $variantData['product_id'] = $product->id;
                $variantData['low_stock_threshold'] = $variantData['low_stock_threshold'] ?? 5;
                $variantData['is_active'] = $variantData['is_active'] ?? true;
                $variantData['sort_order'] = $variantData['sort_order'] ?? 0;

                // Calculate prices for new variant
                $variantData['retail_price'] = $this->calculatePrice(
                    $variantData['retail_mrp'],
                    $variantData['retail_discount_type'] ?? null,
                    $variantData['retail_discount_value'] ?? null
                );

                if (!empty($variantData['distributor_mrp'])) {
                    $variantData['distributor_price'] = $this->calculatePrice(
                        $variantData['distributor_mrp'],
                        $variantData['distributor_discount_type'] ?? null,
                        $variantData['distributor_discount_value'] ?? null
                    );
                } else {
                    $variantData['distributor_price'] = null;
                    $variantData['distributor_mrp'] = null;
                    $variantData['distributor_discount_type'] = null;
                    $variantData['distributor_discount_value'] = null;
                }

                $variant = ProductVariant::create($variantData);

                // Handle variant images if any
                if (!empty($variantImages)) {
                    $imageCount = 0;
                    $hasPrimary = false;

                    foreach ($variantImages as $imageData) {
                        // Check if we have a file upload or a URL
                        if (isset($imageData['image']) && $imageData['image'] instanceof \Illuminate\Http\UploadedFile) {
                            $path = $imageData['image']->store('variants', 'public');

                            $isPrimary = $imageData['is_primary'] ?? false;
                            if (!$hasPrimary && $imageCount === 0) {
                                $isPrimary = true;
                            }

                            VariantImage::create([
                                'variant_id' => $variant->id,
                                'image' => $path,
                                'is_primary' => $isPrimary,
                                'sort_order' => $imageData['sort_order'] ?? $imageCount,
                            ]);

                            if ($isPrimary) {
                                $hasPrimary = true;
                            }
                        } elseif (isset($imageData['image_url'])) {
                            $isPrimary = $imageData['is_primary'] ?? false;
                            if (!$hasPrimary && $imageCount === 0) {
                                $isPrimary = true;
                            }

                            VariantImage::create([
                                'variant_id' => $variant->id,
                                'image' => $imageData['image_url'],
                                'is_primary' => $isPrimary,
                                'sort_order' => $imageData['sort_order'] ?? $imageCount,
                            ]);

                            if ($isPrimary) {
                                $hasPrimary = true;
                            }
                        }
                        $imageCount++;
                    }

                    if (!$hasPrimary && $imageCount > 0) {
                        $firstImage = VariantImage::where('variant_id', $variant->id)
                            ->orderBy('sort_order')
                            ->first();
                        if ($firstImage) {
                            $firstImage->update(['is_primary' => true]);
                        }
                    }
                }
            }

            // Add variant stock to total
            $totalStock += $variantStock;
        }

        // Soft delete variants that are not in the update list (if we have existing IDs)
        if (!empty($existingVariantIds)) {
            ProductVariant::where('product_id', $product->id)
                ->whereNotIn('id', $existingVariantIds)
                ->delete();
        }

        return $totalStock;
    }

    /**
     * Show single product with variants
     */
    public function show(Product $product)
    {
        $product->load(['category', 'taxCategory', 'images', 'variants.images']);

        $userId = request()->query('user_id');
        $wishlistIds = $this->getUserWishlistIds($userId);

        return response()->json($this->formatProduct($product, $wishlistIds));
    }

    /**
     * Show product by slug with reviews and variants
     */
    // public function showBySlug($slug)
    // {
    //     $product = Product::with(['category', 'taxCategory', 'images', 'variants.images'])
    //         ->where('slug', $slug)
    //         ->firstOrFail();

    //     $userId = request()->query('user_id');
    //     $wishlistIds = $this->getUserWishlistIds($userId);

    //     // Get selected variant from query parameter
    //     $selectedVariantId = request()->query('variant_id');
    //     $selectedAttributes = request()->query('attributes'); // JSON string like {"color":"Red","size":"S"}

    //     $selectedVariant = null;
    //     $isVariantSelected = false;

    //     // If variant_id is provided
    //     if ($selectedVariantId) {
    //         $selectedVariant = $product->variants->where('id', $selectedVariantId)->first();
    //         if ($selectedVariant) {
    //             $isVariantSelected = true;
    //         }
    //     }

    //     // If attributes are provided, find matching variant
    //     if (!$selectedVariant && $selectedAttributes) {
    //         $attributes = is_string($selectedAttributes) ? json_decode($selectedAttributes, true) : $selectedAttributes;
    //         if (is_array($attributes)) {
    //             $selectedVariant = $product->variants->first(function ($variant) use ($attributes) {
    //                 $variantAttributes = $variant->attributes ?? [];
    //                 foreach ($attributes as $key => $value) {
    //                     if (!isset($variantAttributes[$key]) || $variantAttributes[$key] != $value) {
    //                         return false;
    //                     }
    //                 }
    //                 return true;
    //             });
    //             if ($selectedVariant) {
    //                 $isVariantSelected = true;
    //             }
    //         }
    //     }

    //     // Get all approved reviews for this product
    //     $reviews = ProductReview::with([
    //         'user' => function ($query) {
    //             $query->select('id', 'full_name', 'email', 'profile_picture');
    //         },
    //         'images'
    //     ])
    //         ->where('product_id', $product->id)
    //         ->where('status', 'approved')
    //         ->orderBy('created_at', 'desc')
    //         ->get();

    //     $averageRating = ProductReview::where('product_id', $product->id)
    //         ->where('status', 'approved')
    //         ->avg('rating');

    //     $totalReviews = ProductReview::where('product_id', $product->id)
    //         ->where('status', 'approved')
    //         ->count();

    //     // Rating distribution
    //     $ratingDistribution = [
    //         1 => ProductReview::where('product_id', $product->id)->where('status', 'approved')->where('rating', 1)->count(),
    //         2 => ProductReview::where('product_id', $product->id)->where('status', 'approved')->where('rating', 2)->count(),
    //         3 => ProductReview::where('product_id', $product->id)->where('status', 'approved')->where('rating', 3)->count(),
    //         4 => ProductReview::where('product_id', $product->id)->where('status', 'approved')->where('rating', 4)->count(),
    //         5 => ProductReview::where('product_id', $product->id)->where('status', 'approved')->where('rating', 5)->count(),
    //     ];

    //     // Get product attributes (unique attribute keys and values from all variants)
    //     $productAttributes = $this->getProductAttributes($product->variants);

    //     // If variant is selected, format only that variant
    //     if ($isVariantSelected && $selectedVariant) {
    //         // Get primary image for the variant
    //         $primaryImage = $selectedVariant->images->where('is_primary', true)->first()
    //             ?? $selectedVariant->images->first();

    //         // Get product image if variant has no images
    //         if (!$primaryImage) {
    //             $primaryImage = $product->images->where('is_primary', true)->first()
    //                 ?? $product->images->first();
    //         }

    //         $formattedProduct = [
    //             'id' => $product->id,
    //             'product_code' => $product->product_code,
    //             'name' => $product->name,
    //             'slug' => $product->slug,
    //             'description' => $product->description,
    //             'specification' => $product->specification,
    //             'hsn_code' => $product->hsn_code,
    //             'uom' => $product->uom,
    //             'category_id' => $product->category_id,
    //             'category' => $product->category ? [
    //                 'id' => $product->category->id,
    //                 'name' => $product->category->title,
    //                 'slug' => $product->category->slug,
    //                 'description' => $product->category->description,
    //             ] : null,
    //             'tax_category_id' => $product->tax_category_id,
    //             'tax_category' => $product->taxCategory ? [
    //                 'id' => $product->taxCategory->id,
    //                 'name' => $product->taxCategory->name,
    //                 'rate' => $product->taxCategory->rate,
    //             ] : null,

    //             // Variant pricing (from selected variant)
    //             'retail_mrp' => $selectedVariant->retail_mrp,
    //             'retail_price' => $selectedVariant->retail_price,
    //             'retail_discount_type' => $selectedVariant->retail_discount_type,
    //             'retail_discount_value' => $selectedVariant->retail_discount_value,
    //             'retail_discount_amount' => $selectedVariant->retail_mrp - $selectedVariant->retail_price,
    //             'retail_discount_percentage' => $selectedVariant->retail_mrp > 0
    //                 ? round((($selectedVariant->retail_mrp - $selectedVariant->retail_price) / $selectedVariant->retail_mrp) * 100, 2)
    //                 : 0,

    //             'distributor_mrp' => $selectedVariant->distributor_mrp,
    //             'distributor_price' => $selectedVariant->distributor_price,
    //             'distributor_discount_type' => $selectedVariant->distributor_discount_type,
    //             'distributor_discount_value' => $selectedVariant->distributor_discount_value,
    //             'distributor_discount_amount' => $selectedVariant->distributor_mrp && $selectedVariant->distributor_price
    //                 ? $selectedVariant->distributor_mrp - $selectedVariant->distributor_price
    //                 : null,
    //             'distributor_discount_percentage' => $selectedVariant->distributor_mrp && $selectedVariant->distributor_price && $selectedVariant->distributor_mrp > 0
    //                 ? round((($selectedVariant->distributor_mrp - $selectedVariant->distributor_price) / $selectedVariant->distributor_mrp) * 100, 2)
    //                 : null,

    //             // Variant stock
    //             'stock_quantity' => (int) $selectedVariant->stock_quantity,
    //             'low_stock_threshold' => (int) $selectedVariant->low_stock_threshold,
    //             'status' => $this->getVariantStatus($selectedVariant),

    //             // Product level flags
    //             'is_published' => (bool) $product->is_published,
    //             'is_trending' => (bool) $product->is_trending,
    //             'trending_sort_order' => (int) $product->trending_sort_order,
    //             'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
    //             'is_active_deal' => $product->isActiveDealOfTheDay(),
    //             'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
    //             'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),
    //             'sale_type' => $product->sale_type,
    //             'is_wishlisted' => in_array($product->id, $wishlistIds),

    //             // Images (from variant or product)
    //             'images' => $selectedVariant->images->isNotEmpty()
    //                 ? $selectedVariant->images->map(function ($image) {
    //                     return [
    //                         'id' => $image->id,
    //                         'image' => $image->image,
    //                         'image_url' => asset('storage/' . $image->image),
    //                         'sort_order' => $image->sort_order,
    //                         'is_primary' => (bool) $image->is_primary,
    //                     ];
    //                 })->values()->toArray()
    //                 : $product->images->map(function ($image) {
    //                     return [
    //                         'id' => $image->id,
    //                         'image' => $image->image,
    //                         'image_url' => asset('storage/' . $image->image),
    //                         'sort_order' => $image->sort_order,
    //                         'is_primary' => (bool) $image->is_primary,
    //                     ];
    //                 })->values()->toArray(),

    //             'primary_image' => $primaryImage ? $primaryImage->image : null,
    //             'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

    //             // Selected variant details
    //             'selected_variant' => $this->formatSingleVariant($selectedVariant),
    //             'selected_variant_id' => $selectedVariant->id,
    //             'selected_variant_sku' => $selectedVariant->sku,
    //             'selected_attributes' => $selectedVariant->attributes,

    //             // Available attributes for selection UI
    //             'available_attributes' => $productAttributes,

    //             // Only show the selected variant in the variants list
    //             'variants' => [$this->formatSingleVariant($selectedVariant)],

    //             'created_at' => $product->created_at?->toISOString(),
    //             'updated_at' => $product->updated_at?->toISOString(),
    //         ];
    //     } else {
    //         // No variant selected - show all variants
    //         $formattedProduct = $this->formatProduct($product, $wishlistIds);
    //         $formattedProduct['available_attributes'] = $productAttributes;
    //         $formattedProduct['variants'] = $this->formatVariants($product->variants, $product->id, $wishlistIds);
    //     }

    //     // Add reviews to the response
    //     $formattedProduct['reviews'] = [
    //         'summary' => [
    //             'average_rating' => round($averageRating, 1),
    //             'total_reviews' => $totalReviews,
    //             'rating_distribution' => $ratingDistribution,
    //         ],
    //         'data' => $reviews->map(function ($review) {
    //             return [
    //                 'id' => $review->id,
    //                 'user_id' => $review->user_id,
    //                 'user_name' => $review->user->full_name ?? 'Anonymous',
    //                 'user_profile_picture' => $review->user->profile_picture
    //                     ? asset('storage/' . $review->user->profile_picture)
    //                     : null,
    //                 'rating' => $review->rating,
    //                 'review_text' => $review->review_text,
    //                 'created_at' => $review->created_at->format('M d, Y'),
    //                 'updated_at' => $review->updated_at->format('M d, Y'),
    //                 'is_verified_purchase' => $this->isVerifiedPurchase($review->order_id),
    //                 'images' => $review->images->map(function ($image) {
    //                     return [
    //                         'id' => $image->id,
    //                         'image_url' => $image->image_url,
    //                         'sort_order' => $image->sort_order,
    //                     ];
    //                 })->values()->toArray(),
    //             ];
    //         })->values()->toArray(),
    //     ];

    //     return response()->json($formattedProduct);
    // }

    // public function showBySlug($slug)
    // {
    //     $product = Product::with([
    //         'category',
    //         'taxCategory',
    //         'images',
    //         'variants.images'
    //     ])
    //         ->where('slug', $slug)
    //         ->firstOrFail();

    //     $userId = request()->query('user_id');
    //     $wishlistIds = $this->getUserWishlistIds($userId);

    //     // Get selected variant from query parameter
    //     $selectedVariantId = request()->query('variant_id');
    //     $selectedAttributes = request()->query('attributes');

    //     $selectedVariant = null;
    //     $isVariantSelected = false;

    //     // If variant_id is provided
    //     if ($selectedVariantId) {
    //         $selectedVariant = $product->variants
    //             ->where('id', $selectedVariantId)
    //             ->first();

    //         if ($selectedVariant) {
    //             $isVariantSelected = true;
    //         }
    //     }

    //     // If attributes are provided, find matching variant
    //     if (!$selectedVariant && $selectedAttributes) {

    //         $attributes = is_string($selectedAttributes)
    //             ? json_decode($selectedAttributes, true)
    //             : $selectedAttributes;

    //         if (is_array($attributes)) {

    //             $selectedVariant = $product->variants->first(
    //                 function ($variant) use ($attributes) {

    //                     $variantAttributes = $variant->attributes ?? [];

    //                     foreach ($attributes as $key => $value) {

    //                         if (
    //                             !isset($variantAttributes[$key]) ||
    //                             $variantAttributes[$key] != $value
    //                         ) {
    //                             return false;
    //                         }
    //                     }

    //                     return true;
    //                 }
    //             );

    //             if ($selectedVariant) {
    //                 $isVariantSelected = true;
    //             }
    //         }
    //     }

    //     /*
    //  |--------------------------------------------------------------------------
    //     | Get all approved reviews
    //     |--------------------------------------------------------------------------
    //     |
    //     | orderLine.order is loaded so we can get:
    //     | - order_line_id
    //     | - order_id
    //     | - order_reference
    //     |
    //     */
    //     $reviews = ProductReview::with([
    //         'user' => function ($query) {
    //             $query->select(
    //                 'id',
    //                 'full_name',
    //                 'email',
    //                 'profile_picture'
    //             );
    //         },

    //         'images',

    //         'orderLine.order' => function ($query) {
    //             $query->select(
    //                 'id',
    //                 'order_reference'
    //             );
    //         },
    //     ])
    //         ->where('product_id', $product->id)
    //         ->where('status', 'approved')
    //         ->orderBy('created_at', 'desc')
    //         ->get();

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Review Summary
    //     |--------------------------------------------------------------------------
    //     */

    //     $averageRating = ProductReview::where('product_id', $product->id)
    //         ->where('status', 'approved')
    //         ->avg('rating');

    //     $totalReviews = ProductReview::where('product_id', $product->id)
    //         ->where('status', 'approved')
    //         ->count();

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Rating Distribution
    //     |--------------------------------------------------------------------------
    //     */

    //     $ratingDistribution = [
    //         1 => ProductReview::where('product_id', $product->id)
    //             ->where('status', 'approved')
    //             ->where('rating', 1)
    //             ->count(),

    //         2 => ProductReview::where('product_id', $product->id)
    //             ->where('status', 'approved')
    //             ->where('rating', 2)
    //             ->count(),

    //         3 => ProductReview::where('product_id', $product->id)
    //             ->where('status', 'approved')
    //             ->where('rating', 3)
    //             ->count(),

    //         4 => ProductReview::where('product_id', $product->id)
    //             ->where('status', 'approved')
    //             ->where('rating', 4)
    //             ->count(),

    //         5 => ProductReview::where('product_id', $product->id)
    //             ->where('status', 'approved')
    //             ->where('rating', 5)
    //             ->count(),
    //     ];

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Product Attributes
    //     |--------------------------------------------------------------------------
    //     */

    //         $productAttributes = $this->getProductAttributes($product->variants);

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Selected Variant
    //     |--------------------------------------------------------------------------
    //     */

    //     if ($isVariantSelected && $selectedVariant) {

    //         // Get primary image for selected variant
    //         $primaryImage = $selectedVariant->images
    //             ->where('is_primary', true)
    //             ->first()
    //             ?? $selectedVariant->images->first();

    //         // Fallback to product image
    //         if (!$primaryImage) {

    //             $primaryImage = $product->images
    //                 ->where('is_primary', true)
    //                 ->first()
    //                 ?? $product->images->first();
    //         }

    //         $formattedProduct = [

    //             'id' => $product->id,

    //             'product_code' => $product->product_code,

    //             'name' => $product->name,

    //             'slug' => $product->slug,

    //             'description' => $product->description,

    //             'specification' => $product->specification,

    //             'hsn_code' => $product->hsn_code,

    //             'uom' => $product->uom,

    //             'brand_id' => $product->brand_id ?? null,
    //             'category_id' => $product->category_id,

    //             'category' => $product->category
    //                 ? [
    //                     'id' => $product->category->id,
    //                     'name' => $product->category->title,
    //                     'slug' => $product->category->slug,
    //                     'description' => $product->category->description,
    //                 ]
    //                 : null,

    //             'tax_category_id' => $product->tax_category_id,

    //             'tax_category' => $product->taxCategory
    //                 ? [
    //                     'id' => $product->taxCategory->id,
    //                     'name' => $product->taxCategory->name,
    //                     'rate' => $product->taxCategory->rate,
    //                 ]
    //                 : null,

    //             /*
    //         |--------------------------------------------------------------------------
    //         | Retail Pricing
    //         |--------------------------------------------------------------------------
    //         */

    //             'retail_mrp' => $selectedVariant->retail_mrp,

    //             'retail_price' => $selectedVariant->retail_price,

    //             'retail_discount_type' => $selectedVariant->retail_discount_type,

    //             'retail_discount_value' => $selectedVariant->retail_discount_value,

    //             'retail_discount_amount' =>
    //             $selectedVariant->retail_mrp -
    //                 $selectedVariant->retail_price,

    //             'retail_discount_percentage' =>
    //             $selectedVariant->retail_mrp > 0
    //                 ? round(
    //                     (
    //                         (
    //                             $selectedVariant->retail_mrp -
    //                             $selectedVariant->retail_price
    //                         )
    //                         / $selectedVariant->retail_mrp
    //                     ) * 100,
    //                     2
    //                 )
    //                 : 0,

    //             /*
    //         |--------------------------------------------------------------------------
    //         | Distributor Pricing
    //         |--------------------------------------------------------------------------
    //         */

    //             'distributor_mrp' => $selectedVariant->distributor_mrp,

    //             'distributor_price' => $selectedVariant->distributor_price,

    //             'distributor_discount_type' =>
    //             $selectedVariant->distributor_discount_type,

    //             'distributor_discount_value' =>
    //             $selectedVariant->distributor_discount_value,

    //             'distributor_discount_amount' =>
    //             $selectedVariant->distributor_mrp &&
    //                 $selectedVariant->distributor_price
    //                 ? $selectedVariant->distributor_mrp -
    //                 $selectedVariant->distributor_price
    //                 : null,

    //             'distributor_discount_percentage' =>
    //             $selectedVariant->distributor_mrp &&
    //                 $selectedVariant->distributor_price &&
    //                 $selectedVariant->distributor_mrp > 0
    //                 ? round(
    //                     (
    //                         (
    //                             $selectedVariant->distributor_mrp -
    //                             $selectedVariant->distributor_price
    //                         )
    //                         / $selectedVariant->distributor_mrp
    //                     ) * 100,
    //                     2
    //                 )
    //                 : null,

    //             /*
    //         |--------------------------------------------------------------------------
    //         | Variant Stock
    //         |--------------------------------------------------------------------------
    //         */

    //             'stock_quantity' => (int) $selectedVariant->stock_quantity,

    //             'low_stock_threshold' =>
    //             (int) $selectedVariant->low_stock_threshold,

    //             'status' => $this->getVariantStatus($selectedVariant),

    //             /*
    //         |--------------------------------------------------------------------------
    //         | Product Flags
    //         |--------------------------------------------------------------------------
    //         */

    //             'is_published' => (bool) $product->is_published,

    //             'is_trending' => (bool) $product->is_trending,

    //             'trending_sort_order' =>
    //             (int) $product->trending_sort_order,

    //             'is_deal_of_the_day' =>
    //             (bool) $product->is_deal_of_the_day,

    //             'is_active_deal' =>
    //             $product->isActiveDealOfTheDay(),

    //             'deal_of_the_day_starts_at' =>
    //             $product->deal_of_the_day_starts_at?->toISOString(),

    //             'deal_of_the_day_ends_at' =>
    //             $product->deal_of_the_day_ends_at?->toISOString(),

    //             'sale_type' => $product->sale_type,

    //             'is_wishlisted' =>
    //             in_array($product->id, $wishlistIds),

    //             /*
    //         |--------------------------------------------------------------------------
    //         | Images
    //         |--------------------------------------------------------------------------
    //         */

    //             'images' => $selectedVariant->images->isNotEmpty()

    //                 ? $selectedVariant->images
    //                 ->map(function ($image) {

    //                     return [
    //                         'id' => $image->id,
    //                         'image' => $image->image,
    //                         'image_url' =>
    //                         asset('storage/' . $image->image),
    //                         'sort_order' => $image->sort_order,
    //                         'is_primary' =>
    //                         (bool) $image->is_primary,
    //                     ];
    //                 })
    //                 ->values()
    //                 ->toArray()

    //                 : $product->images
    //                 ->map(function ($image) {

    //                     return [
    //                         'id' => $image->id,
    //                         'image' => $image->image,
    //                         'image_url' =>
    //                         asset('storage/' . $image->image),
    //                         'sort_order' => $image->sort_order,
    //                         'is_primary' =>
    //                         (bool) $image->is_primary,
    //                     ];
    //                 })
    //                 ->values()
    //                 ->toArray(),

    //             'primary_image' =>
    //             $primaryImage
    //                 ? $primaryImage->image
    //                 : null,

    //             'primary_image_url' =>
    //             $primaryImage
    //                 ? asset('storage/' . $primaryImage->image)
    //                 : null,

    //             /*
    //         |--------------------------------------------------------------------------
    //         | Selected Variant
    //         |--------------------------------------------------------------------------
    //         */

    //             'selected_variant' =>
    //             $this->formatSingleVariant($selectedVariant),

    //             'selected_variant_id' =>
    //             $selectedVariant->id,

    //             'selected_variant_sku' =>
    //             $selectedVariant->sku,

    //             'selected_attributes' =>
    //             $selectedVariant->attributes,

    //             /*
    //         |--------------------------------------------------------------------------
    //         | Available Attributes
    //         |--------------------------------------------------------------------------
    //         */

    //             'available_attributes' => $productAttributes,

    //             /*
    //         |--------------------------------------------------------------------------
    //         | Only Selected Variant
    //         |--------------------------------------------------------------------------
    //         */

    //             'variants' => [
    //                 $this->formatSingleVariant($selectedVariant)
    //             ],

    //             'created_at' =>
    //             $product->created_at?->toISOString(),

    //             'updated_at' =>
    //             $product->updated_at?->toISOString(),
    //         ];
    //     } else {

    //         /*
    //     |--------------------------------------------------------------------------
    //     | No Variant Selected
    //     |--------------------------------------------------------------------------
    //     */

    //         $formattedProduct =
    //             $this->formatProduct(
    //                 $product,
    //                 $wishlistIds
    //             );

    //         $formattedProduct['available_attributes'] =
    //             $productAttributes;

    //         $formattedProduct['variants'] =
    //             $this->formatVariants(
    //                 $product->variants,
    //                 $product->id,
    //                 $wishlistIds
    //             );
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Add Reviews + Review Summary
    //     |--------------------------------------------------------------------------
    //     */

    //     $formattedProduct['reviews'] = [

    //         'summary' => [

    //             'average_rating' =>
    //             $averageRating !== null
    //                 ? round($averageRating, 1)
    //                 : 0,

    //             'total_reviews' =>
    //             $totalReviews,

    //             'rating_distribution' =>
    //             $ratingDistribution,
    //         ],
    //         'data' => $reviews
    //             ->map(function ($review) {

    //                 return [

    //                     /*
    //                 |--------------------------------------------------------------------------
    //                 | Review
    //                 |--------------------------------------------------------------------------
    //                 */

    //                     'id' => $review->id,

    //                     'rating' => $review->rating,

    //                     'review_text' => $review->review_text,

    //                     /*
    //                 |--------------------------------------------------------------------------
    //                 | Reviewer Details
    //                 |--------------------------------------------------------------------------
    //                 */

    //                     'user' => [

    //                         'id' =>
    //                         $review->user?->id,

    //                         'full_name' =>
    //                         $review->user?->full_name,

    //                         'profile_picture' =>
    //                         $review->user?->profile_picture
    //                             ? asset(
    //                                 'storage/' .
    //                                     $review->user->profile_picture
    //                             )
    //                             : null,
    //                     ],

    //                     /*
    //                 |--------------------------------------------------------------------------
    //                 | Order Details
    //                 |--------------------------------------------------------------------------
    //                 */

    //                     'order_line_id' =>
    //                     $review->order_line_id,

    //                     'order_id' =>
    //                     $review->orderLine?->order?->id
    //                         ?? $review->order_id,

    //                     'order_reference' =>
    //                     $review->orderLine?->order?->order_reference,

    //                     /*
    //                 |--------------------------------------------------------------------------
    //                 | Dates
    //                 |--------------------------------------------------------------------------
    //                 */

    //                     'created_at' =>
    //                     $review->created_at
    //                         ? $review->created_at->format('M d, Y')
    //                         : null,

    //                     'updated_at' =>
    //                     $review->updated_at
    //                         ? $review->updated_at->format('M d, Y')
    //                         : null,

    //                     /*
    //                 |--------------------------------------------------------------------------
    //                 | Verified Purchase
    //                 |--------------------------------------------------------------------------
    //                 */

    //                     'is_verified_purchase' =>
    //                     $this->isVerifiedPurchase(
    //                         $review->order_id
    //                     ),

    //                     /*
    //                 |--------------------------------------------------------------------------
    //                 | Review Images
    //                 |--------------------------------------------------------------------------
    //                 */

    //                     'images' =>
    //                     $review->images
    //                         ->map(function ($image) {

    //                             return [
    //                                 'id' => $image->id,

    //                                 'image_url' =>
    //                                 $image->image_url,

    //                                 'sort_order' =>
    //                                 $image->sort_order,
    //                             ];
    //                         })
    //                         ->values()
    //                         ->toArray(),
    //                 ];
    //             })
    //             ->values()
    //             ->toArray(),
    //     ];

    //     return response()->json($formattedProduct);
    // }

    public function showBySlug($slug)
    {
        $product = Product::with([
            'category',
            'taxCategory',
            'images',
            'variants.images'
        ])
            ->where('slug', $slug)
            ->firstOrFail();

        $userId = request()->query('user_id');
        $wishlistIds = $this->getUserWishlistIds($userId);

        // Get selected variant from query parameter
        $selectedVariantId = request()->query('variant_id');
        $selectedAttributes = request()->query('attributes');

        $selectedVariant = null;
        $isVariantSelected = false;

        // If variant_id is provided
        if ($selectedVariantId) {
            $selectedVariant = $product->variants
                ->where('id', $selectedVariantId)
                ->first();

            if ($selectedVariant) {
                $isVariantSelected = true;
            }
        }

        // If attributes are provided, find matching variant
        if (!$selectedVariant && $selectedAttributes) {

            $attributes = is_string($selectedAttributes)
                ? json_decode($selectedAttributes, true)
                : $selectedAttributes;

            if (is_array($attributes)) {

                $selectedVariant = $product->variants->first(
                    function ($variant) use ($attributes) {

                        $variantAttributes = $variant->attributes ?? [];

                        foreach ($attributes as $key => $value) {

                            if (
                                !isset($variantAttributes[$key]) ||
                                $variantAttributes[$key] != $value
                            ) {
                                return false;
                            }
                        }

                        return true;
                    }
                );

                if ($selectedVariant) {
                    $isVariantSelected = true;
                }
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Get all approved reviews
    |--------------------------------------------------------------------------
    |
    | orderLine.order is loaded so we can get:
    | - order_line_id
    | - order_id
    | - order_reference
    |
    */
        $reviews = ProductReview::with([
            'user' => function ($query) {
                $query->select(
                    'id',
                    'full_name',
                    'email',
                    'profile_picture'
                );
            },

            'images',

            'orderLine.order' => function ($query) {
                $query->select(
                    'id',
                    'order_reference'
                );
            },
        ])
            ->where('product_id', $product->id)
            ->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->get();

        /*
    |--------------------------------------------------------------------------
    | Review Summary
    |--------------------------------------------------------------------------
    */

        $averageRating = ProductReview::where('product_id', $product->id)
            ->where('status', 'approved')
            ->avg('rating');

        $totalReviews = ProductReview::where('product_id', $product->id)
            ->where('status', 'approved')
            ->count();

        /*
    |--------------------------------------------------------------------------
    | Rating Distribution
    |--------------------------------------------------------------------------
    */

        $ratingDistribution = [
            1 => ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->where('rating', 1)
                ->count(),

            2 => ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->where('rating', 2)
                ->count(),

            3 => ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->where('rating', 3)
                ->count(),

            4 => ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->where('rating', 4)
                ->count(),

            5 => ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->where('rating', 5)
                ->count(),
        ];

        /*
    |--------------------------------------------------------------------------
    | Product Attributes
    |--------------------------------------------------------------------------
    */

        $productAttributes = $this->getProductAttributes($product->variants);

        /*
    |--------------------------------------------------------------------------
    | Selected Variant
    |--------------------------------------------------------------------------
    */

        if ($isVariantSelected && $selectedVariant) {

            // Get primary image for selected variant
            $primaryImage = $selectedVariant->images
                ->where('is_primary', true)
                ->first()
                ?? $selectedVariant->images->first();


            // Fallback to product image
            if (!$primaryImage) {

                $primaryImage = $product->images
                    ->where('is_primary', true)
                    ->first()
                    ?? $product->images->first();
            }

            $formattedProduct = [

                'id' => $product->id,

                'product_code' => $product->product_code,

                'name' => $product->name,

                'slug' => $product->slug,

                'description' => $product->description,

                'specification' => $product->specification,

                'hsn_code' => $product->hsn_code,

                'uom' => $product->uom,

                'brand_id' => $product->brand_id ?? null,
                'category_id' => $product->category_id,

                'category' => $product->category
                    ? [
                        'id' => $product->category->id,
                        'name' => $product->category->title,
                        'slug' => $product->category->slug,
                        'description' => $product->category->description,
                    ]
                    : null,

                'tax_category_id' => $product->tax_category_id,

                'tax_category' => $product->taxCategory
                    ? [
                        'id' => $product->taxCategory->id,
                        'name' => $product->taxCategory->name,
                        'rate' => $product->taxCategory->rate,
                    ]
                    : null,

                /*
            |--------------------------------------------------------------------------
            | Retail Pricing
            |--------------------------------------------------------------------------
            */

                'retail_mrp' => $selectedVariant->retail_mrp,

                'retail_price' => $selectedVariant->retail_price,

                'retail_discount_type' => $selectedVariant->retail_discount_type,

                'retail_discount_value' => $selectedVariant->retail_discount_value,

                'retail_discount_amount' =>
                $selectedVariant->retail_mrp -
                    $selectedVariant->retail_price,

                'retail_discount_percentage' =>
                $selectedVariant->retail_mrp > 0
                    ? round(
                        (
                            (
                                $selectedVariant->retail_mrp -
                                $selectedVariant->retail_price
                            )
                            / $selectedVariant->retail_mrp
                        ) * 100,
                        2
                    )
                    : 0,

                /*
            |--------------------------------------------------------------------------
            | Distributor Pricing
            |--------------------------------------------------------------------------
            */

                'distributor_mrp' => $selectedVariant->distributor_mrp,

                'distributor_price' => $selectedVariant->distributor_price,

                'distributor_discount_type' =>
                $selectedVariant->distributor_discount_type,

                'distributor_discount_value' =>
                $selectedVariant->distributor_discount_value,

                'distributor_discount_amount' =>
                $selectedVariant->distributor_mrp &&
                    $selectedVariant->distributor_price
                    ? $selectedVariant->distributor_mrp -
                    $selectedVariant->distributor_price
                    : null,

                'distributor_discount_percentage' =>
                $selectedVariant->distributor_mrp &&
                    $selectedVariant->distributor_price &&
                    $selectedVariant->distributor_mrp > 0
                    ? round(
                        (
                            (
                                $selectedVariant->distributor_mrp -
                                $selectedVariant->distributor_price
                            )
                            / $selectedVariant->distributor_mrp
                        ) * 100,
                        2
                    )
                    : null,

                /*
            |--------------------------------------------------------------------------
            | Variant Stock
            |--------------------------------------------------------------------------
            */

                'stock_quantity' => (int) $selectedVariant->stock_quantity,

                'low_stock_threshold' =>
                (int) $selectedVariant->low_stock_threshold,

                'status' => $this->getVariantStatus($selectedVariant),

                /*
            |--------------------------------------------------------------------------
            | Product Flags
            |--------------------------------------------------------------------------
            */

                'is_published' => (bool) $product->is_published,

                'is_trending' => (bool) $product->is_trending,

                'trending_sort_order' =>
                (int) $product->trending_sort_order,

                'is_deal_of_the_day' =>
                (bool) $product->is_deal_of_the_day,

                'is_active_deal' =>
                $product->isActiveDealOfTheDay(),

                'deal_of_the_day_starts_at' =>
                $product->deal_of_the_day_starts_at?->toISOString(),

                'deal_of_the_day_ends_at' =>
                $product->deal_of_the_day_ends_at?->toISOString(),

                'sale_type' => $product->sale_type,

                'is_wishlisted' =>
                in_array($product->id, $wishlistIds),

                /*
            |--------------------------------------------------------------------------
            | Images
            |--------------------------------------------------------------------------
            */

                'images' => $selectedVariant->images->isNotEmpty()

                    ? $selectedVariant->images
                    ->map(function ($image) {

                        return [
                            'id' => $image->id,
                            'image' => $image->image,
                            'image_url' =>
                            asset('storage/' . $image->image),
                            'sort_order' => $image->sort_order,
                            'is_primary' =>
                            (bool) $image->is_primary,
                        ];
                    })
                    ->values()
                    ->toArray()

                    : $product->images
                    ->map(function ($image) {

                        return [
                            'id' => $image->id,
                            'image' => $image->image,
                            'image_url' =>
                            asset('storage/' . $image->image),
                            'sort_order' => $image->sort_order,
                            'is_primary' =>
                            (bool) $image->is_primary,
                        ];
                    })
                    ->values()
                    ->toArray(),

                'primary_image' =>
                $primaryImage
                    ? $primaryImage->image
                    : null,

                'primary_image_url' =>
                $primaryImage
                    ? asset('storage/' . $primaryImage->image)
                    : null,

                /*
            |--------------------------------------------------------------------------
            | Selected Variant
            |--------------------------------------------------------------------------
            */

                'selected_variant' =>
                $this->formatSingleVariant($selectedVariant),

                'selected_variant_id' =>
                $selectedVariant->id,

                'selected_variant_sku' =>
                $selectedVariant->sku,

                'selected_attributes' =>
                $selectedVariant->attributes,

                /*
            |--------------------------------------------------------------------------
            | Available Attributes
            |--------------------------------------------------------------------------
            */

                'available_attributes' => $productAttributes,

                /*
            |--------------------------------------------------------------------------
            | Only Selected Variant
            |--------------------------------------------------------------------------
            */

                'variants' => [
                    $this->formatSingleVariant($selectedVariant)
                ],

                'created_at' =>
                $product->created_at?->toISOString(),

                'updated_at' =>
                $product->updated_at?->toISOString(),
            ];
        } else {

            /*
        |--------------------------------------------------------------------------
        | No Variant Selected
        |--------------------------------------------------------------------------
        */

            $formattedProduct =
                $this->formatProduct(
                    $product,
                    $wishlistIds
                );

            $formattedProduct['available_attributes'] =
                $productAttributes;

            $formattedProduct['variants'] =
                $this->formatVariants(
                    $product->variants,
                    $product->id,
                    $wishlistIds
                );
        }

        /*
    |--------------------------------------------------------------------------
    | Add Reviews + Review Summary
    |--------------------------------------------------------------------------
    */

        $formattedProduct['reviews'] = [

            'summary' => [

                'average_rating' =>
                $averageRating !== null
                    ? round($averageRating, 1)
                    : 0,

                'total_reviews' =>
                $totalReviews,

                'rating_distribution' =>
                $ratingDistribution,
            ],
            'data' => $reviews
                ->map(function ($review) {

                    return [

                        /*
                    |--------------------------------------------------------------------------
                    | Review
                    |--------------------------------------------------------------------------
                    */

                        'id' => $review->id,

                        'rating' => $review->rating,

                        'review_text' => $review->review_text,

                        /*
                    |--------------------------------------------------------------------------
                    | Reviewer Details
                    |--------------------------------------------------------------------------
                    */

                        'user' => [

                            'id' =>
                            $review->user?->id,

                            'full_name' =>
                            $review->user?->full_name,

                            'profile_picture' =>
                            $review->user?->profile_picture
                                ? asset(
                                    'storage/' .
                                        $review->user->profile_picture
                                )
                                : null,
                        ],

                        /*
                    |--------------------------------------------------------------------------
                    | Order Details
                    |--------------------------------------------------------------------------
                    */

                        'order_line_id' =>
                        $review->order_line_id,

                        'order_id' =>
                        $review->orderLine?->order?->id
                            ?? $review->order_id,

                        'order_reference' =>
                        $review->orderLine?->order?->order_reference,

                        /*
                    |--------------------------------------------------------------------------
                    | Dates
                    |--------------------------------------------------------------------------
                    */

                        'created_at' =>
                        $review->created_at
                            ? $review->created_at->format('M d, Y')
                            : null,

                        'updated_at' =>
                        $review->updated_at
                            ? $review->updated_at->format('M d, Y')
                            : null,

                        /*
                    |--------------------------------------------------------------------------
                    | Verified Purchase
                    |--------------------------------------------------------------------------
                    */

                        'is_verified_purchase' =>
                        $this->isVerifiedPurchase(
                            $review->order_id
                        ),

                        /*
                    |--------------------------------------------------------------------------
                    | Review Images
                    |--------------------------------------------------------------------------
                    */

                        'images' =>
                        $review->images
                            ->map(function ($image) {

                                return [
                                    'id' => $image->id,

                                    'image_url' =>
                                    $image->image_url,

                                    'sort_order' =>
                                    $image->sort_order,
                                ];
                            })
                            ->values()
                            ->toArray(),
                    ];
                })
                ->values()
                ->toArray(),
        ];

        return response()->json($formattedProduct);
    }

    protected function getPriceRange()
    {
        $minPrice = Product::min('retail_price');
        $maxPrice = Product::max('retail_price');

        return [
            'min' => $minPrice ? (float) $minPrice : 0,
            'max' => $maxPrice ? (float) $maxPrice : 0,
        ];
    }

    protected function calculatePrice($mrp, $discountType = null, $discountValue = null)
    {
        if (empty($discountType) || empty($discountValue) || $discountValue <= 0) {
            return $mrp;
        }

        if ($discountType === 'percentage') {
            $discountAmount = ($mrp * $discountValue) / 100;
            $finalPrice = $mrp - $discountAmount;
        } elseif ($discountType === 'fixed') {
            $finalPrice = $mrp - $discountValue;
        } else {
            return $mrp;
        }

        return max(0, $finalPrice);
    }

    protected function getProductStatus($product)
    {
        if (!$product->is_published) return 'draft';
        if ($product->stock_quantity <= 0) return 'out_of_stock';
        if ($product->stock_quantity <= $product->low_stock_threshold) return 'low_stock';
        return 'active';
    }

    protected function generateUniqueSlug($slug, $ignoreId = null)
    {
        $originalSlug = $slug;
        $count = 1;

        while (Product::where('slug', $slug)
            ->when($ignoreId, function ($query) use ($ignoreId) {
                return $query->where('id', '!=', $ignoreId);
            })
            ->exists()
        ) {
            $slug = $originalSlug . '-' . $count;
            $count++;
        }

        return $slug;
    }

    private function isVerifiedPurchase($orderId)
    {
        if (!$orderId) {
            return false;
        }

        $order = Order::find($orderId);
        return $order && $order->status === 'delivered';
    }

    public function productsByCategory(Request $request, $categoryId)
    {
        $limit = $request->get('limit', 20);
        $excludeId = $request->get('exclude_product_id');

        $query = Product::with(['category', 'images'])
            ->where('category_id', $categoryId)
            ->where('is_published', true);

        // Exclude specific product if needed
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        // Optional: Exclude out of stock products
        if ($request->get('exclude_out_of_stock', false)) {
            $query->where('stock_quantity', '>', 0);
        }

        // Sort options
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        $allowedSortFields = ['id', 'name', 'retail_price', 'stock_quantity', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        $products = $query->limit($limit)->get();

        // Get wishlist IDs for authenticated user
        $userId = $request->query('user_id');
        $wishlistIds = $this->getUserWishlistIds($userId);

        return response()->json([
            'category_id' => (int) $categoryId,
            'total' => $products->count(),
            'data' => $this->formatProductCollection($products, $wishlistIds),
        ]);
    }
    public function productsByBrand(Request $request, $brandId)
    {
        $limit = $request->get('limit', 20);
        $excludeId = $request->get('exclude_product_id');

        $query = Product::with(['brand', 'images', 'variants.images', 'category', 'taxCategory'])
            ->where('brand_id', $brandId)
            ->where('is_published', true);

        // Exclude specific product if needed
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        // Exclude out of stock products
        if ($request->get('exclude_out_of_stock', false)) {
            $query->where('stock_quantity', '>', 0);
        }

        // New Arrivals filter
        // Products created within the last 30 days
        if ($request->get('new_arrivals', false)) {
            $query->where('created_at', '>=', now()->subDays(30));
        }

        // Sort options
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        $allowedSortFields = [
            'id',
            'name',
            'retail_price',
            'stock_quantity',
            'created_at',
            'updated_at'
        ];

        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        $products = $query->limit($limit)->get();

        // Get wishlist IDs for authenticated user
        $userId = $request->query('user_id');
        $wishlistIds = $this->getUserWishlistIds($userId);

        // Format products manually with brand details inside each product
        $formattedProducts = $products->map(function ($product) use ($wishlistIds) {
            $isWishlisted = in_array($product->id, $wishlistIds);
            $isActiveDeal = $product->isActiveDealOfTheDay();

            // Get product reviews
            $averageRating = ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->avg('rating');

            $totalReviews = ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->count();

            $primaryImage = $product->images->where('is_primary', true)->first()
                ?? $product->images->first();

            return [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'slug' => $product->slug,


                // Brand details inside each product
                'brand' => [
                    'id' => $product->brand?->id,
                    'title' => $product->brand?->title,
                    'discount_percentage' => $product->brand?->discount_percentage,
                    'logo' => $product->brand?->logo
                        ? asset('storage/' . $product->brand->logo)
                        : null,
                    'banner' => $product->brand?->banner
                        ? asset('storage/' . $product->brand->banner)
                        : null,
                ],



                // Pricing
                'retail_mrp' => $product->retail_mrp,
                'retail_price' => $product->retail_price,
                'retail_discount_percentage' => $product->retail_mrp > 0
                    ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
                    : 0,

                'distributor_mrp' => $product->distributor_mrp,
                'distributor_price' => $product->distributor_price,

                // Deal of the day
                'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
                'is_active_deal' => $isActiveDeal,
                'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
                'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),
                'sale_type' => $product->sale_type,

                // Stock
                'stock_quantity' => (int) $product->stock_quantity,
                'low_stock_threshold' => (int) $product->low_stock_threshold,
                'stock_status' => $this->getProductStatus($product),
                'is_published' => (bool) $product->is_published,
                'is_trending' => (bool) $product->is_trending,
                'trending_sort_order' => (int) $product->trending_sort_order,
                'is_wishlisted' => $isWishlisted,

                // Reviews Summary
                'reviews_summary' => [
                    'average_rating' => round($averageRating, 1),
                    'total_reviews' => $totalReviews,
                ],

                // Images
                'images' => $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => asset('storage/' . $image->image),
                        'is_primary' => (bool) $image->is_primary,
                        'sort_order' => $image->sort_order,
                    ];
                })->values()->toArray(),
                'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

                // Variants
                'variants' => $product->variants->map(function ($variant) {
                    $primaryVariantImage = $variant->images->where('is_primary', true)->first()
                        ?? $variant->images->first();

                    $attributes = $variant->attributes;
                    if (is_string($attributes)) {
                        $attributes = json_decode($attributes, true);
                    }

                    return [
                        'id' => $variant->id,
                        'product_id' => $variant->product_id,
                        'sku' => $variant->sku,
                        'attributes' => $attributes,
                        'retail_price' => $variant->retail_price,
                        'retail_mrp' => $variant->retail_mrp,
                        'retail_discount_type' => $variant->retail_discount_type,
                        'retail_discount_value' => $variant->retail_discount_value,
                        'distributor_price' => $variant->distributor_price,
                        'distributor_mrp' => $variant->distributor_mrp,
                        'distributor_discount_type' => $variant->distributor_discount_type,
                        'distributor_discount_value' => $variant->distributor_discount_value,
                        'stock_quantity' => (int) $variant->stock_quantity,
                        'low_stock_threshold' => (int) $variant->low_stock_threshold,
                        'sort_order' => (int) $variant->sort_order,
                        'is_active' => (bool) $variant->is_active,
                        'images' => $variant->images->map(function ($image) {
                            return [
                                'id' => $image->id,
                                'variant_id' => $image->variant_id,
                                'image_url' => asset('storage/' . $image->image),
                                'sort_order' => $image->sort_order,
                                'is_primary' => (bool) $image->is_primary,
                            ];
                        })->values()->toArray(),
                        'primary_image_url' => $primaryVariantImage ? asset('storage/' . $primaryVariantImage->image) : null,
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();

        return response()->json([
            'total' => $products->count(),
            'data' => $formattedProducts,
        ]);
    }

    /**
     * Delete multiple images from a product
     */
    public function deleteImages(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'image_ids' => ['required', 'array'],
            'image_ids.*' => ['exists:product_images,id'],
            'variant_image_ids' => ['nullable', 'array'],
            'variant_image_ids.*' => ['exists:variant_images,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product = Product::where('id', $id)->first();

        if (!$product) {
            return response()->json([
                'message' => 'No product found'
            ], 404);
        }

        DB::beginTransaction();
        try {
            // Delete product images
            if ($request->has('image_ids')) {
                $imagesToDelete = ProductImage::whereIn('id', $request->image_ids)
                    ->where('product_id', $product->id)
                    ->get();

                if ($imagesToDelete->isNotEmpty()) {
                    $deletedPrimary = $imagesToDelete->contains('is_primary', true);

                    // Delete image files and database records
                    foreach ($imagesToDelete as $image) {
                        if (Storage::disk('public')->exists($image->image)) {
                            Storage::disk('public')->delete($image->image);
                        }
                        $image->delete();
                    }

                    // If primary image was deleted, set a new primary image
                    if ($deletedPrimary) {
                        $remainingImage = ProductImage::where('product_id', $product->id)
                            ->orderBy('sort_order')
                            ->first();

                        if ($remainingImage) {
                            $remainingImage->update(['is_primary' => true]);
                        }
                    }
                }
            }

            // Delete variant images
            if ($request->has('variant_image_ids')) {
                $variantImagesToDelete = VariantImage::whereIn('id', $request->variant_image_ids)
                    ->whereHas('variant', function ($query) use ($product) {
                        $query->where('product_id', $product->id);
                    })
                    ->get();

                foreach ($variantImagesToDelete as $image) {
                    if (Storage::disk('public')->exists($image->image)) {
                        Storage::disk('public')->delete($image->image);
                    }
                    $image->delete();
                }
            }

            DB::commit();

            // Reload product with images and variants
            $product->load(['category', 'taxCategory', 'images', 'variants.images']);

            return response()->json([
                'message' => 'Images deleted successfully',
                'data' => $this->formatProduct($product)
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete images',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a single image from a product or variant
     */
    public function deleteImage(Request $request, $productId, $imageId)
    {
        $product = Product::where('id', $productId)->first();

        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 404);
        }

        $type = $request->get('type', 'product'); // 'product' or 'variant'

        DB::beginTransaction();
        try {
            if ($type === 'variant') {
                // Delete variant image
                $image = VariantImage::where('id', $imageId)
                    ->whereHas('variant', function ($query) use ($product) {
                        $query->where('product_id', $product->id);
                    })
                    ->first();

                if (!$image) {
                    return response()->json([
                        'message' => 'Variant image not found for this product'
                    ], 404);
                }

                if (Storage::disk('public')->exists($image->image)) {
                    Storage::disk('public')->delete($image->image);
                }

                $image->delete();
            } else {
                // Delete product image
                $image = ProductImage::where('id', $imageId)
                    ->where('product_id', $product->id)
                    ->first();

                if (!$image) {
                    return response()->json([
                        'message' => 'Image not found for this product'
                    ], 404);
                }

                $wasPrimary = $image->is_primary;

                if (Storage::disk('public')->exists($image->image)) {
                    Storage::disk('public')->delete($image->image);
                }

                $image->delete();

                // If deleted image was primary, set a new primary image
                if ($wasPrimary) {
                    $remainingImage = ProductImage::where('product_id', $product->id)
                        ->orderBy('sort_order')
                        ->first();

                    if ($remainingImage) {
                        $remainingImage->update(['is_primary' => true]);
                    }
                }
            }

            DB::commit();

            $product->load(['category', 'taxCategory', 'images', 'variants.images']);

            return response()->json([
                'message' => 'Image deleted successfully',
                'data' => $this->formatProduct($product)
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get trending products
     */
    // public function trending()
    // {
    //     $products = Product::with(['images', 'variants.images'])
    //         ->where('is_published', true)
    //         ->where('is_trending', true)
    //         ->orderBy('trending_sort_order', 'asc')
    //         ->get();

    //     $data = $products->map(function ($product) {
    //         // Get reviews for trending products
    //         $averageRating = ProductReview::where('product_id', $product->id)
    //             ->where('status', 'approved')
    //             ->avg('rating');

    //         $totalReviews = ProductReview::where('product_id', $product->id)
    //             ->where('status', 'approved')
    //             ->count();

    //         $primaryImage = $product->images->where('is_primary', true)->first()
    //             ?? $product->images->first();

    //         // Get variant summary
    //         $variantsSummary = $this->getVariantsSummary($product->variants);

    //         return [
    //             'id' => $product->id,
    //             'name' => $product->name,
    //             'slug' => $product->slug,
    //             'description' => $product->description,
    //             'product_code' => $product->product_code,
    //             'category_id' => $product->category_id,
    //             'tax_category_id' => $product->tax_category_id,

    //             // Retail pricing
    //             'retail_price' => $product->retail_price,
    //             'retail_mrp' => $product->retail_mrp,
    //             'retail_discount_type' => $product->retail_discount_type,
    //             'retail_discount_value' => $product->retail_discount_value,
    //             'retail_discount_percentage' => $product->retail_mrp > 0
    //                 ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
    //                 : 0,

    //             // Distributor pricing
    //             'distributor_price' => $product->distributor_price,
    //             'distributor_mrp' => $product->distributor_mrp,
    //             'distributor_discount_type' => $product->distributor_discount_type,
    //             'distributor_discount_value' => $product->distributor_discount_value,

    //             // Stock
    //             'stock_quantity' => (int) $product->stock_quantity,
    //             'stock_status' => $this->getProductStatus($product),

    //             // Review Summary
    //             'reviews' => [
    //                 'average_rating' => round($averageRating, 1),
    //                 'total_reviews' => $totalReviews,
    //             ],

    //             // Variants summary
    //             'variants_summary' => $variantsSummary,

    //             // Images
    //             'images' => $product->images->map(function ($image) {
    //                 return [
    //                     'id' => $image->id,
    //                     'image_url' => asset('storage/' . $image->image),
    //                     'is_primary' => (bool) $image->is_primary,
    //                     'sort_order' => $image->sort_order,
    //                 ];
    //             })->values()->toArray(),
    //             'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

    //             'is_trending' => (bool) $product->is_trending,
    //             'trending_sort_order' => (int) $product->trending_sort_order,
    //         ];
    //     });

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Trending products retrieved successfully.',
    //         'data' => $data,
    //     ]);
    // }
    public function trending()
    {
        $products = Product::with(['brand', 'images', 'variants.images'])
            ->where('is_published', true)
            ->where('is_trending', true)
            ->whereHas('brand', function ($q) {
                $q->where('status', true);
            })
            ->orderBy('trending_sort_order', 'asc')
            ->get();

        $data = $products->map(function ($product) {
            // Get reviews for trending products
            $averageRating = ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->avg('rating');

            $totalReviews = ProductReview::where('product_id', $product->id)
                ->where('status', 'approved')
                ->count();

            $primaryImage = $product->images->where('is_primary', true)->first()
                ?? $product->images->first();

            // Get variant summary
            $variantsSummary = $this->getVariantsSummary($product->variants);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'product_code' => $product->product_code,
                'category_id' => $product->category_id,
                'tax_category_id' => $product->tax_category_id,
                'brand_id' => $product->brand_id,

                // Brand information
                'brand' => $product->brand ? [
                    'id' => $product->brand->id,
                    'title' => $product->brand->title,
                    'logo' => $product->brand->logo_url,
                    'discount_percentage' => $product->brand->discount_percentage,
                ] : null,

                // Retail pricing
                'retail_price' => $product->retail_price,
                'retail_mrp' => $product->retail_mrp,
                'retail_discount_type' => $product->retail_discount_type,
                'retail_discount_value' => $product->retail_discount_value,
                'retail_discount_percentage' => $product->retail_mrp > 0
                    ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
                    : 0,

                // Distributor pricing
                'distributor_price' => $product->distributor_price,
                'distributor_mrp' => $product->distributor_mrp,
                'distributor_discount_type' => $product->distributor_discount_type,
                'distributor_discount_value' => $product->distributor_discount_value,

                // Stock
                'stock_quantity' => (int) $product->stock_quantity,
                'stock_status' => $this->getProductStatus($product),

                // Review Summary
                'reviews' => [
                    'average_rating' => round($averageRating, 1),
                    'total_reviews' => $totalReviews,
                ],

                // Variants summary
                'variants_summary' => $variantsSummary,

                // Images
                'images' => $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => asset('storage/' . $image->image),
                        'is_primary' => (bool) $image->is_primary,
                        'sort_order' => $image->sort_order,
                    ];
                })->values()->toArray(),
                'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

                'is_trending' => (bool) $product->is_trending,
                'trending_sort_order' => (int) $product->trending_sort_order,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Trending products retrieved successfully.',
            'data' => $data,
        ]);
    }

    /**
     * Get product sections (new arrivals, best sellers, best offers) with variants
     */
    public function getProductSections(Request $request)
    {
        // 1. NEW ARRIVALS - Products created within last 30 days
        $newArrivals = Product::with(['category', 'taxCategory', 'images', 'variants.images'])
            ->where('is_published', true)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // 2. BEST SELLERS - Top 8 products from order_lines
        $bestSellerIds = DB::table('order_lines')
            ->select('product_id', DB::raw('COUNT(*) as order_count'))
            ->whereNotNull('product_id')
            ->groupBy('product_id')
            ->orderBy('order_count', 'DESC')
            ->limit(8)
            ->pluck('product_id')
            ->toArray();

        $bestSellers = Product::with(['category', 'taxCategory', 'images', 'variants.images'])
            ->whereIn('id', $bestSellerIds)
            ->where('is_published', true)
            ->get();

        // If no best sellers found, get default products
        if ($bestSellers->isEmpty()) {
            $bestSellers = Product::with(['category', 'taxCategory', 'images', 'variants.images'])
                ->where('is_published', true)
                ->limit(8)
                ->get();
        }

        // 3. BEST OFFERS - Products with discounts based on user type
        $userType = $request->query('user_type', 'customer'); // 'customer' or 'distributor'

        $bestOffers = Product::with(['category', 'taxCategory', 'images', 'variants.images'])
            ->where('is_published', true)
            ->where(function ($query) use ($userType) {
                if ($userType === 'distributor') {
                    // Distributor discounts
                    $query->whereNotNull('distributor_discount_value')
                        ->where('distributor_discount_value', '>', 0);
                } else {
                    // Customer discounts (retail)
                    $query->whereNotNull('retail_discount_value')
                        ->where('retail_discount_value', '>', 0);
                }
            })
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Get wishlist IDs for authenticated user
        $userId = $request->query('user_id');
        $wishlistIds = [];
        if ($userId) {
            $wishlistIds = DB::table('wishlists')
                ->where('user_id', $userId)
                ->pluck('product_id')
                ->toArray();
        }

        // Format function for products
        $formatProducts = function ($products, $wishlistIds, $sectionType) use ($userType) {
            return $products->map(function ($product) use ($wishlistIds, $sectionType, $userType) {
                $isWishlisted = in_array($product->id, $wishlistIds);
                $isActiveDeal = $product->isActiveDealOfTheDay();

                // Calculate discount based on user type
                $discountValue = 0;
                $discountType = null;
                $discountedPrice = null;
                $originalPrice = null;

                if ($userType === 'distributor') {
                    $originalPrice = $product->distributor_mrp ?? $product->retail_mrp;
                    $discountValue = $product->distributor_discount_value ?? 0;
                    $discountType = $product->distributor_discount_type ?? null;
                    $currentPrice = $product->distributor_price ?? $product->retail_price;
                } else {
                    $originalPrice = $product->retail_mrp;
                    $discountValue = $product->retail_discount_value ?? 0;
                    $discountType = $product->retail_discount_type ?? null;
                    $currentPrice = $product->retail_price;
                }

                // Calculate discounted price if discount exists
                if ($discountValue > 0) {
                    if ($discountType === 'percentage') {
                        $discountedPrice = $originalPrice - ($originalPrice * $discountValue / 100);
                    } else if ($discountType === 'fixed') {
                        $discountedPrice = $originalPrice - $discountValue;
                    } else {
                        $discountedPrice = $currentPrice;
                    }
                    $discountedPrice = max(0, $discountedPrice);
                }

                // Get product reviews
                $averageRating = ProductReview::where('product_id', $product->id)
                    ->where('status', 'approved')
                    ->avg('rating');

                $totalReviews = ProductReview::where('product_id', $product->id)
                    ->where('status', 'approved')
                    ->count();

                // Get order count for best sellers
                $orderCount = DB::table('order_lines')
                    ->where('product_id', $product->id)
                    ->count();

                // Variants summary
                $variantsSummary = $this->getVariantsSummary($product->variants);

                // Primary image
                $primaryImage = $product->images->where('is_primary', true)->first()
                    ?? $product->images->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'product_code' => $product->product_code,
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->title,
                        'slug' => $product->category->slug,
                    ] : null,

                    // Price information based on user type
                    'original_price' => $originalPrice,
                    'current_price' => $currentPrice,
                    'discounted_price' => $discountedPrice,
                    'discount_percentage' => $discountValue > 0 && $discountType === 'percentage' ? $discountValue : ($discountValue > 0 && $originalPrice > 0 ? round(($discountValue / $originalPrice) * 100) : 0),
                    'has_discount' => $discountValue > 0,

                    'stock_quantity' => (int) $product->stock_quantity,
                    'stock_status' => $this->getProductStatus($product),
                    'is_published' => (bool) $product->is_published,
                    'is_trending' => (bool) $product->is_trending,
                    'is_wishlisted' => $isWishlisted,

                    // Section specific flags
                    'is_new_arrival' => $sectionType === 'new_arrivals',
                    'is_best_seller' => $sectionType === 'best_sellers',
                    'is_best_offer' => $sectionType === 'best_offers',

                    // For best sellers
                    'order_count' => $orderCount,

                    // Variants summary
                    'variants_summary' => $variantsSummary,

                    'reviews_summary' => [
                        'average_rating' => round($averageRating, 1),
                        'total_reviews' => $totalReviews,
                    ],

                    'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,
                ];
            })->values()->toArray();
        };

        // Format each section
        $newArrivalsFormatted = $formatProducts($newArrivals, $wishlistIds, 'new_arrivals');
        $bestSellersFormatted = $formatProducts($bestSellers, $wishlistIds, 'best_sellers');
        $bestOffersFormatted = $formatProducts($bestOffers, $wishlistIds, 'best_offers');

        return response()->json([
            'status' => 'success',
            'message' => 'Products sections retrieved successfully',
            'user_type' => $userType,
            'data' => [
                'new_arrivals' => [
                    'title' => 'New Arrivals',
                    'count' => count($newArrivalsFormatted),
                    'products' => $newArrivalsFormatted,
                ],
                'best_sellers' => [
                    'title' => 'Best Sellers',
                    'count' => count($bestSellersFormatted),
                    'products' => $bestSellersFormatted,
                ],
                'best_offers' => [
                    'title' => 'Best Offers',
                    'count' => count($bestOffersFormatted),
                    'products' => $bestOffersFormatted,
                ],
            ],
        ]);
    }

    /**
     * Generate product link (supports both product and variant)
     */
    public function generateProductLink(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Generate slug if not exists
        if (empty($product->slug)) {
            $product->slug = $this->generateSlug($product->name, $product->id);
            $product->save();
        }

        $frontendUrl = rtrim(env('FRONTEND_URL'), '/');

        // Base product URL
        $url = $frontendUrl . "/product/{$product->slug}";

        // Optional variant
        $variantId = $request->query('variant_id');

        if ($variantId) {
            $variant = ProductVariant::where('id', $variantId)
                ->where('product_id', $product->id)
                ->first();

            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variant not found for this product'
                ], 404);
            }

            $url .= '?variant_id=' . $variant->id;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $product->id,
                'variant_id' => $variantId ? (int) $variantId : null,
                'url' => $url,
            ]
        ]);
    }

    /**
     * Get product by slug (for frontend routing) with variants
     */
    public function getProductBySlug($slug)
    {
        $product = Product::where('slug', $slug)
            ->with(['category', 'images', 'taxCategory', 'variants.images'])
            ->first();
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Get variant if specified in query params
        $variantId = request()->query('variant_id');
        $selectedVariant = null;

        if ($variantId) {
            $selectedVariant = $product->variants->where('id', $variantId)->first();
        }

        // If no variant specified but product has variants, get the first active one
        if (!$selectedVariant && $product->variants->isNotEmpty()) {
            $selectedVariant = $product->variants->where('is_active', true)->first();
        }

        // Get wishlist status
        $userId = request()->query('user_id');
        $wishlistIds = [];
        if ($userId) {
            $wishlistIds = DB::table('wishlists')
                ->where('user_id', $userId)
                ->pluck('product_id')
                ->toArray();
        }

        $isWishlisted = in_array($product->id, $wishlistIds);

        $primaryImage = $product->images->where('is_primary', true)->first()
            ?? $product->images->first();

        $response = [
            'success' => true,
            'data' => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,
                'specification' => $product->specification,
                'hsn_code' => $product->hsn_code,
                'uom' => $product->uom,
                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->title,
                    'slug' => $product->category->slug,
                ] : null,
                'tax_category' => $product->taxCategory ? [
                    'id' => $product->taxCategory->id,
                    'name' => $product->taxCategory->name,
                    'rate' => $product->taxCategory->rate,
                ] : null,

                // Product level pricing
                'retail_mrp' => $product->retail_mrp,
                'retail_price' => $product->retail_price,
                'retail_discount_percentage' => $product->retail_mrp > 0
                    ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
                    : 0,

                'distributor_mrp' => $product->distributor_mrp,
                'distributor_price' => $product->distributor_price,

                'stock_quantity' => (int) $product->stock_quantity,
                'low_stock_threshold' => (int) $product->low_stock_threshold,
                'stock_status' => $this->getProductStatus($product),

                'is_published' => (bool) $product->is_published,
                'is_trending' => (bool) $product->is_trending,
                'is_wishlisted' => $isWishlisted,

                // Images
                'images' => $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => asset('storage/' . $image->image),
                        'is_primary' => (bool) $image->is_primary,
                        'sort_order' => $image->sort_order,
                    ];
                })->values()->toArray(),
                'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

                // All variants
                'variants' => $this->formatVariants($product->variants, $product->id, $wishlistIds),

                // Selected variant (if any)
                'selected_variant' => $selectedVariant ? $this->formatSingleVariant($selectedVariant) : null,
            ]
        ];

        return response()->json($response);
    }

    private function getProductAttributes($variants)
    {
        $attributes = [];

        foreach ($variants as $variant) {
            if (!empty($variant->attributes)) {
                // Decode the JSON string to an array if it's a string
                $variantAttributes = $variant->attributes;

                if (is_string($variantAttributes)) {
                    $variantAttributes = json_decode($variantAttributes, true);
                }

                // Skip if it's not an array or is empty after decoding
                if (!is_array($variantAttributes) || empty($variantAttributes)) {
                    continue;
                }

                foreach ($variantAttributes as $key => $value) {
                    if (!isset($attributes[$key])) {
                        $attributes[$key] = [];
                    }

                    if (!in_array($value, $attributes[$key])) {
                        $attributes[$key][] = $value;
                    }
                }
            }
        }

        return $attributes;
    }

    /**
     * Format a single variant
     */
    protected function formatSingleVariant($variant)
    {
        $primaryImage = $variant->images->where('is_primary', true)->first()
            ?? $variant->images->first();

        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'attributes' => $variant->attributes,
            // 'attribute_string' => $variant->attribute_string ?? $this->getAttributeString($variant->attributes),

            'retail_mrp' => $variant->retail_mrp,
            'retail_price' => $variant->retail_price,
            'retail_discount_type' => $variant->retail_discount_type,
            'retail_discount_value' => $variant->retail_discount_value,
            'retail_discount_amount' => $variant->retail_mrp - $variant->retail_price,
            'retail_discount_percentage' => $variant->retail_mrp > 0
                ? round((($variant->retail_mrp - $variant->retail_price) / $variant->retail_mrp) * 100, 2)
                : 0,

            'distributor_mrp' => $variant->distributor_mrp,
            'distributor_price' => $variant->distributor_price,
            'distributor_discount_type' => $variant->distributor_discount_type,
            'distributor_discount_value' => $variant->distributor_discount_value,
            'distributor_discount_amount' => $variant->distributor_mrp && $variant->distributor_price
                ? $variant->distributor_mrp - $variant->distributor_price
                : null,
            'distributor_discount_percentage' => $variant->distributor_mrp && $variant->distributor_price && $variant->distributor_mrp > 0
                ? round((($variant->distributor_mrp - $variant->distributor_price) / $variant->distributor_mrp) * 100, 2)
                : null,

            'stock_quantity' => (int) $variant->stock_quantity,
            'low_stock_threshold' => (int) $variant->low_stock_threshold,
            'stock_status' => $this->getVariantStatus($variant),
            'sort_order' => (int) $variant->sort_order,
            'is_active' => (bool) $variant->is_active,

            'images' => $variant->images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'image' => $image->image,
                    'image_url' => asset('storage/' . $image->image),
                    'sort_order' => $image->sort_order,
                    'is_primary' => (bool) $image->is_primary,
                ];
            })->values()->toArray(),
            'primary_image' => $primaryImage ? $primaryImage->image : null,
            'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,

            'created_at' => $variant->created_at?->toISOString(),
            'updated_at' => $variant->updated_at?->toISOString(),
        ];
    }


    /**
     * Generate URL-friendly slug
     */
    private function generateSlug($name, $id = null)
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);

        if ($id) {
            $slug = $slug . '-' . $id;
        }

        return $slug;
    }

    /**
     * ============================================================
     * OUTBOUND API METHODS
     * For external systems (Commission System)
     * ============================================================
     */

    /**
     * Get products for external systems.
     * Supports pagination, last_sync marker, and filters.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function externalIndex(Request $request)
    {
        $perPage = (int) $request->input('per_page', 50);
        $perPage = min($perPage, 100);

        $lastSync = $request->input('last_sync');

        $query = Product::with(['category', 'taxCategory', 'images'])
            ->where('is_published', true);

        // If last_sync provided, only get updated after that time
        if ($lastSync) {
            $query->where('updated_at', '>', $lastSync);
        }

        $products = $query->orderBy('updated_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $products->items()->map(function ($product) {
                return [
                    'id' => $product->id,
                    'product_code' => $product->product_code,
                    'hsn_code' => $product->hsn_code,
                    'name' => $product->name,
                    'description' => $product->description,
                    'specification' => $product->specification,
                    'category' => [
                        'id' => $product->category->id ?? null,
                        'name' => $product->category->title ?? null,
                    ],
                    'tax_category' => [
                        'id' => $product->taxCategory->id ?? null,
                        'name' => $product->taxCategory->name ?? null,
                        'rate' => (float) ($product->taxCategory->rate ?? 0),
                    ],
                    'pricing' => [
                        'retail_price' => (float) $product->retail_price,
                        'retail_mrp' => (float) $product->retail_mrp,
                        'retail_discount_type' => $product->retail_discount_type,
                        'retail_discount_value' => (float) $product->retail_discount_value,
                        'distributor_price' => (float) $product->distributor_price,
                        'distributor_mrp' => (float) $product->distributor_mrp,
                        'distributor_discount_type' => $product->distributor_discount_type,
                        'distributor_discount_value' => (float) $product->distributor_discount_value,
                    ],
                    'stock' => [
                        'quantity' => $product->stock_quantity,
                        'low_stock_threshold' => $product->low_stock_threshold,
                    ],
                    'images' => $product->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'url' => asset('storage/' . $image->image),
                            'is_primary' => (bool) $image->is_primary,
                            'sort_order' => $image->sort_order,
                        ];
                    }),
                    'status' => [
                        'is_published' => (bool) $product->is_published,
                        'is_trending' => (bool) $product->is_trending,
                        'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
                        'deal_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
                        'deal_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),
                    ],
                    'created_at' => $product->created_at->toISOString(),
                    'updated_at' => $product->updated_at->toISOString(),
                ];
            }),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'last_sync_marker' => $products->items()->isNotEmpty()
                    ? $products->items()->first()->updated_at->toISOString()
                    : null,
            ],
        ]);
    }

    /**
     * Get single product by ID or product_code for external systems.
     *
     * @param Request $request
     * @param string $identifier
     * @return JsonResponse
     */
    public function externalShow(Request $request, string $identifier)
    {
        $product = Product::with(['category', 'taxCategory', 'images'])
            ->where(function ($query) use ($identifier) {
                $query->where('id', $identifier)
                    ->orWhere('product_code', $identifier);
            })
            ->where('is_published', true)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    public function markAsDealOfTheDay(Request $request, $id)
    {
        $request->validate([
            'sale_type'    => 'required|string',
        ]);
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $product->update([
                'is_deal_of_the_day' => true,
                'deal_of_the_day_starts_at' => $request->starts_at ?? now(),
                'deal_of_the_day_ends_at' => $request->ends_at ?? now()->addHours(24),
                'sale_type' => $request->sale_type,
            ]);

            DB::commit();
            $product->load(['category', 'taxCategory', 'images']);

            return response()->json([
                'message' => 'Product marked as deal of the day successfully',
                'product' => $this->formatProduct($product)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to mark product as deal of the day:', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Failed to mark product as deal of the day',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getDealOfTheDayProducts($wishlistIds = [])
    {
        // 1. Admin-selected active Deal of the Day products
        $adminDeals = Product::with(['category', 'taxCategory', 'images', 'variants.images'])
            ->where('is_deal_of_the_day', true)
            ->where('sale_type', 'today_best')
            ->where('is_published', true)
            ->where(function ($query) {
                $query->whereNull('deal_of_the_day_starts_at')
                    ->orWhere('deal_of_the_day_starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('deal_of_the_day_ends_at')
                    ->orWhere('deal_of_the_day_ends_at', '>=', now());
            })
            ->orderBy('trending_sort_order')
            ->get();

        // Already selected active product IDs
        $selectedIds = $adminDeals->pluck('id')->toArray();

        // 2. If less than 2, get default products
        $required = 2 - $adminDeals->count();

        if ($required > 0) {
            $defaultProducts = Product::with(['category', 'taxCategory', 'images', 'variants.images'])
                ->where('is_published', true)
                ->where('stock_quantity', '>', 0)
                ->whereNotIn('id', $selectedIds)
                ->get()
                ->map(function ($product) {
                    $mrp = (float) $product->retail_mrp;
                    $discountValue = (float) $product->retail_discount_value;

                    if ($product->retail_discount_type === 'percentage') {
                        $discountAmount = ($mrp * $discountValue) / 100;
                    } elseif ($product->retail_discount_type === 'fixed') {
                        $discountAmount = $discountValue;
                    } else {
                        $discountAmount = 0;
                    }

                    $product->calculated_discount_amount = $discountAmount;

                    return $product;
                })
                ->sortByDesc('calculated_discount_amount')
                ->take($required)
                ->values();

            $adminDeals = $adminDeals->concat($defaultProducts);
        }

        // 3. If still less than 2, fill from products
        if ($adminDeals->count() < 2) {
            $remaining = 2 - $adminDeals->count();

            $fallbackProducts = Product::with(['category', 'taxCategory', 'images', 'variants.images'])
                ->where('is_published', true)
                ->where('stock_quantity', '>', 0)
                ->whereNotIn('id', $adminDeals->pluck('id')->toArray())
                ->latest()
                ->limit($remaining)
                ->get();

            $adminDeals = $adminDeals->concat($fallbackProducts);
        }

        return $adminDeals
            ->take(2)
            ->map(function ($product) use ($wishlistIds) {
                // Get product image or fallback to variant image
                $primaryImage = $product->images->where('is_primary', true)->first()
                    ?? $product->images->first();

                // If no product image, get from first variant
                if (!$primaryImage) {
                    $firstVariant = $product->variants->first();
                    if ($firstVariant) {
                        $variantImage = $firstVariant->images->where('is_primary', true)->first()
                            ?? $firstVariant->images->first();
                        if ($variantImage) {
                            $primaryImage = (object) [
                                'image' => $variantImage->image,
                                'is_primary' => $variantImage->is_primary,
                                'sort_order' => $variantImage->sort_order,
                            ];
                        }
                    }
                }

                $isActiveDeal = $product->isActiveDealOfTheDay();

                return [
                    'id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,

                    // Category details
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->title,
                        'slug' => $product->category->slug,
                        'description' => $product->category->description,
                    ] : null,

                    // Deal status
                    'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
                    'is_active_deal' => $isActiveDeal,
                    'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
                    'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),

                    // Retail pricing
                    'retail_mrp' => $product->retail_mrp,
                    'retail_price' => $product->retail_price,
                    'retail_discount_type' => $product->retail_discount_type,
                    'retail_discount_value' => $product->retail_discount_value,
                    'retail_discount_amount' => $product->retail_mrp - $product->retail_price,
                    'retail_discount_percentage' => $product->retail_mrp > 0
                        ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
                        : 0,

                    // Distributor pricing
                    'distributor_mrp' => $product->distributor_mrp,
                    'distributor_price' => $product->distributor_price,
                    'distributor_discount_type' => $product->distributor_discount_type,
                    'distributor_discount_value' => $product->distributor_discount_value,
                    'distributor_discount_amount' => $product->distributor_mrp && $product->distributor_price
                        ? $product->distributor_mrp - $product->distributor_price
                        : null,
                    'distributor_discount_percentage' => $product->distributor_mrp && $product->distributor_price && $product->distributor_mrp > 0
                        ? round((($product->distributor_mrp - $product->distributor_price) / $product->distributor_mrp) * 100, 2)
                        : null,

                    // Stock and status
                    'stock_quantity' => (int) $product->stock_quantity,
                    'stock_status' => $this->getProductStatus($product),
                    'is_published' => (bool) $product->is_published,

                    // Image (product or variant fallback)
                    'image' => $primaryImage ? [
                        'id' => $primaryImage->id ?? null,
                        'image_url' => asset('storage/' . $primaryImage->image),
                        'is_primary' => (bool) ($primaryImage->is_primary ?? false),
                    ] : null,
                    'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,
                ];
            })
            ->values()
            ->toArray();
    }

    public function removeDealOfTheDay($id)
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        DB::beginTransaction();
        try {
            $product->update([
                'is_deal_of_the_day' => false,
                'deal_of_the_day_starts_at' => null,
                'deal_of_the_day_ends_at' => null,
            ]);

            DB::commit();
            $product->load(['category', 'taxCategory', 'images']);

            return response()->json([
                'message' => 'Product removed from deal of the day successfully',
                'product' => $this->formatProduct($product)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to remove product from deal of the day:', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Failed to remove product from deal of the day',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getTopDiscountedProducts(Request $request)
    {
        $limit = $request->get('limit', 10);
        $type = $request->get('type', 'all');
        $now = now();

        $products = Product::with(['category', 'images', 'variants.images'])
            ->where('is_published', true)
            ->where('sale_type', 'limited')
            ->where(function ($query) use ($now) {
                $query->where(function ($q) use ($now) {
                    $q->whereNull('deal_of_the_day_starts_at')
                        ->orWhere('deal_of_the_day_starts_at', '<=', $now);
                })->where(function ($q) use ($now) {
                    $q->whereNull('deal_of_the_day_ends_at')
                        ->orWhere('deal_of_the_day_ends_at', '>=', $now);
                });
            })
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('retail_mrp', '>', 0)
                        ->whereColumn('retail_price', '<', 'retail_mrp');
                })->orWhere(function ($q) {
                    $q->where('distributor_mrp', '>', 0)
                        ->whereColumn('distributor_price', '<', 'distributor_mrp');
                });
            })
            ->get();

        // Calculate discount percentages and sort
        $productsWithDiscounts = $products->map(function ($product) use ($type) {
            $retailDiscount = $this->calculateDiscountPercentage($product, 'retail');
            $distributorDiscount = $this->calculateDiscountPercentage($product, 'distributor');

            $maxDiscount = max($retailDiscount, $distributorDiscount);

            $discountType = 'none';
            if ($retailDiscount > 0 && $distributorDiscount > 0) {
                $discountType = 'both';
            } elseif ($retailDiscount > 0) {
                $discountType = 'retail';
            } elseif ($distributorDiscount > 0) {
                $discountType = 'distributor';
            }

            return [
                'product' => $product,
                'retail_discount' => $retailDiscount,
                'distributor_discount' => $distributorDiscount,
                'max_discount' => $maxDiscount,
                'discount_type' => $discountType,
            ];
        });

        // Filter by type
        if ($type === 'retail') {
            $productsWithDiscounts = $productsWithDiscounts->filter(function ($item) {
                return $item['retail_discount'] > 0;
            });
        } elseif ($type === 'distributor') {
            $productsWithDiscounts = $productsWithDiscounts->filter(function ($item) {
                return $item['distributor_discount'] > 0;
            });
        }

        // Sort and limit
        $productsWithDiscounts = $productsWithDiscounts->sortByDesc('max_discount')
            ->values()
            ->take($limit);

        // Format response
        $formattedProducts = $productsWithDiscounts->map(function ($item) {
            $product = $item['product'];

            // Get image URL with fallback to variant
            $imageUrl = null;
            $primaryImage = $product->images->where('is_primary', true)->first()
                ?? $product->images->first();

            if ($primaryImage) {
                $imageUrl = asset('storage/' . $primaryImage->image);
            } else {
                $firstVariant = $product->variants->first();
                if ($firstVariant) {
                    $variantImage = $firstVariant->images->where('is_primary', true)->first()
                        ?? $firstVariant->images->first();
                    if ($variantImage) {
                        $imageUrl = asset('storage/' . $variantImage->image);
                    }
                }
            }

            return [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'slug' => $product->slug,

                'category' => $product->category ? [
                    'id' => $product->category->id,
                    'name' => $product->category->title,
                ] : null,

                'is_deal_of_the_day' => true,
                'is_active_deal' => $product->isActiveDealOfTheDay(),
                'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
                'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),

                'retail_mrp' => $product->retail_mrp,
                'retail_price' => $product->retail_price,
                'retail_discount_percentage' => $item['retail_discount'],
                'retail_discount_amount' => (float) $product->retail_mrp - (float) $product->retail_price,

                'distributor_mrp' => $product->distributor_mrp,
                'distributor_price' => $product->distributor_price,
                'distributor_discount_percentage' => $item['distributor_discount'],
                'distributor_discount_amount' => $product->distributor_mrp && $product->distributor_price
                    ? (float) $product->distributor_mrp - (float) $product->distributor_price
                    : 0,

                'image_url' => $imageUrl,
            ];
        });

        return response()->json([
            'data' => $formattedProducts,
            'meta' => [
                'total' => $formattedProducts->count(),
                'limit' => $limit,
                'type' => $type,
            ]
        ]);
    }

    protected function calculateDiscountPercentage($product, $type = 'retail')
    {
        if ($type === 'retail') {
            if ($product->retail_mrp > 0 && $product->retail_price < $product->retail_mrp) {
                return round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2);
            }
            return 0;
        } elseif ($type === 'distributor') {
            if ($product->distributor_mrp > 0 && $product->distributor_price < $product->distributor_mrp) {
                return round((($product->distributor_mrp - $product->distributor_price) / $product->distributor_mrp) * 100, 2);
            }
            return 0;
        }
        return 0;
    }

    public function updateStock(Request $request)
    {
        try {
            // ============ VALIDATION ============
            $validator = \Illuminate\Support\Facades\Validator::make(
                $request->all(),
                [
                    // Product ID is required
                    'product_id' => 'required|exists:products,id',

                    // Variants array (optional - for products with variants)
                    'variants' => 'nullable|array',
                    'variants.*.id' => 'required_with:variants|exists:product_variants,id',
                    'variants.*.stock_quantity' => 'required_with:variants|integer|min:0',

                    // Direct stock update (for products without variants)
                    'stock_quantity' => 'nullable|integer|min:0|required_without:variants',

                    // Operation type (set, add, subtract)
                    'operation' => 'nullable|in:set,add,subtract|required_with:variants',
                ],
                [
                    'product_id.required' => 'Product ID is required',
                    'product_id.exists' => 'Product not found',
                    'variants.*.id.exists' => 'One or more variants not found',
                    'variants.*.stock_quantity.min' => 'Variant stock cannot be negative',
                    'stock_quantity.min' => 'Stock quantity cannot be negative',
                    'stock_quantity.required_without' => 'Stock quantity is required when variants are not provided',
                    'operation.required_with' => 'Operation is required when updating variants',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $productId = $validated['product_id'];

            // ============ GET PRODUCT VARIANTS ============
            $variants = ProductVariant::where('product_id', $productId)
                ->whereNull('deleted_at')
                ->get();

            DB::beginTransaction();

            // ============ CASE 1: PRODUCT HAS NO VARIANTS ============
            if ($variants->isEmpty()) {
                // Update product stock directly
                if (!isset($validated['stock_quantity'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Stock quantity is required for products without variants'
                    ], 400);
                }

                $product = Product::findOrFail($productId);
                $oldStock = $product->stock_quantity;
                $newStock = $validated['stock_quantity'];

                // Apply operation if provided
                if (isset($validated['operation'])) {
                    switch ($validated['operation']) {
                        case 'add':
                            $newStock = $oldStock + $validated['stock_quantity'];
                            break;
                        case 'subtract':
                            $newStock = $oldStock - $validated['stock_quantity'];
                            break;
                        case 'set':
                        default:
                            $newStock = $validated['stock_quantity'];
                            break;
                    }
                }

                // Validate negative stock
                if ($newStock < 0) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Stock cannot be negative. Current: {$oldStock}, New: {$newStock}"
                    ], 400);
                }

                $product->stock_quantity = $newStock;
                $product->save();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Product stock updated successfully',
                    'data' => [
                        'product' => [
                            'id' => $product->id,
                            'name' => $product->name,
                            'old_stock' => $oldStock,
                            'new_stock' => $newStock,
                            'operation' => $validated['operation'] ?? 'set',
                            'has_variants' => false,
                        ]
                    ],
                    'timestamp' => now()->toISOString()
                ]);
            }

            // ============ CASE 2: PRODUCT HAS VARIANTS ============

            // Check if variants array is provided
            if (!isset($validated['variants']) || empty($validated['variants'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Variants data is required for products with variants'
                ], 400);
            }

            // Check operation
            if (!isset($validated['operation'])) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Operation is required for updating variants'
                ], 400);
            }

            $operation = $validated['operation'];
            $variantsData = $validated['variants'];
            $updatedVariants = [];
            $totalVariants = count($variantsData);

            // Update each variant
            foreach ($variantsData as $variantData) {
                $variant = ProductVariant::find($variantData['id']);

                // Verify variant belongs to the product
                if ($variant->product_id != $productId) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Variant ID {$variantData['id']} does not belong to product ID {$productId}"
                    ], 400);
                }

                $oldStock = $variant->stock_quantity;
                $quantity = $variantData['stock_quantity'];

                // Apply operation
                switch ($operation) {
                    case 'set':
                        $newStock = $quantity;
                        break;
                    case 'add':
                        $newStock = $oldStock + $quantity;
                        break;
                    case 'subtract':
                        $newStock = $oldStock - $quantity;
                        break;
                    default:
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid operation type'
                        ], 400);
                }

                // Validate stock is not negative
                if ($newStock < 0) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Stock cannot be negative for variant ID {$variantData['id']}. Current: {$oldStock}, Operation: {$operation}, Quantity: {$quantity}"
                    ], 400);
                }

                $variant->stock_quantity = $newStock;
                $variant->save();

                $updatedVariants[] = [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'attributes' => $variant->attributes,
                    'old_stock' => $oldStock,
                    'operation' => $operation,
                    'quantity' => $quantity,
                    'new_stock' => $newStock,
                ];
            }

            // Update parent product total stock
            $totalStock = $this->updateProductStock($productId);
            $product = Product::find($productId);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Variant stocks updated successfully',
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'total_stock' => $totalStock,
                        'has_variants' => true,
                    ],
                    'total_updated' => $totalVariants,
                    'operation' => $operation,
                    'updated_variants' => $updatedVariants,
                ],
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Stock update failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    private function updateProductStock($productId): int
    {
        $product = Product::findOrFail($productId);

        $totalStock = ProductVariant::where('product_id', $productId)
            ->whereNull('deleted_at')
            ->sum('stock_quantity');

        $product->stock_quantity = $totalStock;
        $product->save();

        return $totalStock;
    }

    private function bulkUpdateVariantsWithOperation(array $variantsData, string $operation): array
    {
        $updatedVariants = [];
        $productIds = [];
        $totalVariants = count($variantsData);

        foreach ($variantsData as $index => $variantData) {
            $variant = ProductVariant::find($variantData['id']);
            if (!$variant) {
                throw new \Exception("Variant with ID {$variantData['id']} not found");
            }

            $oldStock = $variant->stock_quantity;
            $quantity = $variantData['stock_quantity'];

            // Apply operation
            switch ($operation) {
                case 'set':
                    $newStock = $quantity;
                    break;
                case 'add':
                    $newStock = $oldStock + $quantity;
                    break;
                case 'subtract':
                    $newStock = $oldStock - $quantity;
                    break;
                default:
                    throw new \Exception('Invalid operation type');
            }

            // Validate stock is not negative
            if ($newStock < 0) {
                throw new \Exception("Stock cannot be negative for variant {$variantData['id']}. Current: {$oldStock}, Operation: {$operation}, Quantity: {$quantity}");
            }

            $variant->stock_quantity = $newStock;
            $variant->save();

            $updatedVariants[] = [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'attributes' => $variant->attributes,
                'old_stock' => $oldStock,
                'operation' => $operation,
                'quantity' => $quantity,
                'new_stock' => $newStock,
            ];

            $productIds[] = $variant->product_id;
        }

        // Update all affected products
        $productIds = array_unique($productIds);
        $updatedProducts = [];

        foreach ($productIds as $productId) {
            $product = Product::find($productId);
            if ($product) {
                $totalStock = $this->updateProductStock($productId);
                $updatedProducts[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'total_stock' => $totalStock,
                ];
            }
        }

        return [
            'total_updated' => $totalVariants,
            'operation' => $operation,
            'updated_variants' => $updatedVariants,
            'updated_products' => $updatedProducts,
        ];
    }

    private function bulkUpdateVariants(array $variantsData): array
    {
        $updatedVariants = [];
        $productIds = [];
        $totalVariants = count($variantsData);

        foreach ($variantsData as $index => $variantData) {
            $variant = ProductVariant::find($variantData['id']);
            if (!$variant) {
                throw new \Exception("Variant with ID {$variantData['id']} not found");
            }

            // Validate stock is not negative
            if ($variantData['stock_quantity'] < 0) {
                throw new \Exception("Stock cannot be negative for variant {$variantData['id']}");
            }

            $oldStock = $variant->stock_quantity;
            $variant->stock_quantity = $variantData['stock_quantity'];
            $variant->save();

            $updatedVariants[] = [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'attributes' => $variant->attributes,
                'old_stock' => $oldStock,
                'new_stock' => $variant->stock_quantity,
            ];

            $productIds[] = $variant->product_id;
        }

        // Update all affected products
        $productIds = array_unique($productIds);
        $updatedProducts = [];

        foreach ($productIds as $productId) {
            $product = Product::find($productId);
            if ($product) {
                $totalStock = $this->updateProductStock($productId);
                $updatedProducts[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'total_stock' => $totalStock,
                ];
            }
        }

        return [
            'total_updated' => $totalVariants,
            'updated_variants' => $updatedVariants,
            'updated_products' => $updatedProducts,
        ];
    }

    private function getStockSummary($productId)
    {
        try {
            $product = Product::with(['variants' => function ($query) {
                $query->whereNull('deleted_at');
            }])->findOrFail($productId);

            $variantStock = $product->variants->map(function ($variant) {
                return [
                    'variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'attributes' => $variant->attributes,
                    'stock_quantity' => $variant->stock_quantity,
                    'low_stock_threshold' => $variant->low_stock_threshold,
                    'is_low_stock' => $variant->stock_quantity <= ($variant->low_stock_threshold ?? 0),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'product_code' => $product->product_code,
                        'total_stock' => $product->stock_quantity,
                        'low_stock_threshold' => $product->low_stock_threshold,
                        'is_low_stock' => $product->stock_quantity <= ($product->low_stock_threshold ?? 0),
                        'total_variants' => $product->variants->count(),
                        'variants' => $variantStock,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get stock summary: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get stock summary',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    private function adjustVariantStock($variantId, $adjustment): array
    {
        $variant = ProductVariant::findOrFail($variantId);

        $oldStock = $variant->stock_quantity;
        $newStock = $oldStock + $adjustment;

        // Validate stock is not negative
        if ($newStock < 0) {
            throw new \Exception('Stock cannot be negative. Current stock: ' . $oldStock . ', Adjustment: ' . $adjustment);
        }

        $variant->stock_quantity = $newStock;
        $variant->save();

        // Update parent product
        $product = $variant->product;
        $productTotalStock = $this->updateProductStock($product->id);

        return [
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'attributes' => $variant->attributes,
                'old_stock' => $oldStock,
                'adjustment' => $adjustment,
                'new_stock' => $newStock,
            ],
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'total_stock' => $productTotalStock,
            ]
        ];
    }

    private function successResponse(string $message, array $data)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->toISOString()
        ]);
    }

    // private function updateProductStock($productId): int
    // {
    //     $product = Product::findOrFail($productId);

    //     $totalStock = ProductVariant::where('product_id', $productId)
    //         ->whereNull('deleted_at')
    //         ->sum('stock_quantity');

    //     $product->stock_quantity = $totalStock;
    //     $product->save();

    //     return $totalStock;
    // }

    private function updateSingleVariant($variantId, $stockQuantity)
    {
        $variant = ProductVariant::findOrFail($variantId);

        // Validate stock is not negative
        if ($stockQuantity < 0) {
            throw new \Exception('Stock quantity cannot be negative');
        }

        $variant->stock_quantity = $stockQuantity;
        $variant->save();

        // Update parent product
        $product = $variant->product;
        $productTotalStock = $this->updateProductStock($product->id);

        return [
            'variant' => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'attributes' => $variant->attributes,
                'old_stock' => $variant->getOriginal('stock_quantity'),
                'new_stock' => $variant->stock_quantity,
            ],
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'total_stock' => $productTotalStock,
            ]
        ];
    }

    public function globalSearch(Request $request)
    {
        $request->validate([
            'search' => 'required|string|min:2',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $searchTerm = $request->search;
        $perPage = $request->per_page ?? 20;

        // Search Products with image from product_images table
        $products = Product::where('name', 'LIKE', "%{$searchTerm}%")
            ->orWhere('slug', 'LIKE', "%{$searchTerm}%")
            ->orWhere('description', 'LIKE', "%{$searchTerm}%")
            ->orWhere('product_code', 'LIKE', "%{$searchTerm}%")
            ->orWhere('hsn_code', 'LIKE', "%{$searchTerm}%")
            ->select('id', 'name')
            ->with(['images' => function ($query) {
                $query->where('is_primary', true)
                    ->select('product_id', 'image');
            }])
            ->paginate($perPage, ['*'], 'products_page');

        // Transform products to include full image URL
        $products->getCollection()->transform(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'image' => $product->images->first()
                    ? url('/storage/' . $product->images->first()->image)
                    : null,
            ];
        });

        // Search Admins
        $admins = Admin::where('name', 'LIKE', "%{$searchTerm}%")
            ->orWhere('email', 'LIKE', "%{$searchTerm}%")
            ->select('id', 'name', 'email', 'profile_image')
            ->paginate($perPage, ['*'], 'admins_page');

        // Transform admins to include full image URL
        $admins->getCollection()->transform(function ($admin) {
            return [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'profile_image' => $admin->profile_image
                    ? url('storage/' . $admin->profile_image)
                    : null,
            ];
        });

        // Search Users
        $users = User::where('full_name', 'LIKE', "%{$searchTerm}%")
            ->orWhere('email', 'LIKE', "%{$searchTerm}%")
            ->orWhere('phone', 'LIKE', "%{$searchTerm}%")
            ->where('is_registered', true)
            ->select('id', 'full_name as name', 'email', 'profile_picture')
            ->paginate($perPage, ['*'], 'users_page');

        // Transform users to include full image URL
        $users->getCollection()->transform(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_picture' => $user->profile_picture
                    ? url('storage/' . $user->profile_picture)
                    : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products->items(),
                'admins' => $admins->items(),
                'users' => $users->items(),
                'total_results' => $products->total() + $admins->total() + $users->total()
            ]
        ]);
    }
}
