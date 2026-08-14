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
