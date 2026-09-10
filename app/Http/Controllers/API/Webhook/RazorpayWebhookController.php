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
            $orderEntity = $payload['payload']['order']['entity'] ?? null;

            // Try to get order_group_id from notes (preferred), fallback to order_reference
            $notes = $paymentEntity['notes'] ?? $orderEntity['notes'] ?? [];

            $orderGroupId = $notes['order_group_id'] ?? null;
            $orderReference = $notes['order_reference']
                ?? $orderEntity['receipt']
                ?? $payload['reference_id']
                ?? null;

            $reference = $orderGroupId ?? $orderReference;

            if (!$reference) {
                Log::error('Order reference/group missing in webhook', ['payload' => $payload]);
                return response()->json(['error' => 'Order reference missing'], 400);
            }

            Log::info('Webhook event received', [
                'event' => $event,
                'order_group_id' => $orderGroupId,
                'order_reference' => $orderReference,
            ]);

            switch ($event) {
                case 'payment.captured':
                    if (!$paymentEntity) {
                        Log::error('Payment entity not found in webhook payload');
                        return response()->json(['error' => 'Invalid payment entity'], 400);
                    }

                    $result = $this->confirmFromWebhook($orderGroupId, $orderReference, [
                        'gateway' => 'razorpay',
                        'transaction_id' => $paymentEntity['id'] ?? null,
                        'amount' => isset($paymentEntity['amount']) ? $paymentEntity['amount'] / 100 : 0,
                        'method' => $paymentEntity['method'] ?? 'unknown',
                        'status' => 'captured',
                    ]);

                    Log::info('Order(s) confirmed via webhook', [
                        'group' => $orderGroupId,
                        'reference' => $orderReference,
                    ]);
                    return response()->json(['status' => 'success', 'data' => $result]);

                case 'payment.failed':
                    Log::warning('Payment failed', [
                        'group' => $orderGroupId,
                        'reference' => $orderReference,
                    ]);
                    return response()->json(['status' => 'failed', 'message' => 'Payment failed']);

                case 'order.paid':
                    $result = $this->confirmFromWebhook($orderGroupId, $orderReference, [
                        'gateway' => 'razorpay',
                        'transaction_id' => $orderEntity['id'] ?? null,
                        'amount' => isset($orderEntity['amount']) ? $orderEntity['amount'] / 100 : 0,
                        'method' => 'razorpay',
                        'status' => 'paid',
                    ]);

                    Log::info('Order(s) confirmed via order.paid event', [
                        'group' => $orderGroupId,
                        'reference' => $orderReference,
                    ]);
                    return response()->json(['status' => 'success', 'data' => $result]);

                case 'refund.created':
                case 'refund.processed':
                    $refundEntity = $payload['payload']['refund']['entity'] ?? null;

                    if ($refundEntity) {
                        $refundId = $refundEntity['id'] ?? null;
                        $refundStatus = $refundEntity['status'] ?? 'processing';

                        if ($refundId) {
                            $returnOrder = \App\Models\OrderReturn::where('refund_transaction_id', $refundId)->first();
                            if ($returnOrder) {
                                $returnOrder->update(['refund_status' => $refundStatus]);

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

    /**
     * Confirm orders via webhook — supports both group and single reference
     */
    private function confirmFromWebhook(?string $orderGroupId, ?string $orderReference, array $gatewayData): array
    {
        // Prefer group ID (confirms all orders in group)
        if ($orderGroupId) {
            $order = Order::where('order_group_id', $orderGroupId)->first();
            if ($order) {
                return $this->checkoutService->confirmOrder($order->order_reference, $gatewayData);
            }
        }

        // Fallback to single reference
        if ($orderReference) {
            return $this->checkoutService->confirmOrder($orderReference, $gatewayData);
        }

        throw new \Exception('No order reference or group ID provided');
    }

    public function createOrderForGroup(
        float $amount,
        string $orderGroupId,
        array $notes = []
    ): array {
        $amountInPaise = (int) round($amount * 100);

        if ($amountInPaise <= 0) {
            throw new \Exception('Invalid Razorpay order amount.');
        }

        $razorpay = new \Razorpay\Api\Api(
            config('services.razorpay.key_id'),
            config('services.razorpay.key_secret')
        );

        $razorpayOrder = $razorpay->order->create([
            'amount' => $amountInPaise,
            'currency' => 'INR',
            'receipt' => $orderGroupId,
            'notes' => $notes,
        ]);

        return [
            'id' => $razorpayOrder['id'],
            'entity' => $razorpayOrder['entity'] ?? 'order',
            'amount' => $razorpayOrder['amount'],
            'currency' => $razorpayOrder['currency'],
            'status' => $razorpayOrder['status'] ?? 'created',
            'receipt' => $razorpayOrder['receipt'] ?? $orderGroupId,
            'notes' => $razorpayOrder['notes'] ?? $notes,
        ];
    }
}
