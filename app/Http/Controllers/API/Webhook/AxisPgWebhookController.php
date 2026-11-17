<?php

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class AxisPgWebhookController extends Controller
{
    protected CheckoutService $checkoutService;

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    public function handle(Request $request)
    {
        Log::info('AxisPG webhook received', $request->all());

        try {
            $merchantTxnId  = $request->input('merchantTxnId');
            $statusCode     = $request->input('statusCode');
            $signature      = $request->input('signature');
            $txnReferenceId = $request->input('txnReferenceId');
            $amount         = $request->input('amount');

            if (empty($merchantTxnId) || empty($statusCode) || empty($signature)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields'
                ], 400);
            }

            // Find order with user
            $order = Order::with('user')
                ->where('order_reference', $merchantTxnId)
                ->first();

            if (!$order) {
                Log::warning('AxisPG webhook: Order not found', [
                    'merchantTxnId' => $merchantTxnId
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $user          = $order->user;
            $isDistributor = $user && $user->account_type === 'distributor';

            // Base URLs from config
            $distributorBase = config('services.frontend.distributor_url', 'https://www.markupdesigns.net/indiekonnect-distributor');
            $customerBase    = config('services.frontend.customer_url', 'https://www.markupdesigns.net/indiekonnect-web');

            // History / profile URL (post-payment view)
            $historyUrl = $isDistributor
                ? $distributorBase . '/distributor/order-history/'
                : $customerBase . '/profile/?tab=orders';

            // Checkout retry URL (for failed / cancelled payments)
            $checkoutBase = $isDistributor
                ? $distributorBase . '/checkout/'
                : $customerBase . '/checkout/';

            $retryUrl = $checkoutBase . '?' . http_build_query([
                'checkout_type'   => 'cart',
                'address_id'      => $order->address_id,
                'order_reference' => $merchantTxnId,
                'gateway'         => $order->payment_gateway,
                'payment_status'  => 'pending',
                'cancelled'       => 'true',
                'show_repay'      => 'true',
            ]);

            if ($statusCode === 'SPG-0000') {

                // Payment SUCCESS
                $this->checkoutService->confirmOrder($merchantTxnId, [
                    'gateway'        => 'axispg',
                    'transaction_id' => $txnReferenceId,
                    'amount'         => $amount
                ]);

                Log::info('AxisPG webhook: Order confirmed', [
                    'merchantTxnId' => $merchantTxnId
                ]);

                // Redirect to history/profile after success
                return redirect()->away($historyUrl);
            } elseif (in_array($statusCode, ['SPG-0001', 'SPG-0003'])) {

                // Payment FAILED / CANCELLED
                $order->update([
                    'status' => 'payment_failed'
                ]);

                Log::info('AxisPG webhook: Payment failed/cancelled', [
                    'merchantTxnId' => $merchantTxnId,
                    'retry_url'     => $retryUrl,
                    'history_url'   => $historyUrl,
                ]);

                // Redirect user back to checkout with show_repay=true
                return redirect()->away($retryUrl);
            } elseif ($statusCode === 'SPG-0002') {

                // Payment PENDING
                Log::info('AxisPG webhook: Payment pending', [
                    'merchantTxnId' => $merchantTxnId
                ]);

                // Optionally redirect to history (or keep as JSON if S2S only)
                return redirect()->away($historyUrl);
            } else {

                Log::warning('AxisPG webhook: Unknown status code', [
                    'statusCode' => $statusCode
                ]);

                return redirect()->away($historyUrl);
            }
        } catch (Exception $e) {

            Log::error('AxisPG webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error'
            ], 500);
        }
    }
}
