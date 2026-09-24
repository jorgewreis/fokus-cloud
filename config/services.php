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

    'mercado_pago' => [
        'access_token' => env('MERCADO_PAGO_ACCESS_TOKEN'),
        'webhook_secret' => env('MERCADO_PAGO_WEBHOOK_SECRET'),
        'webhook_previous_secrets' => array_filter(explode(',', (string) env('MERCADO_PAGO_WEBHOOK_PREVIOUS_SECRETS', ''))),
        'environment' => env('MERCADO_PAGO_ENVIRONMENT', 'sandbox'),
        'test_payer_email' => env('MERCADO_PAGO_TEST_PAYER_EMAIL', 'test@testuser.com'),
        'api_base_url' => env('MERCADO_PAGO_API_BASE_URL', 'https://api.mercadopago.com'),
        'timeout' => (int) env('MERCADO_PAGO_TIMEOUT', 10),
        'webhook_tolerance' => (int) env('MERCADO_PAGO_WEBHOOK_TOLERANCE', 300),
        'webhook_rate_limit' => (int) env('MERCADO_PAGO_WEBHOOK_RATE_LIMIT', 300),
        'scope' => env('MERCADO_PAGO_SCOPE'),
    ],

    'usage' => [
        'ingestion_secret' => env('FOKUS_USAGE_INGESTION_SECRET'),
        'ingestion_previous_secrets' => array_filter(explode(',', (string) env('FOKUS_USAGE_INGESTION_PREVIOUS_SECRETS', ''))),
    ],

    'ga4' => [
        'measurement_id' => env('FOKUS_GA4_MEASUREMENT_ID'),
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

];
