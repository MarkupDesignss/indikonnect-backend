<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Wishlist;
use App\Models\Cart;
use App\Models\Product;
use Carbon\Carbon;
use App\Models\OrderLine;
use App\Models\ProductReview;
use App\Services\DistributorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserDashboardController extends Controller
{
    protected DistributorService $distributorService;

    public function __construct(DistributorService $distributorService)
    {
        $this->distributorService = $distributorService;
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        // Common stats for all users
        $stats = $this->getUserStats($userId);
        $latestOrder = $this->getLatestOrder($userId);
        $recentActivity = $this->getRecentActivity($userId);
        $wishlistItems = $this->getWishlistItems($userId);
        $cartItems = $this->getCartItems($userId);

        // Commission data – ONLY for distributors
        $commissionData = null;
        if ($user->isDistributor()) {
            $commissionData = $this->distributorService->getDashboardData($userId);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'account_type' => $user->account_type,
                    'member_since' => $user->created_at->format('Y'),
                ],
                'stats' => $stats,
                'latest_order' => $latestOrder,
                'recent_activity' => $recentActivity,
                'wishlist' => $wishlistItems,
                'cart' => $cartItems,
                // Commission data – null for customers, filled for distributors
                'commission' => $commissionData,
            ]
        ]);
    }

    private function getUserStats($userId)
    {
        $totalOrders = Order::where('user_id', $userId)->count();
        $wishlistCount = Wishlist::where('user_id', $userId)->count();
        $totalPoints = Order::where('user_id', $userId)->sum('coin_redeemed') ?? 0;
        $reviewsCount = ProductReview::where('user_id', $userId)->count();
        $averageRating = ProductReview::where('user_id', $userId)->avg('rating');

        $cart = Cart::where('user_id', $userId)->latest()->first();
        $cartItemsCount = 0;
        $cartTotal = 0;
        if ($cart) {
            $cartItems = DB::table('cart_items')->where('cart_id', $cart->id)->get();
            $cartItemsCount = $cartItems->count();
            foreach ($cartItems as $item) {
                $cartTotal += $item->unit_price * $item->quantity;
            }
        }

        return [
            'total_orders' => $totalOrders,
            'wishlist' => $wishlistCount,
            'points_earned' => $totalPoints,
            'reviews' => $reviewsCount,
            'average_rating' => $averageRating ? round($averageRating, 1) : 0,
            'cart_items' => $cartItemsCount,
        ];
    }

    private function getLatestOrder($userId)
    {
        $order = Order::with(['lines.product.images'])
            ->where('user_id', $userId)
            ->latest('created_at')
            ->first();

        if (!$order) {
            return null;
        }

        $orderItems = [];
        foreach ($order->lines as $line) {
            $product = $line->product;
            if ($product) {
                $images = $product->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'image_url' => asset('storage/products/' . $image->image),
                        'is_primary' => $image->is_primary
                    ];
                });

                $orderItems[] = [
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->name,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                    'images' => $images
                ];
            }
        }

        return [
            'order_reference' => $order->order_reference,
            'order_date' => $order->created_at->format('M d, Y'),
            'status' => $order->status,
            'total_payable' => $order->total_payable,
            'courier_company' => $order->courier_company,
            'courier_tracking_number' => $order->courier_tracking_number,
            'courier_status' => $order->courier_status,
            'courier_delivery_date' => $order->courier_delivery_date,
            'expected_delivery' => $order->courier_delivery_date
                ? date('M d', strtotime($order->courier_delivery_date))
                : null,
            'items' => $orderItems,
        ];
    }

    // private function getRecentActivity($userId)
    // {
    //     $orders = Order::where('user_id', $userId)
    //         ->latest('created_at')
    //         ->limit(10)
    //         ->get();

    //     $activities = [];

    //     foreach ($orders as $order) {
    //         $orderLine = OrderLine::where('order_id', $order->id)->first();
    //         $productName = $orderLine ?
    //             (Product::find($orderLine->product_id)?->name ?? 'Product') :
    //             'Product';

    //         $activities[] = [
    //             'event' => "Product Purchase: {$productName}",
    //             'date' => $order->created_at->format('M d, Y'),
    //             'points_earned' => "+{$order->coin_redeemed} PV",
    //             'status' => 'Confirmed',
    //             'order_reference' => $order->order_reference
    //         ];
    //     }

    //     $recentReviews = ProductReview::where('user_id', $userId)
    //         ->with('product')
    //         ->latest('created_at')
    //         ->limit(5)
    //         ->get();

    //     foreach ($recentReviews as $review) {
    //         $productName = $review->product?->name ?? 'Product';
    //         $activities[] = [
    //             'event' => "Product Review: {$productName}",
    //             'date' => $review->created_at->format('M d, Y'),
    //             'rating' => $review->rating,
    //             'review_text' => $review->review_text,
    //             'status' => $review->status ?? 'active',
    //             'product_id' => $review->product_id
    //         ];
    //     }

    //     usort($activities, function ($a, $b) {
    //         return strtotime($b['date']) - strtotime($a['date']);
    //     });

    //     return array_slice($activities, 0, 10);
    // }
    private function getRecentActivity($userId)
    {
        $activities = [];

        // 1. Order activities
        $orders = Order::where('user_id', $userId)
            ->latest('created_at')
            ->limit(10)
            ->get();

        foreach ($orders as $order) {
            $orderLine = OrderLine::where('order_id', $order->id)->first();
            $productName = $orderLine ?
                (Product::find($orderLine->product_id)?->name ?? 'Product') :
                'Product';

            $activities[] = [
                'type' => 'order',
                'event' => "Product Purchase: {$productName}",
                'created_at' => $order->created_at->format('M d, Y H:i:s'),
                'updated_at' => $order->updated_at->format('M d, Y H:i:s'),
                'created_timestamp' => $order->created_at->timestamp, // This is already an int
                'updated_timestamp' => $order->updated_at->timestamp, // This is already an int
                'points_earned' => "+{$order->coin_redeemed} PV",
                'status' => 'Confirmed',
                'order_reference' => $order->order_reference
            ];
        }

        // 2. Review activities
        $recentReviews = ProductReview::where('user_id', $userId)
            ->with('product')
            ->latest('created_at')
            ->limit(5)
            ->get();

        foreach ($recentReviews as $review) {
            $productName = $review->product?->name ?? 'Product';
            $activities[] = [
                'type' => 'review',
                'event' => "Product Review: {$productName}",
                'created_at' => $review->created_at->format('M d, Y H:i:s'),
                'updated_at' => $review->updated_at->format('M d, Y H:i:s'),
                'created_timestamp' => $review->created_at->timestamp, // Fix: use ->timestamp
                'updated_timestamp' => $review->updated_at->timestamp, // Fix: use ->timestamp
                'rating' => $review->rating,
                'review_text' => $review->review_text,
                'status' => $review->status ?? 'active',
                'product_id' => $review->product_id
            ];
        }

        // 3. Wishlist activities
        $wishlistItems = Wishlist::where('user_id', $userId)
            ->with('product')
            ->latest('created_at')
            ->limit(10)
            ->get();

        foreach ($wishlistItems as $wishlist) {
            $productName = $wishlist->product?->name ?? 'Product';
            $activities[] = [
                'type' => 'wishlist',
                'event' => "Added to Wishlist: {$productName}",
                'created_at' => $wishlist->created_at->format('M d, Y H:i:s'),
                'updated_at' => $wishlist->updated_at->format('M d, Y H:i:s'),
                'created_timestamp' => $wishlist->created_at->timestamp, // Fix: use ->timestamp
                'updated_timestamp' => $wishlist->updated_at->timestamp, // Fix: use ->timestamp
                'product_id' => $wishlist->product_id,
                'wishlist_id' => $wishlist->id
            ];
        }

        // 4. Cart activities
        $carts = Cart::where('user_id', $userId)
            ->with('items.product')
            ->latest('created_at')
            ->limit(10)
            ->get();

        foreach ($carts as $cart) {
            // Get cart items for this cart
            $cartItems = DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->join('products', 'cart_items.product_id', '=', 'products.id')
                ->select('products.name', 'cart_items.quantity', 'cart_items.created_at', 'cart_items.updated_at')
                ->get();

            foreach ($cartItems as $item) {
                $createdAt = $item->created_at ? date('M d, Y H:i:s', strtotime($item->created_at)) : $cart->created_at->format('M d, Y H:i:s');
                $updatedAt = $item->updated_at ? date('M d, Y H:i:s', strtotime($item->updated_at)) : $cart->updated_at->format('M d, Y H:i:s');
                $createdTimestamp = $item->created_at ? strtotime($item->created_at) : $cart->created_at->timestamp;
                $updatedTimestamp = $item->updated_at ? strtotime($item->updated_at) : $cart->updated_at->timestamp;

                $activities[] = [
                    'type' => 'cart',
                    'event' => "Added to Cart: {$item->name} (Qty: {$item->quantity})",
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                    'created_timestamp' => $createdTimestamp,
                    'updated_timestamp' => $updatedTimestamp,
                    'quantity' => $item->quantity,
                    'product_name' => $item->name
                ];
            }
        }

        // Sort all activities by created_timestamp (newest first)
        usort($activities, function ($a, $b) {
            return $b['created_timestamp'] - $a['created_timestamp'];
        });

        // Return top 10 most recent activities
        return array_slice($activities, 0, 10);
    }

    private function getWishlistItems($userId)
    {
        $wishlistItems = Wishlist::where('user_id', $userId)
            ->with(['product.images' => function ($query) {
                $query->orderBy('is_primary', 'desc')->orderBy('sort_order');
            }])
            ->latest()
            ->get();

        $items = [];
        foreach ($wishlistItems as $wishlist) {
            $product = $wishlist->product;
            if ($product) {
                $items[] = [
                    'id' => $wishlist->id,
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'retail_price' => $product->retail_price,
                    'distributor_price' => $product->distributor_price,
                    'images' => $product->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => asset('storage/products/' . $image->image),
                            'is_primary' => $image->is_primary
                        ];
                    }),
                    'added_at' => $wishlist->created_at->format('M d, Y')
                ];
            }
        }
        return $items;
    }

    private function getCartItems($userId)
    {
        $cart = Cart::where('user_id', $userId)->latest()->first();
        if (!$cart) {
            return [];
        }

        $cartItems = DB::table('cart_items')->where('cart_id', $cart->id)->get();
        $items = [];

        foreach ($cartItems as $cartItem) {
            $product = Product::with(['images' => function ($query) {
                $query->orderBy('is_primary', 'desc')->orderBy('sort_order');
            }])->find($cartItem->product_id);

            if ($product) {
                $items[] = [
                    'id' => $cartItem->id,
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $cartItem->unit_price,
                    'line_total' => $cartItem->unit_price * $cartItem->quantity,
                    'images' => $product->images->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'image_url' => asset('storage/products/' . $image->image),
                            'is_primary' => $image->is_primary
                        ];
                    })
                ];
            }
        }
        return $items;
    }

    public function getStats()
    {
        try {
            /*
            |--------------------------------------------------------------------------
            | Distributor Reviews
            |--------------------------------------------------------------------------
            | Only reviews submitted by users whose account_type is distributer
            */
            $reviews = ProductReview::query()
                ->select([
                    'product_reviews.id',
                    'product_reviews.rating',
                    'product_reviews.review_text',
                    'product_reviews.created_at',
                    'users.id as user_id',
                    'users.full_name',
                    'users.profile_picture',
                    'addresses.state',
                ])
                ->join('users', 'users.id', '=', 'product_reviews.user_id')
                ->leftJoin('addresses', function ($join) {
                    $join->on('addresses.user_id', '=', 'users.id');
                })
                ->orderByDesc('product_reviews.created_at')
                ->get()
                ->map(function ($review) {
                    return [
                        'id' => $review->id,
                        'rating' => $review->rating,
                        'review_text' => $review->review_text,
                        'created_at' => $review->created_at
                            ? $review->created_at->format('Y-m-d H:i:s')
                            : null,
                        'user' => [
                            'id' => $review->user_id,
                            'full_name' => $review->full_name,
                            'profile_picture' => $review->profile_picture
                                ? asset('storage/' . $review->profile_picture)
                                : null,
                            'state' => $review->state ?? 'Not Provided',
                        ],
                    ];
                });
            /*
            |--------------------------------------------------------------------------
            | Total Reviews
            |--------------------------------------------------------------------------
            | Customer + Distributor both
            */
            $totalReviews = ProductReview::count();

            /*
            |--------------------------------------------------------------------------
            | Average Rating
            |--------------------------------------------------------------------------
            */
            $averageRating = ProductReview::avg('rating');

            /*
            |--------------------------------------------------------------------------
            | Repeat Buyers
            |--------------------------------------------------------------------------
            | Users who have placed more than one order
            */
            $totalBuyers = DB::table('orders')
                ->whereNull('deleted_at')
                ->whereNotNull('user_id')
                ->distinct()
                ->count('user_id');

            $repeatBuyers = DB::table('orders')
                ->whereNull('deleted_at')
                ->whereNotNull('user_id')
                ->select('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count();

            $repeatBuyerPercentage = $totalBuyers > 0
                ? round(($repeatBuyers / $totalBuyers) * 100)
                : 0;

            /*
            |--------------------------------------------------------------------------
            | Total Cities
            |--------------------------------------------------------------------------
            | Unique cities from addresses
            */
            $totalCities = DB::table('addresses')
                ->whereNull('deleted_at')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->count('city');

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => [
                        'total_reviews' => $totalReviews,
                        'average_rating' => round((float) $averageRating, 1),
                        'repeat_buyers_percentage' => $repeatBuyerPercentage,
                        'total_cities' => $totalCities,
                    ],
                    'reviews' => $reviews,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
