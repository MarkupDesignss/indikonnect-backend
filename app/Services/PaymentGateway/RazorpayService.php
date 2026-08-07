<?php

namespace App\Services\PaymentGateway;

use Razorpay\Api\Api;
use Illuminate\Support\Facades\Log;

class RazorpayService implements PaymentInterface
{
    protected $api;

    public function __construct()
    {
        $this->api = new Api(
            config('services.razorpay.key_id'),
            config('services.razorpay.key_secret')
        );
    }

    /**
     * Create Razorpay order
     */
    public function createOrder($order): array
    {
        try {
            $orderData = [
                'receipt' => $order->order_reference,
                'amount' => $order->total_payable * 100, // Convert to paise
                'currency' => 'INR',
                'payment_capture' => 1,
                'notes' => [
                    'order_reference' => $order->order_reference,
                    'user_id' => $order->user_id,
                ],
            ];

            $razorpayOrder = $this->api->order->create($orderData);

            return [
                'id' => $razorpayOrder['id'],
                'amount' => $razorpayOrder['amount'],
                'currency' => $razorpayOrder['currency'],
                'status' => $razorpayOrder['status'],
                'redirect_url' => route('checkout.success', ['orderReference' => $order->order_reference]),
            ];
        } catch (\Exception $e) {
            Log::error('Razorpay order creation failed: ' . $e->getMessage());
            throw new \Exception('Payment gateway error: ' . $e->getMessage());
        }
    }

    /**
     * Capture payment (for manual capture)
     */
    public function capturePayment(array $data): array
    {
        try {
            $payment = $this->api->payment->fetch($data['payment_id']);
            $payment->capture(['amount' => $data['amount']]);

            return [
                'success' => true,
                'payment_id' => $payment['id'],
                'status' => $payment['status'],
                'method' => $payment['method'],
                'amount' => $payment['amount'] / 100,
            ];
        } catch (\Exception $e) {
            Log::error('Razorpay payment capture failed: ' . $e->getMessage());
            throw new \Exception('Payment capture failed: ' . $e->getMessage());
        }
    }

    /**
     * Process refund
     */
    public function refundPayment(string $transactionId, float $amount): array
    {
        try {
            $payment = $this->api->payment->fetch($transactionId);
            $refund = $payment->refund(['amount' => $amount * 100]);

            return [
                'success' => true,
                'refund_id' => $refund['id'],
                'status' => $refund['status'],
                'amount' => $refund['amount'] / 100,
            ];
        } catch (\Exception $e) {
            Log::error('Razorpay refund failed: ' . $e->getMessage());
            throw new \Exception('Refund failed: ' . $e->getMessage());
        }
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhook(array $payload, string $signature): bool
    {
        try {
            $this->api->utility->verifyWebhookSignature(
                json_encode($payload),
                $signature,
                config('services.razorpay.webhook_secret')
            );
            return true;
        } catch (\Exception $e) {
            Log::error('Razorpay webhook verification failed: ' . $e->getMessage());
            return false;
        }
    }
}