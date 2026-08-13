<?php

return [
    'provider' => env('COMMISSION_PROVIDER', 'mock'),
    'mock_failure_rate' => env('MOCK_COMMISSION_FAILURE_RATE', 30),
    'mock_latency_ms' => env('MOCK_COMMISSION_LATENCY_MS', 500),
    'real' => [
        'base_url' => env('COMMISSION_API_URL'),
        'api_key' => env('COMMISSION_API_KEY'),
        'timeout' => 30,
    ],
];