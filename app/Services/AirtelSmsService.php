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
            Log::info('Airtel SMS welcome template not configured.');
            return false;
        }

        $message = "Hi {$fullName}! Welcome to " . config('app.name') . ". Your customer account has been created successfully. Start shopping now!";

        return $this->sendPrepaidSms([$phone], $message, (string) $templateId);
    }

    public function sendPrepaidSms(array $destinations, string $message, ?string $dltTemplateId = null): bool
    {
        $config = config('services.airtel_sms', []);

        if (!is_array($config)) {
            Log::error('Airtel SMS configuration is invalid.');
            return false;
        }

        $templateId = $dltTemplateId ?? ($config['otp_template_id'] ?? '');
        $required = [
            'customer_id' => $config['customer_id'] ?? '',
            'entity_id' => $config['entity_id'] ?? '',
            'source_address' => $config['source_address'] ?? '',
            'dlt_template_id' => $templateId,
        ];
        $missing = [];

        foreach ($required as $key => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            Log::error('Airtel SMS configuration missing', ['fields' => $missing]);
            return false;
        }

        $authorization = $this->buildAuthorizationHeader($config);
        if ($authorization === null) {
            Log::error('Airtel SMS authorization configuration missing or invalid.');
            return false;
        }

        $formattedDestinations = [];
        foreach ($destinations as $phone) {
            $normalized = $this->normalizePhone((string) $phone);
            if ($normalized === null) {
                Log::warning('Airtel SMS invalid destination phone number.');
                return false;
            }
            $formattedDestinations[] = $normalized;
        }
        $formattedDestinations = array_values(array_unique($formattedDestinations));
        if ($formattedDestinations === []) {
            Log::warning('Airtel SMS no destination phone numbers.');
            return false;
        }

        $payload = [
            'customerId' => (string) $config['customer_id'],
            'destinationAddress' => $formattedDestinations,
            'dltTemplateId' => (string) $templateId,
            'entityId' => (string) $config['entity_id'],
            'message' => $message,
            'messageType' => (string) (($config['message_type'] ?? '') ?: 'TEXT'),
            'sourceAddress' => (string) $config['source_address'],
        ];

        $baseUrl = rtrim((string) (($config['base_url'] ?? '') ?: 'https://iqsms.airtel.in'), '/');
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            Log::error('Airtel SMS base URL is invalid.');
            return false;
        }
        $url = $baseUrl . '/api/v1/send-prepaid-sms';

        try {
            $response = Http::connectTimeout(10)
                ->timeout(20)
                ->acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['Authorization' => $authorization])
                ->post($url, $payload);

            if ($response->successful()) {
                // HTTP acceptance does not confirm delivery to the handset.
                Log::info('Airtel SMS API HTTP request accepted', [
                    'status' => $response->status(),
                    'destination_count' => count($formattedDestinations),
                ]);
                return true;
            }

            Log::error('Airtel SMS API error', [
                'status' => $response->status(),
                'destination_count' => count($formattedDestinations),
            ]);
            return false;
        } catch (\Throwable $e) {
            // Capture only the cURL error number, never the raw request or credentials.
            $curlCode = null;
            $cause = $e;
            for ($i = 0; $i < 5 && $cause !== null; $i++, $cause = $cause->getPrevious()) {
                if (preg_match('/cURL error\s+(\d+)\b/i', $cause->getMessage(), $matches)) {
                    $curlCode = (int) $matches[1];
                    break;
                }
            }
            Log::error('Airtel SMS request exception', [
                'exception_type' => get_class($e),
                'curl_error' => $curlCode,
                'destination_count' => count($formattedDestinations),
            ]);
            return false;
        }
    }

    private function buildAuthorizationHeader(array $config): ?string
    {
        $scheme = strtolower(trim((string) ($config['auth_scheme'] ?? 'basic')));
        $token = trim((string) ($config['auth_token'] ?? ''));

        if ($scheme === 'bearer') {
            return $token !== '' ? 'Bearer ' . $token : null;
        }
        if ($scheme === 'raw') {
            return $token !== '' ? $token : null;
        }
        if ($scheme !== 'basic') {
            return null;
        }

        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        if ($username === '' || $password === '') {
            return null;
        }

        return 'Basic ' . base64_encode($username . ':' . $password);
    }

    private function normalizePhone(string $phone): ?string
    {
        // Indian mobile numbers only; preserve the existing 91-prefixed API format.
        $phone = trim($phone);
        if (!preg_match('/^\+?[0-9 ()-]+$/D', $phone)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 14 && str_starts_with($digits, '0091')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '91' . $digits;
        }

        return preg_match('/^91[6-9][0-9]{9}$/D', $digits) ? $digits : null;
    }
}
