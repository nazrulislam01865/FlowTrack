<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Horizontal scaling profile
    |--------------------------------------------------------------------------
    |
    | Keep this disabled on a single-node/local installation. Production
    | multi-node deployments enable it explicitly in the environment so the
    | rollback path is configuration-only rather than a code rollback.
    |
    */
    'horizontal' => (bool) env('FLOWTRACK_HORIZONTAL_SCALING', false),

    'redis' => [
        'cache_connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
        'session_connection' => env('REDIS_SESSION_CONNECTION', 'session'),
        'queue_connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
    ],

    'storage' => [
        'health_sentinel' => env('FLOWTRACK_STORAGE_HEALTH_SENTINEL', 'health/flowtrack-ready.txt'),
        'require_shared_when_horizontal' => (bool) env('FLOWTRACK_REQUIRE_SHARED_STORAGE', true),
        'shared_roots' => [
            'public' => env('FLOWTRACK_PUBLIC_STORAGE_PATH'),
            'private' => env('FLOWTRACK_PRIVATE_STORAGE_PATH'),
            'quarantine' => env('FLOWTRACK_QUARANTINE_STORAGE_PATH'),
        ],
    ],

    'queues' => [
        'names' => array_values(array_filter(array_map('trim', explode(',', (string) env('FLOWTRACK_QUEUE_NAMES', 'realtime,notifications,emails,default'))))),
        'max_depth' => (int) env('FLOWTRACK_QUEUE_MAX_DEPTH', 100),
        'slow_delay_seconds' => (int) env('FLOWTRACK_QUEUE_SLOW_DELAY_SECONDS', 15),
        'monitor_enabled' => (bool) env('FLOWTRACK_QUEUE_MONITOR_ENABLED', true),
    ],

    'database' => [
        'connect_timeout_seconds' => (int) env('DB_CONNECT_TIMEOUT', 5),
        'expected_web_workers' => (int) env('FLOWTRACK_EXPECTED_WEB_WORKERS', 12),
        'expected_queue_workers' => (int) env('FLOWTRACK_EXPECTED_QUEUE_WORKERS', 6),
        'expected_scheduler_workers' => (int) env('FLOWTRACK_EXPECTED_SCHEDULER_WORKERS', 1),
        'connection_reserve' => (int) env('FLOWTRACK_DB_CONNECTION_RESERVE', 25),
    ],

    'health' => [
        'expose_details' => (bool) env('FLOWTRACK_HEALTH_EXPOSE_DETAILS', false),
        'check_storage' => (bool) env('FLOWTRACK_HEALTH_CHECK_STORAGE', true),
        'check_queue' => (bool) env('FLOWTRACK_HEALTH_CHECK_QUEUE', true),
    ],

    'backup' => [
        'enabled' => (bool) env('FLOWTRACK_DATABASE_BACKUP_ENABLED', false),
        'directory' => env('FLOWTRACK_DATABASE_BACKUP_DIRECTORY', storage_path('app/backups')),
        'retain_days' => (int) env('FLOWTRACK_DATABASE_BACKUP_RETAIN_DAYS', 14),
        'schedule' => env('FLOWTRACK_DATABASE_BACKUP_TIME', '01:30'),
        'dump_binary' => env('FLOWTRACK_MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql_binary' => env('FLOWTRACK_MYSQL_BINARY', 'mysql'),
    ],
];
