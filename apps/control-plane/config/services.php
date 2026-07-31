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

    'nats' => [
        'url' => env('NATS_URL'),
    ],

    'gateway' => [
        'signing_key' => env('GATEWAY_SIGNING_KEY'),
        'url' => env('GATEWAY_WS_URL', 'ws://127.0.0.1:4000/socket'),
        'internal_url' => env('GATEWAY_INTERNAL_URL'),
        'internal_token' => env('GATEWAY_INTERNAL_TOKEN'),
    ],

    'ai' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8100'),
        'token' => env('AI_SERVICE_TOKEN'),
        'timeout' => env('AI_SERVICE_TIMEOUT', 25),
    ],

    // Stripe subscription checkout (billing v2). Optional: with no secret
    // key configured, billing stays operator-activated (org:plan) and the
    // dashboard shows the contact-us upgrade path instead of checkout.
    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'prices' => [
            'pro' => env('STRIPE_PRICE_PRO'),
            'business' => env('STRIPE_PRICE_BUSINESS'),
        ],
    ],

];
