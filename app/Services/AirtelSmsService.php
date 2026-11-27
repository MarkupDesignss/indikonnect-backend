<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AirtelSmsService
{
    public function sendOtp(string $phone, string $otp): bool
    {
        $message = "Your IndieKonnect registration OTP is {$otp}. Please do not share this OTP with anyone.";

        return $this->sendPrepaidSms([$phone], $message);
    }

    public function sendWelcomeSms(string $phone, string $fullName): bool
    {
        $templateId = config('services.airtel_sms.welcome_template_id');

        if (empty($templateId)) {
            Log::info('Airtel welcome SMS skipped: AIRTEL_SMS_WELCOME_TEMPLATE_ID is not configured.');
            return false;
        }

        // Apne approved DLT welcome template ke exact text ke mutabik adjust karein
        $message = "Hi {$fullName}! Welcome to " . config('app.name') . ". Your customer account has been created successfully. Start shopping now!";

        return $this->sendPrepaidSms([$phone], $message, (string) $templateId);
    }

    public function sendPrepaidSms(array $destinations, string $message, ?string $dltTemplateId = null): bool
    {
        $config = config('services.airtel_sms');

        $formattedDestinations = array_values(array_filter(array_map(
            fn ($phone) => $this->normalizePhone((string) $phone),
            $destinations
        )));

        if (empty($formattedDestinations)) {
            Log::warning('Airtel SMS skipped: no valid destination phone numbers.');
            return false;
        }

        $payload = [
            'customerId' => (string) ($config['customer_id'] ?? ''),
            'destinationAddress' => $formattedDestinations,
            'dltTemplateId' => (string) ($dltTemplateId ?? ($config['otp_template_id'] ?? '')),
            'entityId' => (string) ($config['entity_id'] ?? ''),
            'message' => $message,
            'messageType' => (string) ($config['message_type'] ?: 'TEXT'),
            'sourceAddress' => (string) ($config['source_address'] ?? ''),
        ];

        $url = rtrim((string) ($config['base_url'] ?? 'https://iqsms.airtel.in'), '/') . '/api/v1/send-prepaid-sms';

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Authorization' => $this->buildAuthorizationHeader($config ?? []),
                ])
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info('Airtel SMS sent successfully', [
                    'destinations' => $formattedDestinations,
                    'status' => $response->status(),
                    'response' => $response->json() ?? $response->body(),
                ]);
                return true;
            }

            Log::error('Airtel SMS API error response', [
                'destinations' => $formattedDestinations,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Airtel SMS request exception', [
                'destinations' => $formattedDestinations,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function buildAuthorizationHeader(array $config): string
    {
        $scheme = strtolower((string) ($config['auth_scheme'] ?? 'basic'));

        if ($scheme === 'bearer' && !empty($config['auth_token'])) {
            return 'Bearer ' . $config['auth_token'];
        }

        if ($scheme === 'raw' && !empty($config['auth_token'])) {
            return (string) $config['auth_token'];
        }

        $credentials = ($config['username'] ?? '') . ':' . ($config['password'] ?? '');
        return 'Basic ' . base64_encode($credentials);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 10) {
            return '91' . $digits;
        }

        return $digits;
    }
}