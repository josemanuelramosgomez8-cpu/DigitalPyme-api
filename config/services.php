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

    'digitalpyme' => [
        'solicitudes_email' => env('SOLICITUDES_EMAIL'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:8443'),
    ],

    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'environment' => env('MERCADOPAGO_ENVIRONMENT', 'sandbox'),
        'webhook_url' => env('MERCADOPAGO_WEBHOOK_URL'),
        'return_url' => env('PAYMENT_RETURN_URL', 'http://localhost:8443'),
    ],

    'bank_transfer' => [
        'bank' => env('BANK_TRANSFER_BANK'),
        'holder' => env('BANK_TRANSFER_HOLDER'),
        'account' => env('BANK_TRANSFER_ACCOUNT'),
        'cci' => env('BANK_TRANSFER_CCI'),
    ],

    'yape_plin' => [
        'enabled' => env('YAPE_PLIN_ENABLED', false),
        'phone' => env('YAPE_PLIN_PHONE'),
        'holder' => env('YAPE_PLIN_HOLDER'),
        'qr_url' => env('YAPE_PLIN_QR_URL'),
    ],

];
