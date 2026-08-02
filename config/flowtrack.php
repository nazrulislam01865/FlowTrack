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


    // Cache the user-specific bootstrap payload briefly. Mutating FlowTrack
    // requests increment the workspace cache version immediately.
    'bootstrap_cache_seconds' => (int) env('FLOWTRACK_BOOTSTRAP_CACHE_SECONDS', 45),

    // Operational datasets are delivered in bounded pages to avoid one huge
    // bootstrap query/response while preserving the same client-side data model.
    'bootstrap_segment_per_page' => (int) env('FLOWTRACK_BOOTSTRAP_SEGMENT_PER_PAGE', 50),

    // Lightweight list pages. Full details are fetched only when a record opens.
    'list_per_page' => (int) env('FLOWTRACK_LIST_PER_PAGE', 25),

    // User/workspace dashboard calculations are cached for 1-5 minutes.
    'metrics_cache_seconds' => (int) env('FLOWTRACK_METRICS_CACHE_SECONDS', 120),

    // New documents use an authenticated private disk. Existing version rows
    // retain their original disk and remain downloadable without migration.
    'documents_disk' => env('FLOWTRACK_DOCUMENTS_DISK', 'flowtrack_documents'),

    // Polling is the reliable fallback when Pusher/Reverb is unavailable.
    // Fifteen seconds keeps 30-40 active browsers responsive without producing
    // an unnecessary heartbeat request every five seconds.
    'realtime_poll_interval_ms' => max(10000, min(60000, (int) env('FLOWTRACK_REALTIME_POLL_INTERVAL_MS', 15000))),

    'force_https' => (bool) env('FLOWTRACK_FORCE_HTTPS', env('APP_ENV') === 'production'),

    // The completed Blade Task Card interface is the only mounted FlowTrack UI.
    'ui_driver' => env('FLOWTRACK_UI_DRIVER', 'template'),

    'notification_rules' => [
        ['name' => 'Job created or assigned', 'trigger' => 'Immediately', 'recipients' => 'Assigned Job users + Authorities', 'active' => true],
        ['name' => 'Task assigned', 'trigger' => 'Immediately', 'recipients' => 'Task assignee + Authorities', 'active' => true],
        ['name' => 'Task status or blocker changed', 'trigger' => 'Immediately', 'recipients' => 'Assigned Job users + Authorities', 'active' => true],
        ['name' => 'Task due date changed', 'trigger' => 'Immediately', 'recipients' => 'Assigned Job users + Authorities', 'active' => true],
        ['name' => 'Task comment added', 'trigger' => 'Immediately', 'recipients' => 'Task assignee + Job managers + Authorities', 'active' => true],
        ['name' => 'Task or Job Phase removed', 'trigger' => 'Immediately', 'recipients' => 'Affected users + Authorities', 'active' => true],
        ['name' => 'Workflow Phase changed', 'trigger' => 'Immediately', 'recipients' => 'Assigned Job users + Authorities', 'active' => true],
        ['name' => 'Required document uploaded', 'trigger' => 'Immediately', 'recipients' => 'Assigned Job users + Authorities', 'active' => true],
        ['name' => 'Job health changed', 'trigger' => 'Immediately', 'recipients' => 'Assigned Job users + Authorities', 'active' => true],
    ],


    'notification_authority_role_codes' => array_values(array_filter(array_map(
        fn (string $code): string => strtoupper(trim($code)),
        explode(',', env(
            'FLOWTRACK_NOTIFICATION_AUTHORITY_ROLE_CODES',
            'SUPER_ADMIN,ADMIN,SYS_ADMIN,ADMINISTRATOR,SYSTEM_ADMINISTRATOR,OPERATIONS_MANAGER'
        )),
    ))),

    // Maximum historical recipient records inspected when an authority account
    // first opens the workspace-wide notification feed.
    'notification_authority_sync_limit' => (int) env('FLOWTRACK_NOTIFICATION_AUTHORITY_SYNC_LIMIT', 2000),

    'notification_authority_sync_interval_seconds' => (int) env('FLOWTRACK_NOTIFICATION_AUTHORITY_SYNC_INTERVAL_SECONDS', 60),

    'administrator_role_codes' => [
        'SUPER_ADMIN',
        'ADMIN',
        'SYS_ADMIN',
        'ADMINISTRATOR',
        'SYSTEM_ADMINISTRATOR',
    ],
];
