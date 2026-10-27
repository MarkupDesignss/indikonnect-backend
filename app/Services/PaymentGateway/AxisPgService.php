<?php

namespace App\Services\PaymentGateway;

use Exception;

class AxisPgService
{
    protected string $merchantId;
    protected string $merchantKey;
    protected string $baseUrl;

    public function __construct(array $config)
    {
        $this->merchantId  = $config['merchant_id'];
        $this->merchantKey = $config['merchant_key'];
        $this->baseUrl     = rtrim($config['base_url'], '/');
    }

    /**
     * Signature generate करें (Document Page 31-32)
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
     * Test करने के लिए simple method
     */
    public function testSignature(): string
    {
        // Document Page 31 का example
        $payload = [
            'merchantId'     => 'MERMER581d32b',
            'merchantTxnId'  => 'txnid24Jan01',
            'txnReferenceId' => '5463f732d7e3002',
        ];
        return $this->generateSignature($payload);
    }
}