<?php

return [
    // Opt-in profiling only. Keep false in normal production traffic.
    'enabled' => (bool) env('FLOWTRACK_ORDER_BASELINE_ENABLED', false),
    'run_id' => (string) env('FLOWTRACK_ORDER_BASELINE_RUN_ID', 'baseline'),
    // Top SQL shapes are SHA-256 fingerprints without bindings or SQL text.
    'max_query_shapes' => 15,
];
