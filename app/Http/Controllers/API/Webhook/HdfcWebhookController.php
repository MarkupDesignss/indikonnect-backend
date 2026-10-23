<?php

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\CheckoutService;
use App\Services\PaymentGateway\HdfcService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request as LaravelHttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HdfcWebhookController extends Controller
{
    public function __construct(
        private CheckoutService $checkoutService
    ) {}

    private const PAID = [
        'CHARGED',
        'CAPTURED',
        'PAID',
        'SUCCESS',
        'TXN_SUCCESS',
    ];

    private const FAILED = [
        'AUTHORIZATION_FAILED',
        'FAILED',
        'DECLINED',
        'JUSPAY_DECLINED',
        'PAYMENT_FAILURE',
        'AUTHENTICATION_FAILED',
        'EXPIRED',
        'ERROR',
        'TXN_FAILURE',
    ];

    private const CANCELLED = [
        'CANCELLED',
        'CANCELED',
        'USER_DROPPED',
        'USER_CANCELLED',
    ];

    private const PENDING = [
        'CREATED',
        'PENDING',
        'NEW',
        'INITIATED',
        'AWAITING_PAYMENT',
        'PROCESSING',
    ];

    /**
     * Handle HDFC webhook.
     */
    public function handle(LaravelHttpRequest $request): JsonResponse
    {
        Log::info('HDFC webhook arrival', [
            'path'          => $request->path(),
            'has_order_id'  => $request->has('order_id'),
            'keys'          => array_keys($request->all()),
        ]);

        $order = $this->findOrder($request);

        if (!$order) {
            Log::warning('HDFC webhook has no matching order');
            // Acknowledge to prevent HDFC retries — order mil hi nahi raha
            return response()->json(['acknowledged' => true]);
        }

        try {
            $result = $this->reconcile($order);

            return response()->json([
                'acknowledged'   => true,
                'payment_status' => $result['payment_status'],
            ]);
        } catch (Throwable $e) {
            Log::error('HDFC webhook reconciliation error', [
                'order_id' => $order->id,
                'reason'   => $e->getMessage(),
            ]);

            // FALLBACK: webhook payload se status nikaal kar fail/cancel mark karein
            $webhookStatus = $this->extractStatusFromRequest($request);
            $isFailureEvent = $this->isFailureEvent($request);

            if (
                $isFailureEvent
                || in_array($webhookStatus, self::FAILED, true)
                || in_array($webhookStatus, self::CANCELLED, true)
            ) {
                try {
                    $this->failOrderFromWebhook($order, $webhookStatus ?: 'UNKNOWN_FAILURE');

                    $isCancelled = in_array($webhookStatus, self::CANCELLED, true);

                    return response()->json([
                        'acknowledged'   => true,
                        'payment_status' => $isCancelled ? 'cancelled' : 'failed',
                    ]);
                } catch (Throwable $e2) {
                    Log::error('HDFC webhook fallback fail-order failed', [
                        'order_id' => $order->id,
                        'error'    => $e2->getMessage(),
                    ]);
                }
            }

            return response()->json(['acknowledged' => false], 503);
        }
    }

    /**
     * Handle browser return (Success, Failure, Cancel sab yahin aayega).
     */
    public function return(LaravelHttpRequest $request): Response
    {
        return $this->browserResult($request, 'return');
    }

    /**
     * Handle browser cancel redirect.
     */
    public function cancel(LaravelHttpRequest $request): Response
    {
        return $this->browserResult($request, 'cancel');
    }

    /**
     * Verify payment and redirect to the frontend.
     */
    private function browserResult(
        LaravelHttpRequest $request,
        string $intent
    ): Response {
        $order = $this->findOrder($request);

        $result = [
            'payment_status' => 'pending',
        ];

        if ($order) {
            try {
                $result = $this->reconcile($order);
                $paymentStatus = $result['payment_status'];

                // Agar status CANCELLED ya FAILED hai, toh HDFC session end karein
                if (in_array($paymentStatus, ['cancelled', 'failed'], true)) {
                    try {
                        $configKey   = $order->payment_gateway === 'hdfc_ie' ? 'ie' : 'sg';
                        $hdfcService = new HdfcService(
                            config("services.hdfc.{$configKey}"),
                            $order->payment_gateway
                        );
                        $hdfcService->cancelOrder($order->order_reference);
                    } catch (Throwable $e) {
                        Log::error('HDFC Cancel API Failed', [
                            'order_id' => $order->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            } catch (Throwable $e) {
                Log::error('HDFC return verification failed', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);

                // SAFETY NET
                $returnStatus   = $this->extractStatusFromRequest($request);
                $isFailureEvent = $this->isFailureEvent($request);

                if (
                    $isFailureEvent
                    || in_array($returnStatus, self::FAILED, true)
                    || in_array($returnStatus, self::CANCELLED, true)
                ) {
                    try {
                        $fallback = $this->failOrderFromWebhook(
                            $order,
                            $returnStatus ?: ($intent === 'cancel' ? 'CANCELLED' : 'UNKNOWN_FAILURE')
                        );

                        $result['payment_status'] = in_array($returnStatus, self::CANCELLED, true)
                            ? 'cancelled'
                            : 'failed';

                        $result = array_merge($result, $fallback);
                    } catch (Throwable $e2) {
                        Log::error('HDFC return fallback fail-order failed', [
                            'order_id' => $order->id,
                            'error'    => $e2->getMessage(),
                        ]);
                        $result['payment_status'] = 'pending';
                    }
                } else {
                    $result['payment_status'] = 'pending';
                }
            }
        }

        // Redirect to Frontend
        $configKey = $order?->payment_gateway === 'hdfc_ie' ? 'ie' : 'sg';
        $frontendUrl = (string) config("services.hdfc.{$configKey}.frontend_result_url");

        if (!$frontendUrl) {
            Log::error('HDFC frontend URL missing', ['merchant' => $configKey]);
            abort(500, 'Payment result URL not configured');
        }

        $allowedUrls = array_filter([
            config('services.hdfc.sg.frontend_result_url'),
            config('services.hdfc.ie.frontend_result_url'),
        ]);

        if (
            !in_array($frontendUrl, $allowedUrls, true)
            || !str_starts_with($frontendUrl, 'https://')
        ) {
            abort(500, 'Invalid payment result destination');
        }

        $query = http_build_query([
            'gateway'         => 'hdfc',
            'order_reference' => $order?->order_reference ?? '',
            'payment_status'  => $result['payment_status'],
            'return_type'     => $intent,
        ]);

        $destination = rtrim($frontendUrl, '/') . '/?' . $query;

        $safeUrl = htmlspecialchars($destination, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $jsUrl   = json_encode($destination, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $html = '<!doctype html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment Result</title>
</head>
<body>
<h2>Returning to your store...</h2>
<p>Your payment status is being verified.</p>
<a href="' . $safeUrl . '">Continue to store</a>
<script>
window.location.replace(' . $jsUrl . ');
</script>
</body>
</html>';

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Get verified payment status for an authenticated order owner.
     */
    public function status(
        LaravelHttpRequest $request,
        Order $order
    ): JsonResponse {
        if ((int) $order->user_id !== (int) $request->user()->id) {
            abort(403);
        }

        if (!in_array($order->payment_gateway, ['hdfc_sg', 'hdfc_ie'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Not an HDFC order',
            ], 422);
        }

        try {
            $state = $this->reconcile($order);

            return response()->json([
                'success'         => true,
                'order_reference' => $order->order_reference,
                ...$state,
            ]);
        } catch (Throwable $e) {
            Log::error('HDFC status lookup error', [
                'order_id' => $order->id,
                'reason'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment verification temporarily unavailable',
            ], 503);
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Extract status from webhook / return payload.
     * HDFC various payload shapes support karta hai — sab try karo.
     */
    private function extractStatusFromRequest(LaravelHttpRequest $request): string
    {
        $candidates = [
            $request->input('status'),
            $request->input('order.status'),
            $request->input('content.order.status'),      // HDFC actual webhook
            $request->input('content.status'),
            $request->input('data.order.status'),
            $request->input('payment.status'),
            $request->input('content.payment.status'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtoupper(trim($candidate));
            }
        }

        return '';
    }

    /**
     * Detect failure by event_name — kyunki status field missing ho sakti hai.
     */
    private function isFailureEvent(LaravelHttpRequest $request): bool
    {
        $eventName = strtoupper((string) (
            $request->input('event_name')
            ?? $request->input('event')
            ?? $request->input('content.event_name')
            ?? ''
        ));

        if ($eventName === '') {
            return false;
        }

        return str_contains($eventName, 'FAIL')
            || str_contains($eventName, 'CANCEL')
            || str_contains($eventName, 'EXPIRED')
            || str_contains($eventName, 'DROPPED')
            || str_contains($eventName, 'DECLINED');
    }

    /**
     * Find the local order using its reference.
     */
    private function findOrder(LaravelHttpRequest $request): ?Order
    {
        $ids = array_values(array_unique(array_filter(
            array_map('strval', [
                $request->input('order_id'),
                $request->input('udf1'),
                $request->input('order.order_id'),
                $request->input('order_reference'),
                $request->input('content.order.order_id'),
                $request->input('content.order.udf1'),
            ]),
            fn($value) => trim($value) !== ''
        )));

        if (!$ids) {
            Log::warning('HDFC return: no order identifier', [
                'path'   => $request->path(),
                'method' => $request->method(),
                'keys'   => array_keys($request->all()),
            ]);
            return null;
        }

        return Order::query()
            ->whereIn('payment_gateway', ['hdfc_sg', 'hdfc_ie'])
            ->whereIn('order_reference', $ids)
            ->first();
    }

    /**
     * Verify HDFC payment status and process the appropriate action.
     */
    private function reconcile(Order $order): array
    {
        if (!in_array($order->payment_gateway, ['hdfc_sg', 'hdfc_ie'], true)) {
            throw new \RuntimeException('Unexpected gateway');
        }

        $configKey = $order->payment_gateway === 'hdfc_ie' ? 'ie' : 'sg';
        $service   = new HdfcService(
            config("services.hdfc.{$configKey}"),
            $order->payment_gateway
        );

        $gatewayOrderId = (string) $order->order_reference;

        // ---- RETRY LOGIC: HDFC sometimes returns incomplete/404 for fresh orders ----
        $data   = null;
        $status = '';
        $lastError = null;

        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $data = $service->getOrderStatus($gatewayOrderId, (string) $order->user_id);

                $status = strtoupper((string) (
                    data_get($data, 'status')
                    ?? data_get($data, 'order.status')
                    ?? ''
                ));

                // Agar status final (paid/failed/cancelled) hai, retry mat karo
                if (
                    in_array($status, self::PAID, true)
                    || in_array($status, self::FAILED, true)
                    || in_array($status, self::CANCELLED, true)
                ) {
                    break;
                }

                // Pending / empty status — thoda wait karke retry
                if ($attempt < $maxAttempts) {
                    sleep(2);
                }
            } catch (Throwable $e) {
                $lastError = $e;
                Log::warning('HDFC getOrderStatus attempt failed', [
                    'order_id' => $order->id,
                    'attempt'  => $attempt,
                    'error'    => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
                    sleep(2);
                }
            }
        }

        if ($data === null) {
            throw $lastError ?? new \RuntimeException('HDFC status fetch failed after retries');
        }

        // -----------------------------------------------------------------
        // SUCCESSFUL PAYMENT — yahan strict ID + amount/currency validate
        // -----------------------------------------------------------------
        if (in_array($status, self::PAID, true)) {
            $returnedId = (string) (
                data_get($data, 'order_id')
                ?? data_get($data, 'order.order_id')
                ?? ''
            );

            if (!$returnedId || $returnedId !== $gatewayOrderId) {
                throw new \RuntimeException('HDFC returned a mismatching order ID');
            }

            $amount   = data_get($data, 'amount') ?? data_get($data, 'order.amount');
            $expected = $order->total_payable;

            if (
                $amount === null
                || $expected === null
                || abs((float) $amount - (float) $expected) > 0.009
            ) {
                throw new \RuntimeException('Gateway amount does not match local order');
            }

            $currency = strtoupper((string) (
                data_get($data, 'currency')
                ?? data_get($data, 'order.currency')
                ?? ''
            ));

            if ($currency !== 'INR') {
                throw new \RuntimeException('Gateway currency missing or mismatch');
            }

            $transactionId = (string) (
                data_get($data, 'txn_id')
                ?? data_get($data, 'transaction_id')
                ?? data_get($data, 'id')
                ?? $gatewayOrderId
            );

            Cache::lock('hdfc-confirm-' . $order->id, 90)
                ->block(12, function () use ($order, $transactionId, $amount, $data) {
                    $fresh = Order::findOrFail($order->id);

                    if ($fresh->status !== 'confirmed' && $fresh->confirmed_at === null) {
                        if ($fresh->status !== 'pending') {
                            throw new \RuntimeException('Refusing to confirm an order in unexpected state');
                        }

                        $this->checkoutService->confirmOrder(
                            $fresh->order_reference,
                            [
                                'gateway'        => $fresh->payment_gateway,
                                'transaction_id' => $transactionId,
                                'amount'         => (float) $amount,
                                'method'         => (string) (
                                    data_get($data, 'payment_method_type')
                                    ?? 'hdfc'
                                ),
                                'status'         => 'captured',
                            ]
                        );
                    }
                });

            return [
                'payment_status' => 'paid',
                'gateway_status' => $status,
            ];
        }

        // -----------------------------------------------------------------
        // CANCELLED PAYMENT — amount/currency/ID validate NA karein
        // -----------------------------------------------------------------
        if (in_array($status, self::CANCELLED, true)) {
            Log::info('HDFC payment cancelled', [
                'order_id' => $order->id,
                'status'   => $status,
            ]);

            $result = $this->failOrderFromWebhook($order, $status);

            return [
                'payment_status' => 'cancelled',
                'gateway_status' => $status,
                ...$result,
            ];
        }

        // -----------------------------------------------------------------
        // FAILED PAYMENT — amount/currency/ID validate NA karein
        // -----------------------------------------------------------------
        if (in_array($status, self::FAILED, true)) {
            Log::info('HDFC payment failed', [
                'order_id' => $order->id,
                'status'   => $status,
            ]);

            $result = $this->failOrderFromWebhook($order, $status);

            return [
                'payment_status' => 'failed',
                'gateway_status' => $status,
                ...$result,
            ];
        }

        // Pending / unknown status
        Log::info('HDFC payment still pending', [
            'order_id' => $order->id,
            'status'   => $status,
        ]);

        return [
            'payment_status' => 'pending',
            'gateway_status' => $status,
        ];
    }

    /**
     * Mark an order and all its order lines as payment_failed.
     *
     * - Idempotent (dobara call karne par safe)
     * - Confirmed order ko overwrite nahi karta
     * - Lock timeout gracefully handle karta hai
     * - DB transaction + row lock use karta hai
     */
    private function failOrderFromWebhook(Order $order, string $gatewayStatus): array
    {
        try {
            return Cache::lock('hdfc-confirm-' . $order->id, 90)
                ->block(12, function () use ($order, $gatewayStatus) {
                    return DB::transaction(function () use ($order, $gatewayStatus) {
                        $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

                        // Confirmed order kabhi fail na karein
                        if ($fresh->status === 'confirmed' || $fresh->confirmed_at !== null) {
                            Log::warning('HDFC failure received for an already confirmed order', [
                                'order_id'       => $fresh->id,
                                'gateway_status' => $gatewayStatus,
                            ]);

                            return [
                                'order_updated' => false,
                                'message'       => 'Order is already confirmed; status was not changed.',
                            ];
                        }

                        // Idempotent
                        if ($fresh->status === 'payment_failed') {
                            Log::info('HDFC failure: order already payment_failed', [
                                'order_id'       => $fresh->id,
                                'gateway_status' => $gatewayStatus,
                            ]);

                            return [
                                'order_updated'      => false,
                                'order_id'           => $fresh->id,
                                'order_reference'    => $fresh->order_reference,
                                'order_status'       => 'payment_failed',
                                'order_lines_status' => 'payment_failed',
                            ];
                        }

                        // Order update
                        $fresh->update(['status' => 'payment_failed']);

                        // Order lines update
                        $updatedLines = OrderLine::where('order_id', $fresh->id)
                            ->where('delivery_status', '!=', 'payment_failed')
                            ->update([
                                'delivery_status' => 'payment_failed',
                            ]);

                        Log::warning('HDFC failed payment: order and order lines updated', [
                            'order_id'             => $fresh->id,
                            'order_reference'      => $fresh->order_reference,
                            'gateway_status'       => $gatewayStatus,
                            'order_lines_updated'  => $updatedLines,
                        ]);

                        return [
                            'order_updated'       => true,
                            'order_id'            => $fresh->id,
                            'order_reference'     => $fresh->order_reference,
                            'order_status'        => 'payment_failed',
                            'order_lines_status'  => 'payment_failed',
                            'order_lines_updated' => $updatedLines,
                        ];
                    });
                });
        } catch (LockTimeoutException $e) {
            Log::error('HDFC fail-order lock timeout', [
                'order_id'       => $order->id,
                'gateway_status' => $gatewayStatus,
            ]);

            return [
                'order_updated' => false,
                'message'       => 'Lock timeout; another process is reconciling this order.',
            ];
        }
    }
}
