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

    public function __construct(
        GSTCalculator $gstCalculator,
        InvoiceService $invoiceService,
        RazorpayService $razorpayService
    ) {
        $this->gstCalculator = $gstCalculator;
        $this->invoiceService = $invoiceService;
        $this->razorpayService = $razorpayService;
    }

    /**
     * FR-CO-003: Calculate order summary
     */
    /**
     * Calculate cart summary with coupon and shipping
     */
    public function calculateSummary(int $userId, int $addressId, ?string $couponCode = null, ?int $shippingMethodId = null): array
    {
        $cart = Cart::with(['items.product.taxCategory'])->where('user_id', $userId)->firstOrFail();
        $address = Address::findOrFail($addressId);
        $user = User::findOrFail($userId);

        if ($cart->items->isEmpty()) {
            throw new Exception('Cart is empty');
        }

        // Calculate base subtotal (without tax)
        $subtotal = 0;
        $items = [];
        $productDetails = [];

        foreach ($cart->items as $item) {
            $unitPrice = $user->isDistributor()
                ? ($item->product->distributor_price ?? $item->product->retail_price)
                : $item->product->retail_price;

            $lineTotal = $unitPrice * $item->quantity;
            $subtotal += $lineTotal;

            // Store product details for tax calculation later
            $productDetails[] = [
                'item' => $item,
                'unitPrice' => $unitPrice,
                'lineTotal' => $lineTotal,
                'taxRate' => $item->product->taxCategory?->rate ?? 0,
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

        // Calculate subtotal after discount (before tax)
        $subtotalAfterDiscount = $subtotal - $couponDiscount;

        // Apply shipping method
        $shippingCost = 0;
        $shippingData = null;
        if ($shippingMethodId) {
            $shippingMethod = ShippingMethod::find($shippingMethodId);

            if ($shippingMethod && $shippingMethod->is_active) {
                // Check if order amount meets minimum requirement
                if ($shippingMethod->min_order_amount && $subtotalAfterDiscount < $shippingMethod->min_order_amount) {
                    throw new Exception("Minimum order amount for this shipping method is ₹" . $shippingMethod->min_order_amount);
                }

                // Check max order amount
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

        // Calculate tax on discounted amount (subtotal_after_discount)
        // Distribute tax proportionally across products
        $totalTax = 0;
        $taxBreakdown = [];
        $itemsWithTax = [];

        foreach ($productDetails as $product) {
            // Calculate proportion of this product in the discounted subtotal
            $proportion = $subtotal > 0 ? $product['lineTotal'] / $subtotal : 0;

            // Apply discount proportionally to each product's taxable value
            $discountedLineTotal = $product['lineTotal'] - ($couponDiscount * $proportion);

            // Calculate tax on discounted amount
            $taxRate = $product['taxRate'];
            $gstAmount = ($discountedLineTotal * $taxRate) / 100;
            $totalTax += $gstAmount;

            // Determine CGST/SGST/IGST based on delivery state
            $supplierState = config('app.supplier_state', 'Maharashtra');
            $deliveryState = $address->state;

            if (strtolower($deliveryState) === strtolower($supplierState)) {
                $cgst = $gstAmount / 2;
                $sgst = $gstAmount / 2;
                $igst = 0;
            } else {
                $cgst = 0;
                $sgst = 0;
                $igst = $gstAmount;
            }

            $itemsWithTax[] = [
                'product_id'      => $product['item']->product_id,
                'product_name'    => $product['item']->product->name,
                'product_code'    => $product['item']->product->product_code,
                'quantity'        => $product['item']->quantity,
                'unit_price'      => round($product['unitPrice'], 2),
                'taxable_value'   => round($discountedLineTotal, 2),
                'gst_rate'        => (string) $taxRate,
                'cgst'            => round($cgst, 2),
                'sgst'            => round($sgst, 2),
                'igst'            => round($igst, 2),
                'total_tax'       => round($gstAmount, 2),
                'line_total'      => round($discountedLineTotal + $gstAmount, 2),
            ];

            $taxBreakdown[] = [
                'product_name' => $product['item']->product->name,
                'rate' => (string) $taxRate,
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
            ];
        }

        // Calculate grand total: subtotal_after_discount + tax + shipping
        $grandTotal = round($subtotalAfterDiscount + $totalTax + $shippingCost, 2);

        // Coin balance for distributors
        $coinBalance = 0;
        $maxCoinsRedeemable = 0;
        if ($user->isDistributor()) {
            $coinBalance = $this->getCoinBalance($userId);
            $maxCoinsRedeemable = min($coinBalance, floor($grandTotal / 10));
        }

        return [
            'subtotal' => round($subtotal, 2),
            'coupon_discount' => round($couponDiscount, 2),
            'coupon' => $couponData,
            'subtotal_after_discount' => round($subtotalAfterDiscount, 2),
            'total_tax' => round($totalTax, 2),
            'shipping_cost' => round($shippingCost, 2),
            'shipping_method' => $shippingData,
            'grand_total' => $grandTotal,
            'coin_balance' => $coinBalance,
            'max_coins_redeemable' => $maxCoinsRedeemable,
            'items' => $itemsWithTax,
            'tax_breakdown' => $taxBreakdown,
            'delivery_address' => [
                'id' => $address->id,
                'full_address' => $this->formatAddress($address),
                'state' => $address->state,
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
    public function applyCoupon(int $userId, int $addressId, string $couponCode): array
    {
        return $this->calculateSummary($userId, $addressId, $couponCode, null);
    }

    /**
     * Apply shipping method
     */
    public function applyShipping(int $userId, int $addressId, int $shippingMethodId, ?string $couponCode = null): array
    {
        return $this->calculateSummary($userId, $addressId, $couponCode, $shippingMethodId);
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

    /**
     * FR-CO-005: Place order and initiate Razorpay payment
     */
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

        $summary = $this->calculateSummary($userId, $data['address_id']);

        $coinRedemption = null;
        if (isset($data['redemption_id'])) {
            $coinRedemption = CoinRedemption::where('id', $data['redemption_id'])
                ->where('user_id', $userId)
                ->where('status', 'authorized')
                ->first();
            if (!$coinRedemption) {
                throw new Exception('Invalid or expired coin redemption');
            }
        }

        return DB::transaction(function () use ($user, $address, $cart, $summary, $coinRedemption, $data) {
            $order = Order::create([
                'order_reference' => 'ORD-' . strtoupper(uniqid()),
                'user_id' => $user->id,
                'billing_address_id' => $data['address_id'],
                'delivery_address_id' => $data['address_id'],
                'order_type' => $user->isDistributor() ? 'distributor' : 'retail',
                'subtotal' => $summary['subtotal'],
                'total_gst' => $summary['total_tax'],
                'shipping_charge' => 0,
                'coin_redeemed' => $coinRedemption ? $coinRedemption->amount_redeemed : 0,
                'total_payable' => $summary['grand_total'] - ($coinRedemption ? $coinRedemption->amount_redeemed : 0),
                'amount_paid' => 0,
                'status' => 'pending',
                'tax_breakdown' => json_encode($summary['tax_breakdown']),
            ]);

            // Create order lines
            foreach ($summary['items'] as $itemData) {
                $product = Product::find($itemData['product_id']);
                OrderLine::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'gst_rate' => $itemData['gst_rate'],
                    'gst_amount' => $itemData['total_tax'],
                    'line_total' => $itemData['line_total'],
                    'commissionable_volume' => $product->commissionable_volume ?? 0,
                ]);
            }

            // Update coin redemption with order
            if ($coinRedemption) {
                $coinRedemption->update(['order_id' => $order->id]);
            }

            // Clear cart
            $cart->items()->delete();

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

            // Generate invoice
            $invoice = $this->invoiceService->generateInvoice($order);

            // Queue commission event (optional)
            CommissionApiEvent::create([
                'event_type' => 'order_post',
                'order_id' => $order->id,
                'payload' => json_encode([
                    'order_reference' => $order->order_reference,
                    'user_id' => $order->user_id,
                    'total' => $order->total_payable,
                ]),
                'status' => 'pending',
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