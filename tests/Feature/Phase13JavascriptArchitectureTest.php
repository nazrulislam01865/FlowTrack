<?php

namespace Tests\Feature;

use Tests\TestCase;

class Phase13JavascriptArchitectureTest extends TestCase
{
    public function test_browser_runtime_is_composed_from_phase_thirteen_modules(): void
    {
        foreach ([
            'resources/js/core/events.js',
            'resources/js/core/navigation.js',
            'resources/js/core/realtime.js',
            'resources/js/components/inline-edit.js',
            'resources/js/components/list-filters.js',
            'resources/js/features/notifications.js',
            'resources/js/features/workspace-refresh.js',
            'resources/js/features/route-loader.js',
            'resources/js/core/browser-api.js',
        ] as $relative) {
            $this->assertFileExists(base_path($relative), $relative);
        }

        $app = file_get_contents(resource_path('js/app.js'));
        $navigation = file_get_contents(resource_path('js/core/navigation.js'));
        $realtime = file_get_contents(resource_path('js/core/realtime.js'));
        $bridge = file_get_contents(resource_path('js/core/browser-api.js'));

        $this->assertStringContainsString('bindNavigationLifecycle', $app);
        $this->assertStringContainsString('bootRealtimeClient', $app);
        $this->assertStringContainsString('lifecycleState.bound', $navigation);
        $this->assertStringContainsString("document.addEventListener('livewire:navigated'", $navigation);
        $this->assertStringContainsString('let client = null;', $realtime);
        $this->assertStringContainsString('Math.min(15000, 500 * Math.pow(2', $realtime);
        $this->assertStringContainsString('window.FlowTrack = existing;', $bridge);
        $this->assertStringContainsString('get client() { return getRealtimeClient(); }', $bridge);
    }

    public function test_layout_has_no_unmanaged_flowtrack_or_sheetjs_runtime_scripts(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $bulk = file_get_contents(resource_path('views/pages/bulk-order-import.blade.php'));
        $bulkFeature = file_get_contents(resource_path('js/features/bulk-order-import.js'));
        $routeLoader = file_get_contents(resource_path('js/features/route-loader.js'));

        $this->assertStringContainsString("'resources/js/app.js'", $layout);
        $this->assertStringNotContainsString('/js/flowtrack-', $layout);
        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/xlsx', $bulk);
        $this->assertStringContainsString("import * as XLSX from 'xlsx';", $bulkFeature);
        $this->assertStringContainsString("import('./bulk-order-import.js')", $routeLoader);
    }

    public function test_realtime_event_names_have_one_shared_contract(): void
    {
        $events = file_get_contents(resource_path('js/core/events.js'));
        $notifications = file_get_contents(resource_path('js/features/notifications.js'));
        $workspace = file_get_contents(resource_path('js/features/workspace-refresh.js'));

        $this->assertStringContainsString("WORKSPACE_REFRESH: 'flowtrack.refresh'", $events);
        $this->assertStringContainsString("NOTIFICATION: 'flowtrack.notification'", $events);
        $this->assertStringContainsString("NOTIFICATION_STATE: 'flowtrack.notification-state'", $events);
        $this->assertStringContainsString('REALTIME_EVENTS.NOTIFICATION', $notifications);
        $this->assertStringContainsString('REALTIME_EVENTS.WORKSPACE_REFRESH', $workspace);
        $this->assertStringNotContainsString('window.FlowTrackRealtime', $notifications);
        $this->assertStringNotContainsString('window.FlowTrackRealtime', $workspace);
    }
}
