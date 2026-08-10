<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
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
    public function showBySlug($slug)
    {
        $product = Product::with(['category', 'taxCategory', 'images'])
            ->where('slug', $slug)
            ->firstOrFail();

        $userId = request()->query('user_id');
        $wishlistIds = $this->getUserWishlistIds($userId);

        return response()->json($this->formatProduct($product, $wishlistIds));
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
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_code' => ['required', 'string', 'max:255', Rule::unique('products')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products')],
            'description' => ['nullable', 'string'],
            'specification' => ['nullable', 'string'],
            'category_id' => ['required', 'exists:categories,id'],
            'tax_category_id' => ['nullable', 'exists:tax_categories,id'],
            'retail_price' => ['required', 'numeric'],
            'distributor_price' => ['nullable', 'numeric'],
            'stock_quantity' => ['required', 'integer'],
            'low_stock_threshold' => ['nullable', 'integer'],
            'is_published' => ['nullable', 'boolean'],
            'product_images' => ['nullable', 'array'],
            'product_images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
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

            // Generate slug if not provided
            if (empty($productData['slug'])) {
                $productData['slug'] = Str::slug($productData['name']);
            }
            $productData['slug'] = $this->generateUniqueSlug($productData['slug']);
            $productData['is_published'] = $productData['is_published'] ?? false;
            $productData['low_stock_threshold'] = $productData['low_stock_threshold'] ?? 5;

            // Create product
            $product = Product::create($productData);

            // Handle images
            $productImages = $request->input('product_images', []);
            $imageCount = 0;
            $hasPrimary = false;

            // Debug: Log received images
            Log::info('Product images received:', [
                'count' => count($productImages),
                'images' => $productImages
            ]);

            if (!empty($productImages)) {
                foreach ($productImages as $index => $imageData) {
                    // Check if image file exists in request
                    $imageFile = $request->file("product_images.{$index}.image");

                    // Debug: Log each image
                    Log::info("Processing image {$index}:", [
                        'has_file' => $imageFile ? 'yes' : 'no',
                        'is_valid' => $imageFile && $imageFile->isValid() ? 'yes' : 'no',
                        'file_name' => $imageFile ? $imageFile->getClientOriginalName() : 'null'
                    ]);

                    if ($imageFile && $imageFile->isValid()) {
                        $path = $imageFile->store('products', 'public');
                        $sortOrder = isset($imageData['sort_order']) ? (int) $imageData['sort_order'] : $imageCount;

                        // Determine if this should be primary
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

                // If no primary image was set, set the first image as primary
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
    //         'is_wishlisted' => $isWishlisted, // Add this field
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
            'retail_price' => $product->retail_price,
            'retail_price_formatted' => number_format($product->retail_price, 2),
            'distributor_price' => $product->distributor_price,
            'distributor_price_formatted' => $product->distributor_price ? number_format($product->distributor_price, 2) : null,
            'stock_quantity' => (int) $product->stock_quantity,
            'low_stock_threshold' => (int) $product->low_stock_threshold,
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

    /**
     * Update an existing product
     */
    public function update(Request $request, $id)
    {
        $product = Product::where('id', $id)->first();
        if (!$product) {
            return response()->json([
                'message' => 'Product not found'
            ], 422);
        }

        // DEBUG: Log incoming request
        Log::info('=== UPDATE REQUEST DEBUG ===');
        Log::info('All request data:', $request->all());
        Log::info('All files:', array_keys($_FILES));

        $validator = Validator::make($request->all(), [
            'product_code' => ['required', 'string', 'max:255', Rule::unique('products')->ignore($product->id)],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products')->ignore($product->id)],
            'description' => ['nullable', 'string'],
            'specification' => ['nullable', 'string'],
            'category_id' => ['required', 'exists:categories,id'],
            'tax_category_id' => ['nullable', 'exists:tax_categories,id'],
            'retail_price' => ['required', 'numeric', 'min:0'],
            'distributor_price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'is_published' => ['nullable', 'boolean'],
            'product_images' => ['nullable', 'array'],
            'product_images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
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

            // Handle slug
            if (empty($productData['slug'])) {
                $productData['slug'] = Str::slug($productData['name']);
            }
            if ($productData['slug'] !== $product->slug) {
                $productData['slug'] = $this->generateUniqueSlug($productData['slug'], $product->id);
            }
            $productData['low_stock_threshold'] = $productData['low_stock_threshold'] ?? 5;

            // Update product
            $product->update($productData);

            // Remove specified images
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

            // Handle images - IMPROVED VERSION
            $productImages = $request->input('product_images', []);
            Log::info('Product images input:', $productImages);

            if (!empty($productImages)) {
                $existingCount = $product->images()->count();
                $hasPrimary = $product->images()->where('is_primary', true)->exists();
                $imageCount = 0;

                foreach ($productImages as $index => $imageData) {
                    // Try multiple ways to get the file
                    $imageFile = $request->file("product_images.{$index}.image");

                    // If not found, try alternative key format
                    if (!$imageFile) {
                        $imageFile = $request->file("product_images.{$index}.image");
                    }

                    Log::info("Processing image {$index}:", [
                        'has_file' => $imageFile ? 'yes' : 'no',
                        'is_valid' => $imageFile && $imageFile->isValid() ? 'yes' : 'no',
                        'file_name' => $imageFile ? $imageFile->getClientOriginalName() : 'null',
                        'image_data' => $imageData
                    ]);

                    if ($imageFile && $imageFile->isValid()) {
                        $path = $imageFile->store('products', 'public');

                        // Get sort order - use provided or auto-increment
                        $sortOrder = isset($imageData['sort_order'])
                            ? (int) $imageData['sort_order']
                            : $existingCount + $imageCount;

                        // Determine primary status
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

                // If no primary image was set, set the first image as primary
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
    //             'retail_price' => $product->retail_price,
    //             'distributor_price' => $product->distributor_price,
    //             'stock_quantity' => $product->stock_quantity,
    //             'low_stock_threshold' => $product->low_stock_threshold,
    //             'stock_status' => $product->stock_status,
    //             'is_published' => $product->is_published,
    //             'is_wishlisted' => $isWishlisted, // Add this field
    //             'images' => $product->images->map(function ($image) {
    //                 return [
    //                     'id' => $image->id,
    //                     'image' => asset('storage/' . $image->image),
    //                     'is_primary' => $image->is_primary,
    //                     'sort_order' => $image->sort_order,
    //                 ];
    //             })->values()->toArray(),
    //             'primary_image' => $product->primaryImage ? asset('storage/' . $product->primaryImage->image) : null,
    //             'image_urls' => $product->image_urls,
    //             'created_at' => $product->created_at,
    //             'updated_at' => $product->updated_at,
    //         ];
    //     })->values()->toArray();
    // }

    protected function formatProductCollection($products, $wishlistIds = [])
    {
        return $products->map(function ($product) use ($wishlistIds) {
            $isWishlisted = in_array($product->id, $wishlistIds);

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
                'retail_price' => $product->retail_price,
                'retail_price_formatted' => number_format($product->retail_price, 2),
                'distributor_price' => $product->distributor_price,
                'distributor_price_formatted' => $product->distributor_price ? number_format($product->distributor_price, 2) : null,
                'stock_quantity' => (int) $product->stock_quantity,
                'low_stock_threshold' => (int) $product->low_stock_threshold,
                'stock_status' => $this->getProductStatus($product),
                'is_published' => (bool) $product->is_published,
                'is_wishlisted' => $isWishlisted,
                'images' => $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => asset('storage/' . $image->image),
                        'is_primary' => (bool) $image->is_primary,
                        'sort_order' => $image->sort_order,
                    ];
                })->values()->toArray(),
                'primary_image_url' => $product->primaryImage ? asset('storage/' . $product->primaryImage->image) : null,
                'created_at' => $product->created_at?->toISOString(),
                'updated_at' => $product->updated_at?->toISOString(),
            ];
        })->values()->toArray();
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
}
