<?php

namespace App\Http\Controllers\Api\Webhook;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use App\Services\PaymentGateway\RazorpayService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RazorpayWebhookController extends Controller
{
    protected CheckoutService $checkoutService;
    protected RazorpayService $razorpayService;

    public function __construct(CheckoutService $checkoutService, RazorpayService $razorpayService)
    {
        $this->checkoutService = $checkoutService;
        $this->razorpayService = $razorpayService;
    }

    /**
     * FR-CO-006: Handle Razorpay webhook
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            $signature = $request->header('X-Razorpay-Signature');

            // Verify webhook signature
            if (!$this->razorpayService->verifyWebhook($payload, $signature)) {
                Log::warning('Razorpay webhook signature invalid');
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            $event = $payload['event'] ?? null;

            // Extract order reference from webhook
            $orderReference = $payload['payload']['payment']['entity']['notes']['order_reference']
                ?? $payload['reference_id']
                ?? null;

            if (!$orderReference) {
                Log::error('Order reference missing in webhook');
                return response()->json(['error' => 'Order reference missing'], 400);
            }

            switch ($event) {
                case 'payment.captured':
                    $result = $this->checkoutService->confirmOrder($orderReference, [
                        'gateway' => 'razorpay',
                        'transaction_id' => $payload['payload']['payment']['entity']['id'],
                        'amount' => $payload['payload']['payment']['entity']['amount'] / 100,
                        'method' => $payload['payload']['payment']['entity']['method'],
                        'status' => 'captured',
                    ]);

                    Log::info('Order confirmed via webhook', ['order' => $orderReference]);
                    return response()->json(['status' => 'success', 'data' => $result]);

                case 'payment.failed':
                    Log::warning('Payment failed for order', ['order' => $orderReference]);
                    return response()->json(['status' => 'failed', 'message' => 'Payment failed']);

                default:
                    Log::info('Unhandled webhook event', ['event' => $event]);
                    return response()->json(['status' => 'ignored']);
            }
        } catch (\Exception $e) {
            Log::error('Webhook processing failed: ' . $e->getMessage());
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}