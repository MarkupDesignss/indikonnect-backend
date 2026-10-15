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

    protected array $successStatuses = [
        'CHARGED',
        'SUCCESS',
        'CAPTURED',
        'PAID',
        'AUTHORIZED',
    ];

    protected array $failureStatuses = [
        'AUTHORIZATION_FAILED',
        'FAILED',
        'DECLINED',
        'JUSPAY_DECLINED',
        'PAYMENT_FAILURE',
        'AUTHENTICATION_FAILED',
        'EXPIRED',
        'CANCELLED',
        'USER_DROPPED',
    ];

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    protected function resolveHdfcService(?string $gatewayKey): HdfcService
    {
        $gatewayKey = $gatewayKey ?: 'hdfc_sg';
        $configKey  = $gatewayKey === 'hdfc_ie' ? 'ie' : 'sg';
        $config     = config("services.hdfc.{$configKey}");

        if (!$config || empty($config['api_key'])) {
            throw new \Exception("HDFC merchant [{$gatewayKey}] is not configured.");
        }

        return new HdfcService($config, $gatewayKey);
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Log without the huge HTML blob
        $loggable = $payload;
        if (isset($loggable['return_url']) && is_string($loggable['return_url'])) {
            $loggable['return_url'] = '[html-omitted]';
        }
        Log::info('HDFC webhook received', $loggable);

        try {
            // ---------- Order reference ----------
            $orderReference = $payload['order_id']
                ?? $payload['udf1']
                ?? ($payload['order']['order_id'] ?? null);

            // ---------- Status (top-level) ----------
            $status = strtoupper(
                (string) ($payload['status'] ?? ($payload['order']['status'] ?? ''))
            );

            // ---------- Category (primary signal for this webhook shape) ----------
            $category = strtoupper((string) ($payload['resp_category'] ?? ''));

            if (!$orderReference) {
                Log::warning('HDFC webhook: order reference missing', $loggable);
                return $this->ack('Order reference missing, ignored');
            }

            $order = Order::where('order_reference', $orderReference)->first();
            if (!$order) {
                Log::warning('HDFC webhook: order not found', ['reference' => $orderReference]);
                return $this->ack('Order not found, ignored');
            }

            $hdfcService = $this->resolveHdfcService($order->payment_gateway);

            // ---------- Signature (may be embedded inside return_url HTML) ----------
            $signature = $this->extractSignature($payload);

            if ($signature && !$hdfcService->verifySignature($payload, $signature)) {
                Log::warning('HDFC webhook signature invalid', [
                    'reference' => $orderReference,
                ]);
                // Log-only; don't reject so HDFC doesn't retry.
            }

            // ---------- Outcome ----------
            $isSuccess = in_array($status, $this->successStatuses, true)
                || $category === 'PAYMENT_SUCCESS';

            $isFailure = in_array($status, $this->failureStatuses, true)
                || $category === 'PAYMENT_FAILURE';

            if ($isSuccess) {
                $result = $this->checkoutService->confirmOrder($orderReference, [
                    'gateway'        => $order->payment_gateway,
                    'transaction_id' => $payload['id']
                        ?? $payload['transaction_id']
                        ?? ($payload['order']['id'] ?? null),
                    'amount'         => $payload['amount']
                        ?? ($payload['order']['amount'] ?? 0),
                    'method'         => $payload['payment_method_type']
                        ?? $payload['payment_method'] ?? 'hdfc',
                    'status'         => 'captured',
                ]);

                Log::info('HDFC webhook: order confirmed', [
                    'reference' => $orderReference,
                    'status'    => $status,
                    'category'  => $category,
                ]);

                return response()->json(['status' => 'success', 'data' => $result]);
            }

            if ($isFailure) {
                Log::warning('HDFC webhook: payment failed', [
                    'reference' => $orderReference,
                    'status'    => $status,
                    'status_id' => $payload['status_id'] ?? null,
                    'category'  => $category,
                ]);

                // Optional: mark order as failed
                // $order->update(['status' => 'failed']);

                return $this->ack('Payment failed, order left unchanged');
            }

            Log::info('HDFC webhook: unhandled status', [
                'reference' => $orderReference,
                'status'    => $status,
                'category'  => $category,
            ]);

            return $this->ack("Unhandled status: {$status} / category: {$category}");
        } catch (\Exception $e) {
            Log::error('HDFC webhook failed: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->ack('Processed with errors: ' . $e->getMessage());
        }
    }

    /**
     * Try to pull the signature from:
     *  1. top-level `signature`
     *  2. request header (x-hdfc-signature / X-HDFC-Signature)
     *  3. hidden input inside `return_url` HTML
     */
    protected function extractSignature(array $payload): ?string
    {
        // 1. Top-level
        if (!empty($payload['signature']) && is_string($payload['signature'])) {
            return $payload['signature'];
        }

        // 2. Header
        $header = request()->header('x-hdfc-signature')
            ?? request()->header('X-HDFC-Signature');
        if (!empty($header)) {
            return $header;
        }

        // 3. Embedded in return_url HTML: name='signature' value='...'
        if (!empty($payload['return_url']) && is_string($payload['return_url'])) {
            if (preg_match(
                "/name=['\"]signature['\"]\s+value=['\"]([^'\"]+)['\"]/i",
                $payload['return_url'],
                $m
            )) {
                return urldecode($m[1]);
            }
        }

        return null;
    }

    protected function ack(string $message): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
        ]);
    }
}
