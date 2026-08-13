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
use App\Services\PaymentGateway\RazorpayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Models\Coupon;
use App\Models\ShippingMethod;
use App\Models\CouponUsage;

class CheckoutService
{
    protected GSTCalculator $gstCalculator;
    protected InvoiceService $invoiceService;
    protected RazorpayService $razorpayService;
    protected $pdfInvoiceService;

    public function __construct(
        GSTCalculator $gstCalculator,
        InvoiceService $invoiceService,
        RazorpayService $razorpayService,
        PdfInvoiceService $pdfInvoiceService
    ) {
        $this->gstCalculator = $gstCalculator;
        $this->invoiceService = $invoiceService;
        $this->razorpayService = $razorpayService;
        $this->pdfInvoiceService = $pdfInvoiceService;;
    }

    /**
     * FR-CO-003: Calculate order summary
     */
    /**
     * Calculate cart summary with coupon and shipping
     */

    // public function calculateSummary(int $userId, int $addressId, ?string $couponCode = null, ?int $shippingMethodId = null): array
    // {
    //     // Fetch cart with products and their tax categories
    //     $cart = Cart::with(['items.product.taxCategory'])->where('user_id', $userId)->firstOrFail();
    //     $address = Address::findOrFail($addressId);
    //     $user = User::findOrFail($userId);

    //     if ($cart->items->isEmpty()) {
    //         throw new Exception('Cart is empty');
    //     }

    //     // Calculate base subtotal and prepare product data
    //     $subtotal = 0;
    //     $productDetails = [];

    //     foreach ($cart->items as $item) {
    //         // Determine price based on user role
    //         $unitPrice = $user->isDistributor()
    //             ? ($item->product->distributor_price ?? $item->product->retail_price)
    //             : $item->product->retail_price;

    //         $lineTotal = $unitPrice * $item->quantity;
    //         $subtotal += $lineTotal;

    //         // Get tax rate from product's tax category
    //         $taxRate = $item->product->taxCategory?->rate ?? 0;

    //         $productDetails[] = [
    //             'item' => $item,
    //             'unitPrice' => $unitPrice,
    //             'lineTotal' => $lineTotal,
    //             'taxRate' => $taxRate,
    //             'taxCategoryName' => $item->product->taxCategory?->name ?? 'No Tax',
    //         ];
    //     }

    //     // Apply coupon if provided
    //     $couponDiscount = 0;
    //     $couponData = null;
    //     if ($couponCode) {
    //         $coupon = Coupon::where('code', strtoupper($couponCode))->first();

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

    //     // Calculate subtotal after discount
    //     $subtotalAfterDiscount = $subtotal - $couponDiscount;

    //     // Apply shipping method
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

    //     // Calculate tax PER PRODUCT based on its tax category
    //     $totalTax = 0;
    //     $itemsWithTax = [];
    //     $taxBreakdown = [];
    //     $taxByCategory = [];

    //     foreach ($productDetails as $product) {
    //         // Calculate proportion of this product in the discounted subtotal
    //         $proportion = $subtotal > 0 ? $product['lineTotal'] / $subtotal : 0;

    //         // Apply discount proportionally to each product's taxable value
    //         $discountedLineTotal = $product['lineTotal'] - ($couponDiscount * $proportion);

    //         // Calculate tax based on product's tax category rate
    //         $taxRate = $product['taxRate'];
    //         $gstAmount = ($discountedLineTotal * $taxRate) / 100;
    //         $totalTax += $gstAmount;

    //         // Determine CGST/SGST/IGST based on delivery state
    //         $supplierState = config('app.supplier_state', 'Maharashtra');
    //         $deliveryState = $address->state;

    //         if (strtolower($deliveryState) === strtolower($supplierState)) {
    //             $cgst = $gstAmount / 2;
    //             $sgst = $gstAmount / 2;
    //             $igst = 0;
    //         } else {
    //             $cgst = 0;
    //             $sgst = 0;
    //             $igst = $gstAmount;
    //         }

    //         // Add to items with tax
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
    //             'total_tax' => round($gstAmount, 2),
    //             'line_total' => round($discountedLineTotal + $gstAmount, 2),
    //         ];

    //         // Add to tax breakdown
    //         $taxBreakdown[] = [
    //             'product_name' => $product['item']->product->name,
    //             'tax_category' => $product['taxCategoryName'],
    //             'rate' => (string) $taxRate . '%',
    //             'cgst' => round($cgst, 2),
    //             'sgst' => round($sgst, 2),
    //             'igst' => round($igst, 2),
    //         ];

    //         // Group by tax category for summary
    //         $categoryKey = $product['taxCategoryName'] . '_' . $taxRate;
    //         if (!isset($taxByCategory[$categoryKey])) {
    //             $taxByCategory[$categoryKey] = [
    //                 'category' => $product['taxCategoryName'],
    //                 'rate' => $taxRate,
    //                 'taxable_amount' => 0,
    //                 'tax_amount' => 0,
    //                 'cgst' => 0,
    //                 'sgst' => 0,
    //                 'igst' => 0,
    //             ];
    //         }
    //         $taxByCategory[$categoryKey]['taxable_amount'] += $discountedLineTotal;
    //         $taxByCategory[$categoryKey]['tax_amount'] += $gstAmount;
    //         $taxByCategory[$categoryKey]['cgst'] += $cgst;
    //         $taxByCategory[$categoryKey]['sgst'] += $sgst;
    //         $taxByCategory[$categoryKey]['igst'] += $igst;
    //     }

    //     // Calculate grand total
    //     $grandTotal = round($subtotalAfterDiscount + $totalTax + $shippingCost, 2);

    //     // Coin balance for distributors
    //     $coinBalance = 0;
    //     $maxCoinsRedeemable = 0;
    //     if ($user->isDistributor()) {
    //         $coinBalance = $this->getCoinBalance($userId);
    //         $maxCoinsRedeemable = min($coinBalance, floor($grandTotal / 10));
    //     }

    //     return [
    //         'subtotal' => round($subtotal, 2),
    //         'coupon_discount' => round($couponDiscount, 2),
    //         'coupon' => $couponData,
    //         'subtotal_after_discount' => round($subtotalAfterDiscount, 2),
    //         'total_tax' => round($totalTax, 2),
    //         'tax_by_category' => array_values($taxByCategory), // Tax summary by category
    //         'shipping_cost' => round($shippingCost, 2),
    //         'shipping_method' => $shippingData,
    //         'grand_total' => $grandTotal,
    //         'coin_balance' => $coinBalance,
    //         'max_coins_redeemable' => $maxCoinsRedeemable,
    //         'items' => $itemsWithTax,
    //         'tax_breakdown' => $taxBreakdown,
    //         'delivery_address' => [
    //             'id' => $address->id,
    //             'full_address' => $this->formatAddress($address),
    //             'state' => $address->state,
    //         ],
    //     ];
    // }

    /**
     * Calculate cart summary with coupon, shipping, and coin redemption
     */
    // public function calculateSummary(int $userId, int $addressId, ?string $couponCode = null, ?int $shippingMethodId = null, ?int $coinsToRedeem = null): array
    // {
    //     // Fetch cart with products and their tax categories
    //     $cart = Cart::with(['items.product.taxCategory'])->where('user_id', $userId)->firstOrFail();
    //     $address = Address::findOrFail($addressId);
    //     $user = User::findOrFail($userId);
    //     if ($cart->items->isEmpty()) {
    //         throw new Exception('Cart is empty');
    //     }

    //     // Calculate base subtotal and prepare product data
    //     $subtotal = 0;
    //     $productDetails = [];

    //     foreach ($cart->items as $item) {
    //         // Determine price based on user role
    //         $unitPrice = $user->isDistributor()
    //             ? ($item->product->distributor_price ?? $item->product->retail_price)
    //             : $item->product->retail_price;

    //         $lineTotal = $unitPrice * $item->quantity;
    //         $subtotal += $lineTotal;

    //         // Get tax rate from product's tax category
    //         $taxRate = $item->product->taxCategory?->rate ?? 0;

    //         $productDetails[] = [
    //             'item' => $item,
    //             'unitPrice' => $unitPrice,
    //             'lineTotal' => $lineTotal,
    //             'taxRate' => $taxRate,
    //             'taxCategoryName' => $item->product->taxCategory?->name ?? 'No Tax',
    //         ];
    //     }

    //     // Apply coupon if provided
    //     $couponDiscount = 0;
    //     $couponData = null;
    //     if ($couponCode) {
    //         $coupon = Coupon::where('code', strtoupper($couponCode))->first();

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

    //     // Calculate subtotal after discount
    //     $subtotalAfterDiscount = $subtotal - $couponDiscount;

    //     // Apply shipping method
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

    //     // Calculate tax PER PRODUCT based on its tax category
    //     $totalTax = 0;
    //     $itemsWithTax = [];
    //     $taxBreakdown = [];
    //     $taxByCategory = [];

    //     foreach ($productDetails as $product) {
    //         // Calculate proportion of this product in the discounted subtotal
    //         $proportion = $subtotal > 0 ? $product['lineTotal'] / $subtotal : 0;

    //         // Apply discount proportionally to each product's taxable value
    //         $discountedLineTotal = $product['lineTotal'] - ($couponDiscount * $proportion);

    //         // Calculate tax based on product's tax category rate
    //         $taxRate = $product['taxRate'];
    //         $gstAmount = ($discountedLineTotal * $taxRate) / 100;
    //         $totalTax += $gstAmount;

    //         // Determine CGST/SGST/IGST based on delivery state
    //         $supplierState = config('app.supplier_state', 'Maharashtra');
    //         $deliveryState = $address->state;
    //         // dd($supplierState, $deliveryState);
    //         if (strtolower($deliveryState) === strtolower($supplierState)) {
    //             $cgst = $gstAmount / 2;
    //             $sgst = $gstAmount / 2;
    //             $igst = 0;
    //         } else {
    //             $cgst = 0;
    //             $sgst = 0;
    //             $igst = $gstAmount;
    //         }

    //         // Add to items with tax
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
    //             'total_tax' => round($gstAmount, 2),
    //             'line_total' => round($discountedLineTotal + $gstAmount, 2),
    //         ];

    //         // Add to tax breakdown
    //         $taxBreakdown[] = [
    //             'product_name' => $product['item']->product->name,
    //             'tax_category' => $product['taxCategoryName'],
    //             'rate' => (string) $taxRate . '%',
    //             'cgst' => round($cgst, 2),
    //             'sgst' => round($sgst, 2),
    //             'igst' => round($igst, 2),
    //         ];

    //         // Group by tax category for summary
    //         $categoryKey = $product['taxCategoryName'] . '_' . $taxRate;
    //         if (!isset($taxByCategory[$categoryKey])) {
    //             $taxByCategory[$categoryKey] = [
    //                 'category' => $product['taxCategoryName'],
    //                 'rate' => $taxRate,
    //                 'taxable_amount' => 0,
    //                 'tax_amount' => 0,
    //                 'cgst' => 0,
    //                 'sgst' => 0,
    //                 'igst' => 0,
    //             ];
    //         }
    //         $taxByCategory[$categoryKey]['taxable_amount'] += $discountedLineTotal;
    //         $taxByCategory[$categoryKey]['tax_amount'] += $gstAmount;
    //         $taxByCategory[$categoryKey]['cgst'] += $cgst;
    //         $taxByCategory[$categoryKey]['sgst'] += $sgst;
    //         $taxByCategory[$categoryKey]['igst'] += $igst;
    //     }

    //     // Calculate subtotal after discount and tax
    //     $subtotalAfterDiscountAndTax = $subtotalAfterDiscount + $totalTax + $shippingCost;

    //     // Handle coin redemption
    //     $coinRedemptionData = null;
    //     $coinsUsed = 0;
    //     $amountRedeemed = 0;
    //     $coinBalance = 0;
    //     $maxCoinsRedeemable = 0;

    //     if ($user->isDistributor()) {
    //         $coinBalance = $this->getCoinBalance($userId);
    //         $maxCoinsRedeemable = min($coinBalance, floor($subtotalAfterDiscountAndTax / 10));

    //         // If coins to redeem is provided
    //         if ($coinsToRedeem != null && $coinsToRedeem > 0) {

    //             // Validate coins
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

    //     // Calculate grand total after coin redemption
    //     $grandTotal = round($subtotalAfterDiscountAndTax - $amountRedeemed, 2);

    //     return [
    //         'subtotal' => round($subtotal, 2),
    //         'coupon_discount' => round($couponDiscount, 2),
    //         'coupon' => $couponData,
    //         'subtotal_after_discount' => round($subtotalAfterDiscount, 2),
    //         'total_tax' => round($totalTax, 2),
    //         'tax_by_category' => array_values($taxByCategory),
    //         'shipping_cost' => round($shippingCost, 2),
    //         'shipping_method' => $shippingData,
    //         'coin_balance' => $coinBalance,
    //         'max_coins_redeemable' => $maxCoinsRedeemable,
    //         'coins_used' => $coinsUsed,
    //         'amount_redeemed' => $amountRedeemed,
    //         'coin_redemption' => $coinRedemptionData,
    //         'subtotal_after_discount_and_tax' => round($subtotalAfterDiscountAndTax, 2),
    //         'grand_total' => $grandTotal,
    //         'items' => $itemsWithTax,
    //         'tax_breakdown' => $taxBreakdown,
    //         'delivery_address' => [
    //             'id' => $address->id,
    //             'full_address' => $this->formatAddress($address),
    //             'state' => $address->state,
    //         ],
    //     ];
    // }

    // public function calculateSummary(int $userId, int $addressId, ?string $couponCode = null, ?int $shippingMethodId = null, ?int $coinsToRedeem = null): array
    // {
    //     // Fetch cart with products and their tax categories
    //     $cart = Cart::with(['items.product.taxCategory'])->where('user_id', $userId)->firstOrFail();
    //     $address = Address::findOrFail($addressId);
    //     $user = User::findOrFail($userId);

    //     if ($cart->items->isEmpty()) {
    //         throw new Exception('Cart is empty');
    //     }

    //     // Calculate base subtotal and prepare product data
    //     $subtotal = 0;
    //     $productDetails = [];

    //     foreach ($cart->items as $item) {
    //         // Determine price based on user role
    //         $unitPrice = $user->isDistributor()
    //             ? ($item->product->distributor_price ?? $item->product->retail_price)
    //             : $item->product->retail_price;

    //         $lineTotal = $unitPrice * $item->quantity;
    //         $subtotal += $lineTotal;

    //         // Get tax rate from product's tax category
    //         $taxRate = $item->product->taxCategory?->rate ?? 0;

    //         $productDetails[] = [
    //             'item' => $item,
    //             'unitPrice' => $unitPrice,
    //             'lineTotal' => $lineTotal,
    //             'taxRate' => $taxRate,
    //             'taxCategoryName' => $item->product->taxCategory?->name ?? 'No Tax',
    //         ];
    //     }

    //     // Apply coupon if provided
    //     $couponDiscount = 0;
    //     $couponData = null;
    //     if ($couponCode) {
    //         $coupon = Coupon::where('code', strtoupper($couponCode))->first();

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

    //     // Calculate subtotal after discount
    //     $subtotalAfterDiscount = $subtotal - $couponDiscount;

    //     // Apply shipping method
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

    //     // Calculate tax PER PRODUCT based on its tax category
    //     $productTaxTotal = 0;
    //     $productGstTotal = 0;
    //     $productOtherTaxTotal = 0;
    //     $itemsWithTax = [];
    //     $taxBreakdown = [];
    //     $taxByCategory = [];

    //     // Individual product tax breakdown
    //     $productTaxBreakdown = [];

    //     foreach ($productDetails as $index => $product) {
    //         // Calculate proportion of this product in the discounted subtotal
    //         $proportion = $subtotal > 0 ? $product['lineTotal'] / $subtotal : 0;

    //         // Apply discount proportionally to each product's taxable value
    //         $discountedLineTotal = $product['lineTotal'] - ($couponDiscount * $proportion);

    //         // Calculate tax based on product's tax category rate
    //         $taxRate = $product['taxRate'];
    //         $taxAmount = ($discountedLineTotal * $taxRate) / 100;
    //         $productTaxTotal += $taxAmount;

    //         // Track GST (18%) separately
    //         if ($taxRate == 18) {
    //             $productGstTotal += $taxAmount;
    //         } else {
    //             $productOtherTaxTotal += $taxAmount;
    //         }

    //         // Add to individual product tax breakdown
    //         $productName = $product['item']->product->name;
    //         $productKey = 'product_' . ($index + 1) . '_' . str_replace(' ', '_', strtolower($productName));

    //         $productTaxBreakdown[$productKey] = [
    //             'product_name' => $productName,
    //             'product_code' => $product['item']->product->product_code,
    //             'quantity' => $product['item']->quantity,
    //             'unit_price' => round($product['unitPrice'], 2),
    //             'tax_category' => $product['taxCategoryName'],
    //             'tax_rate' => (string) $taxRate . '%',
    //             'taxable_value' => round($discountedLineTotal, 2),
    //             'tax_amount' => round($taxAmount, 2),
    //             'line_total_after_tax' => round($discountedLineTotal + $taxAmount, 2),
    //         ];

    //         // Determine CGST/SGST/IGST based on delivery state
    //         $supplierState = config('app.supplier_state', 'Maharashtra');
    //         $deliveryState = $address->state;

    //         if (strtolower($deliveryState) === strtolower($supplierState)) {
    //             $cgst = $taxAmount / 2;
    //             $sgst = $taxAmount / 2;
    //             $igst = 0;
    //         } else {
    //             $cgst = 0;
    //             $sgst = 0;
    //             $igst = $taxAmount;
    //         }

    //         // Add to items with tax
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
    //             'line_total' => round($discountedLineTotal + $taxAmount, 2),
    //         ];

    //         // Add to tax breakdown
    //         $taxBreakdown[] = [
    //             'product_name' => $product['item']->product->name,
    //             'tax_category' => $product['taxCategoryName'],
    //             'rate' => (string) $taxRate . '%',
    //             'cgst' => round($cgst, 2),
    //             'sgst' => round($sgst, 2),
    //             'igst' => round($igst, 2),
    //         ];

    //         // Group by tax category for summary
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
    //                 'is_gst' => ($taxRate == 18)
    //             ];
    //         }
    //         $taxByCategory[$categoryKey]['taxable_amount'] += $discountedLineTotal;
    //         $taxByCategory[$categoryKey]['tax_amount'] += $taxAmount;
    //         $taxByCategory[$categoryKey]['cgst'] += $cgst;
    //         $taxByCategory[$categoryKey]['sgst'] += $sgst;
    //         $taxByCategory[$categoryKey]['igst'] += $igst;
    //     }

    //     // Total tax is only from products
    //     $totalTax = $productTaxTotal;

    //     // Calculate subtotal after discount and tax
    //     $subtotalAfterDiscountAndTax = $subtotalAfterDiscount + $totalTax + $shippingCost;

    //     // Handle coin redemption
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

    //     // Calculate grand total after coin redemption
    //     $grandTotal = round($subtotalAfterDiscountAndTax - $amountRedeemed, 2);

    //     return [
    //         // ============ ORDER SUMMARY ============
    //         'subtotal' => round($subtotal, 2),
    //         'coupon_discount' => round($couponDiscount, 2),
    //         'coupon' => $couponData,
    //         'subtotal_after_discount' => round($subtotalAfterDiscount, 2),

    //         // ============ INDIVIDUAL PRODUCT TAX BREAKDOWN ============
    //         'product_tax_breakdown' => $productTaxBreakdown,

    //         // ============ TAX SUMMARY ============
    //         'tax_summary' => [
    //             'gst_18_percent' => round($productGstTotal, 2),        // 18% GST from products
    //             'other_tax' => round($productOtherTaxTotal, 2),          // Other taxes from products (5%, 12%, 28%)
    //             'total_product_tax' => round($productTaxTotal, 2),       // Sum of all product taxes
    //         ],

    //         // Grand total tax
    //         'total_tax' => round($totalTax, 2),

    //         // Tax by category (from products only)
    //         'tax_by_category' => array_values($taxByCategory),

    //         // ============ SHIPPING ============
    //         'shipping_cost' => round($shippingCost, 2),
    //         'shipping_method' => $shippingData,

    //         // ============ SUBTOTAL AFTER ALL CHARGES ============
    //         'subtotal_after_discount_and_tax' => round($subtotalAfterDiscountAndTax, 2),

    //         // ============ COIN REDEMPTION ============
    //         'coin_balance' => $coinBalance,
    //         'max_coins_redeemable' => $maxCoinsRedeemable,
    //         'coins_used' => $coinsUsed,
    //         'amount_redeemed' => $amountRedeemed,
    //         'coin_redemption' => $coinRedemptionData,

    //         // ============ FINAL TOTAL ============
    //         'grand_total' => $grandTotal,

    //         // ============ DETAILED BREAKDOWNS ============
    //         'items' => $itemsWithTax,
    //         'tax_breakdown' => $taxBreakdown,
    //         'delivery_address' => [
    //             'id' => $address->id,
    //             'full_address' => $this->formatAddress($address),
    //             'state' => $address->state,
    //         ],

    //         // ============ SUMMARY (Easy to read) ============
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
    //         ],
    //     ];
    // }

    public function calculateSummary(int $userId, int $addressId, ?string $couponCode = null, ?int $shippingMethodId = null, ?int $coinsToRedeem = null): array
    {
        // Fetch cart with products, their tax categories, and images
        $cart = Cart::with(['items.product.taxCategory', 'items.product.images'])->where('user_id', $userId)->firstOrFail();
        $address = Address::findOrFail($addressId);
        $user = User::findOrFail($userId);

        if ($cart->items->isEmpty()) {
            throw new Exception('Cart is empty');
        }

        // Calculate base subtotal and prepare product data
        $subtotal = 0;
        $productDetails = [];

        foreach ($cart->items as $item) {
            // Determine price based on user role
            $unitPrice = $user->isDistributor()
                ? ($item->product->distributor_price ?? $item->product->retail_price)
                : $item->product->retail_price;

            $lineTotal = $unitPrice * $item->quantity;
            $subtotal += $lineTotal;

            // Get tax rate from product's tax category
            $taxRate = $item->product->taxCategory?->rate ?? 0;

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

            $productDetails[] = [
                'item' => $item,
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineTotal,
                'taxRate' => $taxRate,
                'taxCategoryName' => $item->product->taxCategory?->name ?? 'No Tax',
                'images' => $productImages,
                'primary_image' => $item->product->primaryImage ? asset('storage/' . $item->product->primaryImage->image) : null,
            ];
        }

        // Apply coupon if provided
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

        // Calculate subtotal after discount
        $subtotalAfterDiscount = $subtotal - $couponDiscount;

        // Apply shipping method
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

        // Calculate tax PER PRODUCT based on its tax category
        $productTaxTotal = 0;
        $productGstTotal = 0;
        $productOtherTaxTotal = 0;
        $itemsWithTax = [];
        $taxBreakdown = [];
        $taxByCategory = [];

        // Individual product tax breakdown
        $productTaxBreakdown = [];

        foreach ($productDetails as $index => $product) {
            // Calculate proportion of this product in the discounted subtotal
            $proportion = $subtotal > 0 ? $product['lineTotal'] / $subtotal : 0;

            // Apply discount proportionally to each product's taxable value
            $discountedLineTotal = $product['lineTotal'] - ($couponDiscount * $proportion);

            // Calculate tax based on product's tax category rate
            $taxRate = $product['taxRate'];
            $taxAmount = ($discountedLineTotal * $taxRate) / 100;
            $productTaxTotal += $taxAmount;

            // Track GST (18%) separately
            if ($taxRate == 18) {
                $productGstTotal += $taxAmount;
            } else {
                $productOtherTaxTotal += $taxAmount;
            }

            // Add to individual product tax breakdown
            $productName = $product['item']->product->name;
            $productKey = 'product_' . ($index + 1) . '_' . str_replace(' ', '_', strtolower($productName));

            $productTaxBreakdown[$productKey] = [
                'product_id' => $product['item']->product_id,
                'product_name' => $productName,
                'product_code' => $product['item']->product->product_code,
                'quantity' => $product['item']->quantity,
                'unit_price' => round($product['unitPrice'], 2),
                'tax_category' => $product['taxCategoryName'],
                'tax_rate' => (string) $taxRate . '%',
                'taxable_value' => round($discountedLineTotal, 2),
                'tax_amount' => round($taxAmount, 2),
                'line_total_after_tax' => round($discountedLineTotal + $taxAmount, 2),
                // Add images here
                'images' => $product['images'],
                'primary_image' => $product['primary_image'],
            ];

            // Determine CGST/SGST/IGST based on delivery state
            $supplierState = config('app.supplier_state', 'Maharashtra');
            $deliveryState = $address->state;

            if (strtolower($deliveryState) === strtolower($supplierState)) {
                $cgst = $taxAmount / 2;
                $sgst = $taxAmount / 2;
                $igst = 0;
            } else {
                $cgst = 0;
                $sgst = 0;
                $igst = $taxAmount;
            }

            // Add to items with tax
            $itemsWithTax[] = [
                'product_id' => $product['item']->product_id,
                'product_name' => $product['item']->product->name,
                'product_code' => $product['item']->product->product_code,
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
                // Add images here too
                'images' => $product['images'],
                'primary_image' => $product['primary_image'],
            ];

            // Add to tax breakdown
            $taxBreakdown[] = [
                'product_name' => $product['item']->product->name,
                'tax_category' => $product['taxCategoryName'],
                'rate' => (string) $taxRate . '%',
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
            ];

            // Group by tax category for summary
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
                    'is_gst' => ($taxRate == 18)
                ];
            }
            $taxByCategory[$categoryKey]['taxable_amount'] += $discountedLineTotal;
            $taxByCategory[$categoryKey]['tax_amount'] += $taxAmount;
            $taxByCategory[$categoryKey]['cgst'] += $cgst;
            $taxByCategory[$categoryKey]['sgst'] += $sgst;
            $taxByCategory[$categoryKey]['igst'] += $igst;
        }

        // Total tax is only from products
        $totalTax = $productTaxTotal;

        // Calculate subtotal after discount and tax
        $subtotalAfterDiscountAndTax = $subtotalAfterDiscount + $totalTax + $shippingCost;

        // Handle coin redemption
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

        // Calculate grand total after coin redemption
        $grandTotal = round($subtotalAfterDiscountAndTax - $amountRedeemed, 2);

        return [
            // ============ ORDER SUMMARY ============
            'subtotal' => round($subtotal, 2),
            'coupon_discount' => round($couponDiscount, 2),
            'coupon' => $couponData,
            'subtotal_after_discount' => round($subtotalAfterDiscount, 2),

            // ============ INDIVIDUAL PRODUCT TAX BREAKDOWN ============
            'product_tax_breakdown' => $productTaxBreakdown,

            // ============ TAX SUMMARY ============
            'tax_summary' => [
                'gst_18_percent' => round($productGstTotal, 2),
                'other_tax' => round($productOtherTaxTotal, 2),
                'total_product_tax' => round($productTaxTotal, 2),
            ],

            // Grand total tax
            'total_tax' => round($totalTax, 2),

            // Tax by category (from products only)
            'tax_by_category' => array_values($taxByCategory),

            // ============ SHIPPING ============
            'shipping_cost' => round($shippingCost, 2),
            'shipping_method' => $shippingData,

            // ============ SUBTOTAL AFTER ALL CHARGES ============
            'subtotal_after_discount_and_tax' => round($subtotalAfterDiscountAndTax, 2),

            // ============ COIN REDEMPTION ============
            'coin_balance' => $coinBalance,
            'max_coins_redeemable' => $maxCoinsRedeemable,
            'coins_used' => $coinsUsed,
            'amount_redeemed' => $amountRedeemed,
            'coin_redemption' => $coinRedemptionData,

            // ============ FINAL TOTAL ============
            'grand_total' => $grandTotal,

            // ============ DETAILED BREAKDOWNS ============
            'items' => $itemsWithTax,
            'tax_breakdown' => $taxBreakdown,
            'delivery_address' => [
                'id' => $address->id,
                'full_address' => $this->formatAddress($address),
                'state' => $address->state,
            ],

            // ============ SUMMARY (Easy to read) ============
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
            ],
        ];
    }




    /**
     * Calculate coupon discount
     */
    private function calculateCouponDiscount(Coupon $coupon, float $subtotal): float
    {
        // Check minimum order requirement
        if ($coupon->min_order && $subtotal < $coupon->min_order) {
            throw new Exception("Minimum order amount of ₹" . $coupon->min_order . " required for this coupon");
        }

        $discount = 0;

        if ($coupon->type === 'percentage') {
            $discount = ($subtotal * $coupon->value) / 100;
        } else { // fixed
            $discount = $coupon->value;
        }

        // Apply maximum discount limit if set
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
        // Check if coupon has usage limit
        if ($coupon->max_uses && $coupon->used_count >= $coupon->max_uses) {
            throw new Exception('This coupon has reached its usage limit');
        }

        // Check if user has already used this coupon
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

        // Simulate authorization from Commission API
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


    // public function placeOrder(int $userId, array $data): array
    // {
    //     $address = Address::findOrFail($data['address_id']);
    //     $cart = Cart::with('items.product')->where('user_id', $userId)->firstOrFail();
    //     $user = User::findOrFail($userId);

    //     // Check stock
    //     foreach ($cart->items as $item) {
    //         if ($item->product->stock_quantity < $item->quantity) {
    //             throw new Exception("Insufficient stock for: {$item->product->name}");
    //         }
    //     }

    //     $summary = $this->calculateSummary($userId, $data['address_id']);

    //     $coinRedemption = null;
    //     if (isset($data['redemption_id'])) {
    //         $coinRedemption = CoinRedemption::where('id', $data['redemption_id'])
    //             ->where('user_id', $userId)
    //             ->where('status', 'authorized')
    //             ->first();
    //         if (!$coinRedemption) {
    //             throw new Exception('Invalid or expired coin redemption');
    //         }

    //         // Verify that the redemption amount matches what's in the summary
    //         if ($coinRedemption->amount_redeemed != $summary['amount_redeemed']) {
    //             throw new Exception('Coin redemption amount mismatch');
    //         }
    //     }

    //     return DB::transaction(function () use ($user, $address, $cart, $summary, $coinRedemption, $data) {
    //         $order = Order::create([
    //             'order_reference' => 'ORD-' . strtoupper(uniqid()),
    //             'user_id' => $user->id,
    //             'billing_address_id' => $data['address_id'],
    //             'delivery_address_id' => $data['address_id'],
    //             'order_type' => $user->isDistributor() ? 'distributor' : 'retail',
    //             'subtotal' => $summary['subtotal'],
    //             'total_gst' => $summary['total_tax'],
    //             'shipping_charge' => $summary['shipping_cost'], // You had this as 0, should use actual shipping cost
    //             'coin_redeemed' => $summary['coins_used'], // Use from summary
    //             'coin_redeemed_amount' => $summary['amount_redeemed'], // Add this field to orders table
    //             'total_payable' => $summary['grand_total'], // This already includes coin deduction
    //             'amount_paid' => 0,
    //             'status' => 'pending',
    //             'tax_breakdown' => json_encode($summary['tax_breakdown']),
    //         ]);
    //         // Create order lines
    //         foreach ($summary['items'] as $itemData) {
    //             $product = Product::find($itemData['product_id']);
    //             // dd($itemData);
    //             OrderLine::create([
    //                 'order_id' => $order->id,
    //                 'product_id' => $product->id,
    //                 'quantity' => $itemData['quantity'],
    //                 'unit_price' => $itemData['unit_price'],
    //                 'gst_rate' => (float) str_replace('%', '', $itemData['tax_rate']),
    //                 // 'gst_rate' => $itemData['tax_rate'],
    //                 'gst_amount' => $itemData['total_tax'],
    //                 'line_total' => $itemData['line_total'],
    //                 'commissionable_volume' => $product->commissionable_volume ?? 0,
    //             ]);
    //         }

    //         // Update coin redemption with order
    //         if ($coinRedemption) {
    //             $coinRedemption->update([
    //                 'order_id' => $order->id,
    //                 'status' => 'used' // Update status to used
    //             ]);
    //         }

    //         // Clear cart
    //         $cart->items()->delete();

    //         // Create Razorpay order
    //         $razorpayOrder = $this->razorpayService->createOrder($order);

    //         return [
    //             'order_id' => $order->id,
    //             'order_reference' => $order->order_reference,
    //             'amount' => $order->total_payable,
    //             'razorpay_order_id' => $razorpayOrder['id'],
    //             'razorpay_key' => config('services.razorpay.key_id'),
    //             'status' => 'pending',
    //             'summary' => $summary, // Optional: return summary for verification
    //         ];
    //     });
    // }

    public function placeOrder(int $userId, array $data): array
    {
        $address = Address::findOrFail($data['address_id']);
        $cart = Cart::with('items.product')->where('user_id', $userId)->firstOrFail();
        $user = User::findOrFail($userId);

        // Check stock
        foreach ($cart->items as $item) {
            if ($item->product->stock_quantity < $item->quantity) {
                throw new Exception("Insufficient stock for: {$item->product->name}");
            }
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

        return DB::transaction(function () use ($user, $address, $cart, $coinRedemption, $coinsUsed, $coinRedeemedAmount, $grandTotal, $data) {

            // Calculate totals from cart
            $subtotal = 0;
            $totalTax = 0;
            $orderItemsData = [];

            foreach ($cart->items as $item) {
                $unitPrice = $user->isDistributor()
                    ? ($item->product->distributor_price ?? $item->product->retail_price)
                    : $item->product->retail_price;

                $lineTotal = $unitPrice * $item->quantity;
                $subtotal += $lineTotal;

                $taxRate = $item->product->taxCategory?->rate ?? 0;
                $taxAmount = ($lineTotal * $taxRate) / 100;
                $totalTax += $taxAmount;

                $orderItemsData[] = [
                    'item' => $item,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal + $taxAmount,
                ];
            }

            $shippingCharge = $data['shipping_cost'] ?? 0;

            $order = Order::create([
                'order_reference' => 'ORD-' . strtoupper(uniqid()),
                'user_id' => $user->id,
                'billing_address_id' => $data['address_id'],
                'delivery_address_id' => $data['address_id'],
                'order_type' => $user->isDistributor() ? 'distributor' : 'retail',
                'subtotal' => $subtotal,
                'total_gst' => $totalTax,
                'shipping_charge' => $shippingCharge,
                'coin_redeemed' => $coinsUsed,
                'coin_redeemed_amount' => $coinRedeemedAmount,
                'total_payable' => $grandTotal,
                'amount_paid' => 0,
                'status' => 'pending',
                'tax_breakdown' => json_encode([]),
            ]);

            // Create order lines
            foreach ($orderItemsData as $itemData) {
                OrderLine::create([
                    'order_id' => $order->id,
                    'product_id' => $itemData['item']->product_id,
                    'quantity' => $itemData['item']->quantity,
                    'unit_price' => $itemData['unit_price'],
                    'gst_rate' => $itemData['tax_rate'],
                    'gst_amount' => $itemData['tax_amount'],
                    'line_total' => $itemData['line_total'],
                    'commissionable_volume' => $itemData['item']->product->commissionable_volume ?? 0,
                ]);
            }

            // Update coin redemption with order
            if ($coinRedemption) {
                $coinRedemption->update([
                    'order_id' => $order->id,
                    'status' => 'used'
                ]);
            }

            // Create Razorpay order
            $razorpayOrder = $this->razorpayService->createOrder($order);

            return [
                'order_id' => $order->id,
                'order_reference' => $order->order_reference,
                'amount' => $order->total_payable,
                'razorpay_order_id' => $razorpayOrder['id'],
                'razorpay_key' => config('services.razorpay.key_id'),
                'status' => 'pending',
            ];
        });
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

    //         // Decrement stock
    //         foreach ($order->lines as $line) {
    //             $product = $line->product;
    //             $product->decrement('stock_quantity', $line->quantity);

    //             StockMovement::create([
    //                 'product_id' => $product->id,
    //                 'quantity' => -$line->quantity,
    //                 'available_quantity_after' => $product->stock_quantity,
    //                 'reason' => 'Order confirmed: ' . $order->order_reference,
    //                 'order_id' => $order->id,
    //             ]);
    //         }

    //         // Generate invoice
    //         $invoice = $this->invoiceService->generateInvoice($order);

    //         // Queue commission event (optional)
    //         CommissionApiEvent::create([
    //             'event_type' => 'order_post',
    //             'order_id' => $order->id,
    //             'payload' => json_encode([
    //                 'order_reference' => $order->order_reference,
    //                 'user_id' => $order->user_id,
    //                 'total' => $order->total_payable,
    //             ]),
    //             'status' => 'pending',
    //         ]);

    //         return [
    //             'success' => true,
    //             'order_id' => $order->id,
    //             'order_reference' => $order->order_reference,
    //             'status' => 'confirmed',
    //             'invoice_number' => $invoice->invoice_number,
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
    //         $order->update([
    //             'status' => 'confirmed',
    //             'confirmed_at' => now(),
    //             'payment_gateway' => $gatewayData['gateway'],
    //             'gateway_transaction_id' => $gatewayData['transaction_id'],
    //             'amount_paid' => $order->total_payable,
    //         ]);

    //         // Decrement stock
    //         foreach ($order->lines as $line) {
    //             $product = $line->product;
    //             $product->decrement('stock_quantity', $line->quantity);

    //             StockMovement::create([
    //                 'product_id' => $product->id,
    //                 'quantity' => -$line->quantity,
    //                 'available_quantity_after' => $product->stock_quantity,
    //                 'reason' => 'Order confirmed: ' . $order->order_reference,
    //                 'order_id' => $order->id,
    //             ]);
    //         }

    //         // ============ ADD: Delete cart after successful payment ============
    //         $cart = Cart::where('user_id', $order->user_id)->first();
    //         if ($cart) {
    //             $cart->items()->delete();
    //             // Optionally delete the cart itself
    //             $cart->delete();
    //         }

    //         // Generate invoice
    //         $invoice = $this->invoiceService->generateInvoice($order);

    //         // Queue commission event (optional)
    //         CommissionApiEvent::create([
    //             'event_type' => 'order_post',
    //             'order_id' => $order->id,
    //             'payload' => json_encode([
    //                 'order_reference' => $order->order_reference,
    //                 'user_id' => $order->user_id,
    //                 'total' => $order->total_payable,
    //             ]),
    //             'status' => 'pending',
    //         ]);

    //         return [
    //             'success' => true,
    //             'order_id' => $order->id,
    //             'order_reference' => $order->order_reference,
    //             'status' => 'confirmed',
    //             'invoice_number' => $invoice->invoice_number,
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
            $order->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'payment_gateway' => $gatewayData['gateway'],
                'gateway_transaction_id' => $gatewayData['transaction_id'],
                'amount_paid' => $order->total_payable,
            ]);

            // Decrement stock
            foreach ($order->lines as $line) {
                $product = $line->product;
                $product->decrement('stock_quantity', $line->quantity);

                StockMovement::create([
                    'product_id' => $product->id,
                    'quantity' => -$line->quantity,
                    'available_quantity_after' => $product->stock_quantity,
                    'reason' => 'Order confirmed: ' . $order->order_reference,
                    'order_id' => $order->id,
                ]);
            }

            // Generate invoice using stored summary data
            $invoice = $this->invoiceService->generateInvoice($order);

            // Generate PDF and send email
            try {
                $this->pdfInvoiceService->generateAndSendInvoice($order, $invoice);
            } catch (\Exception $e) {
                Log::error('Failed to send invoice email: ' . $e->getMessage(), [
                    'order_id' => $order->id
                ]);
            }

            // // Queue commission event
            // CommissionApiEvent::create([
            //     'event_type' => 'order_post',
            //     'order_id' => $order->id,
            //     'payload' => json_encode([
            //         'order_reference' => $order->order_reference,
            //         'user_id' => $order->user_id,
            //         'total' => $order->total_payable,
            //     ]),
            //     'status' => 'pending',
            // ]);

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

            return [
                'success' => true,
                'order_id' => $order->id,
                'order_reference' => $order->order_reference,
                'status' => 'confirmed',
                'invoice_number' => $invoice->invoice_number,
            ];
        });
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
            'eventId' => 'evt_' . \Illuminate\Support\Str::random(24),
            'action' => 'ORDER_PLACED',
            'orderReference' => $order->order_reference,
            'purchaserIdentifier' => $user->distributor_id ?? $user->id,
            'accountType' => $user->isDistributor() ? 'DISTRIBUTOR' : 'CUSTOMER',
            'eventTimestamp' => now()->toIso8601String(),
            'lines' => $lines,
            'totalOrderValue' => $order->total_payable + ($order->coin_redeemed_amount ?? 0), // Full value before coins
        ];
    }

    protected function getSummaryData(array $data, int $userId, int $addressId): array
    {
        // If items are provided in the request, use them
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

        // Otherwise calculate from cart
        return $this->calculateSummary(
            $userId,
            $addressId,
            $data['coupon_code'] ?? null,
            $data['shipping_method_id'] ?? null,
            $data['coins'] ?? null
        );
    }

    // ============================================================
    // Helper methods
    // ============================================================

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

    protected function getCoinBalance(int $userId): int
    {
        // TODO: Call Commission API or use cached value
        return 500; // mock
    }

    protected function getCurrentCartTotal(int $userId): float
    {
        $cart = Cart::with('items.product')->where('user_id', $userId)->first();
        if (!$cart) return 0;
        $total = 0;
        foreach ($cart->items as $item) {
            $price = $item->product->retail_price;
            $total += $price * $item->quantity;
        }
        return $total;
    }

    protected function authorizeCoinRedemption(int $userId, int $coins, float $amount): array
    {
        // TODO: Call Commission API to authorize
        return [
            'id' => 'AUTH-' . strtoupper(uniqid()),
            'status' => 'authorized',
            'coins' => $coins,
            'amount' => $amount,
        ];
    }
}