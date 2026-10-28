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

    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * Handle AxisPG server-to-server callback / webhook.
     * AxisPG POSTs { clientId, encData } here.
     */
    public function handle(Request $request): JsonResponse
    {
        Log::info('AxisPG webhook received', $request->all());

        try {
            // 1. Basic validation
            $clientId = $request->input('clientId');
            $encData  = $request->input('encData');

            if ($clientId !== config('services.axispg.merchant_id')) {
                Log::warning('AxisPG webhook: clientId mismatch', ['got' => $clientId]);
                return response()->json(['success' => false], 400);
            }

            if (empty($encData)) {
                Log::warning('AxisPG webhook: encData missing');
                return response()->json(['success' => false], 400);
            }

            // 2. Decrypt encData (JWS verify + JWE decrypt)
            // ⚠️ NOT YET IMPLEMENTED — needs FCPG Public Key + merchant private key.
            // Replace the pseudo-code below with real decryption.
            $axispg = new AxisPgService(config('services.axispg'));
            // $payload = $axispg->decryptEncData($encData);

            // PSEUDO for now:
            // $payload = [
            //     'statusCode'     => 'SPG-0000',
            //     'merchantTxnId'  => 'ORD...',
            //     'txnReferenceId' => '...',
            //     'amount'         => 100,
            //     'signature'      => '...',
            // ];

            // 3. Verify the signature inside the decrypted payload
            // $payloadWithoutSig = $payload;
            // unset($payloadWithoutSig['signature']);
            // $expected = $axispg->generateSignature($payloadWithoutSig);
            // if (!hash_equals($expected, $payload['signature'])) {
            //     Log::warning('AxisPG webhook: signature mismatch');
            //     return response()->json(['success' => false], 400);
            // }

            // 4. Handle the status
            // $statusCode = $payload['statusCode'];
            // $orderRef   = $payload['merchantTxnId'];
            //
            // if ($statusCode === 'SPG-0000') {
            //     // SUCCESS → confirm order
            //     $this->checkoutService->confirmOrder($orderRef, [
            //         'gateway'        => 'axispg',
            //         'transaction_id' => $payload['txnReferenceId'],
            //     ]);
            // } elseif ($statusCode === 'SPG-0001') {
            //     // FAILED
            //     Order::where('order_reference', $orderRef)
            //         ->update(['status' => 'failed']);
            // }
            // // SPG-0002 = PENDING → leave order as pending; poll Status API

            // 5. Always return 200 so AxisPG does not retry
            return response()->json(['success' => true], 200);

        } catch (Exception $e) {
            Log::error('AxisPG webhook error', ['error' => $e->getMessage()]);
            // Return 500 so AxisPG retries
            return response()->json(['success' => false], 500);
        }
    }
}