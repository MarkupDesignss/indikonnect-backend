<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class WishlistController extends Controller
{
    /**
     * Get user's wishlist
     */
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

        // Debug
        // dd($wishlist->pluck('product.category'));

        $formattedWishlist = $wishlist->map(function ($item) {

            $product = $item->product;

            if ($product) {
                return [
                    'id' => $item->id,
                    'product_id' => $product->id,
                    'product' => $this->formatProductWithWishlist($product, true),
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
    protected function formatProductWithWishlist($product, $isWishlisted = false)
    {
        $primaryImage = $product->images->where('is_primary', true)->first()
            ?? $product->images->first();

        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
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
            'distributor_price_formatted' => $product->distributor_price ? number_format($product->distributor_price, 2) : null,
            'stock_quantity' => (int) $product->stock_quantity,
            'low_stock_threshold' => (int) $product->low_stock_threshold,
            'stock_status' => $product->stock_status,
            'is_published' => (bool) $product->is_published,
            'status' => $this->getProductStatus($product),
            'is_wishlisted' => $isWishlisted,
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
}
