<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\ProductReviewImage;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ProductReviewController extends Controller
{
    /**
     * Get all reviews for a product
     */
    public function index($productId)
    {
        $product = Product::find($productId);
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $reviews = ProductReview::with([
            'user' => function ($query) {
                $query->select('id', 'full_name', 'email');
            },
            'images' // Load images relationship
        ])
            ->where('product_id', $productId)
            ->orderBy('created_at', 'desc')
            ->get();

        $averageRating = ProductReview::where('product_id', $productId)
            ->avg('rating');

        $totalReviews = ProductReview::where('product_id', $productId)
            ->count();

        $ratingDistribution = [
            1 => ProductReview::where('product_id', $productId)->where('rating', 1)->count(),
            2 => ProductReview::where('product_id', $productId)->where('rating', 2)->count(),
            3 => ProductReview::where('product_id', $productId)->where('rating', 3)->count(),
            4 => ProductReview::where('product_id', $productId)->where('rating', 4)->count(),
            5 => ProductReview::where('product_id', $productId)->where('rating', 5)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $productId,
                'product_name' => $product->name,
                'average_rating' => round($averageRating, 1),
                'total_reviews' => $totalReviews,
                'rating_distribution' => $ratingDistribution,
                'reviews' => $reviews->map(function ($review) {
                    return [
                        'id' => $review->id,
                        'user_id' => $review->user_id,
                        'user_name' => $review->user->full_name ?? 'Anonymous',
                        'rating' => $review->rating,
                        'review_text' => $review->review_text,
                        'is_verified_purchase' => $this->isVerifiedPurchase($review->order_id),
                        'helpful_count' => 0,
                        'images' => $review->images->map(function ($image) {
                            return [
                                'id' => $image->id,
                                'image_url' => $image->image_url,
                                'sort_order' => $image->sort_order
                            ];
                        })
                    ];
                })
            ]
        ]);
    }

    /**
     * Get a specific review for a product
     */
    public function show($productId, $reviewId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $review = ProductReview::with([
            'user' => function ($query) {
                $query->select('id', 'full_name', 'email');
            },
            'images'
        ])
            ->where('product_id', $productId)
            ->where('id', $reviewId)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found'
            ], 404);
        }

        $user = request()->user();
        if ($review->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Review is not available'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $review->id,
                'user_id' => $review->user_id,
                'user_name' => $review->user->full_name ?? 'Anonymous',
                'rating' => $review->rating,
                'review_text' => $review->review_text,
                'status' => $review->status,
                'is_verified_purchase' => $this->isVerifiedPurchase($review->order_id),
                'helpful_count' => 0,
                'images' => $review->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => $image->image_url,
                        'sort_order' => $image->sort_order
                    ];
                })
            ]
        ]);
    }

    /**
     * Store a new review (user must have purchased the product)
     */
    public function store(Request $request, $productId)
    {
        $user = $request->user();

        $product = Product::find($productId);
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'review_text' => 'required|string|min:10|max:1000',
            'images' => 'nullable|array|max:5', // Max 5 images
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max per image
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $existingReview = ProductReview::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->first();

        if ($existingReview) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this product'
            ], 409);
        }

        $orderId = $this->checkUserPurchasedProduct($user->id, $productId);

        if (!$orderId) {
            return response()->json([
                'success' => false,
                'message' => 'You can only review products you have purchased'
            ], 403);
        }

        try {
            DB::beginTransaction();

            $review = ProductReview::create([
                'user_id' => $user->id,
                'product_id' => $productId,
                'order_id' => $orderId,
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

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully and is pending moderation',
                'data' => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'review_text' => $review->review_text,
                    'status' => $review->status,
                    'images' => $review->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => $image->image_url,
                            'sort_order' => $image->sort_order
                        ];
                    })
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to submit review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a review (only the review owner)
     */
    public function update(Request $request, $productId, $reviewId)
    {
        $user = $request->user();

        // Check if product exists
        $product = Product::find($productId);
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Find the review and verify it belongs to the product
        $review = ProductReview::where('product_id', $productId)
            ->where('id', $reviewId)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found'
            ], 404);
        }

        // Check if user owns the review
        if ($review->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to edit this review'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'rating' => 'sometimes|integer|min:1|max:5',
            'review_text' => 'sometimes|string|min:10|max:1000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'delete_images' => 'nullable|array',
            'delete_images.*' => 'integer|exists:product_review_images,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $review->update([
                'rating' => $request->rating ?? $review->rating,
                'review_text' => $request->review_text ?? $review->review_text,
                'status' => 'pending',
            ]);

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

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Review updated successfully and is pending moderation',
                'data' => [
                    'id' => $review->id,
                    'product_id' => $review->product_id,
                    'rating' => $review->rating,
                    'review_text' => $review->review_text,
                    'status' => $review->status,
                    'images' => $review->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => $image->image_url,
                            'sort_order' => $image->sort_order
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a review (only the review owner)
     */
    public function destroy(Request $request, $productId, $reviewId)
    {
        $user = $request->user();

        // Check if product exists
        $product = Product::find($productId);
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Find the review and verify it belongs to the product
        $review = ProductReview::where('product_id', $productId)
            ->where('id', $reviewId)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found'
            ], 404);
        }

        // Check if user owns the review
        if ($review->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to delete this review'
            ], 403);
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

            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add images to an existing review
     */
    public function addImages(Request $request, $productId, $reviewId)
    {
        $user = $request->user();

        $review = ProductReview::where('product_id', $productId)
            ->where('id', $reviewId)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found'
            ], 404);
        }

        if ($review->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to add images to this review'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'images' => 'required|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $currentCount = $review->images()->count();
        $maxImages = 5;

        if ($currentCount >= $maxImages) {
            return response()->json([
                'success' => false,
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
                'success' => true,
                'message' => 'Images added successfully',
                'data' => [
                    'images' => $uploadedImages
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add images',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove an image from a review
     */
    public function removeImage(Request $request, $productId, $reviewId, $imageId)
    {
        $user = $request->user();

        $review = ProductReview::where('product_id', $productId)
            ->where('id', $reviewId)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found'
            ], 404);
        }

        if ($review->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to remove images from this review'
            ], 403);
        }

        $image = ProductReviewImage::where('id', $imageId)
            ->where('product_review_id', $review->id)
            ->first();

        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Image not found'
            ], 404);
        }

        try {
            Storage::disk('public')->delete($image->image_path);
            $image->delete();

            return response()->json([
                'success' => true,
                'message' => 'Image removed successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if user has purchased the product
     */
    private function checkUserPurchasedProduct($userId, $productId)
    {
        $order = Order::where('user_id', $userId)
            ->where('status', 'delivered')
            ->whereHas('lines', function ($query) use ($productId) {
                $query->where('product_id', $productId);
            })
            ->first();

        return $order ? $order->id : null;
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
