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


    'realtime' => [
        'enabled' => env('REVERB_ENABLED', true),
        'queue' => env('REALTIME_QUEUE', 'realtime'),
        'queue_connection' => env('REALTIME_QUEUE_CONNECTION', env('FLOWTRACK_HORIZONTAL_SCALING', false) ? 'redis' : env('QUEUE_CONNECTION', 'database')),
        // Laravel publishes to the Reverb HTTP API through this internal address.
        // In production this can stay on loopback even though browsers use WSS
        // through the public REVERB_HOST / Nginx endpoint.
        'api_host' => env('REVERB_API_HOST', '127.0.0.1'),
        'api_port' => (int) env('REVERB_API_PORT', 8080),
        'api_scheme' => env('REVERB_API_SCHEME', 'http'),
        'connect_timeout' => (float) env('REALTIME_CONNECT_TIMEOUT', 2),
        'timeout' => (float) env('REALTIME_TIMEOUT', 5),
        'circuit_seconds' => (int) env('REALTIME_CIRCUIT_SECONDS', 60),
    ],

];
