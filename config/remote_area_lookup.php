<?php

return [
    // Safe default: existing full-collection matcher until warm+verify succeeds.
    'enabled' => (bool) env('FLOWTRACK_REMOTE_AREA_INDEX_ENABLED', false),
    'order_resolution_writes_enabled' => (bool) env('FLOWTRACK_ORDER_REMOTE_AREA_RESOLUTION_WRITES_ENABLED', false),
    'order_snapshot_reads_enabled' => (bool) env('FLOWTRACK_ORDER_REMOTE_AREA_SNAPSHOT_READS_ENABLED', false),
    'order_invoice_snapshot_enabled' => (bool) env('FLOWTRACK_ORDER_REMOTE_AREA_INVOICE_SNAPSHOT_ENABLED', false),
    // Dedicated Redis connection; does not depend on CACHE_STORE.
    'redis_connection' => env('FLOWTRACK_REMOTE_AREA_REDIS_CONNECTION', 'default'),
    'key_prefix' => 'flowtrack:remote-area:compiled:v1',
    // A generation is retained long enough for in-flight requests to finish.
    'generation_ttl_seconds' => 86400,
];
