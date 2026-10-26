<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class HdfcService
{
    private array $config;
    private string $base;
    private string $merchant;
    private string $key;
    private string $gatewayKey;

    /**
     * $config is optional so Laravel's container can resolve the service.
     * Explicit config remains supported for merchant-specific instances.
     */
    public function __construct(array $config = [], string $gatewayKey = 'hdfc_sg')
    {
        if (!in_array($gatewayKey, ['hdfc_sg', 'hdfc_ie'], true)) {
            throw new RuntimeException('Unsupported HDFC gateway');
        }

        $this->gatewayKey = $gatewayKey;
        $merchantConfigKey = $gatewayKey === 'hdfc_ie' ? 'ie' : 'sg';
        $this->config = $config !== []
            ? $config
            : (array) config('services.hdfc.' . $merchantConfigKey, []);

        $this->base = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        $this->merchant = (string) ($this->config['merchant_id'] ?? '');
        $this->key = (string) ($this->config['api_key'] ?? '');

        if ($this->base === '' || $this->merchant === '' || $this->key === '') {
            throw new RuntimeException('HDFC merchant configuration missing for ' . $gatewayKey);
        }
        if (!filter_var($this->base, FILTER_VALIDATE_URL) || parse_url($this->base, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('HDFC base_url must be a valid HTTPS URL');
        }
    }

    private function client(string $customerId = ''): PendingRequest
    {
        $headers = [
            'Accept' => 'application/json',
            'x-merchantid' => $this->merchant,
        ];
        if ($customerId !== '') {
            $headers['x-customerid'] = $customerId;
            $headers['x-routing-id'] = $customerId;
        }
        if (!empty($this->config['reseller_id'])) {
            $headers['x-resellerid'] = (string) $this->config['reseller_id'];
        }

        return Http::withBasicAuth($this->key, '')
            ->acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->timeout(30);
    }

    private function decode(Response $response, string $operation): array
    {
        if ($response->failed()) {
            Log::warning('HDFC gateway API request failed', [
                'gateway' => $this->gatewayKey,
                'operation' => $operation,
                'http_status' => $response->status(),
            ]);
            // Gateway bodies can include sensitive customer/payment details.
            throw new RuntimeException('HDFC ' . $operation . ' HTTP ' . $response->status());
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new RuntimeException('Invalid HDFC JSON response for ' . $operation);
        }
        return $data;
    }

    private function assertGatewayOrderId(string $gatewayOrderId): void
    {
        if (!preg_match('/^[A-Za-z0-9]{1,20}$/D', $gatewayOrderId)) {
            throw new RuntimeException('Invalid HDFC gateway order ID');
        }
    }

    /** Local preflight only: guarantees no gateway request has been attempted. */
    public function validateCreateOrder(Order $order): void
    {
        $customer = $order->user;
        if (!$customer) {
            throw new RuntimeException('Order customer missing');
        }
        if (empty($customer->email) && empty($customer->phone)) {
            throw new RuntimeException('Customer email or phone is required');
        }
        $amount = (float) $order->total_payable;
        if (!is_finite($amount) || $amount <= 0) {
            throw new RuntimeException('HDFC payment amount must be positive');
        }
        $returnUrl = (string) ($this->config['return_url'] ?? '');
        if (!filter_var($returnUrl, FILTER_VALIDATE_URL)
            || parse_url($returnUrl, PHP_URL_SCHEME) !== 'https'
            || parse_url($returnUrl, PHP_URL_QUERY) !== null) {
            throw new RuntimeException('HDFC return_url must be HTTPS without a query string');
        }
    }

    public function createOrder(Order $order, string $gatewayOrderId): array
    {
        $this->assertGatewayOrderId($gatewayOrderId);
        $this->validateCreateOrder($order);

        $customer = $order->user;
        $amount = (float) $order->total_payable;
        $returnUrl = (string) $this->config['return_url'];

        $payload = [
            'return_url' => $returnUrl,
            'order_id' => $gatewayOrderId,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'INR',
            'customer_id' => (string) $order->user_id,
            'payment_page_client_id' => (string) ($this->config['payment_page_client_id'] ?? $this->merchant),
            'action' => 'paymentPage',
            'description' => 'Order ' . (string) $order->order_reference,
            'first_name' => (string) ($customer->name ?: 'Customer'),
            'udf1' => (string) $order->order_reference,
        ];
        if (!empty($customer->email)) {
            $payload['customer_email'] = (string) $customer->email;
        }
        if (!empty($customer->phone)) {
            $payload['customer_phone'] = (string) $customer->phone;
        }

        $sessionPath = '/' . ltrim((string) ($this->config['session_path'] ?? '/session'), '/');
        $result = $this->decode(
            $this->client((string) $order->user_id)->post($this->base . $sessionPath, $payload),
            'create-session'
        );

        $sessionId = $result['id'] ?? null;
        $link = $result['payment_links']['web'] ?? null;
        if (!is_string($sessionId) || $sessionId === ''
            || !is_string($link) || !filter_var($link, FILTER_VALIDATE_URL)
            || parse_url($link, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('HDFC response missing a valid session ID or HTTPS payment link');
        }

        return [
            'id' => $sessionId,
            'payment_link' => $link,
            'status' => (string) ($result['status'] ?? 'CREATED'),
        ];
    }

    /** Read authoritative gateway state; do not treat 404 as cancellation. */
    public function getOrderStatus(string $gatewayOrderId, string $customerId): array
    {
        $this->assertGatewayOrderId($gatewayOrderId);
        return $this->decode(
            $this->client($customerId)->get($this->base . '/orders/' . rawurlencode($gatewayOrderId)),
            'get-order-status'
        );
    }

    /**
     * Requests cancellation. A successful HTTP response is NOT proof that
     * an already opened HDFC hosted payment link is unusable.
     * The caller must reconcile order status before marking the attempt cancelled.
     */
    public function cancelOrder(string $gatewayOrderId, string $customerId): array
    {
        $this->assertGatewayOrderId($gatewayOrderId);
        if ($customerId === '') {
            throw new RuntimeException('HDFC cancellation requires customer ID');
        }

        $path = '/merchants/' . rawurlencode($this->merchant)
            . '/order/' . rawurlencode($gatewayOrderId) . '/cancel';

        $response = $this->client($customerId)
            ->withHeaders(['version' => '2019-08-19'])
            ->post($this->base . $path, [
                'cancel_reason' => 'Customer requested cancellation',
            ]);

        // 404 and 409 are errors here, never "already cancelled" confirmations.
        $data = $this->decode($response, 'cancel-order');
        if (isset($data['order_id']) && (string) $data['order_id'] !== $gatewayOrderId) {
            throw new RuntimeException('HDFC cancel response order ID mismatch');
        }

        // Exact payload can vary by merchant integration. Do not claim a
        // terminal state based only on HTTP 200 or an undocumented status.
        return [
            'gateway_order_id' => $gatewayOrderId,
            'cancel_request_accepted' => true,
            'cancelled' => false,
            'verification_pending' => true,
            'gateway_response_status' => (string) ($data['status'] ?? ''),
        ];
    }
}
