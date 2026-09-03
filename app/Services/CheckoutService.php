<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Invoice;
use App\Models\CoinRedemption;
use App\Models\CommissionApiEvent;
use App\Models\StockMovement;
use App\Models\Cart;
use App\Models\Address;
use App\Models\User;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use App\Services\PaymentGateway\RazorpayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // Added for clarity
use Exception;
use App\Models\Coupon;
use App\Models\ShippingMethod;
use App\Models\CouponUsage;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use App\Traits\AuditLogTrait;

class CheckoutService
{
    use AuditLogTrait;

    protected GSTCalculator $gstCalculator;
    protected InvoiceService $invoiceService;
    protected RazorpayService $razorpayService;
    protected NotificationService $notificationService;
    protected $pdfInvoiceService;

    public function __construct(
        GSTCalculator $gstCalculator,
        InvoiceService $invoiceService,
        RazorpayService $razorpayService,
        PdfInvoiceService $pdfInvoiceService,
        NotificationService $notificationService
    ) {
        $this->gstCalculator = $gstCalculator;
        $this->invoiceService = $invoiceService;
        $this->razorpayService = $razorpayService;
        $this->pdfInvoiceService = $pdfInvoiceService;
        $this->notificationService = $notificationService;
    }

    /**
     * FR-CO-003: Calculate order summary
     */

    // public function calculateSummary(
    //     int $userId,
    //     int $addressId = null,
    //     ?string $couponCode = null,
    //     ?int $shippingMethodId = null,
    //     ?int $coinsToRedeem = null,
    //     ?int $buyNowProductId = null,
    //     ?int $buyNowQuantity = null
    //     ): array {
    //     // Fetch address if ID is provided
    //     $address = null;
    //     if ($addressId !== null) {
    //         $address = Address::find($addressId);
    //         if (!$address) {
    //             throw new Exception('Invalid address ID');
    //         }
    //     }

    //     $user = User::findOrFail($userId);

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Cart / Buy Now
    //     |--------------------------------------------------------------------------
    //     |
    //     | Existing cart flow remains unchanged.
    //     |
    //     | If $buyNowProductId is provided:
    //     |     Use only that product + quantity.
    //     |
    //     | Otherwise:
    //     |     Use the user's cart items.
    //     |
    //     */

    //     $isBuyNow = $buyNowProductId !== null;

    //     if ($isBuyNow) {

    //         if (!$buyNowQuantity || $buyNowQuantity < 1) {
    //             throw new Exception('Invalid product quantity');
    //         }

    //         $product = Product::with([
    //             'taxCategory',
    //             'images',
    //             'primaryImage',
    //         ])->findOrFail($buyNowProductId);

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Create temporary item object
    //     |--------------------------------------------------------------------------
    //     |
    //     | We don't add anything to the cart.
    //     | This object only allows the existing calculation logic
    //     | to work for Buy Now.
    //     |
    //     */

    //         $item = new \stdClass();

    //         $item->product_id = $product->id;
    //         $item->quantity = $buyNowQuantity;
    //         $item->product = $product;

    //         $cartItems = collect([$item]);
    //     } else {

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Existing Cart Flow
    //     |--------------------------------------------------------------------------
    //     */

    //         $cart = Cart::with([
    //             'items.product.taxCategory',
    //             'items.product.images',
    //             'items.product.primaryImage',
    //         ])
    //             ->where('user_id', $userId)
    //             ->firstOrFail();

    //         if ($cart->items->isEmpty()) {
    //             throw new Exception('Cart is empty');
    //         }

    //         $cartItems = $cart->items;
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Calculate Subtotal
    //     |--------------------------------------------------------------------------
    //     */

    //     $subtotal = 0;
    //     $productDetails = [];

    //     foreach ($cartItems as $item) {

    //         $unitPrice = $user->isDistributor()
    //             ? ($item->product->distributor_price ?? $item->product->retail_price)
    //             : $item->product->retail_price;

    //         $lineTotal = $unitPrice * $item->quantity;

    //         $subtotal += $lineTotal;

    //         $taxRate = $item->product->taxCategory?->rate ?? 0;

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Product Images
    //     |--------------------------------------------------------------------------
    //     */

    //         $productImages = [];

    //         if ($item->product->images) {
    //             foreach ($item->product->images as $image) {
    //                 $productImages[] = [
    //                     'id' => $image->id,
    //                     'image' => $image->image,
    //                     'image_url' => asset('storage/' . $image->image),
    //                     'is_primary' => $image->is_primary,
    //                     'sort_order' => $image->sort_order,
    //                 ];
    //             }
    //         }

    //         $productDetails[] = [
    //             'item' => $item,
    //             'unitPrice' => $unitPrice,
    //             'lineTotal' => $lineTotal,
    //             'taxRate' => $taxRate,
    //             'taxCategoryName' => $item->product->taxCategory?->name ?? 'No Tax',
    //             'images' => $productImages,
    //             'primary_image' => $item->product->primaryImage
    //                 ? asset('storage/' . $item->product->primaryImage->image)
    //                 : null,
    //         ];
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Coupon
    //     |--------------------------------------------------------------------------
    //     */

    //     $couponDiscount = 0;
    //     $couponData = null;

    //     if ($couponCode) {

    //         $coupon = Coupon::where(
    //             'code',
    //             strtoupper($couponCode)
    //         )->first();

    //         if (
    //             $coupon &&
    //             $coupon->isValid() &&
    //             $this->validateCouponForUser($coupon, $userId)
    //         ) {

    //             $couponDiscount = $this->calculateCouponDiscount(
    //                 $coupon,
    //                 $subtotal
    //             );

    //             $couponData = [
    //                 'code' => $coupon->code,
    //                 'title' => $coupon->title,
    //                 'type' => $coupon->type,
    //                 'value' => (string) $coupon->value,
    //                 'discount_amount' => round($couponDiscount, 2),
    //             ];
    //         } else {

    //             throw new Exception('Invalid coupon code');
    //         }
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Subtotal After Discount
    //     |--------------------------------------------------------------------------
    //     */

    //     $subtotalAfterDiscount = $subtotal - $couponDiscount;

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Shipping
    //     |--------------------------------------------------------------------------
    //     */

    //     $shippingCost = 0;
    //     $shippingData = null;

    //     if ($shippingMethodId) {

    //         $shippingMethod = ShippingMethod::find($shippingMethodId);

    //         if ($shippingMethod && $shippingMethod->is_active) {

    //             if (
    //                 $shippingMethod->min_order_amount &&
    //                 $subtotalAfterDiscount < $shippingMethod->min_order_amount
    //             ) {
    //                 throw new Exception(
    //                     "Minimum order amount for this shipping method is ₹" .
    //                         $shippingMethod->min_order_amount
    //                 );
    //             }

    //             if (
    //                 $shippingMethod->max_order_amount &&
    //                 $subtotalAfterDiscount > $shippingMethod->max_order_amount
    //             ) {
    //                 throw new Exception(
    //                     "Order amount exceeds maximum limit for this shipping method"
    //                 );
    //             }

    //             $shippingCost = $this->calculateShippingCost(
    //                 $shippingMethod,
    //                 $subtotalAfterDiscount
    //             );

    //             $shippingData = [
    //                 'id' => $shippingMethod->id,
    //                 'name' => $shippingMethod->name,
    //                 'code' => $shippingMethod->code,
    //                 'estimated_days' => $shippingMethod->estimated_days,
    //                 'cost' => round($shippingCost, 2),
    //             ];
    //         } else {

    //             throw new Exception('Invalid shipping method');
    //         }
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Tax Variables
    //     |--------------------------------------------------------------------------
    //     */

    //     $productTaxTotal = 0;
    //     $productGstTotal = 0;
    //     $productOtherTaxTotal = 0;

    //     $itemsWithTax = [];
    //     $taxBreakdown = [];
    //     $taxByCategory = [];
    //     $productTaxBreakdown = [];

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Product Tax Calculation
    //     |--------------------------------------------------------------------------
    //     */

    //     // Get supplier state from config
    //     $supplierState = config('app.supplier_state', 'Maharashtra');

    //     // Get delivery state from address if available, otherwise use a default or null
    //     $deliveryState = $address ? $address->state : null;

    //     foreach ($productDetails as $index => $product) {

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Discount Distribution
    //     |--------------------------------------------------------------------------
    //     |
    //     | Coupon discount is distributed proportionally across products.
    //     |
    //     */

    //         $proportion = $subtotal > 0
    //             ? $product['lineTotal'] / $subtotal
    //             : 0;

    //         $discountedLineTotal =
    //             $product['lineTotal'] -
    //             ($couponDiscount * $proportion);

    //         $taxRate = $product['taxRate'];

    //         $taxAmount = ($discountedLineTotal * $taxRate) / 100;

    //         $productTaxTotal += $taxAmount;

    //         /*
    //     |--------------------------------------------------------------------------
    //     | GST / Other Tax
    //     |--------------------------------------------------------------------------
    //     */

    //         if ($taxRate == 18) {

    //             $productGstTotal += $taxAmount;
    //         } else {

    //             $productOtherTaxTotal += $taxAmount;
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Product Information
    //     |--------------------------------------------------------------------------
    //     */

    //         $productName = $product['item']->product->name;

    //         $productKey =
    //             'product_' .
    //             ($index + 1) .
    //             '_' .
    //             str_replace(
    //                 ' ',
    //                 '_',
    //                 strtolower($productName)
    //             );

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Product Tax Breakdown
    //     |--------------------------------------------------------------------------
    //     */

    //         $productTaxBreakdown[$productKey] = [
    //             'product_id' => $product['item']->product_id,
    //             'product_name' => $productName,
    //             'product_code' => $product['item']->product->product_code,
    //             'quantity' => $product['item']->quantity,
    //             'unit_price' => round($product['unitPrice'], 2),
    //             'tax_category' => $product['taxCategoryName'],
    //             'tax_rate' => (string) $taxRate . '%',
    //             'taxable_value' => round($discountedLineTotal, 2),
    //             'tax_amount' => round($taxAmount, 2),
    //             'line_total_after_tax' => round(
    //                 $discountedLineTotal + $taxAmount,
    //                 2
    //             ),
    //             'images' => $product['images'],
    //             'primary_image' => $product['primary_image'],
    //         ];

    //         /*
    //     |--------------------------------------------------------------------------
    //     | CGST / SGST / IGST
    //     |--------------------------------------------------------------------------
    //     */

    //         // Initialize tax components
    //         $cgst = 0;
    //         $sgst = 0;
    //         $igst = 0;

    //         // Only calculate GST if we have a delivery state
    //         if ($deliveryState) {
    //             if (
    //                 strtolower($deliveryState) ===
    //                 strtolower($supplierState)
    //             ) {
    //                 $cgst = $taxAmount / 2;
    //                 $sgst = $taxAmount / 2;
    //             } else {
    //                 $igst = $taxAmount;
    //             }
    //         } else {
    //             // If no address, treat as inter-state (IGST) or calculate as GST
    //             // You can modify this logic based on your business rules
    //             $igst = $taxAmount; // Default to IGST if no address
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Items With Tax
    //     |--------------------------------------------------------------------------
    //     */

    //         $itemsWithTax[] = [
    //             'product_id' => $product['item']->product_id,
    //             'product_name' => $product['item']->product->name,
    //             'product_code' => $product['item']->product->product_code,
    //             'quantity' => $product['item']->quantity,
    //             'unit_price' => round($product['unitPrice'], 2),
    //             'tax_category' => $product['taxCategoryName'],
    //             'tax_rate' => (string) $taxRate . '%',
    //             'taxable_value' => round($discountedLineTotal, 2),
    //             'cgst' => round($cgst, 2),
    //             'sgst' => round($sgst, 2),
    //             'igst' => round($igst, 2),
    //             'total_tax' => round($taxAmount, 2),
    //             'line_total' => round(
    //                 $discountedLineTotal + $taxAmount,
    //                 2
    //             ),
    //             'images' => $product['images'],
    //             'primary_image' => $product['primary_image'],
    //         ];

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Tax Breakdown
    //     |--------------------------------------------------------------------------
    //     */

    //         $taxBreakdown[] = [
    //             'product_name' => $product['item']->product->name,
    //             'tax_category' => $product['taxCategoryName'],
    //             'rate' => (string) $taxRate . '%',
    //             'cgst' => round($cgst, 2),
    //             'sgst' => round($sgst, 2),
    //             'igst' => round($igst, 2),
    //         ];

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Tax By Category
    //     |--------------------------------------------------------------------------
    //     */

    //         $categoryKey =
    //             $product['taxCategoryName'] .
    //             '_' .
    //             $taxRate;

    //         if (!isset($taxByCategory[$categoryKey])) {

    //             $taxByCategory[$categoryKey] = [
    //                 'category' => $product['taxCategoryName'],
    //                 'rate' => (string) $taxRate,
    //                 'taxable_amount' => 0,
    //                 'tax_amount' => 0,
    //                 'cgst' => 0,
    //                 'sgst' => 0,
    //                 'igst' => 0,
    //                 'is_gst' => ($taxRate == 18),
    //             ];
    //         }

    //         $taxByCategory[$categoryKey]['taxable_amount']
    //             += $discountedLineTotal;

    //         $taxByCategory[$categoryKey]['tax_amount']
    //             += $taxAmount;

    //         $taxByCategory[$categoryKey]['cgst']
    //             += $cgst;

    //         $taxByCategory[$categoryKey]['sgst']
    //             += $sgst;

    //         $taxByCategory[$categoryKey]['igst']
    //             += $igst;
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Total Tax
    //     |--------------------------------------------------------------------------
    //     */

    //     $totalTax = $productTaxTotal;

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Subtotal + Tax + Shipping
    //     |--------------------------------------------------------------------------
    //     */

    //     $subtotalAfterDiscountAndTax =
    //         $subtotalAfterDiscount +
    //         $totalTax +
    //         $shippingCost;

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Coins
    //     |--------------------------------------------------------------------------
    //     */

    //     $coinRedemptionData = null;

    //     $coinsUsed = 0;
    //     $amountRedeemed = 0;
    //     $coinBalance = 0;
    //     $maxCoinsRedeemable = 0;

    //     if ($user->isDistributor()) {

    //         $coinBalance = $this->getCoinBalance($userId);

    //         $maxCoinsRedeemable = min(
    //             $coinBalance,
    //             floor($subtotalAfterDiscountAndTax / 10)
    //         );

    //         if ($coinsToRedeem != null && $coinsToRedeem > 0) {

    //             if ($coinsToRedeem > $coinBalance) {

    //                 throw new Exception(
    //                     'Insufficient coin balance. You have ' .
    //                         $coinBalance .
    //                         ' coins.'
    //                 );
    //             }

    //             if ($coinsToRedeem > $maxCoinsRedeemable) {

    //                 throw new Exception(
    //                     'Cannot redeem more than ' .
    //                         $maxCoinsRedeemable .
    //                         ' coins for this order.'
    //                 );
    //             }

    //             $coinsUsed = $coinsToRedeem;

    //             $amountRedeemed = $coinsToRedeem * 10;

    //             $coinRedemptionData = [
    //                 'coins_used' => $coinsUsed,
    //                 'amount_redeemed' => $amountRedeemed,
    //                 'remaining_coins' =>
    //                 $coinBalance - $coinsUsed,
    //             ];
    //         }
    //     }

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Grand Total
    //     |--------------------------------------------------------------------------
    //     */

    //     $grandTotal = round(
    //         $subtotalAfterDiscountAndTax -
    //             $amountRedeemed,
    //         2
    //     );

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Final Response
    //     |--------------------------------------------------------------------------
    //     */

    //     return [

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Checkout Type
    //     |--------------------------------------------------------------------------
    //     */

    //         'checkout_type' => $isBuyNow
    //             ? 'buy_now'
    //             : 'cart',

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Basic Summary
    //     |--------------------------------------------------------------------------
    //     */

    //         'subtotal' => round($subtotal, 2),

    //         'coupon_discount' => round(
    //             $couponDiscount,
    //             2
    //         ),

    //         'coupon' => $couponData,

    //         'subtotal_after_discount' => round(
    //             $subtotalAfterDiscount,
    //             2
    //         ),

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Product Tax
    //     |--------------------------------------------------------------------------
    //     */

    //         'product_tax_breakdown' => $productTaxBreakdown,

    //         'tax_summary' => [
    //             'gst_18_percent' => round(
    //                 $productGstTotal,
    //                 2
    //             ),

    //             'other_tax' => round(
    //                 $productOtherTaxTotal,
    //                 2
    //             ),

    //             'total_product_tax' => round(
    //                 $productTaxTotal,
    //                 2
    //             ),
    //         ],

    //         'total_tax' => round(
    //             $totalTax,
    //             2
    //         ),

    //         'tax_by_category' => array_values(
    //             $taxByCategory
    //         ),

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Shipping
    //     |--------------------------------------------------------------------------
    //     */

    //         'shipping_cost' => round(
    //             $shippingCost,
    //             2
    //         ),

    //         'shipping_method' => $shippingData,

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Total Before Coins
    //     |--------------------------------------------------------------------------
    //     */

    //         'subtotal_after_discount_and_tax' => round(
    //             $subtotalAfterDiscountAndTax,
    //             2
    //         ),

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Coins
    //     |--------------------------------------------------------------------------
    //     */

    //         'coin_balance' => $coinBalance,

    //         'max_coins_redeemable' => $maxCoinsRedeemable,

    //         'coins_used' => $coinsUsed,

    //         'amount_redeemed' => $amountRedeemed,

    //         'coin_redemption' => $coinRedemptionData,

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Grand Total
    //     |--------------------------------------------------------------------------
    //     */

    //         'grand_total' => $grandTotal,

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Items
    //     |--------------------------------------------------------------------------
    //     */

    //         // 'items' => $itemsWithTax,

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Tax Breakdown
    //     |--------------------------------------------------------------------------
    //     */

    //         'tax_breakdown' => $taxBreakdown,

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Delivery Address
    //     |--------------------------------------------------------------------------
    //     */

    //         'delivery_address' => $address ? [
    //             'id' => $address->id,
    //             'full_address' => $this->formatAddress($address),
    //             'state' => $address->state,
    //         ] : null,

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Final Summary
    //     |--------------------------------------------------------------------------
    //     */

    //         'summary' => [

    //             'subtotal' => round(
    //                 $subtotal,
    //                 2
    //             ),

    //             'less_coupon' => round(
    //                 $couponDiscount,
    //                 2
    //             ),

    //             'net_subtotal' => round(
    //                 $subtotalAfterDiscount,
    //                 2
    //             ),

    //             'product_gst_18' => round(
    //                 $productGstTotal,
    //                 2
    //             ),

    //             'product_other_tax' => round(
    //                 $productOtherTaxTotal,
    //                 2
    //             ),

    //             'total_tax' => round(
    //                 $totalTax,
    //                 2
    //             ),

    //             'plus_shipping' => round(
    //                 $shippingCost,
    //                 2
    //             ),

    //             'less_coins' => round(
    //                 $amountRedeemed,
    //                 2
    //             ),

    //             'grand_total' => $grandTotal,
    //             'coupon_code' => $couponData['code'] ?? null,
    //             'tax_breakdown' => array_map(function ($item) {
    //                 return [
    //                     'product_name' => $item['product_name'],
    //                     'tax_category' => $item['tax_category'],
    //                     'rate' => (float) str_replace('%', '', $item['rate']), // Remove % and convert to float
    //                 ];
    //             }, $taxBreakdown),
    //         ],
    //     ];
    // }

    public function calculateSummary(
        int $userId,
        int $addressId = null,
        ?string $couponCode = null,
        ?int $shippingMethodId = null,
        ?int $coinsToRedeem = null,
        ?int $buyNowProductId = null,
        ?int $buyNowVariantId = null,
        ?int $buyNowQuantity = null
    ): array {
        // Fetch address if ID is provided
        $address = null;
        if ($addressId !== null) {
            $address = Address::find($addressId);
            if (!$address) {
                throw new Exception('Invalid address ID');
            }
        }

        $user = User::findOrFail($userId);

        /*
    |--------------------------------------------------------------------------
    | Cart / Buy Now
    |--------------------------------------------------------------------------
    */

        $isBuyNow = $buyNowProductId !== null || $buyNowVariantId !== null;

        if ($isBuyNow) {
            if (!$buyNowQuantity || $buyNowQuantity < 1) {
                throw new Exception('Invalid product quantity');
            }

            $product = null;
            $variant = null;
            $variantAttributes = null;
            $variantSku = null;
            $variantId = null;

            // If variant_id is provided
            if ($buyNowVariantId) {
                $variant = ProductVariant::with(['product.taxCategory', 'product.images', 'product.primaryImage'])
                    ->findOrFail($buyNowVariantId);

                $product = $variant->product;
                $variantId = $variant->id;
                $variantSku = $variant->sku;
                $variantAttributes = $variant->attributes;
            } else {
                // If product_id is provided
                $product = Product::with(['taxCategory', 'images', 'primaryImage'])
                    ->findOrFail($buyNowProductId);
            }

            /*
        |--------------------------------------------------------------------------
        | Create temporary item object with variant support
        |--------------------------------------------------------------------------
        */

            $item = new \stdClass();
            $item->product_id = $product->id;
            $item->variant_id = $variantId;
            $item->variant_sku = $variantSku;
            $item->variant_attributes = $variantAttributes;
            $item->quantity = $buyNowQuantity;
            $item->product = $product;
            $item->variant = $variant;

            $cartItems = collect([$item]);
        } else {
            /*
        |--------------------------------------------------------------------------
        | Existing Cart Flow with Variant Support
        |--------------------------------------------------------------------------
        */

            $cart = Cart::with([
                'items.product.taxCategory',
                'items.product.images',
                'items.product.primaryImage',
                'items.variant',
            ])
                ->where('user_id', $userId)
                ->firstOrFail();

            if ($cart->items->isEmpty()) {
                throw new Exception('Cart is empty');
            }

            $cartItems = $cart->items;
        }

        /*
    |--------------------------------------------------------------------------
    | Calculate Subtotal
    |--------------------------------------------------------------------------
    */

        $subtotal = 0;
        $productDetails = [];

        foreach ($cartItems as $item) {
            // Check if item has variant
            $hasVariant = isset($item->variant) && $item->variant !== null;

            // Determine unit price based on variant or product
            if ($hasVariant) {
                // Use variant pricing
                $unitPrice = $user->isDistributor()
                    ? ($item->variant->distributor_price ?? $item->variant->retail_price)
                    : $item->variant->retail_price;

                $taxRate = $item->product->taxCategory?->rate ?? 0;

                // Get variant images
                $variantImages = [];
                if ($item->variant->images) {
                    foreach ($item->variant->images as $image) {
                        $variantImages[] = [
                            'id' => $image->id,
                            'image' => $image->image,
                            'image_url' => asset('storage/' . $image->image),
                            'is_primary' => $image->is_primary,
                            'sort_order' => $image->sort_order,
                        ];
                    }
                }

                // Get variant primary image
                $primaryImage = $item->variant->images->where('is_primary', true)->first()
                    ?? $item->variant->images->first();

                $primaryImageUrl = $primaryImage ? asset('storage/' . $primaryImage->image) : null;
            } else {
                // Use product pricing
                $unitPrice = $user->isDistributor()
                    ? ($item->product->distributor_price ?? $item->product->retail_price)
                    : $item->product->retail_price;

                $taxRate = $item->product->taxCategory?->rate ?? 0;
                $variantImages = [];

                // Get product images
                $productImages = [];
                if ($item->product->images) {
                    foreach ($item->product->images as $image) {
                        $productImages[] = [
                            'id' => $image->id,
                            'image' => $image->image,
                            'image_url' => asset('storage/' . $image->image),
                            'is_primary' => $image->is_primary,
                            'sort_order' => $image->sort_order,
                        ];
                    }
                }

                $primaryImageUrl = $item->product->primaryImage
                    ? asset('storage/' . $item->product->primaryImage->image)
                    : null;
            }

            $lineTotal = $unitPrice * $item->quantity;
            $subtotal += $lineTotal;

            $productDetails[] = [
                'item' => $item,
                'has_variant' => $hasVariant,
                'variant_id' => $hasVariant ? $item->variant->id : null,
                'variant_sku' => $hasVariant ? $item->variant->sku : null,
                'variant_attributes' => $hasVariant ? $item->variant->attributes : null,
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineTotal,
                'taxRate' => $taxRate,
                'taxCategoryName' => $item->product->taxCategory?->name ?? 'No Tax',
                'images' => $hasVariant ? $variantImages : $productImages,
                'primary_image' => $hasVariant ? $primaryImageUrl : $primaryImageUrl,
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | Coupon
    |--------------------------------------------------------------------------
    */

        $couponDiscount = 0;
        $couponData = null;

        if ($couponCode) {
            $coupon = Coupon::where('code', strtoupper($couponCode))->first();

            if ($coupon && $coupon->isValid() && $this->validateCouponForUser($coupon, $userId)) {
                $couponDiscount = $this->calculateCouponDiscount($coupon, $subtotal);
                $couponData = [
                    'code' => $coupon->code,
                    'title' => $coupon->title,
                    'type' => $coupon->type,
                    'value' => (string) $coupon->value,
                    'discount_amount' => round($couponDiscount, 2),
                ];
            } else {
                throw new Exception('Invalid coupon code');
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Subtotal After Discount
    |--------------------------------------------------------------------------
    */

        $subtotalAfterDiscount = $subtotal - $couponDiscount;

        /*
    |--------------------------------------------------------------------------
    | Shipping
    |--------------------------------------------------------------------------
    */

        $shippingCost = 0;
        $shippingData = null;

        if ($shippingMethodId) {
            $shippingMethod = ShippingMethod::find($shippingMethodId);

            if ($shippingMethod && $shippingMethod->is_active) {
                if ($shippingMethod->min_order_amount && $subtotalAfterDiscount < $shippingMethod->min_order_amount) {
                    throw new Exception("Minimum order amount for this shipping method is ₹" . $shippingMethod->min_order_amount);
                }

                if ($shippingMethod->max_order_amount && $subtotalAfterDiscount > $shippingMethod->max_order_amount) {
                    throw new Exception("Order amount exceeds maximum limit for this shipping method");
                }

                $shippingCost = $this->calculateShippingCost($shippingMethod, $subtotalAfterDiscount);

                $shippingData = [
                    'id' => $shippingMethod->id,
                    'name' => $shippingMethod->name,
                    'code' => $shippingMethod->code,
                    'estimated_days' => $shippingMethod->estimated_days,
                    'cost' => round($shippingCost, 2),
                ];
            } else {
                throw new Exception('Invalid shipping method');
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Tax Variables
    |--------------------------------------------------------------------------
    */

        $productTaxTotal = 0;
        $productGstTotal = 0;
        $productOtherTaxTotal = 0;

        $itemsWithTax = [];
        $taxBreakdown = [];
        $taxByCategory = [];
        $productTaxBreakdown = [];

        // Get supplier state from config
        $supplierState = config('app.supplier_state', 'Punjab');
        $deliveryState = $address ? $address->state : null;

        foreach ($productDetails as $index => $product) {
            $proportion = $subtotal > 0 ? $product['lineTotal'] / $subtotal : 0;
            $discountedLineTotal = $product['lineTotal'] - ($couponDiscount * $proportion);

            $taxRate = $product['taxRate'];
            $taxAmount = ($discountedLineTotal * $taxRate) / 100;
            $productTaxTotal += $taxAmount;

            // GST / Other Tax
            if ($taxRate == 18) {
                $productGstTotal += $taxAmount;
            } else {
                $productOtherTaxTotal += $taxAmount;
            }

            // Product Information
            $productName = $product['item']->product->name;
            $productKey = 'product_' . ($index + 1) . '_' . str_replace(' ', '_', strtolower($productName));

            // Product Tax Breakdown
            $productTaxBreakdown[$productKey] = [
                'product_id' => $product['item']->product_id,
                'product_name' => $productName,
                'product_code' => $product['item']->product->product_code,
                'variant_id' => $product['variant_id'] ?? null,
                'variant_sku' => $product['variant_sku'] ?? null,
                'variant_attributes' => $product['variant_attributes'] ?? null,
                'quantity' => $product['item']->quantity,
                'unit_price' => round($product['unitPrice'], 2),
                'tax_category' => $product['taxCategoryName'],
                'tax_rate' => (string) $taxRate . '%',
                'taxable_value' => round($discountedLineTotal, 2),
                'tax_amount' => round($taxAmount, 2),
                'line_total_after_tax' => round($discountedLineTotal + $taxAmount, 2),
                'images' => $product['images'],
                'primary_image' => $product['primary_image'],
            ];

            // CGST / SGST / IGST
            $cgst = 0;
            $sgst = 0;
            $igst = 0;

            if ($deliveryState) {
                if (strtolower($deliveryState) === strtolower($supplierState)) {
                    $cgst = $taxAmount / 2;
                    $sgst = $taxAmount / 2;
                } else {
                    $igst = $taxAmount;
                }
            } else {
                $igst = $taxAmount;
            }

            // Items With Tax
            $itemsWithTax[] = [
                'product_id' => $product['item']->product_id,
                'product_name' => $product['item']->product->name,
                'product_code' => $product['item']->product->product_code,
                'variant_id' => $product['variant_id'] ?? null,
                'variant_sku' => $product['variant_sku'] ?? null,
                'variant_attributes' => $product['variant_attributes'] ?? null,
                'quantity' => $product['item']->quantity,
                'unit_price' => round($product['unitPrice'], 2),
                'tax_category' => $product['taxCategoryName'],
                'tax_rate' => (string) $taxRate . '%',
                'taxable_value' => round($discountedLineTotal, 2),
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
                'total_tax' => round($taxAmount, 2),
                'line_total' => round($discountedLineTotal + $taxAmount, 2),
                'images' => $product['images'],
                'primary_image' => $product['primary_image'],
            ];

            // Tax Breakdown
            $taxBreakdown[] = [
                'product_name' => $product['item']->product->name,
                'variant_sku' => $product['variant_sku'] ?? null,
                'variant_attributes' => $product['variant_attributes'] ?? null,
                'tax_category' => $product['taxCategoryName'],
                'rate' => (string) $taxRate . '%',
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
            ];

            // Tax By Category
            $categoryKey = $product['taxCategoryName'] . '_' . $taxRate;

            if (!isset($taxByCategory[$categoryKey])) {
                $taxByCategory[$categoryKey] = [
                    'category' => $product['taxCategoryName'],
                    'rate' => (string) $taxRate,
                    'taxable_amount' => 0,
                    'tax_amount' => 0,
                    'cgst' => 0,
                    'sgst' => 0,
                    'igst' => 0,
                    'is_gst' => ($taxRate == 18),
                ];
            }

            $taxByCategory[$categoryKey]['taxable_amount'] += $discountedLineTotal;
            $taxByCategory[$categoryKey]['tax_amount'] += $taxAmount;
            $taxByCategory[$categoryKey]['cgst'] += $cgst;
            $taxByCategory[$categoryKey]['sgst'] += $sgst;
            $taxByCategory[$categoryKey]['igst'] += $igst;
        }

        /*
    |--------------------------------------------------------------------------
    | Total Tax
    |--------------------------------------------------------------------------
    */

        $totalTax = $productTaxTotal;

        /*
    |--------------------------------------------------------------------------
    | Subtotal + Tax + Shipping
    |--------------------------------------------------------------------------
    */

        $subtotalAfterDiscountAndTax = $subtotalAfterDiscount + $totalTax + $shippingCost;

        /*
    |--------------------------------------------------------------------------
    | Coins
    |--------------------------------------------------------------------------
    */

        $coinRedemptionData = null;
        $coinsUsed = 0;
        $amountRedeemed = 0;
        $coinBalance = 0;
        $maxCoinsRedeemable = 0;

        if ($user->isDistributor()) {
            $coinBalance = $this->getCoinBalance($userId);
            $maxCoinsRedeemable = min($coinBalance, floor($subtotalAfterDiscountAndTax / 10));

            if ($coinsToRedeem != null && $coinsToRedeem > 0) {
                if ($coinsToRedeem > $coinBalance) {
                    throw new Exception('Insufficient coin balance. You have ' . $coinBalance . ' coins.');
                }

                if ($coinsToRedeem > $maxCoinsRedeemable) {
                    throw new Exception('Cannot redeem more than ' . $maxCoinsRedeemable . ' coins for this order.');
                }

                $coinsUsed = $coinsToRedeem;
                $amountRedeemed = $coinsToRedeem * 10;

                $coinRedemptionData = [
                    'coins_used' => $coinsUsed,
                    'amount_redeemed' => $amountRedeemed,
                    'remaining_coins' => $coinBalance - $coinsUsed,
                ];
            }
        }

        /*
    |--------------------------------------------------------------------------
    | Grand Total
    |--------------------------------------------------------------------------
    */

        $grandTotal = round($subtotalAfterDiscountAndTax - $amountRedeemed, 2);

        /*
    |--------------------------------------------------------------------------
    | Final Response
    |--------------------------------------------------------------------------
    */

        return [
            'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',

            'subtotal' => round($subtotal, 2),
            'coupon_discount' => round($couponDiscount, 2),
            'coupon' => $couponData,
            'subtotal_after_discount' => round($subtotalAfterDiscount, 2),

            'product_tax_breakdown' => $productTaxBreakdown,
            'tax_summary' => [
                'gst_18_percent' => round($productGstTotal, 2),
                'other_tax' => round($productOtherTaxTotal, 2),
                'total_product_tax' => round($productTaxTotal, 2),
            ],
            'total_tax' => round($totalTax, 2),
            'tax_by_category' => array_values($taxByCategory),

            'shipping_cost' => round($shippingCost, 2),
            'shipping_method' => $shippingData,

            'subtotal_after_discount_and_tax' => round($subtotalAfterDiscountAndTax, 2),

            'coin_balance' => $coinBalance,
            'max_coins_redeemable' => $maxCoinsRedeemable,
            'coins_used' => $coinsUsed,
            'amount_redeemed' => $amountRedeemed,
            'coin_redemption' => $coinRedemptionData,

            'grand_total' => $grandTotal,

            'tax_breakdown' => $taxBreakdown,

            'delivery_address' => $address ? [
                'id' => $address->id,
                'full_address' => $this->formatAddress($address),
                'state' => $address->state,
            ] : null,

            'summary' => [
                'subtotal' => round($subtotal, 2),
                'less_coupon' => round($couponDiscount, 2),
                'net_subtotal' => round($subtotalAfterDiscount, 2),
                'product_gst_18' => round($productGstTotal, 2),
                'product_other_tax' => round($productOtherTaxTotal, 2),
                'total_tax' => round($totalTax, 2),
                'plus_shipping' => round($shippingCost, 2),
                'less_coins' => round($amountRedeemed, 2),
                'grand_total' => $grandTotal,
                'coupon_code' => $couponData['code'] ?? null,
                'tax_breakdown' => array_map(function ($item) {
                    return [
                        'product_name' => $item['product_name'],
                        'variant_sku' => $item['variant_sku'] ?? null,
                        'variant_attributes' => $item['variant_attributes'] ?? null,
                        'tax_category' => $item['tax_category'],
                        'rate' => (float) str_replace('%', '', $item['rate']),
                    ];
                }, $taxBreakdown),
            ],
        ];
    }


    /**
     * Calculate coupon discount
     */
    private function calculateCouponDiscount(Coupon $coupon, float $subtotal): float
    {
        if ($coupon->min_order && $subtotal < $coupon->min_order) {
            throw new Exception("Minimum order amount of ₹" . $coupon->min_order . " required for this coupon");
        }

        $discount = 0;
        if ($coupon->type === 'percentage') {
            $discount = ($subtotal * $coupon->value) / 100;
        } else {
            $discount = $coupon->value;
        }

        if ($coupon->max_order && $discount > $coupon->max_order) {
            $discount = $coupon->max_order;
        }

        return $discount;
    }

    /**
     * Calculate shipping cost
     */
    private function calculateShippingCost(ShippingMethod $shippingMethod, float $orderAmount): float
    {
        $cost = 0;
        switch ($shippingMethod->rate_type) {
            case 'flat':
                $cost = $shippingMethod->base_rate + $shippingMethod->rate_value;
                break;
            case 'percentage':
                $cost = $shippingMethod->base_rate + ($orderAmount * $shippingMethod->rate_value / 100);
                break;
            case 'free':
                $cost = 0;
                break;
            default:
                $cost = $shippingMethod->base_rate + $shippingMethod->rate_value;
        }
        return $cost;
    }

    /**
     * Validate coupon for user
     */
    private function validateCouponForUser(Coupon $coupon, int $userId): bool
    {
        if ($coupon->max_uses && $coupon->used_count >= $coupon->max_uses) {
            throw new Exception('This coupon has reached its usage limit');
        }

        $userUsage = CouponUsage::where('coupon_id', $coupon->id)
            ->where('user_id', $userId)
            ->count();

        if ($userUsage > 0) {
            throw new Exception('You have already used this coupon');
        }

        return true;
    }

    /**
     * Apply coupon to cart
     */
    public function applyCoupon(int $userId, int $addressId, string $couponCode, ?int $coinsToRedeem = null): array
    {
        return $this->calculateSummary($userId, $addressId, $couponCode, null, $coinsToRedeem);
    }

    /**
     * Apply shipping method
     */
    public function applyShipping(int $userId, int $addressId, int $shippingMethodId, ?string $couponCode = null, ?int $coinsToRedeem = null): array
    {
        return $this->calculateSummary($userId, $addressId, $couponCode, $shippingMethodId, $coinsToRedeem);
    }

    /**
     * FR-CO-004: Apply coin redemption
     */
    public function applyCoins(int $userId, int $coinsToRedeem): array
    {
        $user = User::findOrFail($userId);
        if (!$user->isDistributor()) {
            throw new Exception('Coin redemption only for distributors');
        }

        $coinBalance = $this->getCoinBalance($userId);
        if ($coinsToRedeem > $coinBalance) {
            throw new Exception('Insufficient coin balance');
        }

        $cartTotal = $this->getCurrentCartTotal($userId);
        $maxCoins = floor($cartTotal / 10);
        if ($coinsToRedeem > $maxCoins) {
            throw new Exception('Cannot redeem more than order value');
        }

        $amountRedeemed = $coinsToRedeem * 10;
        $authorization = $this->authorizeCoinRedemption($userId, $coinsToRedeem, $amountRedeemed);

        $redemption = CoinRedemption::create([
            'user_id' => $userId,
            'order_id' => null,
            'coins_used' => $coinsToRedeem,
            'amount_redeemed' => $amountRedeemed,
            'status' => 'authorized',
            'api_authorization_id' => $authorization['id'],
            'authorized_at' => now(),
        ]);

        return [
            'success' => true,
            'coins_used' => $coinsToRedeem,
            'amount_redeemed' => $amountRedeemed,
            'remaining_coins' => $coinBalance - $coinsToRedeem,
            'redemption_id' => $redemption->id,
        ];
    }

    /**
     * Place order (FR-CO-005)
     */
    // public function placeOrder(int $userId, array $data): array
    // {
    //     $address = Address::findOrFail($data['address_id']);
    //     $user = User::findOrFail($userId);

    //     // Extract summary data
    //     $summary = $data['summary_data'] ?? [];
    //     $checkoutType = $data['checkout_type'] ?? 'cart';

    //     // Get cart or buy now items
    //     $cartItems = [];
    //     $isBuyNow = $checkoutType === 'buy_now';

    //     if ($isBuyNow) {
    //         if (!isset($summary['items']) || empty($summary['items'])) {
    //             throw new Exception('No items found for Buy Now');
    //         }

    //         foreach ($summary['items'] as $itemData) {
    //             $product = Product::with('taxCategory')->find($itemData['product_id']);
    //             if (!$product) {
    //                 throw new Exception("Product not found: {$itemData['product_id']}");
    //             }

    //             // Check if variant exists
    //             $variant = null;
    //             if (isset($itemData['variant_id']) && $itemData['variant_id']) {
    //                 $variant = ProductVariant::find($itemData['variant_id']);
    //                 if (!$variant) {
    //                     throw new Exception("Variant not found: {$itemData['variant_id']}");
    //                 }
    //                 // Check variant stock
    //                 if ($variant->stock_quantity < $itemData['quantity']) {
    //                     throw new Exception("Insufficient stock for variant: {$variant->sku}");
    //                 }
    //             } else {
    //                 // Check product stock
    //                 if ($product->stock_quantity < $itemData['quantity']) {
    //                     throw new Exception("Insufficient stock for: {$product->name}");
    //                 }
    //             }

    //             $cartItems[] = (object) [
    //                 'product_id' => $product->id,
    //                 'product' => $product,
    //                 'variant_id' => $variant ? $variant->id : null,
    //                 'variant' => $variant,
    //                 'quantity' => $itemData['quantity'],
    //                 'unit_price' => $itemData['unit_price'] ?? 0,
    //             ];
    //         }
    //     } else {
    //         // Cart flow
    //         $cart = Cart::with(['items.product.taxCategory', 'items.variant'])
    //             ->where('user_id', $userId)
    //             ->firstOrFail();

    //         if ($cart->items->isEmpty()) {
    //             throw new Exception('Cart is empty');
    //         }

    //         // Check stock for all items
    //         foreach ($cart->items as $item) {
    //             if ($item->variant_id) {
    //                 $variant = $item->variant;
    //                 if (!$variant) {
    //                     throw new Exception("Variant not found for product: {$item->product->name}");
    //                 }
    //                 if ($variant->stock_quantity < $item->quantity) {
    //                     throw new Exception("Insufficient stock for variant: {$variant->sku}");
    //                 }
    //             } else {
    //                 if ($item->product->stock_quantity < $item->quantity) {
    //                     throw new Exception("Insufficient stock for: {$item->product->name}");
    //                 }
    //             }
    //         }

    //         $cartItems = $cart->items;
    //     }

    //     // Use the grand total from request
    //     $grandTotal = $data['grand_total'];

    //     // Handle coin redemption
    //     $coinRedemption = null;
    //     $coinsUsed = 0;
    //     $coinRedeemedAmount = 0;

    //     if (isset($data['redemption_id'])) {
    //         $coinRedemption = CoinRedemption::where('id', $data['redemption_id'])
    //             ->where('user_id', $userId)
    //             ->where('status', 'authorized')
    //             ->first();
    //         if (!$coinRedemption) {
    //             throw new Exception('Invalid or expired coin redemption');
    //         }

    //         $coinsUsed = $coinRedemption->coins_used ?? 0;
    //         $coinRedeemedAmount = $coinRedemption->amount_redeemed ?? 0;
    //     }

    //     return DB::transaction(function () use ($user, $address, $coinRedemption, $coinsUsed, $coinRedeemedAmount, $grandTotal, $data, $summary, $cartItems, $isBuyNow) {

    //         // Calculate totals from items
    //         $subtotal = 0;
    //         $totalTax = 0;
    //         $totalAfterDiscount = 0;
    //         $orderItemsData = [];
    //         $taxBreakdown = [];

    //         // Get coupon discount from summary
    //         $couponDiscount = $summary['coupon_discount'] ?? 0;
    //         $couponCode = $summary['coupon_code'] ?? null;

    //         // First pass: Calculate subtotal and determine discount distribution
    //         $itemsWithPrices = [];
    //         $totalSubtotal = 0;

    //         foreach ($cartItems as $item) {
    //             $hasVariant = isset($item->variant) && $item->variant !== null;

    //             // Determine unit price based on variant or product
    //             if ($hasVariant) {
    //                 $unitPrice = $user->isDistributor()
    //                     ? ($item->variant->distributor_price ?? $item->variant->retail_price)
    //                     : $item->variant->retail_price;
    //             } else {
    //                 $unitPrice = $user->isDistributor()
    //                     ? ($item->product->distributor_price ?? $item->product->retail_price)
    //                     : $item->product->retail_price;
    //             }

    //             $lineTotal = $unitPrice * $item->quantity;
    //             $totalSubtotal += $lineTotal;

    //             $itemsWithPrices[] = [
    //                 'item' => $item,
    //                 'has_variant' => $hasVariant,
    //                 'unitPrice' => $unitPrice,
    //                 'lineTotal' => $lineTotal,
    //             ];
    //         }

    //         // Second pass: Apply coupon discount proportionally and calculate tax
    //         foreach ($itemsWithPrices as $index => $itemData) {
    //             $item = $itemData['item'];
    //             $hasVariant = $itemData['has_variant'];
    //             $unitPrice = $itemData['unitPrice'];
    //             $lineTotal = $itemData['lineTotal'];

    //             // Calculate proportional discount for this item
    //             $proportion = $totalSubtotal > 0 ? $lineTotal / $totalSubtotal : 0;
    //             $itemDiscount = $couponDiscount * $proportion;

    //             // Apply discount to get discounted line total
    //             $discountedLineTotal = $lineTotal - $itemDiscount;

    //             // Calculate tax on discounted amount
    //             $taxRate = $item->product->taxCategory?->rate ?? 0;
    //             $taxAmount = ($discountedLineTotal * $taxRate) / 100;

    //             // Calculate final line total (after discount + tax)
    //             $finalLineTotal = $discountedLineTotal + $taxAmount;

    //             // Accumulate totals
    //             $subtotal += $lineTotal;
    //             $totalTax += $taxAmount;
    //             $totalAfterDiscount += $discountedLineTotal;

    //             // Get variant info for display
    //             $variantSku = $hasVariant ? $item->variant->sku : null;
    //             $variantAttributes = $hasVariant ? $item->variant->attributes : null;

    //             // Build tax breakdown for this item
    //             $taxCategoryName = $item->product->taxCategory?->name ?? 'Default';
    //             $taxBreakdown[] = [
    //                 'product_id' => $item->product_id,
    //                 'product_name' => $item->product->name,
    //                 'product_code' => $item->product->product_code ?? null,
    //                 'variant_id' => $item->variant_id ?? null,
    //                 'variant_sku' => $variantSku,
    //                 'variant_attributes' => $variantAttributes,
    //                 'quantity' => $item->quantity,
    //                 'unit_price' => $unitPrice,
    //                 'line_total_before_discount' => $lineTotal,
    //                 'line_total_after_discount' => round($discountedLineTotal, 2),
    //                 'tax_category' => $taxCategoryName,
    //                 'tax_rate' => $taxRate,
    //                 'tax_amount' => round($taxAmount, 2),
    //                 'line_total_after_tax' => round($finalLineTotal, 2),
    //             ];

    //             $orderItemsData[] = [
    //                 'item' => $item,
    //                 'has_variant' => $hasVariant,
    //                 'unit_price' => $unitPrice,
    //                 'tax_rate' => $taxRate,
    //                 'tax_amount' => $taxAmount,
    //                 'line_total_after_discount' => $discountedLineTotal,
    //                 'line_total' => $finalLineTotal,
    //             ];
    //         }

    //         // Get values from summary data with fallbacks
    //         $shippingCharge = $summary['shipping_charge'] ?? $data['shipping_cost'] ?? 0;
    //         $amountRedeemed = $summary['amount_redeemed'] ?? $coinRedeemedAmount ?? 0;
    //         $netSubtotal = $summary['net_subtotal'] ?? $subtotal;
    //         $shippingMethodId = $summary['shipping_method_id'] ?? null;

    //         // Calculate tax by category
    //         $taxByCategory = $this->calculateTaxByCategory($taxBreakdown);

    //         // Add tax summary to the breakdown
    //         $taxBreakdownSummary = [
    //             'items' => $taxBreakdown,
    //             'summary' => [
    //                 'subtotal' => round($subtotal, 2),
    //                 'coupon_discount' => round($couponDiscount, 2),
    //                 'subtotal_after_discount' => round($totalAfterDiscount, 2),
    //                 'total_tax' => round($totalTax, 2),
    //                 'shipping_charge' => round($shippingCharge, 2),
    //                 'coin_redeemed' => $coinsUsed,
    //                 'coin_redeemed_amount' => round($amountRedeemed, 2),
    //                 'grand_total' => round($grandTotal, 2),
    //             ],
    //             'tax_by_category' => $taxByCategory,
    //         ];

    //         // Create the order
    //         $order = Order::create([
    //             'order_reference' => 'ORD-' . strtoupper(uniqid()),
    //             'user_id' => $user->id,
    //             'billing_address_id' => $data['address_id'],
    //             'delivery_address_id' => $data['address_id'],
    //             'order_type' => $user->isDistributor() ? 'distributor' : 'retail',
    //             'subtotal' => round($subtotal, 2),
    //             'total_gst' => round($totalTax, 2),
    //             'shipping_charge' => round($shippingCharge, 2),
    //             'shipping_method_id' => $shippingMethodId,
    //             'coupon_code' => $couponCode,
    //             'coupon_discount' => round($couponDiscount, 2),
    //             'coin_redeemed' => $summary['coin_redeemed'] ?? $coinsUsed,
    //             'coin_redeemed_amount' => round($amountRedeemed, 2),
    //             'total_payable' => round($grandTotal, 2),
    //             'amount_paid' => 0,
    //             'status' => 'pending',
    //             'tax_breakdown' => json_encode($taxBreakdownSummary),
    //             'summary_data' => json_encode($summary),
    //             'payment_gateway' => $data['payment_gateway'] ?? null,
    //             'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',
    //         ]);

    //         // Create order lines with variant support
    //         foreach ($orderItemsData as $itemData) {
    //             $item = $itemData['item'];
    //             $hasVariant = $itemData['has_variant'];

    //             $orderLineData = [
    //                 'order_id' => $order->id,
    //                 'product_id' => $item->product_id,
    //                 'variant_id' => $item->variant_id ?? null,
    //                 'quantity' => $item->quantity,
    //                 'unit_price' => round($itemData['unit_price'], 2),
    //                 'gst_rate' => $itemData['tax_rate'],
    //                 'gst_amount' => round($itemData['tax_amount'], 2),
    //                 'line_total' => round($itemData['line_total'], 2),
    //                 'commissionable_volume' => $item->product->commissionable_volume ?? 0,
    //             ];

    //             OrderLine::create($orderLineData);
    //         }

    //         // Update coin redemption with order
    //         if ($coinRedemption) {
    //             $coinRedemption->update([
    //                 'order_id' => $order->id,
    //                 'status' => 'used'
    //             ]);
    //         }
    //         $userId = Auth::user()->id;
    //         // Clear cart only if it's not buy now
    //         if (!$isBuyNow) {
    //             $cart = Cart::where('user_id', $userId)->first();
    //             if ($cart) {
    //                 $cart->items()->delete();
    //                 $cart->delete();
    //             }
    //         }

    //         // Create Razorpay order
    //         $razorpayOrder = $this->razorpayService->createOrder($order);

    //         return [
    //             'order_id' => $order->id,
    //             'order_reference' => $order->order_reference,
    //             'amount' => $order->total_payable,
    //             'razorpay_order_id' => $razorpayOrder['id'],
    //             'razorpay_key' => config('services.razorpay.key_id'),
    //             'status' => 'pending',
    //             'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',
    //         ];
    //     });
    // }

    public function placeOrder(int $userId, array $data): array
    {
        $address = Address::findOrFail($data['address_id']);
        $user = User::findOrFail($userId);

        // Extract summary data
        $summary = $data['summary_data'] ?? [];
        $checkoutType = $data['checkout_type'] ?? 'cart';

        // Get supplier state from config
        $supplierState = strtolower(config('app.supplier_state', 'Maharashtra'));
        $deliveryState = $address ? strtolower($address->state) : null;

        // Get cart or buy now items
        $cartItems = [];
        $isBuyNow = $checkoutType === 'buy_now';

        if ($isBuyNow) {
            if (!isset($summary['items']) || empty($summary['items'])) {
                throw new Exception('No items found for Buy Now');
            }

            foreach ($summary['items'] as $itemData) {
                $product = Product::with('taxCategory')->find($itemData['product_id']);
                if (!$product) {
                    throw new Exception("Product not found: {$itemData['product_id']}");
                }

                // Check if variant exists
                $variant = null;
                if (isset($itemData['variant_id']) && $itemData['variant_id']) {
                    $variant = ProductVariant::find($itemData['variant_id']);
                    if (!$variant) {
                        throw new Exception("Variant not found: {$itemData['variant_id']}");
                    }
                    // Check variant stock
                    if ($variant->stock_quantity < $itemData['quantity']) {
                        throw new Exception("Insufficient stock for variant: {$variant->sku}");
                    }
                } else {
                    // Check product stock
                    if ($product->stock_quantity < $itemData['quantity']) {
                        throw new Exception("Insufficient stock for: {$product->name}");
                    }
                }

                $cartItems[] = (object) [
                    'product_id' => $product->id,
                    'product' => $product,
                    'variant_id' => $variant ? $variant->id : null,
                    'variant' => $variant,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'] ?? 0,
                ];
            }
        } else {
            // Cart flow
            $cart = Cart::with(['items.product.taxCategory', 'items.variant'])
                ->where('user_id', $userId)
                ->firstOrFail();

            if ($cart->items->isEmpty()) {
                throw new Exception('Cart is empty');
            }

            // Check stock for all items
            foreach ($cart->items as $item) {
                if ($item->variant_id) {
                    $variant = $item->variant;
                    if (!$variant) {
                        throw new Exception("Variant not found for product: {$item->product->name}");
                    }
                    if ($variant->stock_quantity < $item->quantity) {
                        throw new Exception("Insufficient stock for variant: {$variant->sku}");
                    }
                } else {
                    if ($item->product->stock_quantity < $item->quantity) {
                        throw new Exception("Insufficient stock for: {$item->product->name}");
                    }
                }
            }

            $cartItems = $cart->items;
        }

        // Use the grand total from request
        $grandTotal = $data['grand_total'];

        // Handle coin redemption
        $coinRedemption = null;
        $coinsUsed = 0;
        $coinRedeemedAmount = 0;

        if (isset($data['redemption_id'])) {
            $coinRedemption = CoinRedemption::where('id', $data['redemption_id'])
                ->where('user_id', $userId)
                ->where('status', 'authorized')
                ->first();
            if (!$coinRedemption) {
                throw new Exception('Invalid or expired coin redemption');
            }

            $coinsUsed = $coinRedemption->coins_used ?? 0;
            $coinRedeemedAmount = $coinRedemption->amount_redeemed ?? 0;
        }

        return DB::transaction(function () use ($user, $address, $coinRedemption, $coinsUsed, $coinRedeemedAmount, $grandTotal, $data, $summary, $cartItems, $isBuyNow, $supplierState, $deliveryState) {

            // Calculate totals from items
            $subtotal = 0;
            $totalTax = 0;
            $totalCgst = 0;
            $totalSgst = 0;
            $totalIgst = 0;
            $totalAfterDiscount = 0;
            $orderItemsData = [];
            $taxBreakdown = [];
            $itemsWithTaxSplit = [];

            // Get coupon discount from summary
            $couponDiscount = $summary['coupon_discount'] ?? 0;
            $couponCode = $summary['coupon_code'] ?? null;

            // First pass: Calculate subtotal and determine discount distribution
            $itemsWithPrices = [];
            $totalSubtotal = 0;

            foreach ($cartItems as $item) {
                $hasVariant = isset($item->variant) && $item->variant !== null;

                // Determine unit price based on variant or product
                if ($hasVariant) {
                    $unitPrice = $user->isDistributor()
                        ? ($item->variant->distributor_price ?? $item->variant->retail_price)
                        : $item->variant->retail_price;
                } else {
                    $unitPrice = $user->isDistributor()
                        ? ($item->product->distributor_price ?? $item->product->retail_price)
                        : $item->product->retail_price;
                }

                $lineTotal = $unitPrice * $item->quantity;
                $totalSubtotal += $lineTotal;

                $itemsWithPrices[] = [
                    'item' => $item,
                    'has_variant' => $hasVariant,
                    'unitPrice' => $unitPrice,
                    'lineTotal' => $lineTotal,
                ];
            }

            // Second pass: Apply coupon discount proportionally and calculate tax with split
            foreach ($itemsWithPrices as $index => $itemData) {
                $item = $itemData['item'];
                $hasVariant = $itemData['has_variant'];
                $unitPrice = $itemData['unitPrice'];
                $lineTotal = $itemData['lineTotal'];

                // Calculate proportional discount for this item
                $proportion = $totalSubtotal > 0 ? $lineTotal / $totalSubtotal : 0;
                $itemDiscount = $couponDiscount * $proportion;

                // Apply discount to get discounted line total
                $discountedLineTotal = $lineTotal - $itemDiscount;

                // Calculate tax on discounted amount
                $taxRate = $item->product->taxCategory?->rate ?? 0;
                $taxAmount = ($discountedLineTotal * $taxRate) / 100;

                // =============================================
                // TAX SPLIT LOGIC - PERCENTAGE BASED
                // =============================================
                $cgstRate = 0;
                $sgstRate = 0;
                $igstRate = 0;
                $cgstAmount = 0;
                $sgstAmount = 0;
                $igstAmount = 0;

                // Check if delivery state is Punjab (case-insensitive)
                $isPunjab = $deliveryState && $deliveryState === 'punjab';

                // Check if it's an inter-state transaction
                $isInterState = $deliveryState && $deliveryState !== $supplierState;

                if ($deliveryState) {
                    if ($isPunjab) {
                        // PUNJAB: Split tax rate equally between CGST and SGST (percentage)
                        $cgstRate = $taxRate / 2;
                        $sgstRate = $taxRate / 2;
                        $igstRate = 0;

                        $cgstAmount = ($discountedLineTotal * $cgstRate) / 100;
                        $sgstAmount = ($discountedLineTotal * $sgstRate) / 100;
                        $igstAmount = 0;
                    } elseif ($isInterState) {
                        // INTER-STATE: Full tax as IGST (percentage)
                        $igstRate = $taxRate;
                        $cgstRate = 0;
                        $sgstRate = 0;

                        $igstAmount = $taxAmount;
                        $cgstAmount = 0;
                        $sgstAmount = 0;
                    } else {
                        // INTRA-STATE (non-Punjab): Split tax rate equally between CGST and SGST
                        $cgstRate = $taxRate / 2;
                        $sgstRate = $taxRate / 2;
                        $igstRate = 0;

                        $cgstAmount = ($discountedLineTotal * $cgstRate) / 100;
                        $sgstAmount = ($discountedLineTotal * $sgstRate) / 100;
                        $igstAmount = 0;
                    }
                } else {
                    // No delivery state: Default to IGST
                    $igstRate = $taxRate;
                    $igstAmount = $taxAmount;
                    $cgstRate = 0;
                    $sgstRate = 0;
                    $cgstAmount = 0;
                    $sgstAmount = 0;
                }

                // Calculate final line total (after discount + tax)
                $finalLineTotal = $discountedLineTotal + $taxAmount;

                // Accumulate totals
                $subtotal += $lineTotal;
                $totalTax += $taxAmount;
                $totalCgst += $cgstAmount;
                $totalSgst += $sgstAmount;
                $totalIgst += $igstAmount;
                $totalAfterDiscount += $discountedLineTotal;

                // Get variant info for display
                $variantSku = $hasVariant ? $item->variant->sku : null;
                $variantAttributes = $hasVariant ? $item->variant->attributes : null;

                // Build tax breakdown for this item
                $taxCategoryName = $item->product->taxCategory?->name ?? 'Default';
                $taxBreakdown[] = [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name,
                    'product_code' => $item->product->product_code ?? null,
                    'variant_id' => $item->variant_id ?? null,
                    'variant_sku' => $variantSku,
                    'variant_attributes' => $variantAttributes,
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                    'line_total_before_discount' => $lineTotal,
                    'line_total_after_discount' => round($discountedLineTotal, 2),
                    'tax_category' => $taxCategoryName,
                    'tax_rate' => $taxRate,
                    'cgst_rate' => round($cgstRate, 2),
                    'sgst_rate' => round($sgstRate, 2),
                    'igst_rate' => round($igstRate, 2),
                    'cgst_amount' => round($cgstAmount, 2),
                    'sgst_amount' => round($sgstAmount, 2),
                    'igst_amount' => round($igstAmount, 2),
                    'tax_amount' => round($taxAmount, 2),
                    'line_total_after_tax' => round($finalLineTotal, 2),
                ];

                $orderItemsData[] = [
                    'item' => $item,
                    'has_variant' => $hasVariant,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                    'cgst_rate' => $cgstRate,
                    'sgst_rate' => $sgstRate,
                    'igst_rate' => $igstRate,
                    'cgst_amount' => $cgstAmount,
                    'sgst_amount' => $sgstAmount,
                    'igst_amount' => $igstAmount,
                    'tax_amount' => $taxAmount,
                    'line_total_after_discount' => $discountedLineTotal,
                    'line_total' => $finalLineTotal,
                ];

                // Store for order lines with tax split
                $itemsWithTaxSplit[] = [
                    'product_id' => $item->product_id,
                    'product' => $item->product,
                    'variant_id' => $item->variant_id ?? null,
                    'variant' => $item->variant ?? null,
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'discounted_line_total' => $discountedLineTotal,
                    'tax_rate' => $taxRate,
                    'cgst_rate' => $cgstRate,
                    'sgst_rate' => $sgstRate,
                    'igst_rate' => $igstRate,
                    'cgst_amount' => $cgstAmount,
                    'sgst_amount' => $sgstAmount,
                    'igst_amount' => $igstAmount,
                    'tax_amount' => $taxAmount,
                    'final_line_total' => $finalLineTotal,
                ];
            }

            // Get values from summary data with fallbacks
            $shippingCharge = $summary['shipping_charge'] ?? $data['shipping_cost'] ?? 0;
            $amountRedeemed = $summary['amount_redeemed'] ?? $coinRedeemedAmount ?? 0;
            $netSubtotal = $summary['net_subtotal'] ?? $subtotal;
            $shippingMethodId = $summary['shipping_method_id'] ?? null;

            // Calculate tax by category
            $taxByCategory = $this->calculateTaxByCategory($taxBreakdown);

            // Add tax summary to the breakdown
            $taxBreakdownSummary = [
                'items' => $taxBreakdown,
                'summary' => [
                    'subtotal' => round($subtotal, 2),
                    'coupon_discount' => round($couponDiscount, 2),
                    'subtotal_after_discount' => round($totalAfterDiscount, 2),
                    'total_tax' => round($totalTax, 2),
                    'total_cgst' => round($totalCgst, 2),
                    'total_sgst' => round($totalSgst, 2),
                    'total_igst' => round($totalIgst, 2),
                    'shipping_charge' => round($shippingCharge, 2),
                    'coin_redeemed' => $coinsUsed,
                    'coin_redeemed_amount' => round($amountRedeemed, 2),
                    'grand_total' => round($grandTotal, 2),
                ],
                'tax_by_category' => $taxByCategory,
                'delivery_state' => $deliveryState,
                'supplier_state' => $supplierState,
            ];

            // Create the order
            $order = Order::create([
                'order_reference' => 'ORD-' . strtoupper(uniqid()),
                'user_id' => $user->id,
                'billing_address_id' => $data['address_id'],
                'delivery_address_id' => $data['address_id'],
                'order_type' => $user->isDistributor() ? 'distributor' : 'retail',
                'subtotal' => round($subtotal, 2),
                'total_gst' => round($totalTax, 2),
                'total_cgst' => round($totalCgst, 2),
                'total_sgst' => round($totalSgst, 2),
                'total_igst' => round($totalIgst, 2),
                'shipping_charge' => round($shippingCharge, 2),
                'shipping_method_id' => $shippingMethodId,
                'coupon_code' => $couponCode,
                'coupon_discount' => round($couponDiscount, 2),
                'coin_redeemed' => $summary['coin_redeemed'] ?? $coinsUsed,
                'coin_redeemed_amount' => round($amountRedeemed, 2),
                'total_payable' => round($grandTotal, 2),
                'amount_paid' => 0,
                'status' => 'pending',
                'tax_breakdown' => json_encode($taxBreakdownSummary),
                'summary_data' => json_encode($summary),
                'payment_gateway' => $data['payment_gateway'] ?? null,
                'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',
            ]);

            // Create order lines with CGST, SGST, IGST columns (percentage-based)
            foreach ($itemsWithTaxSplit as $itemData) {
                $orderLineData = [
                    'order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'variant_id' => $itemData['variant_id'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => round($itemData['unit_price'], 2),

                    // Tax rates (percentage)
                    'gst_rate' => $itemData['tax_rate'],
                    'cgst_rate' => round($itemData['cgst_rate'], 2),
                    'sgst_rate' => round($itemData['sgst_rate'], 2),
                    'igst_rate' => round($itemData['igst_rate'], 2),

                    // Tax amounts
                    'gst_amount' => round($itemData['tax_amount'], 2),
                    'cgst_amount' => round($itemData['cgst_amount'], 2),
                    'sgst_amount' => round($itemData['sgst_amount'], 2),
                    'igst_amount' => round($itemData['igst_amount'], 2),

                    // Line totals
                    'line_total' => round($itemData['final_line_total'], 2),
                    'commissionable_volume' => $itemData['product']->commissionable_volume ?? 0,
                ];

                OrderLine::create($orderLineData);
            }

            // Update coin redemption with order
            if ($coinRedemption) {
                $coinRedemption->update([
                    'order_id' => $order->id,
                    'status' => 'used'
                ]);
            }
            $userId = Auth::user()->id;
            // Clear cart only if it's not buy now
            // if (!$isBuyNow) {
            //     $cart = Cart::where('user_id', $userId)->first();
            //     if ($cart) {
            //         $cart->items()->delete();
            //         $cart->delete();
            //     }
            // }

            // Create Razorpay order
            $razorpayOrder = $this->razorpayService->createOrder($order);

            return [
                'order_id' => $order->id,
                'order_reference' => $order->order_reference,
                'amount' => $order->total_payable,
                'razorpay_order_id' => $razorpayOrder['id'],
                'razorpay_key' => config('services.razorpay.key_id'),
                'status' => 'pending',
                'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',
                'tax_split' => [
                    'delivery_state' => $deliveryState,
                    'supplier_state' => $supplierState,
                    'total_cgst' => round($totalCgst, 2),
                    'total_sgst' => round($totalSgst, 2),
                    'total_igst' => round($totalIgst, 2),
                ],
            ];
        });
    }

    /**
     * Helper function to calculate tax breakdown by category
     */
    private function calculateTaxByCategory(array $taxBreakdown): array
    {
        $taxByCategory = [];

        foreach ($taxBreakdown as $item) {
            $category = $item['tax_category'];
            if (!isset($taxByCategory[$category])) {
                $taxByCategory[$category] = [
                    'tax_rate' => $item['tax_rate'],
                    'total_taxable_amount' => 0,
                    'total_tax_amount' => 0,
                    'items' => [],
                ];
            }

            // Use line_total_after_discount as taxable amount (after coupon discount)
            $taxByCategory[$category]['total_taxable_amount'] += $item['line_total_after_discount'] ?? $item['line_total_before_discount'] ?? 0;
            $taxByCategory[$category]['total_tax_amount'] += $item['tax_amount'];
            $taxByCategory[$category]['items'][] = $item['product_name'];
        }

        return $taxByCategory;
    }
    /**
     * FR-CO-006: Confirm order via webhook
     */

    // public function confirmOrder(string $orderReference, array $gatewayData): array
    // {
    //     $order = Order::where('order_reference', $orderReference)->firstOrFail();

    //     if ($order->status === 'confirmed') {
    //         return ['success' => true, 'message' => 'Order already confirmed'];
    //     }

    //     return DB::transaction(function () use ($order, $gatewayData) {
    //         $order->update([
    //             'status' => 'confirmed',
    //             'confirmed_at' => now(),
    //             'payment_gateway' => $gatewayData['gateway'],
    //             'gateway_transaction_id' => $gatewayData['transaction_id'],
    //             'amount_paid' => $order->total_payable,
    //         ]);

    //         // Decrement stock for products and variants
    //         foreach ($order->lines as $line) {
    //             // Check if line has variant
    //             if ($line->variant_id) {
    //                 $variant = $line->variant;
    //                 if ($variant) {
    //                     // Decrement variant stock
    //                     $variant->decrement('stock_quantity', $line->quantity);

    //                     // Also decrement product stock if tracking at product level
    //                     $product = $line->product;
    //                     if ($product) {
    //                         $product->decrement('stock_quantity', $line->quantity);
    //                     }

    //                     // Log variant stock movement
    //                     StockMovement::create([
    //                         'product_id' => $line->product_id,
    //                         'variant_id' => $line->variant_id,
    //                         'quantity' => -$line->quantity,
    //                         'available_quantity_after' => $variant->stock_quantity,
    //                         'reason' => 'Order confirmed (variant): ' . $order->order_reference,
    //                         'order_id' => $order->id,
    //                     ]);

    //                     // Check variant low stock
    //                     $variant->refresh();
    //                     if ($variant->stock_quantity <= $variant->low_stock_threshold) {
    //                         $this->sendLowStockNotification($order, $line, 'variant');
    //                     }
    //                 }
    //             } else {
    //                 // Decrement product stock (no variant)
    //                 $product = $line->product;
    //                 if ($product) {
    //                     $product->decrement('stock_quantity', $line->quantity);

    //                     // Log product stock movement
    //                     StockMovement::create([
    //                         'product_id' => $line->product_id,
    //                         'variant_id' => null,
    //                         'quantity' => -$line->quantity,
    //                         'available_quantity_after' => $product->stock_quantity,
    //                         'reason' => 'Order confirmed: ' . $order->order_reference,
    //                         'order_id' => $order->id,
    //                     ]);

    //                     // Check product low stock
    //                     $product->refresh();
    //                     if ($product->stock_quantity <= $product->low_stock_threshold) {
    //                         $this->sendLowStockNotification($order, $line, 'product');
    //                     }
    //                 }
    //             }

    //             $line->update([
    //                 'delivery_status' => 'confirmed'
    //             ]);
    //         }

    //         // Delete the cart and its items (only for cart checkout)
    //         if ($order->checkout_type !== 'buy_now') {
    //             $cart = Cart::where('user_id', $order->user_id)->first();
    //             if ($cart) {
    //                 $cart->items()->delete();
    //                 $cart->delete();
    //             }
    //         }

    //         // Generate invoice using stored summary data
    //         // $invoice = $this->invoiceService->generateInvoice($order);

    //         // try {
    //         //     $this->pdfInvoiceService->generateAndSendInvoice($order, $invoice);
    //         // } catch (\Exception $e) {
    //         //     Log::error('Failed to send invoice email: ' . $e->getMessage(), [
    //         //         'order_id' => $order->id
    //         //     ]);
    //         // }

    //         // Build full payload for commission API
    //         $payload = $this->buildCommissionPayload($order);

    //         CommissionApiEvent::create([
    //             'event_type' => 'order_post',
    //             'order_id' => $order->id,
    //             'payload' => $payload,
    //             'status' => 'pending',
    //             'retry_count' => 0,
    //             'max_retries' => 5,
    //             'last_attempt' => null,
    //             'error_message' => null,
    //             'response_data' => null,
    //         ]);

    //         // Send order confirmation notification
    //         $this->sendOrderConfirmationNotification($order, $gatewayData);

    //         return [
    //             'success' => true,
    //             'order_id' => $order->id,
    //             'order_reference' => $order->order_reference,
    //             'status' => 'confirmed',
    //             // 'invoice_number' => $invoice->invoice_number,
    //         ];
    //     });
    // }
    public function confirmOrder(string $orderReference, array $gatewayData): array
    {
        $order = Order::where('order_reference', $orderReference)->firstOrFail();

        if ($order->status === 'confirmed') {
            return ['success' => true, 'message' => 'Order already confirmed'];
        }

        return DB::transaction(function () use ($order, $gatewayData) {
            $oldStatus = $order->status;

            $order->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'payment_gateway' => $gatewayData['gateway'],
                'gateway_transaction_id' => $gatewayData['transaction_id'],
                'amount_paid' => $order->total_payable,
            ]);

            $lowStockAlerts = [];

            // Decrement stock for products and variants
            foreach ($order->lines as $line) {
                // Check if line has variant
                if ($line->variant_id) {
                    $variant = $line->variant;
                    if ($variant) {
                        // Decrement variant stock
                        $variant->decrement('stock_quantity', $line->quantity);

                        // Also decrement product stock if tracking at product level
                        $product = $line->product;
                        if ($product) {
                            $product->decrement('stock_quantity', $line->quantity);
                        }

                        // Log variant stock movement
                        StockMovement::create([
                            'product_id' => $line->product_id,
                            'variant_id' => $line->variant_id,
                            'quantity' => -$line->quantity,
                            'available_quantity_after' => $variant->stock_quantity,
                            'reason' => 'Order confirmed (variant): ' . $order->order_reference,
                            'order_id' => $order->id,
                        ]);

                        // Check variant low stock
                        $variant->refresh();
                        if ($variant->stock_quantity <= $variant->low_stock_threshold) {
                            $alert = $this->sendLowStockNotification($order, $line, 'variant');
                            $lowStockAlerts[] = [
                                'product_id' => $line->product_id,
                                'variant_id' => $line->variant_id,
                                'product_name' => $line->product?->name ?? 'Unknown',
                                'stock' => $variant->stock_quantity,
                                'threshold' => $variant->low_stock_threshold,
                                'alert_type' => 'variant_low_stock'
                            ];
                        }
                    }
                } else {
                    // Decrement product stock (no variant)
                    $product = $line->product;
                    if ($product) {
                        $product->decrement('stock_quantity', $line->quantity);

                        // Log product stock movement
                        StockMovement::create([
                            'product_id' => $line->product_id,
                            'variant_id' => null,
                            'quantity' => -$line->quantity,
                            'available_quantity_after' => $product->stock_quantity,
                            'reason' => 'Order confirmed: ' . $order->order_reference,
                            'order_id' => $order->id,
                        ]);

                        // Check product low stock
                        $product->refresh();
                        if ($product->stock_quantity <= $product->low_stock_threshold) {
                            $alert = $this->sendLowStockNotification($order, $line, 'product');
                            $lowStockAlerts[] = [
                                'product_id' => $line->product_id,
                                'product_name' => $product->name,
                                'stock' => $product->stock_quantity,
                                'threshold' => $product->low_stock_threshold,
                                'alert_type' => 'product_low_stock'
                            ];
                        }
                    }
                }

                $line->update([
                    'delivery_status' => 'confirmed'
                ]);
            }

            // Log order confirmation with low stock alerts
            $this->logAudit(
                'order_confirm',
                'orders',
                [
                    'order_reference' => $order->order_reference,
                    'status' => $oldStatus,
                    'total_amount' => $order->total_payable,
                    'user_id' => $order->user_id ?? Auth::user()->id,
                ],
                [
                    'order_reference' => $order->order_reference,
                    'status' => 'confirmed',
                    'confirmed_at' => now()->toDateTimeString(),
                    'payment_gateway' => $gatewayData['gateway'],
                    'transaction_id' => $gatewayData['transaction_id'],
                    'amount_paid' => $order->total_payable,
                    'low_stock_alerts' => $lowStockAlerts,
                    'confirmed_by' => $this->getAdminId(),
                ]
            );

            // If low stock alerts exist, log them separately
            foreach ($lowStockAlerts as $alert) {
                $this->logAudit(
                    'low_stock_alert',
                    'inventory',
                    null,
                    $alert,
                    null,
                    $this->getClientIp()
                );
            }

            // Delete the cart and its items (only for cart checkout)
            if ($order->checkout_type !== 'buy_now') {
                $cart = Cart::where('user_id', $order->user_id)->first();
                if ($cart) {
                    $cart->items()->delete();
                    $cart->delete();
                }
            }

            // Generate invoice using stored summary data
            // $invoice = $this->invoiceService->generateInvoice($order);

            // try {
            //     $this->pdfInvoiceService->generateAndSendInvoice($order, $invoice);
            // } catch (\Exception $e) {
            //     Log::error('Failed to send invoice email: ' . $e->getMessage(), [
            //         'order_id' => $order->id
            //     ]);
            // }

            // Build full payload for commission API
            $payload = $this->buildCommissionPayload($order);

            CommissionApiEvent::create([
                'event_type' => 'order_post',
                'order_id' => $order->id,
                'payload' => $payload,
                'status' => 'pending',
                'retry_count' => 0,
                'max_retries' => 5,
                'last_attempt' => null,
                'error_message' => null,
                'response_data' => null,
            ]);

            // Send order confirmation notification
            $this->sendOrderConfirmationNotification($order, $gatewayData);

            return [
                'success' => true,
                'order_id' => $order->id,
                'order_reference' => $order->order_reference,
                'status' => 'confirmed',
                'invoice_number' => $invoice->invoice_number ?? null,
            ];
        });
    }

    /**
     * Send low stock notification
     */
    protected function sendLowStockNotification($order, $line, $type)
    {
        try {
            $product = $line->product;
            $identifier = $type === 'variant' ? "Variant: {$line->variant->sku}" : "Product: {$product->product_code}";
            $stock = $type === 'variant' ? $line->variant->stock_quantity : $product->stock_quantity;
            $threshold = $type === 'variant' ? $line->variant->low_stock_threshold : $product->low_stock_threshold;

            $message = sprintf(
                "%s '%s' (Code: %s) is running low on stock. Current stock: %d. Threshold: %d",
                $type === 'variant' ? 'Variant' : 'Product',
                $product->name,
                $identifier,
                $stock,
                $threshold
            );

            \App\Models\AdminNotification::create([
                'admin_id' => 1,
                'type' => 'low_stock_alert',
                'title' => 'Low Stock Alert',
                'message' => $message,
                'reference_type' => $type === 'variant' ? 'variant' : 'product',
                'reference_id' => $type === 'variant' ? $line->variant_id : $line->product_id,
                'priority' => 'critical',
                'extra_data' => json_encode([
                    'product_code' => $product->product_code,
                    'product_name' => $product->name,
                    'current_stock' => $stock,
                    'threshold' => $threshold,
                    'category_id' => $product->category_id,
                    'variant_id' => $line->variant_id ?? null,
                    'variant_sku' => $line->variant->sku ?? null,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('Low stock notification sent', [
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'current_stock' => $stock
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send low stock notification: ' . $e->getMessage(), [
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id
            ]);
        }
    }

    /**
     * Send order confirmation notification
     */
    protected function sendOrderConfirmationNotification($order, $gatewayData)
    {
        try {
            $message = sprintf(
                "Order #%s has been confirmed. Total amount: %s. Customer: %s",
                $order->order_reference,
                number_format($order->total_payable, 2),
                $order->user->name ?? 'Guest'
            );

            \App\Models\AdminNotification::create([
                'admin_id' => 1,
                'type' => 'order_confirmed',
                'title' => 'New Order Confirmed',
                'message' => $message,
                'reference_type' => 'order',
                'reference_id' => $order->id,
                'priority' => 'high',
                'extra_data' => json_encode([
                    'order_reference' => $order->order_reference,
                    'total_payable' => $order->total_payable,
                    'customer_name' => $order->user->full_name ?? 'Guest',
                    'confirmed_at' => now()->toDateTimeString(),
                    'checkout_type' => $order->checkout_type ?? 'cart',
                    'has_variants' => $order->lines->whereNotNull('variant_id')->isNotEmpty(),
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Send notification to customer
            $user = $order->user;
            if ($user) {
                $templateData = [
                    'order_reference' => $order->order_reference,
                    'total_payable' => number_format($order->total_payable, 2),
                    'order_date' => $order->created_at->format('d M Y, h:i A'),
                    'customer_name' => $user->full_name ?? $user->name ?? 'Customer',
                    'order_id' => $order->id,
                    'confirmed_at' => now()->format('d M Y, h:i A'),
                    'payment_gateway' => $gatewayData['gateway'],
                    'transaction_id' => $gatewayData['transaction_id'],
                    'amount_paid' => number_format($order->total_payable, 2),
                ];

                $extraNotificationData = [
                    'order_id' => $order->id,
                    'order_reference' => $order->order_reference,
                    'total_payable' => $order->total_payable,
                    'confirmed_at' => now()->toDateTimeString(),
                    'checkout_type' => $order->checkout_type ?? 'cart',
                    'items' => $order->lines->map(function ($line) {
                        $product = $line->product;
                        $image = $product->images->first();

                        return [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_slug' => $product->slug ?? '',
                            'variant_id' => $line->variant_id,
                            'variant_sku' => $line->variant->sku ?? null,
                            'variant_attributes' => $line->variant->attributes ?? null,
                            'quantity' => $line->quantity,
                            'price' => $line->unit_price,
                            'total' => $line->line_total,
                            'image_url' => $image ? ($image->url ?? ($image->image_url ? Storage::url($image->image_url) : null)) : null,
                        ];
                    })->toArray()
                ];

                $this->notificationService->sendUserNotification(
                    $user,
                    'order_confirmed',
                    $templateData,
                    ['database', 'mail'],
                    $extraNotificationData
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to send order confirmation notification: ' . $e->getMessage(), [
                'order_id' => $order->id
            ]);
        }
    }

    /**
     * Build payload for Commission API (FR-CM-002)
     */
    protected function buildCommissionPayload(Order $order): array
    {
        $user = $order->user;

        $lines = $order->lines->map(function ($line) {
            return [
                'productIdentifier' => $line->product->product_code,
                'quantity' => $line->quantity,
                'unitPriceCharged' => $line->unit_price,
                'taxCategory' => $line->product->taxCategory?->name ?? 'GST-18',
            ];
        })->toArray();

        return [
            'eventId' => 'evt_' . Str::random(24),
            'action' => 'ORDER_PLACED',
            'orderReference' => $order->order_reference,
            'purchaserIdentifier' => $user->distributor_id ?? $user->id,
            'accountType' => $user->isDistributor() ? 'DISTRIBUTOR' : 'CUSTOMER',
            'eventTimestamp' => now()->toIso8601String(),
            'lines' => $lines,
            'totalOrderValue' => $order->total_payable + ($order->coin_redeemed_amount ?? 0),
        ];
    }

    // =========================================================================
    // NEW METHODS – Reversal & Clawback (FR-CM-009)
    // =========================================================================

    /**
     * Build reversal payload for Commission API (FR-CM-009)
     */
    protected function buildReversalPayload(Order $order, string $reason): array
    {
        $user = $order->user;

        $lines = $order->lines->map(function ($line) {
            return [
                'productIdentifier' => $line->product->product_code,
                'quantity' => $line->quantity,
                'unitPriceCharged' => $line->unit_price,
                'taxCategory' => $line->product->taxCategory?->name ?? 'GST-18',
            ];
        })->toArray();

        return [
            'eventId' => 'evt_' . Str::random(24),
            'action' => 'REVERSAL',
            'orderReference' => $order->order_reference,
            'reason' => $reason,
            'lines' => $lines,
            'reversedValue' => (float) $order->total_payable,
            'originalCv' => (float) ($order->commissionable_volume ?? 0),
            'purchaserIdentifier' => $user->distributor_id ?? (string) $user->id,
            'accountType' => $user->isDistributor() ? 'DISTRIBUTOR' : 'CUSTOMER',
            'eventTimestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * FR-CM-009: Cancel an order and queue reversal event
     */


    // public function cancelOrder(
    //     int $userId,
    //     string $orderReference,
    //     string $reason
    // ): array {
    //     $order = Order::where('order_reference', $orderReference)
    //         ->where('user_id', $userId)
    //         ->with('lines.product')
    //         ->firstOrFail();

    //     DB::transaction(function () use ($order, $reason) {

    //         // Update order status
    //         $order->update([
    //             'status' => 'cancelled',
    //             'cancelled_at' => now(),
    //         ]);

    //         // Cancel order lines and restore stock
    //         foreach ($order->lines as $line) {

    //             // Update order line delivery status
    //             $line->update([
    //                 'delivery_status' => 'cancelled',
    //                 'cancelled_at' => now()
    //             ]);

    //             // Restore stock
    //             $line->product->increment(
    //                 'stock_quantity',
    //                 $line->quantity
    //             );

    //             StockMovement::create([
    //                 'product_id' => $line->product_id,
    //                 'quantity' => $line->quantity,
    //                 'available_quantity_after' => $line->product->stock_quantity,
    //                 'reason' => $reason,
    //                 'order_id' => $order->id,
    //             ]);
    //         }

    //         // Create reversal event
    //         $payload = $this->buildReversalPayload($order, $reason);

    //         CommissionApiEvent::create([
    //             'event_type' => 'reversal',
    //             'order_id' => $order->id,
    //             'payload' => $payload,
    //             'status' => 'pending',
    //             'retry_count' => 0,
    //             'max_retries' => 5,
    //             'last_attempt' => null,
    //             'error_message' => null,
    //             'response_data' => null,
    //         ]);

    //         // Process refund if order was paid
    //         if ($order->amount_paid > 0) {
    //             $this->processRefund($order);
    //         }

    //         Log::info('Order cancelled and reversal queued', [
    //             'order' => $order->order_reference,
    //             'reason' => $reason,
    //         ]);
    //     });

    //     return [
    //         'order_reference' => $order->order_reference,
    //         'status' => $order->status,
    //         'reason' => $reason,
    //     ];
    // }

    public function cancelOrder(
        int $userId,
        string $orderReference,
        string $reason
    ): array {
        $order = Order::where('order_reference', $orderReference)
            ->where('user_id', $userId)
            ->with('lines.product')
            ->firstOrFail();

        DB::transaction(function () use ($order, $reason) {

            // Update order status
            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            // Cancel order lines and restore stock
            // Cancel order lines and restore stock
            foreach ($order->lines as $line) {

                // Update order line delivery status
                $line->update([
                    'delivery_status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

                // Restore variant stock if order line has a variant
                if ($line->variant_id && $line->variant) {
                    $line->variant->increment(
                        'stock_quantity',
                        $line->quantity
                    );
                } else {
                    // Restore product stock if no variant is associated
                    $line->product->increment(
                        'stock_quantity',
                        $line->quantity
                    );
                }

                // Restore product stock as well if your system maintains
                // both product-level and variant-level stock
                if ($line->variant_id && $line->variant) {
                    $line->product->increment(
                        'stock_quantity',
                        $line->quantity
                    );
                }

                StockMovement::create([
                    'product_id' => $line->product_id,
                    'quantity' => $line->quantity,
                    'available_quantity_after' => $line->product->stock_quantity,
                    'reason' => $reason,
                    'order_id' => $order->id,
                ]);
            }


            // Create reversal event
            $payload = $this->buildReversalPayload($order, $reason);

            CommissionApiEvent::create([
                'event_type' => 'reversal',
                'order_id' => $order->id,
                'payload' => $payload,
                'status' => 'pending',
                'retry_count' => 0,
                'max_retries' => 5,
                'last_attempt' => null,
                'error_message' => null,
                'response_data' => null,
            ]);

            // Process refund if order was paid
            if ($order->amount_paid > 0) {
                $this->processRefund($order);
            }

            Log::info('Order cancelled and reversal queued', [
                'order' => $order->order_reference,
                'reason' => $reason,
            ]);
        });

        return [
            'order_reference' => $order->order_reference,
            'status' => $order->status,
            'reason' => $reason,
        ];
    }

    /**
     * Process refund for cancelled/returned order (FR-CO-012)
     */
    protected function processRefund(Order $order): void
    {
        $gateway = $order->payment_gateway ?? 'razorpay';
        $refundAmount = $order->amount_paid;

        if ($gateway === 'razorpay') {
            // Call Razorpay refund API
            $this->razorpayService->refundPayment($order->gateway_transaction_id, $refundAmount);
        }

        $order->update([
            'refund_status' => 'processed',
            'refunded_at' => now(),
        ]);

        Log::info('Refund processed for order', ['order' => $order->order_reference, 'amount' => $refundAmount]);
    }

    // End of new reversal methods
    // =========================================================================

    /**
     * Get summary data from request or cart
     */
    protected function getSummaryData(array $data, int $userId, int $addressId): array
    {
        if (isset($data['items']) && isset($data['subtotal'])) {
            return [
                'subtotal' => $data['subtotal'],
                'total_tax' => $data['total_tax'] ?? 0,
                'grand_total' => $data['grand_total'] + ($data['coin_redeemed'] ?? 0),
                'items' => $data['items'],
                'tax_breakdown' => $data['tax_breakdown'] ?? [],
                'coupon_code' => $data['coupon_code'] ?? null,
                'coupon_discount' => $data['coupon_discount'] ?? 0,
                'shipping_charge' => $data['shipping_charge'] ?? 0,
                'shipping_method_id' => $data['shipping_method_id'] ?? null,
                'coin_redeemed' => $data['coin_redeemed'] ?? 0,
            ];
        }

        return $this->calculateSummary(
            $userId,
            $addressId,
            $data['coupon_code'] ?? null,
            $data['shipping_method_id'] ?? null,
            $data['coins'] ?? null
        );
    }

    /**
     * Helper: Format address
     */
    private function formatAddress(Address $address): string
    {
        return implode(', ', array_filter([
            $address->address_line1,
            $address->address_line2,
            $address->city,
            $address->state,
            $address->postal_code,
            $address->country,
        ]));
    }

    /**
     * Get coin balance from Commission API (mock)
     */
    protected function getCoinBalance(int $userId): int
    {
        return 500;
    }

    /**
     * Get current cart total
     */
    protected function getCurrentCartTotal(int $userId): float
    {
        $cart = Cart::with('items.product')->where('user_id', $userId)->first();
        if (!$cart) {
            return 0;
        }
        $total = 0;
        foreach ($cart->items as $item) {
            $price = $item->product->retail_price;
            $total += $price * $item->quantity;
        }
        return $total;
    }

    /**
     * Authorize coin redemption via Commission API (mock)
     */
    protected function authorizeCoinRedemption(int $userId, int $coins, float $amount): array
    {
        return [
            'id' => 'AUTH-' . strtoupper(uniqid()),
            'status' => 'authorized',
            'coins' => $coins,
            'amount' => $amount,
        ];
    }
}
