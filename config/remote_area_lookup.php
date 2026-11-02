<?php

return [
    // Safe default: existing full-collection matcher until warm+verify succeeds.
    'enabled' => (bool) env('FLOWTRACK_REMOTE_AREA_INDEX_ENABLED', false),
    // Dedicated Redis connection; does not depend on CACHE_STORE.
    'redis_connection' => env('FLOWTRACK_REMOTE_AREA_REDIS_CONNECTION', 'default'),
    'key_prefix' => 'flowtrack:remote-area:compiled:v1',
    // A generation is retained long enough for in-flight requests to finish.
    'generation_ttl_seconds' => 86400,
];
