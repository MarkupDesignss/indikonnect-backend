<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\Product;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
            ->with('user:id,name')
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

        return response()->json([
            'data' => $reviews,
            'meta' => [
                'average_rating' => round($averageRating, 2),
                'total_reviews' => $reviews->total(),
                'rating_distribution' => $distribution,
            ]
        ]);
    }

    /**
     * Get user's review for a specific product
     */
    public function showUserReview(Request $request, $productId)
    {
        $userId = auth()->id();

        $review = ProductReview::where('user_id', $userId)
            ->where('product_id', $productId)
            ->first();

        if (!$review) {
            return response()->json(['message' => 'No review found'], 404);
        }

        return response()->json($review);
    }

    /**
     * Create a new review
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'rating' => 'required|integer|min:1|max:5',
            'review_text' => 'nullable|string|max:1000',
            'order_id' => 'nullable|exists:orders,id'
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

            // Verify product is in the order (adjust based on your order structure)
            // This assumes you have an order_items table
            $hasProduct = $order->items()->where('product_id', $request->product_id)->exists();

            if (!$hasProduct) {
                return response()->json([
                    'message' => 'This product was not in the specified order'
                ], 422);
            }
        }

        // Create review
        $review = ProductReview::create([
            'user_id' => $userId,
            'product_id' => $request->product_id,
            'order_id' => $request->order_id,
            'rating' => $request->rating,
            'review_text' => $request->review_text,
            'status' => 'pending' // All reviews start as pending
        ]);

        return response()->json([
            'message' => 'Review submitted successfully and is pending moderation',
            'review' => $review
        ], 201);
    }

    /**
     * Update a review
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
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $review->update($request->only(['rating', 'review_text']));

        // If review was rejected, reset to pending after update
        if ($review->status === 'rejected') {
            $review->update(['status' => 'pending']);
        }

        return response()->json([
            'message' => 'Review updated successfully',
            'review' => $review
        ]);
    }

    /**
     * Delete a review
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

        $review->delete();

        return response()->json(['message' => 'Review deleted successfully']);
    }
}
