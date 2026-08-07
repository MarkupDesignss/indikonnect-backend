<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Invoice;
use App\Models\CommissionApiEvent;
use App\Models\CoinRedemption;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckoutService
{
    protected $gstCalculator;
    protected $invoiceService;

    public function __construct(GSTCalculator $gstCalculator, InvoiceService $invoiceService)
    {
        $this->gstCalculator = $gstCalculator;
        $this->invoiceService = $invoiceService;
    }

    /**
     * FR-CO-003: Calculate order summary with itemised GST
     */
    public function calculateSummary(int $userId, int $addressId): array
    {
        $cart = Cart::with(['items.product.taxCategory'])->where('user_id', $userId)->firstOrFail();
        $address = Address::findOrFail($addressId);
        $user = User::findOrFail($userId);

        if ($cart->items->isEmpty()) {
            throw new \Exception('Cart is empty');
        }

        $subtotal = 0;
        $totalTax = 0;
        $taxBreakdown = [];
        $items = [];

        foreach ($cart->items as $item) {
            // Determine price based on user type (FR-ST-011)
            $unitPrice = $user->isDistributor() 
                ? ($item->product->distributor_price ?? $item->product->retail_price)
                : $item->product->retail_price;

            $lineTotal = $unitPrice * $item->quantity;
            $subtotal += $lineTotal;

            // Calculate GST (FR-CO-003)
            $taxCategory = $item->product->taxCategory;
            $taxRate = $taxCategory?->rate ?? 0;

            $gstAmount = ($lineTotal * $taxRate) / 100;
            $totalTax += $gstAmount;

            // Split into CGST/SGST or IGST (FR-CO-003)
            $supplierState = config('app.supplier_state', 'Maharashtra');
            $deliveryState = $address->state;

            if (strtolower($deliveryState) === strtolower($supplierState)) {
                // Intra-state: CGST + SGST (half each)
                $cgst = $gstAmount / 2;
                $sgst = $gstAmount / 2;
                $igst = 0;
            } else {
                // Inter-state: IGST
                $cgst = 0;
                $sgst = 0;
                $igst = $gstAmount;
            }

            $items[] = [
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'product_code' => $item->product->product_code,
                'quantity' => $item->quantity,
                'unit_price' => round($unitPrice, 2),
                'taxable_value' => round($lineTotal, 2),
                'gst_rate' => $taxRate,
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
                'total_tax' => round($gstAmount, 2),
                'line_total' => round($lineTotal + $gstAmount, 2),
            ];

            $taxBreakdown[] = [
                'product_name' => $item->product->name,
                'rate' => $taxRate,
                'cgst' => round($cgst, 2),
                'sgst' => round($sgst, 2),
                'igst' => round($igst, 2),
            ];
        }

        $grandTotal = round($subtotal + $totalTax, 2);

        // Get coin balance for distributors (FR-CO-004)
        $coinBalance = 0;
        $maxCoinsRedeemable = 0;
        if ($user->isDistributor()) {
            $coinBalance = $this->getCoinBalance($userId);
            $maxCoinsRedeemable = min($coinBalance, $grandTotal * 10); // 1 coin = ₹10 (example)
        }

        return [
            'subtotal' => round($subtotal, 2),
            'total_tax' => round($totalTax, 2),
            'grand_total' => $grandTotal,
            'coin_balance' => $coinBalance,
            'max_coins_redeemable' => $maxCoinsRedeemable,
            'items' => $items,
            'tax_breakdown' => $taxBreakdown,
            'delivery_address' => [
                'id' => $address->id,
                'full_address' => $this->formatAddress($address),
                'state' => $address->state,
            ],
        ];
    }

    /**
     * FR-CO-004: Apply coin redemption
     */
    public function applyCoins(int $userId, int $coinsToRedeem): array
    {
        $user = User::findOrFail($userId);

        if (!$user->isDistributor()) {
            throw new \Exception('Coin redemption is only available for distributors');
        }

        // Get coin balance from Commission API or cached value
        $coinBalance = $this->getCoinBalance($userId);

        if ($coinsToRedeem > $coinBalance) {
            throw new \Exception('Insufficient coin balance');
        }

        // Validate against order value (if we have a pending order)
        // This is simplified - in production, pass the order total
        $orderTotal = 1000; // Placeholder - get from cart
        $maxRedeemable = $orderTotal * 10; // 1 coin = ₹10

        if ($coinsToRedeem > $maxRedeemable) {
            throw new \Exception('Cannot redeem more than order value');
        }

        $amountRedeemed = $coinsToRedeem * 10; // 1 coin = ₹10 (configurable)

        // Authorize redemption with Commission API (FR-CO-004)
        $authorization = $this->authorizeCoinRedemption($userId, $coinsToRedeem, $amountRedeemed);

        // Save redemption record
        $redemption = CoinRedemption::create([
            'user_id' => $userId,
            'order_id' => null, // Will be updated when order is placed
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
            'authorization_id' => $authorization['id'],
            'redemption_id' => $redemption->id,
        ];
    }

    /**
     * FR-CO-005: Create pending order and initiate payment
     */
    public function placeOrder(int $userId, array $data): array
    {
        $address = Address::findOrFail($data['address_id']);
        $cart = Cart::with('items.product')->where('user_id', $userId)->firstOrFail();
        $user = User::findOrFail($userId);

        // Validate stock availability (FR-ST-009)
        foreach ($cart->items as $item) {
            if ($item->product->stock_quantity < $item->quantity) {
                throw new \Exception("Insufficient stock for product: {$item->product->name}");
            }
        }

        // Calculate summary
        $summary = $this->calculateSummary($userId, $data['address_id']);

        // Apply coins if any
        $coinRedemption = null;
        if (isset($data['redemption_id'])) {
            $coinRedemption = CoinRedemption::where('id', $data['redemption_id'])
                ->where('user_id', $userId)
                ->where('status', 'authorized')
                ->first();

            if (!$coinRedemption) {
                throw new \Exception('Invalid or expired coin redemption');
            }
        }

        return DB::transaction(function () use ($user, $address, $cart, $summary, $coinRedemption, $data) {
            // Create pending order (FR-CO-005)
            $order = Order::create([
                'order_reference' => $this->generateOrderReference(),
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

            // Create order lines (FR-CO-005)
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

            // Update coin redemption with order ID
            if ($coinRedemption) {
                $coinRedemption->update(['order_id' => $order->id]);
            }

            // Clear cart after order placement
            $cart->items()->delete();

            // Initiate payment gateway (FR-CO-005)
            $gatewayService = app('App\Services\PaymentGateway\RazorpayService');
            $paymentOrder = $gatewayService->createOrder($order);

            return [
                'order_id' => $order->id,
                'order_reference' => $order->order_reference,
                'amount' => $order->total_payable,
                'gateway_order_id' => $paymentOrder['id'],
                'gateway_redirect_url' => $paymentOrder['redirect_url'] ?? null,
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
            // Update order status
            $order->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'payment_gateway' => $gatewayData['gateway'],
                'gateway_transaction_id' => $gatewayData['transaction_id'],
                'amount_paid' => $order->total_payable,
            ]);

            // Decrement stock (FR-ST-009)
            foreach ($order->lines as $line) {
                $product = $line->product;
                $product->decrement('stock_quantity', $line->quantity);

                // Log stock movement
                \App\Models\StockMovement::create([
                    'product_id' => $product->id,
                    'quantity' => -$line->quantity,
                    'available_quantity_after' => $product->stock_quantity,
                    'reason' => 'Order confirmed: ' . $order->order_reference,
                    'order_id' => $order->id,
                ]);
            }

            // Generate invoice (FR-CO-007)
            $invoice = $this->invoiceService->generateInvoice($order);

            // Raise Commission API event (FR-CM-002)
            $this->raiseCommissionEvent($order);

            // Create notification (FR-NT-003)
            $this->sendOrderConfirmationNotification($order);

            return [
                'success' => true,
                'order_id' => $order->id,
                'order_reference' => $order->order_reference,
                'status' => 'confirmed',
                'invoice_number' => $invoice->invoice_number,
                'invoice_url' => route('api.orders.invoice', ['orderReference' => $order->order_reference]),
            ];
        });
    }

    /**
     * FR-CO-008: Get user order history
     */
    public function getOrderHistory(int $userId, array $filters = []): array
    {
        $query = Order::where('user_id', $userId)
            ->with(['lines.product', 'invoice'])
            ->orderBy('created_at', 'desc');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        if (isset($filters['order_type'])) {
            $query->where('order_type', $filters['order_type']);
        }

        $perPage = $filters['per_page'] ?? 15;
        $orders = $query->paginate($perPage);

        return [
            'orders' => $orders->items(),
            'total' => $orders->total(),
            'per_page' => $orders->perPage(),
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
        ];
    }

    /**
     * FR-CO-008: Get order detail
     */
    public function getOrderDetail(int $userId, string $orderReference): array
    {
        $order = Order::with([
            'user',
            'lines.product',
            'lines.product.category',
            'invoice',
            'billingAddress',
            'deliveryAddress',
            'statusHistory'
        ])->where('order_reference', $orderReference)
            ->where('user_id', $userId)
            ->firstOrFail();

        return $order->toArray();
    }

    /**
     * FR-CO-010: Cancel order (before dispatch)
     */
    public function cancelOrder(int $userId, string $orderReference): array
    {
        $order = Order::where('order_reference', $orderReference)
            ->where('user_id', $userId)
            ->firstOrFail();

        if (!in_array($order->status, ['pending', 'confirmed', 'processing'])) {
            throw new \Exception('Order cannot be cancelled in its current state');
        }

        if ($order->status === 'dispatched' || $order->status === 'delivered') {
            throw new \Exception('Order has already been dispatched. Please use return option.');
        }

        return DB::transaction(function () use ($order) {
            // Restore stock
            foreach ($order->lines as $line) {
                $product = $line->product;
                $product->increment('stock_quantity', $line->quantity);

                \App\Models\StockMovement::create([
                    'product_id' => $product->id,
                    'quantity' => $line->quantity,
                    'available_quantity_after' => $product->stock_quantity,
                    'reason' => 'Order cancelled: ' . $order->order_reference,
                    'order_id' => $order->id,
                ]);
            }

            $order->update(['status' => 'cancelled']);

            // Raise reversal (FR-CM-009)
            $this->raiseReversalEvent($order, 'cancellation');

            // Initiate refund (FR-CO-012)
            $this->initiateRefund($order);

            return [
                'success' => true,
                'order_reference' => $order->order_reference,
                'status' => 'cancelled',
                'message' => 'Order cancelled successfully',
            ];
        });
    }

    // ============================================================
    // Helper Methods
    // ============================================================

    protected function generateOrderReference(): string
    {
        return 'ORD-' . strtoupper(uniqid());
    }

    protected function formatAddress(Address $address): string
    {
        return implode(', ', array_filter([
            $address->address_line_1,
            $address->address_line_2,
            $address->city,
            $address->state,
            $address->postcode,
            $address->country,
        ]));
    }

    protected function getCoinBalance(int $userId): int
    {
        // Call Commission API or get from cache
        // For now, return mock value
        try {
            // You can call external API here:
            // $response = Http::post(config('commission.api_url') . '/coin-balance', ['user_id' => $userId]);
            // return $response->json('balance', 0);
            
            return 50; // Placeholder
        } catch (\Exception $e) {
            Log::error('Failed to get coin balance: ' . $e->getMessage());
            return 0;
        }
    }

    protected function authorizeCoinRedemption(int $userId, int $coins, float $amount): array
    {
        // Call Commission API to authorize redemption (FR-CO-004)
        // For now, mock authorization
        return [
            'id' => 'AUTH-' . strtoupper(uniqid()),
            'status' => 'authorized',
            'coins' => $coins,
            'amount' => $amount,
        ];
    }

    protected function raiseCommissionEvent(Order $order): void
    {
        // FR-CM-002: Post order event to Commission API
        CommissionApiEvent::create([
            'event_type' => 'order_post',
            'order_id' => $order->id,
            'payload' => json_encode([
                'order_reference' => $order->order_reference,
                'user_id' => $order->user_id,
                'order_type' => $order->order_type,
                'total' => $order->total_payable,
                'lines' => $order->lines->map(function ($line) {
                    return [
                        'product_id' => $line->product_id,
                        'quantity' => $line->quantity,
                        'unit_price' => $line->unit_price,
                        'commissionable_volume' => $line->commissionable_volume,
                    ];
                })->toArray(),
            ]),
            'status' => 'pending',
        ]);
    }

    protected function raiseReversalEvent(Order $order, string $reason): void
    {
        // FR-CM-009: Reversal event
        CommissionApiEvent::create([
            'event_type' => 'reversal',
            'order_id' => $order->id,
            'payload' => json_encode([
                'order_reference' => $order->order_reference,
                'reason' => $reason,
                'lines' => $order->lines->map(function ($line) {
                    return [
                        'product_id' => $line->product_id,
                        'quantity' => $line->quantity,
                        'cv_reversed' => $line->commissionable_volume,
                    ];
                })->toArray(),
            ]),
            'status' => 'pending',
        ]);
    }

    protected function initiateRefund(Order $order): void
    {
        // FR-CO-012: Create refund record
        \App\Models\Refund::create([
            'order_id' => $order->id,
            'amount' => $order->amount_paid,
            'status' => 'initiated',
        ]);

        // In production, call gateway refund API here
    }

    protected function sendOrderConfirmationNotification(Order $order): void
    {
        // FR-NT-003: Send order confirmation notification
        // Queue email, SMS, push notification
        // You can use Laravel notifications here
    }
}