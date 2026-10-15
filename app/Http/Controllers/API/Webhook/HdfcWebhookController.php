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
    protected HdfcService $hdfcService;

    public function __construct(CheckoutService $checkoutService, HdfcService $hdfcService)
    {
        $this->checkoutService = $checkoutService;
        $this->hdfcService     = $hdfcService;
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

            // Optional signature verification (only if HDFC sends a signature header)
            $signature = $request->header('x-hdfc-signature')
                ?? $request->header('X-HDFC-Signature');

            if (config('app.env') === 'production' && $signature) {
                if (!$this->hdfcService->verifySignature($payload, $signature)) {
                    Log::warning('HDFC webhook signature invalid');
                    return response()->json(['error' => 'Invalid signature'], 401);
                }
            }

            if (in_array($status, ['CHARGED', 'SUCCESS', 'CAPTURED', 'PAID'])) {
                $result = $this->checkoutService->confirmOrder($orderReference, [
                    'gateway'        => 'hdfc',
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
     *
     * HDFC does NOT reliably POST back here — always re-verify via API.
     */
    public function return(Request $request)
    {
        // HDFC sends these params on return URL
        $orderReference = $request->input('order_id')
            ?? $request->input('udf1')
            ?? $request->input('orderId');

        $hdfcOrderId = $request->input('id')
            ?? $request->input('order_id'); // sometimes same

        $frontend = rtrim(config('app.frontend_url', config('app.url')), '/');

        try {
            // Prefer verifying against HDFC API rather than trusting query params
            if ($hdfcOrderId) {
                $status = $this->hdfcService->getOrderStatus($hdfcOrderId);
                $orderStatus = strtoupper($status['status'] ?? '');

                if (in_array($orderStatus, ['CHARGED', 'SUCCESS', 'CAPTURED', 'PAID'])) {
                    $this->checkoutService->confirmOrder($orderReference, [
                        'gateway'        => 'hdfc',
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

            // Fallback: webhook may have already confirmed; just push to a status page
            $order = Order::where('order_reference', $orderReference)->first();

            if ($order && $order->status === 'confirmed') {
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
