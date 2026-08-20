<?php

namespace App\Services\PaymentGateway;

use Razorpay\Api\Api;
use Illuminate\Support\Facades\Log;

class RazorpayService
{
    protected Api $api;

    public function __construct()
    {
        $this->api = new Api(
            config('services.razorpay.key_id'),
            config('services.razorpay.key_secret')
        );
    }

    /**
     * Create a Razorpay order
     */
    public function createOrder($order): array
    {
        try {
            $razorpayOrder = $this->api->order->create([
                'receipt' => $order->order_reference,
                'amount' => $order->total_payable * 100,
                'currency' => 'INR',
                'payment_capture' => 1,
                'notes' => [
                    'order_reference' => $order->order_reference,
                    'user_id' => $order->user_id,
                ],
            ]);

            return [
                'id' => $razorpayOrder['id'],
                'amount' => $razorpayOrder['amount'],
                'currency' => $razorpayOrder['currency'],
                'status' => $razorpayOrder['status'],
            ];
        } catch (\Exception $e) {
            Log::error('Razorpay order creation failed: ' . $e->getMessage());
            throw new \Exception('Payment gateway error: ' . $e->getMessage());
        }
    }

    /**
     * Process a refund for a payment
     */
    // public function refundPayment(string $paymentId, float $amount): array
    // {
    //     try {
    //         // Fetch the payment
    //         $payment = $this->api->payment->fetch($paymentId);

    //         // Create refund
    //         $refund = $payment->refund([
    //             'amount' => (int)($amount * 100),
    //             'speed' => 'normal',
    //             'notes' => [
    //                 'refund_reason' => 'Product return - items received',
    //                 'processed_at' => now()->toDateTimeString(),
    //             ]
    //         ]);

    //         Log::info('Razorpay refund processed successfully', [
    //             'payment_id' => $paymentId,
    //             'refund_id' => $refund->id,
    //             'amount' => $amount,
    //         ]);

    //         return [
    //             'success' => true,
    //             'refund_id' => $refund->id,
    //         'payment_id' => $paymentId,
    //             'amount' => $amount,
    //             'status' => $refund->status,
    //             'created_at' => $refund->created_at,
    //         ];
    //     } catch (\Exception $e) {
    //         Log::error('Razorpay refund failed', [
    //             'payment_id' => $paymentId,
    //             'amount' => $amount,
    //             'error' => $e->getMessage(),
    //         ]);
    //         throw new \Exception('Refund processing failed: ' . $e->getMessage());
    //     }
    // }

    public function refundPayment(string $paymentId, float $amount): array
    {
        try {
            // Validate amount
            if ($amount <= 0) {
                throw new \Exception('Refund amount must be greater than zero.');
            }

            // Amount in paise (Razorpay expects amount in paise)
            $amountInPaise = (int) round($amount * 100);

            Log::info('Processing Razorpay refund', [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'amount_in_paise' => $amountInPaise,
            ]);

            // Fetch the payment
            $payment = $this->api->payment->fetch($paymentId);

            // Verify payment exists and is captured
            if ($payment->status !== 'captured') {
                throw new \Exception(
                    "Payment is not captured. Current status: {$payment->status}"
                );
            }

            // Verify refund amount doesn't exceed payment amount
            $paymentAmount = (float) ($payment->amount / 100);
            if ($amount > $paymentAmount) {
                throw new \Exception(
                    "Refund amount ({$amount}) exceeds payment amount ({$paymentAmount})"
                );
            }

            // Create refund
            $refund = $payment->refund([
                'amount' => $amountInPaise,
                'speed' => 'normal',
                'notes' => [
                    'refund_reason' => 'Product return - items received',
                    'processed_at' => now()->toDateTimeString(),
                    'refund_type' => 'partial_return',
                ]
            ]);

            Log::info('Razorpay refund processed successfully', [
                'payment_id' => $paymentId,
                'refund_id' => $refund->id,
                'amount' => $amount,
                'amount_in_paise' => $amountInPaise,
                'status' => $refund->status,
                'created_at' => $refund->created_at,
            ]);

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'payment_id' => $paymentId,
                'amount' => $amount,
                'amount_in_paise' => $amountInPaise,
                'status' => $refund->status,
                'created_at' => $refund->created_at,
            ];
        } catch (\Razorpay\Api\Errors\Error $e) {
            // Razorpay specific errors
            Log::error('Razorpay API error during refund', [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
            ]);

            throw new \Exception('Razorpay refund failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            // General errors
            Log::error('Unexpected error during Razorpay refund', [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \Exception('Refund processing failed: ' . $e->getMessage());
        }
    }

    public function calculateRefundAmount(): float
    {
        $total = 0.00;

        // Get items from the return
        $items = $this->items ?? [];

        foreach ($items as $item) {
            // Handle both array and object access
            $lineTotal = is_array($item)
                ? ($item['line_total'] ?? 0)
                : ($item->line_total ?? 0);

            $total += (float) $lineTotal;
        }

        // Add shipping if applicable
        $total += (float) ($this->refund_shipping ?? 0);

        return round($total, 2);
    }

    /**
     * Get all line totals from items.
     */
    public function getLineTotals(): array
    {
        $totals = [];
        $items = $this->items ?? [];

        foreach ($items as $item) {
            $orderLineId = is_array($item)
                ? ($item['order_line_id'] ?? null)
                : ($item->order_line_id ?? null);

            $lineTotal = is_array($item)
                ? ($item['line_total'] ?? 0)
                : ($item->line_total ?? 0);

            $productName = is_array($item)
                ? ($item['product_name'] ?? 'Unknown')
                : ($item->product_name ?? 'Unknown');

            $totals[] = [
                'order_line_id' => $orderLineId,
                'product_name' => $productName,
                'line_total' => (float) $lineTotal,
            ];
        }

        return $totals;
    }

    /**
     * Check refund status
     */
    public function checkRefundStatus(string $refundId): array
    {
        try {
            $refund = $this->api->refund->fetch($refundId);

            return [
                'id' => $refund->id,
                'payment_id' => $refund->payment_id,
                'amount' => $refund->amount / 100,
                'status' => $refund->status,
                'created_at' => $refund->created_at,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch refund status', [
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to fetch refund status: ' . $e->getMessage());
        }
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhook(array $payload, ?string $signature): bool
    {
        if (!$signature) {
            return false;
        }

        try {
            $this->api->utility->verifyWebhookSignature(
                json_encode($payload),
                $signature,
                config('services.razorpay.webhook_secret')
            );
            return true;
        } catch (\Exception $e) {
            Log::error('Webhook signature verification failed: ' . $e->getMessage());
            return false;
        }
    }
}
