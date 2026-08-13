<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

        $reviews = ProductReview::with(['user' => function ($query) {
            $query->select('id', 'name', 'email');
        }])
            ->where('product_id', $productId)
            ->where('status', 'approved')
            ->orderBy('created_at', 'desc')
            ->get();

        $averageRating = ProductReview::where('product_id', $productId)
            ->where('status', 'approved')
            ->avg('rating');

        $totalReviews = ProductReview::where('product_id', $productId)
            ->where('status', 'approved')
            ->count();

        $ratingDistribution = [
            1 => ProductReview::where('product_id', $productId)->where('status', 'approved')->where('rating', 1)->count(),
            2 => ProductReview::where('product_id', $productId)->where('status', 'approved')->where('rating', 2)->count(),
            3 => ProductReview::where('product_id', $productId)->where('status', 'approved')->where('rating', 3)->count(),
            4 => ProductReview::where('product_id', $productId)->where('status', 'approved')->where('rating', 4)->count(),
            5 => ProductReview::where('product_id', $productId)->where('status', 'approved')->where('rating', 5)->count(),
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
                        'user_name' => $review->user->name ?? 'Anonymous',
                        'rating' => $review->rating,
                        'review_text' => $review->review_text,
                        'created_at' => $review->created_at->format('M d, Y'),
                        'updated_at' => $review->updated_at->format('M d, Y'),
                        'is_verified_purchase' => $this->isVerifiedPurchase($review->order_id),
                        'helpful_count' => 0,
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

        $review = ProductReview::with(['user' => function ($query) {
            $query->select('id', 'name', 'email');
        }])
            ->where('product_id', $productId)
            ->where('id', $reviewId)
            ->first();

        if (!$review) {
            return response()->json([
                'success' => false,
                'message' => 'Review not found'
            ], 404);
        }

        // Check if review is approved or the user owns it
        $user = request()->user();
        if ($review->status !== 'approved' && $review->user_id !== $user->id) {
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
                'user_name' => $review->user->name ?? 'Anonymous',
                'rating' => $review->rating,
                'review_text' => $review->review_text,
                'status' => $review->status,
                'created_at' => $review->created_at->format('M d, Y'),
                'updated_at' => $review->updated_at->format('M d, Y'),
                'is_verified_purchase' => $this->isVerifiedPurchase($review->order_id),
                'helpful_count' => 0,
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
            $review = ProductReview::create([
                'user_id' => $user->id,
                'product_id' => $productId,
                'order_id' => $orderId,
                'rating' => $request->rating,
                'review_text' => $request->review_text,
                'status' => 'pending'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully and is pending moderation',
                'data' => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'review_text' => $review->review_text,
                    'status' => $review->status,
                    'created_at' => $review->created_at->format('M d, Y')
                ]
            ], 201);
        } catch (\Exception $e) {
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

        // Check if review can be edited (only pending or rejected reviews can be edited)
        if ($review->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Approved reviews cannot be edited. Please contact support.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'rating' => 'sometimes|integer|min:1|max:5',
            'review_text' => 'sometimes|string|min:10|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $review->update([
                'rating' => $request->rating ?? $review->rating,
                'review_text' => $request->review_text ?? $review->review_text,
                'status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Review updated successfully and is pending moderation',
                'data' => [
                    'id' => $review->id,
                    'product_id' => $review->product_id,
                    'rating' => $review->rating,
                    'review_text' => $review->review_text,
                    'status' => $review->status,
                    'updated_at' => $review->updated_at->format('M d, Y')
                ]
            ]);
        } catch (\Exception $e) {
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

        // Check if review can be deleted
        if ($review->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Approved reviews cannot be deleted. Please contact support.'
            ], 403);
        }

        try {
            $review->delete();

            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete review',
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
