<?php

namespace App\Services\PaymentGateway;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Http\Client\Response;

class HdfcService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $merchantId;
    protected string $resellerId;
    protected string $paymentPageClientId;
    protected bool $enableLogging;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('services.hdfc.base_url'),
            '/'
        );

        $this->apiKey = (string) config('services.hdfc.api_key');
        $this->merchantId = (string) config('services.hdfc.merchant_id');

        $this->resellerId = (string) config(
            'services.hdfc.reseller_id',
            ''
        );

        $this->paymentPageClientId = (string) config(
            'services.hdfc.payment_page_client_id',
            $this->merchantId
        );

        $this->enableLogging = (bool) config(
            'services.hdfc.enable_logging',
            false
        );

        if (
            $this->baseUrl === '' ||
            $this->apiKey === '' ||
            $this->merchantId === ''
        ) {
            throw new Exception(
                'HDFC configuration is incomplete. Check HDFC_BASE_URL, HDFC_API_KEY and HDFC_MERCHANT_ID.'
            );
        }
    }

    /**
     * Create a payment session using Basic Auth.
     */
    public function createOrder(Order $order): array
    {
        $orderId = (string) $order->order_reference;

        // SmartGateway order IDs must follow its documented restrictions.
        if (
            $orderId === '' ||
            strlen($orderId) > 20 ||
            !preg_match('/^[a-zA-Z0-9]+$/', $orderId)
        ) {
            throw new Exception(
                'HDFC order_reference must be alphanumeric and no longer than 20 characters. Use a unique HDFC-compatible order ID.'
            );
        }

        $customer = $order->user;

        $email = $customer?->email;
        $phone = $customer?->phone;

        if (empty($email) && empty($phone)) {
            throw new Exception(
                'Customer email or phone is required for HDFC payment.'
            );
        }

        $amount = number_format(
            (float) $order->total_payable,
            2,
            '.',
            ''
        );

        if ((float) $amount <= 0) {
            throw new Exception(
                'HDFC payment amount must be greater than zero.'
            );
        }

        $payload = [
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => 'INR',
            'customer_id' => (string) $order->user_id,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'payment_page_client_id' => $this->paymentPageClientId,
            'action' => 'paymentPage',
            'return_url' => route('hdfc.return'),
            'description' => 'Order ' . $orderId,
            'first_name' => $customer?->name ?? 'Customer',
            'last_name' => '',
            'udf1' => $orderId,
        ];

        // Basic Auth session endpoint.
        // Confirm that /session is enabled for your HDFC merchant account.
        $response = $this->post('/session', $payload, (string) $order->user_id);

        if (empty($response['id'])) {
            throw new Exception(
                'HDFC session creation failed: ' .
                    json_encode($response, JSON_UNESCAPED_SLASHES)
            );
        }

        return [
            'id' => $response['id'],
            'status' => $response['status'] ?? 'CREATED',
            'payment_link' => $response['payment_links']['web'] ?? null,
            'sdk_payload' => $response['sdk_payload'] ?? null,
            'raw' => $response,
        ];
    }

    /**
     * Fetch order status.
     * Confirm the path against the Basic Auth API documentation for your account.
     */
    public function getOrderStatus(string $hdfcOrderId): array
    {
        return $this->get(
            '/orders/' . rawurlencode($hdfcOrderId)
        );
    }

    /**
     * Fetch transaction details.
     * Confirm the transaction endpoint against your merchant's API documentation.
     */
    public function getTransaction(string $transactionId): array
    {
        return $this->get(
            '/transactions/' . rawurlencode($transactionId)
        );
    }

    /**
     * Do not use this method for payment approval until the signature
     * algorithm and signed fields have been confirmed from HDFC documentation.
     */
    public function verifySignature(
        array $payload,
        ?string $signature
    ): bool {
        if (empty($signature)) {
            return false;
        }

        $responseKey = (string) config('services.hdfc.response_key');

        if ($responseKey === '') {
            return false;
        }

        $responseData = $payload['response'] ?? null;

        if (!is_string($responseData)) {
            return false;
        }

        $computed = base64_encode(
            hash_hmac('sha256', $responseData, $responseKey, true)
        );

        return hash_equals($computed, $signature);
    }

    /**
     * Send a POST request using Basic Auth.
     */
    protected function post(
        string $path,
        array $body,
        string $customerId
    ): array {
        $url = $this->baseUrl . $path;

        $response = Http::withBasicAuth($this->apiKey, '')
            ->withHeaders($this->headers($customerId))
            ->timeout(30)
            ->post($url, $body);

        return $this->handleResponse(
            $response,
            'POST',
            $url,
            $body
        );
    }

    /**
     * Send a GET request using Basic Auth.
     */
    protected function get(string $path): array
    {
        $url = $this->baseUrl . $path;

        $response = Http::withBasicAuth($this->apiKey, '')
            ->withHeaders($this->headers(''))
            ->timeout(30)
            ->get($url);

        return $this->handleResponse(
            $response,
            'GET',
            $url,
            []
        );
    }

    /**
     * Common request headers.
     */
    protected function headers(string $customerId): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'x-merchantid' => $this->merchantId,
            'x-customerid' => $customerId,
        ];

        if ($this->resellerId !== '') {
            $headers['x-resellerid'] = $this->resellerId;
        }

        return $headers;
    }

    /**
     * Process the API response.
     */
    protected function handleResponse(
        Response $response,
        string $method,
        string $url,
        array $payload
    ): array {
        $json = $response->json();

        if (!is_array($json)) {
            $json = [
                'raw_response' => $response->body(),
            ];
        }

        if ($this->enableLogging) {
            // Do not log API keys, authorization headers or customer payment data.
            Log::info('HDFC API Call', [
                'method' => $method,
                'url' => $url,
                'payload' => [
                    'order_id' => $payload['order_id'] ?? null,
                    'amount' => $payload['amount'] ?? null,
                    'currency' => $payload['currency'] ?? null,
                ],
                'status' => $response->status(),
                'response' => $json,
            ]);
        }

        if ($response->failed()) {
            throw new Exception(
                'HDFC API error (' . $response->status() . '): ' .
                    json_encode($json, JSON_UNESCAPED_SLASHES)
            );
        }

        return $json;
    }
}
