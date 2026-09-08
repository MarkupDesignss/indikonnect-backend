<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubcategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Subcategory::with('category');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Sort by created_at or name
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $subcategories = $query->paginate($request->get('per_page', 15));

        // Transform the data to include full image URL
        $subcategories->getCollection()->transform(function ($subcategory) {
            $subcategory->image_url = $subcategory->image ? asset('storage/' . $subcategory->image) : null;
            return $subcategory;
        });

        return response()->json([
            'success' => true,
            'data' => $subcategories,
            'message' => 'Subcategories retrieved successfully'
        ]);
    }

    /**
     * Store a new subcategory
     */
    public function store(Request $request): JsonResponse
    {
        // Validation rules - image as file
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:subcategories,slug',
            'image' => 'nullable',
            'status' => 'nullable|boolean'
        ], [
            'category_id.required' => 'Category ID is required',
            'category_id.exists' => 'Selected category does not exist',
            'name.required' => 'Subcategory name is required',
            'slug.required' => 'Slug is required',
            'slug.unique' => 'This slug is already taken',
            'image.image' => 'The file must be an image',
            'image.mimes' => 'The image must be a file of type: jpeg, png, jpg, gif, svg, webp',
            'image.max' => 'The image may not be greater than 2MB',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('subcategories', 'public');
            $validated['image'] = $imagePath;
        }

        // Set default status if not provided
        if (!isset($validated['status'])) {
            $validated['status'] = true;
        }

        $subcategory = Subcategory::create($validated);

        return response()->json([
            'success' => true,
            'data' => $subcategory->load('category'),
            'message' => 'Subcategory created successfully'
        ], 201);
    }

    /**
     * Get a single subcategory
     */
    public function show($id): JsonResponse
    {
        $subcategory = Subcategory::with('category')->find($id);

        if (!$subcategory) {
            return response()->json([
                'success' => false,
                'message' => 'Subcategory not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $subcategory,
            'message' => 'Subcategory retrieved successfully'
        ]);
    }

    /**
     * Update a subcategory
     */
    public function update(Request $request, $id): JsonResponse
    {
        $subcategory = Subcategory::find($id);

        if (!$subcategory) {
            return response()->json([
                'success' => false,
                'message' => 'Subcategory not found'
            ], 404);
        }

        // Validation rules - image as file (nullable for updates)
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('subcategories', 'slug')->ignore($id)
            ],
            'image' => 'nullable', // Image validation
            'status' => 'nullable|boolean'
        ], [
            'category_id.required' => 'Category ID is required',
            'category_id.exists' => 'Selected category does not exist',
            'name.required' => 'Subcategory name is required',
            'slug.required' => 'Slug is required',
            'slug.unique' => 'This slug is already taken',
            'image.image' => 'The file must be an image',
            'image.mimes' => 'The image must be a file of type: jpeg, png, jpg, gif, svg, webp',
            'image.max' => 'The image may not be greater than 2MB',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($subcategory->image && Storage::disk('public')->exists($subcategory->image)) {
                Storage::disk('public')->delete($subcategory->image);
            }

            $imagePath = $request->file('image')->store('subcategories', 'public');
            $validated['image'] = $imagePath;
        }

        $subcategory->update($validated);

        return response()->json([
            'success' => true,
            'data' => $subcategory->fresh()->load('category'),
            'message' => 'Subcategory updated successfully'
        ]);
    }

    /**
     * Toggle status (active/inactive)
     */
    public function toggleStatus(Request $request, $id): JsonResponse
    {
        $subcategory = Subcategory::find($id);

        if (!$subcategory) {
            return response()->json([
                'success' => false,
                'message' => 'Subcategory not found'
            ], 404);
        }

        // Validate status from request
        $validator = Validator::make($request->all(), [
            'status' => 'required|boolean'
        ], [
            'status.required' => 'Status is required',
            'status.boolean' => 'Status must be 0 or 1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Update status with the value from request
        $subcategory->update(['status' => $request->status]);

        // Add full image URL if needed
        $subcategory->image_url = $subcategory->image ? asset('storage/' . $subcategory->image) : null;

        return response()->json([
            'success' => true,
            'data' => $subcategory->load('category'),
            'message' => $request->status ? 'Subcategory activated successfully' : 'Subcategory deactivated successfully'
        ]);
    }

    /**
     * Get subcategories by category
     */
    public function getByCategory($categoryId): JsonResponse
    {
        $subcategories = Subcategory::with('category')
            ->where('category_id', $categoryId)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subcategories,
            'message' => 'Subcategories retrieved successfully'
        ]);
    }
}
