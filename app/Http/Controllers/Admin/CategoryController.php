<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Traits\AuditLogTrait;

class CategoryController extends Controller
{
    use AuditLogTrait;
    /**
     * Display a listing of categories.
     */

    // public function index(Request $request)
    // {
    //     try {
    //         $query = Category::query();

    //         // Search by title
    //         if ($request->has('search')) {
    //             $query->where('title', 'like', '%' . $request->search . '%')
    //                 ->orWhere('description', 'like', '%' . $request->search . '%');
    //         }

    //         // Filter by status
    //         if ($request->has('status')) {
    //             $query->where('status', $request->status);
    //         }

    //         // Eager load products for efficiency
    //         $query->with(['products' => function ($q) {
    //             $q->whereNull('deleted_at'); // Only non-deleted products
    //         }]);

    //         // Sorting
    //         $sortField = $request->get('sort_by', 'created_at');
    //         $sortDirection = $request->get('sort_direction', 'desc');
    //         $query->orderBy($sortField, $sortDirection);

    //         // Pagination
    //         $perPage = $request->get('per_page', 10);
    //         $categories = $query->paginate($perPage);

    //         // Find the most expensive product across ALL categories
    //         $allProducts = collect();
    //         $categories->each(function ($category) use (&$allProducts) {
    //             $allProducts = $allProducts->merge($category->products);
    //         });

    //         $mostExpensiveProduct = $allProducts->sortByDesc('distributor_price')
    //             ->first() ??
    //             $allProducts->sortByDesc('retail_price')
    //             ->first();

    //         $mostExpensivePrice = $mostExpensiveProduct ?
    //             ($mostExpensiveProduct->retail_price ?? $mostExpensiveProduct->distributor_price ?? 0) :
    //             0;

    //         // Transform data with product count and per category max price
    //         $data = $categories->map(function ($category) {
    //             $products = $category->products;
    //             $productsCount = $products->count();

    //             // Find most expensive product in this category
    //             $maxPriceProduct = $products->sortByDesc(function ($product) {
    //                 // Get the highest price between retail and distributor
    //                 return max($product->retail_price ?? 0, $product->distributor_price ?? 0);
    //             })->first();

    //             // Get max price from the product
    //             $maxPrice = $maxPriceProduct ?
    //                 max($maxPriceProduct->retail_price ?? 0, $maxPriceProduct->distributor_price ?? 0) :
    //                 0;

    //             return [
    //                 'id' => $category->id,
    //                 'title' => $category->title,
    //                 'image' => $category->image
    //                     ? asset('storage/categories/' . $category->image)
    //                     : null,
    //                 'description' => $category->description,
    //                 'status' => $category->status,
    //                 'created_at' => $category->created_at,
    //                 'updated_at' => $category->updated_at,
    //                 'products_count' => $productsCount,
    //                 'max_price' => $maxPrice, // Per category max price
    //                 'max_price_formatted' => number_format($maxPrice, 2), // Formatted price
    //                 'max_price_product' => $maxPriceProduct ? [ // Most expensive product details
    //                     'id' => $maxPriceProduct->id,
    //                     'name' => $maxPriceProduct->name,
    //                     'product_code' => $maxPriceProduct->product_code,
    //                     'retail_price' => $maxPriceProduct->retail_price,
    //                     'distributor_price' => $maxPriceProduct->distributor_price,
    //                 ] : null,
    //             ];
    //         });

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Categories retrieved successfully',
    //             'data' => $data,
    //             'most_expensive_price' => $mostExpensivePrice, // Overall most expensive price
    //             'meta' => [
    //                 'current_page' => $categories->currentPage(),
    //                 'per_page' => $categories->perPage(),
    //                 'total' => $categories->total(),
    //                 'last_page' => $categories->lastPage(),
    //             ]
    //         ], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to retrieve categories',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function index(Request $request)
    {
        try {
            $query = Category::query();

            // Search by title
            if ($request->has('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('title', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%');
                });
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Eager load products for efficiency
            $query->with(['products' => function ($q) {
                $q->whereNull('deleted_at');
            }]);

            // Sorting
            $sortField = $request->get('sort_by', 'created_at');
            $sortDirection = $request->get('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            // Pagination
            $perPage = $request->get('per_page', 10);
            $categories = $query->paginate($perPage);

            // Find the most expensive product across ALL categories
            $allProducts = collect();

            $categories->each(function ($category) use (&$allProducts) {
                $allProducts = $allProducts->merge($category->products);
            });

            $mostExpensiveProduct = $allProducts->sortByDesc('distributor_price')
                ->first() ??
                $allProducts->sortByDesc('retail_price')
                ->first();

            $mostExpensivePrice = $mostExpensiveProduct
                ? ($mostExpensiveProduct->retail_price
                    ?? $mostExpensiveProduct->distributor_price
                    ?? 0)
                : 0;

            // Transform category data
            $data = $categories->map(function ($category) {

                $products = $category->products;
                $productsCount = $products->count();

                // Find most expensive product in this category
                $maxPriceProduct = $products->sortByDesc(function ($product) {
                    return max(
                        $product->retail_price ?? 0,
                        $product->distributor_price ?? 0
                    );
                })->first();

                $maxPrice = $maxPriceProduct
                    ? max(
                        $maxPriceProduct->retail_price ?? 0,
                        $maxPriceProduct->distributor_price ?? 0
                    )
                    : 0;

                return [
                    'id' => $category->id,
                    'title' => $category->title,
                    'image' => $category->image
                        ? asset('storage/categories/' . $category->image)
                        : null,
                    'description' => $category->description,
                    'status' => $category->status,
                    'created_at' => $category->created_at,
                    'updated_at' => $category->updated_at,
                    'products_count' => $productsCount,
                    'max_price' => $maxPrice,
                    'max_price_formatted' => number_format($maxPrice, 2),

                    'max_price_product' => $maxPriceProduct ? [
                        'id' => $maxPriceProduct->id,
                        'name' => $maxPriceProduct->name,
                        'product_code' => $maxPriceProduct->product_code,
                        'retail_price' => $maxPriceProduct->retail_price,
                        'distributor_price' => $maxPriceProduct->distributor_price,
                    ] : null,
                ];
            });

            // Get all brands
            $brands = Brand::select('id', 'title')
                ->orderBy('title', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Categories retrieved successfully',

                // Categories
                'data' => $data,

                // Brands
                'brands' => $brands,

                // Overall most expensive price
                'most_expensive_price' => $mostExpensivePrice,

                // Pagination
                'meta' => [
                    'current_page' => $categories->currentPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                    'last_page' => $categories->lastPage(),
                ]
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Store a newly created category.
     */
    public function store(Request $request)
    {
        try {
            // Validation
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'slug' => 'nullable|string|max:255|unique:categories,slug',
                'description' => 'nullable|string',
                'image' => 'nullable|mimes:jpeg,png,jpg,gif,svg,webp,avif',
                'status' => 'nullable|in:active,inactive',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $data = $validator->validated();

            // Generate slug if not provided
            if (empty($data['slug'])) {
                $data['slug'] = Str::slug($data['title']);
            }

            // Check if generated slug already exists
            if (Category::where('slug', $data['slug'])->exists()) {
                $data['slug'] = $data['slug'] . '-' . time();
            }

            // Handle image upload
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $imageName = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $image->storeAs('categories', $imageName, 'public');
                $data['image'] = $imageName;
            }

            // Set default status if not provided
            if (empty($data['status'])) {
                $data['status'] = 'active';
            }

            $category = Category::create($data);

            DB::commit();
            $this->logAudit(
                'category_create',
                'catalogue',
                null,
                [
                    'category_id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'parent_id' => $category->parent_id,
                    'parent_name' => $category->parent?->name,
                    'description' => $category->description,
                    'is_active' => $category->is_active,
                    'sort_order' => $category->sort_order,
                    'has_image' => !is_null($category->image),
                    'created_by' => $this->getAdminId(),
                    'created_at' => now()->toDateTimeString(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Category created successfully',
                'data' => new CategoryResource($category)
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create category',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display the specified category.
     */
    public function show($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Category retrieved successfully',
                'data' => new CategoryResource($category)
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, $id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            // Validation
            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'slug' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('categories')->ignore($category->id),
                ],
                'description' => 'nullable|string',
                'image' => 'nullable|mimes:jpeg,png,jpg,gif,svg,webp,avif',
                'status' => 'nullable|in:active,inactive',
            ]);

            $oldValues = [
                'category_id' => $category->id,
                'name' => $category->title,
                'slug' => $category->slug,
                'description' => $category->description,
                'status' => $category->status,
                'sort_order' => $category->sort_order,
                'icon' => $category->icon,
                'has_image' => !is_null($category->image)
            ];

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $data = $validator->validated();

            // Handle slug
            if (!empty($data['title']) && empty($data['slug'])) {
                $data['slug'] = Str::slug($data['title']);

                // Check if generated slug already exists (and it's not the current category)
                if (Category::where('slug', $data['slug'])->where('id', '!=', $category->id)->exists()) {
                    $data['slug'] = $data['slug'] . '-' . time();
                }
            }

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image
                if ($category->image) {
                    Storage::disk('public')->delete('categories/' . $category->image);
                }

                $image = $request->file('image');
                $imageName = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $image->storeAs('categories', $imageName, 'public');
                $data['image'] = $imageName;
            }

            // Remove image field if not provided (keep existing)
            if (!$request->hasFile('image') && !$request->has('image')) {
                unset($data['image']);
            }
            $newValues = [
                'category_id' => $category->id,
                'name' => $category->title,
                'slug' => $category->slug,
                'description' => $category->description,
                'is_active' => $category->is_active,
                'has_image' => !is_null($category->image),
                'meta_title' => $category->meta_title,
            ];
            $category->update($data);

            DB::commit();
            $this->logAudit(
                'category_update',
                'catalogue',
                $oldValues,
                $newValues
            );
            return response()->json([
                'success' => true,
                'message' => 'Category updated successfully',
                'data' => new CategoryResource($category)
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified category.
     */
    public function destroy($id)
    {
        try {
            DB::beginTransaction();

            $category = Category::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            // Delete image
            if ($category->image) {
                Storage::disk('public')->delete('categories/' . $category->image);
            }

            $category->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Category deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete category',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update category status.
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:active,inactive'
            ]);

            $category = Category::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            $category->update([
                'status' => $request->status
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Category status updated successfully',
                'data' => new CategoryResource($category)
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update category status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk delete categories.
     */
    public function bulkDelete(Request $request)
    {
        try {
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'exists:categories,id'
            ]);

            DB::beginTransaction();

            $categories = Category::whereIn('id', $request->ids)->get();

            // Delete all images
            foreach ($categories as $category) {
                if ($category->image) {
                    Storage::disk('public')->delete('categories/' . $category->image);
                }
            }

            Category::whereIn('id', $request->ids)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Categories deleted successfully',
                'deleted_count' => count($request->ids)
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function deleteImage($id)
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Category not found'
                ], 404);
            }

            // Check if category has an image
            if (!$category->image) {
                return response()->json([
                    'success' => false,
                    'message' => 'No image found for this category'
                ], 404);
            }

            DB::beginTransaction();

            // Delete the image file from storage
            $deleted = Storage::disk('public')->delete('categories/' . $category->image);

            if (!$deleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete image file'
                ], 500);
            }

            // Remove image reference from database
            $category->update(['image' => null]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Category image deleted successfully',
                'data' => new CategoryResource($category)
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete category image',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
