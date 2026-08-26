<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
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
     */
    protected function getCurrentProductPrice($product, $variant = null, $user = null)
    {
        if (!$user) {
            $user = auth('sanctum')->user();
        }

        $isDistributor = $user && $user->account_type === 'distributor';

        // If variant is provided, use variant pricing
        if ($variant) {
            return $isDistributor
                ? ($variant->distributor_price ?? $variant->retail_price)
                : $variant->retail_price;
        }

        // Use product pricing
        return $isDistributor
            ? ($product->distributor_price ?? $product->retail_price)
            : $product->retail_price;
    }

    /**
     * Get or create cart for the current user/session
     */ 
    protected function getCart(Request $request)
    {
        $user = auth('sanctum')->user();
        $sessionId = $request->header('X-Session-ID');

        if ($user) {
            $cart = Cart::with(['items.product.images', 'items.variant.images'])
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

        $cart = Cart::with(['items.product.images', 'items.variant.images'])
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
     * Add item to cart (supports both product and variant)
     */
    public function add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required_without:variant_id', 'exists:products,id'],
            'variant_id' => ['required_without:product_id', 'exists:product_variants,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'from_wishlist' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $productId = $request->product_id;
        $variantId = $request->variant_id;
        $quantity = $request->quantity ?? 1;
        $fromWishlist = $request->boolean('from_wishlist') ?? false;

        // If variant_id is provided, get the product from variant
        if ($variantId) {
            $variant = ProductVariant::with('product')->find($variantId);
            if (!$variant) {
                return response()->json(['message' => 'Variant not found'], 404);
            }
            $product = $variant->product;
            if (!$product) {
                return response()->json(['message' => 'Product not found'], 404);
            }
        } else {
            $product = Product::find($productId);
            if (!$product) {
                return response()->json(['message' => 'Product not found'], 404);
            }
        }

        DB::beginTransaction();

        try {
            $cart = $this->getCart($request);

            // Check if item already exists in cart (same product and variant)
            $cartItem = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->when($variantId, function ($query) use ($variantId) {
                    return $query->where('variant_id', $variantId);
                })
                ->first();

            if ($cartItem) {
                $cartItem->quantity += $quantity;
                $cartItem->unit_price = 0;
                $cartItem->save();
            } else {
                $cartItem = CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                    'unit_price' => 0,
                ]);
            }

            // If item was added to cart from wishlist, remove it from wishlist
            if ($fromWishlist) {
                $user = auth('sanctum')->user();
                if ($user) {
                    Wishlist::where('user_id', $user->id)
                        ->where('product_id', $product->id)
                        ->when($variantId, function ($query) use ($variantId) {
                            return $query->where('variant_id', $variantId);
                        })
                        ->delete();
                }
            }

            DB::commit();

            $cart->load(['items.product.images', 'items.variant.images']);
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
                ->where('id', $itemId)
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
                    if ($cartItem->quantity <= 0) {
                        $cartItem->delete();
                        DB::commit();
                        $cart->load(['items.product.images', 'items.variant.images']);
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
                        $cart->load(['items.product.images', 'items.variant.images']);
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

            if (isset($cartItem) && $cartItem->exists) {
                $cartItem->save();
            }

            DB::commit();

            $cart->load(['items.product.images', 'items.variant.images']);
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

            $cart->load(['items.product.images', 'items.variant.images']);
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
            $guestCart = Cart::where('session_id', $sessionId)
                ->whereNull('user_id')
                ->with(['items.product.images', 'items.variant.images'])
                ->first();

            if (!$guestCart || $guestCart->items->isEmpty()) {
                return response()->json([
                    'message' => 'No guest cart items to merge',
                    'data' => $this->getUserCart($user),
                    'user_type' => $this->getUserType(),
                ]);
            }

            $userCart = Cart::where('user_id', $user->id)->first();

            if (!$userCart) {
                $guestCart->update([
                    'user_id' => $user->id,
                    'session_id' => null,
                ]);

                foreach ($guestCart->items as $item) {
                    $item->unit_price = 0;
                    $item->save();
                }

                DB::commit();
                $userCart = $guestCart;
            } else {
                foreach ($guestCart->items as $guestItem) {
                    $existingItem = CartItem::where('cart_id', $userCart->id)
                        ->where('product_id', $guestItem->product_id)
                        ->where('variant_id', $guestItem->variant_id)
                        ->first();

                    if ($existingItem) {
                        $existingItem->quantity += $guestItem->quantity;
                        $existingItem->unit_price = 0;
                        $existingItem->save();
                    } else {
                        $guestItem->update([
                            'cart_id' => $userCart->id,
                            'unit_price' => 0,
                        ]);
                    }
                }

                $guestCart->delete();
                DB::commit();
            }

            $userCart->load(['items.product.images', 'items.variant.images']);

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
        $cart = Cart::with(['items.product.images', 'items.variant.images'])
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

        $items = $cart->items->map(function ($item) use ($isDistributor, $user) {
            $product = $item->product;
            $variant = $item->variant;
            $currentPrice = 0;
            $variantAttributes = null;

            if ($variant) {
                // Use variant pricing
                $currentPrice = $isDistributor
                    ? ($variant->distributor_price ?? $variant->retail_price)
                    : $variant->retail_price;
                $variantAttributes = $variant->attributes;

                // Get variant image
                $variantImage = $variant->images->where('is_primary', true)->first()
                    ?? $variant->images->first();
                $imageUrl = $variantImage ? asset('storage/' . $variantImage->image) : null;
            } elseif ($product) {
                // Use product pricing
                $currentPrice = $isDistributor
                    ? ($product->distributor_price ?? $product->retail_price)
                    : $product->retail_price;

                $imageUrl = $product->primaryImage ?
                    asset('storage/' . $product->primaryImage->image) : null;
            }

            $subtotal = $currentPrice * $item->quantity;

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'product' => $product ? [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'product_code' => $product->product_code,
                    'retail_price' => $product->retail_price,
                    'distributor_price' => $product->distributor_price,
                ] : null,
                'variant' => $variant ? [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'attributes' => $variant->attributes,
                    'attribute_string' => $this->getAttributeString($variant->attributes),
                    'retail_price' => $variant->retail_price,
                    'distributor_price' => $variant->distributor_price,
                    'stock_quantity' => $variant->stock_quantity,
                ] : null,
                'variant_attributes' => $variantAttributes,
                'quantity' => $item->quantity,
                'current_unit_price' => $currentPrice,
                'current_unit_price_formatted' => number_format($currentPrice, 2),
                'subtotal' => $subtotal,
                'subtotal_formatted' => number_format($subtotal, 2),
                'image_url' => $imageUrl ?? null,
                'current_price_type' => $isDistributor ? 'distributor' : 'retail',
            ];
        })->values()->toArray();

        $total = collect($items)->sum('subtotal');
        $totalItems = collect($items)->sum('quantity');

        return [
            'id' => $cart->id,
            'items' => $items,
            'total' => $total,
            'total_formatted' => number_format($total, 2),
            'total_items' => $totalItems,
            'price_type' => $isDistributor ? 'distributor' : 'retail',
            'price_calculation' => 'dynamic',
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
            $price = 0;
            if ($item->variant) {
                $price = $isDistributor
                    ? ($item->variant->distributor_price ?? $item->variant->retail_price)
                    : $item->variant->retail_price;
            } elseif ($item->product) {
                $price = $isDistributor
                    ? ($item->product->distributor_price ?? $item->product->retail_price)
                    : $item->product->retail_price;
            }
            $total += $price * $item->quantity;
            $totalItems += $item->quantity;
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
            'count' => $cart->items->sum('quantity'),
            'user_type' => $this->getUserType(),
        ]);
    }

    /**
     * Get attribute string from attributes array
     */
    protected function getAttributeString($attributes)
    {
        if (empty($attributes)) {
            return '';
        }

        return collect($attributes)
            ->map(function ($value, $key) {
                return $key . ': ' . $value;
            })
            ->implode(' | ');
    }
}