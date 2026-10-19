<?php

return [
    'enabled' => env('PERFORMANCE_MONITORING_ENABLED', true),
    'sample_rate' => (float) env('PERFORMANCE_SAMPLE_RATE', 1.0),
    'slow_request_ms' => (int) env('PERFORMANCE_SLOW_REQUEST_MS', 750),
    'slow_query_ms' => (int) env('PERFORMANCE_SLOW_QUERY_MS', 150),
    'slow_query_total_ms' => (int) env('PERFORMANCE_SLOW_QUERY_TOTAL_MS', 400),
    'slow_outgoing_ms' => (int) env('PERFORMANCE_SLOW_OUTGOING_MS', 500),
    'log_all_requests' => env('PERFORMANCE_LOG_ALL_REQUESTS', false),
    'server_timing' => env('PERFORMANCE_SERVER_TIMING', false),
    'include_query_sql' => env('PERFORMANCE_INCLUDE_QUERY_SQL', false),
    // Staging-only diagnostic: aggregates repeated SQL shapes without bindings.
    // Keep disabled in normal production because it adds per-query bookkeeping.
    'query_fingerprints' => env('PERFORMANCE_QUERY_FINGERPRINTS', false),
    'dashboard_cache_seconds' => (int) env('DASHBOARD_CACHE_SECONDS', 30),
    'detect_lazy_loading' => env('PERFORMANCE_DETECT_LAZY_LOADING', true),
    'budgets' => [
        'standard_page_p95_ms' => (int) env('PERFORMANCE_STANDARD_PAGE_P95_MS', 500),
        'heavy_report_p95_ms' => (int) env('PERFORMANCE_HEAVY_REPORT_P95_MS', 1000),
        'standard_query_count' => (int) env('PERFORMANCE_STANDARD_QUERY_COUNT', 80),
        'standard_query_total_ms' => (int) env('PERFORMANCE_STANDARD_QUERY_TOTAL_MS', 400),
        'slow_query_ms' => (int) env('PERFORMANCE_SLOW_QUERY_MS', 150),
    ],
];
