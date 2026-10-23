<?php

namespace App\Services\PaymentGateway;

use App\Models\Order;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

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
    protected bool $enableLogging;
    protected string $gatewayKey;
    protected string $returnUrl;

    public function __construct(
        array $config = [],
        string $gatewayKey = 'hdfc_sg'
    ) {
        if (empty($config)) {
            $config = config('services.hdfc.sg', []);
        }

        $this->gatewayKey = $gatewayKey;
        $this->returnUrl = (string) ($config['return_url'] ?? '');
        $this->baseUrl = rtrim(
            (string) ($config['base_url'] ?? ''),
            '/'
        );
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->merchantId = (string) ($config['merchant_id'] ?? '');
        $this->resellerId = (string) ($config['reseller_id'] ?? '');
        $this->paymentPageClientId = (string) (
            $config['payment_page_client_id'] ?? $this->merchantId
        );
        $this->responseKey = (string) ($config['response_key'] ?? '');
        $this->cardEncodingKey = (string) (
            $config['card_encoding_key'] ?? ''
        );
        $this->environment = (string) (
            $config['environment'] ?? 'live'
        );
        $this->enableLogging = (bool) (
            $config['enable_logging'] ?? false
        );

        if (
            $this->baseUrl === '' ||
            $this->apiKey === '' ||
            $this->merchantId === ''
        ) {
            throw new Exception(
                'HDFC configuration is incomplete. Check base_url, api_key and merchant_id.'
            );
        }
    }

    /**
     * Create a hosted payment session.
     */
    public function createOrder(Order $order): array
    {
        $orderId = (string) $order->order_reference;

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

        if (!$customer) {
            throw new Exception(
                'Customer not found for HDFC payment.'
            );
        }

        $email = $customer->email;
        $phone = $customer->phone;

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

        if (
            !filter_var($this->returnUrl, FILTER_VALIDATE_URL)
            || !str_starts_with($this->returnUrl, 'https://')
            || parse_url($this->returnUrl, PHP_URL_QUERY)
        ) {
            throw new Exception('HDFC return_url must be public HTTPS with no query parameters.');
        }

        $payload = [
            'return_url'             => $this->returnUrl,
            'order_id'               => $orderId,
            'amount'                 => $amount,
            'currency'               => 'INR',
            'customer_id'            => (string) $order->user_id,
            'payment_page_client_id' => $this->paymentPageClientId,
            'action'                 => 'paymentPage',
            'description'            => 'Order ' . $orderId,
            'first_name'             => $customer->name ?: 'Customer',
            'last_name'              => '',
            'udf1'                   => $orderId,
        ];

        if (!empty($email)) {
            $payload['customer_email'] = $email;
        }

        if (!empty($phone)) {
            $payload['customer_phone'] = $phone;
        }

        $response = $this->post(
            '/session',
            $payload,
            (string) $order->user_id
        );

        if (empty($response['id'])) {
            throw new Exception(
                'HDFC session creation failed: ' .
                    json_encode($response, JSON_UNESCAPED_SLASHES)
            );
        }

        return [
            'id'           => $response['id'],
            'status'       => $response['status'] ?? 'CREATED',
            'payment_link' => $response['payment_links']['web'] ?? null,
            'sdk_payload'  => $response['sdk_payload'] ?? null,
            'raw'          => $response,
        ];
    }

    /**
     * Fetch the latest order status from HDFC/Juspay.
     */
    public function getOrderStatus(string $orderReference, ?string $customerId = null): array
    {
        return $this->get('/orders/' . rawurlencode($orderReference), $customerId);
    }

    /**
     * Fetch transaction details.
     */
    public function getTransaction(string $transactionId): array
    {
        return $this->get(
            '/transactions/' . rawurlencode($transactionId)
        );
    }

    /**
     * Verify a signed response.
     */
    public function verifySignature(
        array $payload,
        ?string $signature
    ): bool {
        if (
            empty($signature) ||
            $this->responseKey === ''
        ) {
            return false;
        }

        $responseData = $payload['response'] ?? null;

        if (!is_string($responseData)) {
            return false;
        }

        $computed = base64_encode(
            hash_hmac(
                'sha256',
                $responseData,
                $this->responseKey,
                true
            )
        );

        return hash_equals($computed, $signature);
    }

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

    protected function get(string $path, ?string $customerId = null): array
    {
        $url = $this->baseUrl . $path;

        $response = Http::withBasicAuth($this->apiKey, '')
            ->withHeaders($this->headers($customerId ?? ''))
            ->timeout(30)
            ->get($url);

        return $this->handleResponse(
            $response,
            'GET',
            $url,
            []
        );
    }

    protected function headers(string $customerId): array
    {
        $headers = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'x-merchantid' => $this->merchantId,
            'x-customerid' => $customerId,
        ];

        if ($customerId !== '') {
            $headers['x-routing-id'] = $customerId;
        }
        if ($this->resellerId !== '') {
            $headers['x-resellerid'] = $this->resellerId;
        }

        return $headers;
    }

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
            Log::info('HDFC API Call', [
                'method'  => $method,
                'url'     => $url,
                'gateway' => $this->gatewayKey,
                'payload' => [
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

    /**
     * Cancel the HDFC payment session/order.
     */
    public function cancelOrder(string $orderReference): array
    {
        // HDFC cancel endpoint requires merchantId in the path
        $path = '/merchants/' . $this->merchantId
            . '/order/' . rawurlencode($orderReference)
            . '/cancel';

        // HDFC requires a specific version header for this endpoint
        $headers = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'x-merchantid' => $this->merchantId,
            'version'      => '2019-08-19',
        ];

        if ($this->resellerId !== '') {
            $headers['x-resellerid'] = $this->resellerId;
        }

        $url = $this->baseUrl . $path;

        $response = Http::withBasicAuth($this->apiKey, '')
            ->withHeaders($headers)
            ->timeout(30)
            ->post($url, [
                'cancel_reason' => 'User cancelled on payment page',
            ]);

        return $this->handleResponse(
            $response,
            'POST',
            $url,
            ['order_id' => $orderReference]
        );
    }
}
