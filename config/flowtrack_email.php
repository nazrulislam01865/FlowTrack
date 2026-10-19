<?php

use App\Services\Email\E2AEmailTransport;
use App\Services\Email\LaravelEmailTransport;

return [
    /*
    |--------------------------------------------------------------------------
    | FlowTrack Email Abstraction
    |--------------------------------------------------------------------------
    |
    | Application modules depend on EmailService. The transport adapter below
    | is the only place that knows how delivery happens. For normal SMTP/SES/
    | Postmark/Resend provider changes, keep "laravel" and change only mailer
    | configuration/environment values.
    |
    */
    'transport' => env('FLOWTRACK_EMAIL_TRANSPORT', 'laravel'),

    'transports' => [
        'laravel' => LaravelEmailTransport::class,
        'e2a' => E2AEmailTransport::class,
    ],

    'e2a' => [
        'base_url' => env('E2A_BASE_URL', 'https://api.e2a.dev'),
        'api_key' => env('E2A_API_KEY'),
        'agent_email' => env('E2A_AGENT_EMAIL'),
        'wait' => env('E2A_WAIT', 'sent'),
        'timeout' => (int) env('E2A_TIMEOUT', 25),
    ],

    // Any key from config/mail.php: smtp, ses, postmark, resend, failover, etc.
    'mailer' => env('FLOWTRACK_EMAIL_MAILER', env('MAIL_MAILER', 'log')),

    'queue' => [
        // Normal application email should not block web/Livewire requests.
        'enabled' => (bool) env('FLOWTRACK_EMAIL_QUEUE_ENABLED', true),
        'connection' => env('FLOWTRACK_EMAIL_QUEUE_CONNECTION'),
        'name' => env('FLOWTRACK_EMAIL_QUEUE', 'emails'),
        // Rate-limit releases count as attempts, so keep this high; provider
        // failures are separately capped by max_exceptions.
        'tries' => (int) env('FLOWTRACK_EMAIL_QUEUE_TRIES', 100),
        'max_exceptions' => (int) env('FLOWTRACK_EMAIL_QUEUE_MAX_EXCEPTIONS', 4),
        'timeout' => (int) env('FLOWTRACK_EMAIL_QUEUE_TIMEOUT', 30),
        'backoff' => [10, 60, 180],
        // Protect provider reputation/quotas while still allowing horizontal workers.
        'rate_limit_per_minute' => (int) env('FLOWTRACK_EMAIL_RATE_LIMIT_PER_MINUTE', 120),
    ],

    'attachments' => [
        'max_bytes' => (int) env('FLOWTRACK_EMAIL_ATTACHMENT_MAX_BYTES', 15 * 1024 * 1024),
    ],
];
