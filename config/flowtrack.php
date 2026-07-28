<?php

return [
    'workspace' => [
        'name' => env('FLOWTRACK_WORKSPACE_NAME', 'FlowTrack Workspace'),
        'slug' => env('FLOWTRACK_WORKSPACE_SLUG', 'flowtrack-demo'),
        'timezone' => env('FLOWTRACK_WORKSPACE_TIMEZONE', env('APP_TIMEZONE', 'Asia/Dhaka')),
        'currency' => env('FLOWTRACK_WORKSPACE_CURRENCY', 'USD'),
    ],

    'super_admin' => [
        'name' => env('FLOWTRACK_SUPER_ADMIN_NAME', 'FlowTrack Super Admin'),
        'email' => env('FLOWTRACK_SUPER_ADMIN_EMAIL'),
        'password' => env('FLOWTRACK_SUPER_ADMIN_PASSWORD'),
    ],

    'administrator_role_codes' => [
        'SUPER_ADMIN',
        'ADMIN',
        'SYS_ADMIN',
        'ADMINISTRATOR',
        'SYSTEM_ADMINISTRATOR',
    ],
];
