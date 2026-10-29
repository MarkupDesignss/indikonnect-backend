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
        // Using the 'kyc' config block which holds your Sandbox credentials
        $this->baseUrl    = config('services.kyc.base_url', 'https://test-api.sandbox.co.in');
        $this->apiKey     = config('services.kyc.api_key');
        $this->apiSecret  = config('services.kyc.api_secret');
        $this->apiVersion = config('services.kyc.api_version', '1.0.0');
    }

    /**
     * Generate an access token using API Key and Secret.
     * The token is cached for 23 hours to avoid unnecessary API calls.
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
        // 1. Validate transporter ID format (15 chars, alphanumeric)
        if (empty($transporterId) || strlen($transporterId) !== 15) {
            throw new Exception("Invalid Transporter ID. It must be a 15-character GSTIN/TRANSIN.");
        }

        // 2. Build the payload for Sandbox E-Way Bill Generate API
        $payload = $this->buildPayload($invoice, $transporterId);

        // 3. Get Access Token
        $token = $this->getAccessToken();

        // 4. Call Sandbox API
        try {
            $response = Http::withHeaders([
                'authorization' => $token, // Raw JWT (No 'Bearer' prefix)
                'x-api-key'     => $this->apiKey,
                'x-api-version' => $this->apiVersion,
                'Content-Type'  => 'application/json',
            ])->post($this->baseUrl . '/gst/compliance/e-way-bill/consignor/bill', $payload);

            $responseData = $response->json();

            // 5. Handle Sandbox/NIC business failure (HTTP 200 but status_cd = 0)
            if (!isset($responseData['data']['status_cd']) || $responseData['data']['status_cd'] !== '1') {
                $errorData = $responseData['data']['error'] ?? [];
                $errorMessage = is_array($errorData) ? ($errorData['message'] ?? 'Unknown E-Way Bill error') : $errorData;
                $errorCode = is_array($errorData) ? ($errorData['error_cd'] ?? '') : '';
                throw new Exception("E-Way Bill generation failed: {$errorMessage} (Code: {$errorCode})");
            }

            // 6. Extract and validate EBN
            $ewbNo = $responseData['data']['data']['ewayBillNo'] ?? null;
            $ewbDate = $responseData['data']['data']['ewayBillDate'] ?? null;
            $validUpto = $responseData['data']['data']['validUpto'] ?? null;

            if (empty($ewbNo)) {
                throw new Exception("E-Way Bill number not found in response.");
            }

            // 7. Log any alert from Sandbox
            $alert = $responseData['data']['data']['alert'] ?? null;
            if (!empty($alert)) {
                Log::warning('E-Way Bill Part A generated with alert', [
                    'invoice_number' => $invoice->invoice_number,
                    'eway_bill_no'   => $ewbNo,
                    'alert'          => $alert,
                ]);
            }

            // 8. Return normalized result
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
     * NO vehicle number is included. Transporter ID is provided.
     */
    protected function buildPayload(Invoice $invoice, string $transporterId): array
    {
        $order = $invoice->order;
        if (!$order) {
            throw new Exception("Invoice has no associated order.");
        }

        $order->loadMissing(['deliveryAddress', 'billingAddress', 'user']);

        $deliveryAddress = $order->deliveryAddress;
        $user            = $order->user;

        if (!$deliveryAddress) {
            throw new Exception("Delivery address is missing for order {$order->order_reference}.");
        }

        $items = $this->buildItems($invoice);

        // Consignor (your company) details from config
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

        // Consignee (buyer) details
        // If buyer has no GSTIN, we must send 'URP' (Unregistered Person)
        $consigneeGstin = !empty($user->gstin) ? $user->gstin : 'URP';
        $consigneeName  = $deliveryAddress->full_name ?? $user->name;
        $consigneeAddr1 = $deliveryAddress->address_line_1;
        $consigneeAddr2 = $deliveryAddress->address_line_2 ?? '';
        $consigneePlace = $deliveryAddress->city;
        $consigneePin   = $deliveryAddress->postal_code;
        $consigneeState = $this->getStateCodeFromName($deliveryAddress->state);

        $totalInvoiceValue = (float) $invoice->total_value;

        // Part A payload – Transporter ID provided, NO vehicle number, NO transport mode
        return [
            'supplyType'       => 'O',
            'subSupplyType'    => '1',
            'subSupplyDesc'    => '', // Required by NIC
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
            'actFromStateCode' => (int) $consignorState, // Required
            
            'toGstin'          => $consigneeGstin,
            'toTrdName'        => $consigneeName,
            'toAddr1'          => $consigneeAddr1,
            'toAddr2'          => $consigneeAddr2,
            'toPlace'          => $consigneePlace,
            'toPincode'        => (int) $consigneePin,
            'toStateCode'      => (int) $consigneeState,
            'actToStateCode'   => (int) $consigneeState, // Required
            
            'transactionType'  => 1, // 1 = Regular
            'totalValue'       => $totalInvoiceValue,
            'totInvValue'      => $totalInvoiceValue,
            'transporterId'    => $transporterId,
            
            'itemList'         => $items,
        ];
    }

    /**
     * Build item list from invoice.
     */
    protected function buildItems(Invoice $invoice): array
    {
        $items = [];
        $slNo = 1;
        $orderLines = $invoice->order->lines ?? collect();

        foreach ($orderLines as $line) {
            $product = $line->product;
            $items[] = [
                'slNo'          => (string) $slNo++,
                'productName'   => $product->name ?? 'Product',
                'productDesc'   => $product->description ?? '',
                'hsnCode'       => (int) ($product->hsn_code ?? 998232),
                'quantity'      => (float) $line->quantity,
                'qtyUnit'       => 'NOS',
                'taxableAmount' => (float) $line->unit_price,
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
        ];

        $normalized = ucwords(strtolower(trim($stateName)));
        return $map[$normalized] ?? '24'; // Default to Gujarat if unknown
    }
}