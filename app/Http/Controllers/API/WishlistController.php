<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class WishlistController extends Controller
{
    /**
     * Get user's wishlist
     */
    // public function index(Request $request)
    // {
    //     $user = Auth::user();

    //     $wishlist = Wishlist::with([
    //         'product' => function ($query) {
    //             $query->with([
    //                 'category',
    //                 'taxCategory',
    //                 'images'
    //             ]);
    //         }
    //     ])
    //         ->where('user_id', $user->id)
    //         ->orderBy('created_at', 'desc')
    //         ->get();

    //     $formattedWishlist = $wishlist->map(function ($item) {

    //         $product = $item->product;

    //         if ($product) {
    //             return [
    //                 'id' => $item->id,
    //                 'product_id' => $product->id,
    //                 'product' => $this->formatProductWithWishlist($product, true),
    //                 'added_at' => $item->created_at?->toISOString(),
    //             ];
    //         }

    //         return null;
    //     })->filter()->values();

    //     return response()->json([
    //         'data' => $formattedWishlist,
    //         'total' => $formattedWishlist->count()
    //     ]);
    // }
    protected function buildComboChildrenMap($products): array
    {
        // 1) Collect all unique child IDs across every combo product on this page
        $childIdsByParent = [];
        $allChildIds      = [];

        foreach ($products as $product) {
            if (($product->product_type ?? 'single') !== 'combo') {
                continue;
            }

            $groups = \App\Models\Product::normalizeComboGroups($product->parent_combo_id);

            // Flatten all groups -> unique IDs, preserve order
            $ids = collect($groups)
                ->flatten()
                ->map(fn($v) => (int) $v)
                ->unique()
                ->values()
                ->all();

            if (empty($ids)) {
                $childIdsByParent[$product->id] = [];
                continue;
            }

            $childIdsByParent[$product->id] = $ids;
            $allChildIds = array_merge($allChildIds, $ids);
        }

        $allChildIds = array_values(array_unique($allChildIds));

        if (empty($allChildIds)) {
            return [];
        }

        // 2) Fetch all children in one query with needed relations
        $children = \App\Models\Product::query()
            ->whereIn('id', $allChildIds)
            ->whereNull('deleted_at')
            ->with([
                'category:id,title,slug',
                'subcategory:id,category_id,name,slug',
                'brand:id,title,logo,banner',
                'taxCategory:id,name,rate',
                'images',
            ])
            ->get()
            ->keyBy('id');

        // 3) Build the payload per parent, preserving child order from parent_combo_id
        $map = [];

        foreach ($childIdsByParent as $parentId => $childIds) {
            $payload = [];

            foreach ($childIds as $cid) {
                $child = $children->get($cid);
                if (!$child) {
                    continue;
                }

                $images = $child->images
                    ->sortBy(fn($img) => $img->is_primary ? 0 : 1)  // primary first
                    ->take(2)
                    ->map(fn($img) => [
                        'id'         => $img->id,
                        'image_url'  => asset('storage/' . $img->image),
                        'is_primary' => (bool) $img->is_primary,
                        'sort_order' => $img->sort_order,
                    ])
                    ->values()
                    ->all();

                $payload[] = [
                    'id'                    => $child->id,
                    'product_code'          => $child->product_code,
                    'name'                  => $child->name,
                    'slug'                  => $child->slug,
                    'is_mandatory'          => $child->is_mandatory ?? null,
                    'commission_value'      => $child->commission_value ?? null,

                    // both prices
                    'retail_price'          => $child->retail_price,
                    'retail_mrp'            => $child->retail_mrp,
                    'distributor_price'     => $child->distributor_price,
                    'distributor_mrp'       => $child->distributor_mrp,

                    // up to 2 images (primary first)
                    'images'                => $images,

                    // taxonomy / brand
                    'category'              => $child->category ? [
                        'id'   => $child->category->id,
                        'name' => $child->category->title,
                        'slug' => $child->category->slug,
                    ] : null,

                    'subcategory'           => $child->subcategory ? [
                        'id'          => $child->subcategory->id,
                        'category_id' => $child->subcategory->category_id,
                        'name'        => $child->subcategory->name,
                        'slug'        => $child->subcategory->slug,
                    ] : null,

                    'brand'                 => $child->brand ? [
                        'id'     => $child->brand->id,
                        'name'   => $child->brand->title,
                        'logo'   => $child->brand->logo
                            ? asset('storage/' . $child->brand->logo)
                            : null,
                        'banner' => $child->brand->banner
                            ? asset('storage/' . $child->brand->banner)
                            : null,
                    ] : null,

                    'tax_category'          => $child->taxCategory ? [
                        'id'   => $child->taxCategory->id,
                        'name' => $child->taxCategory->name,
                        'rate' => $child->taxCategory->rate,
                    ] : null,
                ];
            }

            $map[$parentId] = $payload;
        }

        return $map;
    }
    public function index(Request $request)
    {
        $user = Auth::user();

        $wishlist = Wishlist::with([
            'product' => function ($query) {
                $query->with([
                    'category',
                    'taxCategory',
                    'images'
                ]);
            }
        ])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // ============================================================
        // COMBO CHILDREN (batch across the whole wishlist)   NEW
        // ============================================================
        $wishlistProducts = $wishlist
            ->pluck('product')
            ->filter()
            ->unique('id')
            ->values();

        $comboChildren = $this->buildComboChildrenMap($wishlistProducts);

        $formattedWishlist = $wishlist->map(function ($item) use ($comboChildren) {

            $product = $item->product;

            if ($product) {
                $formatted = $this->formatProductWithWishlist($product, true);

                // Override with batched data to avoid per-product query
                if ($this->isComboProduct($product)) {
                    $formatted['product_type']    = 'combo';
                    $formatted['combo_products']  = $comboChildren[$product->id] ?? [];
                } else {
                    $formatted['product_type']    = 'single';
                    $formatted['combo_products']  = [];
                }

                return [
                    'id' => $item->id,
                    'product_id' => $product->id,
                    'product' => $formatted,
                    'added_at' => $item->created_at?->toISOString(),
                ];
            }

            return null;
        })->filter()->values();

        return response()->json([
            'data' => $formattedWishlist,
            'total' => $formattedWishlist->count()
        ]);
    }

    /**
     * Whether this product should be treated as a combo.
     * Uses product_type as the source of truth, with a defensive
     * fallback to a non-empty parent_combo_id (for legacy rows).
     */
    protected function isComboProduct($product): bool
    {
        if (($product->product_type ?? null) === 'combo') {
            return true;
        }

        $groups = \App\Models\Product::normalizeComboGroups($product->parent_combo_id ?? null);

        return !empty(collect($groups)->flatten()->filter()->all());
    }

    /**
     * Load and format the combo children of a single combo product.
     * Returns the same shape as buildComboChildrenMap() so index & show match.
     */
    protected function formatComboChildren($product): array
    {
        $groups = \App\Models\Product::normalizeComboGroups($product->parent_combo_id ?? null);

        $childIds = collect($groups)
            ->flatten()
            ->map(fn($v) => (int) $v)
            ->filter(fn($id) => $id > 0 && $id !== (int) $product->id)
            ->unique()
            ->values()
            ->all();

        if (empty($childIds)) {
            return [];
        }

        $children = \App\Models\Product::query()
            ->whereIn('id', $childIds)
            ->whereNull('deleted_at')
            ->with([
                'category:id,title,slug',
                'subcategory:id,category_id,name,slug',
                'brand:id,title,logo,banner',
                'taxCategory:id,name,rate',
                'images',
            ])
            ->get()
            ->keyBy('id');

        $payload = [];

        foreach ($childIds as $cid) {
            $child = $children->get($cid);
            if (!$child) {
                continue;
            }

            $images = $child->images
                ->sortBy(fn($img) => $img->is_primary ? 0 : 1)
                ->take(2)
                ->map(fn($img) => [
                    'id'         => $img->id,
                    'image_url'  => asset('storage/' . ltrim($img->image, '/')),
                    'is_primary' => (bool) $img->is_primary,
                    'sort_order' => (int) $img->sort_order,
                ])
                ->values()
                ->all();

            $payload[] = [
                'id'               => $child->id,
                'product_code'     => $child->product_code,
                'name'             => $child->name,
                'slug'             => $child->slug,
                'is_mandatory'     => $child->is_mandatory ?? null,
                'commission_value' => $child->commission_value ?? null,

                // both prices
                'retail_price'      => $child->retail_price,
                'retail_mrp'        => $child->retail_mrp,
                'distributor_price' => $child->distributor_price,
                'distributor_mrp'   => $child->distributor_mrp,

                // up to 2 images (primary first)
                'images'            => $images,

                'category'          => $child->category ? [
                    'id'   => $child->category->id,
                    'name' => $child->category->title,
                    'slug' => $child->category->slug,
                ] : null,

                'subcategory'       => $child->subcategory ? [
                    'id'          => $child->subcategory->id,
                    'category_id' => $child->subcategory->category_id,
                    'name'        => $child->subcategory->name,
                    'slug'        => $child->subcategory->slug,
                ] : null,

                'brand'             => $child->brand ? [
                    'id'     => $child->brand->id,
                    'name'   => $child->brand->title,
                    'logo'   => $child->brand->logo
                        ? asset('storage/' . ltrim($child->brand->logo, '/'))
                        : null,
                    'banner' => $child->brand->banner
                        ? asset('storage/' . ltrim($child->brand->banner, '/'))
                        : null,
                ] : null,

                'tax_category'      => $child->taxCategory ? [
                    'id'   => $child->taxCategory->id,
                    'name' => $child->taxCategory->name,
                    'rate' => $child->taxCategory->rate,
                ] : null,
            ];
        }

        return $payload;
    }
    /**
     * Add product to wishlist
     */
    public function add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'exists:products,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $productId = $request->product_id;

        // Check if already in wishlist
        $exists = Wishlist::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Product already in wishlist',
                'already_exists' => true
            ], 200);
        }

        DB::beginTransaction();
        try {
            $wishlist = Wishlist::create([
                'user_id' => $user->id,
                'product_id' => $productId,
            ]);

            DB::commit();

            // Load product details
            $wishlist->load(['product' => function ($query) {
                $query->with(['category', 'taxCategory', 'images']);
            }]);

            return response()->json([
                'message' => 'Product added to wishlist successfully',
                'data' => [
                    'id' => $wishlist->id,
                    'product_id' => $wishlist->product_id,
                    'product' => $this->formatProductWithWishlist($wishlist->product, true),
                    'added_at' => $wishlist->created_at?->toISOString(),
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add product to wishlist',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove product from wishlist
     */
    public function remove(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'exists:products,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $productId = $request->product_id;

        $wishlist = Wishlist::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if (!$wishlist) {
            return response()->json([
                'message' => 'Product not found in wishlist'
            ], 404);
        }

        DB::beginTransaction();
        try {
            $wishlist->delete();
            DB::commit();

            return response()->json([
                'message' => 'Product removed from wishlist successfully',
                'product_id' => $productId
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to remove product from wishlist',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle wishlist (add if not exists, remove if exists)
     */
    public function toggle(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'exists:products,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $productId = $request->product_id;

        $wishlist = Wishlist::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        DB::beginTransaction();
        try {
            if ($wishlist) {
                // Remove from wishlist
                $wishlist->delete();
                DB::commit();

                return response()->json([
                    'message' => 'Product removed from wishlist',
                    'action' => 'removed',
                    'product_id' => $productId,
                    'is_wishlisted' => false
                ], 200);
            } else {
                // Add to wishlist
                $wishlist = Wishlist::create([
                    'user_id' => $user->id,
                    'product_id' => $productId,
                ]);

                DB::commit();
                $wishlist->load(['product' => function ($query) {
                    $query->with(['category', 'taxCategory', 'images']);
                }]);

                return response()->json([
                    'message' => 'Product added to wishlist',
                    'action' => 'added',
                    'data' => [
                        'id' => $wishlist->id,
                        'product_id' => $wishlist->product_id,
                        'added_at' => $wishlist->created_at?->toISOString(),
                    ],
                    'is_wishlisted' => true
                ], 201);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to toggle wishlist',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if product is in user's wishlist
     */
    public function check(Request $request, $productId)
    {
        $user = Auth::user();

        $isWishlisted = Wishlist::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->exists();

        return response()->json([
            'product_id' => (int) $productId,
            'is_wishlisted' => $isWishlisted
        ]);
    }

    /**
     * Get wishlist product IDs (for bulk checking)
     */
    public function getWishlistIds(Request $request)
    {
        $user = Auth::user();

        $wishlistIds = Wishlist::where('user_id', $user->id)
            ->pluck('product_id')
            ->toArray();

        return response()->json([
            'wishlist_product_ids' => $wishlistIds,
            'count' => count($wishlistIds)
        ]);
    }

    /**
     * Format product with wishlist status
     */
    // protected function formatProductWithWishlist($product, $isWishlisted = false)
    // {
    //     $primaryImage = $product->images->where('is_primary', true)->first()
    //         ?? $product->images->first();

    //     // Get approved reviews for this product
    //     $reviews = ProductReview::with([
    //         'user' => function ($query) {
    //             $query->select('id', 'full_name', 'profile_picture');
    //         },
    //         'images'
    //     ])
    //         ->where('product_id', $product->id)
    //         ->where('status', 'approved')
    //         ->orderBy('created_at', 'desc')
    //         ->limit(5)
    //         ->get();

    //     // Average rating
    //     $averageRating = ProductReview::where('product_id', $product->id)
    //         ->where('status', 'approved')
    //         ->avg('rating');

    //     // Total approved reviews
    //     $totalReviews = ProductReview::where('product_id', $product->id)
    //         ->where('status', 'approved')
    //         ->count();

    //     return [
    //         'id' => $product->id,
    //         'product_code' => $product->product_code,
    //         'name' => $product->name,
    //         'allowed_purchsed' => $product->allowed_purchsed,
    //         'slug' => $product->slug,
    //         'description' => $product->description,
    //         'specification' => $product->specification,

    //         'category_id' => $product->category_id,

    //         'category' => $product->category ? [
    //             'id' => $product->category->id,
    //             'name' => $product->category->title,
    //             'slug' => $product->category->slug,
    //         ] : null,

    //         'tax_category_id' => $product->tax_category_id,

    //         'retail_price' => $product->retail_price,
    //         'retail_price_formatted' => number_format($product->retail_price, 2),

    //         'distributor_price' => $product->distributor_price,
    //         'distributor_price_formatted' => $product->distributor_price
    //             ? number_format($product->distributor_price, 2)
    //             : null,

    //         'stock_quantity' => (int) $product->stock_quantity,
    //         'low_stock_threshold' => (int) $product->low_stock_threshold,

    //         'stock_status' => $product->stock_status,

    //         'is_published' => (bool) $product->is_published,

    //         'status' => $this->getProductStatus($product),

    //         'is_wishlisted' => $isWishlisted,

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Reviews Summary
    //         |--------------------------------------------------------------------------
    //         */

    //         'reviews_summary' => [
    //             'average_rating' => round($averageRating ?? 0, 1),
    //             'total_reviews' => $totalReviews,

    //             'recent_reviews' => $reviews->map(function ($review) {
    //                 return [
    //                     'id' => $review->id,
    //                     'user_id' => $review->user_id,

    //                     'user_name' => $review->user->full_name ?? 'Anonymous',

    //                     'user_profile_picture' => $review->user && $review->user->profile_picture
    //                         ? asset('storage/' . $review->user->profile_picture)
    //                         : null,

    //                     'rating' => $review->rating,

    //                     'review_text' => $review->review_text,

    //                     'created_at' => $review->created_at
    //                         ? $review->created_at->format('M d, Y')
    //                         : null,

    //                     'updated_at' => $review->updated_at
    //                         ? $review->updated_at->format('M d, Y')
    //                         : null,



    //                     'images' => $review->images->map(function ($image) {
    //                         return [
    //                             'id' => $image->id,
    //                             'image_url' => $image->image_url,
    //                             'sort_order' => $image->sort_order,
    //                         ];
    //                     })->values()->toArray(),
    //                 ];
    //             })->values()->toArray(),
    //         ],

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Product Images
    //         |--------------------------------------------------------------------------
    //         */

    //         'images' => $product->images->map(function ($image) {
    //             return [
    //                 'id' => $image->id,
    //                 'image' => $image->image,
    //                 'image_url' => asset('storage/' . $image->image),
    //                 'sort_order' => $image->sort_order,
    //                 'is_primary' => (bool) $image->is_primary,
    //             ];
    //         })->values()->toArray(),

    //         'primary_image' => $primaryImage
    //             ? $primaryImage->image
    //             : null,

    //         'primary_image_url' => $primaryImage
    //             ? asset('storage/' . $primaryImage->image)
    //             : null,

    //         'created_at' => $product->created_at?->toISOString(),
    //         'updated_at' => $product->updated_at?->toISOString(),
    //     ];
    // }
    protected function formatProductWithWishlist($product, $isWishlisted = false)
    {
        $primaryImage = $product->images->where('is_primary', true)->first()
            ?? $product->images->first();

        // Get approved reviews for this product
        $reviews = ProductReview::with([
            'user' => function ($query) {
                $query->select('id', 'full_name', 'profile_picture');
            },
            'images'
        ])
            ->where('product_id', $product->id)
            ->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Average rating
        $averageRating = ProductReview::where('product_id', $product->id)
            ->where('status', 'approved')
            ->avg('rating');

        // Total approved reviews
        $totalReviews = ProductReview::where('product_id', $product->id)
            ->where('status', 'approved')
            ->count();

        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'allowed_purchsed' => $product->allowed_purchsed,
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

            'retail_price' => $product->retail_price,
            'retail_price_formatted' => number_format($product->retail_price, 2),

            'distributor_price' => $product->distributor_price,
            'distributor_price_formatted' => $product->distributor_price
                ? number_format($product->distributor_price, 2)
                : null,

            'stock_quantity' => (int) $product->stock_quantity,
            'low_stock_threshold' => (int) $product->low_stock_threshold,

            'stock_status' => $product->stock_status,

            'is_published' => (bool) $product->is_published,

            'status' => $this->getProductStatus($product),

            'is_wishlisted' => $isWishlisted,

            /*
        |--------------------------------------------------------------------------
        | Reviews Summary
        |--------------------------------------------------------------------------
        */

            'reviews_summary' => [
                'average_rating' => round($averageRating ?? 0, 1),
                'total_reviews' => $totalReviews,

                'recent_reviews' => $reviews->map(function ($review) {
                    return [
                        'id' => $review->id,
                        'user_id' => $review->user_id,

                        'user_name' => $review->user->full_name ?? 'Anonymous',

                        'user_profile_picture' => $review->user && $review->user->profile_picture
                            ? asset('storage/' . $review->user->profile_picture)
                            : null,

                        'rating' => $review->rating,

                        'review_text' => $review->review_text,

                        'created_at' => $review->created_at
                            ? $review->created_at->format('M d, Y')
                            : null,

                        'updated_at' => $review->updated_at
                            ? $review->updated_at->format('M d, Y')
                            : null,

                        'images' => $review->images->map(function ($image) {
                            return [
                                'id' => $image->id,
                                'image_url' => $image->image_url,
                                'sort_order' => $image->sort_order,
                            ];
                        })->values()->toArray(),
                    ];
                })->values()->toArray(),
            ],

            /*
        |--------------------------------------------------------------------------
        | Product Images
        |--------------------------------------------------------------------------
        */

            'images' => $product->images->map(function ($image) {
                return [
                    'id' => $image->id,
                    'image' => $image->image,
                    'image_url' => asset('storage/' . $image->image),
                    'sort_order' => $image->sort_order,
                    'is_primary' => (bool) $image->is_primary,
                ];
            })->values()->toArray(),

            'primary_image' => $primaryImage
                ? $primaryImage->image
                : null,

            'primary_image_url' => $primaryImage
                ? asset('storage/' . $primaryImage->image)
                : null,

            'product_type' => $this->isComboProduct($product) ? 'combo' : 'single',
            'combo_products' => $this->isComboProduct($product)
                ? $this->formatComboChildren($product)
                : [],

            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    protected function getProductStatus($product)
    {
        if (!$product->is_published) return 'draft';
        if ($product->stock_quantity <= 0) return 'out_of_stock';
        if ($product->stock_quantity <= $product->low_stock_threshold) return 'low_stock';
        return 'active';
    }

    /**
     * Move a product from wishlist to cart – ONLY this endpoint is needed for the move.
     * It removes from wishlist and adds to cart in one transaction.
     */
    public function moveToCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'exists:products,id'],
            'quantity'   => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $productId = $request->product_id;
        $quantity = $request->input('quantity', 1);

        $product = Product::find($productId);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // Optional stock & publication check
        if (!$product->is_published || $product->stock_quantity < $quantity) {
            return response()->json([
                'message' => 'Product is not available in the requested quantity'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // 1. Remove from wishlist (if exists)
            $wishlistItem = Wishlist::where('user_id', $user->id)
                ->where('product_id', $productId)
                ->first();
            $wasInWishlist = false;
            if ($wishlistItem) {
                $wishlistItem->delete();
                $wasInWishlist = true;
            }

            // 2. Add to cart
            $cart = $this->getCart($request);
            $cartItem = $this->addItemToCart($cart, $productId, $quantity);

            DB::commit();

            // Optional: reload cart with items if you need details, but you can keep it minimal
            $cart->load('items.product.images');

            // Simple response – only what's necessary
            return response()->json([
                'message' => $wasInWishlist
                    ? 'Product moved to cart successfully'
                    : 'Product added to cart (was not in wishlist)',
                'cart_item_id' => $cartItem->id,
                'product_id'   => $productId,
                'quantity'     => $cartItem->quantity,
                'removed_from_wishlist' => $wasInWishlist,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to move product to cart',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    // ============================================================
    //  Helper methods (copied from CartController – essential)
    // ============================================================

    /**
     * Get or create cart for the current authenticated user.
     */
    protected function getCart(Request $request): Cart
    {
        $user = Auth::user();
        $cart = Cart::with('items.product.images')
            ->where('user_id', $user->id)
            ->first();

        if (!$cart) {
            $cart = Cart::create([
                'user_id' => $user->id,
                'session_id' => null,
            ]);
        }

        return $cart;
    }

    /**
     * Add a product to the cart (creates or updates quantity).
     */
    protected function addItemToCart(Cart $cart, int $productId, int $quantity): CartItem
    {
        $product = Product::find($productId);
        $currentPrice = $this->getCurrentProductPrice($product);

        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->first();

        if ($cartItem) {
            $cartItem->quantity += $quantity;
            $cartItem->unit_price = $currentPrice;
            $cartItem->save();
            return $cartItem;
        }

        return CartItem::create([
            'cart_id'    => $cart->id,
            'product_id' => $productId,
            'quantity'   => $quantity,
            'unit_price' => $currentPrice,
        ]);
    }

    /**
     * Get current price based on user type (distributor vs retail).
     */
    protected function getCurrentProductPrice(Product $product, $user = null): float
    {
        if (!$user) {
            $user = Auth::user();
        }
        if ($user && $user->account_type === 'distributor') {
            return $product->distributor_price ?? $product->retail_price;
        }
        return $product->retail_price;
    }

    /**
     * Get cart summary (optional – can be removed if not needed).
     */
    protected function getCartSummary(Cart $cart, $user = null): array
    {
        $total = 0;
        $totalItems = 0;
        foreach ($cart->items as $item) {
            $product = $item->product;
            if ($product) {
                $price = $this->getCurrentProductPrice($product, $user);
                $total += $price * $item->quantity;
                $totalItems += $item->quantity;
            }
        }
        return [
            'total_items' => $totalItems,
            'total'       => $total,
            'total_formatted' => number_format($total, 2),
        ];
    }
}
