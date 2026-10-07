<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AirtelSmsService
{
    protected string $baseUrl;
    protected ?string $clientId;
    protected ?string $clientSecret;
    protected ?string $customerId;
    protected ?string $senderId;
    protected ?string $dltEntityId;
    protected ?string $dltTemplateId;
    protected bool $enabled;
    protected int $timeout;

    public function __construct()
    {
        $cfg = config('services.airtel_sms');

        $this->enabled       = (bool) ($cfg['enabled'] ?? false);
        $this->baseUrl       = rtrim($cfg['base_url'] ?? '', '/');
        $this->clientId      = $cfg['client_id'] ?? null;
        $this->clientSecret  = $cfg['client_secret'] ?? null;
        $this->customerId    = $cfg['customer_id'] ?? null;
        $this->senderId      = $cfg['sender_id'] ?? null;
        $this->dltEntityId   = $cfg['dlt_entity_id'] ?? null;
        $this->dltTemplateId = $cfg['dlt_template_id'] ?? null;
        $this->timeout       = (int) ($cfg['timeout'] ?? 30);
    }

    /**
     * Send OTP SMS.
     *
     * @param string $phone  E.164 format e.g. +919980980980
     * @param string $otp    6-digit OTP
     * @return array{success: bool, message: string, data: mixed}
     */
    public function sendOtp(string $phone, string $otp): array
    {
        // If disabled, just log (dev / static OTP flow)
        if (! $this->enabled) {
            Log::info('[AirtelSMS] Disabled — skipping send', [
                'phone' => $this->mask($phone),
                'otp'   => $otp,
            ]);

            return [
                'success' => true,
                'message' => 'SMS disabled (dev mode).',
                'data'    => ['otp' => $otp],
            ];
        }

        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            return [
                'success' => false,
                'message' => 'Failed to obtain Airtel access token.',
                'data'    => null,
            ];
        }

        // DLT-approved OTP template
        // Example template: "Your OTP for login is {#var#}. Valid for 5 minutes. Do not share."
        $message = "Your OTP is {$otp}. Valid for 5 minutes. Please do not share it with anyone.";

        $payload = [
            'customerId'   => $this->customerId,
            'destination'  => [$this->normalizePhone($phone)],
            'message'      => $message,
            'senderId'     => $this->senderId,
            'dltEntityId'  => $this->dltEntityId,
            'dltTemplateId'=> $this->dltTemplateId,
            'messageType'  => 'OTP',
            'priority'     => 'HIGH',
            'timeToLive'   => 300, // 5 min
        ];

        try {
            $response = Http::withToken($accessToken)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post("{$this->baseUrl}/sms/send", $payload);

            $body = $response->json();

            if ($response->successful()) {
                Log::info('[AirtelSMS] OTP sent', [
                    'phone'      => $this->mask($phone),
                    'status'     => $response->status(),
                    'response'   => $body,
                ]);

                return [
                    'success' => true,
                    'message' => 'OTP sent successfully.',
                    'data'    => $body,
                ];
            }

            Log::error('[AirtelSMS] OTP send failed', [
                'phone'    => $this->mask($phone),
                'status'   => $response->status(),
                'response' => $body,
            ]);

            return [
                'success' => false,
                'message' => $body['message'] ?? 'Airtel SMS send failed.',
                'data'    => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('[AirtelSMS] Exception while sending OTP', [
                'phone' => $this->mask($phone),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => null,
            ];
        }
    }

    /**
     * Get (and cache) Airtel OAuth token.
     */
    protected function getAccessToken(): ?string
    {
        return Cache::remember('airtel_sms_access_token', now()->addMinutes(50), function () {
            try {
                $response = Http::asForm()
                    ->timeout($this->timeout)
                    ->post("{$this->baseUrl}/auth/v1/token", [
                        'client_id'     => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'grant_type'    => 'client_credentials',
                    ]);

                if (! $response->successful()) {
                    Log::error('[AirtelSMS] Token fetch failed', [
                        'status'   => $response->status(),
                        'response' => $response->body(),
                    ]);
                    return null;
                }

                return $response->json('access_token');
            } catch (\Throwable $e) {
                Log::error('[AirtelSMS] Token exception', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Ensure phone is in pure 10-digit or E.164 digits (no +).
     */
    protected function normalizePhone(string $phone): string
    {
        // Strip everything except digits
        $digits = preg_replace('/\D+/', '', $phone);

        // If starts with country code 91 and length > 10, keep last 10 for Airtel India
        // Airtel IQ India expects 10-digit mobile without country code in `destination`
        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        }

        return $digits;
    }

    protected function mask(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 4) return $phone;
        return str_repeat('*', $len - 4) . substr($phone, -4);
    }
}