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
    // public function index(Request $request)
    // {
    //     $cart = $this->getCart($request);
    //     $user = auth('sanctum')->user();

    //     // ============================================================
    //     // COMBO CHILDREN (batch across all cart items)   NEW
    //     // ============================================================
    //     $cartProducts = $cart->items
    //         ->pluck('product')
    //         ->filter()
    //         ->unique('id')
    //         ->values();

    //     $comboChildren = $this->buildComboChildrenMap($cartProducts);

    //     return response()->json([
    //         'data' => $this->formatCart($cart, $user, $comboChildren),
    //         'user_type' => $this->getUserType(),
    //     ]);
    // }
    public function index(Request $request)
    {
        $cart = $this->getCart($request);
        $user = auth('sanctum')->user();

        // ✅ ADD: mandatory products sync karo (add/remove)
        $this->ensureMandatoryProductsInCart($cart);

        // Reload items (kyunki ensure ne add/remove kiya ho sakta hai)
        $cart->load([
            'items.product.taxCategory',
            'items.product.images',
            'items.product.primaryImage',
            'items.variant.images',
        ]);

        $cartProducts = $cart->items
            ->pluck('product')
            ->filter()
            ->unique('id')
            ->values();

        $comboChildren = $this->buildComboChildrenMap($cartProducts);

        return response()->json([
            'data'      => $this->formatCart($cart, $user, $comboChildren),
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

    /**
     * Has this user already purchased the mandatory product(s)?
     * "Purchased" = paid/confirmed order, non-cancelled line.
     */
    protected function hasUserPurchasedMandatoryProduct(int $userId, array $mandatoryProductIds): bool
    {
        $ids = array_filter(array_map('intval', (array) $mandatoryProductIds));

        if (empty($ids)) {
            return false;
        }

        return \App\Models\OrderLine::query()
            ->whereIn('product_id', $ids)
            ->whereHas('order', function ($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->where(function ($inner) {
                        $inner->where('amount_paid', '>', 0)
                            ->orWhereIn('status', [
                                'confirmed',
                                'processing',
                                'dispatched',
                                'shipped',
                                'delivered',
                            ]);
                    });
            })
            ->whereNotIn('delivery_status', [
                'cancelled',
                'cancel_pending',
                'cancel_rejected',
                'returned',
                'refunded',
            ])
            ->exists();
    }
    protected function ensureMandatoryProductsInCart(Cart $cart): void
    {
        $user = auth('sanctum')->user();

        if (!$user) {
            return;
        }

        $mandatoryProducts = Product::where('is_mandatory', true)->get();

        if ($mandatoryProducts->isEmpty()) {
            return;
        }

        $alreadyPurchased = $this->hasUserPurchasedMandatoryProduct(
            (int) $user->id,
            $mandatoryProducts->pluck('id')->toArray()
        );

        if ($alreadyPurchased) {
            // User already purchased → mandatory dobara force nahi
            // Also remove from cart if somehow present
            CartItem::where('cart_id', $cart->id)
                ->whereIn('product_id', $mandatoryProducts->pluck('id')->toArray())
                ->whereNull('variant_id')
                ->delete();

            return;
        }

        // ✅ Check cart_items for existing mandatory product
        $existingMandatoryIds = CartItem::where('cart_id', $cart->id)
            ->whereIn('product_id', $mandatoryProducts->pluck('id')->toArray())
            ->whereNull('variant_id')
            ->pluck('product_id')
            ->toArray();

        foreach ($mandatoryProducts as $product) {
            // Skip if already in cart
            if (in_array($product->id, $existingMandatoryIds, true)) {
                continue;
            }

            CartItem::create([
                'cart_id'         => $cart->id,
                'product_id'      => $product->id,
                'variant_id'      => null,
                'quantity'        => 1,
                'unit_price'      => 0,
                'shipping_charge' => $product->shipping_charge ?? 0,
            ]);
        }
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
            // if ($user) {
            //     $hasOrdered = Order::where('user_id', $user->id)->exists();

            //     if (!$hasOrdered) {
            //         $mandatoryProducts = Product::where('is_mandatory', true)->get();

            //         foreach ($mandatoryProducts as $mandatoryProduct) {
            //             $exists = CartItem::where('cart_id', $cart->id)
            //                 ->where('product_id', $mandatoryProduct->id)
            //                 ->whereNull('variant_id')
            //                 ->exists();

            //             if ($exists) {
            //                 continue;
            //             }

            //             CartItem::create([
            //                 'cart_id'         => $cart->id,
            //                 'product_id'      => $mandatoryProduct->id,
            //                 'variant_id'      => null,
            //                 'quantity'        => 1,
            //                 'unit_price'      => 0,
            //                 'shipping_charge' => $mandatoryProduct->shipping_charge ?? 0,
            //             ]);
            //         }
            //     }
            // }
            // ---------- Auto add mandatory products (first-time users) ----------
            if ($user) {
                $this->ensureMandatoryProductsInCart($cart);
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
            // $hasOrdered = $user
            //     ? Order::where('user_id', $user->id)->exists()
            //     : true;

            // // Check whether requested item is mandatory
            // $isMandatoryItem = $cartItem->product
            //     && (bool) $cartItem->product->is_mandatory;

            // // Apply restriction only for first-time users
            // if (!$hasOrdered && $isMandatoryItem) {

            //     // Get all other non-mandatory products from cart
            //     $otherProducts = CartItem::with('product')
            //         ->where('cart_id', $cart->id)
            //         ->where('id', '!=', $cartItem->id)
            //         ->whereHas('product', function ($query) {
            //             $query->where(function ($q) {
            //                 $q->where('is_mandatory', false)
            //                     ->orWhereNull('is_mandatory');
            //             });
            //         })
            //         ->get();

            //     // Block mandatory product removal if other products exist
            //     if ($otherProducts->isNotEmpty()) {

            //         $productNames = $otherProducts
            //             ->map(function ($item) {
            //                 return $item->product->name ?? 'Unknown Product';
            //             })
            //             ->unique()
            //             ->values()
            //             ->implode(', ');

            //         DB::rollBack();

            //         return response()->json([
            //             'success' => false,
            //             'message' => "Please remove {$productNames} first. Mandatory products can only be removed after removing these products.",
            //         ], 422);
            //     }
            // }
            // Check whether requested item is mandatory
            $isMandatoryItem = $cartItem->product
                && (bool) $cartItem->product->is_mandatory;

            // Restriction only if user has NOT purchased mandatory yet
            if ($user && $isMandatoryItem) {
                $mandatoryProducts = Product::where('is_mandatory', true)->get();

                $alreadyPurchased = $this->hasUserPurchasedMandatoryProduct(
                    (int) $user->id,
                    $mandatoryProducts->pluck('id')->toArray()
                );

                // Block mandatory removal only if user hasn't purchased it AND
                // other non-mandatory products exist in cart
                if (!$alreadyPurchased) {
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

                    if ($otherProducts->isNotEmpty()) {
                        $productNames = $otherProducts
                            ->map(fn($item) => $item->product->name ?? 'Unknown Product')
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

    protected function resolveComboTaxRate(Product $product): ?float
    {
        if (($product->product_type ?? 'single') !== 'combo') {
            return null; // not a combo, caller keeps existing logic
        }

        // Reuse the model helper that already normalizes parent_combo_id
        $childIds = collect(\App\Models\Product::normalizeComboGroups($product->parent_combo_id))
            ->flatten()
            ->map(fn($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        if (empty($childIds)) {
            return 0.0;
        }

        $maxRate = Product::query()
            ->whereIn('id', $childIds)
            ->whereNull('deleted_at')
            ->with('taxCategory')
            ->get()
            ->map(fn($p) => (float) ($p->taxCategory->rate ?? 0))
            ->max();

        return $maxRate !== null ? (float) $maxRate : 0.0;
    }
    protected function formatCart($cart, $user = null, array $comboChildren = [], $address = null)
    {
        $isDistributor = $user && $user->account_type === 'distributor';

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

            $availableStock = null;
            if ($product && $product->stock_quantity !== null) {
                $availableStock = max(0, (int) $product->stock_quantity - (int) $product->reserved_inventory);
            }

            // ============================================================
            // GST & PRICING BREAKDOWN
            // NOTE: Admin prices are GST-INCLUSIVE.
            //       We derive EXCL. GST via price / (1 + rate/100).
            // ============================================================

            $taxRate = 0;
            if ($product) {
                $taxRate = (float) ($product->taxCategory?->rate ?? 0);
                if ($taxRate === 0 && ($product->product_type ?? 'single') === 'combo') {
                    $taxRate = (float) $this->resolveComboTaxRate($product);
                }
            }

            $taxCategoryName = $product?->taxCategory?->name ?? 'No Tax'; // record only
            $qty             = (int) $item->quantity;
            $gstFactor       = 1 + ($taxRate / 100);   // divisor for tax-inclusive prices

            // ---------- helper closure: incl → excl ----------
            $splitInclusive = function (float $inclusiveAmount) use ($gstFactor): array {
                if ($gstFactor <= 0) {
                    return ['excl' => $inclusiveAmount, 'gst' => 0.0];
                }
                $excl = round($inclusiveAmount / $gstFactor, 2);
                $gst  = round($inclusiveAmount - $excl, 2);
                return ['excl' => $excl, 'gst' => $gst];
            };

            // ---------- SHIPPING (per unit is GST-inclusive) ----------
            $shippingPerUnitIncl = (float) ($product?->shipping_charge ?? 0);
            $shippingTotalIncl   = round($shippingPerUnitIncl * $qty, 2);

            $shipSplitPerUnit = $splitInclusive($shippingPerUnitIncl);
            $shipSplitTotal   = $splitInclusive($shippingTotalIncl);

            $shippingPerUnitExcl = $shipSplitPerUnit['excl'];
            $shippingGstPerUnit  = $shipSplitPerUnit['gst'];
            $shippingExclTotal   = $shipSplitTotal['excl'];
            $shippingGstTotal    = $shipSplitTotal['gst'];

            // ---------- DISTRIBUTOR pricing (GST-inclusive) ----------
            $distPriceIncl        = (float) ($product->distributor_price ?? 0);
            $distMrpIncl          = (float) ($product->distributor_mrp ?? 0);
            $distDiscountType     = $product->distributor_discount_type ?? null;
            $distDiscountValue    = (float) ($product->distributor_discount_value ?? 0);

            $distDiscountAmountPerUnit = 0.0;
            if ($distDiscountType === 'percentage' && $distMrpIncl > 0) {
                $distDiscountAmountPerUnit = round(($distMrpIncl * $distDiscountValue) / 100, 2);
            } elseif ($distDiscountType === 'fixed') {
                $distDiscountAmountPerUnit = $distDiscountValue;
            } elseif ($distMrpIncl > 0 && $distMrpIncl > $distPriceIncl) {
                $distDiscountAmountPerUnit = $distMrpIncl - $distPriceIncl;
            }

            $distDiscountAmountTotal = round($distDiscountAmountPerUnit * $qty, 2);
            $distDiscountPercent     = ($distMrpIncl > 0 && $distDiscountAmountPerUnit > 0)
                ? round(($distDiscountAmountPerUnit / $distMrpIncl) * 100, 2)
                : 0;

            $distPerUnitSplit = $splitInclusive($distPriceIncl);
            $distLineSplit    = $splitInclusive(round($distPriceIncl * $qty, 2));

            $distPriceExclPerUnit = $distPerUnitSplit['excl'];
            $distGstPerUnit       = $distPerUnitSplit['gst'];
            $distPriceInclPerUnit = $distPriceIncl;

            $distLineExclGst   = $distLineSplit['excl'];
            $distGstAmountLine = $distLineSplit['gst'];
            $distLineInclGst   = round($distPriceIncl * $qty, 2);

            // ---------- RETAIL pricing (GST-inclusive) ----------
            $retailPriceIncl        = (float) ($product->retail_price ?? 0);
            $retailMrpIncl          = (float) ($product->retail_mrp ?? 0);
            $retailDiscountType     = $product->retail_discount_type ?? null;
            $retailDiscountValue    = (float) ($product->retail_discount_value ?? 0);

            $retailDiscountAmountPerUnit = 0.0;
            if ($retailDiscountType === 'percentage' && $retailMrpIncl > 0) {
                $retailDiscountAmountPerUnit = round(($retailMrpIncl * $retailDiscountValue) / 100, 2);
            } elseif ($retailDiscountType === 'fixed') {
                $retailDiscountAmountPerUnit = $retailDiscountValue;
            } elseif ($retailMrpIncl > 0 && $retailMrpIncl > $retailPriceIncl) {
                $retailDiscountAmountPerUnit = $retailMrpIncl - $retailPriceIncl;
            }

            $retailDiscountAmountTotal = round($retailDiscountAmountPerUnit * $qty, 2);
            $retailDiscountPercent     = ($retailMrpIncl > 0 && $retailDiscountAmountPerUnit > 0)
                ? round(($retailDiscountAmountPerUnit / $retailMrpIncl) * 100, 2)
                : 0;

            $retailPerUnitSplit = $splitInclusive($retailPriceIncl);
            $retailLineSplit    = $splitInclusive(round($retailPriceIncl * $qty, 2));

            $retailPriceExclPerUnit = $retailPerUnitSplit['excl'];
            $retailGstPerUnit       = $retailPerUnitSplit['gst'];
            $retailPriceInclPerUnit = $retailPriceIncl;

            $retailLineExclGst   = $retailLineSplit['excl'];
            $retailGstAmountLine = $retailLineSplit['gst'];
            $retailLineInclGst   = round($retailPriceIncl * $qty, 2);

            // ---------- TAXABLE AMOUNT (excl. GST) ----------
            $activeLineExclGst   = $isDistributor ? $distLineExclGst : $retailLineExclGst;
            $activeGstAmountLine = $isDistributor ? $distGstAmountLine : $retailGstAmountLine;
            $activeLineInclGst   = $isDistributor ? $distLineInclGst : $retailLineInclGst;

            $taxableAmountLine = round($activeLineExclGst + $shippingExclTotal, 2);
            $gstAmountLine     = round($activeGstAmountLine + $shippingGstTotal, 2);

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

                // ============================================================
                // GST METADATA (record-only)
                // ============================================================
                'tax_category'     => $taxCategoryName,
                'gst_rate_percent' => $taxRate,

                // ---------- SHIPPING (incl. → excl. breakdown) ----------
                'shipping_charge_per_unit_incl_gst' => round($shippingPerUnitIncl, 2),
                'shipping_charge_per_unit_excl_gst' => $shippingPerUnitExcl,
                'shipping_gst_per_unit'             => $shippingGstPerUnit,
                'shipping_charge_total_incl_gst'    => $shippingTotalIncl,
                'shipping_charge_total_excl_gst'    => $shippingExclTotal,
                'shipping_gst_total'                => $shippingGstTotal,

                // ---------- DISTRIBUTOR (incl. → excl. breakdown) ----------
                'distributor_price_incl_gst'             => round($distPriceIncl, 2),
                'distributor_price_excl_gst_per_unit'    => $distPriceExclPerUnit,
                'distributor_gst_amount_per_unit'        => $distGstPerUnit,
                'distributor_line_incl_gst'              => $distLineInclGst,
                'distributor_line_excl_gst'              => $distLineExclGst,
                'distributor_gst_amount_line'            => $distGstAmountLine,

                'distributor_mrp'                        => round($distMrpIncl, 2),
                'distributor_discount_type'              => $distDiscountType,
                'distributor_discount_value'             => $distDiscountValue,
                'distributor_discount_percent'           => $distDiscountPercent,
                'distributor_discount_amount_per_unit'   => $distDiscountAmountPerUnit,
                'distributor_discount_amount_total'      => $distDiscountAmountTotal,

                // ---------- RETAIL (incl. → excl. breakdown) ----------
                'retail_price_incl_gst'                  => round($retailPriceIncl, 2),
                'retail_price_excl_gst_per_unit'         => $retailPriceExclPerUnit,
                'retail_gst_amount_per_unit'             => $retailGstPerUnit,
                'retail_line_incl_gst'                   => $retailLineInclGst,
                'retail_line_excl_gst'                   => $retailLineExclGst,
                'retail_gst_amount_line'                 => $retailGstAmountLine,

                'retail_mrp'                             => round($retailMrpIncl, 2),
                'retail_discount_type'                   => $retailDiscountType,
                'retail_discount_value'                  => $retailDiscountValue,
                'retail_discount_percent'                => $retailDiscountPercent,
                'retail_discount_amount_per_unit'        => $retailDiscountAmountPerUnit,
                'retail_discount_amount_total'           => $retailDiscountAmountTotal,

                // ---------- TAXABLE + GST (line) ----------
                'taxable_amount_line' => $taxableAmountLine,
                'gst_amount_line'     => $gstAmountLine,

                // ---------- ACTIVE PRICING (based on user type) ----------
                'active_price_type'                => $isDistributor ? 'distributor' : 'retail',
                'active_price_incl_gst_per_unit'   => $isDistributor ? $distPriceIncl : $retailPriceIncl,
                'active_price_excl_gst_per_unit'   => $isDistributor ? $distPriceExclPerUnit : $retailPriceExclPerUnit,
                'active_gst_amount_per_unit'       => $isDistributor ? $distGstPerUnit : $retailGstPerUnit,
                'active_line_incl_gst'             => $activeLineInclGst,
                'active_line_excl_gst'             => $activeLineExclGst,
                'active_gst_amount_line'           => $activeGstAmountLine,

                // ---------- EXISTING FIELDS (kept) ----------
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

        $total         = collect($items)->sum('subtotal');
        $totalItems    = collect($items)->sum('quantity');
        $totalMrp      = collect($items)->sum(fn($i) => ($i['mrp'] ?? 0) * $i['quantity']);
        $totalDiscount = collect($items)->sum(fn($i) => $i['discount_total'] ?? 0);

        // ============================================================
        // CART-LEVEL AGGREGATES
        // ============================================================
        $cartTotals = [
            'active_line_incl_gst'   => collect($items)->sum('active_line_incl_gst'),
            'active_line_excl_gst'   => collect($items)->sum('active_line_excl_gst'),
            'active_gst_amount'      => collect($items)->sum('active_gst_amount_line'),

            'shipping_incl_gst'      => collect($items)->sum('shipping_charge_total_incl_gst'),
            'shipping_excl_gst'      => collect($items)->sum('shipping_charge_total_excl_gst'),
            'shipping_gst'           => collect($items)->sum('shipping_gst_total'),

            'taxable_amount'         => collect($items)->sum('taxable_amount_line'),
            'gst_amount'             => collect($items)->sum('gst_amount_line'),

            'retail_line_incl_gst'   => collect($items)->sum('retail_line_incl_gst'),
            'retail_line_excl_gst'   => collect($items)->sum('retail_line_excl_gst'),
            'retail_gst_amount'      => collect($items)->sum('retail_gst_amount_line'),

            'dist_line_incl_gst'     => collect($items)->sum('distributor_line_incl_gst'),
            'dist_line_excl_gst'     => collect($items)->sum('distributor_line_excl_gst'),
            'dist_gst_amount'        => collect($items)->sum('distributor_gst_amount_line'),
        ];

        $grandTotalInclShipping = round(
            $cartTotals['active_line_incl_gst'] + $cartTotals['shipping_incl_gst'],
            2
        );

        // ============================================================
        // CATEGORY-WISE GST BREAKDOWN
        // Groups items by (tax_category, rate) and aggregates
        // taxable + GST + CGST/SGST/IGST splits.
        // ============================================================
        $deliveryState = $address?->state ?? null;
        $supplierState = strtolower(config('app.supplier_state', 'Punjab'));

        $isIntraState = $deliveryState
            && strtolower($deliveryState) === $supplierState;

        $gstBreakdownMap = [];

        foreach ($items as $row) {
            $catName = $row['tax_category'] ?? 'No Tax';
            $rate    = (float) ($row['gst_rate_percent'] ?? 0);

            $lineExcl = (float) ($row['active_line_excl_gst'] ?? 0);
            $lineGst  = (float) ($row['active_gst_amount_line'] ?? 0);
            $shipExcl = (float) ($row['shipping_charge_total_excl_gst'] ?? 0);
            $shipGst  = (float) ($row['shipping_gst_total'] ?? 0);

            $taxable = $lineExcl + $shipExcl;
            $gst     = $lineGst + $shipGst;

            if ($isIntraState) {
                $cgst = round($gst / 2, 2);
                $sgst = round($gst / 2, 2);
                $igst = 0.0;
            } else {
                $cgst = 0.0;
                $sgst = 0.0;
                $igst = $gst;
            }

            $key = $catName . '|' . $rate;

            if (!isset($gstBreakdownMap[$key])) {
                $gstBreakdownMap[$key] = [
                    'tax_category'   => $catName,
                    'rate'           => $rate,
                    'rate_percent'   => $rate . '%',
                    'taxable_amount' => 0.0,
                    'gst_amount'     => 0.0,
                    'cgst'           => 0.0,
                    'sgst'           => 0.0,
                    'igst'           => 0.0,
                ];
            }

            $gstBreakdownMap[$key]['taxable_amount'] += $taxable;
            $gstBreakdownMap[$key]['gst_amount']     += $gst;
            $gstBreakdownMap[$key]['cgst']           += $cgst;
            $gstBreakdownMap[$key]['sgst']           += $sgst;
            $gstBreakdownMap[$key]['igst']           += $igst;
        }

        $gstBreakdown = collect($gstBreakdownMap)
            ->map(function ($row) {
                return [
                    'tax_category'   => $row['tax_category'],
                    'rate'           => $row['rate'],
                    'rate_percent'   => $row['rate_percent'],
                    'taxable_amount' => round($row['taxable_amount'], 2),
                    'gst_amount'     => round($row['gst_amount'], 2),
                    'cgst'           => round($row['cgst'], 2),
                    'sgst'           => round($row['sgst'], 2),
                    'igst'           => round($row['igst'], 2),
                ];
            })
            ->sortBy('rate')
            ->values()
            ->all();

        // ✅ NEW: effective weighted rate (single number)
        $effectiveRate = $cartTotals['taxable_amount'] > 0
            ? round(($cartTotals['gst_amount'] / $cartTotals['taxable_amount']) * 100, 2)
            : 0;

        // ✅ NEW: category-wise rate detail (consistent with gst_breakdown)
        $taxRateOnLineDetail = collect($gstBreakdown)->map(function ($row) {
            return [
                'tax_category'   => $row['tax_category'],
                'rate'           => $row['rate'],
                'rate_percent'   => $row['rate_percent'],
                'taxable_amount' => $row['taxable_amount'],
                'gst_amount'     => $row['gst_amount'],
            ];
        })->values()->all();

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
            'price_calculation' => 'dynamic_inclusive',

            // NEW: tax & pricing aggregates
            'tax_and_pricing' => [
                'pricing_mode'          => 'gst_inclusive',
                'gst_rate_source'       => 'product.taxCategory.rate',
                'tax_category_recorded' => true,
                'active_price_type'     => $isDistributor ? 'distributor' : 'retail',

                // Product totals (excl. shipping)
                'active_line_incl_gst' => round($cartTotals['active_line_incl_gst'], 2),
                'active_line_excl_gst' => round($cartTotals['active_line_excl_gst'], 2),
                'active_gst_amount'    => round($cartTotals['active_gst_amount'], 2),

                // Shipping
                'shipping_incl_gst' => round($cartTotals['shipping_incl_gst'], 2),
                'shipping_excl_gst' => round($cartTotals['shipping_excl_gst'], 2),
                'shipping_gst'      => round($cartTotals['shipping_gst'], 2),

                // Taxable + GST (product + shipping)
                'taxable_amount'    => round($cartTotals['taxable_amount'], 2),
                'gst_amount'        => round($cartTotals['gst_amount'], 2),

                // ✅ NEW: effective weighted rate (single number)
                'tax_rate_on_line'        => $effectiveRate,

                // ✅ NEW: category-wise rate detail
                'tax_rate_on_line_detail' => $taxRateOnLineDetail,

                // Category-wise GST breakdown
                'gst_breakdown'     => $gstBreakdown,

                // Grand total
                'grand_total'       => $grandTotalInclShipping,

                // Both views
                'retail' => [
                    'line_incl_gst' => round($cartTotals['retail_line_incl_gst'], 2),
                    'line_excl_gst' => round($cartTotals['retail_line_excl_gst'], 2),
                    'gst_amount'    => round($cartTotals['retail_gst_amount'], 2),
                ],
                'distributor' => [
                    'line_incl_gst' => round($cartTotals['dist_line_incl_gst'], 2),
                    'line_excl_gst' => round($cartTotals['dist_line_excl_gst'], 2),
                    'gst_amount'    => round($cartTotals['dist_gst_amount'], 2),
                ],
            ],
        ];
    }

    /**
     * Get cart summary with dynamic pricing
     */
    // protected function getCartSummary($cart, $user = null)
    // {
    //     $isDistributor = $user && $user->account_type === 'distributor';

    //     $total = 0;
    //     $totalItems = 0;

    //     foreach ($cart->items as $item) {
    //         $price = 0;
    //         if ($item->variant) {
    //             $price = $isDistributor
    //                 ? ($item->variant->distributor_price ?? $item->variant->retail_price)
    //                 : $item->variant->retail_price;
    //         } elseif ($item->product) {
    //             $price = $isDistributor
    //                 ? ($item->product->distributor_price ?? $item->product->retail_price)
    //                 : $item->product->retail_price;
    //         }
    //         $total += $price * $item->quantity;
    //         $totalItems += $item->quantity;
    //     }

    //     return [
    //         'total_items' => $totalItems,
    //         'total' => $total,
    //         'total_formatted' => number_format($total, 2),
    //     ];
    // }

    protected function getCartSummary($cart, $user = null)
    {
        $formatted = $this->formatCart($cart, $user);

        return [
            'total_items'     => $formatted['total_items'] ?? 0,
            'total'           => $formatted['total'] ?? 0,
            'total_formatted' => $formatted['total_formatted'] ?? '0.00',
            'price_type'      => $formatted['price_type'] ?? 'retail',
            'tax_and_pricing' => $formatted['tax_and_pricing'] ?? [],
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
