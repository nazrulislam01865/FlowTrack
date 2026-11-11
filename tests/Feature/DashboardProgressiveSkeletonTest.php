<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Prevent visually blank Release A/B/C placeholders from returning. */
class DashboardProgressiveSkeletonTest extends TestCase
{
    public function test_each_lazy_panel_uses_its_own_shimmer_placeholder(): void
    {
        $component = file_get_contents(app_path('Livewire/Dashboard/Panel.php'));
        $placeholder = file_get_contents(resource_path('views/livewire/dashboard/panel-placeholder.blade.php'));

        $this->assertStringContainsString("'kind' => strtolower(class_basename(\$this))", $component);
        foreach (['prioritypanel', 'attentionpanel', 'distributionpanel', 'teampanel', 'portfoliopanel', 'cataloguepanel', 'activitypanel'] as $kind) {
            $this->assertStringContainsString($kind, $placeholder);
        }
        $this->assertStringContainsString('ft-mgmt-skeleton-line', $placeholder);
        $this->assertStringContainsString('aria-busy="true"', $placeholder);
    }

    public function test_stages_and_mentions_get_skeletons_without_query_changes(): void
    {
        $stages = file_get_contents(resource_path('views/livewire/dashboard/overview-placeholder.blade.php'));
        $mentions = file_get_contents(resource_path('views/livewire/dashboard/mentions-placeholder.blade.php'));
        $tagged = file_get_contents(app_path('Livewire/Dashboard/TaggedComments.php'));

        $this->assertStringContainsString('$i < 7', $stages);
        $this->assertStringContainsString('ft-mgmt-skeleton-stage', $stages);
        $this->assertStringContainsString('ft-mgmt-skeleton-mention-list', $mentions);
        $this->assertStringContainsString("view('livewire.dashboard.mentions-placeholder')->render()", $tagged);
    }

    public function test_animation_is_scoped_to_progressive_dashboard_and_is_accessible(): void
    {
        $theme = file_get_contents(resource_path('theme/flowtrack/theme.css'));
        $css = file_get_contents(resource_path('theme/flowtrack/components/dashboard-skeletons.css'));
        $shell = file_get_contents(resource_path('views/livewire/dashboard/progressive-shell.blade.php'));

        $this->assertStringContainsString("@import './components/dashboard-skeletons.css'", $theme);
        $this->assertStringContainsString('data-dashboard-progressive="1"', $shell);
        $this->assertStringContainsString('[data-dashboard-panel]', $css);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertStringNotContainsString('wire:poll', $css);
    }

    public function test_loading_and_loaded_states_are_owned_by_one_livewire_component(): void
    {
        $panel = file_get_contents(app_path('Livewire/Dashboard/Panel.php'));
        $overview = file_get_contents(app_path('Livewire/Dashboard/OverviewPanel.php'));
        $mentions = file_get_contents(app_path('Livewire/Dashboard/TaggedComments.php'));
        $skeleton = file_get_contents(resource_path('views/livewire/dashboard/panel-placeholder.blade.php'));
        $essential = file_get_contents(resource_path('views/livewire/dashboard/overview-placeholder.blade.php'));
        $mentionPlaceholder = file_get_contents(resource_path('views/livewire/dashboard/mentions-placeholder.blade.php'));
        $css = file_get_contents(resource_path('theme/flowtrack/components/dashboard-skeletons.css'));

        foreach ([$panel, $overview, $mentions] as $component) {
            $this->assertStringContainsString('public bool $dashboardReady = false;', $component);
            $this->assertStringContainsString('public function loadDashboardPanel(): void', $component);
        }
        $this->assertStringContainsString('wire:init="loadDashboardPanel"', $essential);
        $this->assertStringContainsString('wire:intersect.once="loadDashboardPanel"', $mentionPlaceholder);
        $this->assertSame(3, substr_count($skeleton, 'wire:intersect.once="loadDashboardPanel"'));
        $this->assertStringNotContainsString(':has([data-dashboard-panel=', $css);
        $this->assertStringNotContainsString('wire:poll', $css);
        $this->assertStringNotContainsString('setInterval', $css);
    }
}
