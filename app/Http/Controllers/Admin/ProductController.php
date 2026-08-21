<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductReview;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Get user's wishlist product IDs
     */
    /**
     * Get user's wishlist product IDs
     */
    protected function getUserWishlistIds($userId = null)
    {
        // If user ID is provided in the request
        if ($userId) {
            return Wishlist::where('user_id', $userId)
                ->pluck('product_id')
                ->toArray();
        }

        // Otherwise check authenticated user
        if (auth('sanctum')->check()) {
            return Wishlist::where('user_id', auth('sanctum')->id())
                ->pluck('product_id')
                ->toArray();
        }

        return [];
    }

    /**
     * Get all products with filtering and pagination
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'taxCategory', 'images']);

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

        // Filter by stock status
        if ($request->has('stock_status')) {
            $stockStatus = $request->stock_status;

            if (is_array($stockStatus)) {
                // Handle multiple stock statuses
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
                // Single stock status filter
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
                    ->orWhere('slug', 'LIKE', "%{$search}%");
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
     * Get min and max price range for products
     */
    protected function getPriceRange()
    {
        $minPrice = Product::min('retail_price');
        $maxPrice = Product::max('retail_price');

        return [
            'min' => $minPrice ? (float) $minPrice : 0,
            'max' => $maxPrice ? (float) $maxPrice : 0,
        ];
    }

    /**
     * Get a single product by ID
     */
    public function show(Product $product)
    {
        $product->load(['category', 'taxCategory', 'images']);

        $userId = request()->query('user_id');
        $wishlistIds = $this->getUserWishlistIds($userId);

        return response()->json($this->formatProduct($product, $wishlistIds));
    }

    /**
     * Get product by slug
     */
    // public function showBySlug($slug)
    // {
    //     $product = Product::with(['category', 'taxCategory', 'images'])
    //         ->where('slug', $slug)
    //         ->firstOrFail();

    //     $userId = request()->query('user_id');
    //     $wishlistIds = $this->getUserWishlistIds($userId);

    //     return response()->json($this->formatProduct($product, $wishlistIds));
    // }
    public function showBySlug($slug)
    {
        $product = Product::with(['category', 'taxCategory', 'images'])
            ->where('slug', $slug)
            ->firstOrFail();

        $userId = request()->query('user_id');
        $wishlistIds = $this->getUserWishlistIds($userId);

        // Get all approved reviews for this product
        $reviews = ProductReview::with([
            'user' => function ($query) {
                $query->select('id', 'full_name', 'email', 'profile_picture');
            },
            'images'
        ])
            ->where('product_id', $product->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $averageRating = ProductReview::where('product_id', $product->id)
            ->avg('rating');

        $totalReviews = ProductReview::where('product_id', $product->id)
            ->count();

        // Rating distribution
        $ratingDistribution = [
            1 => ProductReview::where('product_id', $product->id)->where('rating', 1)->count(),
            2 => ProductReview::where('product_id', $product->id)->where('rating', 2)->count(),
            3 => ProductReview::where('product_id', $product->id)->where('rating', 3)->count(),
            4 => ProductReview::where('product_id', $product->id)->where('rating', 4)->count(),
            5 => ProductReview::where('product_id', $product->id)->where('rating', 5)->count(),
        ];

        $formattedProduct = $this->formatProduct($product, $wishlistIds);

        // Add reviews to the response
        $formattedProduct['reviews'] = [
            'summary' => [
                'average_rating' => round($averageRating, 1),
                'total_reviews' => $totalReviews,
                'rating_distribution' => $ratingDistribution,
            ],
            'data' => $reviews->map(function ($review) {
                return [
                    'id' => $review->id,
                    'user_id' => $review->user_id,
                    'user_name' => $review->user->full_name ?? 'Anonymous',
                    'user_profile_picture' => $review->user->profile_picture
                        ? asset('storage/' . $review->user->profile_picture)
                        : null,
                    'rating' => $review->rating,
                    'review_text' => $review->review_text,
                    'created_at' => $review->created_at->format('M d, Y'),
                    'updated_at' => $review->updated_at->format('M d, Y'),
                    'is_verified_purchase' => $this->isVerifiedPurchase($review->order_id),
                    'images' => $review->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => $image->image_url,
                            'sort_order' => $image->sort_order,
                        ];
                    })->values()->toArray(),
                ];
            })->values()->toArray(),
        ];

        return response()->json($formattedProduct);
    }

    private function isVerifiedPurchase($orderId)
    {
        if (!$orderId) {
            return false;
        }

        $order = Order::find($orderId);
        return $order && $order->status === 'delivered';
    }


    /**
     * Get product by product code
     */
    public function showByCode($code)
    {
        $product = Product::with(['category', 'taxCategory', 'images'])
            ->where('product_code', $code)
            ->firstOrFail();

        return response()->json($this->formatProduct($product));
    }

    /**
     * Create a new product
     */
    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'product_code' => ['required', 'string', 'max:255', Rule::unique('products')],
    //         'name' => ['required', 'string', 'max:255'],
    //         'slug' => ['nullable', 'string', 'max:255', Rule::unique('products')],
    //         'description' => ['nullable', 'string'],
    //         'specification' => ['nullable', 'string'],
    //         'category_id' => ['required', 'exists:categories,id'],
    //         'tax_category_id' => ['nullable', 'exists:tax_categories,id'],
    //         'retail_price' => ['required', 'numeric'],
    //         'distributor_price' => ['nullable', 'numeric'],
    //         'stock_quantity' => ['required', 'integer'],
    //         'low_stock_threshold' => ['nullable', 'integer'],
    //         'is_published' => ['nullable', 'boolean'],
    //         'product_images' => ['nullable', 'array'],
    //         'product_images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
    //         'product_images.*.sort_order' => ['nullable', 'integer'],
    //         'product_images.*.is_primary' => ['nullable', 'boolean'],
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => $validator->errors()], 422);
    //     }

    //     DB::beginTransaction();
    //     try {
    //         $validated = $validator->validated();

    //         // Extract product data (remove product_images)
    //         $productData = collect($validated)->except(['product_images'])->toArray();

    //         // Generate slug if not provided
    //         if (empty($productData['slug'])) {
    //             $productData['slug'] = Str::slug($productData['name']);
    //         }
    //         $productData['slug'] = $this->generateUniqueSlug($productData['slug']);
    //         $productData['is_published'] = $productData['is_published'] ?? false;
    //         $productData['low_stock_threshold'] = $productData['low_stock_threshold'] ?? 5;

    //         // Create product
    //         $product = Product::create($productData);

    //         // Handle images
    //         $productImages = $request->input('product_images', []);
    //         $imageCount = 0;
    //         $hasPrimary = false;

    //         // Debug: Log received images
    //         Log::info('Product images received:', [
    //             'count' => count($productImages),
    //             'images' => $productImages
    //         ]);

    //         if (!empty($productImages)) {
    //             foreach ($productImages as $index => $imageData) {
    //                 // Check if image file exists in request
    //                 $imageFile = $request->file("product_images.{$index}.image");

    //                 // Debug: Log each image
    //                 Log::info("Processing image {$index}:", [
    //                     'has_file' => $imageFile ? 'yes' : 'no',
    //                     'is_valid' => $imageFile && $imageFile->isValid() ? 'yes' : 'no',
    //                     'file_name' => $imageFile ? $imageFile->getClientOriginalName() : 'null'
    //                 ]);

    //                 if ($imageFile && $imageFile->isValid()) {
    //                     $path = $imageFile->store('products', 'public');
    //                     $sortOrder = isset($imageData['sort_order']) ? (int) $imageData['sort_order'] : $imageCount;

    //                     // Determine if this should be primary
    //                     $isPrimary = false;
    //                     if (isset($imageData['is_primary'])) {
    //                         $isPrimary = (bool) $imageData['is_primary'];
    //                     } elseif (!$hasPrimary && $imageCount === 0) {
    //                         $isPrimary = true;
    //                     }

    //                     ProductImage::create([
    //                         'product_id' => $product->id,
    //                         'image' => $path,
    //                         'is_primary' => $isPrimary,
    //                         'sort_order' => $sortOrder,
    //                     ]);

    //                     if ($isPrimary) {
    //                         $hasPrimary = true;
    //                     }

    //                     $imageCount++;
    //                 }
    //             }

    //             // If no primary image was set, set the first image as primary
    //             if (!$hasPrimary && $imageCount > 0) {
    //                 $firstImage = ProductImage::where('product_id', $product->id)
    //                     ->orderBy('sort_order')
    //                     ->first();
    //                 if ($firstImage) {
    //                     $firstImage->update(['is_primary' => true]);
    //                 }
    //             }
    //         }

    //         DB::commit();
    //         $product->load(['category', 'taxCategory', 'images']);

    //         return response()->json($this->formatProduct($product), 201);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error('Product creation failed:', [
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);
    //         return response()->json([
    //             'message' => 'Failed to create product',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function store(Request $request)
    {
        // dd($request->all());
        $validator = Validator::make($request->all(), [
            'product_code' => ['required', 'string', 'max:255', Rule::unique('products')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products')],
            'description' => ['nullable', 'string'],
            'specification' => ['nullable', 'string'],
            'category_id' => ['required', 'exists:categories,id'],
            'tax_category_id' => ['nullable', 'exists:tax_categories,id'],
            'retail_mrp' => ['required', 'numeric', 'min:0'],
            'retail_discount_type' => ['nullable', 'in:percentage,fixed'],
            'retail_discount_value' => ['nullable', 'numeric', 'min:0'],
            'distributor_mrp' => ['nullable', 'numeric', 'min:0'],
            'distributor_discount_type' => ['nullable', 'in:percentage,fixed'],
            'distributor_discount_value' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
            'is_trending' => ['nullable', 'boolean'],
            'trending_sort_order' => ['nullable', 'integer', 'min:0'],
            'product_images' => ['nullable', 'array'],
            'product_images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,avif'],
            'product_images.*.sort_order' => ['nullable', 'integer'],
            'product_images.*.is_primary' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $validator->validated();

            // Extract product data (remove product_images)
            $productData = collect($validated)->except(['product_images'])->toArray();

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

            // Create product
            $product = Product::create($productData);

            // Handle images (same as before)
            $productImages = $request->input('product_images', []);
            $imageCount = 0;
            $hasPrimary = false;

            Log::info('Product images received:', [
                'count' => count($productImages),
                'images' => $productImages
            ]);

            if (!empty($productImages)) {
                foreach ($productImages as $index => $imageData) {
                    $imageFile = $request->file("product_images.{$index}.image");

                    Log::info("Processing image {$index}:", [
                        'has_file' => $imageFile ? 'yes' : 'no',
                        'is_valid' => $imageFile && $imageFile->isValid() ? 'yes' : 'no',
                        'file_name' => $imageFile ? $imageFile->getClientOriginalName() : 'null'
                    ]);

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

            DB::commit();
            $product->load(['category', 'taxCategory', 'images']);

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
    /**
     * Format a single product
     */
    // protected function formatProduct($product, $wishlistIds = [])
    // {
    //     $isWishlisted = in_array($product->id, $wishlistIds);

    //     $primaryImage = $product->images->where('is_primary', true)->first()
    //         ?? $product->images->first();

    //     return [
    //         'id' => $product->id,
    //         'product_code' => $product->product_code,
    //         'name' => $product->name,
    //         'slug' => $product->slug,
    //         'description' => $product->description,
    //         'specification' => $product->specification,
    //         'category_id' => $product->category_id,
    //         'category' => $product->category ? [
    //             'id' => $product->category->id,
    //             'name' => $product->category->title,
    //             'slug' => $product->category->slug,
    //             'description' => $product->category->description,
    //         ] : null,
    //         'tax_category_id' => $product->tax_category_id,
    //         'retail_price' => $product->retail_price,
    //         'retail_price_formatted' => number_format($product->retail_price, 2),
    //         'distributor_price' => $product->distributor_price,
    //         'distributor_price_formatted' => $product->distributor_price ? number_format($product->distributor_price, 2) : null,
    //         'stock_quantity' => (int) $product->stock_quantity,
    //         'low_stock_threshold' => (int) $product->low_stock_threshold,
    //         'is_published' => (bool) $product->is_published,
    //         'status' => $this->getProductStatus($product),
    //         'is_wishlisted' => $isWishlisted,
    //         'images' => $product->images->map(function ($image) {
    //             return [
    //                 'id' => $image->id,
    //                 'image' => $image->image,
    //                 'image_url' => asset('storage/' . $image->image),
    //                 'sort_order' => $image->sort_order,
    //                 'is_primary' => (bool) $image->is_primary,
    //             ];
    //         })->values()->toArray(),
    //         'primary_image' => $primaryImage ? $primaryImage->image : null,
    //         'primary_image_url' => $primaryImage ? asset('storage/' . $primaryImage->image) : null,
    //         'created_at' => $product->created_at?->toISOString(),
    //         'updated_at' => $product->updated_at?->toISOString(),
    //     ];
    // }

    protected function formatProduct($product, $wishlistIds = [])
    {
        $isWishlisted = in_array($product->id, $wishlistIds);

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
                'description' => $product->category->description,
            ] : null,
            'tax_category_id' => $product->tax_category_id,

            // Retail pricing
            'retail_mrp' => $product->retail_mrp,
            'retail_price' => $product->retail_price,
            // 'retail_price_formatted' => number_format($product->retail_price, 2),
            'retail_discount_type' => $product->retail_discount_type,
            'retail_discount_value' => $product->retail_discount_value,
            'retail_discount_amount' => $product->retail_mrp - $product->retail_price,
            'retail_discount_percentage' => $product->retail_mrp > 0
                ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
                : 0,

            // Distributor pricing
            'distributor_mrp' => $product->distributor_mrp,
            'distributor_price' => $product->distributor_price,
            // 'distributor_price_formatted' => $product->distributor_price ? number_format($product->distributor_price, 2) : null,
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

    /**
     * Update an existing product
     */
    // public function update(Request $request, $id)
    // {
    //     $product = Product::where('id', $id)->first();
    //     if (!$product) {
    //         return response()->json([
    //             'message' => 'Product not found'
    //         ], 422);
    //     }

    //     // DEBUG: Log incoming request
    //     Log::info('=== UPDATE REQUEST DEBUG ===');
    //     Log::info('All request data:', $request->all());
    //     Log::info('All files:', array_keys($_FILES));

    //     $validator = Validator::make($request->all(), [
    //         'product_code' => ['required', 'string', 'max:255', Rule::unique('products')->ignore($product->id)],
    //         'name' => ['required', 'string', 'max:255'],
    //         'slug' => ['nullable', 'string', 'max:255', Rule::unique('products')->ignore($product->id)],
    //         'description' => ['nullable', 'string'],
    //         'specification' => ['nullable', 'string'],
    //         'category_id' => ['required', 'exists:categories,id'],
    //         'tax_category_id' => ['nullable', 'exists:tax_categories,id'],
    //         'retail_price' => ['required', 'numeric', 'min:0'],
    //         'distributor_price' => ['nullable', 'numeric', 'min:0'],
    //         'stock_quantity' => ['required', 'integer', 'min:0'],
    //         'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
    //         'is_published' => ['nullable', 'boolean'],
    //         'product_images' => ['nullable', 'array'],
    //         'product_images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
    //         'product_images.*.sort_order' => ['nullable', 'integer'],
    //         'product_images.*.is_primary' => ['nullable', 'boolean'],
    //         'remove_images' => ['nullable', 'array'],
    //         'remove_images.*' => ['exists:product_images,id'],
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => $validator->errors()], 422);
    //     }

    //     DB::beginTransaction();
    //     try {
    //         $validated = $validator->validated();

    //         // Extract product data (remove product_images and remove_images)
    //         $productData = collect($validated)->except(['product_images', 'remove_images'])->toArray();

    //         // Handle slug
    //         if (empty($productData['slug'])) {
    //             $productData['slug'] = Str::slug($productData['name']);
    //         }
    //         if ($productData['slug'] !== $product->slug) {
    //             $productData['slug'] = $this->generateUniqueSlug($productData['slug'], $product->id);
    //         }
    //         $productData['low_stock_threshold'] = $productData['low_stock_threshold'] ?? 5;

    //         // Update product
    //         $product->update($productData);

    //         // Remove specified images
    //         $removeImages = $validated['remove_images'] ?? [];
    //         if (!empty($removeImages)) {
    //             $imagesToRemove = ProductImage::whereIn('id', $removeImages)
    //                 ->where('product_id', $product->id)
    //                 ->get();

    //             foreach ($imagesToRemove as $image) {
    //                 if (Storage::disk('public')->exists($image->image)) {
    //                     Storage::disk('public')->delete($image->image);
    //                 }
    //                 $image->delete();
    //             }
    //         }

    //         // Handle images - IMPROVED VERSION
    //         $productImages = $request->input('product_images', []);
    //         Log::info('Product images input:', $productImages);

    //         if (!empty($productImages)) {
    //             $existingCount = $product->images()->count();
    //             $hasPrimary = $product->images()->where('is_primary', true)->exists();
    //             $imageCount = 0;

    //             foreach ($productImages as $index => $imageData) {
    //                 // Try multiple ways to get the file
    //                 $imageFile = $request->file("product_images.{$index}.image");

    //                 // If not found, try alternative key format
    //                 if (!$imageFile) {
    //                     $imageFile = $request->file("product_images.{$index}.image");
    //                 }

    //                 Log::info("Processing image {$index}:", [
    //                     'has_file' => $imageFile ? 'yes' : 'no',
    //                     'is_valid' => $imageFile && $imageFile->isValid() ? 'yes' : 'no',
    //                     'file_name' => $imageFile ? $imageFile->getClientOriginalName() : 'null',
    //                     'image_data' => $imageData
    //                 ]);

    //                 if ($imageFile && $imageFile->isValid()) {
    //                     $path = $imageFile->store('products', 'public');

    //                     // Get sort order - use provided or auto-increment
    //                     $sortOrder = isset($imageData['sort_order'])
    //                         ? (int) $imageData['sort_order']
    //                         : $existingCount + $imageCount;

    //                     // Determine primary status
    //                     $isPrimary = false;
    //                     if (isset($imageData['is_primary'])) {
    //                         $isPrimary = (bool) $imageData['is_primary'];
    //                     } elseif (!$hasPrimary && $imageCount === 0) {
    //                         $isPrimary = true;
    //                     }

    //                     $created = ProductImage::create([
    //                         'product_id' => $product->id,
    //                         'image' => $path,
    //                         'is_primary' => $isPrimary,
    //                         'sort_order' => $sortOrder,
    //                     ]);

    //                     Log::info("Created image:", [
    //                         'id' => $created->id,
    //                         'path' => $path,
    //                         'is_primary' => $isPrimary,
    //                         'sort_order' => $sortOrder
    //                     ]);

    //                     if ($isPrimary) {
    //                         $hasPrimary = true;
    //                     }

    //                     $imageCount++;
    //                 }
    //             }

    //             // If no primary image was set, set the first image as primary
    //             if (!$hasPrimary && $imageCount > 0) {
    //                 $firstImage = ProductImage::where('product_id', $product->id)
    //                     ->orderBy('sort_order')
    //                     ->first();
    //                 if ($firstImage) {
    //                     $firstImage->update(['is_primary' => true]);
    //                     Log::info("Set primary image:", ['id' => $firstImage->id]);
    //                 }
    //             }
    //         }

    //         DB::commit();
    //         $product->load(['category', 'taxCategory', 'images']);

    //         Log::info('Final images count: ' . $product->images->count());

    //         return response()->json($this->formatProduct($product));
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error('Failed to update product:', [
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);
    //         return response()->json([
    //             'message' => 'Failed to update product',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function update(Request $request, $id)
    {
        $product = Product::where('id', $id)->first();
        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 422);
        }

        Log::info('=== UPDATE REQUEST DEBUG ===');
        Log::info('All request data:', $request->all());
        Log::info('All files:', array_keys($_FILES));

        $validator = Validator::make($request->all(), [
            'product_code' => ['sometimes', 'required', 'string', 'max:255'],  // Changed to 'sometimes|required'
            'name' => ['sometimes', 'required', 'string', 'max:255'],         // Changed to 'sometimes|required'
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products')->ignore($product->id)],
            'description' => ['nullable', 'string'],
            'specification' => ['nullable', 'string'],
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],  // Changed to 'sometimes|required'
            'tax_category_id' => ['nullable', 'exists:tax_categories,id'],
            'retail_mrp' => ['sometimes', 'required', 'numeric', 'min:0'],  // Changed to 'sometimes|required'
            'retail_discount_type' => ['nullable', 'in:percentage,fixed'],
            'retail_discount_value' => ['nullable', 'numeric', 'min:0'],
            'distributor_mrp' => ['nullable', 'numeric', 'min:0'],
            'distributor_discount_type' => ['nullable', 'in:percentage,fixed'],
            'distributor_discount_value' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['sometimes', 'required', 'integer', 'min:0'],  // Changed to 'sometimes|required'
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
            'is_trending' => ['nullable', 'boolean'],
            'trending_sort_order' => ['nullable', 'integer', 'min:0'],
            'product_images' => ['nullable', 'array'],
            'product_images.*.image' => ['nullable', 'mimes:jpg,jpeg,png,webp,avif'],
            'product_images.*.sort_order' => ['nullable', 'integer'],
            'product_images.*.is_primary' => ['nullable', 'boolean'],
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['exists:product_images,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $validated = $validator->validated();

            // Extract product data (remove product_images and remove_images)
            $productData = collect($validated)->except(['product_images', 'remove_images'])->toArray();

            // Only calculate retail price if retail_mrp is provided
            if (isset($productData['retail_mrp'])) {
                $productData['retail_price'] = $this->calculatePrice(
                    $productData['retail_mrp'],
                    $productData['retail_discount_type'] ?? null,
                    $productData['retail_discount_value'] ?? null
                );
            }

            // Only calculate distributor price if distributor_mrp is provided
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

            // Handle slug - only if provided or if name is provided
            if (isset($productData['slug']) || isset($productData['name'])) {
                if (empty($productData['slug']) && isset($productData['name'])) {
                    $productData['slug'] = Str::slug($productData['name']);
                }
                if (isset($productData['slug']) && $productData['slug'] !== $product->slug) {
                    $productData['slug'] = $this->generateUniqueSlug($productData['slug'], $product->id);
                }
            }

            // Only set low_stock_threshold if provided
            if (isset($productData['low_stock_threshold'])) {
                $productData['low_stock_threshold'] = $productData['low_stock_threshold'] ?? 5;
            }

            // Update product - only update fields that are present
            $product->update($productData);

            // ... rest of your code remains the same ...

            // Remove specified images (same as before)
            $removeImages = $validated['remove_images'] ?? [];
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

            // Handle images (same as before)
            $productImages = $request->input('product_images', []);
            Log::info('Product images input:', $productImages);

            if (!empty($productImages)) {
                $existingCount = $product->images()->count();
                $hasPrimary = $product->images()->where('is_primary', true)->exists();
                $imageCount = 0;

                foreach ($productImages as $index => $imageData) {
                    $imageFile = $request->file("product_images.{$index}.image");

                    Log::info("Processing image {$index}:", [
                        'has_file' => $imageFile ? 'yes' : 'no',
                        'is_valid' => $imageFile && $imageFile->isValid() ? 'yes' : 'no',
                        'file_name' => $imageFile ? $imageFile->getClientOriginalName() : 'null',
                        'image_data' => $imageData
                    ]);

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

                        $created = ProductImage::create([
                            'product_id' => $product->id,
                            'image' => $path,
                            'is_primary' => $isPrimary,
                            'sort_order' => $sortOrder,
                        ]);

                        Log::info("Created image:", [
                            'id' => $created->id,
                            'path' => $path,
                            'is_primary' => $isPrimary,
                            'sort_order' => $sortOrder
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
                        Log::info("Set primary image:", ['id' => $firstImage->id]);
                    }
                }
            }

            DB::commit();
            $product->load(['category', 'taxCategory', 'images']);

            Log::info('Final images count: ' . $product->images->count());

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

    protected function calculatePrice($mrp, $discountType = null, $discountValue = null)
    {
        // If no discount type or value, return MRP as final price
        if (empty($discountType) || empty($discountValue) || $discountValue <= 0) {
            return $mrp;
        }

        if ($discountType === 'percentage') {
            // Percentage discount: MRP - (MRP * discount% / 100)
            $discountAmount = ($mrp * $discountValue) / 100;
            $finalPrice = $mrp - $discountAmount;
        } elseif ($discountType === 'fixed') {
            // Fixed discount: MRP - fixed amount
            $finalPrice = $mrp - $discountValue;
        } else {
            return $mrp;
        }

        // Ensure price doesn't go below zero
        return max(0, $finalPrice);
    }

    /**
     * Delete/Soft delete a product
     */
    public function destroy(Product $product)
    {
        $product->delete();
        return response()->json(['message' => 'Product deleted successfully'], 200);
    }

    /**
     * Restore a soft-deleted product
     */
    public function restore($id)
    {
        $product = Product::withTrashed()->findOrFail($id);
        $product->restore();
        $product->load(['category', 'taxCategory', 'images']);

        return response()->json($this->formatProduct($product));
    }

    /**
     * Permanently delete a product
     */
    public function forceDelete($id)
    {
        $product = Product::withTrashed()->findOrFail($id);

        DB::beginTransaction();
        try {
            // Delete all images
            foreach ($product->images as $image) {
                if (Storage::disk('public')->exists($image->image)) {
                    Storage::disk('public')->delete($image->image);
                }
            }
            $product->images()->delete();
            $product->forceDelete();

            DB::commit();
            return response()->json(['message' => 'Product permanently deleted'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update stock quantity
     */
    public function updateStock(Request $request, Product $product)
    {
        $validator = Validator::make($request->all(), [
            'stock_quantity' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $product->update(['stock_quantity' => $request->stock_quantity]);
        return response()->json($this->formatProduct($product));
    }

    /**
     * Toggle product publication status
     */
    public function togglePublish(Product $product)
    {
        $product->update(['is_published' => !$product->is_published]);
        return response()->json($this->formatProduct($product));
    }

    /**
     * Bulk update product status
     */
    public function bulkUpdateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['exists:products,id'],
            'is_published' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        Product::whereIn('id', $request->product_ids)
            ->update(['is_published' => $request->is_published]);

        return response()->json(['message' => 'Products updated successfully'], 200);
    }

    /**
     * Bulk delete products
     */
    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['exists:products,id'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $products = Product::whereIn('id', $request->product_ids)->get();
            foreach ($products as $product) {
                foreach ($product->images as $image) {
                    if (Storage::disk('public')->exists($image->image)) {
                        Storage::disk('public')->delete($image->image);
                    }
                }
                $product->images()->delete();
                $product->delete();
            }

            DB::commit();
            return response()->json(['message' => 'Products deleted successfully'], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete products',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get product statistics
     */
    public function stats()
    {
        $stats = [
            'total' => Product::count(),
            'published' => Product::where('is_published', true)->count(),
            'unpublished' => Product::where('is_published', false)->count(),
            'out_of_stock' => Product::where('stock_quantity', 0)->count(),
            'low_stock' => Product::whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                ->where('stock_quantity', '>', 0)
                ->count(),
            'total_value' => Product::sum('retail_price'),
        ];

        return response()->json($stats);
    }

    /**
     * Generate unique slug
     */
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



    /**
     * Format product collection
     */


    // protected function formatProductCollection($products, $wishlistIds = [])
    // {
    //     return $products->map(function ($product) use ($wishlistIds) {
    //         $isWishlisted = in_array($product->id, $wishlistIds);
    //         $isActiveDeal = $product->isActiveDealOfTheDay();

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
    //             // 'retail_price_formatted' => number_format($product->retail_price, 2),
    //             // 'retail_discount_type' => $product->retail_discount_type,
    //             // 'retail_discount_value' => $product->retail_discount_value,
    //             // 'retail_discount_amount' => $product->retail_mrp - $product->retail_price,
    //             // 'retail_discount_percentage' => $product->retail_mrp > 0
    //             //     ? round((($product->retail_mrp - $product->retail_price) / $product->retail_mrp) * 100, 2)
    //             //     : 0,

    //             'distributor_mrp' => $product->distributor_mrp,
    //             'distributor_price' => $product->distributor_price,
    //             // 'distributor_price_formatted' => $product->distributor_price ? number_format($product->distributor_price, 2) : null,
    //             // 'distributor_discount_type' => $product->distributor_discount_type,
    //             // 'distributor_discount_value' => $product->distributor_discount_value,
    //             // 'distributor_discount_amount' => $product->distributor_mrp && $product->distributor_price
    //             //     ? $product->distributor_mrp - $product->distributor_price
    //             //     : null,
    //             // 'distributor_discount_percentage' => $product->distributor_mrp && $product->distributor_price && $product->distributor_mrp > 0
    //             //     ? round((($product->distributor_mrp - $product->distributor_price) / $product->distributor_mrp) * 100, 2)
    //             //     : null,

    //             // Deal of the Day fields
    //             'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
    //             'is_active_deal' => $isActiveDeal,
    //             'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
    //             'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),

    //             'stock_quantity' => (int) $product->stock_quantity,
    //             'low_stock_threshold' => (int) $product->low_stock_threshold,
    //             'stock_status' => $this->getProductStatus($product),
    //             'is_published' => (bool) $product->is_published,
    //             'is_trending' => (bool) $product->is_trending,
    //             'trending_sort_order' => (int) $product->trending_sort_order,
    //             'is_wishlisted' => $isWishlisted,
    //             'images' => $product->images->map(function ($image) {
    //                 return [
    //                     'id' => $image->id,
    //                     'image_url' => asset('storage/' . $image->image),
    //                     'is_primary' => (bool) $image->is_primary,
    //                     'sort_order' => $image->sort_order,
    //                 ];
    //             })->values()->toArray(),
    //             'primary_image_url' => $product->primaryImage ? asset('storage/' . $product->primaryImage->image) : null,
    //             // 'created_at' => $product->created_at?->toISOString(),
    //             // 'updated_at' => $product->updated_at?->toISOString(),
    //         ];
    //     })->values()->toArray();
    // }

    protected function formatProductCollection($products, $wishlistIds = [])
    {
        return $products->map(function ($product) use ($wishlistIds) {
            $isWishlisted = in_array($product->id, $wishlistIds);
            $isActiveDeal = $product->isActiveDealOfTheDay();

            // Get product reviews with user data
            $reviews = ProductReview::with([
                'user' => function ($query) {
                    $query->select('id', 'full_name', 'profile_picture');
                },
                'images'
            ])
                ->where('product_id', $product->id)
                ->orderBy('created_at', 'desc')
                ->limit(5) // Show last 5 reviews
                ->get();

            $averageRating = ProductReview::where('product_id', $product->id)
                ->avg('rating');

            $totalReviews = ProductReview::where('product_id', $product->id)
                ->count();

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
                'tax_category' => $product->taxCategory ? [
                    'id' => $product->taxCategory->id,
                    'name' => $product->taxCategory->name,
                    'rate' => $product->taxCategory->rate,
                ] : null,

                'retail_mrp' => $product->retail_mrp,
                'retail_price' => $product->retail_price,

                'distributor_mrp' => $product->distributor_mrp,
                'distributor_price' => $product->distributor_price,

                // Deal of the Day fields
                'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
                'is_active_deal' => $isActiveDeal,
                'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
                'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),

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
                    'recent_reviews' => $reviews->map(function ($review) {
                        return [
                            'id' => $review->id,
                            'user_id' => $review->user_id,
                            'user_name' => $review->user->full_name ?? 'Anonymous',
                            'user_profile_picture' => $review->user->profile_picture
                                ? asset('storage/' . $review->user->profile_picture)
                                : null,
                            'rating' => $review->rating,
                            'review_text' => $review->review_text,
                            'created_at' => $review->created_at->format('M d, Y'),
                            'is_verified_purchase' => $this->isVerifiedPurchase($review->order_id),
                            'images' => $review->images->map(function ($image) {
                                return [
                                    'id' => $image->id,
                                    'image_url' => $image->image_url,
                                ];
                            })->values()->toArray(),
                        ];
                    })->values()->toArray(),
                ],

                'images' => $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => asset('storage/' . $image->image),
                        'is_primary' => (bool) $image->is_primary,
                        'sort_order' => $image->sort_order,
                    ];
                })->values()->toArray(),
                'primary_image_url' => $product->primaryImage ? asset('storage/' . $product->primaryImage->image) : null,
            ];
        })->values()->toArray();
    }

    /**
     * Mark product as deal of the day
     */
    public function markAsDealOfTheDay(Request $request, $id)
    {
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
                'deal_of_the_day_ends_at' => $request->ends_at ?? null,
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

    /**
     * Remove product from deal of the day
     */
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

    /**
     * Get all active deal of the day products
     */
    // public function getDealOfTheDayProducts(Request $request)
    // {
    //     $now = now();

    //     $products = Product::with(['category', 'taxCategory', 'images', 'primaryImage'])
    //         ->where('is_published', true)
    //         ->where('is_deal_of_the_day', true)
    //         ->where(function ($query) use ($now) {
    //             $query->whereNull('deal_of_the_day_starts_at')
    //                 ->orWhere('deal_of_the_day_starts_at', '<=', $now);
    //         })
    //         ->where(function ($query) use ($now) {
    //             $query->whereNull('deal_of_the_day_ends_at')
    //                 ->orWhere('deal_of_the_day_ends_at', '>=', $now);
    //         })
    //         ->orderBy('deal_of_the_day_starts_at', 'asc')
    //         ->paginate($request->get('per_page', 15));

    //     return response()->json([
    //         'data' => $this->formatProductCollection($products),
    //         'meta' => [
    //             'current_page' => $products->currentPage(),
    //             'per_page' => $products->perPage(),
    //             'total' => $products->total(),
    //             'last_page' => $products->lastPage(),
    //         ]
    //     ]);
    // }
    // public  function getDealOfTheDayProducts($wishlistIds = [])
    // {
    //     // 1. Admin-selected Deal of the Day products
    //     $adminDeals = Product::with(['category', 'taxCategory', 'images'])
    //         ->where('is_deal_of_the_day', true)
    //         ->where('is_published', true)
    //         ->orderBy('trending_sort_order')
    //         ->get();

    //     // Already selected product IDs
    //     $selectedIds = $adminDeals->pluck('id')->toArray();

    //     // 2. If less than 2, get most ordered products
    //     $required = 2 - $adminDeals->count();

    //     if ($required > 0) {
    //         $defaultProducts = OrderLine::query()
    //             ->select('product_id', DB::raw('SUM(quantity) as total_quantity'))
    //             ->whereNotNull('product_id')
    //             ->whereNotIn('product_id', $selectedIds)
    //             ->groupBy('product_id')
    //             ->orderByDesc('total_quantity')
    //             ->limit($required)
    //             ->with('product')
    //             ->get()
    //             ->pluck('product')
    //             ->filter(function ($product) {
    //                 return $product
    //                     && $product->is_published
    //                     && $product->stock_quantity > 0;
    //             });

    //         $adminDeals = $adminDeals->concat($defaultProducts);
    //     }

    //     // 3. If still less than 2, fill from products
    //     // (useful when there are not enough products in order_lines)
    //     if ($adminDeals->count() < 2) {
    //         $remaining = 2 - $adminDeals->count();

    //         $fallbackProducts = Product::with(['category', 'taxCategory', 'images'])
    //             ->where('is_published', true)
    //             ->where('stock_quantity', '>', 0)
    //             ->whereNotIn('id', $adminDeals->pluck('id')->toArray())
    //             ->latest()
    //             ->limit($remaining)
    //             ->get();

    //         $adminDeals = $adminDeals->concat($fallbackProducts);
    //     }

    //     return $adminDeals
    //         ->take(2)
    //         ->map(function ($product) use ($wishlistIds) {
    //             return $this->formatProduct($product, $wishlistIds);
    //         })
    //         ->values()
    //         ->toArray();
    // }

    public function getDealOfTheDayProducts($wishlistIds = [])
    {
        // 1. Admin-selected active Deal of the Day products
        $adminDeals = Product::with(['category', 'taxCategory', 'images'])
            ->where('is_deal_of_the_day', true)
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
            $defaultProducts = Product::with(['category', 'taxCategory', 'images'])
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

            $fallbackProducts = Product::with(['category', 'taxCategory', 'images'])
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
                return $this->formatProduct($product, $wishlistIds);
            })
            ->values()
            ->toArray();
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

    public function getTopDiscountedProducts(Request $request)
    {
        $limit = $request->get('limit', 10);
        $type = $request->get('type', 'all');
        $now = now();



        $products = Product::with(['category', 'taxCategory', 'images', 'primaryImage'])
            ->where('is_published', true)
            ->where('is_deal_of_the_day', true)
            ->where(function ($query) use ($now) {
                // Check if current time is between start and end date
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

        // dd([
        //     'now' => $now,
        //     'product' => $products->first()?->toArray(),
        // ]);

        // Calculate discount percentages and sort
        $productsWithDiscounts = $products->map(function ($product) use ($type) {
            $retailDiscount = $this->calculateDiscountPercentage($product, 'retail');
            $distributorDiscount = $this->calculateDiscountPercentage($product, 'distributor');

            // Get the highest discount among both
            $maxDiscount = max($retailDiscount, $distributorDiscount);

            // Determine discount type
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

        // Filter by type if specified
        if ($type === 'retail') {
            $productsWithDiscounts = $productsWithDiscounts->filter(function ($item) {
                return $item['retail_discount'] > 0;
            });
        } elseif ($type === 'distributor') {
            $productsWithDiscounts = $productsWithDiscounts->filter(function ($item) {
                return $item['distributor_discount'] > 0;
            });
        }

        // Sort by max discount (highest first)
        $productsWithDiscounts = $productsWithDiscounts->sortByDesc('max_discount')
            ->values()
            ->take($limit);

        // Format the response
        $formattedProducts = $productsWithDiscounts->map(function ($item) {
            $product = $item['product'];

            return [
                'product' => $this->formatProduct($product),
                'deal_info' => [
                    'starts_at' => $product->deal_of_the_day_starts_at?->toISOString(),
                    'ends_at' => $product->deal_of_the_day_ends_at?->toISOString(),
                    'is_active' => true,
                ],
                'discounts' => [
                    'retail' => [
                        'mrp' => $product->retail_mrp,
                        'price' => $product->retail_price,
                        'discount_amount' => $product->retail_mrp > 0 ? $product->retail_mrp - $product->retail_price : 0,
                        'discount_percentage' => $item['retail_discount'],
                        'has_discount' => $item['retail_discount'] > 0,
                    ],
                    'distributor' => [
                        'mrp' => $product->distributor_mrp,
                        'price' => $product->distributor_price,
                        'discount_amount' => $product->distributor_mrp > 0 && $product->distributor_price
                            ? $product->distributor_mrp - $product->distributor_price
                            : 0,
                        'discount_percentage' => $item['distributor_discount'],
                        'has_discount' => $item['distributor_discount'] > 0,
                    ],
                    'max_discount' => $item['max_discount'],
                    'discount_type' => $item['discount_type'],
                ]
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


    /**
     * Get product status
     */
    protected function getProductStatus($product)
    {
        if (!$product->is_published) return 'draft';
        if ($product->stock_quantity <= 0) return 'out_of_stock';
        if ($product->stock_quantity <= $product->low_stock_threshold) return 'low_stock';
        return 'active';
    }


    /**
     * Delete multiple images from a product
     */
    public function deleteImages(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'image_ids' => ['required', 'array'],
            'image_ids.*' => ['exists:product_images,id'],
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
            // Get images that belong to this product
            $imagesToDelete = ProductImage::whereIn('id', $request->image_ids)
                ->where('product_id', $product->id)
                ->get();

            if ($imagesToDelete->isEmpty()) {
                return response()->json([
                    'message' => 'No valid images found for this product'
                ], 404);
            }

            // Check if we're deleting the primary image
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

            DB::commit();

            // Reload product with images
            $product->load(['category', 'taxCategory', 'images']);

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
     * Delete a single image from a product
     */
    public function deleteImage(Request $request, Product $product, $imageId)
    {
        $image = ProductImage::where('id', $imageId)
            ->where('product_id', $product->id)
            ->first();

        if (!$image) {
            return response()->json([
                'message' => 'Image not found for this product'
            ], 404);
        }

        DB::beginTransaction();
        try {
            $wasPrimary = $image->is_primary;

            // Delete the image file
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

            DB::commit();

            $product->load(['category', 'taxCategory', 'images']);

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

    // public function trending()
    // {
    //     $products = Product::with('images')
    //         ->where('is_published', true)
    //         ->where('is_trending', true)
    //         ->orderBy('trending_sort_order', 'asc')
    //         ->get();

    //     $data = $products->map(function ($product) {
    //         return [
    //             'id' => $product->id,
    //             'name' => $product->name,
    //             'slug' => $product->slug,
    //             'description' => $product->description,

    //             'category_id' => $product->category_id,
    //             'tax_category_id' => $product->tax_category_id,

    //             // Retail pricing
    //             'retail_price' => $product->retail_price,
    //             'retail_mrp' => $product->retail_mrp,
    //             'retail_discount_type' => $product->retail_discount_type,
    //             'retail_discount_value' => $product->retail_discount_value,

    //             // Distributor pricing
    //             'distributor_price' => $product->distributor_price,
    //             'distributor_mrp' => $product->distributor_mrp,
    //             'distributor_discount_type' => $product->distributor_discount_type,
    //             'distributor_discount_value' => $product->distributor_discount_value,

    //             // Images
    //             'images' => $product->images->map(function ($image) {
    //                 return [
    //                     'id' => $image->id,
    //                     'image_url' => asset('storage/' . $image->image),
    //                     'is_primary' => (bool) $image->is_primary,
    //                     'sort_order' => $image->sort_order,
    //                 ];
    //             })->values()->toArray(),
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
        $products = Product::with(['images'])
            ->where('is_published', true)
            ->where('is_trending', true)
            ->orderBy('trending_sort_order', 'asc')
            ->get();

        $data = $products->map(function ($product) {
            // Get reviews for trending products
            $averageRating = ProductReview::where('product_id', $product->id)
                ->avg('rating');

            $totalReviews = ProductReview::where('product_id', $product->id)
                ->count();

            return [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'description' => $product->description,

                'category_id' => $product->category_id,
                'tax_category_id' => $product->tax_category_id,

                // Retail pricing
                'retail_price' => $product->retail_price,
                'retail_mrp' => $product->retail_mrp,
                'retail_discount_type' => $product->retail_discount_type,
                'retail_discount_value' => $product->retail_discount_value,

                // Distributor pricing
                'distributor_price' => $product->distributor_price,
                'distributor_mrp' => $product->distributor_mrp,
                'distributor_discount_type' => $product->distributor_discount_type,
                'distributor_discount_value' => $product->distributor_discount_value,

                // Review Summary
                'reviews' => [
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
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Trending products retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function getProductSections(Request $request)
    {
        // 1. NEW ARRIVALS - Products created within last 30 days
        $newArrivals = Product::with(['category', 'taxCategory', 'images'])
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

        $bestSellers = Product::with(['category', 'taxCategory', 'images'])
            ->whereIn('id', $bestSellerIds)
            ->where('is_published', true)
            ->get();

        // If no best sellers found, get default products
        if ($bestSellers->isEmpty()) {
            $bestSellers = Product::with(['category', 'taxCategory', 'images'])
                ->where('is_published', true)
                ->limit(8)
                ->get();
        }

        // 3. BEST OFFERS - Products with discounts based on user type
        $userType = $request->query('user_type', 'customer'); // 'customer' or 'distributor'

        $bestOffers = Product::with(['category', 'taxCategory', 'images'])
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
            ->limit(10) // You can adjust the limit
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
                $reviews = ProductReview::with([
                    'user' => function ($query) {
                        $query->select('id', 'full_name', 'profile_picture');
                    },
                    'images'
                ])
                    ->where('product_id', $product->id)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get();

                $averageRating = ProductReview::where('product_id', $product->id)
                    ->avg('rating');

                $totalReviews = ProductReview::where('product_id', $product->id)
                    ->count();

                // Get order count for best sellers
                $orderCount = DB::table('order_lines')
                    ->where('product_id', $product->id)
                    ->count();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->title,
                        'slug' => $product->category->slug,
                    ] : null,

                    // Price information based on user type
                    'original_price' => $originalPrice,
                    'current_price' => $currentPrice,
                    'discounted_price' => $discountedPrice,
                    // 'discount_value' => $discountValue,
                    // 'discount_type' => $discountType,
                    'discount_percentage' => $discountValue > 0 && $discountType === 'percentage' ? $discountValue : ($discountValue > 0 && $originalPrice > 0 ? round(($discountValue / $originalPrice) * 100) : 0),
                    'has_discount' => $discountValue > 0,

                    'is_published' => (bool) $product->is_published,
                    'is_trending' => (bool) $product->is_trending,
                    'is_wishlisted' => $isWishlisted,

                    // Section specific flags
                    'is_new_arrival' => $sectionType === 'new_arrivals',
                    'is_best_seller' => $sectionType === 'best_sellers',
                    'is_best_offer' => $sectionType === 'best_offers',

                    // For best sellers
                    'order_count' => $orderCount,

                    'reviews_summary' => [
                        'average_rating' => round($averageRating, 1),
                        'total_reviews' => $totalReviews,
                    ],

                    'primary_image_url' => $product->primaryImage ? asset('storage/' . $product->primaryImage->image) : null,
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
}
