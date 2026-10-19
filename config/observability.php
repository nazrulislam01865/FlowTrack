<?php

return [
    'enabled' => (bool) env('FLOWTRACK_OBSERVABILITY_ENABLED', env('FLOWTRACK_HORIZONTAL_SCALING', false)),
    'redis_connection' => env('FLOWTRACK_OBSERVABILITY_REDIS_CONNECTION', 'observability'),
    'prefix' => env('FLOWTRACK_OBSERVABILITY_PREFIX', 'flowtrack:observability:'),
    'window_minutes' => max(1, (int) env('FLOWTRACK_OBSERVABILITY_WINDOW_MINUTES', 15)),
    'retention_minutes' => max(30, (int) env('FLOWTRACK_OBSERVABILITY_RETENTION_MINUTES', 180)),
    'samples_per_minute' => max(20, (int) env('FLOWTRACK_OBSERVABILITY_SAMPLES_PER_MINUTE', 240)),
    'realtime_client_endpoint' => (bool) env('FLOWTRACK_REALTIME_CLIENT_TELEMETRY', true),
    'alerts' => [
        'http_error_rate_percent' => (float) env('FLOWTRACK_ALERT_HTTP_ERROR_RATE_PERCENT', 1.0),
        'request_p95_ms' => (int) env('FLOWTRACK_ALERT_REQUEST_P95_MS', 500),
        'query_p95_ms' => (int) env('FLOWTRACK_ALERT_QUERY_P95_MS', 400),
        'memory_p95_mb' => (float) env('FLOWTRACK_ALERT_MEMORY_P95_MB', 192),
        'cache_hit_rate_percent_min' => (float) env('FLOWTRACK_ALERT_CACHE_HIT_RATE_MIN_PERCENT', 60),
        'queue_delay_p95_seconds' => (float) env('FLOWTRACK_ALERT_QUEUE_DELAY_P95_SECONDS', 15),
        'realtime_error_rate_percent' => (float) env('FLOWTRACK_ALERT_REALTIME_ERROR_RATE_PERCENT', 5.0),
    ],
];
