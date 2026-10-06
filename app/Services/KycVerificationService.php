<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class KycVerificationService
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiSecret;
    protected string $environment;

    public function __construct()
    {
        // All values come from .env — never hardcode in code
        $this->baseUrl     = rtrim(config('services.kyc.base_url'), '/');
        $this->apiKey      = config('services.kyc.api_key');
        $this->apiSecret   = config('services.kyc.api_secret');
        $this->environment = config('services.kyc.environment', 'sandbox');
    }

    /**
     * Check whether we are running in live or sandbox environment.
     */
    public function isLive(): bool
    {
        return $this->environment === 'live';
    }

    /**
     * Fetch access token. Cached for 23 hours to avoid repeated calls.
     */
    public function getAccessToken(): ?string
    {
        $cacheKey = 'kyc_access_token_' . $this->environment;

        return Cache::remember($cacheKey, now()->addHours(23), function () {
            try {
                $response = Http::withHeaders([
                    'x-api-key'    => $this->apiKey,
                    'x-api-secret' => $this->apiSecret,
                    'Content-Type' => 'application/json',
                ])->timeout(30)->post($this->baseUrl . '/authenticate');

                if ($response->successful()) {
                    return $response->json()['access_token'] ?? null;
                }

                Log::error('[KYC] Auth failed', [
                    'env'      => $this->environment,
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);

                return null;
            } catch (\Exception $e) {
                Log::error('[KYC] Auth exception: ' . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Common headers used by all KYC requests.
     */
    protected function headers(): array
    {
        return [
            'Authorization' => $this->getAccessToken(),
            'x-api-key'     => $this->apiKey,
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Send OTP to the Aadhaar-linked mobile number.
     *
     * POST /kyc/aadhaar/okyc/otp
     */
    public function sendAadhaarOtp(string $aadhaarNumber): array
    {
        if (!$this->getAccessToken()) {
            return $this->errorResponse('Authentication failed');
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(30)
                ->post($this->baseUrl . '/kyc/aadhaar/okyc/otp', [
                    '@entity'        => 'in.co.sandbox.kyc.aadhaar.okyc.otp.request',
                    'aadhaar_number' => $aadhaarNumber,
                    'consent'        => 'y',
                    'reason'         => 'For KYC',
                ]);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            return $this->exceptionResponse('Aadhaar OTP send', $e);
        }
    }

    /**
     * Verify Aadhaar OTP using the reference_id returned earlier.
     *
     * POST /kyc/aadhaar/okyc/otp/verify
     */
    public function verifyAadhaarOtp(string $referenceId, string $otp): array
    {
        if (!$this->getAccessToken()) {
            return $this->errorResponse('Authentication failed');
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(30)
                ->post($this->baseUrl . '/kyc/aadhaar/okyc/otp/verify', [
                    '@entity'      => 'in.co.sandbox.kyc.aadhaar.okyc.request',
                    'reference_id' => $referenceId,
                    'otp'          => $otp,
                ]);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            return $this->exceptionResponse('Aadhaar OTP verify', $e);
        }
    }

    /**
     * Verify PAN details against the KYC provider.
     *
     * POST /kyc/pan/verify
     */
    public function verifyPan(
        string $pan,
        string $nameAsPerPan,
        string $dateOfBirth
    ): array {
        if (!$this->getAccessToken()) {
            return $this->errorResponse('Authentication failed');
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(30)
                ->post($this->baseUrl . '/kyc/pan/verify', [
                    '@entity'         => 'in.co.sandbox.kyc.pan_verification.request',
                    'pan'             => strtoupper($pan),
                    'name_as_per_pan' => $nameAsPerPan,
                    'date_of_birth'   => $dateOfBirth,
                    'consent'         => 'Y',
                    'reason'          => 'For onboarding customers',
                ]);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            return $this->exceptionResponse('PAN verify', $e);
        }
    }

    /**
     * Check whether the given PAN is linked to the given Aadhaar.
     *
     * POST /kyc/pan-aadhaar/status
     */
    public function checkPanAadhaarLink(
        string $pan,
        string $aadhaarNumber
    ): array {
        if (!$this->getAccessToken()) {
            return $this->errorResponse('Authentication failed');
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(30)
                ->post($this->baseUrl . '/kyc/pan-aadhaar/status', [
                    '@entity'        => 'in.co.sandbox.kyc.pan_aadhaar.status',
                    'pan'            => strtoupper($pan),
                    'aadhaar_number' => $aadhaarNumber,
                    'consent'        => 'y',
                    'reason'         => 'For Testing',
                ]);

            return $this->handleResponse($response);
        } catch (\Exception $e) {
            return $this->exceptionResponse('PAN-Aadhaar link check', $e);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Common Response Handlers
    |--------------------------------------------------------------------------
    */

    protected function handleResponse($response): array
    {
        return [
            'success'     => $response->successful(),
            'status'      => $response->status(),
            'data'        => $response->json(),
            'environment' => $this->environment,
        ];
    }

    protected function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'status'  => 500,
        ];
    }

    protected function exceptionResponse(string $context, \Exception $e): array
    {
        Log::error("[KYC] {$context} failed: " . $e->getMessage());

        return [
            'success' => false,
            'message' => $e->getMessage(),
            'status'  => 500,
        ];
    }
}