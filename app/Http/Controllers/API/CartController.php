<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\CouponUsage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\StockGuardService;

class CartController extends Controller
{
    protected StockGuardService $stockGuard;

    public function __construct(StockGuardService $stockGuard)
    {
        $this->stockGuard = $stockGuard;
    }
    /**
     * Get the current price for a product based on user account type
     */
    protected function getCurrentProductPrice($product, $variant = null, $user = null)
    {
        if (!$user) {
            $user = auth('sanctum')->user();
        }

        $isDistributor = $user && $user->account_type === 'distributor';

        if ($variant) {
            return $isDistributor
                ? ($variant->distributor_price ?? $variant->retail_price)
                : $variant->retail_price;
        }

        return $isDistributor
            ? ($product->distributor_price ?? $product->retail_price)
            : $product->retail_price;
    }

    /**
     * Get or create cart for the authenticated user
     */
    // protected function getCart(Request $request)
    // {
    //     $user = auth('sanctum')->user();

    //     if (!$user) {
    //         abort(response()->json([
    //             'message' => 'Unauthenticated. Please login to use the cart.'
    //         ], 401));
    //     }

    //     $relations = [
    //         'items.product.primaryImage',
    //         'items.product.images',
    //         'items.product.category:id,title,slug',
    //         'items.product.subcategory:id,category_id,name,slug',
    //         'items.product.brand:id,category_id,title',   // <- slug hata diya
    //         'items.product.taxCategory',
    //         'items.variant.images',
    //     ];

    //     $cart = Cart::with($relations)
    //         ->where('user_id', $user->id)
    //         ->first();

    //     if (!$cart) {
    //         $cart = Cart::create(['user_id' => $user->id]);
    //         $cart->load($relations);
    //     }

    //     return $cart;
    // }
    protected function getCart(Request $request)
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            abort(response()->json([
                'message' => 'Unauthenticated. Please login to use the cart.'
            ], 401));
        }

        $relations = [
            'items.product.primaryImage',
            'items.product.images',
            'items.product.category:id,title,slug',
            'items.product.subcategory:id,category_id,name,slug',
            'items.product.brand:id,category_id,title',
            'items.product.taxCategory',
            'items.variant.images',
        ];

        $cart = Cart::with($relations)
            ->where('user_id', $user->id)
            ->first();

        if (!$cart) {
            $cart = Cart::create(['user_id' => $user->id]);
            $cart->load($relations);
        }

        return $cart;
    }
    /**
     * Get cart items with dynamic pricing
     */
    // public function index(Request $request)
    // {
    //     $cart = $this->getCart($request);
    //     $user = auth('sanctum')->user();

    //     return response()->json([
    //         'data' => $this->formatCart($cart, $user),
    //         'user_type' => $this->getUserType(),
    //     ]);
    // }
    public function index(Request $request)
    {
        $cart = $this->getCart($request);
        $user = auth('sanctum')->user();

        // ============================================================
        // COMBO CHILDREN (batch across all cart items)   NEW
        // ============================================================
        $cartProducts = $cart->items
            ->pluck('product')
            ->filter()
            ->unique('id')
            ->values();

        $comboChildren = $this->buildComboChildrenMap($cartProducts);

        return response()->json([
            'data' => $this->formatCart($cart, $user, $comboChildren),
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
     * Ensure all mandatory products exist in the given cart.
     * Skips duplicates and inactive products.
     */
    protected function ensureMandatoryProductsInCart(Cart $cart): void
    {
        // Already seeded? Don't add again.
        if ($cart->mandatory_products_seeded) {
            return;
        }

        $user = auth('sanctum')->user();

        if (!$user) {
            return;
        }

        // Only for users who haven't placed an order
        $hasOrdered = Order::where('user_id', $user->id)->exists();

        if ($hasOrdered) {
            $cart->mandatory_products_seeded = true;
            $cart->save();
            return;
        }

        $mandatoryProducts = Product::where('is_mandatory', true)->get();

        foreach ($mandatoryProducts as $product) {

            CartItem::firstOrCreate(
                [
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'variant_id' => null,
                ],
                [
                    'quantity' => 1,
                    'unit_price' => 0,
                    'shipping_charge' => $product->shipping_charge ?? 0,
                ]
            );
        }

        $cart->mandatory_products_seeded = true;
        $cart->save();
    }

    // public function add(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'product_id'    => ['required_without:variant_id', 'exists:products,id'],
    //         'variant_id'    => ['required_without:product_id', 'exists:product_variants,id'],
    //         'quantity'      => ['nullable', 'integer', 'min:1'],
    //         'from_wishlist' => ['nullable', 'boolean'],
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'errors' => $validator->errors()
    //         ], 422);
    //     }

    //     $productId    = $request->product_id;
    //     $variantId    = $request->variant_id;
    //     $quantity     = $request->quantity ?? 1;
    //     $fromWishlist = $request->boolean('from_wishlist');

    //     // ==========================================
    //     // GET PRODUCT / VARIANT
    //     // ==========================================


    //     if ($variantId) {

    //         $variant = ProductVariant::with('product')->find($variantId);

    //         if (!$variant) {
    //             return response()->json([
    //                 'message' => 'Variant not found'
    //             ], 404);
    //         }

    //         $product = $variant->product;

    //         if (!$product) {
    //             return response()->json([
    //                 'message' => 'Product not found'
    //             ], 404);
    //         }
    //     } else {

    //         $product = Product::find($productId);

    //         if (!$product) {
    //             return response()->json([
    //                 'message' => 'Product not found'
    //             ], 404);
    //         }
    //     }



    //     DB::beginTransaction();

    //     try {

    //         $user = auth('sanctum')->user();

    //         $cart = $this->getCart($request);

    //         // ==========================================
    //         // ADD REQUESTED PRODUCT TO CART
    //         // ==========================================

    //         $shippingCharge = $product->shipping_charge ?? 0;

    //         $cartItem = CartItem::where('cart_id', $cart->id)
    //             ->where('product_id', $product->id)
    //             ->where('variant_id', $variantId)
    //             ->first();

    //         if ($cartItem) {

    //             $cartItem->quantity += $quantity;
    //             $cartItem->unit_price = 0;
    //             $cartItem->shipping_charge = $shippingCharge;
    //             $cartItem->save();
    //         } else {

    //             CartItem::create([
    //                 'cart_id'         => $cart->id,
    //                 'product_id'      => $product->id,
    //                 'variant_id'      => $variantId,
    //                 'quantity'        => $quantity,
    //                 'unit_price'      => 0,
    //                 'shipping_charge' => $shippingCharge,
    //             ]);
    //         }

    //         // ==========================================
    //         // AUTO ADD MANDATORY PRODUCTS
    //         // ONLY IF USER HAS NOT PLACED ANY ORDER
    //         // ==========================================

    //         if ($user) {

    //             $hasOrdered = Order::where('user_id', $user->id)
    //                 ->exists();

    //             if (!$hasOrdered) {

    //                 $mandatoryProducts = Product::where('is_mandatory', true)
    //                     ->where('is_mandatory', true)
    //                     ->get();

    //                 foreach ($mandatoryProducts as $mandatoryProduct) {

    //                     // Already exists in cart?
    //                     $exists = CartItem::where('cart_id', $cart->id)
    //                         ->where('product_id', $mandatoryProduct->id)
    //                         ->whereNull('variant_id')
    //                         ->exists();

    //                     if ($exists) {
    //                         continue;
    //                     }

    //                     CartItem::create([
    //                         'cart_id'         => $cart->id,
    //                         'product_id'      => $mandatoryProduct->id,
    //                         'variant_id'      => null,
    //                         'quantity'        => 1,
    //                         'unit_price'      => 0,
    //                         'shipping_charge' => $mandatoryProduct->shipping_charge ?? 0,
    //                     ]);
    //                 }
    //             }
    //         }

    //         // ==========================================
    //         // REMOVE FROM WISHLIST
    //         // ==========================================

    //         if ($fromWishlist && $user) {

    //             Wishlist::where('user_id', $user->id)
    //                 ->where('product_id', $product->id)
    //                 ->when($variantId, function ($query) use ($variantId) {
    //                     return $query->where('variant_id', $variantId);
    //                 }, function ($query) {
    //                     return $query->whereNull('variant_id');
    //                 })
    //                 ->delete();
    //         }

    //         DB::commit();

    //         // ==========================================
    //         // LOAD CART DATA
    //         // ==========================================

    //         $cart->load([
    //             'items.product.taxCategory',
    //             'items.product.images',
    //             'items.product.primaryImage',
    //             'items.variant.images',
    //         ]);

    //         return response()->json([
    //             'message'   => 'Item added to cart successfully',
    //             'data'      => $this->formatCart($cart, $user),
    //             'summary'   => $this->getCartSummary($cart, $user),
    //             'user_type' => $this->getUserType(),
    //         ], 201);
    //     } catch (\Exception $e) {

    //         DB::rollBack();

    //         return response()->json([
    //             'message' => 'Failed to add item to cart',
    //             'error'   => $e->getMessage(),
    //         ], 500);
    //     }
    // }
    public function add(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id'    => ['required_without:variant_id', 'exists:products,id'],
            'variant_id'    => ['required_without:product_id', 'exists:product_variants,id'],
            'quantity'      => ['nullable', 'integer', 'min:1'],
            'from_wishlist' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $productId    = $request->product_id;
        $variantId    = $request->variant_id;
        $quantity     = $request->quantity ?? 1;
        $fromWishlist = $request->boolean('from_wishlist');

        $variant = null;

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
            $user = auth('sanctum')->user();
            $cart = $this->getCart($request);

            // ---------- Stock guard (respects allowed_purchased) ----------
            $existingQty = (int) (CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->where('variant_id', $variantId)
                ->value('quantity') ?? 0);

            $this->stockGuard->assertCanAdd($product, $variant, (int) $quantity, $existingQty);

            // ---------- Add / update cart item ----------
            $shippingCharge = $product->shipping_charge ?? 0;

            $cartItem = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->where('variant_id', $variantId)
                ->first();

            if ($cartItem) {
                $cartItem->quantity += $quantity;
                $cartItem->unit_price = 0;
                $cartItem->shipping_charge = $shippingCharge;
                $cartItem->save();
            } else {
                CartItem::create([
                    'cart_id'         => $cart->id,
                    'product_id'      => $product->id,
                    'variant_id'      => $variantId,
                    'quantity'        => $quantity,
                    'unit_price'      => 0,
                    'shipping_charge' => $shippingCharge,
                ]);
            }

            // ---------- Auto add mandatory products (first-time users) ----------
            if ($user) {
                $hasOrdered = Order::where('user_id', $user->id)->exists();

                if (!$hasOrdered) {
                    $mandatoryProducts = Product::where('is_mandatory', true)->get();

                    foreach ($mandatoryProducts as $mandatoryProduct) {
                        $exists = CartItem::where('cart_id', $cart->id)
                            ->where('product_id', $mandatoryProduct->id)
                            ->whereNull('variant_id')
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        CartItem::create([
                            'cart_id'         => $cart->id,
                            'product_id'      => $mandatoryProduct->id,
                            'variant_id'      => null,
                            'quantity'        => 1,
                            'unit_price'      => 0,
                            'shipping_charge' => $mandatoryProduct->shipping_charge ?? 0,
                        ]);
                    }
                }
            }

            // ---------- Remove from wishlist ----------
            if ($fromWishlist && $user) {
                Wishlist::where('user_id', $user->id)
                    ->where('product_id', $product->id)
                    ->when($variantId, function ($query) use ($variantId) {
                        return $query->where('variant_id', $variantId);
                    }, function ($query) {
                        return $query->whereNull('variant_id');
                    })
                    ->delete();
            }

            DB::commit();

            $cart->load([
                'items.product.taxCategory',
                'items.product.images',
                'items.product.primaryImage',
                'items.variant.images',
            ]);

            return response()->json([
                'message'   => 'Item added to cart successfully',
                'data'      => $this->formatCart($cart, $user),
                'summary'   => $this->getCartSummary($cart, $user),
                'user_type' => $this->getUserType(),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->getMessage(),
                'success' => false,
            ], 422);
        }
    }
    /**
     * Add item to cart (supports both product and variant)
     */


    /**
     * Update cart item quantity
     */
    // public function update(Request $request, $itemId)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'quantity' => ['nullable', 'integer', 'min:1'],
    //         'action' => ['nullable', 'in:increment,decrement,set'],
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => $validator->errors()], 422);
    //     }

    //     DB::beginTransaction();
    //     try {
    //         $cart = $this->getCart($request);
    //         $cartItem = CartItem::where('cart_id', $cart->id)
    //             ->where('id', $itemId)
    //             ->first();

    //         if (!$cartItem) {
    //             return response()->json(['message' => 'Item not found in cart'], 404);
    //         }

    //         $action = $request->action ?? 'set';
    //         $quantity = $request->quantity ?? 1;

    //         switch ($action) {
    //             case 'increment':
    //                 $cartItem->quantity += 1;
    //                 break;
    //             case 'decrement':
    //                 $cartItem->quantity -= 1;
    //                 if ($cartItem->quantity <= 0) {
    //                     $cartItem->delete();
    //                     DB::commit();
    //                     $cart->load(['items.product.images', 'items.variant.images']);
    //                     $user = auth('sanctum')->user();
    //                     return response()->json([
    //                         'message' => 'Item removed from cart',
    //                         'data' => $this->formatCart($cart, $user),
    //                         'summary' => $this->getCartSummary($cart, $user),
    //                         'user_type' => $this->getUserType(),
    //                     ]);
    //                 }
    //                 break;
    //             case 'set':
    //                 if ($quantity <= 0) {
    //                     $cartItem->delete();
    //                     DB::commit();
    //                     $cart->load(['items.product.images', 'items.variant.images']);
    //                     $user = auth('sanctum')->user();
    //                     return response()->json([
    //                         'message' => 'Item removed from cart',
    //                         'data' => $this->formatCart($cart, $user),
    //                         'summary' => $this->getCartSummary($cart, $user),
    //                         'user_type' => $this->getUserType(),
    //                     ]);
    //                 }
    //                 $cartItem->quantity = $quantity;
    //                 break;
    //             default:
    //                 return response()->json(['message' => 'Invalid action'], 400);
    //         }

    //         if (isset($cartItem) && $cartItem->exists) {
    //             $cartItem->save();
    //         }

    //         DB::commit();

    //         $cart->load(['items.product.images', 'items.variant.images']);
    //         $user = auth('sanctum')->user();

    //         return response()->json([
    //             'message' => 'Cart updated successfully',
    //             'data' => $this->formatCart($cart, $user),
    //             'summary' => $this->getCartSummary($cart, $user),
    //             'user_type' => $this->getUserType(),
    //         ]);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'message' => 'Failed to update cart',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
    public function update(Request $request, $itemId)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => ['nullable', 'integer', 'min:1'],
            'action'   => ['nullable', 'in:increment,decrement,set'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $cart = $this->getCart($request);
            $cartItem = CartItem::with(['product', 'variant'])
                ->where('cart_id', $cart->id)
                ->where('id', $itemId)
                ->first();

            if (!$cartItem) {
                return response()->json(['message' => 'Item not found in cart'], 404);
            }

            $action   = $request->action ?? 'set';
            $quantity = $request->quantity ?? 1;

            // Compute proposed final quantity
            $proposedQty = match ($action) {
                'increment' => $cartItem->quantity + 1,
                'decrement' => $cartItem->quantity - 1,
                'set'       => (int) $quantity,
                default     => $cartItem->quantity,
            };

            // ---------- Remove item branches ----------
            if ($action === 'decrement' && $proposedQty <= 0) {
                $cartItem->delete();
                DB::commit();
                $cart->load(['items.product.images', 'items.variant.images']);
                $user = auth('sanctum')->user();
                return response()->json([
                    'message'   => 'Item removed from cart',
                    'data'      => $this->formatCart($cart, $user),
                    'summary'   => $this->getCartSummary($cart, $user),
                    'user_type' => $this->getUserType(),
                ]);
            }

            if ($action === 'set' && $proposedQty <= 0) {
                $cartItem->delete();
                DB::commit();
                $cart->load(['items.product.images', 'items.variant.images']);
                $user = auth('sanctum')->user();
                return response()->json([
                    'message'   => 'Item removed from cart',
                    'data'      => $this->formatCart($cart, $user),
                    'summary'   => $this->getCartSummary($cart, $user),
                    'user_type' => $this->getUserType(),
                ]);
            }

            // ---------- Stock guard ----------
            if ($proposedQty > $cartItem->quantity) {
                // only check when increasing
                $this->stockGuard->assertCanAdd(
                    $cartItem->product,
                    $cartItem->variant,
                    $proposedQty
                );
            }

            $cartItem->quantity = $proposedQty;
            $cartItem->save();

            DB::commit();

            $cart->load(['items.product.images', 'items.variant.images']);
            $user = auth('sanctum')->user();

            return response()->json([
                'message'   => 'Cart updated successfully',
                'data'      => $this->formatCart($cart, $user),
                'summary'   => $this->getCartSummary($cart, $user),
                'user_type' => $this->getUserType(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->getMessage(),
                'success' => false,
            ], 422);
        }
    }

    /**
     * Remove item from cart
     */
    // public function remove(Request $request, $itemId)
    // {
    //     DB::beginTransaction();
    //     try {
    //         $cart = $this->getCart($request);
    //         $cartItem = CartItem::where('cart_id', $cart->id)
    //             ->where('id', $itemId)
    //             ->first();

    //         if (!$cartItem) {
    //             return response()->json(['message' => 'Item not found in cart'], 404);
    //         }

    //         $cartItem->delete();

    //         DB::commit();

    //         $cart->load(['items.product.images', 'items.variant.images']);
    //         $user = auth('sanctum')->user();

    //         return response()->json([
    //             'message' => 'Item removed from cart',
    //             'data' => $this->formatCart($cart, $user),
    //             'summary' => $this->getCartSummary($cart, $user),
    //             'user_type' => $this->getUserType(),
    //         ]);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'message' => 'Failed to remove item from cart',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function remove(Request $request, $itemId)
    {
        DB::beginTransaction();

        try {
            $cart = $this->getCart($request);
            $user = auth('sanctum')->user();

            $cartItem = CartItem::with('product')
                ->where('cart_id', $cart->id)
                ->where('id', $itemId)
                ->first();

            if (!$cartItem) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Item not found in cart',
                ], 404);
            }

            // Check whether user has placed any order
            $hasOrdered = $user
                ? Order::where('user_id', $user->id)->exists()
                : true;

            // Check whether requested item is mandatory
            $isMandatoryItem = $cartItem->product
                && (bool) $cartItem->product->is_mandatory;

            // Apply restriction only for first-time users
            if (!$hasOrdered && $isMandatoryItem) {

                // Get all other non-mandatory products from cart
                $otherProducts = CartItem::with('product')
                    ->where('cart_id', $cart->id)
                    ->where('id', '!=', $cartItem->id)
                    ->whereHas('product', function ($query) {
                        $query->where(function ($q) {
                            $q->where('is_mandatory', false)
                                ->orWhereNull('is_mandatory');
                        });
                    })
                    ->get();

                // Block mandatory product removal if other products exist
                if ($otherProducts->isNotEmpty()) {

                    $productNames = $otherProducts
                        ->map(function ($item) {
                            return $item->product->name ?? 'Unknown Product';
                        })
                        ->unique()
                        ->values()
                        ->implode(', ');

                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => "Please remove {$productNames} first. Mandatory products can only be removed after removing these products.",
                    ], 422);
                }
            }

            // Delete requested cart item
            $deleted = CartItem::where('cart_id', $cart->id)
                ->where('id', $itemId)
                ->delete();

            if (!$deleted) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to remove item from cart',
                ], 500);
            }

            DB::commit();

            // Refresh cart relations
            $cart->unsetRelation('items');

            $cart->load([
                'items.product.taxCategory',
                'items.product.images',
                'items.product.primaryImage',
                'items.variant.images',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Item removed from cart successfully',
                'data' => $this->formatCart($cart, $user),
                'summary' => $this->getCartSummary($cart, $user),
                'user_type' => $this->getUserType(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove item from cart',
                'error' => $e->getMessage(),
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
     * Format cart for response with dynamic pricing
     */
    // protected function formatCart($cart, $user = null)
    // {
    //     $isDistributor = $user && $user->account_type === 'distributor';

    //     $items = $cart->items->map(function ($item) use ($isDistributor) {
    //         $product = $item->product;
    //         $variant = $item->variant;

    //         $currentPrice          = 0;
    //         $variantAttributes     = null;
    //         $imageUrl              = null;
    //         $mrp                   = null;
    //         $discountType          = null;
    //         $discountValue         = null;
    //         $discountAmountPerUnit = 0;

    //         // ---------------- variant path ----------------
    //         if ($variant) {
    //             $currentPrice = $isDistributor
    //                 ? ($variant->distributor_price ?? $variant->retail_price)
    //                 : $variant->retail_price;

    //             $mrp = $isDistributor
    //                 ? ($variant->distributor_mrp ?? $variant->retail_mrp ?? null)
    //                 : ($variant->retail_mrp ?? null);

    //             $discountType  = $isDistributor
    //                 ? ($variant->distributor_discount_type ?? null)
    //                 : ($variant->retail_discount_type ?? null);

    //             $discountValue = $isDistributor
    //                 ? ($variant->distributor_discount_value ?? null)
    //                 : ($variant->retail_discount_value ?? null);

    //             $variantAttributes = $variant->attributes;

    //             $variantImage = $variant->images->where('is_primary', true)->first()
    //                 ?? $variant->images->first();
    //             $imageUrl = $variantImage ? asset('storage/' . $variantImage->image) : null;
    //         }
    //         // ---------------- product fallback ----------------
    //         elseif ($product) {
    //             $currentPrice = $isDistributor
    //                 ? ($product->distributor_price ?? $product->retail_price)
    //                 : $product->retail_price;

    //             $mrp = $isDistributor
    //                 ? ($product->distributor_mrp ?? $product->retail_mrp ?? null)
    //                 : ($product->retail_mrp ?? null);

    //             $discountType  = $isDistributor
    //                 ? ($product->distributor_discount_type ?? null)
    //                 : ($product->retail_discount_type ?? null);

    //             $discountValue = $isDistributor
    //                 ? ($product->distributor_discount_value ?? null)
    //                 : ($product->retail_discount_value ?? null);

    //             $imageUrl = $product->primaryImage
    //                 ? asset('storage/' . $product->primaryImage->image)
    //                 : null;
    //         }

    //         if ($mrp !== null && $mrp > $currentPrice) {
    //             $discountAmountPerUnit = $mrp - $currentPrice;
    //         }

    //         $subtotal = $currentPrice * $item->quantity;

    //         // Availability = physical stock - reserved (never negative)
    //         $availableStock = null;
    //         if ($product && $product->stock_quantity !== null) {
    //             $availableStock = max(0, (int) $product->stock_quantity - (int) $product->reserved_inventory);
    //         }

    //         return [
    //             'id' => $item->id,
    //             'product_id' => $item->product_id,
    //             'variant_id' => $item->variant_id,

    //             'product' => $product ? [
    //                 'id' => $product->id,
    //                 'name' => $product->name,
    //                 'allowed_purchsed' => $product->allowed_purchsed,
    //                 'slug' => $product->slug,
    //                 'service_charge' => $product->service_charge,
    //                 'product_code' => $product->product_code,
    //                 'hsn_code' => $product->hsn_code,
    //                 'uom' => $product->uom,

    //                 'retail_price' => $product->retail_price,
    //                 'retail_mrp' => $product->retail_mrp,
    //                 'retail_discount_type' => $product->retail_discount_type,
    //                 'retail_discount_value' => $product->retail_discount_value,

    //                 'distributor_price' => $product->distributor_price,
    //                 'distributor_mrp' => $product->distributor_mrp,
    //                 'distributor_discount_type' => $product->distributor_discount_type,
    //                 'distributor_discount_value' => $product->distributor_discount_value,

    //                 'stock_quantity' => $product->stock_quantity,
    //                 'reserved_inventory' => $product->reserved_inventory,
    //                 'available_stock' => $availableStock,
    //                 'low_stock_threshold' => $product->low_stock_threshold,
    //                 'is_in_stock' => $availableStock !== null ? $availableStock > 0 : null,
    //                 'is_low_stock' => ($availableStock !== null && $product->low_stock_threshold !== null)
    //                     ? ($availableStock > 0 && $availableStock <= (int) $product->low_stock_threshold)
    //                     : false,

    //                 'category' => $product->relationLoaded('category') && $product->category ? [
    //                     'id' => $product->category->id,
    //                     'title' => $product->category->title,
    //                     'slug' => $product->category->slug,
    //                 ] : null,

    //                 'subcategory' => $product->relationLoaded('subcategory') && $product->subcategory ? [
    //                     'id' => $product->subcategory->id,
    //                     'name' => $product->subcategory->name,
    //                     'slug' => $product->subcategory->slug,
    //                 ] : null,

    //                 'brand' => $product->relationLoaded('brand') && $product->brand ? [
    //                     'id' => $product->brand->id,
    //                     'title' => $product->brand->title,
    //                     'category_id' => $product->brand->category_id,

    //                 ] : null,

    //                 'tax_category_id' => $product->tax_category_id,
    //                 'tax_category' => ($product->relationLoaded('taxCategory') && $product->taxCategory) ? [
    //                     'id' => $product->taxCategory->id,
    //                     'name' => $product->taxCategory->name ?? null,
    //                     'rate' => $product->taxCategory->rate ?? null,
    //                 ] : null,

    //                 'is_published' => (bool) $product->is_published,
    //                 'sale_type' => $product->sale_type,
    //                 'is_trending' => (bool) $product->is_trending,
    //                 'is_best_seller' => (bool) $product->is_best_seller,
    //                 'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
    //                 'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toIso8601String(),
    //                 'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toIso8601String(),
    //                 'is_deal_active_now' => (bool) (
    //                     $product->is_deal_of_the_day
    //                     && (!$product->deal_of_the_day_starts_at || $product->deal_of_the_day_starts_at->isPast())
    //                     && (!$product->deal_of_the_day_ends_at || $product->deal_of_the_day_ends_at->isFuture())
    //                 ),

    //                 'shipping_charge' => $product->shipping_charge,
    //                 'commission_value' => $product->commission_value,
    //                 'parent_combo_id' => $product->parent_combo_id,
    //                 'ignite_product_id' => $product->ignite_product_id,
    //             ] : null,

    //             'variant' => $variant ? [
    //                 'id' => $variant->id,
    //                 'sku' => $variant->sku,
    //                 'attributes' => $variant->attributes,
    //                 'attribute_string' => $this->getAttributeString($variant->attributes),
    //                 'retail_price' => $variant->retail_price,
    //                 'retail_mrp' => $variant->retail_mrp ?? null,
    //                 'retail_discount_type' => $variant->retail_discount_type ?? null,
    //                 'retail_discount_value' => $variant->retail_discount_value ?? null,
    //                 'distributor_price' => $variant->distributor_price,
    //                 'distributor_mrp' => $variant->distributor_mrp ?? null,
    //                 'distributor_discount_type' => $variant->distributor_discount_type ?? null,
    //                 'distributor_discount_value' => $variant->distributor_discount_value ?? null,
    //                 'stock_quantity' => $variant->stock_quantity ?? null,
    //             ] : null,

    //             'variant_attributes' => $variantAttributes,
    //             'quantity' => $item->quantity,

    //             'current_unit_price' => $currentPrice,
    //             'current_unit_price_formatted' => number_format($currentPrice, 2),

    //             'mrp' => $mrp,
    //             'mrp_formatted' => $mrp !== null ? number_format($mrp, 2) : null,
    //             'discount_type' => $discountType,
    //             'discount_value' => $discountValue,
    //             'discount_amount_per_unit' => $discountAmountPerUnit,
    //             'discount_amount_per_unit_formatted' => number_format($discountAmountPerUnit, 2),
    //             'discount_total' => $discountAmountPerUnit * $item->quantity,
    //             'discount_total_formatted' => number_format($discountAmountPerUnit * $item->quantity, 2),
    //             'discount_percentage' => ($mrp !== null && $mrp > 0 && $discountAmountPerUnit > 0)
    //                 ? round(($discountAmountPerUnit / $mrp) * 100, 2)
    //                 : 0,

    //             'subtotal' => $subtotal,
    //             'subtotal_formatted' => number_format($subtotal, 2),

    //             'image_url' => $imageUrl ?? null,
    //             'current_price_type' => $isDistributor ? 'distributor' : 'retail',
    //         ];
    //     })->values()->toArray();

    //     $total = collect($items)->sum('subtotal');
    //     $totalItems = collect($items)->sum('quantity');
    //     $totalMrp = collect($items)->sum(fn($i) => ($i['mrp'] ?? 0) * $i['quantity']);
    //     $totalDiscount = collect($items)->sum(fn($i) => $i['discount_total'] ?? 0);

    //     return [
    //         'id' => $cart->id,
    //         'items' => $items,
    //         'total' => $total,
    //         'total_formatted' => number_format($total, 2),
    //         'total_mrp' => $totalMrp,
    //         'total_mrp_formatted' => number_format($totalMrp, 2),
    //         'total_discount' => $totalDiscount,
    //         'total_discount_formatted' => number_format($totalDiscount, 2),
    //         'total_items' => $totalItems,
    //         'price_type' => $isDistributor ? 'distributor' : 'retail',
    //         'price_calculation' => 'dynamic',
    //     ];
    // }
    protected function formatCart($cart, $user = null, array $comboChildren = [])
    {
        $isDistributor = $user && $user->account_type === 'distributor';

        // If caller didn't pass the map, build it internally (no N+1, one query)
        if (empty($comboChildren)) {
            $comboChildren = $this->buildComboChildrenMap(
                $cart->items->pluck('product')->filter()->unique('id')->values()
            );
        }

        $items = $cart->items->map(function ($item) use ($isDistributor, $comboChildren) {
            $product = $item->product;
            $variant = $item->variant;

            $currentPrice          = 0;
            $variantAttributes     = null;
            $imageUrl              = null;
            $mrp                   = null;
            $discountType          = null;
            $discountValue         = null;
            $discountAmountPerUnit = 0;

            // ---------------- variant path ----------------
            if ($variant) {
                $currentPrice = $isDistributor
                    ? ($variant->distributor_price ?? $variant->retail_price)
                    : $variant->retail_price;

                $mrp = $isDistributor
                    ? ($variant->distributor_mrp ?? $variant->retail_mrp ?? null)
                    : ($variant->retail_mrp ?? null);

                $discountType  = $isDistributor
                    ? ($variant->distributor_discount_type ?? null)
                    : ($variant->retail_discount_type ?? null);

                $discountValue = $isDistributor
                    ? ($variant->distributor_discount_value ?? null)
                    : ($variant->retail_discount_value ?? null);

                $variantAttributes = $variant->attributes;

                $variantImage = $variant->images->where('is_primary', true)->first()
                    ?? $variant->images->first();
                $imageUrl = $variantImage ? asset('storage/' . $variantImage->image) : null;
            }
            // ---------------- product fallback ----------------
            elseif ($product) {
                $currentPrice = $isDistributor
                    ? ($product->distributor_price ?? $product->retail_price)
                    : $product->retail_price;

                $mrp = $isDistributor
                    ? ($product->distributor_mrp ?? $product->retail_mrp ?? null)
                    : ($product->retail_mrp ?? null);

                $discountType  = $isDistributor
                    ? ($product->distributor_discount_type ?? null)
                    : ($product->retail_discount_type ?? null);

                $discountValue = $isDistributor
                    ? ($product->distributor_discount_value ?? null)
                    : ($product->retail_discount_value ?? null);

                $imageUrl = $product->primaryImage
                    ? asset('storage/' . $product->primaryImage->image)
                    : null;
            }

            if ($mrp !== null && $mrp > $currentPrice) {
                $discountAmountPerUnit = $mrp - $currentPrice;
            }

            $subtotal = $currentPrice * $item->quantity;

            // Availability = physical stock - reserved (never negative)
            $availableStock = null;
            if ($product && $product->stock_quantity !== null) {
                $availableStock = max(0, (int) $product->stock_quantity - (int) $product->reserved_inventory);
            }

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,

                'product' => $product ? [
                    'id' => $product->id,
                    'name' => $product->name,
                    'allowed_purchsed' => $product->allowed_purchsed,
                    'slug' => $product->slug,
                    'service_charge' => $product->service_charge,
                    'product_code' => $product->product_code,
                    'hsn_code' => $product->hsn_code,
                    'uom' => $product->uom,

                    'retail_price' => $product->retail_price,
                    'retail_mrp' => $product->retail_mrp,
                    'retail_discount_type' => $product->retail_discount_type,
                    'retail_discount_value' => $product->retail_discount_value,

                    'distributor_price' => $product->distributor_price,
                    'distributor_mrp' => $product->distributor_mrp,
                    'distributor_discount_type' => $product->distributor_discount_type,
                    'distributor_discount_value' => $product->distributor_discount_value,

                    'stock_quantity' => $product->stock_quantity,
                    'reserved_inventory' => $product->reserved_inventory,
                    'available_stock' => $availableStock,
                    'low_stock_threshold' => $product->low_stock_threshold,
                    'is_in_stock' => $availableStock !== null ? $availableStock > 0 : null,
                    'is_low_stock' => ($availableStock !== null && $product->low_stock_threshold !== null)
                        ? ($availableStock > 0 && $availableStock <= (int) $product->low_stock_threshold)
                        : false,

                    'category' => $product->relationLoaded('category') && $product->category ? [
                        'id' => $product->category->id,
                        'title' => $product->category->title,
                        'slug' => $product->category->slug,
                    ] : null,

                    'subcategory' => $product->relationLoaded('subcategory') && $product->subcategory ? [
                        'id' => $product->subcategory->id,
                        'name' => $product->subcategory->name,
                        'slug' => $product->subcategory->slug,
                    ] : null,

                    'brand' => $product->relationLoaded('brand') && $product->brand ? [
                        'id' => $product->brand->id,
                        'title' => $product->brand->title,
                        'category_id' => $product->brand->category_id,
                    ] : null,

                    'tax_category_id' => $product->tax_category_id,
                    'tax_category' => ($product->relationLoaded('taxCategory') && $product->taxCategory) ? [
                        'id' => $product->taxCategory->id,
                        'name' => $product->taxCategory->name ?? null,
                        'rate' => $product->taxCategory->rate ?? null,
                    ] : null,

                    'is_published' => (bool) $product->is_published,
                    'sale_type' => $product->sale_type,
                    'is_trending' => (bool) $product->is_trending,
                    'is_best_seller' => (bool) $product->is_best_seller,
                    'is_deal_of_the_day' => (bool) $product->is_deal_of_the_day,
                    'deal_of_the_day_starts_at' => $product->deal_of_the_day_starts_at?->toIso8601String(),
                    'deal_of_the_day_ends_at' => $product->deal_of_the_day_ends_at?->toIso8601String(),
                    'is_deal_active_now' => (bool) (
                        $product->is_deal_of_the_day
                        && (!$product->deal_of_the_day_starts_at || $product->deal_of_the_day_starts_at->isPast())
                        && (!$product->deal_of_the_day_ends_at || $product->deal_of_the_day_ends_at->isFuture())
                    ),

                    'shipping_charge' => $product->shipping_charge,
                    'commission_value' => $product->commission_value,
                    'parent_combo_id' => $product->parent_combo_id,
                    'ignite_product_id' => $product->ignite_product_id,

                    //  NEW: combo details
                    'product_type' => $this->isComboProduct($product) ? 'combo' : 'single',
                    'combo_products' => $this->isComboProduct($product)
                        ? ($comboChildren[$product->id] ?? [])
                        : [],
                ] : null,

                'variant' => $variant ? [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'attributes' => $variant->attributes,
                    'attribute_string' => $this->getAttributeString($variant->attributes),
                    'retail_price' => $variant->retail_price,
                    'retail_mrp' => $variant->retail_mrp ?? null,
                    'retail_discount_type' => $variant->retail_discount_type ?? null,
                    'retail_discount_value' => $variant->retail_discount_value ?? null,
                    'distributor_price' => $variant->distributor_price,
                    'distributor_mrp' => $variant->distributor_mrp ?? null,
                    'distributor_discount_type' => $variant->distributor_discount_type ?? null,
                    'distributor_discount_value' => $variant->distributor_discount_value ?? null,
                    'stock_quantity' => $variant->stock_quantity ?? null,
                ] : null,

                'variant_attributes' => $variantAttributes,
                'quantity' => $item->quantity,

                'current_unit_price' => $currentPrice,
                'current_unit_price_formatted' => number_format($currentPrice, 2),

                'mrp' => $mrp,
                'mrp_formatted' => $mrp !== null ? number_format($mrp, 2) : null,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'discount_amount_per_unit' => $discountAmountPerUnit,
                'discount_amount_per_unit_formatted' => number_format($discountAmountPerUnit, 2),
                'discount_total' => $discountAmountPerUnit * $item->quantity,
                'discount_total_formatted' => number_format($discountAmountPerUnit * $item->quantity, 2),
                'discount_percentage' => ($mrp !== null && $mrp > 0 && $discountAmountPerUnit > 0)
                    ? round(($discountAmountPerUnit / $mrp) * 100, 2)
                    : 0,

                'subtotal' => $subtotal,
                'subtotal_formatted' => number_format($subtotal, 2),

                'image_url' => $imageUrl ?? null,
                'current_price_type' => $isDistributor ? 'distributor' : 'retail',
            ];
        })->values()->toArray();

        $total = collect($items)->sum('subtotal');
        $totalItems = collect($items)->sum('quantity');
        $totalMrp = collect($items)->sum(fn($i) => ($i['mrp'] ?? 0) * $i['quantity']);
        $totalDiscount = collect($items)->sum(fn($i) => $i['discount_total'] ?? 0);

        return [
            'id' => $cart->id,
            'items' => $items,
            'total' => $total,
            'total_formatted' => number_format($total, 2),
            'total_mrp' => $totalMrp,
            'total_mrp_formatted' => number_format($totalMrp, 2),
            'total_discount' => $totalDiscount,
            'total_discount_formatted' => number_format($totalDiscount, 2),
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

    public function applyCoupon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'coupon_code' => ['required', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $couponCode = strtoupper($request->coupon_code);

            // Get cart with items
            $cart = Cart::with(['items.product'])->where('user_id', $user->id)->first();

            if (!$cart || $cart->items->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart is empty'
                ], 400);
            }

            // Find coupon
            $coupon = Coupon::where('code', $couponCode)->first();

            if (!$coupon) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid coupon code'
                ], 404);
            }

            // Validate coupon
            $validationResult = $this->validateCoupon($coupon, $user->id);
            if ($validationResult !== true) {
                return response()->json([
                    'success' => false,
                    'message' => $validationResult
                ], 400);
            }

            // Calculate cart total
            $cartTotal = $this->calculateCartTotal($cart, $user);

            // Check minimum order requirement
            if ($coupon->min_order && $cartTotal < $coupon->min_order) {
                return response()->json([
                    'success' => false,
                    'message' => "Minimum order amount of ₹" . number_format($coupon->min_order, 2) . " required for this coupon"
                ], 400);
            }

            // Calculate discount
            $discount = $this->calculateDiscount($coupon, $cartTotal);

            // Store applied coupon in session or cache
            $this->storeAppliedCoupon($user->id, $coupon, $discount);

            // Get updated cart with coupon applied
            $cartWithDiscount = $this->getCartWithDiscount($user->id);

            return response()->json([
                'success' => true,
                'message' => "Coupon '{$coupon->code}' applied successfully!",
                'data' => $cartWithDiscount,
                'coupon_details' => [
                    'code' => $coupon->code,
                    'title' => $coupon->title,
                    'type' => $coupon->type,
                    'value' => (float) $coupon->value,
                    'discount_amount' => round($discount, 2),
                    'min_order' => $coupon->min_order,
                    'max_discount' => $coupon->max_order,
                ],
                'savings' => round($discount, 2),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply coupon',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove coupon from cart
     */
    public function removeCoupon(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Remove applied coupon
            $removed = $this->removeAppliedCoupon($user->id);

            if (!$removed) {
                return response()->json([
                    'success' => false,
                    'message' => 'No coupon applied to remove'
                ], 400);
            }

            // Get updated cart without coupon
            $cartWithDiscount = $this->getCartWithDiscount($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Coupon removed successfully',
                'data' => $cartWithDiscount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove coupon',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    protected function removeAppliedCoupon($userId)
    {
        $key = 'applied_coupon_' . $userId;

        // Remove from cache
        cache()->forget($key);

        // Remove from session
        session()->forget($key);

        return true;
    }

    protected function validateCoupon($coupon, $userId)
    {
        // Check if coupon is active
        if (!$coupon->is_active) {
            return 'This coupon is no longer active';
        }

        // Check expiry
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            return 'This coupon has expired';
        }

        // Check usage limit
        if ($coupon->max_uses && $coupon->used_count >= $coupon->max_uses) {
            return 'This coupon has reached its usage limit';
        }

        // Check if user has already used this coupon
        $userUsage = CouponUsage::where('coupon_id', $coupon->id)
            ->where('user_id', $userId)
            ->count();

        if ($userUsage > 0) {
            return 'You have already used this coupon';
        }

        return true;
    }

    protected function calculateCartTotal($cart, $user)
    {
        $isDistributor = $user && $user->account_type === 'distributor';
        $total = 0;

        foreach ($cart->items as $item) {
            $product = $item->product;
            if ($product) {
                $price = $isDistributor
                    ? ($product->distributor_price ?? $product->retail_price)
                    : $product->retail_price;
                $total += $price * $item->quantity;
            }
        }

        return $total;
    }

    protected function calculateDiscount($coupon, $cartTotal)
    {
        $discount = 0;

        if ($coupon->type === 'percentage') {
            $discount = ($cartTotal * $coupon->value) / 100;
        } else { // fixed
            $discount = $coupon->value;
        }

        // Apply maximum discount limit if set
        if ($coupon->max_order && $discount > $coupon->max_order) {
            $discount = $coupon->max_order;
        }

        return $discount;
    }

    protected function storeAppliedCoupon($userId, $coupon, $discount)
    {
        // Store in cache or session
        $key = 'applied_coupon_' . $userId;
        cache()->put($key, [
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'discount' => $discount,
            'applied_at' => now(),
        ], 3600); // 1 hour expiry

        // Also store in session
        session()->put($key, [
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'discount' => $discount,
            'applied_at' => now(),
        ]);

        return true;
    }

    protected function getAppliedCoupon($userId)
    {
        $key = 'applied_coupon_' . $userId;

        // Try cache first
        $couponData = cache()->get($key);

        // If not in cache, try session
        if (!$couponData) {
            $couponData = session()->get($key);
        }

        return $couponData;
    }

    protected function getCartWithDiscount($userId)
    {
        $user = User::find($userId);
        $cart = Cart::with(['items.product.images'])->where('user_id', $userId)->first();

        if (!$cart) {
            return [
                'items' => [],
                'total' => 0,
                'total_formatted' => '0.00',
                'total_items' => 0,
            ];
        }

        // Get formatted cart
        $formattedCart = $this->formatCart($cart, $user);

        // Get applied coupon
        $appliedCoupon = $this->getAppliedCoupon($userId);

        if ($appliedCoupon) {
            $formattedCart['applied_coupon'] = [
                'code' => $appliedCoupon['code'],
                'discount_amount' => round($appliedCoupon['discount'], 2),
                'discount_amount_formatted' => number_format($appliedCoupon['discount'], 2),
            ];
            $formattedCart['discounted_total'] = round($formattedCart['total'] - $appliedCoupon['discount'], 2);
            $formattedCart['discounted_total_formatted'] = number_format($formattedCart['total'] - $appliedCoupon['discount'], 2);
            $formattedCart['savings'] = round($appliedCoupon['discount'], 2);
            $formattedCart['savings_formatted'] = number_format($appliedCoupon['discount'], 2);
        } else {
            $formattedCart['applied_coupon'] = null;
            $formattedCart['discounted_total'] = $formattedCart['total'];
            $formattedCart['discounted_total_formatted'] = $formattedCart['total_formatted'];
            $formattedCart['savings'] = 0;
            $formattedCart['savings_formatted'] = '0.00';
        }

        return $formattedCart;
    }

    protected function buildComboChildrenMap($products): array
    {
        // 1) Collect all unique child IDs across every combo product on this page
        $childIdsByParent = [];
        $allChildIds      = [];

        foreach ($products as $product) {
            if (($product->product_type ?? 'single') !== 'combo') {
                continue;
            }

            $groups = \App\Models\Product::normalizeComboGroups($product->parent_combo_id);

            // Flatten all groups -> unique IDs, preserve order
            $ids = collect($groups)
                ->flatten()
                ->map(fn($v) => (int) $v)
                ->unique()
                ->values()
                ->all();

            if (empty($ids)) {
                $childIdsByParent[$product->id] = [];
                continue;
            }

            $childIdsByParent[$product->id] = $ids;
            $allChildIds = array_merge($allChildIds, $ids);
        }

        $allChildIds = array_values(array_unique($allChildIds));

        if (empty($allChildIds)) {
            return [];
        }

        // 2) Fetch all children in one query with needed relations
        $children = \App\Models\Product::query()
            ->whereIn('id', $allChildIds)
            ->whereNull('deleted_at')
            ->with([
                'category:id,title,slug',
                'subcategory:id,category_id,name,slug',
                'brand:id,title,logo,banner',
                'taxCategory:id,name,rate',
                'images',
            ])
            ->get()
            ->keyBy('id');

        // 3) Build the payload per parent, preserving child order from parent_combo_id
        $map = [];

        foreach ($childIdsByParent as $parentId => $childIds) {
            $payload = [];

            foreach ($childIds as $cid) {
                $child = $children->get($cid);
                if (!$child) {
                    continue;
                }

                $images = $child->images
                    ->sortBy(fn($img) => $img->is_primary ? 0 : 1)  // primary first
                    ->take(2)
                    ->map(fn($img) => [
                        'id'         => $img->id,
                        'image_url'  => asset('storage/' . $img->image),
                        'is_primary' => (bool) $img->is_primary,
                        'sort_order' => $img->sort_order,
                    ])
                    ->values()
                    ->all();

                $payload[] = [
                    'id'                    => $child->id,
                    'product_code'          => $child->product_code,
                    'name'                  => $child->name,
                    'slug'                  => $child->slug,
                    'is_mandatory'          => $child->is_mandatory ?? null,
                    'commission_value'      => $child->commission_value ?? null,

                    // both prices
                    'retail_price'          => $child->retail_price,
                    'retail_mrp'            => $child->retail_mrp,
                    'distributor_price'     => $child->distributor_price,
                    'distributor_mrp'       => $child->distributor_mrp,

                    // up to 2 images (primary first)
                    'images'                => $images,

                    // taxonomy / brand
                    'category'              => $child->category ? [
                        'id'   => $child->category->id,
                        'name' => $child->category->title,
                        'slug' => $child->category->slug,
                    ] : null,

                    'subcategory'           => $child->subcategory ? [
                        'id'          => $child->subcategory->id,
                        'category_id' => $child->subcategory->category_id,
                        'name'        => $child->subcategory->name,
                        'slug'        => $child->subcategory->slug,
                    ] : null,

                    'brand'                 => $child->brand ? [
                        'id'     => $child->brand->id,
                        'name'   => $child->brand->title,
                        'logo'   => $child->brand->logo
                            ? asset('storage/' . $child->brand->logo)
                            : null,
                        'banner' => $child->brand->banner
                            ? asset('storage/' . $child->brand->banner)
                            : null,
                    ] : null,

                    'tax_category'          => $child->taxCategory ? [
                        'id'   => $child->taxCategory->id,
                        'name' => $child->taxCategory->name,
                        'rate' => $child->taxCategory->rate,
                    ] : null,
                ];
            }

            $map[$parentId] = $payload;
        }

        return $map;
    }
    /**
     * Whether this product should be treated as a combo.
     * Uses product_type as the source of truth, with a defensive
     * fallback to a non-empty parent_combo_id (for legacy rows).
     */
    protected function isComboProduct($product): bool
    {
        if (($product->product_type ?? null) === 'combo') {
            return true;
        }

        $groups = \App\Models\Product::normalizeComboGroups($product->parent_combo_id ?? null);

        return !empty(collect($groups)->flatten()->filter()->all());
    }

    /**
     * Load and format the combo children of a single combo product.
     * Returns the same shape as buildComboChildrenMap() so index & show match.
     */
    protected function formatComboChildren($product): array
    {
        $groups = \App\Models\Product::normalizeComboGroups($product->parent_combo_id ?? null);

        $childIds = collect($groups)
            ->flatten()
            ->map(fn($v) => (int) $v)
            ->filter(fn($id) => $id > 0 && $id !== (int) $product->id)
            ->unique()
            ->values()
            ->all();

        if (empty($childIds)) {
            return [];
        }

        $children = \App\Models\Product::query()
            ->whereIn('id', $childIds)
            ->whereNull('deleted_at')
            ->with([
                'category:id,title,slug',
                'subcategory:id,category_id,name,slug',
                'brand:id,title,logo,banner',
                'taxCategory:id,name,rate',
                'images',
            ])
            ->get()
            ->keyBy('id');

        $payload = [];

        foreach ($childIds as $cid) {
            $child = $children->get($cid);
            if (!$child) {
                continue;
            }

            $images = $child->images
                ->sortBy(fn($img) => $img->is_primary ? 0 : 1)
                ->take(2)
                ->map(fn($img) => [
                    'id'         => $img->id,
                    'image_url'  => asset('storage/' . ltrim($img->image, '/')),
                    'is_primary' => (bool) $img->is_primary,
                    'sort_order' => (int) $img->sort_order,
                ])
                ->values()
                ->all();

            $payload[] = [
                'id'               => $child->id,
                'product_code'     => $child->product_code,
                'name'             => $child->name,
                'slug'             => $child->slug,
                'is_mandatory'     => $child->is_mandatory ?? null,
                'commission_value' => $child->commission_value ?? null,

                // both prices
                'retail_price'      => $child->retail_price,
                'retail_mrp'        => $child->retail_mrp,
                'distributor_price' => $child->distributor_price,
                'distributor_mrp'   => $child->distributor_mrp,

                // up to 2 images (primary first)
                'images'            => $images,

                'category'          => $child->category ? [
                    'id'   => $child->category->id,
                    'name' => $child->category->title,
                    'slug' => $child->category->slug,
                ] : null,

                'subcategory'       => $child->subcategory ? [
                    'id'          => $child->subcategory->id,
                    'category_id' => $child->subcategory->category_id,
                    'name'        => $child->subcategory->name,
                    'slug'        => $child->subcategory->slug,
                ] : null,

                'brand'             => $child->brand ? [
                    'id'     => $child->brand->id,
                    'name'   => $child->brand->title,
                    'logo'   => $child->brand->logo
                        ? asset('storage/' . ltrim($child->brand->logo, '/'))
                        : null,
                    'banner' => $child->brand->banner
                        ? asset('storage/' . ltrim($child->brand->banner, '/'))
                        : null,
                ] : null,

                'tax_category'      => $child->taxCategory ? [
                    'id'   => $child->taxCategory->id,
                    'name' => $child->taxCategory->name,
                    'rate' => $child->taxCategory->rate,
                ] : null,
            ];
        }

        return $payload;
    }
}
