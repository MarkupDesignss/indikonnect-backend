<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
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

        return response()->json([
            'data' => $this->formatProductCollection($products),
            'pagination' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
        ]);
    }

    /**
     * Get a single product by ID
     */
    public function show(Product $product)
    {
        $product->load(['category', 'taxCategory', 'images']);
        return response()->json($this->formatProduct($product));
    }

    /**
     * Get product by slug
     */
    public function showBySlug($slug)
    {
        $product = Product::with(['category', 'taxCategory', 'images'])
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json($this->formatProduct($product));
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

        // DB::beginTransaction();
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
            if (!empty($productImages)) {
                foreach ($productImages as $index => $imageData) {
                    // Check if image file exists in request
                    $imageFile = $request->file("product_images.{$index}.image");
                    if ($imageFile) {
                        $path = $imageFile->store('products', 'public');
                        $sortOrder = isset($imageData['sort_order']) ? (int) $imageData['sort_order'] : $index;
                        $isPrimary = isset($imageData['is_primary']) ? (bool) $imageData['is_primary'] : ($index === 0);
                        ProductImage::create([
                            'product_id' => $product->id,
                            'image' => $path,
                            'is_primary' => $isPrimary,
                            'sort_order' => $sortOrder,
                        ]);
                    }
                }
            }
            // DB::commit();
            $product->load(['category', 'taxCategory', 'images']);

            return response()->json($this->formatProduct($product), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create product',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format a single product
     */
    protected function formatProduct($product)
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
                'name' => $product->category->name,
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
    public function update(Request $request, Product $product)
    {
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
            'product_images.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp'],
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

            // Handle images
            $productImages = $request->input('product_images', []);
            if (!empty($productImages)) {
                $existingCount = $product->images()->count();

                foreach ($productImages as $index => $imageData) {
                    // Check if image file exists in request
                    $imageFile = $request->file("product_images.{$index}.image");

                    if ($imageFile) {
                        $path = $imageFile->store('products', 'public');
                        $sortOrder = isset($imageData['sort_order']) ? (int) $imageData['sort_order'] : ($existingCount + $index);
                        $isPrimary = isset($imageData['is_primary']) ? (bool) $imageData['is_primary'] : ($existingCount === 0 && $index === 0);

                        ProductImage::create([
                            'product_id' => $product->id,
                            'image' => $path,
                            'is_primary' => $isPrimary,
                            'sort_order' => $sortOrder,
                        ]);
                    }
                }
            }

            DB::commit();
            $product->load(['category', 'taxCategory', 'images']);

            return response()->json($this->formatProduct($product));
        } catch (\Exception $e) {
            DB::rollBack();
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
    // protected function formatProductCollection($products)
    // {
    //     return $products->map(fn($product) => $this->formatProduct($product))->values()->toArray();
    // }

    protected function formatProductCollection($products)
    {
        return $products->map(function ($product) {
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
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
                'tax_category_id' => $product->tax_category_id,
                'tax_category' => $product->taxCategory ? [
                    'id' => $product->taxCategory->id,
                    'name' => $product->taxCategory->name,
                    'rate' => $product->taxCategory->rate,
                ] : null,
                'retail_price' => $product->retail_price,
                'distributor_price' => $product->distributor_price,
                'stock_quantity' => $product->stock_quantity,
                'low_stock_threshold' => $product->low_stock_threshold,
                'stock_status' => $product->stock_status, // Add stock status
                'is_published' => $product->is_published,
                'images' => $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image' => asset('storage/' . $image->image),
                        'is_primary' => $image->is_primary,
                        'sort_order' => $image->sort_order,
                    ];
                })->values()->toArray(),
                'primary_image' => $product->primaryImage ? asset('storage/' . $product->primaryImage->image) : null,
                'image_urls' => $product->image_urls, // If you have this accessor
                'created_at' => $product->created_at,
                'updated_at' => $product->updated_at,
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
}