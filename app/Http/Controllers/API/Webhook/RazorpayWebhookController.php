<?php

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Order;
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

            $paymentEntity = $payload['payload']['payment']['entity'] ?? null;
            $orderEntity   = $payload['payload']['order']['entity'] ?? null;

            // Pull notes from payment first, fallback to order entity
            $notes = $paymentEntity['notes'] ?? $orderEntity['notes'] ?? [];

            //  Only order_reference now (no group id)
            $orderReference = $notes['order_reference']
                ?? $orderEntity['receipt']
                ?? $payload['reference_id']
                ?? null;

            if (!$orderReference) {
                Log::error('Order reference missing in webhook', ['payload' => $payload]);
                return response()->json(['error' => 'Order reference missing'], 400);
            }

            Log::info('Webhook event received', [
                'event'           => $event,
                'order_reference' => $orderReference,
            ]);

            switch ($event) {
                case 'payment.captured':
                    if (!$paymentEntity) {
                        Log::error('Payment entity not found in webhook payload');
                        return response()->json(['error' => 'Invalid payment entity'], 400);
                    }

                    $result = $this->confirmFromWebhook($orderReference, [
                        'gateway'        => 'razorpay',
                        'transaction_id' => $paymentEntity['id'] ?? null,
                        'amount'         => isset($paymentEntity['amount']) ? $paymentEntity['amount'] / 100 : 0,
                        'method'         => $paymentEntity['method'] ?? 'unknown',
                        'status'         => 'captured',
                    ]);

                    Log::info('Order confirmed via webhook', [
                        'reference' => $orderReference,
                    ]);
                    return response()->json(['status' => 'success', 'data' => $result]);

                    // case 'payment.failed':
                    //     Log::warning('Payment failed', [
                    //         'reference' => $orderReference,
                    //     ]);
                    //     return response()->json(['status' => 'failed', 'message' => 'Payment failed']);
                case 'payment.failed':
                    $result = $this->failOrderFromWebhook($orderReference, [
                        'gateway'        => 'razorpay',
                        'transaction_id' => $paymentEntity['id'] ?? null,
                        'amount'         => isset($paymentEntity['amount'])
                            ? $paymentEntity['amount'] / 100
                            : 0,
                        'method'         => $paymentEntity['method'] ?? 'unknown',
                        'status'         => 'payment_failed',
                    ]);

                    Log::warning('Order payment failed via webhook', [
                        'reference' => $orderReference,
                    ]);

                    return response()->json([
                        'status' => 'failed',
                        'data' => $result,
                    ]);

                case 'order.paid':
                    $result = $this->confirmFromWebhook($orderReference, [
                        'gateway'        => 'razorpay',
                        'transaction_id' => $orderEntity['id'] ?? null,
                        'amount'         => isset($orderEntity['amount']) ? $orderEntity['amount'] / 100 : 0,
                        'method'         => 'razorpay',
                        'status'         => 'paid',
                    ]);

                    Log::info('Order confirmed via order.paid event', [
                        'reference' => $orderReference,
                    ]);
                    return response()->json(['status' => 'success', 'data' => $result]);

                case 'refund.created':
                case 'refund.processed':
                    $refundEntity = $payload['payload']['refund']['entity'] ?? null;

                    if ($refundEntity) {
                        $refundId     = $refundEntity['id'] ?? null;
                        $refundStatus = $refundEntity['status'] ?? 'processing';

                        if ($refundId) {
                            $returnOrder = \App\Models\OrderReturn::where('refund_transaction_id', $refundId)->first();
                            if ($returnOrder) {
                                $returnOrder->update(['refund_status' => $refundStatus]);

                                Log::info('Refund status updated via webhook', [
                                    'return_id' => $returnOrder->id,
                                    'refund_id' => $refundId,
                                    'status'    => $refundStatus,
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

    /**
     * Confirm order via webhook — reference-based only
     */
    private function confirmFromWebhook(string $orderReference, array $gatewayData): array
    {
        return $this->checkoutService->confirmOrder($orderReference, $gatewayData);
    }

    /**
     * Mark order and all order lines as payment failed.
     */

    private function failOrderFromWebhook(
        string $orderReference,
        array $gatewayData
    ): array {
        $order = Order::where('order_reference', $orderReference)->first();

        if (!$order) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                "Order not found for reference: {$orderReference}"
            );
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($order, $gatewayData) {
            $order->update([
                'status' => 'payment_failed',
                'payment_gateway' => $gatewayData['gateway'] ?? 'razorpay',
                'gateway_transaction_id' => $gatewayData['transaction_id'] ?? null,
            ]);

            \App\Models\OrderLine::where('order_id', $order->id)
                ->update([
                    'delivery_status' => 'payment_failed',
                ]);
        });

        return [
            'order_id' => $order->id,
            'order_reference' => $order->order_reference,
            'order_status' => $order->fresh()->status,
            'order_lines_status' => 'payment_failed',
        ];
    }
}
