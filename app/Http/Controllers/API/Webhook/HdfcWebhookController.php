<?php

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\CheckoutService;
use App\Services\PaymentGateway\HdfcService;
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
    ];

    private const FAILED = [
        'AUTHORIZATION_FAILED',
        'FAILED',
        'DECLINED',
        'JUSPAY_DECLINED',
        'PAYMENT_FAILURE',
        'AUTHENTICATION_FAILED',
        'EXPIRED',
    ];

    private const CANCELLED = [
        'CANCELLED',
        'USER_DROPPED',
    ];

    /**
     * Handle HDFC webhook.
     */
    public function handle(LaravelHttpRequest $request): JsonResponse
    {
        Log::info('HDFC webhook arrival', [
            'path' => $request->path(),
            'has_order_id' => $request->has('order_id'),
        ]);

        $order = $this->findOrder($request);

        if (!$order) {
            Log::warning('HDFC webhook has no matching order');

            return response()->json([
                'acknowledged' => true,
            ]);
        }

        try {
            $result = $this->reconcile($order);

            return response()->json([
                'acknowledged' => true,
                'payment_status' => $result['payment_status'],
            ]);
        } catch (Throwable $e) {
            Log::error('HDFC webhook reconciliation error', [
                'order_id' => $order->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'acknowledged' => false,
            ], 503);
        }
    }

    /**
     * Handle browser return.
     */
    public function return(LaravelHttpRequest $request): Response
    {
        return $this->browserResult($request, 'return');
    }

    /**
     * Handle browser cancellation.
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
            } catch (Throwable $e) {
                Log::error('HDFC return verification failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $configKey = $order?->payment_gateway === 'hdfc_ie'
            ? 'ie'
            : 'sg';

        $frontendUrl = (string) config(
            "services.hdfc.{$configKey}.frontend_result_url"
        );

        if (!$frontendUrl) {
            Log::error('HDFC frontend URL missing', [
                'merchant' => $configKey,
            ]);

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
            'gateway' => 'hdfc',
            'order_reference' => $order?->order_reference ?? '',
            'payment_status' => $result['payment_status'],
            'return_type' => $intent,
        ]);

        $destination = rtrim($frontendUrl, '/') . '/?' . $query;

        $safeUrl = htmlspecialchars(
            $destination,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $jsUrl = json_encode(
            $destination,
            JSON_HEX_TAG | JSON_HEX_AMP |
                JSON_HEX_APOS | JSON_HEX_QUOT
        );

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

        if (!in_array(
            $order->payment_gateway,
            ['hdfc_sg', 'hdfc_ie'],
            true
        )) {
            abort(422, 'Not an HDFC order');
        }

        try {
            $state = $this->reconcile($order);

            return response()->json([
                'success' => true,
                'order_reference' => $order->order_reference,
                ...$state,
            ]);
        } catch (Throwable $e) {
            Log::error('HDFC status lookup error', [
                'order_id' => $order->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment verification temporarily unavailable',
            ], 503);
        }
    }

    /**
     * Find the local order using its reference.
     */
    private function findOrder(
        LaravelHttpRequest $request
    ): ?Order {
        $ids = array_values(array_unique(array_filter(
            array_map('strval', [
                $request->input('order_id'),
                $request->input('udf1'),
                $request->input('order.order_id'),
                $request->input('order_reference'),
            ]),
            fn($value) => trim($value) !== ''
        )));

        if (!$ids) {
            Log::warning('HDFC return: no order identifier', [
                'path' => $request->path(),
                'method' => $request->method(),
                'keys' => array_keys($request->all()),
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
        if (!in_array(
            $order->payment_gateway,
            ['hdfc_sg', 'hdfc_ie'],
            true
        )) {
            throw new \RuntimeException('Unexpected gateway');
        }

        $configKey = $order->payment_gateway === 'hdfc_ie'
            ? 'ie'
            : 'sg';

        $service = new HdfcService(
            config("services.hdfc.{$configKey}"),
            $order->payment_gateway
        );

        // Our order reference was used as the HDFC gateway order ID.
        $gatewayOrderId = (string) $order->order_reference;

        $data = $service->getOrderStatus(
            $gatewayOrderId,
            (string) $order->user_id
        );

        $status = strtoupper((string) (
            data_get($data, 'status')
            ?? data_get($data, 'order.status')
            ?? ''
        ));

        $returnedId = (string) (
            data_get($data, 'order_id')
            ?? data_get($data, 'order.order_id')
            ?? ''
        );

        if (!$returnedId || $returnedId !== $gatewayOrderId) {
            throw new \RuntimeException(
                'HDFC returned a mismatching order ID'
            );
        }

        // Verify amount before updating any local payment status.
        $amount = data_get($data, 'amount')
            ?? data_get($data, 'order.amount');

        $expected = $order->total_payable;

        if (
            $amount === null
            || $expected === null
            || abs((float) $amount - (float) $expected) > 0.009
        ) {
            throw new \RuntimeException(
                'Gateway amount does not match local order'
            );
        }

        $currency = strtoupper((string) (
            data_get($data, 'currency')
            ?? data_get($data, 'order.currency')
            ?? ''
        ));

        if ($currency !== 'INR') {
            throw new \RuntimeException(
                'Gateway currency missing or mismatch'
            );
        }

        // Successful payment.
        if (in_array($status, self::PAID, true)) {
            $transactionId = (string) (
                data_get($data, 'txn_id')
                ?? data_get($data, 'transaction_id')
                ?? data_get($data, 'id')
                ?? $gatewayOrderId
            );

            Cache::lock('hdfc-confirm-' . $order->id, 90)
                ->block(12, function () use (
                    $order,
                    $transactionId,
                    $amount,
                    $data
                ) {
                    $fresh = Order::findOrFail($order->id);

                    // Do not confirm the order twice.
                    if (
                        $fresh->status !== 'confirmed'
                        && $fresh->confirmed_at === null
                    ) {
                        if ($fresh->status !== 'pending') {
                            throw new \RuntimeException(
                                'Refusing to confirm an order in unexpected state'
                            );
                        }

                        $this->checkoutService->confirmOrder(
                            $fresh->order_reference,
                            [
                                'gateway' => $fresh->payment_gateway,
                                'transaction_id' => $transactionId,
                                'amount' => (float) $amount,
                                'method' => (string) (
                                    data_get($data, 'payment_method_type')
                                    ?? 'hdfc'
                                ),
                                'status' => 'captured',
                            ]
                        );
                    }
                });

            return [
                'payment_status' => 'paid',
                'gateway_status' => $status,
            ];
        }

        // Cancelled payment.
        if (in_array($status, self::CANCELLED, true)) {
            Log::info('HDFC payment cancelled', [
                'order_id' => $order->id,
                'status' => $status,
            ]);

            return [
                'payment_status' => 'cancelled',
                'gateway_status' => $status,
            ];
        }

        // Failed payment: call the separate function.
        if (in_array($status, self::FAILED, true)) {
            $result = $this->failOrderFromWebhook(
                $order,
                $status
            );

            return [
                'payment_status' => 'failed',
                'gateway_status' => $status,
                ...$result,
            ];
        }

        return [
            'payment_status' => 'pending',
            'gateway_status' => $status,
        ];
    }

    /**
     * Mark an order and all its order lines as payment_failed.
     */
    private function failOrderFromWebhook(
        Order $order,
        string $gatewayStatus
    ): array {
        return Cache::lock('hdfc-confirm-' . $order->id, 90)
            ->block(12, function () use ($order, $gatewayStatus) {
                return DB::transaction(function () use (
                    $order,
                    $gatewayStatus
                ) {
                    $fresh = Order::whereKey($order->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    // Never overwrite an already confirmed/paid order.
                    if (
                        $fresh->status === 'confirmed'
                        || $fresh->confirmed_at !== null
                    ) {
                        Log::warning(
                            'HDFC failure received for an already confirmed order',
                            [
                                'order_id' => $fresh->id,
                                'gateway_status' => $gatewayStatus,
                            ]
                        );

                        return [
                            'order_updated' => false,
                            'message' => 'Order is already confirmed; status was not changed.',
                        ];
                    }

                    // Update order status.
                    $fresh->update([
                        'status' => 'payment_failed',
                    ]);

                    // Update every line belonging to this order.
                    $updatedLines = OrderLine::where(
                        'order_id',
                        $fresh->id
                    )->update([
                        'delivery_status' => 'payment_failed',
                    ]);

                    Log::warning(
                        'HDFC failed payment: order and order lines updated',
                        [
                            'order_id' => $fresh->id,
                            'order_reference' => $fresh->order_reference,
                            'gateway_status' => $gatewayStatus,
                            'order_lines_updated' => $updatedLines,
                        ]
                    );

                    return [
                        'order_updated' => true,
                        'order_id' => $fresh->id,
                        'order_reference' => $fresh->order_reference,
                        'order_status' => 'payment_failed',
                        'order_lines_status' => 'payment_failed',
                        'order_lines_updated' => $updatedLines,
                    ];
                });
            });
    }
}
