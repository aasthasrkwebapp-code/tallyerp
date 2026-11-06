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

    // WhatsApp Business (Cloud) API. Dummy keys = log mode (nothing is sent).
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'log'), // log | cloud
        'token' => env('WHATSAPP_TOKEN', 'dummy-whatsapp-token'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', '000000000000000'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID', '000000000000000'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'base_url' => env('WHATSAPP_BASE_URL', 'https://graph.facebook.com'),
        'business_number' => env('WHATSAPP_BUSINESS_NUMBER', '+919660533307'),
        // Test mode only: used when the party ledger has no mobile number.
        'test_to' => env('WHATSAPP_TEST_TO', '+919660533307'),
        'template' => env('WHATSAPP_TEMPLATE'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
