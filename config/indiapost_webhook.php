<?php

return [
    // Keep false until DoP confirms authentication, actual sender IPs and payload.
    'enabled' => (bool) env('INDIAPOST_WEBHOOK_ENABLED', false),
    // disabled | token | ip | both
    'auth_mode' => env('INDIAPOST_WEBHOOK_AUTH_MODE', 'disabled'),
    // Optional shared-token contract: only use if DoP confirms it can send this header.
    'token' => env('INDIAPOST_WEBHOOK_SHARED_TOKEN', ''),
    // SOURCE IPs of DoP webhook sender, not our own server's whitelisted IP.
    'allowed_ips' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('INDIAPOST_WEBHOOK_SENDER_IPS', ''))))),
    'expected_customer_id' => env('INDIAPOST_WEBHOOK_CUSTOMER_ID', ''),
    // Keep false until shipment schema and frontend integration have been checked.
    'apply_shipment_status' => (bool) env('INDIAPOST_WEBHOOK_APPLY_SHIPMENT_STATUS', false),
];
