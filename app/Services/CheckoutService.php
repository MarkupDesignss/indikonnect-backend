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
use App\Models\Refund;
use Illuminate\Support\Facades\Auth;
use App\Traits\AuditLogTrait;
use App\Services\ReturnService;
use App\Services\ProformaInvoiceService;

class CheckoutService
{
    use AuditLogTrait;
    protected GSTCalculator $gstCalculator;
    protected InvoiceService $invoiceService;
    protected RazorpayService $razorpayService;
    protected NotificationService $notificationService;
    protected $pdfInvoiceService;
    protected ReturnService $returnService;
    protected ProformaInvoiceService $proformaInvoiceService;


    public function __construct(
        GSTCalculator $gstCalculator,
        InvoiceService $invoiceService,
        RazorpayService $razorpayService,
        PdfInvoiceService $pdfInvoiceService,
        NotificationService $notificationService,
        ReturnService $returnService,
        ProformaInvoiceService $proformaInvoiceService
    ) {
        $this->gstCalculator = $gstCalculator;
        $this->invoiceService = $invoiceService;
        $this->razorpayService = $razorpayService;
        $this->pdfInvoiceService = $pdfInvoiceService;
        $this->notificationService = $notificationService;
        $this->returnService = $returnService;
        $this->proformaInvoiceService = $proformaInvoiceService;
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
    //     ?int $buyNowVariantId = null,
    //     ?int $buyNowQuantity = null
    // ): array {
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
    // |--------------------------------------------------------------------------
    // | Cart / Buy Now
    // |--------------------------------------------------------------------------
    // */

    //     $isBuyNow = $buyNowProductId !== null || $buyNowVariantId !== null;

    //     if ($isBuyNow) {
    //         if (!$buyNowQuantity || $buyNowQuantity < 1) {
    //             throw new Exception('Invalid product quantity');
    //         }

    //         $product = null;
    //         $variant = null;
    //         $variantAttributes = null;
    //         $variantSku = null;
    //         $variantId = null;

    //         // If variant_id is provided
    //         if ($buyNowVariantId) {
    //             $variant = ProductVariant::with(['product.taxCategory', 'product.images', 'product.primaryImage'])
    //                 ->findOrFail($buyNowVariantId);

    //             $product = $variant->product;
    //             $variantId = $variant->id;
    //             $variantSku = $variant->sku;
    //             $variantAttributes = $variant->attributes;
    //         } else {
    //             // If product_id is provided
    //             $product = Product::with(['taxCategory', 'images', 'primaryImage'])
    //                 ->findOrFail($buyNowProductId);
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | Create temporary item object with variant support
    //     |--------------------------------------------------------------------------
    //     */

    //         $item = new \stdClass();
    //         $item->product_id = $product->id;
    //         $item->variant_id = $variantId;
    //         $item->variant_sku = $variantSku;
    //         $item->variant_attributes = $variantAttributes;
    //         $item->quantity = $buyNowQuantity;
    //         $item->product = $product;
    //         $item->variant = $variant;

    //         $cartItems = collect([$item]);
    //     } else {
    //         /*
    //     |--------------------------------------------------------------------------
    //     | Existing Cart Flow with Variant Support
    //     |--------------------------------------------------------------------------
    //     */

    //         $cart = Cart::with([
    //             'items.product.taxCategory',
    //             'items.product.images',
    //             'items.product.primaryImage',
    //             'items.variant',
    //         ])
    //             ->where('user_id', $userId)
    //             ->firstOrFail();

    //         if ($cart->items->isEmpty()) {
    //             throw new Exception('Cart is empty');
    //         }

    //         $cartItems = $cart->items;
    //     }

    //     /*
    // |--------------------------------------------------------------------------
    // | Calculate Subtotal
    // |--------------------------------------------------------------------------
    // */

    //     $subtotal = 0;
    //     $productDetails = [];

    //     foreach ($cartItems as $item) {
    //         // Check if item has variant
    //         $hasVariant = isset($item->variant) && $item->variant !== null;

    //         // Determine unit price based on variant or product
    //         if ($hasVariant) {
    //             // Use variant pricing
    //             $unitPrice = $user->isDistributor()
    //                 ? ($item->variant->distributor_price ?? $item->variant->retail_price)
    //                 : $item->variant->retail_price;

    //             $taxRate = $item->product->taxCategory?->rate ?? 0;

    //             // Get variant images
    //             $variantImages = [];
    //             if ($item->variant->images) {
    //                 foreach ($item->variant->images as $image) {
    //                     $variantImages[] = [
    //                         'id' => $image->id,
    //                         'image' => $image->image,
    //                         'image_url' => asset('storage/' . $image->image),
    //                         'is_primary' => $image->is_primary,
    //                         'sort_order' => $image->sort_order,
    //                     ];
    //                 }
    //             }

    //             // Get variant primary image
    //             $primaryImage = $item->variant->images->where('is_primary', true)->first()
    //                 ?? $item->variant->images->first();

    //             $primaryImageUrl = $primaryImage ? asset('storage/' . $primaryImage->image) : null;
    //         } else {
    //             // Use product pricing
    //             $unitPrice = $user->isDistributor()
    //                 ? ($item->product->distributor_price ?? $item->product->retail_price)
    //                 : $item->product->retail_price;

    //             $taxRate = $item->product->taxCategory?->rate ?? 0;
    //             $variantImages = [];

    //             // Get product images
    //             $productImages = [];
    //             if ($item->product->images) {
    //                 foreach ($item->product->images as $image) {
    //                     $productImages[] = [
    //                         'id' => $image->id,
    //                         'image' => $image->image,
    //                         'image_url' => asset('storage/' . $image->image),
    //                         'is_primary' => $image->is_primary,
    //                         'sort_order' => $image->sort_order,
    //                     ];
    //                 }
    //             }

    //             $primaryImageUrl = $item->product->primaryImage
    //                 ? asset('storage/' . $item->product->primaryImage->image)
    //                 : null;
    //         }

    //         $lineTotal = $unitPrice * $item->quantity;
    //         $subtotal += $lineTotal;

    //         $productDetails[] = [
    //             'item' => $item,
    //             'has_variant' => $hasVariant,
    //             'variant_id' => $hasVariant ? $item->variant->id : null,
    //             'variant_sku' => $hasVariant ? $item->variant->sku : null,
    //             'variant_attributes' => $hasVariant ? $item->variant->attributes : null,
    //             'unitPrice' => $unitPrice,
    //             'lineTotal' => $lineTotal,
    //             'taxRate' => $taxRate,
    //             'taxCategoryName' => $item->product->taxCategory?->name ?? 'No Tax',
    //             'images' => $hasVariant ? $variantImages : $productImages,
    //             'primary_image' => $hasVariant ? $primaryImageUrl : $primaryImageUrl,
    //         ];
    //     }

    //     /*
    // |--------------------------------------------------------------------------
    // | Coupon
    // |--------------------------------------------------------------------------
    // */

    //     $couponDiscount = 0;
    //     $couponData = null;

    //     if ($couponCode) {
    //         $coupon = Coupon::where('code', strtoupper($couponCode))->first();
    //         $validationResult = $this->validateCouponForUser($coupon, $userId);

    //         if ($validationResult !== true) {
    //             throw new Exception($validationResult);
    //         }

    //         if ($coupon && $coupon->isValid() && $this->validateCouponForUser($coupon, $userId)) {
    //             $couponDiscount = $this->calculateCouponDiscount($coupon, $subtotal);
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
    // |--------------------------------------------------------------------------
    // | Subtotal After Discount
    // |--------------------------------------------------------------------------
    // */

    //     $subtotalAfterDiscount = $subtotal - $couponDiscount;

    //     /*
    // |--------------------------------------------------------------------------
    // | Shipping
    // |--------------------------------------------------------------------------
    // */

    //     $shippingCost = 0;
    //     $shippingData = null;

    //     if ($shippingMethodId) {
    //         $shippingMethod = ShippingMethod::find($shippingMethodId);

    //         if ($shippingMethod && $shippingMethod->is_active) {
    //             if ($shippingMethod->min_order_amount && $subtotalAfterDiscount < $shippingMethod->min_order_amount) {
    //                 throw new Exception("Minimum order amount for this shipping method is ₹" . $shippingMethod->min_order_amount);
    //             }

    //             if ($shippingMethod->max_order_amount && $subtotalAfterDiscount > $shippingMethod->max_order_amount) {
    //                 throw new Exception("Order amount exceeds maximum limit for this shipping method");
    //             }

    //             $shippingCost = $this->calculateShippingCost($shippingMethod, $subtotalAfterDiscount);

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
    // |--------------------------------------------------------------------------
    // | Tax Variables
    // |--------------------------------------------------------------------------
    // */

    //     $productTaxTotal = 0;
    //     $productGstTotal = 0;
    //     $productOtherTaxTotal = 0;

    //     $itemsWithTax = [];
    //     $taxBreakdown = [];
    //     $taxByCategory = [];
    //     $productTaxBreakdown = [];

    //     // Get supplier state from config
    //     $supplierState = config('app.supplier_state', 'Punjab');
    //     $deliveryState = $address ? $address->state : null;

    //     foreach ($productDetails as $index => $product) {
    //         $proportion = $subtotal > 0 ? $product['lineTotal'] / $subtotal : 0;
    //         $discountedLineTotal = $product['lineTotal'] - ($couponDiscount * $proportion);

    //         $taxRate = $product['taxRate'];
    //         $taxAmount = ($discountedLineTotal * $taxRate) / 100;
    //         $productTaxTotal += $taxAmount;

    //         // GST / Other Tax
    //         if ($taxRate == 18) {
    //             $productGstTotal += $taxAmount;
    //         } else {
    //             $productOtherTaxTotal += $taxAmount;
    //         }

    //         // Product Information
    //         $productName = $product['item']->product->name;
    //         $productKey = 'product_' . ($index + 1) . '_' . str_replace(' ', '_', strtolower($productName));

    //         // Product Tax Breakdown
    //         $productTaxBreakdown[$productKey] = [
    //             'product_id' => $product['item']->product_id,
    //             'product_name' => $productName,
    //             'product_code' => $product['item']->product->product_code,
    //             'variant_id' => $product['variant_id'] ?? null,
    //             'variant_sku' => $product['variant_sku'] ?? null,
    //             'variant_attributes' => $product['variant_attributes'] ?? null,
    //             'quantity' => $product['item']->quantity,
    //             'unit_price' => round($product['unitPrice'], 2),
    //             'tax_category' => $product['taxCategoryName'],
    //             'tax_rate' => (string) $taxRate . '%',
    //             'taxable_value' => round($discountedLineTotal, 2),
    //             'tax_amount' => round($taxAmount, 2),
    //             'line_total_after_tax' => round($discountedLineTotal + $taxAmount, 2),
    //             'images' => $product['images'],
    //             'primary_image' => $product['primary_image'],
    //         ];

    //         // CGST / SGST / IGST
    //         $cgst = 0;
    //         $sgst = 0;
    //         $igst = 0;

    //         if ($deliveryState) {
    //             if (strtolower($deliveryState) === strtolower($supplierState)) {
    //                 $cgst = $taxAmount / 2;
    //                 $sgst = $taxAmount / 2;
    //             } else {
    //                 $igst = $taxAmount;
    //             }
    //         } else {
    //             $igst = $taxAmount;
    //         }

    //         // Items With Tax
    //         $itemsWithTax[] = [
    //             'product_id' => $product['item']->product_id,
    //             'product_name' => $product['item']->product->name,
    //             'product_code' => $product['item']->product->product_code,
    //             'variant_id' => $product['variant_id'] ?? null,
    //             'variant_sku' => $product['variant_sku'] ?? null,
    //             'variant_attributes' => $product['variant_attributes'] ?? null,
    //             'quantity' => $product['item']->quantity,
    //             'unit_price' => round($product['unitPrice'], 2),
    //             'tax_category' => $product['taxCategoryName'],
    //             'tax_rate' => (string) $taxRate . '%',
    //             'taxable_value' => round($discountedLineTotal, 2),
    //             'cgst' => round($cgst, 2),
    //             'sgst' => round($sgst, 2),
    //             'igst' => round($igst, 2),
    //             'total_tax' => round($taxAmount, 2),
    //             'line_total' => round($discountedLineTotal + $taxAmount, 2),
    //             'images' => $product['images'],
    //             'primary_image' => $product['primary_image'],
    //         ];

    //         // Tax Breakdown
    //         $taxBreakdown[] = [
    //             'product_name' => $product['item']->product->name,
    //             'variant_sku' => $product['variant_sku'] ?? null,
    //             'variant_attributes' => $product['variant_attributes'] ?? null,
    //             'tax_category' => $product['taxCategoryName'],
    //             'rate' => (string) $taxRate . '%',
    //             'cgst' => round($cgst, 2),
    //             'sgst' => round($sgst, 2),
    //             'igst' => round($igst, 2),
    //         ];

    //         // Tax By Category
    //         $categoryKey = $product['taxCategoryName'] . '_' . $taxRate;

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

    //         $taxByCategory[$categoryKey]['taxable_amount'] += $discountedLineTotal;
    //         $taxByCategory[$categoryKey]['tax_amount'] += $taxAmount;
    //         $taxByCategory[$categoryKey]['cgst'] += $cgst;
    //         $taxByCategory[$categoryKey]['sgst'] += $sgst;
    //         $taxByCategory[$categoryKey]['igst'] += $igst;
    //     }

    //     /*
    // |--------------------------------------------------------------------------
    // | Total Tax
    // |--------------------------------------------------------------------------
    // */

    //     $totalTax = $productTaxTotal;

    //     /*
    // |--------------------------------------------------------------------------
    // | Subtotal + Tax + Shipping
    // |--------------------------------------------------------------------------
    // */

    //     $subtotalAfterDiscountAndTax = $subtotalAfterDiscount + $totalTax + $shippingCost;

    //     /*
    // |--------------------------------------------------------------------------
    // | Coins
    // |--------------------------------------------------------------------------
    // */

    //     $coinRedemptionData = null;
    //     $coinsUsed = 0;
    //     $amountRedeemed = 0;
    //     $coinBalance = 0;
    //     $maxCoinsRedeemable = 0;

    //     if ($user->isDistributor()) {
    //         $coinBalance = $this->getCoinBalance($userId);
    //         $maxCoinsRedeemable = min($coinBalance, floor($subtotalAfterDiscountAndTax / 10));

    //         if ($coinsToRedeem != null && $coinsToRedeem > 0) {
    //             if ($coinsToRedeem > $coinBalance) {
    //                 throw new Exception('Insufficient coin balance. You have ' . $coinBalance . ' coins.');
    //             }

    //             if ($coinsToRedeem > $maxCoinsRedeemable) {
    //                 throw new Exception('Cannot redeem more than ' . $maxCoinsRedeemable . ' coins for this order.');
    //             }

    //             $coinsUsed = $coinsToRedeem;
    //             $amountRedeemed = $coinsToRedeem * 10;

    //             $coinRedemptionData = [
    //                 'coins_used' => $coinsUsed,
    //                 'amount_redeemed' => $amountRedeemed,
    //                 'remaining_coins' => $coinBalance - $coinsUsed,
    //             ];
    //         }
    //     }

    //     /*
    // |--------------------------------------------------------------------------
    // | Grand Total
    // |--------------------------------------------------------------------------
    // */

    //     $grandTotal = round($subtotalAfterDiscountAndTax - $amountRedeemed, 2);

    //     /*
    // |--------------------------------------------------------------------------
    // | Final Response
    // |--------------------------------------------------------------------------
    // */

    //     return [
    //         'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',

    //         'subtotal' => round($subtotal, 2),
    //         'coupon_discount' => round($couponDiscount, 2),
    //         'coupon' => $couponData,
    //         'subtotal_after_discount' => round($subtotalAfterDiscount, 2),

    //         'product_tax_breakdown' => $productTaxBreakdown,
    //         'tax_summary' => [
    //             'gst_18_percent' => round($productGstTotal, 2),
    //             'other_tax' => round($productOtherTaxTotal, 2),
    //             'total_product_tax' => round($productTaxTotal, 2),
    //         ],
    //         'total_tax' => round($totalTax, 2),
    //         'tax_by_category' => array_values($taxByCategory),

    //         'shipping_cost' => round($shippingCost, 2),
    //         'shipping_method' => $shippingData,

    //         'subtotal_after_discount_and_tax' => round($subtotalAfterDiscountAndTax, 2),

    //         'coin_balance' => $coinBalance,
    //         'max_coins_redeemable' => $maxCoinsRedeemable,
    //         'coins_used' => $coinsUsed,
    //         'amount_redeemed' => $amountRedeemed,
    //         'coin_redemption' => $coinRedemptionData,

    //         'grand_total' => $grandTotal,

    //         'tax_breakdown' => $taxBreakdown,

    //         'delivery_address' => $address ? [
    //             'id' => $address->id,
    //             'full_address' => $this->formatAddress($address),
    //             'state' => $address->state,
    //         ] : null,

    //         'summary' => [
    //             'subtotal' => round($subtotal, 2),
    //             'less_coupon' => round($couponDiscount, 2),
    //             'net_subtotal' => round($subtotalAfterDiscount, 2),
    //             'product_gst_18' => round($productGstTotal, 2),
    //             'product_other_tax' => round($productOtherTaxTotal, 2),
    //             'total_tax' => round($totalTax, 2),
    //             'plus_shipping' => round($shippingCost, 2),
    //             'less_coins' => round($amountRedeemed, 2),
    //             'grand_total' => $grandTotal,
    //             'coupon_code' => $couponData['code'] ?? null,
    //             'tax_breakdown' => array_map(function ($item) {
    //                 return [
    //                     'product_name' => $item['product_name'],
    //                     'variant_sku' => $item['variant_sku'] ?? null,
    //                     'variant_attributes' => $item['variant_attributes'] ?? null,
    //                     'tax_category' => $item['tax_category'],
    //                     'rate' => (float) str_replace('%', '', $item['rate']),
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

            // Per-product shipping charge
            $productShippingCharge = $item->product->shipping_charge ?? 0;
            $lineShippingCharge = $productShippingCharge * $item->quantity;

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
                'product_shipping_charge' => $productShippingCharge,
                'line_shipping_charge' => $lineShippingCharge,
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
            $validationResult = $this->validateCouponForUser($coupon, $userId);

            if ($validationResult !== true) {
                throw new Exception($validationResult);
            }

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
    | Shipping (Based on Product's Shipping Charge)
    |--------------------------------------------------------------------------
    */

        $shippingCost = 0;
        $shippingData = null;
        $shippingBreakdown = [];

        foreach ($productDetails as $product) {
            $unitShipping = $product['product_shipping_charge'];
            $qty = $product['item']->quantity;
            $lineShipping = $product['line_shipping_charge'];
            $shippingCost += $lineShipping;

            $shippingBreakdown[] = [
                'product_id' => $product['item']->product_id,
                'product_name' => $product['item']->product->name,
                'variant_id' => $product['variant_id'] ?? null,
                'variant_sku' => $product['variant_sku'] ?? null,
                'quantity' => $qty,
                'shipping_charge_per_unit' => round($unitShipping, 2),
                'total_shipping_charge' => round($lineShipping, 2),
            ];
        }

        if ($shippingCost > 0) {
            $shippingData = [
                'name' => 'Product Shipping',
                'cost' => round($shippingCost, 2),
            ];
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

            // Product Tax Breakdown (with shipping)
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

                // Shipping info per product
                'shipping_charge_per_unit' => round($product['product_shipping_charge'], 2),
                'total_shipping_charge' => round($product['line_shipping_charge'], 2),

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

            // Items With Tax (with shipping)
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

                // Shipping info per product
                'shipping_charge_per_unit' => round($product['product_shipping_charge'], 2),
                'total_shipping_charge' => round($product['line_shipping_charge'], 2),

                'images' => $product['images'],
                'primary_image' => $product['primary_image'],
            ];

            // Tax Breakdown (with shipping)
            $taxBreakdown[] = [
                'product_name' => $product['item']->product->name,
                'variant_sku' => $product['variant_sku'] ?? null,
                'variant_attributes' => $product['variant_attributes'] ?? null,
                'tax_category' => $product['taxCategoryName'],
                'rate' => (string) $taxRate . '%',
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
                'shipping_charge_per_unit' => round($product['product_shipping_charge'], 2),
                'total_shipping_charge' => round($product['line_shipping_charge'], 2),
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

            // Total shipping (single place)
            'shipping_cost' => round($shippingCost, 2),
            'shipping_method' => $shippingData,

            // Per-product shipping breakdown
            'shipping_breakdown' => $shippingBreakdown,

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
                        'shipping_charge_per_unit' => $item['shipping_charge_per_unit'] ?? 0,
                        'total_shipping_charge' => $item['total_shipping_charge'] ?? 0,
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
    // private function validateCouponForUser(Coupon $coupon, int $userId): bool
    // {
    //     if ($coupon->max_uses && $coupon->used_count >= $coupon->max_uses) {
    //         throw new Exception('This coupon has reached its usage limit');
    //     }

    //     $userUsage = CouponUsage::where('coupon_id', $coupon->id)
    //         ->where('user_id', $userId)
    //         ->count();

    //     if ($userUsage > 0) {
    //         throw new Exception('You have already used this coupon');
    //     }

    //     return true;
    // }

    private function validateCouponForUser($coupon, $userId): string|bool
    {
        if (!$coupon->is_active) {
            return 'This coupon is currently inactive.';
        }

        if ($coupon->expires_at && $coupon->expires_at < now()) {
            return 'This coupon has expired.';
        }

        if ($coupon->max_uses && $coupon->used_count >= $coupon->max_uses) {
            return 'This coupon has reached its maximum usage limit.';
        }

        $userUsageCount = CouponUsage::where('coupon_id', $coupon->id)
            ->where('user_id', $userId)
            ->count();

        if ($coupon->max_uses_per_user) {
            if ($userUsageCount >= $coupon->max_uses_per_user) {
                return 'You have already used this coupon. (Maximum ' . $coupon->max_uses_per_user . ' time(s) per user)';
            }
        } else {
            if ($userUsageCount > 0) {
                return 'You have already used this coupon. Each coupon can only be used once per user.';
            }
        }

        if ($coupon->min_order && $coupon->min_order > 0) {
            // You'll need to pass the subtotal here
            // For now, we'll skip this check in this method
            // Better to handle it in the calling method
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

    //     // Get supplier state from config
    //     $supplierState = strtolower(config('app.supplier_state', 'Maharashtra'));
    //     $deliveryState = $address ? strtolower($address->state) : null;

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

    //             $variant = null;
    //             if (isset($itemData['variant_id']) && $itemData['variant_id']) {
    //                 $variant = ProductVariant::find($itemData['variant_id']);
    //                 if (!$variant) {
    //                     throw new Exception("Variant not found: {$itemData['variant_id']}");
    //                 }
    //                 if ($variant->stock_quantity < $itemData['quantity']) {
    //                     throw new Exception("Insufficient stock for variant: {$variant->sku}");
    //                 }
    //             } else {
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

    //     // Get coupon discount from summary
    //     $couponDiscount = $summary['coupon_discount'] ?? 0;
    //     $couponCode = $summary['coupon_code'] ?? null;

    //     return DB::transaction(function () use (
    //         $user,
    //         $address,
    //         $coinRedemption,
    //         $coinsUsed,
    //         $coinRedeemedAmount,
    //         $data,
    //         $summary,
    //         $cartItems,
    //         $isBuyNow,
    //         $supplierState,
    //         $deliveryState,
    //         $couponDiscount,
    //         $couponCode
    //     ) {

    //         // First pass: Calculate subtotal
    //         $itemsWithPrices = [];
    //         $totalSubtotal = 0;

    //         foreach ($cartItems as $item) {
    //             $hasVariant = isset($item->variant) && $item->variant !== null;

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

    //         // Second pass: Apply coupon proportionally and calculate tax with split
    //         $itemsWithTaxSplit = [];

    //         foreach ($itemsWithPrices as $itemData) {
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

    //             // =============================================
    //             // TAX SPLIT LOGIC - PERCENTAGE BASED
    //             // =============================================
    //             $cgstRate = 0;
    //             $sgstRate = 0;
    //             $igstRate = 0;
    //             $cgstAmount = 0;
    //             $sgstAmount = 0;
    //             $igstAmount = 0;

    //             $isPunjab = $deliveryState && $deliveryState === 'punjab';
    //             $isInterState = $deliveryState && $deliveryState !== $supplierState;

    //             if ($deliveryState) {
    //                 if ($isPunjab) {
    //                     $cgstRate = $taxRate / 2;
    //                     $sgstRate = $taxRate / 2;
    //                     $igstRate = 0;

    //                     $cgstAmount = ($discountedLineTotal * $cgstRate) / 100;
    //                     $sgstAmount = ($discountedLineTotal * $sgstRate) / 100;
    //                     $igstAmount = 0;
    //                 } elseif ($isInterState) {
    //                     $igstRate = $taxRate;
    //                     $cgstRate = 0;
    //                     $sgstRate = 0;

    //                     $igstAmount = $taxAmount;
    //                     $cgstAmount = 0;
    //                     $sgstAmount = 0;
    //                 } else {
    //                     $cgstRate = $taxRate / 2;
    //                     $sgstRate = $taxRate / 2;
    //                     $igstRate = 0;

    //                     $cgstAmount = ($discountedLineTotal * $cgstRate) / 100;
    //                     $sgstAmount = ($discountedLineTotal * $sgstRate) / 100;
    //                     $igstAmount = 0;
    //                 }
    //             } else {
    //                 $igstRate = $taxRate;
    //                 $igstAmount = $taxAmount;
    //                 $cgstRate = 0;
    //                 $sgstRate = 0;
    //                 $cgstAmount = 0;
    //                 $sgstAmount = 0;
    //             }

    //             // Product shipping charge
    //             $productShippingCharge = $item->product->shipping_charge ?? 0;
    //             $lineShippingCharge = $productShippingCharge * $item->quantity;

    //             $finalLineTotal = $discountedLineTotal + $taxAmount;

    //             $variantSku = $hasVariant ? $item->variant->sku : null;
    //             $variantAttributes = $hasVariant ? $item->variant->attributes : null;

    //             $itemsWithTaxSplit[] = [
    //                 'product_id' => $item->product_id,
    //                 'product' => $item->product,
    //                 'variant_id' => $item->variant_id ?? null,
    //                 'variant' => $item->variant ?? null,
    //                 'quantity' => $item->quantity,
    //                 'unit_price' => $unitPrice,
    //                 'line_total' => $lineTotal,
    //                 'discounted_line_total' => $discountedLineTotal,
    //                 'item_discount' => $itemDiscount,
    //                 'tax_rate' => $taxRate,
    //                 'cgst_rate' => $cgstRate,
    //                 'sgst_rate' => $sgstRate,
    //                 'igst_rate' => $igstRate,
    //                 'cgst_amount' => $cgstAmount,
    //                 'sgst_amount' => $sgstAmount,
    //                 'igst_amount' => $igstAmount,
    //                 'tax_amount' => $taxAmount,
    //                 'final_line_total' => $finalLineTotal,
    //                 'product_shipping_charge' => $productShippingCharge,
    //                 'line_shipping_charge' => $lineShippingCharge,
    //                 'variant_sku' => $variantSku,
    //                 'variant_attributes' => $variantAttributes,
    //             ];
    //         }

    //         // =============================================
    //         // CREATE SEPARATE ORDER PER ITEM
    //         // =============================================
    //         $orders = [];
    //         $totalOrderCount = count($itemsWithTaxSplit);

    //         // Distribute coins proportionally across orders
    //         $totalCoinsToDistribute = $coinsUsed;
    //         $totalAmountToDistribute = $coinRedeemedAmount;
    //         $distributedCoins = 0;
    //         $distributedAmount = 0;

    //         foreach ($itemsWithTaxSplit as $index => $itemData) {
    //             $item = $itemData['product'];
    //             $lineShippingCharge = $itemData['line_shipping_charge'];
    //             $lineSubtotal = $itemData['line_total'];
    //             $lineDiscount = $itemData['item_discount'];
    //             $discountedLineTotal = $itemData['discounted_line_total'];
    //             $taxAmount = $itemData['tax_amount'];
    //             $cgstAmount = $itemData['cgst_amount'];
    //             $sgstAmount = $itemData['sgst_amount'];
    //             $igstAmount = $itemData['igst_amount'];

    //             // Distribute coins proportionally (last item gets the remainder)
    //             if ($index === $totalOrderCount - 1) {
    //                 $orderCoinsUsed = $totalCoinsToDistribute - $distributedCoins;
    //                 $orderAmountRedeemed = $totalAmountToDistribute - $distributedAmount;
    //             } else {
    //                 $proportion = $totalSubtotal > 0 ? $lineSubtotal / $totalSubtotal : 0;
    //                 $orderCoinsUsed = (int) round($totalCoinsToDistribute * $proportion);
    //                 $orderAmountRedeemed = round($totalAmountToDistribute * $proportion, 2);
    //                 $distributedCoins += $orderCoinsUsed;
    //                 $distributedAmount += $orderAmountRedeemed;
    //             }

    //             // Per-order grand total
    //             $orderGrandTotal = round(
    //                 $discountedLineTotal + $taxAmount + $lineShippingCharge - $orderAmountRedeemed,
    //                 2
    //             );

    //             // Per-order tax breakdown summary
    //             $orderTaxBreakdownSummary = [
    //                 'items' => [
    //                     [
    //                         'product_id' => $itemData['product_id'],
    //                         'product_name' => $item->name,
    //                         'product_code' => $item->product_code ?? null,
    //                         'variant_id' => $itemData['variant_id'],
    //                         'variant_sku' => $itemData['variant_sku'],
    //                         'variant_attributes' => $itemData['variant_attributes'],
    //                         'quantity' => $itemData['quantity'],
    //                         'unit_price' => $itemData['unit_price'],
    //                         'line_total_before_discount' => $itemData['line_total'],
    //                         'line_total_after_discount' => round($discountedLineTotal, 2),
    //                         'tax_category' => $item->taxCategory?->name ?? 'Default',
    //                         'tax_rate' => $itemData['tax_rate'],
    //                         'cgst_rate' => round($itemData['cgst_rate'], 2),
    //                         'sgst_rate' => round($itemData['sgst_rate'], 2),
    //                         'igst_rate' => round($itemData['igst_rate'], 2),
    //                         'cgst_amount' => round($cgstAmount, 2),
    //                         'sgst_amount' => round($sgstAmount, 2),
    //                         'igst_amount' => round($igstAmount, 2),
    //                         'tax_amount' => round($taxAmount, 2),
    //                         'line_total_after_tax' => round($discountedLineTotal + $taxAmount, 2),
    //                         'shipping_charge' => round($lineShippingCharge, 2),
    //                     ]
    //                 ],
    //                 'summary' => [
    //                     'subtotal' => round($lineSubtotal, 2),
    //                     'coupon_discount' => round($lineDiscount, 2),
    //                     'subtotal_after_discount' => round($discountedLineTotal, 2),
    //                     'total_tax' => round($taxAmount, 2),
    //                     'total_cgst' => round($cgstAmount, 2),
    //                     'total_sgst' => round($sgstAmount, 2),
    //                     'total_igst' => round($igstAmount, 2),
    //                     'shipping_charge' => round($lineShippingCharge, 2),
    //                     'coin_redeemed' => $orderCoinsUsed,
    //                     'coin_redeemed_amount' => round($orderAmountRedeemed, 2),
    //                     'grand_total' => $orderGrandTotal,
    //                 ],
    //                 'tax_by_category' => $this->calculateTaxByCategory([
    //                     [
    //                         'product_name' => $item->name,
    //                         'tax_category' => $item->taxCategory?->name ?? 'Default',
    //                         'tax_rate' => $itemData['tax_rate'],
    //                         'line_total_after_discount' => $discountedLineTotal,
    //                         'tax_amount' => $taxAmount,
    //                         'cgst_amount' => $cgstAmount,
    //                         'sgst_amount' => $sgstAmount,
    //                         'igst_amount' => $igstAmount,
    //                     ]
    //                 ]),
    //                 'delivery_state' => $deliveryState,
    //                 'supplier_state' => $supplierState,
    //             ];

    //             // Create one Order per item
    //             $order = Order::create([
    //                 'order_reference' => 'ORD-' . strtoupper(uniqid()),
    //                 'user_id' => $user->id,
    //                 'billing_address_id' => $data['address_id'],
    //                 'delivery_address_id' => $data['address_id'],
    //                 'order_type' => $user->isDistributor() ? 'distributor' : 'retail',
    //                 'subtotal' => round($lineSubtotal, 2),
    //                 'total_gst' => round($taxAmount, 2),
    //                 'total_cgst' => round($cgstAmount, 2),
    //                 'total_sgst' => round($sgstAmount, 2),
    //                 'total_igst' => round($igstAmount, 2),
    //                 'shipping_charge' => round($lineShippingCharge, 2),
    //                 'shipping_method_id' => null,
    //                 'coupon_code' => $couponCode,
    //                 'coupon_discount' => round($lineDiscount, 2),
    //                 'coin_redeemed' => $orderCoinsUsed,
    //                 'coin_redeemed_amount' => round($orderAmountRedeemed, 2),
    //                 'total_payable' => $orderGrandTotal,
    //                 'amount_paid' => 0,
    //                 'status' => 'pending',
    //                 'tax_breakdown' => json_encode($orderTaxBreakdownSummary),
    //                 'summary_data' => json_encode($summary),
    //                 'payment_gateway' => $data['payment_gateway'] ?? null,
    //                 'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',
    //             ]);

    //             // Create order line with product shipping charge
    //             OrderLine::create([
    //                 'order_id' => $order->id,
    //                 'product_id' => $itemData['product_id'],
    //                 'variant_id' => $itemData['variant_id'],
    //                 'quantity' => $itemData['quantity'],
    //                 'unit_price' => round($itemData['unit_price'], 2),

    //                 // Shipping charge per line
    //                 'shipping_charge' => round($itemData['product_shipping_charge'], 2),

    //                 // Tax rates
    //                 'gst_rate' => $itemData['tax_rate'],
    //                 'cgst_rate' => round($itemData['cgst_rate'], 2),
    //                 'sgst_rate' => round($itemData['sgst_rate'], 2),
    //                 'igst_rate' => round($itemData['igst_rate'], 2),

    //                 // Tax amounts
    //                 'gst_amount' => round($itemData['tax_amount'], 2),
    //                 'cgst_amount' => round($itemData['cgst_amount'], 2),
    //                 'sgst_amount' => round($itemData['sgst_amount'], 2),
    //                 'igst_amount' => round($itemData['igst_amount'], 2),

    //                 // Line totals
    //                 'line_total' => round($itemData['final_line_total'], 2),
    //                 'commissionable_volume' => $itemData['product']->commissionable_volume ?? 0,
    //             ]);

    //             $orders[] = $order;
    //         }

    //         // Update coin redemption with the FIRST order (or all orders if needed)
    //         if ($coinRedemption && !empty($orders)) {
    //             $coinRedemption->update([
    //                 'order_id' => $orders[0]->id,
    //                 'status' => 'used'
    //             ]);
    //         }

    //         // Create Razorpay order for the TOTAL amount (combined)
    //         // OR create one per order — here we create one for the combined total
    //         $razorpayTotalAmount = 0;
    //         foreach ($orders as $order) {
    //             $razorpayTotalAmount += $order->total_payable;
    //         }

    //         // Create a combined Razorpay order
    //         // NOTE: razorpayService->createOrder() typically takes an Order model.
    //         // If you need a combined amount, you may need to adjust the service.
    //         // For now, we create razorpay order for the FIRST order (or modify as needed).
    //         $razorpayOrder = $this->razorpayService->createOrder($orders[0]);

    //         return [
    //             'order_ids' => array_map(fn($o) => $o->id, $orders),
    //             'order_references' => array_map(fn($o) => $o->order_reference, $orders),
    //             'total_orders' => count($orders),
    //             'total_amount' => round(array_sum(array_map(fn($o) => $o->total_payable, $orders)), 2),
    //             'razorpay_order_id' => $razorpayOrder['id'],
    //             'razorpay_key' => config('services.razorpay.key_id'),
    //             'status' => 'pending',
    //             'checkout_type' => $isBuyNow ? 'buy_now' : 'cart',
    //             'tax_split' => [
    //                 'delivery_state' => $deliveryState,
    //                 'supplier_state' => $supplierState,
    //                 'total_cgst' => round(array_sum(array_map(fn($o) => $o->total_cgst, $orders)), 2),
    //                 'total_sgst' => round(array_sum(array_map(fn($o) => $o->total_sgst, $orders)), 2),
    //                 'total_igst' => round(array_sum(array_map(fn($o) => $o->total_igst, $orders)), 2),
    //             ],
    //             'orders' => array_map(function ($o) {
    //                 return [
    //                     'order_id' => $o->id,
    //                     'order_reference' => $o->order_reference,
    //                     'subtotal' => $o->subtotal,
    //                     'shipping_charge' => $o->shipping_charge,
    //                     'total_tax' => $o->total_gst,
    //                     'total_payable' => $o->total_payable,
    //                 ];
    //             }, $orders),
    //         ];
    //     });
    // }
    public function placeOrder(int $userId, array $data): array
    {
        $address = Address::findOrFail($data['address_id']);
        $user = User::findOrFail($userId);

        $summary = $data['summary_data'] ?? [];
        $checkoutType = $data['checkout_type'] ?? 'cart';

        $supplierState = strtolower(config('app.supplier_state', 'Maharashtra'));
        $deliveryState = $address ? strtolower($address->state) : null;

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

                $variant = null;
                if (isset($itemData['variant_id']) && $itemData['variant_id']) {
                    $variant = ProductVariant::find($itemData['variant_id']);
                    if (!$variant) {
                        throw new Exception("Variant not found: {$itemData['variant_id']}");
                    }
                    if ($variant->stock_quantity < $itemData['quantity']) {
                        throw new Exception("Insufficient stock for variant: {$variant->sku}");
                    }
                } else {
                    if ($product->stock_quantity < $itemData['quantity']) {
                        throw new Exception("Insufficient stock for: {$product->name}");
                    }
                }

                $cartItems[] = (object) [
                    'product_id' => $product->id,
                    'product'    => $product,
                    'variant_id' => $variant ? $variant->id : null,
                    'variant'    => $variant,
                    'quantity'   => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'] ?? 0,
                ];
            }
        } else {
            $cart = Cart::with(['items.product.taxCategory', 'items.variant'])
                ->where('user_id', $userId)
                ->firstOrFail();

            if ($cart->items->isEmpty()) {
                throw new Exception('Cart is empty');
            }

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

        $couponDiscount = $summary['coupon_discount'] ?? 0;
        $couponCode = $summary['coupon_code'] ?? null;

        return DB::transaction(function () use (
            $user,
            $address,
            $coinRedemption,
            $coinsUsed,
            $coinRedeemedAmount,
            $data,
            $summary,
            $cartItems,
            $isBuyNow,
            $supplierState,
            $deliveryState,
            $couponDiscount,
            $couponCode
        ) {
            // First pass: Calculate subtotal
            $itemsWithPrices = [];
            $totalSubtotal = 0;

            foreach ($cartItems as $item) {
                $hasVariant = isset($item->variant) && $item->variant !== null;

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
                    'item'        => $item,
                    'has_variant' => $hasVariant,
                    'unitPrice'   => $unitPrice,
                    'lineTotal'   => $lineTotal,
                ];
            }

            // Second pass: Apply coupon proportionally and calculate tax with split
            $itemsWithTaxSplit = [];

            foreach ($itemsWithPrices as $itemData) {
                $item = $itemData['item'];
                $hasVariant = $itemData['has_variant'];
                $unitPrice = $itemData['unitPrice'];
                $lineTotal = $itemData['lineTotal'];

                $proportion = $totalSubtotal > 0 ? $lineTotal / $totalSubtotal : 0;
                $itemDiscount = $couponDiscount * $proportion;

                $discountedLineTotal = $lineTotal - $itemDiscount;

                $taxRate = $item->product->taxCategory?->rate ?? 0;
                $taxAmount = ($discountedLineTotal * $taxRate) / 100;

                $cgstRate = 0;
                $sgstRate = 0;
                $igstRate = 0;
                $cgstAmount = 0;
                $sgstAmount = 0;
                $igstAmount = 0;

                $isPunjab = $deliveryState && $deliveryState === 'punjab';
                $isInterState = $deliveryState && $deliveryState !== $supplierState;

                if ($deliveryState) {
                    if ($isPunjab) {
                        $cgstRate = $taxRate / 2;
                        $sgstRate = $taxRate / 2;
                        $igstRate = 0;

                        $cgstAmount = ($discountedLineTotal * $cgstRate) / 100;
                        $sgstAmount = ($discountedLineTotal * $sgstRate) / 100;
                        $igstAmount = 0;
                    } elseif ($isInterState) {
                        $igstRate = $taxRate;
                        $cgstRate = 0;
                        $sgstRate = 0;

                        $igstAmount = $taxAmount;
                        $cgstAmount = 0;
                        $sgstAmount = 0;
                    } else {
                        $cgstRate = $taxRate / 2;
                        $sgstRate = $taxRate / 2;
                        $igstRate = 0;

                        $cgstAmount = ($discountedLineTotal * $cgstRate) / 100;
                        $sgstAmount = ($discountedLineTotal * $sgstRate) / 100;
                        $igstAmount = 0;
                    }
                } else {
                    $igstRate = $taxRate;
                    $igstAmount = $taxAmount;
                    $cgstRate = 0;
                    $sgstRate = 0;
                    $cgstAmount = 0;
                    $sgstAmount = 0;
                }

                $productShippingCharge = $item->product->shipping_charge ?? 0;
                $lineShippingCharge = $productShippingCharge * $item->quantity;

                $finalLineTotal = $discountedLineTotal + $taxAmount;

                $variantSku = $hasVariant ? $item->variant->sku : null;
                $variantAttributes = $hasVariant ? $item->variant->attributes : null;

                $itemsWithTaxSplit[] = [
                    'product_id'              => $item->product_id,
                    'product'                 => $item->product,
                    'variant_id'              => $item->variant_id ?? null,
                    'variant'                 => $item->variant ?? null,
                    'quantity'                => $item->quantity,
                    'unit_price'              => $unitPrice,
                    'line_total'              => $lineTotal,
                    'discounted_line_total'   => $discountedLineTotal,
                    'item_discount'           => $itemDiscount,
                    'tax_rate'                => $taxRate,
                    'cgst_rate'               => $cgstRate,
                    'sgst_rate'               => $sgstRate,
                    'igst_rate'               => $igstRate,
                    'cgst_amount'             => $cgstAmount,
                    'sgst_amount'             => $sgstAmount,
                    'igst_amount'             => $igstAmount,
                    'tax_amount'              => $taxAmount,
                    'final_line_total'        => $finalLineTotal,
                    'product_shipping_charge' => $productShippingCharge,
                    'line_shipping_charge'    => $lineShippingCharge,
                    'variant_sku'             => $variantSku,
                    'variant_attributes'      => $variantAttributes,
                ];
            }

            // =============================================
            // CREATE SEPARATE ORDER PER ITEM
            // =============================================
            $orders = [];
            $totalOrderCount = count($itemsWithTaxSplit);

            $totalCoinsToDistribute = $coinsUsed;
            $totalAmountToDistribute = $coinRedeemedAmount;
            $distributedCoins = 0;
            $distributedAmount = 0;

            foreach ($itemsWithTaxSplit as $index => $itemData) {
                $item = $itemData['product'];
                $lineShippingCharge = $itemData['line_shipping_charge'];
                $lineSubtotal = $itemData['line_total'];
                $lineDiscount = $itemData['item_discount'];
                $discountedLineTotal = $itemData['discounted_line_total'];
                $taxAmount = $itemData['tax_amount'];
                $cgstAmount = $itemData['cgst_amount'];
                $sgstAmount = $itemData['sgst_amount'];
                $igstAmount = $itemData['igst_amount'];

                if ($index === $totalOrderCount - 1) {
                    $orderCoinsUsed = $totalCoinsToDistribute - $distributedCoins;
                    $orderAmountRedeemed = $totalAmountToDistribute - $distributedAmount;
                } else {
                    $proportion = $totalSubtotal > 0 ? $lineSubtotal / $totalSubtotal : 0;
                    $orderCoinsUsed = (int) round($totalCoinsToDistribute * $proportion);
                    $orderAmountRedeemed = round($totalAmountToDistribute * $proportion, 2);
                    $distributedCoins += $orderCoinsUsed;
                    $distributedAmount += $orderAmountRedeemed;
                }

                $orderGrandTotal = round(
                    $discountedLineTotal + $taxAmount + $lineShippingCharge - $orderAmountRedeemed,
                    2
                );

                $orderTaxBreakdownSummary = [
                    'items' => [
                        [
                            'product_id'                 => $itemData['product_id'],
                            'product_name'               => $item->name,
                            'product_code'               => $item->product_code ?? null,
                            'variant_id'                 => $itemData['variant_id'],
                            'variant_sku'                => $itemData['variant_sku'],
                            'variant_attributes'         => $itemData['variant_attributes'],
                            'quantity'                   => $itemData['quantity'],
                            'unit_price'                 => $itemData['unit_price'],
                            'line_total_before_discount' => $itemData['line_total'],
                            'line_total_after_discount'  => round($discountedLineTotal, 2),
                            'tax_category'               => $item->taxCategory?->name ?? 'Default',
                            'tax_rate'                   => $itemData['tax_rate'],
                            'cgst_rate'                  => round($itemData['cgst_rate'], 2),
                            'sgst_rate'                  => round($itemData['sgst_rate'], 2),
                            'igst_rate'                  => round($itemData['igst_rate'], 2),
                            'cgst_amount'                => round($cgstAmount, 2),
                            'sgst_amount'                => round($sgstAmount, 2),
                            'igst_amount'                => round($igstAmount, 2),
                            'tax_amount'                 => round($taxAmount, 2),
                            'line_total_after_tax'       => round($discountedLineTotal + $taxAmount, 2),
                            'shipping_charge'            => round($lineShippingCharge, 2),
                        ]
                    ],
                    'summary' => [
                        'subtotal'               => round($lineSubtotal, 2),
                        'coupon_discount'        => round($lineDiscount, 2),
                        'subtotal_after_discount' => round($discountedLineTotal, 2),
                        'total_tax'              => round($taxAmount, 2),
                        'total_cgst'             => round($cgstAmount, 2),
                        'total_sgst'             => round($sgstAmount, 2),
                        'total_igst'             => round($igstAmount, 2),
                        'shipping_charge'        => round($lineShippingCharge, 2),
                        'coin_redeemed'          => $orderCoinsUsed,
                        'coin_redeemed_amount'   => round($orderAmountRedeemed, 2),
                        'grand_total'            => $orderGrandTotal,
                    ],
                    'tax_by_category' => $this->calculateTaxByCategory([
                        [
                            'product_name'            => $item->name,
                            'tax_category'            => $item->taxCategory?->name ?? 'Default',
                            'tax_rate'                => $itemData['tax_rate'],
                            'line_total_after_discount' => $discountedLineTotal,
                            'tax_amount'              => $taxAmount,
                            'cgst_amount'             => $cgstAmount,
                            'sgst_amount'             => $sgstAmount,
                            'igst_amount'             => $igstAmount,
                        ]
                    ]),
                    'delivery_state' => $deliveryState,
                    'supplier_state' => $supplierState,
                ];

                // =============================================
                // CREATE ORDER
                // =============================================
                $order = Order::create([
                    'order_reference'      => 'ORD-' . strtoupper(uniqid()),
                    'user_id'              => $user->id,
                    'billing_address_id'   => $data['address_id'],
                    'delivery_address_id'  => $data['address_id'],
                    'order_type'           => $user->isDistributor() ? 'distributor' : 'retail',
                    'subtotal'             => round($lineSubtotal, 2),
                    'total_gst'            => round($taxAmount, 2),
                    'total_cgst'           => round($cgstAmount, 2),
                    'total_sgst'           => round($sgstAmount, 2),
                    'total_igst'           => round($igstAmount, 2),
                    'shipping_charge'      => round($lineShippingCharge, 2),
                    'shipping_method_id'   => null,
                    'coupon_code'          => $couponCode,
                    'coupon_discount'      => round($lineDiscount, 2),
                    'coin_redeemed'        => $orderCoinsUsed,
                    'coin_redeemed_amount' => round($orderAmountRedeemed, 2),
                    'total_payable'        => $orderGrandTotal,
                    'amount_paid'          => 0,
                    'status'               => 'pending',
                    'tax_breakdown'        => json_encode($orderTaxBreakdownSummary),
                    'summary_data'         => json_encode($summary),
                    'payment_gateway'      => $data['payment_gateway'] ?? null,
                    'checkout_type'        => $isBuyNow ? 'buy_now' : 'cart',
                ]);

                // =============================================
                // GENERATE UNIQUE ITEM REFERENCE ID
                // =============================================
                $itemReferenceId = $this->generateUniqueItemReferenceId();

                OrderLine::create([
                    'order_id'              => $order->id,
                    'item_reference_id'     => $itemReferenceId,
                    'product_id'            => $itemData['product_id'],
                    'variant_id'            => $itemData['variant_id'],
                    'quantity'              => $itemData['quantity'],
                    'unit_price'            => round($itemData['unit_price'], 2),
                    'shipping_charge'       => round($itemData['product_shipping_charge'], 2),
                    'gst_rate'              => $itemData['tax_rate'],
                    'cgst_rate'             => round($itemData['cgst_rate'], 2),
                    'sgst_rate'             => round($itemData['sgst_rate'], 2),
                    'igst_rate'             => round($itemData['igst_rate'], 2),
                    'gst_amount'            => round($itemData['tax_amount'], 2),
                    'cgst_amount'           => round($itemData['cgst_amount'], 2),
                    'sgst_amount'           => round($itemData['sgst_amount'], 2),
                    'igst_amount'           => round($itemData['igst_amount'], 2),
                    'line_total'            => round($itemData['final_line_total'], 2),
                    'commissionable_volume' => $itemData['product']->commissionable_volume ?? 0,
                ]);

                $orders[] = $order;
            }

            if ($coinRedemption && !empty($orders)) {
                $coinRedemption->update([
                    'order_id' => $orders[0]->id,
                    'status'   => 'used',
                ]);
            }

            // =============================================
            // COMBINED TOTAL AMOUNT
            // =============================================
            $razorpayTotalAmount = collect($orders)->sum('total_payable');

            // Collect all order references for webhook mapping
            $orderReferences = array_map(fn($o) => $o->order_reference, $orders);

            return [
                'order_ids'        => array_map(fn($o) => $o->id, $orders),
                'order_references' => $orderReferences,
                'total_orders'     => count($orders),
                'total_amount'     => round($razorpayTotalAmount, 2),
                'razorpay_key'     => config('services.razorpay.key_id'),
                'status'           => 'pending',
                'checkout_type'    => $isBuyNow ? 'buy_now' : 'cart',
                'tax_split'        => [
                    'delivery_state' => $deliveryState,
                    'supplier_state' => $supplierState,
                    'total_cgst'     => round(collect($orders)->sum('total_cgst'), 2),
                    'total_sgst'     => round(collect($orders)->sum('total_sgst'), 2),
                    'total_igst'     => round(collect($orders)->sum('total_igst'), 2),
                ],
                'orders' => array_map(function ($o) {
                    return [
                        'order_id'        => $o->id,
                        'order_reference' => $o->order_reference,
                        'subtotal'        => $o->subtotal,
                        'shipping_charge' => $o->shipping_charge,
                        'total_tax'       => $o->total_gst,
                        'total_payable'   => $o->total_payable,
                    ];
                }, $orders),
            ];
        });
    }

    /**
     * Generate a unique item reference ID for order lines.
     * Format: ITM-YYYYMMDD-XXXXXX (e.g., ITM-20260911-A1B2C3)
     */
    private function generateUniqueItemReferenceId(): string
    {
        do {
            $reference = 'ITM-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (OrderLine::where('item_reference_id', $reference)->exists());

        return $reference;
    }

    /**
     * Generate a unique order group ID (ensures no collision in DB)
     */
    private function generateUniqueOrderGroupId(): string
    {
        do {
            $groupId = 'GRP-' . strtoupper(bin2hex(random_bytes(8)));
        } while (Order::where('order_group_id', $groupId)->exists());

        return $groupId;
    }
    /**
     * Helper function to calculate tax breakdown by category
     */
    // private function calculateTaxByCategory(array $taxBreakdown): array
    // {
    //     $taxByCategory = [];

    //     foreach ($taxBreakdown as $item) {
    //         $category = $item['tax_category'];
    //         if (!isset($taxByCategory[$category])) {
    //             $taxByCategory[$category] = [
    //                 'tax_rate' => $item['tax_rate'],
    //                 'total_taxable_amount' => 0,
    //                 'total_tax_amount' => 0,
    //                 'items' => [],
    //             ];
    //         }

    //         // Use line_total_after_discount as taxable amount (after coupon discount)
    //         $taxByCategory[$category]['total_taxable_amount'] += $item['line_total_after_discount'] ?? $item['line_total_before_discount'] ?? 0;
    //         $taxByCategory[$category]['total_tax_amount'] += $item['tax_amount'];
    //         $taxByCategory[$category]['items'][] = $item['product_name'];
    //     }

    //     return $taxByCategory;
    // }
    private function calculateTaxByCategory(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $category = $item['tax_category'] ?? 'Default';

            if (!isset($grouped[$category])) {
                $grouped[$category] = [
                    'tax_category' => $category,
                    'tax_rate'     => $item['tax_rate'],
                    'taxable_amount' => 0,
                    'tax_amount'   => 0,
                    'cgst_amount'  => 0,
                    'sgst_amount'  => 0,
                    'igst_amount'  => 0,
                ];
            }

            $grouped[$category]['taxable_amount'] += $item['line_total_after_discount'] ?? 0;
            $grouped[$category]['tax_amount']     += $item['tax_amount'] ?? 0;
            $grouped[$category]['cgst_amount']    += $item['cgst_amount'] ?? 0;
            $grouped[$category]['sgst_amount']    += $item['sgst_amount'] ?? 0;
            $grouped[$category]['igst_amount']    += $item['igst_amount'] ?? 0;
        }

        return array_values(array_map(function ($row) {
            $row['taxable_amount'] = round($row['taxable_amount'], 2);
            $row['tax_amount']     = round($row['tax_amount'], 2);
            $row['cgst_amount']    = round($row['cgst_amount'], 2);
            $row['sgst_amount']    = round($row['sgst_amount'], 2);
            $row['igst_amount']    = round($row['igst_amount'], 2);
            return $row;
        }, $grouped));
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
    // public function confirmOrder(string $orderReference, array $gatewayData): array
    // {
    //     $order = Order::where('order_reference', $orderReference)->firstOrFail();

    //     if ($order->status === 'confirmed') {
    //         return ['success' => true, 'message' => 'Order already confirmed'];
    //     }

    //     return DB::transaction(function () use ($order, $gatewayData) {
    //         $oldStatus = $order->status;

    //         $order->update([
    //             'status' => 'confirmed',
    //             'confirmed_at' => now(),
    //             'payment_gateway' => $gatewayData['gateway'],
    //             'gateway_transaction_id' => $gatewayData['transaction_id'],
    //             'amount_paid' => $order->total_payable,
    //         ]);

    //         // =============================================
    //         // RECORD COUPON USAGE
    //         // =============================================
    //         if ($order->coupon_code) {
    //             $coupon = Coupon::where('code', $order->coupon_code)->first();

    //             if ($coupon) {
    //                 // Check if usage already exists for this order
    //                 $existingUsage = CouponUsage::where('order_id', $order->id)->first();

    //                 if (!$existingUsage) {
    //                     // Create coupon usage entry
    //                     CouponUsage::create([
    //                         'coupon_id' => $coupon->id,
    //                         'user_id' => $order->user_id,
    //                         'order_id' => $order->id,
    //                         'discount_amount' => $order->coupon_discount,
    //                     ]);

    //                     // Increment used_count on coupon
    //                     $coupon->increment('used_count');
    //                 }
    //             }
    //         }

    //         $lowStockAlerts = [];

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
    //                         $alert = $this->sendLowStockNotification($order, $line, 'variant');
    //                         $lowStockAlerts[] = [
    //                             'product_id' => $line->product_id,
    //                             'variant_id' => $line->variant_id,
    //                             'product_name' => $line->product?->name ?? 'Unknown',
    //                             'stock' => $variant->stock_quantity,
    //                             'threshold' => $variant->low_stock_threshold,
    //                             'alert_type' => 'variant_low_stock'
    //                         ];
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
    //                         $alert = $this->sendLowStockNotification($order, $line, 'product');
    //                         $lowStockAlerts[] = [
    //                             'product_id' => $line->product_id,
    //                             'product_name' => $product->name,
    //                             'stock' => $product->stock_quantity,
    //                             'threshold' => $product->low_stock_threshold,
    //                             'alert_type' => 'product_low_stock'
    //                         ];
    //                     }
    //                 }
    //             }

    //             $line->update([
    //                 'delivery_status' => 'confirmed'
    //             ]);
    //         }

    //         // Log order confirmation with low stock alerts
    //         $this->logAudit(
    //             'order_confirm',
    //             'orders',
    //             [
    //                 'order_reference' => $order->order_reference,
    //                 'status' => $oldStatus,
    //                 'total_amount' => $order->total_payable,
    //                 'user_id' => $order->user_id ?? Auth::user()->id,
    //             ],
    //             [
    //                 'order_reference' => $order->order_reference,
    //                 'status' => 'confirmed',
    //                 'confirmed_at' => now()->toDateTimeString(),
    //                 'payment_gateway' => $gatewayData['gateway'],
    //                 'transaction_id' => $gatewayData['transaction_id'],
    //                 'amount_paid' => $order->total_payable,
    //                 'low_stock_alerts' => $lowStockAlerts,
    //                 'confirmed_by' => $this->getAdminId(),
    //             ]
    //         );

    //         // If low stock alerts exist, log them separately
    //         foreach ($lowStockAlerts as $alert) {
    //             $this->logAudit(
    //                 'low_stock_alert',
    //                 'inventory',
    //                 null,
    //                 $alert,
    //                 null,
    //                 $this->getClientIp()
    //             );
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
    //             'invoice_number' => $invoice->invoice_number ?? null,
    //         ];
    //     });
    // }
    public function confirmOrder(string|array $orderReferences, array $gatewayData): array
    {
        // Normalize input to array
        if (is_string($orderReferences)) {
            $orderReferences = array_filter(array_map('trim', explode(',', $orderReferences)));
        }

        if (empty($orderReferences)) {
            throw new Exception('No order references provided for confirmation');
        }

        $orders = Order::whereIn('order_reference', $orderReferences)
            ->with(['lines.product', 'lines.variant'])
            ->get();

        if ($orders->isEmpty()) {
            throw new Exception('No orders found for the provided references');
        }

        // If ALL orders are already confirmed, return early
        if ($orders->every(fn($o) => $o->status === 'confirmed')) {
            return [
                'success'          => true,
                'message'          => 'Orders already confirmed',
                'order_references' => $orders->pluck('order_reference')->toArray(),
            ];
        }

        return DB::transaction(function () use ($orders, $gatewayData) {
            $confirmedOrders = [];
            $allLowStockAlerts = [];

            foreach ($orders as $order) {
                // Skip already confirmed orders (idempotency)
                if ($order->status === 'confirmed') {
                    $confirmedOrders[] = $order;
                    continue;
                }

                $oldStatus = $order->status;

                // =============================================
                // UPDATE ORDER STATUS
                // =============================================
                $order->update([
                    'status'                 => 'confirmed',
                    'confirmed_at'           => now(),
                    'payment_gateway'        => $gatewayData['gateway'] ?? $order->payment_gateway,
                    'gateway_transaction_id' => $gatewayData['transaction_id'] ?? null,
                    'amount_paid'            => $order->total_payable,
                ]);

                // =============================================
                // RECORD COUPON USAGE
                // =============================================
                if ($order->coupon_code) {
                    $coupon = Coupon::where('code', $order->coupon_code)->first();

                    if ($coupon) {
                        $existingUsage = CouponUsage::where('order_id', $order->id)->first();

                        if (!$existingUsage) {
                            CouponUsage::create([
                                'coupon_id'       => $coupon->id,
                                'user_id'         => $order->user_id,
                                'order_id'        => $order->id,
                                'discount_amount' => $order->coupon_discount,
                            ]);

                            $coupon->increment('used_count');
                        }
                    }
                }

                $lowStockAlerts = [];

                // =============================================
                // DECREMENT STOCK FOR EACH ORDER LINE
                // =============================================
                foreach ($order->lines as $line) {
                    if ($line->variant_id) {
                        $variant = $line->variant;

                        if ($variant) {
                            $variant->decrement('stock_quantity', $line->quantity);

                            $product = $line->product;
                            if ($product) {
                                $product->decrement('stock_quantity', $line->quantity);
                            }

                            StockMovement::create([
                                'product_id'               => $line->product_id,
                                'variant_id'               => $line->variant_id,
                                'item_reference_id'        => $line->item_reference_id,   // <-- include
                                'quantity'                 => -$line->quantity,
                                'available_quantity_after' => $variant->stock_quantity,
                                'reason'                   => 'Order confirmed (variant): ' . $order->order_reference . ' [' . $line->item_reference_id . ']',
                                'order_id'                 => $order->id,
                            ]);

                            $variant->refresh();
                            if ($variant->stock_quantity <= $variant->low_stock_threshold) {
                                $this->sendLowStockNotification($order, $line, 'variant');
                                $alert = [
                                    'product_id'    => $line->product_id,
                                    'variant_id'    => $line->variant_id,
                                    'item_reference_id' => $line->item_reference_id,
                                    'product_name'  => $line->product?->name ?? 'Unknown',
                                    'stock'         => $variant->stock_quantity,
                                    'threshold'     => $variant->low_stock_threshold,
                                    'alert_type'    => 'variant_low_stock',
                                ];
                                $lowStockAlerts[] = $alert;
                                $allLowStockAlerts[] = $alert;
                            }
                        }
                    } else {
                        $product = $line->product;

                        if ($product) {
                            $product->decrement('stock_quantity', $line->quantity);

                            StockMovement::create([
                                'product_id'               => $line->product_id,
                                'variant_id'               => null,
                                'item_reference_id'        => $line->item_reference_id,   // <-- include
                                'quantity'                 => -$line->quantity,
                                'available_quantity_after' => $product->stock_quantity,
                                'reason'                   => 'Order confirmed: ' . $order->order_reference . ' [' . $line->item_reference_id . ']',
                                'order_id'                 => $order->id,
                            ]);

                            $product->refresh();
                            if ($product->stock_quantity <= $product->low_stock_threshold) {
                                $this->sendLowStockNotification($order, $line, 'product');
                                $alert = [
                                    'product_id'    => $line->product_id,
                                    'item_reference_id' => $line->item_reference_id,
                                    'product_name'  => $product->name,
                                    'stock'         => $product->stock_quantity,
                                    'threshold'     => $product->low_stock_threshold,
                                    'alert_type'    => 'product_low_stock',
                                ];
                                $lowStockAlerts[] = $alert;
                                $allLowStockAlerts[] = $alert;
                            }
                        }
                    }

                    $line->update([
                        'delivery_status' => 'confirmed',
                    ]);
                }

                // =============================================
                // AUDIT LOG: ORDER CONFIRM
                // =============================================
                $this->logAudit(
                    'order_confirm',
                    'orders',
                    [
                        'order_reference' => $order->order_reference,
                        'status'          => $oldStatus,
                        'total_amount'    => $order->total_payable,
                        'user_id'         => $order->user_id ?? Auth::id(),
                    ],
                    [
                        'order_reference'   => $order->order_reference,
                        'status'            => 'confirmed',
                        'confirmed_at'      => now()->toDateTimeString(),
                        'payment_gateway'   => $gatewayData['gateway'] ?? null,
                        'transaction_id'    => $gatewayData['transaction_id'] ?? null,
                        'amount_paid'       => $order->total_payable,
                        'low_stock_alerts'  => $lowStockAlerts,
                        'confirmed_by'      => $this->getAdminId(),
                    ]
                );

                // =============================================
                // AUDIT LOG: LOW STOCK ALERTS
                // =============================================
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

                // =============================================
                // COMMISSION API EVENT
                // =============================================
                $payload = $this->buildCommissionPayload($order);

                CommissionApiEvent::create([
                    'event_type'    => 'order_post',
                    'order_id'      => $order->id,
                    'payload'       => $payload,
                    'status'        => 'pending',
                    'retry_count'   => 0,
                    'max_retries'   => 5,
                    'last_attempt'  => null,
                    'error_message' => null,
                    'response_data' => null,
                ]);

                // =============================================
                // SEND ORDER CONFIRMATION NOTIFICATION
                // =============================================
                $this->sendOrderConfirmationNotification($order, $gatewayData);

                $confirmedOrders[] = $order;
            }

            // =============================================
            // DELETE CART (only once, for cart checkout)
            // =============================================
            $firstOrder = $confirmedOrders[0] ?? null;
            if ($firstOrder && $firstOrder->checkout_type !== 'buy_now') {
                $cart = Cart::where('user_id', $firstOrder->user_id)->first();
                if ($cart) {
                    $cart->items()->delete();
                    $cart->delete();
                }
            }

            if ($order->user->account_type == 'distributor') {
                $this->proformaInvoiceService->generateForOrder($order);
            }

            return [
                'success'          => true,
                'order_ids'        => array_map(fn($o) => $o->id, $confirmedOrders),
                'order_references' => array_map(fn($o) => $o->order_reference, $confirmedOrders),
                'total_orders'     => count($confirmedOrders),
                'total_amount'     => round(collect($confirmedOrders)->sum('total_payable'), 2),
                'status'           => 'confirmed',
                'low_stock_alerts' => $allLowStockAlerts,
                'invoice_number'   => null, // set this if invoice service used
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
    //  ): array {
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
    //         // Cancel order lines and restore stock
    //         foreach ($order->lines as $line) {

    //             // Update order line delivery status
    //             $line->update([
    //                 'delivery_status' => 'cancelled',
    //                 'cancelled_at' => now(),
    //             ]);

    //             // Restore variant stock if order line has a variant
    //             if ($line->variant_id && $line->variant) {
    //                 $line->variant->increment(
    //                     'stock_quantity',
    //                     $line->quantity
    //                 );
    //             } else {
    //                 // Restore product stock if no variant is associated
    //                 $line->product->increment(
    //                     'stock_quantity',
    //                     $line->quantity
    //                 );
    //             }

    //             // Restore product stock as well if your system maintains
    //             // both product-level and variant-level stock
    //             if ($line->variant_id && $line->variant) {
    //                 $line->product->increment(
    //                     'stock_quantity',
    //                     $line->quantity
    //                 );
    //             }

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
        int $orderLineId,
        string $reason
    ): array {
        // Find the specific order line
        $orderLine = OrderLine::where('id', $orderLineId)
            ->whereHas('order', function ($query) use ($userId, $orderReference) {
                $query->where('order_reference', $orderReference)
                    ->where('user_id', $userId);
            })
            ->with(['order', 'product', 'variant'])
            ->firstOrFail();

        $order = $orderLine->order;

        // Check if line is already cancelled
        if ($orderLine->delivery_status === 'cancelled') {
            throw new \Exception('This item is already cancelled');
        }

        DB::transaction(function () use ($orderLine, $order, $reason) {

            // Update order line status
            $orderLine->update([
                'delivery_status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            // Restore stock for this specific line
            // if ($orderLine->variant_id && $orderLine->variant) {
            //     $orderLine->variant->increment(
            //         'stock_quantity',
            //         $orderLine->quantity
            //     );

            //     // Also restore product-level stock if maintained
            //     $orderLine->product->increment(
            //         'stock_quantity',
            //         $orderLine->quantity
            //     );
            // } else {
            //     $orderLine->product->increment(
            //         'stock_quantity',
            //         $orderLine->quantity
            //     );
            // }

            // Create stock movement
            StockMovement::create([
                'product_id' => $orderLine->product_id,
                'quantity' => $orderLine->quantity,
                'available_quantity_after' => $orderLine->product->stock_quantity,
                'reason' => $reason,
                'order_id' => $order->id,
                'order_line_id' => $orderLine->id,
            ]);

            // Check if ALL order lines are cancelled
            $allCancelled = $order->lines()
                ->where('delivery_status', '!=', 'cancelled')
                ->doesntExist();

            // Update order status if all items are cancelled
            if ($allCancelled) {
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                ]);

                // Process full refund if order was paid
                if ($order->amount_paid > 0) {
                    $this->returnService->processRefundForOrder($order, $reason);
                }

                // Create reversal event for full order
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

                Log::info('Order fully cancelled as all items were cancelled', [
                    'order' => $order->order_reference,
                    'reason' => $reason,
                ]);
            } else {
                // Process partial refund for this specific line
                if ($orderLine->line_total > 0) {
                    $this->processPartialRefund($order, $orderLine, $reason);
                }

                Log::info('Order line cancelled', [
                    'order' => $order->order_reference,
                    'order_line_id' => $orderLine->id,
                    'reason' => $reason,
                    'remaining_items' => $order->lines()
                        ->where('delivery_status', '!=', 'cancelled')
                        ->count(),
                ]);
            }
        });

        // Reload order to get updated status
        $order->refresh();

        return [
            'order_reference' => $order->order_reference,
            'order_line_id' => $orderLine->id,
            'line_status' => $orderLine->delivery_status,
            'order_status' => $order->status,
            'reason' => $reason,
            'all_items_cancelled' => $order->status === 'cancelled',
        ];
    }

    protected function processPartialRefund(Order $order, OrderLine $orderLine, string $reason): void
    {
        // Total already refunded for this order (cash refunds only)
        $alreadyRefunded = Refund::where('order_id', $order->id)
            ->where('status', 'completed')
            ->sum('amount');

        $remainingBalance = max(0, (float) $order->amount_paid - $alreadyRefunded);

        // Refund amount = line_total (already includes tax and discounts)
        // No need to add tax separately because line_total already has it.
        $refundAmount = (float) $orderLine->line_total;

        // Proportional shipping refund (if shipping was charged)
        if ($order->shipping_charge > 0 && $order->subtotal > 0) {
            $proportion = $orderLine->line_total / $order->subtotal;
            $shippingRefund = $order->shipping_charge * $proportion;
            $refundAmount += $shippingRefund;
        }

        $refundAmount = round($refundAmount, 2);

        // Cap by remaining balance
        $refundAmount = min($refundAmount, $remainingBalance);

        if ($refundAmount <= 0) {
            Log::warning('Partial refund skipped: amount is zero or exceeds remaining balance', [
                'order_id' => $order->id,
                'order_line_id' => $orderLine->id,
                'remaining_balance' => $remainingBalance,
                'requested' => $refundAmount,
            ]);
            return;
        }

        // Razorpay partial refund
        $gateway = $order->payment_gateway ?? 'razorpay';
        if ($gateway === 'razorpay') {
            if (!$order->gateway_transaction_id) {
                throw new \Exception("Payment transaction ID not found for order {$order->order_reference}.");
            }
            $refundResponse = $this->razorpayService->processPartialRefund(
                $order->gateway_transaction_id,
                $refundAmount
            );
            Log::info('Razorpay partial refund completed', [
                'order_id' => $order->id,
                'order_line_id' => $orderLine->id,
                'payment_id' => $order->gateway_transaction_id,
                'refund_id' => $refundResponse['refund_id'] ?? null,
                'amount' => $refundAmount,
            ]);
        }

        // Create refund record
        $refund = Refund::create([
            'order_id' => $order->id,
            'order_line_id' => $orderLine->id,
            'amount' => $refundAmount,
            'reason' => $reason,
            'status' => 'completed',
            'completed_at' => now(),
            'gateway_reference' => $refundResponse['refund_id'] ?? null,
        ]);

        // Generate credit note for this line
        $this->generateCreditNoteForLine($order, $orderLine, $refund->id, $reason);

        // Update order paid amount
        $newAmountPaid = max(0, (float) $order->amount_paid - $refundAmount);
        $order->update([
            'amount_paid' => $newAmountPaid,
            'refund_status' => 'partial',
            'refunded_at' => now(),
        ]);

        Log::info('Partial refund processed', [
            'order' => $order->order_reference,
            'order_line_id' => $orderLine->id,
            'amount' => $refundAmount,
            'remaining_balance_after' => $newAmountPaid,
        ]);
    }

    /**
     * Generate credit note for a cancelled line
     */

    protected function generateCreditNoteForLine(Order $order, OrderLine $orderLine, int $refundId, string $reason): void
    {
        $creditNoteService = app(\App\Services\CreditNoteService::class);

        $subtotal = (float) $orderLine->unit_price * $orderLine->quantity;
        $tax = (float) ($orderLine->gst_amount ?? 0);
        $lineTotal = $subtotal + $tax;

        $items = [[
            'order_line_id' => $orderLine->id,
            'product_id'    => $orderLine->product_id,
            'product_name'  => $orderLine->product?->name ?? 'Unknown',
            'quantity'      => $orderLine->quantity,
            'unit_price'    => (float) $orderLine->unit_price,
            'gst_rate'      => (float) $orderLine->gst_rate,
            'subtotal'      => $subtotal,
            'tax'           => $tax,
            'line_total'    => $lineTotal,
            'reason'        => $reason,
            'image_paths'   => [],
            'return_status' => 'completed',
        ]];

        $returnOrder = new \App\Models\OrderReturn();
        $returnOrder->order = $order;
        $returnOrder->user_id = $order->user_id;
        $returnOrder->type = 'cancellation';
        $returnOrder->reason = $reason;
        $returnOrder->items = $items;
        $returnOrder->refund_subtotal = $subtotal;
        $returnOrder->refund_tax = $tax;
        $returnOrder->refund_shipping = 0; // Partial line cancellation – shipping not refunded proportionally? You can add logic if needed.
        $returnOrder->total_refund_amount = $lineTotal; // or include proportional shipping if you want

        $creditNoteService->generateFromReturn($returnOrder, $refundId);
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
