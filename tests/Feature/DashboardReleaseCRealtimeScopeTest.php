<?php

namespace Tests\Feature;

use Tests\TestCase;

final class DashboardReleaseCRealtimeScopeTest extends TestCase
{
    public function test_release_c_routes_only_dashboard_events_without_changing_legacy_path(): void
    {
        $workspace = file_get_contents(resource_path('js/features/workspace-refresh.js'));
        $notifications = file_get_contents(resource_path('js/features/notifications.js'));
        $this->assertStringContainsString("routeOrderWorkspaceRefresh", $workspace);
        $this->assertStringContainsString('dashboard?.routeWorkspace?.(reason, payload)', $workspace);
        $this->assertStringContainsString('LIVEWIRE_EVENTS.WORKSPACE_REFRESH', $workspace);
        $this->assertStringContainsString('dashboard?.routeNotification?.(payload)', $notifications);
        $this->assertStringContainsString('LIVEWIRE_EVENTS.NOTIFICATION', $notifications);

        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString("request()->routeIs('dashboard') && config('dashboard.progressive_loading_enabled', false)", $layout);
        $this->assertStringContainsString('flowtrack-dashboard-realtime.js', $layout);
    }

    public function test_scoped_refresh_does_not_replace_global_filter_state_or_panel_actions(): void
    {
        $base = file_get_contents(app_path('Livewire/Dashboard/Panel.php'));
        $overview = file_get_contents(app_path('Livewire/Dashboard/OverviewPanel.php'));
        $mentions = file_get_contents(app_path('Livewire/Dashboard/TaggedComments.php'));
        foreach ([$base, $overview, $mentions] as $source) {
            $this->assertStringContainsString("#[On('dashboard-scoped-refresh')]", $source);
            $this->assertStringContainsString('function refreshDashboardPanel()', $source);
            $this->assertStringNotContainsString('DashboardPrimaryQuery', $source);
        }
        $shell = file_get_contents(resource_path('views/livewire/dashboard/progressive-shell.blade.php'));
        $this->assertStringContainsString('data-dashboard-progressive="1"', $shell);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="search"', $shell);
        $this->assertStringNotContainsString('wire:poll', $shell);
        foreach (['overview','priority','attention','distribution','team','portfolio','catalogue','activity','tagged-comments'] as $section) {
            $view = file_get_contents(resource_path('views/livewire/dashboard/'.($section === 'tagged-comments' ? 'tagged-comments' : $section.'-panel').'.blade.php'));
            $this->assertStringContainsString('data-dashboard-panel=', $view);
        }
    }

    public function test_catalogue_cache_is_invalidated_only_by_relevant_sources(): void
    {
        config()->set('dashboard.progressive_loading_enabled', true);
        config()->set('cache.default', 'array');
        \Illuminate\Support\Facades\Cache::flush();

        $user = \App\Models\User::factory()->make(['id' => 381]);
        $cache = app(\App\Services\Dashboard\DashboardReadModelCache::class);
        $resolutions = 0;
        $resolver = static function () use (&$resolutions): array {
            return ['value' => ++$resolutions];
        };

        $this->assertSame(['value' => 1], $cache->rememberArray($user, 'catalogue-readiness', [], $resolver));
        $cache->invalidateCatalogueForReason('Task:updated|Activity:created');
        $this->assertSame(['value' => 1], $cache->rememberArray($user, 'catalogue-readiness', [], $resolver));
        $cache->invalidateCatalogueForReason('MasterRecord:updated');
        $this->assertSame(['value' => 2], $cache->rememberArray($user, 'catalogue-readiness', [], $resolver));
        $cache->invalidateCatalogueForReason('unclassified-event');
        $this->assertSame(['value' => 3], $cache->rememberArray($user, 'catalogue-readiness', [], $resolver));
    }

    public function test_current_built_asset_contains_scoped_routing_and_legacy_fallback(): void
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $asset = file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));
        $this->assertStringContainsString('routeWorkspace?.(', $asset);
        $this->assertStringContainsString('routeNotification?.(', $asset);
        $this->assertStringContainsString('P.WORKSPACE_REFRESH', $asset);
        $this->assertStringContainsString('P.NOTIFICATION', $asset);
    }
}
