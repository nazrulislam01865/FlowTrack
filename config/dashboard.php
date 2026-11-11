<?php

return [
    // Release A rollback: false retains <livewire:dashboard.index defer />.
    'progressive_loading_enabled' => (bool) env('FLOWTRACK_DASHBOARD_PROGRESSIVE_LOADING_ENABLED', false),
];
