<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Wishlist;
use App\Models\Cart;
use App\Models\Product;
use App\Models\OrderLine;
use App\Models\ProductReview;
use App\Models\User;
use App\Services\DistributorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Sdks\Partner\IgniteClient;
use App\Sdks\Partner\ResultCode;

class UserDashboardController extends Controller
{
    protected DistributorService $distributorService;
    protected IgniteClient $ignite;

    public function __construct(
        DistributorService $distributorService,
        IgniteClient $ignite
    ) {
        $this->distributorService = $distributorService;
        $this->ignite = $ignite;
    }

    /**
     * Display the user dashboard.
     *
     * Returns common stats, latest order, recent activity, wishlist, and cart.
     * For distributors, also includes Ignite member details (rank, CV, balances).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
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

        // Distributor-only: Ignite details
        $igniteData = null;
        if ($user->isDistributor() && $user->distributor_id) {
            $igniteData = $this->getIgniteMemberDetails($user->distributor_id);
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
                'ignite' => $igniteData,
            ]
        ]);
    }

    /**
     * Get Ignite member details (rank, CV, balances) for a distributor.
     *
     * @param  string  $baId
     * @return array|null
     */
    private function getIgniteMemberDetails(string $baId): ?array
    {
        try {
            $response = $this->ignite->memberDetails($baId);

            if ($response['code'] !== ResultCode::OK->value) {
                return null;
            }

            $data = $response['data'];

            return [
                'member_id'         => $data['member_id'] ?? null,
                'ba_id'             => $data['ba_id'] ?? null,
                'rank_no'           => $data['rank_no'] ?? 0,
                'base_rank_no'      => $data['base_rank_no'] ?? 0,
                'eligible_rank_no'  => $data['eligible_rank_no'] ?? 0,
                'cv_left'           => $data['cv_left'] ?? '0.0000',
                'cv_right'          => $data['cv_right'] ?? '0.0000',
                'floating_cv_left'  => $data['floating_cv_left'] ?? '0.0000',
                'floating_cv_right' => $data['floating_cv_right'] ?? '0.0000',
                'total_cv_left'     => $data['total_cv_left'] ?? '0.0000',
                'total_cv_right'    => $data['total_cv_right'] ?? '0.0000',
                'cash_bal'          => $data['cash_bal'] ?? '0.0000',
                'green_bal'         => $data['green_bal'] ?? '0.0000',
                'yellow_bal'        => $data['yellow_bal'] ?? '0.0000',
                'blue_bal'          => $data['blue_bal'] ?? '0.0000',
                'membership_status' => $data['membership_status'] ?? null,
                'total_steps'       => $data['total_steps'] ?? 0,
                'qualified_at'      => $data['qualified_at'] ?? null,
                'is_placed'         => $data['is_placed'] ?? !empty($data['binary_parent_id']),
                'placement_leg'     => $data['binary_side'] ?? null,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Calculate general user statistics.
     *
     * @param  int  $userId
     * @return array
     */
    private function getUserStats($userId)
    {
        // FIX: count orders, not order lines
        $totalOrders = Order::where('user_id', $userId)->count();

        $wishlistCount = Wishlist::where('user_id', $userId)->count();

        // FIX: points_earned = total CV from confirmed orders (Ignite commissionable_volume),
        //      NOT coin_redeemed (which is coin count)
        $totalPoints = Order::where('user_id', $userId)
            ->where('status', 'confirmed')
            ->sum('commissionable_volume') ?? 0;

        $reviewsCount  = ProductReview::where('user_id', $userId)->count();
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
            'total_orders'   => $totalOrders,
            'wishlist'       => $wishlistCount,
            'points_earned'  => number_format((float) $totalPoints, 2, '.', ''),  // FIX: string format
            'reviews'        => $reviewsCount,
            'average_rating' => $averageRating ? round($averageRating, 1) : 0,
            'cart_items'     => $cartItemsCount,
        ];
    }

    /**
     * Get the user's most recent order with product details.
     *
     * @param  int  $userId
     * @return array|null
     */
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
                        'image_url' => asset('storage/' . $image->image),
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

    /**
     * Get recent activity for the user (orders, reviews, wishlist, cart).
     *
     * @param  int  $userId
     * @return array
     */
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
            $productName = $orderLine
                ? (Product::find($orderLine->product_id)?->name ?? 'Product')
                : 'Product';

            // FIX: use commissionable_volume (CV), not coin_redeemed
            $cv = (float) ($order->commissionable_volume ?? 0);

            $activities[] = [
                'type'              => 'order',
                'event'             => "Product Purchase: {$productName}",
                'created_at'        => $order->created_at->format('M d, Y H:i:s'),
                'updated_at'        => $order->updated_at->format('M d, Y H:i:s'),
                'created_timestamp' => $order->created_at->timestamp,
                'updated_timestamp' => $order->updated_at->timestamp,
                'points_earned'     => '+' . number_format($cv, 2, '.', '') . ' PV',
                'status'            => 'Confirmed',
                'order_reference'   => $order->order_reference,
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
                'created_timestamp' => $review->created_at->timestamp,
                'updated_timestamp' => $review->updated_at->timestamp,
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
                'created_timestamp' => $wishlist->created_at->timestamp,
                'updated_timestamp' => $wishlist->updated_at->timestamp,
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
            $cartItems = DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->join('products', 'cart_items.product_id', '=', 'products.id')
                ->select('products.name', 'cart_items.quantity', 'cart_items.created_at', 'cart_items.updated_at')
                ->get();

            foreach ($cartItems as $item) {
                $createdAt = $item->created_at
                    ? date('M d, Y H:i:s', strtotime($item->created_at))
                    : $cart->created_at->format('M d, Y H:i:s');
                $updatedAt = $item->updated_at
                    ? date('M d, Y H:i:s', strtotime($item->updated_at))
                    : $cart->updated_at->format('M d, Y H:i:s');
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

        // Sort by created_timestamp (newest first)
        usort($activities, function ($a, $b) {
            return $b['created_timestamp'] - $a['created_timestamp'];
        });

        return array_slice($activities, 0, 10);
    }

    /**
     * Get wishlist items with product details.
     *
     * @param  int  $userId
     * @return array
     */
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
                            'image_url' => asset('storage/' . $image->image),
                            'is_primary' => $image->is_primary
                        ];
                    }),
                    'added_at' => $wishlist->created_at->format('M d, Y')
                ];
            }
        }
        return $items;
    }

    /**
     * Get cart items with product details.
     *
     * @param  int  $userId
     * @return array
     */
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
                            'image_url' => asset('storage/' . $image->image),
                            'is_primary' => $image->is_primary
                        ];
                    })
                ];
            }
        }
        return $items;
    }

    /**
     * Get global marketplace statistics (public).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        try {
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

            $totalReviews = ProductReview::count();
            $averageRating = ProductReview::avg('rating');

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

    /**
     * Get distributor-specific statistics including Ignite rank progress.
     *
     * Combines local order data with Ignite rank progress. Requires the
     * authenticated user to be a distributor.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDistributorStats(Request $request)
    {
        try {
            $authUser = Auth::user();

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated. Please login first.',
                    'data' => []
                ], 401);
            }

            if ($authUser->account_type !== 'distributor') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Only distributors can access this data.',
                    'data' => []
                ], 403);
            }

            $userId = $authUser->id;

            $user = User::where('account_type', 'distributor')
                ->where('id', $userId)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Distributor not found',
                    'data' => []
                ], 404);
            }

            // Local order statistics
            $stats = DB::table('orders as o')
                ->join('order_lines as ol', 'o.id', '=', 'ol.order_id')
                ->join('products as p', 'ol.product_id', '=', 'p.id')
                ->where('o.user_id', $userId)
                ->where('o.status', '!=', 'cancelled')
                ->select([
                    DB::raw('COUNT(DISTINCT o.id) as total_orders'),
                    DB::raw('COALESCE(SUM(p.distributor_mrp * ol.quantity), 0) as total_amount_mrp'),
                    DB::raw('COALESCE(SUM((p.distributor_mrp - p.distributor_price) * ol.quantity), 0) as total_savings'),
                    DB::raw('COALESCE(SUM(o.commissionable_volume), 0) as total_cv'),          // FIX: CV
                    DB::raw('COALESCE(SUM(o.coin_redeemed), 0) as total_coins_redeemed'),      // keep separate
                ])
                ->first();

            // Ignite rank progress (if registered)
            $rankProgress = null;
            if ($user->distributor_id) {
                try {
                    $response = $this->ignite->rankProgress($user->distributor_id);

                    if ($response['code'] === ResultCode::OK->value) {
                        $rankProgress = $response['data'];
                    }
                } catch (\Exception $e) {
                    // Silently ignore Ignite errors — local stats still returned
                }
            }

            $result = [
                'user_id'              => $user->id,
                'full_name'            => $user->full_name,
                'email'                => $user->email,
                'phone'                => $user->phone,
                'distributor_id'       => $user->distributor_id,
                'total_orders'         => (int) $stats->total_orders,
                'total_amount_mrp'     => (float) $stats->total_amount_mrp,
                'total_savings'        => (float) $stats->total_savings,
                'total_cv'             => number_format((float) $stats->total_cv, 2, '.', ''),         // FIX
                'total_coins_redeemed' => (int) $stats->total_coins_redeemed,                          // FIX
                'account_type'         => $user->account_type,
                'joined_at'            => $user->created_at->toDateTimeString(),
                'rank_progress'        => $rankProgress,
            ];

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Distributor stats retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve distributor stats',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}