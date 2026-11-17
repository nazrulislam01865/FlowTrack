<?php

return [
    'enabled' => (bool) env('FLOWTRACK_SUPPLIER_BRIDGE_ENABLED', false),
    'url' => rtrim((string) env('FLOWTRACK_SUPPLIER_PORTAL_URL', ''), '/'),
    'secret' => (string) env('FLOWTRACK_SUPPLIER_BRIDGE_SECRET', ''),
    'product_api_key' => (string) env('FLOWTRACK_SUPPLIER_PRODUCT_API_KEY', ''),
    // Dedicated FlowTracker hostname strongly recommended to isolate supplier cookies.
    'product_module_url' => rtrim((string) env('FLOWTRACK_SUPPLIER_PRODUCT_MODULE_URL', ''), '/'),
    'login_url' => trim((string) env('FLOWTRACK_SUPPLIER_PORTAL_LOGIN_URL', '')),
];
