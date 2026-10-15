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
    protected string $responseKey;
    protected string $cardEncodingKey;
    protected string $environment;
    protected bool   $enableLogging;

    /** 'hdfc_sg' or 'hdfc_ie' */
    protected string $gatewayKey;

    /**
     * @param  array   $config      One merchant block from config('services.hdfc.*')
     * @param  string  $gatewayKey  'hdfc_sg' | 'hdfc_ie'
     */
    public function __construct(array $config = [], string $gatewayKey = 'hdfc_sg')
    {
        // Fallback to 'sg' config when container resolves without args
        if (empty($config)) {
            $config = config('services.hdfc.sg', []);
        }

        $this->gatewayKey           = $gatewayKey;
        $this->baseUrl              = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->apiKey               = (string) ($config['api_key'] ?? '');
        $this->merchantId           = (string) ($config['merchant_id'] ?? '');
        $this->resellerId           = (string) ($config['reseller_id'] ?? '');
        $this->paymentPageClientId  = (string) ($config['payment_page_client_id'] ?? $this->merchantId);
        $this->responseKey          = (string) ($config['response_key'] ?? '');
        $this->cardEncodingKey      = (string) ($config['card_encoding_key'] ?? '');
        $this->environment          = (string) ($config['environment'] ?? 'live');
        $this->enableLogging        = (bool)   ($config['enable_logging'] ?? false);

        if ($this->baseUrl === '' || $this->apiKey === '' || $this->merchantId === '') {
            throw new Exception(
                'HDFC configuration is incomplete. Check base_url, api_key and merchant_id.'
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
                'HDFC order_reference must be alphanumeric and no longer than 20 characters.'
            );
        }

        $customer = $order->user;

        $email = $customer?->email;
        $phone = $customer?->phone;

        if (empty($email) && empty($phone)) {
            throw new Exception('Customer email or phone is required for HDFC payment.');
        }

        $amount = number_format((float) $order->total_payable, 2, '.', '');

        if ((float) $amount <= 0) {
            throw new Exception('HDFC payment amount must be greater than zero.');
        }

        // ---------- Gateway-specific URLs ----------
        $isIe = $this->gatewayKey === 'hdfc_ie';

        $returnUrl = $isIe
            ? route('hdfc.return.ie')
            : route('hdfc.return.sg');

        $cancelUrl = $isIe
            ? route('hdfc.cancel.ie')
            : route('hdfc.cancel.sg');

        // Webhook URL: some Juspay versions accept it in payload,
        // but the authoritative place is the HDFC dashboard.
        $webhookUrl = $isIe
            ? url('/api/webhook/hdfc1')
            : url('/api/webhook/hdfc');

        $payload = [
            'order_id'                => $orderId,
            'amount'                  => $amount,
            'currency'                => 'INR',
            'customer_id'             => (string) $order->user_id,
            'customer_email'          => $email,
            'customer_phone'          => $phone,
            'payment_page_client_id'  => $this->paymentPageClientId,
            'action'                  => 'paymentPage',
            'return_url'              => $returnUrl,
            'description'             => 'Order ' . $orderId,
            'first_name'              => $customer?->name ?? 'Customer',
            'last_name'               => '',
            'udf1'                    => $orderId,
            // Optional — Juspay may accept this; if not, ignore/remove it.
            'webhook_url'             => $webhookUrl,
        ];

        $response = $this->post('/session', $payload, (string) $order->user_id);

        if (empty($response['id'])) {
            throw new Exception(
                'HDFC session creation failed: ' .
                    json_encode($response, JSON_UNESCAPED_SLASHES)
            );
        }

        return [
            'id'            => $response['id'],
            'status'        => $response['status'] ?? 'CREATED',
            'payment_link'  => $response['payment_links']['web'] ?? null,
            'sdk_payload'   => $response['sdk_payload'] ?? null,
            'raw'           => $response,
        ];
    }

    public function getOrderStatus(string $hdfcOrderId): array
    {
        return $this->get('/orders/' . rawurlencode($hdfcOrderId));
    }

    public function getTransaction(string $transactionId): array
    {
        return $this->get('/transactions/' . rawurlencode($transactionId));
    }

    public function verifySignature(array $payload, ?string $signature): bool
    {
        if (empty($signature) || $this->responseKey === '') {
            return false;
        }

        $responseData = $payload['response'] ?? null;

        if (!is_string($responseData)) {
            return false;
        }

        $computed = base64_encode(
            hash_hmac('sha256', $responseData, $this->responseKey, true)
        );

        return hash_equals($computed, $signature);
    }

    /* ------------------------------------------------------------------ */
    /*  Internals                                                          */
    /* ------------------------------------------------------------------ */

    protected function post(string $path, array $body, string $customerId): array
    {
        $url = $this->baseUrl . $path;

        $response = Http::withBasicAuth($this->apiKey, '')
            ->withHeaders($this->headers($customerId))
            ->timeout(30)
            ->post($url, $body);

        return $this->handleResponse($response, 'POST', $url, $body);
    }

    protected function get(string $path): array
    {
        $url = $this->baseUrl . $path;

        $response = Http::withBasicAuth($this->apiKey, '')
            ->withHeaders($this->headers(''))
            ->timeout(30)
            ->get($url);

        return $this->handleResponse($response, 'GET', $url, []);
    }

    protected function headers(string $customerId): array
    {
        $headers = [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'x-merchantid'  => $this->merchantId,
            'x-customerid'  => $customerId,
        ];

        if ($this->resellerId !== '') {
            $headers['x-resellerid'] = $this->resellerId;
        }

        return $headers;
    }

    protected function handleResponse(Response $response, string $method, string $url, array $payload): array
    {
        $json = $response->json();

        if (!is_array($json)) {
            $json = ['raw_response' => $response->body()];
        }

        if ($this->enableLogging) {
            Log::info('HDFC API Call', [
                'method'   => $method,
                'url'      => $url,
                'gateway'  => $this->gatewayKey,
                'payload'  => [
                    'order_id' => $payload['order_id'] ?? null,
                    'amount'   => $payload['amount'] ?? null,
                    'currency' => $payload['currency'] ?? null,
                ],
                'status'   => $response->status(),
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
