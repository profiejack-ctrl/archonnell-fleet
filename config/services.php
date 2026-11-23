<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
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

    'central_api' => [
        'base_url' => env('CENTRAL_API_URL', env('CENTRAL_GATEWAY_URL', 'https://api.archonnellincorporated.com/api/v1')),
        'auth_url' => env('CENTRAL_AUTH_URL', 'https://api.archonnellincorporated.com/api/v1/auth'),
        'service_email' => env('CENTRAL_SERVICE_EMAIL', 'admin@archonnell.com'),
        'service_password' => env('CENTRAL_SERVICE_PASSWORD', 'Admin123!'),
        'timeout' => (int) env('CENTRAL_API_TIMEOUT', 4),
    ],

];
