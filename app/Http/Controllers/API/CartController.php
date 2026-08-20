<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CartController extends Controller
{
    /**
     * Get the current price for a product based on user account type
     * This is dynamic and always uses the latest product price
     */
    protected function getCurrentProductPrice(Product $product, $user = null)
    {
        // If no user provided, try to get authenticated user
        if (!$user) {
            $user = auth('sanctum')->user();
        }

        // If user is authenticated and is a distributor, use distributor price
        if ($user && $user->account_type === 'distributor') {
            return $product->distributor_price ?? $product->retail_price;
        }

        // Default to retail price for customers and guests
        return $product->retail_price;
    }

    /**
     * Get or create cart for the current user/session
     */
    protected function getCart(Request $request)
    {
        $user = auth('sanctum')->user();
        $sessionId = $request->header('X-Session-ID');

        if ($user) {
            // For authenticated users
            $cart = Cart::with('items.product.images')
                ->where('user_id', $user->id)
                ->first();
            if (!$cart) {
                $cart = Cart::create([
                    'user_id' => $user->id,
                    'session_id' => null,
                ]);
            }

            return $cart;
        }

        // For guest users
        if (!$sessionId) {
            $sessionId = Str::uuid()->toString();
        }

        $cart = Cart::with('items.product.images')
            ->where('session_id', $sessionId)
            ->first();

        if (!$cart) {
            $cart = Cart::create([
                'user_id' => null,
                'session_id' => $sessionId,
            ]);
        }

        return $cart;
    }

    /**
     * Get cart items with dynamic pricing
     */
    public function index(Request $request)
    {
        $cart = $this->getCart($request);
        $user = auth('sanctum')->user();

        return response()->json([
            'data' => $this->formatCart($cart, $user),
            'is_guest' => !auth('sanctum')->check(),
            'session_id' => $cart->session_id,
            'user_type' => $this->getUserType(),
        ]);
    }

    /**
     * Get user type for response
     */
    protected function getUserType()
    {
        $user = auth('sanctum')->user();
        if (!$user) {
            return 'guest';
        }
        return $user->account_type ?? 'customer';
    }

    /**
     * Add item to cart
     * Store only the product_id and quantity, not the price
     */
    // public function add(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'product_id' => ['required', 'exists:products,id'],
    //         'quantity' => ['nullable', 'integer', 'min:1'],
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => $validator->errors()], 422);
    //     }

    //     $productId = $request->product_id;
    //     $quantity = $request->quantity ?? 1;

    //     // Get product
    //     $product = Product::find($productId);
    //     if (!$product) {
    //         return response()->json(['message' => 'Product not found'], 404);
    //     }

    //     DB::beginTransaction();
    //     try {
    //         // Get or create cart
    //         $cart = $this->getCart($request);

    //         // Check if item already exists in cart
    //         $cartItem = CartItem::where('cart_id', $cart->id)
    //             ->where('product_id', $productId)
    //             ->first();

    //         if ($cartItem) {
    //             // Update quantity only - keep the unit_price as null or 0 since we don't store prices anymore
    //             $cartItem->quantity += $quantity;
    //             $cartItem->unit_price = 0;
    //             $cartItem->save();
    //         } else {
    //             // Add new item - store only product_id and quantity
    //             $cartItem = CartItem::create([
    //                 'cart_id' => $cart->id,
    //                 'product_id' => $productId,
    //                 'quantity' => $quantity,
    //                 'unit_price' => 0, // We don't store price anymore
    //             ]);
    //         }

    //         DB::commit();

    //         // Reload cart with items
    //         $cart->load('items.product.images');
    //         $user = auth('sanctum')->user();

    //         return response()->json([
    //             'message' => 'Item added to cart successfully',
    //             'data' => $this->formatCart($cart, $user),
    //             'summary' => $this->getCartSummary($cart, $user),
    //             'user_type' => $this->getUserType(),
    //         ], 201);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'message' => 'Failed to add item to cart',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
    public function add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'from_wishlist' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $productId = $request->product_id;
        $quantity = $request->quantity ?? 1;
        $fromWishlist = $request->boolean('from_wishlist');

        $product = Product::find($productId);

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        DB::beginTransaction();

        try {
            $cart = $this->getCart($request);

            $cartItem = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $productId)
                ->first();

            if ($cartItem) {
                $cartItem->quantity += $quantity;
                $cartItem->unit_price = 0;
                $cartItem->save();
            } else {
                $cartItem = CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => 0,
                ]);
            }

            /*
         * If item was added to cart from wishlist,
         * remove it from wishlist.
         */
            if ($fromWishlist) {
                $user = auth('sanctum')->user();

                if ($user) {
                    Wishlist::where('user_id', $user->id)
                        ->where('product_id', $productId)
                        ->delete();
                }
            }

            DB::commit();

            $cart->load('items.product.images');

            $user = auth('sanctum')->user();

            return response()->json([
                'message' => 'Item added to cart successfully',
                'data' => $this->formatCart($cart, $user),
                'summary' => $this->getCartSummary($cart, $user),
                'user_type' => $this->getUserType(),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to add item to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update cart item quantity
     */
    /**
     * Update cart item quantity
     * Supports increment, decrement, and set operations
     */
    public function update(Request $request, $itemId)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'in:increment,decrement,set'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $cart = $this->getCart($request);
            $cartItem = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $itemId)
                ->first();

            if (!$cartItem) {
                return response()->json(['message' => 'Item not found in cart'], 404);
            }

            $action = $request->action ?? 'set';
            $quantity = $request->quantity ?? 1;

            switch ($action) {
                case 'increment':
                    $cartItem->quantity += 1;
                    break;
                case 'decrement':
                    $cartItem->quantity -= 1;
                    // If quantity becomes 0, remove the item
                    if ($cartItem->quantity <= 0) {
                        $cartItem->delete();
                        DB::commit();
                        $cart->load('items.product.images');
                        $user = auth('sanctum')->user();
                        return response()->json([
                            'message' => 'Item removed from cart',
                            'data' => $this->formatCart($cart, $user),
                            'summary' => $this->getCartSummary($cart, $user),
                            'user_type' => $this->getUserType(),
                        ]);
                    }
                    break;
                case 'set':
                    if ($quantity <= 0) {
                        $cartItem->delete();
                        DB::commit();
                        $cart->load('items.product.images');
                        $user = auth('sanctum')->user();
                        return response()->json([
                            'message' => 'Item removed from cart',
                            'data' => $this->formatCart($cart, $user),
                            'summary' => $this->getCartSummary($cart, $user),
                            'user_type' => $this->getUserType(),
                        ]);
                    }
                    $cartItem->quantity = $quantity;
                    break;
                default:
                    return response()->json(['message' => 'Invalid action'], 400);
            }

            // Save if not deleted
            if (isset($cartItem) && $cartItem->exists) {
                $cartItem->save();
            }

            DB::commit();

            $cart->load('items.product.images');
            $user = auth('sanctum')->user();

            return response()->json([
                'message' => 'Cart updated successfully',
                'data' => $this->formatCart($cart, $user),
                'summary' => $this->getCartSummary($cart, $user),
                'user_type' => $this->getUserType(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove item from cart
     */
    public function remove(Request $request, $itemId)
    {
        DB::beginTransaction();
        try {
            $cart = $this->getCart($request);
            $cartItem = CartItem::where('cart_id', $cart->id)
                ->where('id', $itemId)
                ->first();

            if (!$cartItem) {
                return response()->json(['message' => 'Item not found in cart'], 404);
            }

            $cartItem->delete();

            DB::commit();

            $cart->load('items.product.images');
            $user = auth('sanctum')->user();

            return response()->json([
                'message' => 'Item removed from cart',
                'data' => $this->formatCart($cart, $user),
                'summary' => $this->getCartSummary($cart, $user),
                'user_type' => $this->getUserType(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to remove item from cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear entire cart
     */
    public function clear(Request $request)
    {
        DB::beginTransaction();
        try {
            $cart = $this->getCart($request);
            $cart->items()->delete();

            DB::commit();

            $user = auth('sanctum')->user();

            return response()->json([
                'message' => 'Cart cleared successfully',
                'data' => $this->formatCart($cart, $user),
                'summary' => $this->getCartSummary($cart, $user),
                'user_type' => $this->getUserType(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to clear cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Merge guest cart with user cart after login
     * This will preserve quantities and use dynamic pricing
     */
    public function mergeCart(Request $request)
    {
        $user = auth('sanctum')->user();
        $sessionId = $request->header('X-Session-ID');

        if (!$user) {
            return response()->json(['message' => 'User not authenticated'], 401);
        }

        if (!$sessionId) {
            return response()->json(['message' => 'Session ID required'], 422);
        }

        DB::beginTransaction();
        try {
            // Get guest cart
            $guestCart = Cart::where('session_id', $sessionId)
                ->whereNull('user_id')
                ->first();

            if (!$guestCart || $guestCart->items->isEmpty()) {
                return response()->json([
                    'message' => 'No guest cart items to merge',
                    'data' => $this->getUserCart($user),
                    'user_type' => $this->getUserType(),
                ]);
            }

            // Get user cart
            $userCart = Cart::where('user_id', $user->id)->first();

            if (!$userCart) {
                // If user has no cart, assign guest cart to user
                $guestCart->update([
                    'user_id' => $user->id,
                    'session_id' => null,
                ]);

                // Reset unit_price to 0 for all items (prices will be dynamic)
                foreach ($guestCart->items as $item) {
                    $item->unit_price = 0;
                    $item->save();
                }

                DB::commit();
                $userCart = $guestCart;
            } else {
                // Merge guest cart items into user cart
                foreach ($guestCart->items as $guestItem) {
                    // Check if item already exists in user cart
                    $existingItem = CartItem::where('cart_id', $userCart->id)
                        ->where('product_id', $guestItem->product_id)
                        ->first();

                    if ($existingItem) {
                        // Update quantity only
                        $existingItem->quantity += $guestItem->quantity;
                        $existingItem->unit_price = 0; // Reset price
                        $existingItem->save();
                    } else {
                        // Move item to user cart with zero price (will be dynamic)
                        $guestItem->update([
                            'cart_id' => $userCart->id,
                            'unit_price' => 0,
                        ]);
                    }
                }

                // Delete guest cart
                $guestCart->delete();

                DB::commit();
            }

            // Load user cart with items
            $userCart->load('items.product.images');

            return response()->json([
                'message' => 'Guest cart merged successfully',
                'data' => $this->formatCart($userCart, $user),
                'summary' => $this->getCartSummary($userCart, $user),
                'user_type' => $this->getUserType(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to merge cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's cart (helper method)
     */
    protected function getUserCart($user)
    {
        $cart = Cart::with('items.product.images')
            ->where('user_id', $user->id)
            ->first();

        if (!$cart) {
            $cart = Cart::create([
                'user_id' => $user->id,
                'session_id' => null,
            ]);
        }

        return $cart;
    }

    /**
     * Format cart for response with dynamic pricing
     */
    protected function formatCart($cart, $user = null)
    {
        $isDistributor = $user && $user->account_type === 'distributor';

        // Calculate dynamic totals
        $items = $cart->items->map(function ($item) use ($isDistributor) {
            $product = $item->product;
            $currentPrice = 0;

            if ($product) {
                // Get dynamic price based on user type
                if ($isDistributor) {
                    $currentPrice = $product->distributor_price ?? $product->retail_price;
                } else {
                    $currentPrice = $product->retail_price;
                }
            }

            // Calculate subtotal with dynamic price
            $subtotal = $currentPrice * $item->quantity;

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $product ? [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'product_code' => $product->product_code,
                    'retail_price' => $product->retail_price,
                    'distributor_price' => $product->distributor_price,
                    'primary_image' => $product->primaryImage ?
                        asset('storage/' . $product->primaryImage->image) : null,
                    'current_price_type' => $isDistributor ? 'distributor' : 'retail',
                ] : null,
                'quantity' => $item->quantity,
                'current_unit_price' => $currentPrice,
                'current_unit_price_formatted' => number_format($currentPrice, 2),
                'subtotal' => $subtotal,
                'subtotal_formatted' => number_format($subtotal, 2),
                // Keep original stored price (if any) for reference, but don't use it
                'stored_unit_price' => $item->unit_price,
            ];
        })->values()->toArray();

        // Calculate totals
        $total = collect($items)->sum('subtotal');
        $totalItems = collect($items)->sum('quantity');

        return [
            'id' => $cart->id,
            'items' => $items,
            'total' => $total,
            'total_formatted' => number_format($total, 2),
            'total_items' => $totalItems,
            'price_type' => $isDistributor ? 'distributor' : 'retail',
            'price_calculation' => 'dynamic', // Indicate that prices are calculated on-the-fly
        ];
    }

    /**
     * Get cart summary with dynamic pricing
     */
    protected function getCartSummary($cart, $user = null)
    {
        $isDistributor = $user && $user->account_type === 'distributor';

        $total = 0;
        $totalItems = 0;

        foreach ($cart->items as $item) {
            $product = $item->product;
            if ($product) {
                $price = $isDistributor
                    ? ($product->distributor_price ?? $product->retail_price)
                    : $product->retail_price;
                $total += $price * $item->quantity;
                $totalItems += $item->quantity;
            }
        }

        return [
            'total_items' => $totalItems,
            'total' => $total,
            'total_formatted' => number_format($total, 2),
        ];
    }

    /**
     * Get cart count (for badge/navigation)
     */
    public function count(Request $request)
    {
        $cart = $this->getCart($request);

        return response()->json([
            'count' => $cart->items->sum('quantity'), // Count total items quantity
            'user_type' => $this->getUserType(),
        ]);
    }
}
