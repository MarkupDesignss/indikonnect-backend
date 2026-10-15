<?php

namespace App\Services\PaymentGateway;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class HdfcService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $merchantId;
    protected string $responseKey;
    protected bool $enableLogging;

    public function __construct()
    {
        $this->baseUrl       = rtrim(config('services.hdfc.base_url'), '/');
        $this->apiKey        = config('services.hdfc.api_key');
        $this->merchantId    = config('services.hdfc.merchant_id');
        $this->responseKey   = config('services.hdfc.response_key');
        $this->enableLogging = (bool) config('services.hdfc.enable_logging', false);
    }

    /**
     * Create HDFC order (session) and return payment link / order id
     */
    public function createOrder(Order $order): array
    {
        $payload = [
            'order_id'          => $order->order_reference,
            'amount'            => (float) $order->total_payable,
            'currency'          => 'INR',
            'customer_id'       => (string) $order->user_id,
            'customer_email'    => optional($order->user)->email,
            'customer_phone'    => optional($order->user)->phone ?? '9999999999',
            'payment_page_client_id' => config('services.hdfc.payment_page_client_id'),
            'action'            => 'paymentPage',
            'return_url'        => route('hdfc.return'),
            'description'       => 'Order ' . $order->order_reference,
            'first_name'        => optional($order->user)->name ?? 'Customer',
            'last_name'         => '',
            'udf1'              => $order->order_reference,
        ];

        $response = $this->post('/v4/session', $payload);

        if (empty($response['id'])) {
            throw new Exception('HDFC order creation failed: ' . json_encode($response));
        }

        return [
            'id'            => $response['id'],
            'status'        => $response['status'] ?? 'CREATED',
            'payment_link'  => $response['payment_links']['web'] ?? null,
            'sdk_payload'   => $response['sdk_payload'] ?? null,
            'raw'           => $response,
        ];
    }

    /**
     * Fetch order status from HDFC
     */
    public function getOrderStatus(string $hdfcOrderId): array
    {
        return $this->get("/pg/api/v1/orders/{$hdfcOrderId}");
    }

    /**
     * Fetch transaction/payment details
     */
    public function getTransaction(string $transactionId): array
    {
        return $this->get("/pg/api/v1/transactions/{$transactionId}");
    }

    /**
     * Verify response signature on return / webhook
     * HDFC uses HMAC SHA256 of response payload with response key.
     */
    public function verifySignature(array $payload, ?string $signature): bool
    {
        if (!$signature) {
            return false;
        }

        $computed = $this->computeSignature($payload);
        return hash_equals($computed, $signature);
    }

    protected function computeSignature(array $payload): string
    {
        // HDFC uses the "response" JSON string (base64-encoded in some flows)
        $responseJson = $payload['response'] ?? json_encode($payload);
        return base64_encode(hash_hmac('sha256', $responseJson, $this->responseKey, true));
    }

    /**
     * HTTP helpers
     */
    protected function post(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->post($url, $body);

        return $this->handleResponse($response, 'POST', $url, $body);
    }

    protected function get(string $path): array
    {
        $url = $this->baseUrl . $path;

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get($url);

        return $this->handleResponse($response, 'GET', $url, []);
    }

    protected function headers(): array
    {
        return [
            'Authorization'   => 'Basic ' . base64_encode($this->apiKey . ':'),
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
            'x-merchantid'    => $this->merchantId,
            'x-customerid'    => '',
        ];
    }

    protected function handleResponse($response, string $method, string $url, array $payload): array
    {
        $json = $response->json() ?? [];
        // dd($method, $url, $payload, $response->status(), $json);
        if ($this->enableLogging) {
            Log::info('HDFC API Call', [
                'method'   => $method,
                'url'      => $url,
                'payload'  => $payload,
                'status'   => $response->status(),
                'response' => $json,
            ]);
        }
        if ($response->failed()) {
            throw new Exception(
                'HDFC API error (' . $response->status() . '): ' . ($json['message'] ?? $response->body())
            );
        }

        return $json;
    }
}
