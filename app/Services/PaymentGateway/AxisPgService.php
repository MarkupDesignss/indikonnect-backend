<?php

namespace App\Services\PaymentGateway;

use App\Models\Order;
use Exception;

class AxisPgService
{
    protected string $merchantId;
    protected string $merchantKey;
    protected string $baseUrl;
    protected string $callbackUrl;

    public function __construct(array $config)
    {
        $this->merchantId  = $config['merchant_id'];
        $this->merchantKey = $config['merchant_key'];
        $this->baseUrl     = rtrim($config['base_url'], '/');
        $this->callbackUrl = $config['callback_url'] ?? '';
    }

    /**
     * Generate signature (Document Page 31-32)
     */
    public function generateSignature(array $payload): string
    {
        unset($payload['signature']);
        ksort($payload);

        $concatenated = '';
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $concatenated .= (string) $value;
        }

        $concatenated .= $this->merchantKey;

        return hash('sha256', $concatenated);
    }

    /**
     * Create dynamic checkout payload
     */
    public function createCheckoutPayload(Order $order): array
    {
        $payload = [
            'merchantId'        => $this->merchantId,
            'callbackUrl'       => $this->callbackUrl,
            'merchantTxnId'     => $order->order_reference,
            'merchantTxnAmount' => (float) $order->total_payable,
            'currency'          => 'INR',
            'timestamp'         => now()->timestamp * 1000,
            'customerId'        => (string) $order->user_id,
            'customerName'      => $order->user->name ?? '',
            'customerEmailId'   => $order->user->email ?? '',
            'customerMobileNo'  => $order->user->phone ?? '',
            'merchantOrderId'   => (string) $order->id,
        ];

        $payload['signature'] = $this->generateSignature($payload);

        return [
            'clientId' => $this->merchantId,
            'payload'  => $payload, // For debugging and sharing with the team
        ];
    }
}