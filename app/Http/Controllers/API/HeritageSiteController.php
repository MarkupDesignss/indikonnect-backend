<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\HeritageSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HeritageSiteController extends Controller
{
    /**
     * Validation rules for store operation.
     */
    protected function storeValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|mimes:jpeg,png,jpg,gif,avif,webp|max:5120',
            'category' => 'nullable|string|max:100',
        ];
    }

    /**
     * Validation rules for update operation.
     */
    protected function updateValidationRules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|mimes:jpeg,png,jpg,gif,avif,webp|max:5120',
            'category' => 'nullable|string|max:100',
        ];
    }

    /**
     * Validation messages.
     */
    protected function validationMessages(): array
    {
        return [
            'title.required' => 'The heritage site title is required.',
            'title.max' => 'The title must not exceed 255 characters.',
            'location.required' => 'The location is required.',
            'state.required' => 'The state is required.',
            'image.image' => 'The file must be an image.',
            'image.mimes' => 'The image must be a file of type: jpeg, png, jpg, gif, avif, webp.',
            'image.max' => 'The image must not be larger than 5MB.',
            'category.max' => 'The category must not exceed 100 characters.',
        ];
    }

    /**
     * Upload and store image.
     */
    /**
     * Upload and store image.
     */
    protected function uploadImage($image, string $folder = 'heritage-sites'): string
    {
        // Generate unique filename
        $filename = Str::uuid() . '.' . $image->getClientOriginalExtension();
        $path = $image->storeAs($folder, $filename, 'public');

        // Return just the path without 'storage/' prefix
        // Storage::url() will add the proper URL when accessed
        return $path; // This returns something like "heritage-sites/filename.avif"
    }
    /**
     * Delete image from storage.
     */
    protected function deleteImage(?string $imageUrl): void
    {
        if ($imageUrl) {
            // Remove domain and /storage/ prefix to get the actual path
            $path = str_replace('/storage/', '', parse_url($imageUrl, PHP_URL_PATH));

            // Also handle if URL has full domain
            if (strpos($path, 'storage/') === 0) {
                $path = substr($path, 8); // Remove 'storage/' prefix
            }

            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    /**
     * Display a listing of all heritage sites.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = HeritageSite::query();

            // Filter by state if provided
            if ($request->has('state') && $request->state) {
                $query->byState($request->state);
            }

            // Filter by category if provided
            if ($request->has('category') && $request->category) {
                $query->byCategory($request->category);
            }

            // Search by title if provided
            if ($request->has('search') && $request->search) {
                $query->where('title', 'LIKE', '%' . $request->search . '%');
            }

            $sites = $query->orderBy('id', 'asc')->get();

            // Transform the collection to include full image URL
            $sites->transform(function ($site) {
                return [
                    'id' => $site->id,
                    'title' => $site->title,
                    'subtitle' => $site->subtitle,
                    'location' => $site->location,
                    'state' => $site->state,
                    'description' => $site->description,
                    // 'image' => $site->image,
                    'image_url' => $site->image_url,
                    'category' => $site->category,
                    'full_location' => $site->full_location,
                    'created_at' => $site->created_at,
                    'updated_at' => $site->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $sites,
                'message' => 'Heritage sites retrieved successfully',
                'count' => $sites->count()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving heritage sites',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Store a newly created heritage site.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validate the request
            $validator = validator($request->all(), $this->storeValidationRules(), $this->validationMessages());

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $validatedData = $validator->validated();

            // Handle image upload
            if ($request->hasFile('image')) {
                $validatedData['image'] = $this->uploadImage($request->file('image'));
            }

            // Set default category if not provided
            if (empty($validatedData['category'])) {
                $validatedData['category'] = 'HERITAGE';
            }

            $site = HeritageSite::create($validatedData);

            return response()->json([
                'success' => true,
                'data' => $site,
                'message' => 'Heritage site created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error creating heritage site',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified heritage site.
     */
    public function show(string $id): JsonResponse
    {
        try {
            // Validate ID
            $validator = validator(['id' => $id], [
                'id' => 'required|integer|exists:heritage_sites,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid heritage site ID',
                    'errors' => $validator->errors()
                ], 404);
            }

            $site = HeritageSite::find($id);

            return response()->json([
                'success' => true,
                'data' => $site,
                'message' => 'Heritage site retrieved successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving heritage site',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified heritage site.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            // Validate ID exists
            $idValidator = validator(['id' => $id], [
                'id' => 'required|integer|exists:heritage_sites,id'
            ]);

            if ($idValidator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Heritage site not found',
                    'errors' => $idValidator->errors()
                ], 404);
            }

            // Validate the request data
            $validator = validator($request->all(), $this->updateValidationRules(), $this->validationMessages());

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $site = HeritageSite::find($id);
            $validatedData = $validator->validated();

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($site->image) {
                    $this->deleteImage($site->image);
                }
                $validatedData['image'] = $this->uploadImage($request->file('image'));
            }

            // Set default category if not provided and not already set
            if (empty($validatedData['category']) && !$request->has('category')) {
                $validatedData['category'] = 'HERITAGE';
            }

            $site->update($validatedData);

            return response()->json([
                'success' => true,
                'data' => $site->fresh(),
                'message' => 'Heritage site updated successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating heritage site',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified heritage site.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            // Validate ID exists
            $validator = validator(['id' => $id], [
                'id' => 'required|integer|exists:heritage_sites,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Heritage site not found',
                    'errors' => $validator->errors()
                ], 404);
            }

            $site = HeritageSite::find($id);

            // Delete associated image
            if ($site->image) {
                $this->deleteImage($site->image);
            }

            $site->delete();

            return response()->json([
                'success' => true,
                'message' => 'Heritage site deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error deleting heritage site',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
