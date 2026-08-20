<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\ProductReviewImage;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderLine;
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
                'user:id,full_name,profile_picture',
                'images', // Load review images
                'orderLine' // Load order line relationship
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
                'user_name' => $review->user->full_name ?? 'Anonymous',
                'user_profile_picture' => $review->user->profile_picture
                    ? asset('storage/' . $review->user->profile_picture)
                    : null,
                'rating' => $review->rating,
                'review_text' => $review->review_text,
                'status' => $review->status,
                'created_at' => $review->created_at->format('M d, Y'),
                'updated_at' => $review->updated_at->format('M d, Y'),
                'is_verified_purchase' => $this->isVerifiedPurchase($review->order_line_id),
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

        $review = ProductReview::with(['images', 'orderLine'])
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
                'order_line_id' => $review->order_line_id,
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
     * Get eligible order lines for review (delivered items)
     */
    public function getEligibleItems(Request $request)
    {
        $userId = auth()->id();

        // Get all delivered order lines for the user
        $eligibleItems = OrderLine::with(['order', 'product'])
            ->whereHas('order', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->where('delivery_status', 'delivered')
            ->whereDoesntHave('review') // Exclude already reviewed items
            ->get()
            ->map(function ($orderLine) {
                return [
                    'order_line_id' => $orderLine->id,
                    'order_id' => $orderLine->order_id,
                    'order_number' => $orderLine->order->order_number ?? null,
                    'product_id' => $orderLine->product_id,
                    'product_name' => $orderLine->product->name ?? null,
                    'product_image' => $orderLine->product->image ?? null,
                    'quantity' => $orderLine->quantity,
                    'price' => $orderLine->price,
                    'delivered_at' => $orderLine->delivered_at
                        ? $orderLine->delivered_at->format('M d, Y')
                        : null,
                ];
            });

        return response()->json([
            'data' => $eligibleItems,
            'total' => $eligibleItems->count()
        ]);
    }

    /**
     * Create a new review with images
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'order_line_id' => 'required|exists:order_lines,id',
            'rating' => 'required|integer|min:1|max:5',
            'review_text' => 'nullable|string|max:1000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userId = auth()->id();

        // Verify order line belongs to user and is delivered
        $orderLine = OrderLine::with('order')
            ->where('id', $request->order_line_id)
            ->whereHas('order', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->first();

        if (!$orderLine) {
            return response()->json([
                'message' => 'Invalid order line or order not found'
            ], 422);
        }

        // Check if order line is delivered
        if ($orderLine->delivery_status !== 'delivered') {
            return response()->json([
                'message' => 'You can only review products that have been delivered'
            ], 422);
        }

        // Verify product matches the order line
        if ($orderLine->product_id != $request->product_id) {
            return response()->json([
                'message' => 'Product does not match the order line'
            ], 422);
        }

        // Check if this order line already has a review
        $existingReview = ProductReview::where('order_line_id', $request->order_line_id)->first();
        if ($existingReview) {
            return response()->json([
                'message' => 'This order item has already been reviewed',
                'review' => $existingReview
            ], 409);
        }

        // Check if user already reviewed this product (optional)
        $existingProductReview = ProductReview::where('user_id', $userId)
            ->where('product_id', $request->product_id)
            ->first();

        if ($existingProductReview) {
            return response()->json([
                'message' => 'You have already reviewed this product',
                'review' => $existingProductReview
            ], 409);
        }

        try {
            DB::beginTransaction();

            // Create review
            $review = ProductReview::create([
                'user_id' => $userId,
                'product_id' => $request->product_id,
                'order_line_id' => $request->order_line_id,
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
            $review->load('images', 'orderLine');

            return response()->json([
                'message' => 'Review submitted successfully and is pending moderation',
                'review' => [
                    'id' => $review->id,
                    'user_id' => $review->user_id,
                    'product_id' => $review->product_id,
                    'order_line_id' => $review->order_line_id,
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
     * Check if the purchase is verified based on order line
     */
    private function isVerifiedPurchase($orderLineId)
    {
        if (!$orderLineId) {
            return false;
        }

        $orderLine = OrderLine::find($orderLineId);
        return $orderLine && $orderLine->delivery_status === 'delivered';
    }
}