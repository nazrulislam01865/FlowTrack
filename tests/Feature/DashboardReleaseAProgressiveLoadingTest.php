<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Source-level boundaries that can run without a production dashboard dataset. */
class DashboardReleaseAProgressiveLoadingTest extends TestCase
{
    public function test_rollback_retains_deferred_legacy_dashboard(): void
    {
        $page = file_get_contents(resource_path('views/pages/dashboard.blade.php'));
        $config = file_get_contents(config_path('dashboard.php'));

        $this->assertStringContainsString('FLOWTRACK_DASHBOARD_PROGRESSIVE_LOADING_ENABLED', $config);
        $this->assertStringContainsString("env('FLOWTRACK_DASHBOARD_PROGRESSIVE_LOADING_ENABLED', false)", $config);
        $this->assertStringContainsString('<livewire:dashboard.index defer />', $page);
        $this->assertStringContainsString('<livewire:dashboard.index />', $page);
    }

    public function test_shell_does_not_run_the_full_dashboard_reader(): void
    {
        $source = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $shellPath = resource_path('views/livewire/dashboard/progressive-shell.blade.php');
        $shell = file_get_contents($shellPath);
        $shellMethod = explode('protected function validatedDashboardFilters', explode('protected function renderProgressiveShell', $source, 2)[1], 2)[0];

        $this->assertStringNotContainsString('DashboardPrimaryQuery', $shellMethod);
        $this->assertStringNotContainsString('DashboardPrimaryQuery', $shell);
        $this->assertStringContainsString('wire:click="setRange(1)"', $shell);
        $this->assertStringContainsString('wire:click="setRange(7)"', $shell);
        $this->assertStringContainsString('wire:click="setRange(30)"', $shell);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="search"', $shell);
        $this->assertStringContainsString('<livewire:dashboard.overview-panel', $shell);
        $this->assertStringContainsString('<livewire:dashboard.priority-panel', $shell);
        $this->assertStringNotContainsString('<livewire:dashboard.legacy-panels', $shell);
    }

    public function test_essential_request_owns_summary_and_all_seven_stages(): void
    {
        $overview = file_get_contents(app_path('Livewire/Dashboard/OverviewPanel.php'));
        $view = file_get_contents(resource_path('views/livewire/dashboard/overview-panel.blade.php'));

        $this->assertStringContainsString('DashboardSummaryQuery::class', $overview);
        $this->assertStringContainsString('dashboardStages(', $overview);
        $this->assertStringNotContainsString('DashboardPrimaryQuery', $overview);
        foreach (['dashboard_scope', 'dashboard_range', 'date_from', 'date_to', 'client', 'dashboard_team'] as $filter) {
            $this->assertStringContainsString("'{$filter}'", $overview);
        }
        $this->assertStringContainsString('workflow-stage-overview', $view);
        $this->assertStringContainsString("'dashboard', [\$value]", $overview);
    }

    public function test_remaining_panels_do_not_re_read_the_essential_batch(): void
    {
        $reader = file_get_contents(app_path('Queries/Dashboard/DashboardPrimaryQuery.php'));
        $panels = explode('public function panels(', $reader, 2)[1];
        $component = file_get_contents(app_path('Livewire/Dashboard/LegacyPanels.php'));
        $source = file_get_contents(resource_path('views/livewire/dashboard/progressive-panels.blade.php'));

        $this->assertStringNotContainsString('$this->summary->handle(', $panels);
        $this->assertStringContainsString('legacyDashboardData(false)', $component);
        $this->assertStringContainsString('DashboardPrimaryQuery::class)->panels(', file_get_contents(app_path('Livewire/Dashboard/Index.php')));
        $this->assertStringContainsString('setPriorityTab(', file_get_contents(resource_path('views/livewire/dashboard/priority-panel.blade.php')));
        $this->assertStringContainsString('dashboard.tagged-comments', $shell = file_get_contents(resource_path('views/livewire/dashboard/progressive-shell.blade.php')));
    }

    public function test_children_receive_stable_reactive_filter_props(): void
    {
        $shell = file_get_contents(resource_path('views/livewire/dashboard/progressive-shell.blade.php'));
        $overview = file_get_contents(app_path('Livewire/Dashboard/OverviewPanel.php'));
        $other = file_get_contents(app_path('Livewire/Dashboard/LegacyPanels.php'));

        foreach (['range-days', 'client-filter', 'team-filter', 'search'] as $name) {
            $this->assertSame(8, substr_count($shell, ':'.$name.'='));
        }
        foreach (['rangeDays', 'clientFilter', 'teamFilter', 'search'] as $property) {
            $this->assertStringContainsString('public '.($property === 'rangeDays' ? 'int' : 'string').' $'.$property, $overview);
            $this->assertStringContainsString('public '.($property === 'rangeDays' ? 'int' : 'string').' $'.$property, $other);
        }
        // The panel's own skeleton is rendered on the initial request,
        // and only its explicit load action fetches data in a later request.
        $this->assertStringNotContainsString(' lazy ', $shell);
        $this->assertStringNotContainsString(' defer ', $shell);
        $this->assertStringContainsString('wire:init="loadDashboardPanel"', file_get_contents(resource_path('views/livewire/dashboard/overview-placeholder.blade.php')));
        $this->assertStringContainsString('wire:intersect.once="loadDashboardPanel"', file_get_contents(resource_path('views/livewire/dashboard/panel-placeholder.blade.php')));
    }
}
