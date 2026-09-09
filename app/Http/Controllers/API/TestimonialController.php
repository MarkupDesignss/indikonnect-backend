<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class TestimonialController extends Controller
{
    /**
     * Display a listing of testimonials
     */
    public function index(Request $request): JsonResponse
    {
        $query = Testimonial::query();

        // Filter by active status
        if ($request->has('active')) {
            $query->where(
                'is_active',
                filter_var($request->active, FILTER_VALIDATE_BOOLEAN)
            );
        }

        // Search functionality
        if ($request->has('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('person_name', 'LIKE', "%{$search}%")
                    ->orWhere('video_title', 'LIKE', "%{$search}%")
                    ->orWhere('heading', 'LIKE', "%{$search}%")
                    ->orWhere('text', 'LIKE', "%{$search}%");
            });
        }

        $testimonials = $query
            ->ordered()
            ->paginate($request->per_page ?? 15);

        // Convert video_path to complete URL
        $testimonials->getCollection()->transform(function ($testimonial) {
            if ($testimonial->video_path) {
                $testimonial->video_path = asset(
                    'storage/' . ltrim($testimonial->video_path, '/')
                );
            }

            return $testimonial;
        });

        return response()->json([
            'success' => true,
            'data' => $testimonials,
            'message' => 'Testimonials retrieved successfully'
        ]);
    }

    /**
     * Store a newly created testimonial
     */
    public function store(Request $request): JsonResponse
    {
        // Validation rules
        $validator = Validator::make($request->all(), [
            'video' => 'required|file|mimes:mp4,mov,avi,wmv|max:102400', // Max 100MB
            'video_title' => 'required|string|max:255',
            'person_name' => 'required|string|max:255',
            'heading' => 'required|string|max:255',
            'rating' => 'required|numeric|min:0|max:10',
            'text' => 'required|string|min:10',
            'is_active' => 'sometimes|boolean',
            'display_order' => 'sometimes|integer|min:0'
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Handle video upload
            $videoPath = null;
            if ($request->hasFile('video')) {
                $video = $request->file('video');
                $videoPath = $video->store('testimonials/videos', 'public');
            }

            // Create testimonial with video path
            $testimonial = Testimonial::create([
                'video_path' => $videoPath,
                'video_title' => $request->video_title,
                'person_name' => $request->person_name,
                'heading' => $request->heading,
                'rating' => $request->rating,
                'text' => $request->text,
                'is_active' => $request->is_active ?? true,
                'display_order' => $request->display_order ?? 0
            ]);

            return response()->json([
                'success' => true,
                'data' => $testimonial,
                'message' => 'Testimonial created successfully'
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create testimonial',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified testimonial
     */
    public function show(Testimonial $testimonial): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $testimonial,
            'message' => 'Testimonial retrieved successfully'
        ]);
    }

    /**
     * Update the specified testimonial
     */
    public function update(Request $request, Testimonial $testimonial): JsonResponse
    {
        // Validation rules (all fields optional for update)
        $validator = Validator::make($request->all(), [
            'video' => 'sometimes|file|mimes:mp4,mov,avi,wmv|max:102400',
            'video_title' => 'sometimes|string|max:255',
            'person_name' => 'sometimes|string|max:255',
            'heading' => 'sometimes|string|max:255',
            'rating' => 'sometimes|numeric|min:0|max:10',
            'text' => 'sometimes|string|min:10',
            'is_active' => 'sometimes|boolean',
            'display_order' => 'sometimes|integer|min:0'
        ]);

        // Check if validation fails
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->except(['video']);

            // Handle video upload if present
            if ($request->hasFile('video')) {
                // Delete old video if exists
                if ($testimonial->video_path && Storage::disk('public')->exists($testimonial->video_path)) {
                    Storage::disk('public')->delete($testimonial->video_path);
                }

                $video = $request->file('video');
                $data['video_path'] = $video->store('testimonials/videos', 'public');
            }

            $testimonial->update($data);

            return response()->json([
                'success' => true,
                'data' => $testimonial->fresh(),
                'message' => 'Testimonial updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update testimonial',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified testimonial
     */
    public function destroy(Testimonial $testimonial): JsonResponse
    {
        try {
            // Delete video file if exists
            if ($testimonial->video_path && Storage::disk('public')->exists($testimonial->video_path)) {
                Storage::disk('public')->delete($testimonial->video_path);
            }

            $testimonial->delete();

            return response()->json([
                'success' => true,
                'message' => 'Testimonial deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete testimonial',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update display order
     */
    public function updateOrder(Request $request): JsonResponse
    {
        // Validation for order update
        $validator = Validator::make($request->all(), [
            'orders' => 'required|array',
            'orders.*.id' => 'required|exists:testimonials,id',
            'orders.*.display_order' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            foreach ($request->orders as $order) {
                Testimonial::where('id', $order['id'])
                    ->update(['display_order' => $order['display_order']]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Testimonials reordered successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle testimonial active status
     */
    public function toggleActive(Testimonial $testimonial): JsonResponse
    {
        try {
            $testimonial->is_active = !$testimonial->is_active;
            $testimonial->save();

            return response()->json([
                'success' => true,
                'data' => $testimonial,
                'message' => 'Testimonial status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get active testimonials for frontend
     */
    public function getActive(): JsonResponse
    {
        $testimonials = Testimonial::active()->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $testimonials,
            'message' => 'Active testimonials retrieved successfully'
        ]);
    }

    /**
     * Bulk delete testimonials
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        // Validation for bulk delete
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'required|exists:testimonials,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Get testimonials to delete their videos
            $testimonials = Testimonial::whereIn('id', $request->ids)->get();

            foreach ($testimonials as $testimonial) {
                if ($testimonial->video_path && Storage::disk('public')->exists($testimonial->video_path)) {
                    Storage::disk('public')->delete($testimonial->video_path);
                }
            }

            Testimonial::whereIn('id', $request->ids)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Testimonials deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete testimonials',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
