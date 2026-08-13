<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Wishlist;
use App\Models\Cart;
use App\Models\Product;
use App\Models\OrderLine;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserDashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        // Get dashboard statistics
        $stats = $this->getUserStats($userId);

        // Get latest order with images
        $latestOrder = $this->getLatestOrder($userId);

        // Get recent activity
        $recentActivity = $this->getRecentActivity($userId);

        // Get wishlist items with images
        $wishlistItems = $this->getWishlistItems($userId);

        // Get cart items with images
        $cartItems = $this->getCartItems($userId);

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
                // 'wishlist' => $wishlistItems,
                // 'cart' => $cartItems
            ]
        ]);
    }

    private function getUserStats($userId)
    {
        $totalOrders = Order::where('user_id', $userId)->count();

        $wishlistCount = Wishlist::where('user_id', $userId)->count();

        $totalPoints = Order::where('user_id', $userId)
            ->sum('coin_redeemed') ?? 0;

        // Get total reviews count for the user
        $reviewsCount = ProductReview::where('user_id', $userId)->count();

        // Get average rating given by the user
        $averageRating = ProductReview::where('user_id', $userId)->avg('rating');

        // Get cart items count
        $cart = Cart::where('user_id', $userId)->latest()->first();
        $cartItemsCount = 0;
        $cartTotal = 0;

        if ($cart) {
            $cartItems = DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->get();

            $cartItemsCount = $cartItems->count();

            // Calculate cart total
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

    private function getRecentActivity($userId)
    {
        // Get recent order activities
        $orders = Order::where('user_id', $userId)
            ->latest('created_at')
            ->limit(10)
            ->get();

        $activities = [];

        foreach ($orders as $order) {
            // Get first product from order for the name
            $orderLine = OrderLine::where('order_id', $order->id)->first();
            $productName = $orderLine ?
                (Product::find($orderLine->product_id)?->name ?? 'Product') :
                'Product';

            $activities[] = [
                'event' => "Product Purchase: {$productName}",
                'date' => $order->created_at->format('M d, Y'),
                'points_earned' => "+{$order->coin_redeemed} PV",
                'status' => 'Confirmed',
                'order_reference' => $order->order_reference
            ];
        }

        // Get recent review activities
        $recentReviews = ProductReview::where('user_id', $userId)
            ->with('product')
            ->latest('created_at')
            ->limit(5)
            ->get();

        foreach ($recentReviews as $review) {
            $productName = $review->product?->name ?? 'Product';

            $activities[] = [
                'event' => "Product Review: {$productName}",
                'date' => $review->created_at->format('M d, Y'),
                'rating' => $review->rating,
                'review_text' => $review->review_text,
                'status' => $review->status ?? 'active',
                'product_id' => $review->product_id
            ];
        }

        // Sort all activities by date (newest first)
        usort($activities, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // Limit to 10 most recent activities
        $activities = array_slice($activities, 0, 10);

        return $activities;
    }

    private function getWishlistItems($userId)
    {
        $wishlistItems = Wishlist::where('user_id', $userId)
            ->with(['product.images' => function ($query) {
                $query->orderBy('is_primary', 'desc')
                    ->orderBy('sort_order');
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
        $cart = Cart::where('user_id', $userId)
            ->latest()
            ->first();

        if (!$cart) {
            return [];
        }

        $cartItems = DB::table('cart_items')
            ->where('cart_id', $cart->id)
            ->get();

        $items = [];
        foreach ($cartItems as $cartItem) {
            $product = Product::with(['images' => function ($query) {
                $query->orderBy('is_primary', 'desc')
                    ->orderBy('sort_order');
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
}