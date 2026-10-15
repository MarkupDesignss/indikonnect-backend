<?php

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\PaymentGateway\HdfcService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class HdfcWebhookController extends Controller
{
    protected CheckoutService $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * Resolve HdfcService instance based on gateway key stored on order.
     */
    protected function resolveHdfcService(?string $gatewayKey): HdfcService
    {
        $configKey = $gatewayKey === 'hdfc_ie' ? 'ie' : 'sg';
        $config    = config("services.hdfc.{$configKey}");

        if (!$config || empty($config['api_key'])) {
            throw new \Exception("HDFC merchant [{$gatewayKey}] is not configured.");
        }

        return new HdfcService($config);
    }

    /**
     * Server-to-server webhook from HDFC
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();

            Log::info('HDFC webhook received', $payload);

            $orderReference = $payload['order_id']
                ?? $payload['udf1']
                ?? ($payload['order']['order_id'] ?? null);

            $status = strtoupper(
                $payload['status']
                    ?? ($payload['order']['status'] ?? '')
            );

            if (!$orderReference) {
                Log::error('HDFC webhook: order reference missing', $payload);
                return response()->json(['error' => 'Order reference missing'], 400);
            }

            // Find order to know which merchant config to use
            $order = Order::where('order_reference', $orderReference)->first();

            if (!$order) {
                Log::error('HDFC webhook: order not found', ['ref' => $orderReference]);
                return response()->json(['error' => 'Order not found'], 404);
            }

            $hdfcService = $this->resolveHdfcService($order->payment_gateway);

            // Optional signature verification
            $signature = $request->header('x-hdfc-signature')
                ?? $request->header('X-HDFC-Signature');

            if (config('app.env') === 'production' && $signature) {
                if (!$hdfcService->verifySignature($payload, $signature)) {
                    Log::warning('HDFC webhook signature invalid');
                    return response()->json(['error' => 'Invalid signature'], 401);
                }
            }

            if (in_array($status, ['CHARGED', 'SUCCESS', 'CAPTURED', 'PAID'])) {
                $result = $this->checkoutService->confirmOrder($orderReference, [
                    'gateway'        => $order->payment_gateway,
                    'transaction_id' => $payload['id']
                        ?? $payload['transaction_id']
                        ?? ($payload['order']['id'] ?? null),
                    'amount'         => $payload['amount']
                        ?? ($payload['order']['amount'] ?? 0),
                    'method'         => $payload['payment_method_type']
                        ?? ($payload['payment_method'] ?? 'hdfc'),
                    'status'         => 'captured',
                ]);

                Log::info('HDFC order confirmed via webhook', [
                    'reference' => $orderReference,
                ]);

                return response()->json(['status' => 'success', 'data' => $result]);
            }

            if (in_array($status, ['FAILED', 'AUTHORIZATION_FAILED', 'JUSPAY_DECLINED', 'DECLINED'])) {
                Log::warning('HDFC payment failed', [
                    'reference' => $orderReference,
                    'status'    => $status,
                ]);
                return response()->json(['status' => 'failed']);
            }

            return response()->json(['status' => 'ignored', 'hdfc_status' => $status]);
        } catch (\Exception $e) {
            Log::error('HDFC webhook failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Browser redirect target after HDFC payment page.
     */
    public function return(Request $request)
    {
        $orderReference = $request->input('order_id')
            ?? $request->input('udf1')
            ?? $request->input('orderId');

        $hdfcOrderId = $request->input('id')
            ?? $request->input('order_id');

        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');

        try {
            $order = Order::where('order_reference', $orderReference)->first();

            if (!$order) {
                return redirect()->away(
                    $frontend . '/order-failed?ref=' . urlencode((string) $orderReference)
                        . '&reason=order_not_found'
                );
            }

            $hdfcService = $this->resolveHdfcService($order->payment_gateway);

            if ($hdfcOrderId) {
                $status      = $hdfcService->getOrderStatus($hdfcOrderId);
                $orderStatus = strtoupper($status['status'] ?? '');

                if (in_array($orderStatus, ['CHARGED', 'SUCCESS', 'CAPTURED', 'PAID'])) {
                    $this->checkoutService->confirmOrder($orderReference, [
                        'gateway'        => $order->payment_gateway,
                        'transaction_id' => $status['id'] ?? $hdfcOrderId,
                        'amount'         => $status['amount'] ?? 0,
                        'method'         => $status['payment_method_type'] ?? 'hdfc',
                        'status'         => 'captured',
                    ]);

                    return redirect()->away(
                        $frontend . '/order-success?ref=' . urlencode($orderReference)
                    );
                }

                if (in_array($orderStatus, ['FAILED', 'DECLINED', 'AUTHORIZATION_FAILED'])) {
                    return redirect()->away(
                        $frontend . '/order-failed?ref=' . urlencode($orderReference)
                            . '&reason=' . urlencode($status['status'] ?? 'failed')
                    );
                }
            }

            if ($order->status === 'confirmed') {
                return redirect()->away(
                    $frontend . '/order-success?ref=' . urlencode($orderReference)
                );
            }

            return redirect()->away(
                $frontend . '/order-pending?ref=' . urlencode($orderReference)
            );
        } catch (\Exception $e) {
            Log::error('HDFC return handler error: ' . $e->getMessage(), [
                'reference' => $orderReference,
            ]);

            return redirect()->away(
                $frontend . '/order-failed?ref=' . urlencode((string) $orderReference)
                    . '&reason=exception'
            );
        }
    }

    /**
     * User cancelled on the HDFC payment page.
     */
    public function cancel(Request $request)
    {
        $orderReference = $request->input('order_id')
            ?? $request->input('udf1');

        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');

        Log::info('HDFC payment cancelled by user', [
            'reference' => $orderReference,
        ]);

        return redirect()->away(
            $frontend . '/order-cancelled?ref=' . urlencode((string) $orderReference)
        );
    }
}
