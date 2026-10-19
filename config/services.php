<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    'hdfc' => [
        // Indiekonnect Enterprises (SG)
        'sg' => [
            'base_url'                => env('HDFC_BASE_URL', 'https://smartgateway.hdfc.bank.in'),
            'api_key'                 => env('HDFC_API_KEY'),
            'merchant_id'             => env('HDFC_MERCHANT_ID'),
            'reseller_id'             => env('HDFC_RESELLER_ID', ''),
            'payment_page_client_id'  => env('HDFC_PAYMENT_PAGE_CLIENT_ID', env('HDFC_MERCHANT_ID')),
            'response_key'            => env('HDFC_RESPONSE_KEY'),
            'card_encoding_key'       => env('HDFC_CARD_ENCODING_KEY'),
            'environment'             => env('HDFC_ENVIRONMENT', 'live'),
            'enable_logging'          => env('HDFC_ENABLE_LOGGING', false),
        ],

        // Indiekonnect Enterprises Pvt Ltd (IE)
        'ie' => [
            'base_url'                => env('HDFC_IE_BASE_URL', 'https://smartgateway.hdfc.bank.in'),
            'api_key'                 => env('HDFC_IE_API_KEY'),
            'merchant_id'             => env('HDFC_IE_MERCHANT_ID'),
            'reseller_id'             => env('HDFC_IE_RESELLER_ID', ''),
            'payment_page_client_id'  => env('HDFC_IE_PAYMENT_PAGE_CLIENT_ID', env('HDFC_IE_MERCHANT_ID')),
            'response_key'            => env('HDFC_IE_RESPONSE_KEY'),
            'card_encoding_key'       => env('HDFC_IE_CARD_ENCODING_KEY'),
            'environment'             => env('HDFC_IE_ENVIRONMENT', 'live'),
            'enable_logging'          => env('HDFC_IE_ENABLE_LOGGING', false),
        ],
    ],
    'kyc' => [
        'base_url'    => env('KYC_BASE_URL', 'https://test-api.sandbox.co.in'),
        'api_key'     => env('KYC_API_KEY'),
        'api_secret'  => env('KYC_API_SECRET'),
        'environment' => env('KYC_ENV', 'sandbox'),
    ],

    'airtel_sms' => [
        'enabled'         => env('AIRTEL_SMS_ENABLED', false),
        'base_url'        => env('AIRTEL_SMS_BASE_URL', 'https://iqsms.airtel.in/api/v1'),
        'client_id'       => env('AIRTEL_SMS_CLIENT_ID'),
        'client_secret'   => env('AIRTEL_SMS_CLIENT_SECRET'),
        'customer_id'     => env('AIRTEL_SMS_CUSTOMER_ID'),
        'sender_id'       => env('AIRTEL_SMS_SENDER_ID'),
        'dlt_entity_id'   => env('AIRTEL_SMS_DLT_ENTITY_ID'),
        'dlt_template_id' => env('AIRTEL_SMS_DLT_TEMPLATE_ID'),
        'timeout'         => 30,
    ],
    'twilio' => [
        'sid'   => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from'  => env('TWILIO_5R'),
    ],

    'ignite' => [
        'base_url' => env('IGNITE_BASE_URL', 'https://demo-ignite.techtegyit.com/partner/v1'),
        'api_key'  => env('IGNITE_API_KEY', ''),
    ],

];
