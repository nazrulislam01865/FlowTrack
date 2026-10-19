<?php

return [
    'enabled' => (bool) env('FLOWTRACK_SUPPLIER_BRIDGE_ENABLED', false),
    'url' => rtrim((string) env('FLOWTRACK_SUPPLIER_PORTAL_URL', ''), '/'),
    'secret' => (string) env('FLOWTRACK_SUPPLIER_BRIDGE_SECRET', ''),
    'login_url' => trim((string) env('FLOWTRACK_SUPPLIER_PORTAL_LOGIN_URL', '')),
];
