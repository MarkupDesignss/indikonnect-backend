<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\CheckoutService;
use App\Services\PaymentGateway\RazorpayService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    protected $checkoutService;
    protected $gatewayService;

    public function __construct(CheckoutService $checkoutService, RazorpayService $gatewayService)
    {
        $this->checkoutService = $checkoutService;
        $this->gatewayService = $gatewayService;
    }

    /**
     * FR-CO-006: Handle payment webhook
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            Log::info('Payment webhook received', $request->all());

            $signature = $request->header('X-Razorpay-Signature');

            if (!$this->gatewayService->verifyWebhook($request->all(), $signature)) {
                Log::warning('Invalid webhook signature');
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            $payload = $request->all();
            $event = $payload['event'] ?? null;

            // Extract order reference from webhook
            $orderReference = $payload['payload']['payment']['entity']['notes']['order_reference'] 
                ?? $payload['reference_id'] 
                ?? null;

            if (!$orderReference) {
                Log::error('Order reference not found in webhook');
                return response()->json(['error' => 'Order reference missing'], 400);
            }

            // Handle different events
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

                case 'refund.processed':
                    Log::info('Refund processed for order', ['order' => $orderReference]);
                    return response()->json(['status' => 'success', 'message' => 'Refund processed']);

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