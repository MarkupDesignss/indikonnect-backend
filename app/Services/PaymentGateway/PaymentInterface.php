<?php

namespace App\Services\PaymentGateway;

interface PaymentInterface
{
    public function createOrder($order): array;
    public function capturePayment(array $data): array;
    public function refundPayment(string $transactionId, float $amount): array;
    public function verifyWebhook(array $payload, string $signature): bool;
}