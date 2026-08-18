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
    public function refundPayment(string $paymentId, float $amount): array
    {
        try {
            // Fetch the payment
            $payment = $this->api->payment->fetch($paymentId);

            // Create refund
            $refund = $payment->refund([
                'amount' => (int)($amount * 100),
                'speed' => 'normal',
                'notes' => [
                    'refund_reason' => 'Product return - items received',
                    'processed_at' => now()->toDateTimeString(),
                ]
            ]);

            Log::info('Razorpay refund processed successfully', [
                'payment_id' => $paymentId,
                'refund_id' => $refund->id,
                'amount' => $amount,
            ]);

            return [
                'success' => true,
                'refund_id' => $refund->id,
            'payment_id' => $paymentId,
                'amount' => $amount,
                'status' => $refund->status,
                'created_at' => $refund->created_at,
            ];
        } catch (\Exception $e) {
            Log::error('Razorpay refund failed', [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Refund processing failed: ' . $e->getMessage());
        }
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
