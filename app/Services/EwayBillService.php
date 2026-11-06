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

    /**
     * Generate an access token using API Key and Secret.
     * Cached for 23 hours to avoid unnecessary API calls.
     */
    protected function getAccessToken(): string
    {
        return Cache::remember('sandbox_access_token', now()->addHours(23), function () {
            $response = Http::withHeaders([
                'x-api-key'    => $this->apiKey,
                'x-api-secret' => $this->apiSecret,
                'x-api-version'=> $this->apiVersion,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/authenticate');

            if ($response->failed() || !isset($response->json()['data']['access_token'])) {
                Log::error('Sandbox Auth Failed', ['response' => $response->body()]);
                throw new Exception("Failed to generate Sandbox Access Token. Check API Key/Secret.");
            }

            return $response->json()['data']['access_token'];
        });
    }

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

            if (!isset($data['data']['access_token'])) {
                Log::error('E-Way Bill portal auth failed', ['response' => $data]);
                throw new Exception('Failed to authenticate with E-Way Bill portal. Check username/password/GSTIN.');
            }

            return $data['data']['access_token'];
        });
    }

    /**
     * Generate E-Way Bill Part A only (Transporter ID provided, no vehicle number).
     *
     * @param Invoice $invoice
     * @param string $transporterId 15-digit Transporter GSTIN/TRANSIN
     * @return array
     * @throws Exception
     */
    public function generatePartA(Invoice $invoice, string $transporterId): array
    {
        // 1. Validate transporter ID format
        if (empty($transporterId) || strlen($transporterId) !== 15) {
            throw new Exception("Invalid Transporter ID. It must be a 15-character GSTIN/TRANSIN.");
        }

        // 2. Build payload
        $payload = $this->buildPayload($invoice, $transporterId);

        // 3. Get token
        $token = $this->getAccessToken();

        // 4. Call Sandbox API
        try {
            $response = Http::withHeaders([
                'authorization' => $token,
                'x-api-key'     => $this->apiKey,
                'x-api-version' => $this->apiVersion,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/gst/compliance/e-way-bill/consignor/bill', $payload);

            $responseData = $response->json();

            // 5. Handle NIC business failure (HTTP 200 but status_cd != '1')
            if (!isset($responseData['data']['status_cd']) || $responseData['data']['status_cd'] !== '1') {
                $errorData    = $responseData['data']['error'] ?? [];
                $errorMessage = is_array($errorData)
                    ? ($errorData['message'] ?? 'Unknown E-Way Bill error')
                    : $errorData;
                $errorCode = is_array($errorData) ? ($errorData['error_cd'] ?? '') : '';
                throw new Exception("E-Way Bill generation failed: {$errorMessage} (Code: {$errorCode})");
            }

            // 6. Extract fields
            $ewbNo     = $responseData['data']['data']['ewayBillNo'] ?? null;
            $ewbDate   = $responseData['data']['data']['ewayBillDate'] ?? null;
            $validUpto = $responseData['data']['data']['validUpto'] ?? null;

            if (empty($ewbNo)) {
                throw new Exception("E-Way Bill number not found in response.");
            }

            // 7. Log alert (NIC sometimes returns warnings with success)
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
                'eway_bill_no'   => $ewbNo,
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

    /**
     * Build the E-Way Bill payload for Part A only.
     * NO vehicle number, NO transport mode — only transporter ID.
     */
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

        // FIX: Pass only THIS invoice's line to buildItems (not the whole order)
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
        $consigneePin = $deliveryAddress->postcode ?? $deliveryAddress->postal_code ?? $deliveryAddress->pincode;
        $consigneeState = $this->getStateCodeFromName($deliveryAddress->state);

        // FIX: Validate required fields before sending to NIC
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

        // Part A payload
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
            'transporterId'    => $transporterId,

            'itemList'         => $items,
        ];
    }

    /**
     * Build item list for THIS invoice only.
     * Each invoice is generated per order_line (as per your dispatch() flow).
     */
    protected function buildItems(Invoice $invoice): array
    {
        $items = [];
        $slNo  = 1;

        // FIX: Filter to only the line(s) belonging to THIS invoice.
        // Your dispatch() creates one invoice per order_line, so we need the matching line.
        $orderLines = $invoice->order->lines ?? collect();

        // If invoice has order_line_id, filter to that line only
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

            // CRITICAL FIX: taxableAmount = unit_price × quantity (NOT per-unit)
            $taxableAmount = (float) ($line->taxable_amount
                ?? ($line->unit_price * $line->quantity));

            $items[] = [
                'slNo'          => (string) $slNo++,
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
            ];
        }

        if (empty($items)) {
            throw new Exception("No items found for invoice {$invoice->invoice_number}.");
        }

        return $items;
    }

    /**
     * Map state name to GST state code.
     */
    protected function getStateCodeFromName(string $stateName): string
    {
        $map = [
            'Gujarat'       => '24',
            'Maharashtra'   => '27',
            'Karnataka'     => '29',
            'Delhi'         => '07',
            'Tamil Nadu'    => '33',
            'Telangana'     => '36',
            'Uttar Pradesh' => '09',
            'West Bengal'   => '19',
            'Rajasthan'     => '08',
            'Kerala'        => '32',
            'Punjab'        => '03',
            'Haryana'       => '06',
            'Madhya Pradesh'=> '23',
            'Andhra Pradesh'=> '37',
            'Bihar'         => '10',
            'Odisha'        => '21',
            'Assam'         => '18',
            'Uttarakhand'   => '05',
            'Himachal Pradesh' => '02',
            'Goa'           => '30',
            'Chhattisgarh'  => '22',
            'Jharkhand'     => '20',
            'Puducherry'    => '34',
            'Chandigarh'    => '04',
            'Jammu and Kashmir' => '01',
        ];

        $normalized = ucwords(strtolower(trim($stateName)));
        return $map[$normalized] ?? '24'; // Default Gujarat
    }
}