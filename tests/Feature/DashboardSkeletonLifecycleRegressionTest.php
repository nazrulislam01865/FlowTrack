<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ActivityPanel;
use App\Livewire\Dashboard\AttentionPanel;
use App\Livewire\Dashboard\CataloguePanel;
use App\Livewire\Dashboard\DistributionPanel;
use App\Livewire\Dashboard\OverviewPanel;
use App\Livewire\Dashboard\PortfolioPanel;
use App\Livewire\Dashboard\PriorityPanel;
use App\Livewire\Dashboard\TaggedComments;
use App\Livewire\Dashboard\TeamPanel;
use Tests\TestCase;

/** Regression: skeleton and data must never be independent sibling nodes. */
final class DashboardSkeletonLifecycleRegressionTest extends TestCase
{
    public function test_initial_components_render_placeholders_without_any_dashboard_reader(): void
    {
        $cases = [
            PriorityPanel::class => 'panel-placeholder',
            AttentionPanel::class => 'panel-placeholder',
            DistributionPanel::class => 'panel-placeholder',
            TeamPanel::class => 'panel-placeholder',
            PortfolioPanel::class => 'panel-placeholder',
            CataloguePanel::class => 'panel-placeholder',
            ActivityPanel::class => 'panel-placeholder',
            OverviewPanel::class => 'overview-placeholder',
            TaggedComments::class => 'mentions-placeholder',
        ];

        foreach ($cases as $class => $view) {
            $component = new $class();
            $this->assertFalse($component->dashboardReady, $class);
            $this->assertSame('livewire.dashboard.'.$view, $component->render()->name(), $class);
            $component->loadDashboardPanel();
            $this->assertTrue($component->dashboardReady, $class);
        }
    }

    public function test_placeholder_roots_match_loaded_panel_roots(): void
    {
        $views = resource_path('views/livewire/dashboard/');
        $match = [
            'overview' => ['overview-placeholder', 'overview-panel', 'div'],
            'tagged-comments' => ['mentions-placeholder', 'tagged-comments', 'section'],
            'priority' => ['panel-placeholder', 'priority-panel', 'article'],
            'attention' => ['panel-placeholder', 'attention-panel', 'article'],
            'distribution' => ['panel-placeholder', 'distribution-panel', 'section'],
            'team' => ['panel-placeholder', 'team-panel', 'article'],
            'portfolio' => ['panel-placeholder', 'portfolio-panel', 'article'],
            'catalogue' => ['panel-placeholder', 'catalogue-panel', 'article'],
            'activity' => ['panel-placeholder', 'activity-panel', 'article'],
        ];
        foreach ($match as $key => [$placeholder, $loaded, $root]) {
            $html = file_get_contents($views.$loaded.'.blade.php');
            $this->assertStringContainsString('<'.$root.' class=', $html, $key);
            $this->assertStringContainsString('data-dashboard-panel="'.$key.'"', $html);
        }
        $generic = file_get_contents($views.'panel-placeholder.blade.php');
        $this->assertStringContainsString("@if(\$kind === 'distributionpanel')", $generic);
        $this->assertStringContainsString('<section class=', $generic);
        $this->assertStringContainsString('<article class=', $generic);
        $this->assertStringContainsString('wire:intersect.once="loadDashboardPanel"', $generic);
    }

    public function test_panel_load_triggers_are_owned_by_child_elements_not_component_roots(): void
    {
        // Livewire can attribute a directive on a nested component ROOT to the
        // parent dashboard.index. The method exists on the child only.
        $views = resource_path('views/livewire/dashboard/');
        $overview = file_get_contents($views.'overview-placeholder.blade.php');
        $mentions = file_get_contents($views.'mentions-placeholder.blade.php');
        $panels = file_get_contents($views.'panel-placeholder.blade.php');
        $parent = file_get_contents($views.'progressive-shell.blade.php');

        $this->assertStringNotContainsString('ft-mgmt-skeleton-overview" wire:init=', $overview);
        $this->assertStringContainsString('ft-order-workflow-stage-overview" wire:init="loadDashboardPanel"', $overview);
        $this->assertStringNotContainsString('id="mentions-for-you" wire:intersect.once=', $mentions);
        $this->assertStringContainsString('ft-mgmt-mentions-head" wire:intersect.once="loadDashboardPanel"', $mentions);

        $this->assertStringNotContainsString('ft-mgmt-dashboard-skeleton" wire:intersect.once=', $panels);
        $this->assertStringNotContainsString('} }}" wire:intersect.once=', $panels);
        $this->assertStringContainsString('ft-mgmt-flow-prototype" wire:intersect.once="loadDashboardPanel"', $panels);
        $this->assertStringContainsString('ft-mgmt-catalogue-head" wire:intersect.once="loadDashboardPanel"', $panels);
        $this->assertStringContainsString('ft-mgmt-panel-head" wire:intersect.once="loadDashboardPanel"', $panels);
        $this->assertSame(3, substr_count($panels, 'wire:intersect.once="loadDashboardPanel"'));
        $this->assertStringNotContainsString('loadDashboardPanel', $parent);
    }

    public function test_parent_does_not_remount_children_for_scoped_realtime_changes(): void
    {
        $index = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $method = explode('public function refreshRealtime(): void', $index, 2)[1];
        $method = explode('public function setRange(', $method, 2)[0];
        $this->assertStringContainsString("config('dashboard.progressive_loading_enabled', false)", $method);
        $this->assertStringContainsString('$this->skipRender();', $method);
        $shell = file_get_contents(resource_path('views/livewire/dashboard/progressive-shell.blade.php'));
        $this->assertStringNotContainsString(' lazy ', $shell);
        $this->assertStringNotContainsString(' defer ', $shell);
        $this->assertStringContainsString('key="dashboard-priority"', $shell);
    }
}
