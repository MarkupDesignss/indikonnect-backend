<?php

namespace App\Http\Controllers\API\Webhook;

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
    // public function handle(Request $request): JsonResponse
    // {
    //     try {
    //         $payload = $request->all();

    //         // Only verify signature in production
    //         if (config('app.env') === 'production') {
    //             $signature = $request->header('X-Razorpay-Signature');
    //             if (!$this->razorpayService->verifyWebhook($payload, $signature)) {
    //                 Log::warning('Razorpay webhook signature invalid');
    //                 return response()->json(['error' => 'Invalid signature'], 401);
    //             }
    //         } else {
    //             Log::info('Webhook signature verification skipped (local environment)');
    //         }

    //         $event = $payload['event'] ?? null;

    //         // Extract order reference from webhook
    //         $orderReference = $payload['payload']['payment']['entity']['notes']['order_reference']
    //             ?? $payload['reference_id']
    //             ?? $payload['payload']['order']['entity']['receipt']
    //             ?? null;

    //         if (!$orderReference) {
    //             Log::error('Order reference missing in webhook', ['payload' => $payload]);
    //             return response()->json(['error' => 'Order reference missing'], 400);
    //         }

    //         Log::info('Webhook event received', [
    //             'event' => $event,
    //             'order_reference' => $orderReference,
    //         ]);

    //         switch ($event) {
    //             case 'payment.captured':
    //                 $paymentEntity = $payload['payload']['payment']['entity'] ?? null;

    //                 if (!$paymentEntity) {
    //                     Log::error('Payment entity not found in webhook payload');
    //                     return response()->json(['error' => 'Invalid payment entity'], 400);
    //                 }

    //                 $result = $this->checkoutService->confirmOrder($orderReference, [
    //                     'gateway' => 'razorpay',
    //                     'transaction_id' => $paymentEntity['id'] ?? null,
    //                     'amount' => isset($paymentEntity['amount']) ? $paymentEntity['amount'] / 100 : 0,
    //                     'method' => $paymentEntity['method'] ?? 'unknown',
    //                     'status' => 'captured',
    //                 ]);

    //                 Log::info('Order confirmed via webhook', ['order' => $orderReference]);
    //                 return response()->json(['status' => 'success', 'data' => $result]);

    //             case 'payment.failed':
    //                 Log::warning('Payment failed for order', ['order' => $orderReference]);
    //                 return response()->json(['status' => 'failed', 'message' => 'Payment failed']);

    //             case 'order.paid':
    //                 $result = $this->checkoutService->confirmOrder($orderReference, [
    //                     'gateway' => 'razorpay',
    //                     'transaction_id' => $payload['payload']['order']['entity']['id'] ?? null,
    //                     'amount' => isset($payload['payload']['order']['entity']['amount'])
    //                         ? $payload['payload']['order']['entity']['amount'] / 100
    //                         : 0,
    //                     'method' => 'razorpay',
    //                     'status' => 'paid',
    //                 ]);

    //                 Log::info('Order confirmed via order.paid event', ['order' => $orderReference]);
    //                 return response()->json(['status' => 'success', 'data' => $result]);

    //             default:
    //                 Log::info('Unhandled webhook event', ['event' => $event]);
    //                 return response()->json(['status' => 'ignored', 'event' => $event]);
    //         }
    //     } catch (\Exception $e) {
    //         Log::error('Webhook processing failed: ' . $e->getMessage(), [
    //             'file' => $e->getFile(),
    //             'line' => $e->getLine(),
    //         ]);
    //         return response()->json(['error' => 'Internal server error: ' . $e->getMessage()], 500);
    //     }
    // }

    /**
     * FR-CO-006: Handle Razorpay webhook
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();

            // Only verify signature in production
            if (config('app.env') === 'production') {
                $signature = $request->header('X-Razorpay-Signature');
                if (!$this->razorpayService->verifyWebhook($payload, $signature)) {
                    Log::warning('Razorpay webhook signature invalid');
                    return response()->json(['error' => 'Invalid signature'], 401);
                }
            } else {
                Log::info('Webhook signature verification skipped (local environment)');
            }

            $event = $payload['event'] ?? null;

            // Extract order reference from webhook
            $orderReference = $payload['payload']['payment']['entity']['notes']['order_reference']
                ?? $payload['reference_id']
                ?? $payload['payload']['order']['entity']['receipt']
                ?? null;

            if (!$orderReference) {
                Log::error('Order reference missing in webhook', ['payload' => $payload]);
                return response()->json(['error' => 'Order reference missing'], 400);
            }

            Log::info('Webhook event received', [
                'event' => $event,
                'order_reference' => $orderReference,
            ]);

            switch ($event) {
                case 'payment.captured':
                    $paymentEntity = $payload['payload']['payment']['entity'] ?? null;

                    if (!$paymentEntity) {
                        Log::error('Payment entity not found in webhook payload');
                        return response()->json(['error' => 'Invalid payment entity'], 400);
                    }

                    $result = $this->checkoutService->confirmOrder($orderReference, [
                        'gateway' => 'razorpay',
                        'transaction_id' => $paymentEntity['id'] ?? null,
                        'amount' => isset($paymentEntity['amount']) ? $paymentEntity['amount'] / 100 : 0,
                        'method' => $paymentEntity['method'] ?? 'unknown',
                        'status' => 'captured',
                    ]);

                    Log::info('Order confirmed via webhook', ['order' => $orderReference]);
                    return response()->json(['status' => 'success', 'data' => $result]);

                case 'payment.failed':
                    Log::warning('Payment failed for order', ['order' => $orderReference]);
                    return response()->json(['status' => 'failed', 'message' => 'Payment failed']);

                case 'order.paid':
                    $result = $this->checkoutService->confirmOrder($orderReference, [
                        'gateway' => 'razorpay',
                        'transaction_id' => $payload['payload']['order']['entity']['id'] ?? null,
                        'amount' => isset($payload['payload']['order']['entity']['amount'])
                            ? $payload['payload']['order']['entity']['amount'] / 100
                            : 0,
                        'method' => 'razorpay',
                        'status' => 'paid',
                    ]);

                    Log::info('Order confirmed via order.paid event', ['order' => $orderReference]);
                    return response()->json(['status' => 'success', 'data' => $result]);

                    // Handle refund events
                case 'refund.created':
                case 'refund.processed':
                    $refundEntity = $payload['payload']['refund']['entity'] ?? null;

                    if ($refundEntity) {
                        $refundId = $refundEntity['id'] ?? null;
                        $refundStatus = $refundEntity['status'] ?? 'processing';

                        // Update the return record
                        if ($refundId) {
                            $returnOrder = \App\Models\OrderReturn::where('refund_transaction_id', $refundId)->first();
                            if ($returnOrder) {
                                $returnOrder->update([
                                    'refund_status' => $refundStatus,
                                ]);

                                Log::info('Refund status updated via webhook', [
                                    'return_id' => $returnOrder->id,
                                    'refund_id' => $refundId,
                                    'status' => $refundStatus,
                                ]);
                            }
                        }
                    }
                    return response()->json(['status' => 'success']);

                default:
                    Log::info('Unhandled webhook event', ['event' => $event]);
                    return response()->json(['status' => 'ignored', 'event' => $event]);
            }
        } catch (\Exception $e) {
            Log::error('Webhook processing failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['error' => 'Internal server error: ' . $e->getMessage()], 500);
        }
    }
}
