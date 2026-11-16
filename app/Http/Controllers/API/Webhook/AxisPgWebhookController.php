<?php

namespace App\Http\Controllers\API\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\PaymentGateway\AxisPgService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class AxisPgWebhookController extends Controller
{
    protected CheckoutService $checkoutService;

    // 1. AxisPgService ko constructor se hata diya
    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * Handle AxisPG server-to-server callback / webhook.
     * Handles PLAIN TEXT form-data payload (Sandbox Mode).
     */
    public function handle(Request $request): JsonResponse
    {
        // 1. Log the incoming request for debugging
        Log::info('AxisPG webhook received (Plain Text)', $request->all());

        try {
            // 2. Extract required fields from the plain text payload
            $merchantTxnId  = $request->input('merchantTxnId');
            $statusCode     = $request->input('statusCode');
            $signature      = $request->input('signature');
            $txnReferenceId = $request->input('txnReferenceId');
            $amount         = $request->input('amount');

            // 3. Basic Validation: Ensure required fields are present
            if (empty($merchantTxnId) || empty($statusCode) || empty($signature)) {
                Log::warning('AxisPG webhook: Missing required fields', [
                    'merchantTxnId' => $merchantTxnId,
                    'statusCode'    => $statusCode,
                    'signature'     => $signature
                ]);
                return response()->json(['success' => false, 'message' => 'Missing required fields'], 400);
            }

            // 4. Manually instantiate AxisPgService here
            $axispg = new AxisPgService(config('services.axispg'));

            // 5. Verify Signature (CRITICAL for security)
            // We remove the 'signature' key from the payload to generate our own signature
            $dataToVerify = $request->except(['signature']);
            
            $expectedSignature = $axispg->generateSignature($dataToVerify);

            if (!hash_equals($expectedSignature, $signature)) {
                Log::warning('AxisPG webhook: Signature mismatch', [
                    'received_signature' => $signature,
                    'expected_signature' => $expectedSignature
                ]);
                return response()->json(['success' => false, 'message' => 'Invalid signature'], 400);
            }

            // 6. Handle the Order Status based on statusCode
            if ($statusCode === 'SPG-0000') {
                // Payment SUCCESS
                $this->checkoutService->confirmOrder($merchantTxnId, [
                    'gateway'        => 'axispg',
                    'transaction_id' => $txnReferenceId,
                    'amount'         => $amount
                ]);
                Log::info('AxisPG webhook: Order confirmed successfully', ['merchantTxnId' => $merchantTxnId]);

            } elseif ($statusCode === 'SPG-0001') {
                // Payment FAILED
                Order::where('order_reference', $merchantTxnId)->update(['status' => 'failed']);
                Log::info('AxisPG webhook: Order marked as failed', ['merchantTxnId' => $merchantTxnId]);

            } elseif ($statusCode === 'SPG-0002') {
                // Payment PENDING
                Log::info('AxisPG webhook: Payment pending', ['merchantTxnId' => $merchantTxnId]);
                // You might want to leave the order as pending and rely on a status API poll later.
            } else {
                // Unknown status
                Log::warning('AxisPG webhook: Unknown status code received', ['statusCode' => $statusCode]);
            }

            // 7. Always return 200 OK to stop the gateway from retrying
            return response()->json(['success' => true], 200);

        } catch (Exception $e) {
            Log::error('AxisPG webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Return 500 so the gateway knows something went wrong internally
            return response()->json(['success' => false, 'message' => 'Internal Server Error'], 500);
        }
    }

}