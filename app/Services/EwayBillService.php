<?php



namespace App\Services;



use App\Models\Invoice;

use Illuminate\Support\Facades\Http;

use Illuminate\Support\Facades\Cache;

use Illuminate\Support\Facades\Log;

use Exception;



class EwayBillService

{

    protected string $baseUrl;

    protected string $apiKey;

    protected string $apiSecret;

    protected string $apiVersion;



    public function __construct()

    {

        $this->baseUrl    = config('services.kyc.base_url', 'https://test-api.sandbox.co.in');

        $this->apiKey     = config('services.kyc.api_key');

        $this->apiSecret  = config('services.kyc.api_secret');

        $this->apiVersion = config('services.kyc.api_version', '1.0.0');

    }



    /* =========================================================

     |  STEP 1: Sandbox Access Token

     |  POST /authenticate

     |  Headers: x-api-key, x-api-secret, x-api-version

     |  Response: data.access_token

     | ========================================================= */

    protected function getAccessToken(): string

    {

        return Cache::remember('sandbox_access_token', now()->addHours(23), function () {

            $response = Http::withHeaders([

                'x-api-key'     => $this->apiKey,

                'x-api-secret'  => $this->apiSecret,

                'x-api-version' => $this->apiVersion,

                'Content-Type'  => 'application/json',

            ])->post($this->baseUrl . '/authenticate');



            $data = $response->json();



            if ($response->failed() || empty($data['data']['access_token'])) {

                Log::error('Sandbox Auth Failed', ['response' => $response->body()]);

                throw new Exception("Failed to generate Sandbox Access Token. Check API Key/Secret.");

            }



            return $data['data']['access_token'];

        });

    }



    /* =========================================================

     |  STEP 2: E-Way Bill Portal Token (tax-payer)

     |  POST /gst/compliance/e-way-bill/tax-payer/authenticate

     |  Headers: authorization = Step 1 token, x-api-key, x-api-version

     |  Response: data.access_token  ← THIS goes to /consignor/bill

     | ========================================================= */

    protected function getEwbAccessToken(): string

    {

        return Cache::remember('eway_bill_access_token', now()->addHours(5), function () {

            $sandboxToken = $this->getAccessToken();



            $response = Http::withHeaders([

                'authorization' => $sandboxToken,

                'x-api-key'     => $this->apiKey,

                'x-api-version' => $this->apiVersion,

                'Content-Type'  => 'application/json',

            ])->post($this->baseUrl . '/gst/compliance/e-way-bill/tax-payer/authenticate', [

                'username' => config('services.eway_bill.username'),

                'password' => config('services.eway_bill.password'),

                'gstin'    => config('services.eway_bill.gstin'),

            ]);



            $data = $response->json();



            if (empty($data['data']['access_token'])) {

                Log::error('E-Way Bill portal auth failed', [

                    'response' => $data,

                    'status'   => $response->status(),

                ]);

                throw new Exception('Failed to authenticate with E-Way Bill portal. Check username/password/GSTIN.');

            }



            return $data['data']['access_token'];

        });

    }



    /* =========================================================

     |  STEP 3: Generate E-Way Bill Part A only (real invoice)

     |  POST /gst/compliance/e-way-bill/consignor/bill

     |  Headers: authorization = Step 2 token

     | ========================================================= */

    public function generatePartA(Invoice $invoice, string $transporterId): array

    {

        // 1. Validate transporter ID

        if (empty($transporterId) || strlen($transporterId) !== 15) {

            throw new Exception("Invalid Transporter ID. It must be a 15-character GSTIN/TRANSIN.");

        }



        // 2. Build payload

        $payload = $this->buildPayload($invoice, $transporterId);



        // 3. Get EWB-portal token (⚠️ NOT the sandbox token)

        $token = $this->getEwbAccessToken();



        // 4. Call Sandbox API

        try {

            $response = Http::withHeaders([

                'authorization' => $token,

                'x-api-key'     => $this->apiKey,

                'x-api-version' => $this->apiVersion,

                'Content-Type'  => 'application/json',

            ])->post($this->baseUrl . '/gst/compliance/e-way-bill/consignor/bill', $payload);



            $responseData = $response->json();



            // 5. Handle NIC business failure

            if (!isset($responseData['data']['status_cd'])

                || (string) $responseData['data']['status_cd'] !== '1') {



                $errorData    = $responseData['data']['error'] ?? [];

                $errorMessage = is_array($errorData)

                    ? ($errorData['message'] ?? 'Unknown E-Way Bill error')

                    : $errorData;

                $errorCode = is_array($errorData) ? ($errorData['error_cd'] ?? '') : '';



                Log::error('E-Way Bill NIC failure', [

                    'invoice_number' => $invoice->invoice_number,

                    'response'       => $responseData,

                    'payload'        => $payload,

                ]);



                throw new Exception("E-Way Bill generation failed: {$errorMessage} (Code: {$errorCode})");

            }



            // 6. Extract fields

            $ewbNo     = $responseData['data']['data']['ewayBillNo'] ?? null;

            $ewbDate   = $responseData['data']['data']['ewayBillDate'] ?? null;

            $validUpto = $responseData['data']['data']['validUpto'] ?? null;



            if (empty($ewbNo)) {

                throw new Exception("E-Way Bill number not found in response.");

            }



            // 7. Alert log

            $alert = $responseData['data']['data']['alert'] ?? null;

            if (!empty($alert)) {

                Log::warning('E-Way Bill Part A generated with alert', [

                    'invoice_number' => $invoice->invoice_number,

                    'eway_bill_no'   => $ewbNo,

                    'alert'          => $alert,

                ]);

            }



            // 8. Return normalized

            return [

                'eway_bill_no'   => (string) $ewbNo,

                'eway_bill_date' => $ewbDate,

                'valid_upto'     => $validUpto,

                'alert'          => $alert,

            ];



        } catch (Exception $e) {

            Log::error('E-Way Bill Part A generation failed', [

                'invoice_number' => $invoice->invoice_number,

                'transporter_id' => $transporterId,

                'error'          => $e->getMessage(),

                'payload'        => $payload,

            ]);

            throw $e;

        }

    }



    /* =========================================================

     |  MOCK TEST — exact curl body from Sandbox docs

     |  Use this ONLY to verify token flow works end-to-end.

     |  Remove/guard before production.

     | ========================================================= */

     
public function testMockGeneration(): array
{
    // E-Way Bill portal access token
    $token = $this->getEwbAccessToken();

    // Exact static payload provided by Sandbox
    $payload = [
        'supplyType' => 'O',
        'subSupplyType' => '1',
        'subSupplyDesc' => '',
        'docType' => 'INV',
        'docNo' => '7001-11',
        'docDate' => '15/12/2017',

        'fromGstin' => '29AAACQ3770E000',
        'fromTrdName' => 'welton',
        'fromAddr1' => '2ND CROSS NO 59  19  A',
        'fromAddr2' => 'GROUND FLOOR OSBORNE ROAD',
        'fromPlace' => 'FRAZER TOWN',
        'fromPincode' => 400027,
        'actFromStateCode' => 27,
        'fromStateCode' => 27,

        'toGstin' => '27AAACQ3770E000',
        'toTrdName' => 'sthuthya',
        'toAddr1' => 'Shree Nilaya',
        'toAddr2' => 'Dasarahosahalli',
        'toPlace' => 'Beml Nagar',
        'toPincode' => 560090,
        'actToStateCode' => 29,
        'toStateCode' => 29,

        'transactionType' => 4,
        'otherValue' => 0,
        'totalValue' => 56099,
        'cgstValue' => 0,
        'sgstValue' => 0,
        'igstValue' => 1682.97,
        'cessValue' => 1682.97,
        'cessNonAdvolValue' => 0,
        'totInvValue' => 59464.94,

        'transporterId' => '27AAACQ3770E004',
        'transporterName' => '',
        'transDocNo' => '',
        'transMode' => '1',
        'transDistance' => '1000',
        'transDocDate' => '',
        'vehicleNo' => 'PVC1234',
        'vehicleType' => 'R',

        'itemList' => [
            [
                'productName' => 'Wheat',
                'productDesc' => 'Wheat',
                'hsnCode' => 1001,
                'quantity' => 4,
                'qtyUnit' => 'BOX',
                'cgstRate' => 0,
                'sgstRate' => 0,
                'igstRate' => 3,
                'cessRate' => 3,
                'cessNonadvol' => 0,
                'taxableAmount' => 56099,
            ],
        ],
    ];

    try {
        $response = Http::withHeaders([
            'authorization' => $token,
            'x-api-key' => $this->apiKey,
            'x-api-version' => $this->apiVersion,
            'Content-Type' => 'application/json',
        ])->post(
            $this->baseUrl . '/gst/compliance/e-way-bill/consignor/bill',
            $payload
        );

        $responseData = $response->json();

        Log::info('E-Way Bill STATIC MOCK TEST', [
            'http_status' => $response->status(),
            'payload' => $payload,
            'response' => $responseData,
        ]);

        return [
            'success' => (string) (
                $responseData['data']['status']
                ?? $responseData['data']['status_cd']
                ?? ''
            ) === '1',
            'http_status' => $response->status(),
            'response' => $responseData,
        ];

    } catch (\Exception $e) {
        Log::error('E-Way Bill STATIC MOCK TEST FAILED', [
            'error' => $e->getMessage(),
        ]);

        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}



    /* =========================================================

     |  Payload builder — real invoice (Part A only)

     | ========================================================= */

    protected function buildPayload(Invoice $invoice, string $transporterId): array

    {

        $order = $invoice->order;

        if (!$order) {

            throw new Exception("Invoice has no associated order.");

        }



        $order->loadMissing(['deliveryAddress', 'billingAddress', 'user', 'lines.product']);



        $deliveryAddress = $order->deliveryAddress;

        $user            = $order->user;



        if (!$deliveryAddress) {

            throw new Exception("Delivery address is missing for order {$order->order_reference}.");

        }



        $items = $this->buildItems($invoice);



        // Consignor (your company)

        $consignorGstin = config('services.company.gstin');

        $consignorName  = config('services.company.name');

        $consignorAddr1 = config('services.company.address_line_1');

        $consignorAddr2 = config('services.company.address_line_2');

        $consignorPlace = config('services.company.city');

        $consignorPin   = config('services.company.pincode');

        $consignorState = config('services.company.state_code');



        if (empty($consignorGstin)) {

            throw new Exception("Consignor GSTIN is not configured.");

        }



        // Consignee (buyer) — 'URP' if no GSTIN

        $consigneeGstin = !empty($user->gstin) ? $user->gstin : 'URP';

        $consigneeName  = $deliveryAddress->recipient_name

            ?? $deliveryAddress->full_name

            ?? $user->name

            ?? 'Customer';

        $consigneeAddr1 = $deliveryAddress->address_line_1;

        $consigneeAddr2 = $deliveryAddress->address_line_2 ?? '';

        $consigneePlace = $deliveryAddress->city;

        $consigneePin   = $deliveryAddress->postcode

            ?? $deliveryAddress->postal_code

            ?? $deliveryAddress->pincode;

        $consigneeState = $this->getStateCodeFromName($deliveryAddress->state);



        if (empty($consigneeAddr1) || empty($consigneePlace) || empty($consigneePin)) {

            throw new Exception(

                "Incomplete delivery address for order {$order->order_reference}. " .

                "Address line 1, city and pincode are required."

            );

        }



        if (!preg_match('/^\d{6}$/', (string) $consigneePin)) {

            throw new Exception(

                "Invalid consignee pincode '{$consigneePin}' for order {$order->order_reference}. Must be 6 digits."

            );

        }



        $totalInvoiceValue = (float) ($invoice->total_value ?? $invoice->total_payable ?? 0);



        // Part A payload (no vehicleNo / transMode / transDistance)

        return [

            'supplyType'       => 'O',

            'subSupplyType'    => '1',

            'subSupplyDesc'    => '',

            'docType'          => 'INV',

            'docNo'            => $invoice->invoice_number,

            'docDate'          => $invoice->created_at->format('d/m/Y'),



            'fromGstin'        => $consignorGstin,

            'fromTrdName'      => $consignorName,

            'fromAddr1'        => $consignorAddr1,

            'fromAddr2'        => $consignorAddr2,

            'fromPlace'        => $consignorPlace,

            'fromPincode'      => (int) $consignorPin,

            'fromStateCode'    => (int) $consignorState,

            'actFromStateCode' => (int) $consignorState,



            'toGstin'          => $consigneeGstin,

            'toTrdName'        => $consigneeName,

            'toAddr1'          => $consigneeAddr1,

            'toAddr2'          => $consigneeAddr2,

            'toPlace'          => $consigneePlace,

            'toPincode'        => (int) $consigneePin,

            'toStateCode'      => (int) $consigneeState,

            'actToStateCode'   => (int) $consigneeState,



            'transactionType'  => 1,

            'totalValue'       => $totalInvoiceValue,

            'totInvValue'      => $totalInvoiceValue,

            'transporterId'      => $transporterId,

            'transporterName'    => '',

            'transDocNo'         => '',

            'transMode'          => '1',

            'transDistance'      => '1000',

            'transDocDate'       => '',

            'vehicleNo'          => 'PVC1234',

            'vehicleType'         => 'R',



            'itemList'         => $items,

        ];

    }



    /* =========================================================

     |  Item list — THIS invoice only

     | ========================================================= */

    protected function buildItems(Invoice $invoice): array

    {

        $items = [];



        $orderLines = $invoice->order->lines ?? collect();



        if (!empty($invoice->order_line_id)) {

            $orderLines = $orderLines->where('id', $invoice->order_line_id);

        }



        if ($orderLines->isEmpty()) {

            throw new Exception("No matching order line found for invoice {$invoice->invoice_number}.");

        }



        foreach ($orderLines as $line) {

            $product = $line->product;



            if (!$product) {

                throw new Exception(

                    "Product missing for order line {$line->id} in invoice {$invoice->invoice_number}."

                );

            }



            $taxableAmount = (float) ($line->taxable_amount

                ?? ($line->unit_price * $line->quantity));



            $items[] = [


                'productName'   => $product->name ?? 'Product',

                'productDesc'   => $product->description ?? '',

                'hsnCode'       => (int) ($product->hsn_code ?? 998232),

                'quantity'      => (float) $line->quantity,

                'qtyUnit'       => 'NOS',

                'taxableAmount' => round($taxableAmount, 2),

                'cgstRate'      => (float) ($line->cgst_rate ?? 0),

                'sgstRate'      => (float) ($line->sgst_rate ?? 0),

                'igstRate'      => (float) ($line->igst_rate ?? 0),

                'cessRate'      => 0,
                'cessNonadvol'  => 0,

            ];

        }



        if (empty($items)) {

            throw new Exception("No items found for invoice {$invoice->invoice_number}.");

        }



        return $items;

    }



    /* =========================================================

     |  State name → GST state code

     | ========================================================= */

    protected function getStateCodeFromName(string $stateName): string

    {

        $map = [

            'Gujarat'           => '24',

            'Maharashtra'       => '27',

            'Karnataka'         => '29',

            'Delhi'             => '07',

            'Tamil Nadu'        => '33',

            'Telangana'         => '36',

            'Uttar Pradesh'     => '09',

            'West Bengal'       => '19',

            'Rajasthan'         => '08',

            'Kerala'            => '32',

            'Punjab'            => '03',

            'Haryana'           => '06',

            'Madhya Pradesh'    => '23',

            'Andhra Pradesh'    => '37',

            'Bihar'             => '10',

            'Odisha'            => '21',

            'Assam'             => '18',

            'Uttarakhand'       => '05',

            'Himachal Pradesh'  => '02',

            'Goa'               => '30',

            'Chhattisgarh'      => '22',

            'Jharkhand'         => '20',

            'Puducherry'        => '34',

            'Chandigarh'        => '04',

            'Jammu and Kashmir' => '01',

        ];



        $normalized = ucwords(strtolower(trim($stateName)));

        return $map[$normalized] ?? '24'; // Default Gujarat

    }

}