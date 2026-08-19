<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\ProductReviewImage;
use App\Models\Product;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    /**
     * Get reviews for a product (public)
     */
    public function index(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);

        $reviews = ProductReview::forProduct($productId)
            ->approved()
            ->with([
                'user:id,name,profile_picture',
                'images' // Load review images
            ])
            ->latest()
            ->paginate($request->get('per_page', 10));

        // Calculate average rating
        $averageRating = ProductReview::forProduct($productId)
            ->approved()
            ->avg('rating');

        // Rating distribution
        $distribution = ProductReview::forProduct($productId)
            ->approved()
            ->select('rating', DB::raw('count(*) as count'))
            ->groupBy('rating')
            ->get()
            ->pluck('count', 'rating')
            ->toArray();

        // Format the response with images and user data
        $formattedReviews = $reviews->map(function ($review) {
            return [
                'id' => $review->id,
                'user_id' => $review->user_id,
                'user_name' => $review->user->name ?? 'Anonymous',
                'user_profile_picture' => $review->user->profile_picture
                    ? asset('storage/' . $review->user->profile_picture)
                    : null,
                'rating' => $review->rating,
                'review_text' => $review->review_text,
                'status' => $review->status,
                'created_at' => $review->created_at->format('M d, Y'),
                'updated_at' => $review->updated_at->format('M d, Y'),
                'is_verified_purchase' => $this->isVerifiedPurchase($review->order_id),
                'images' => $review->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => $image->image_url,
                        'sort_order' => $image->sort_order
                    ];
                })->values()->toArray(),
            ];
        });

        return response()->json([
            'data' => $formattedReviews,
            'meta' => [
                'average_rating' => round($averageRating, 2),
                'total_reviews' => $reviews->total(),
                'rating_distribution' => $distribution,
                'pagination' => [
                    'total' => $reviews->total(),
                    'per_page' => $reviews->perPage(),
                    'current_page' => $reviews->currentPage(),
                    'last_page' => $reviews->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Get user's review for a specific product
     */
    public function showUserReview(Request $request, $productId)
    {
        $userId = auth()->id();

        $review = ProductReview::with(['images'])
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if (!$review) {
            return response()->json(['message' => 'No review found'], 404);
        }

        return response()->json([
            'review' => [
                'id' => $review->id,
                'user_id' => $review->user_id,
                'product_id' => $review->product_id,
                'order_id' => $review->order_id,
                'rating' => $review->rating,
                'review_text' => $review->review_text,
                'status' => $review->status,
                'created_at' => $review->created_at->format('M d, Y'),
                'updated_at' => $review->updated_at->format('M d, Y'),
                'images' => $review->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => $image->image_url,
                        'sort_order' => $image->sort_order
                    ];
                })->values()->toArray(),
            ]
        ]);
    }

    /**
     * Create a new review with images
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'rating' => 'required|integer|min:1|max:5',
            'review_text' => 'nullable|string|max:1000',
            'order_id' => 'nullable|exists:orders,id',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userId = auth()->id();

        // Check if user already reviewed this product
        $existingReview = ProductReview::where('user_id', $userId)
            ->where('product_id', $request->product_id)
            ->first();

        if ($existingReview) {
            return response()->json([
                'message' => 'You have already reviewed this product',
                'review' => $existingReview
            ], 409);
        }

        // If order_id is provided, verify it belongs to user and contains the product
        if ($request->order_id) {
            $order = Order::where('id', $request->order_id)
                ->where('user_id', $userId)
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Invalid order'], 422);
            }

            // Verify product is in the order
            $hasProduct = $order->items()->where('product_id', $request->product_id)->exists();

            if (!$hasProduct) {
                return response()->json([
                    'message' => 'This product was not in the specified order'
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            // Create review
            $review = ProductReview::create([
                'user_id' => $userId,
                'product_id' => $request->product_id,
                'order_id' => $request->order_id,
                'rating' => $request->rating,
                'review_text' => $request->review_text,
                'status' => 'pending'
            ]);

            // Handle image uploads
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $index => $image) {
                    $path = $image->store('product_reviews', 'public');

                    ProductReviewImage::create([
                        'product_review_id' => $review->id,
                        'image_path' => $path,
                        'sort_order' => $index
                    ]);
                }
            }

            DB::commit();

            // Load images for response
            $review->load('images');

            return response()->json([
                'message' => 'Review submitted successfully and is pending moderation',
                'review' => [
                    'id' => $review->id,
                    'user_id' => $review->user_id,
                    'product_id' => $review->product_id,
                    'rating' => $review->rating,
                    'review_text' => $review->review_text,
                    'status' => $review->status,
                    'created_at' => $review->created_at->format('M d, Y'),
                    'images' => $review->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => $image->image_url,
                            'sort_order' => $image->sort_order
                        ];
                    })->values()->toArray(),
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to submit review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a review with images
     */
    public function update(Request $request, $id)
    {
        $review = ProductReview::findOrFail($id);

        // Check if user owns the review
        if ($review->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Only allow updates if review is pending or rejected
        if ($review->status === 'approved') {
            return response()->json([
                'message' => 'Approved reviews cannot be modified. Please contact support.'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'rating' => 'sometimes|integer|min:1|max:5',
            'review_text' => 'nullable|string|max:1000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'delete_images' => 'nullable|array',
            'delete_images.*' => 'integer|exists:product_review_images,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // Update review data
            $review->update($request->only(['rating', 'review_text']));

            // Delete specified images
            if ($request->has('delete_images')) {
                $imagesToDelete = ProductReviewImage::where('product_review_id', $review->id)
                    ->whereIn('id', $request->delete_images)
                    ->get();

                foreach ($imagesToDelete as $image) {
                    Storage::disk('public')->delete($image->image_path);
                    $image->delete();
                }
            }

            // Upload new images
            if ($request->hasFile('images')) {
                $currentImageCount = $review->images()->count();
                $maxImages = 5;

                foreach ($request->file('images') as $index => $image) {
                    if ($currentImageCount + $index >= $maxImages) {
                        break;
                    }
                    $path = $image->store('product_reviews', 'public');

                    ProductReviewImage::create([
                        'product_review_id' => $review->id,
                        'image_path' => $path,
                        'sort_order' => $currentImageCount + $index
                    ]);
                }
            }

            // If review was rejected, reset to pending after update
            if ($review->status === 'rejected') {
                $review->update(['status' => 'pending']);
            }

            DB::commit();

            // Load images for response
            $review->load('images');

            return response()->json([
                'message' => 'Review updated successfully',
                'review' => [
                    'id' => $review->id,
                    'user_id' => $review->user_id,
                    'product_id' => $review->product_id,
                    'rating' => $review->rating,
                    'review_text' => $review->review_text,
                    'status' => $review->status,
                    'created_at' => $review->created_at->format('M d, Y'),
                    'updated_at' => $review->updated_at->format('M d, Y'),
                    'images' => $review->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => $image->image_url,
                            'sort_order' => $image->sort_order
                        ];
                    })->values()->toArray(),
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a review and its images
     */
    public function destroy($id)
    {
        $review = ProductReview::findOrFail($id);

        // Check if user owns the review
        if ($review->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Only allow deletion if review is pending or rejected
        if ($review->status === 'approved') {
            return response()->json([
                'message' => 'Approved reviews cannot be deleted. Please contact support.'
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Delete associated images from storage
            foreach ($review->images as $image) {
                Storage::disk('public')->delete($image->image_path);
            }

            // Delete review (images will be cascade deleted)
            $review->delete();

            DB::commit();

            return response()->json(['message' => 'Review deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add images to an existing review
     */
    public function addImages(Request $request, $id)
    {
        $review = ProductReview::findOrFail($id);

        // Check if user owns the review
        if ($review->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'images' => 'required|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $currentCount = $review->images()->count();
        $maxImages = 5;

        if ($currentCount >= $maxImages) {
            return response()->json([
                'message' => 'Maximum number of images (5) already added'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $uploadedImages = [];
            foreach ($request->file('images') as $index => $image) {
                if ($currentCount + $index >= $maxImages) {
                    break;
                }
                $path = $image->store('product_reviews', 'public');

                $reviewImage = ProductReviewImage::create([
                    'product_review_id' => $review->id,
                    'image_path' => $path,
                    'sort_order' => $currentCount + $index
                ]);

                $uploadedImages[] = [
                    'id' => $reviewImage->id,
                    'image_url' => $reviewImage->image_url,
                    'sort_order' => $reviewImage->sort_order
                ];
            }

            DB::commit();

            return response()->json([
                'message' => 'Images added successfully',
                'images' => $uploadedImages
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to add images',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove an image from a review
     */
    public function removeImage(Request $request, $id, $imageId)
    {
        $review = ProductReview::findOrFail($id);

        // Check if user owns the review
        if ($review->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $image = ProductReviewImage::where('id', $imageId)
            ->where('product_review_id', $review->id)
            ->first();

        if (!$image) {
            return response()->json(['message' => 'Image not found'], 404);
        }

        try {
            Storage::disk('public')->delete($image->image_path);
            $image->delete();

            return response()->json([
                'message' => 'Image removed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to remove image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if the purchase is verified
     */
    private function isVerifiedPurchase($orderId)
    {
        if (!$orderId) {
            return false;
        }

        $order = Order::find($orderId);
        return $order && $order->status === 'delivered';
    }
}
