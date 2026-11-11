<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Release B request ownership and legacy fallback contracts. */
class DashboardReleaseBPanelIsolationTest extends TestCase
{
    public function test_progressive_shell_uses_stable_isolated_lazy_panels(): void
    {
        $shell = file_get_contents(resource_path('views/livewire/dashboard/progressive-shell.blade.php'));
        $this->assertStringContainsString('<livewire:dashboard.overview-panel', $shell);
        foreach (['priority', 'attention', 'tagged-comments', 'distribution', 'team', 'portfolio', 'catalogue', 'activity'] as $panel) {
            $this->assertStringContainsString('<livewire:dashboard.'.$panel.($panel === 'tagged-comments' ? '' : '-panel'), $shell);
        }
        $this->assertStringNotContainsString('<livewire:dashboard.legacy-panels', $shell);
        $this->assertStringNotContainsString('wire:poll', $shell);
        $this->assertStringContainsString('key="dashboard-priority"', $shell);
        $this->assertStringContainsString('key="dashboard-activity"', $shell);
        $this->assertStringNotContainsString('lazy key=', $shell);
        $this->assertStringContainsString('wire:intersect.once="loadDashboardPanel"', file_get_contents(resource_path('views/livewire/dashboard/panel-placeholder.blade.php')));
    }

    public function test_each_panel_owns_exactly_its_query_without_full_dashboard_aggregate(): void
    {
        $owners = [
            'PriorityPanel' => 'DashboardPriorityWorkQuery',
            'AttentionPanel' => 'DashboardAttentionQuery',
            'DistributionPanel' => 'DashboardDistributionQuery',
            'TeamPanel' => 'DashboardTeamPerformanceQuery',
            'PortfolioPanel' => 'DashboardClientPortfolioQuery',
            'CataloguePanel' => 'DashboardCatalogueReadinessQuery',
            'ActivityPanel' => 'DashboardActivityQuery',
        ];
        foreach ($owners as $component => $query) {
            $source = file_get_contents(app_path('Livewire/Dashboard/'.$component.'.php'));
            $this->assertStringContainsString($query.'::class', $source);
            $this->assertStringNotContainsString('DashboardPrimaryQuery', $source);
            foreach ($owners as $unrelated => $otherQuery) {
                if ($component !== $unrelated) $this->assertStringNotContainsString($otherQuery.'::class', $source);
            }
        }
    }

    public function test_priority_requests_only_selected_ranked_candidates_and_five_row_page(): void
    {
        $query = file_get_contents(app_path('Queries/Dashboard/DashboardPriorityWorkQuery.php'));
        $selected = explode('public function handleSelected(', $query, 2)[1];
        foreach (["'orders' =>", "'inquiries' =>", "'tasks' =>"] as $tab) {
            $this->assertStringContainsString($tab, $selected);
        }
        $component = file_get_contents(app_path('Livewire/Dashboard/PriorityPanel.php'));
        $this->assertStringContainsString('handleSelected(', $component);
        $this->assertStringContainsString('private const PER_PAGE = 5;', $component);
        $this->assertStringContainsString('priorityFilterIdentity', $component);
        $this->assertStringNotContainsString('DashboardPrimaryQuery', $component);
    }

    public function test_all_panels_recheck_dashboard_permission_and_selected_filters(): void
    {
        $base = file_get_contents(app_path('Livewire/Dashboard/Panel.php'));
        $this->assertStringContainsString("'dashboard', 'view'", $base);
        $this->assertStringContainsString('selectedOptions(', $base);
        $this->assertStringContainsString("'That filter option is no longer available.'", $base);
        $this->assertStringContainsString('ReceivesDashboardFilters', $base);
        $this->assertStringNotContainsString('#[Reactive]', $base);
        $this->assertStringContainsString("'flowtrack-notification'", $base);
        $team = file_get_contents(app_path('Livewire/Dashboard/TeamPanel.php'));
        $this->assertStringContainsString("'reports', 'view'", $team);
        $this->assertStringContainsString("@if(auth()->user()->canAccess('reports.view'))", file_get_contents(resource_path('views/livewire/dashboard/progressive-shell.blade.php')));
    }

    public function test_panel_markups_retain_original_buttons_labels_and_existing_routes(): void
    {
        $directory = resource_path('views/livewire/dashboard/');
        $contract = [
            'priority-panel' => ['Priority work', 'setPriorityTab(', 'previousPriorityPage', 'nextPriorityPage'],
            'attention-panel' => ['Needs attention', 'setAttentionTab(', 'metric'],
            'distribution-panel' => ['Work moving through FlowTrack', 'setFlowTab(', 'setTaskStatusTab('],
            'team-panel' => ['Team performance &amp; workload', 'team-performance.report'],
            'portfolio-panel' => ['Client portfolio', 'clients.index'],
            'catalogue-panel' => ['Catalogue readiness', 'master-data'],
            'activity-panel' => ['Recent activity', 'setActivityTab(', 'wire:navigate'],
        ];
        foreach ($contract as $view => $terms) {
            $source = file_get_contents($directory.$view.'.blade.php');
            foreach ($terms as $term) $this->assertStringContainsString($term, $source);
        }
    }

    public function test_filter_generation_is_shared_and_local_priority_pagination_resets(): void
    {
        $shell = file_get_contents(resource_path('views/livewire/dashboard/progressive-shell.blade.php'));
        $parent = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $base = file_get_contents(app_path('Livewire/Dashboard/Panel.php'));
        $priority = file_get_contents(app_path('Livewire/Dashboard/PriorityPanel.php'));
        $this->assertSame(8, substr_count($shell, ':filter-generation="$filterGeneration"'));
        $this->assertStringContainsString('wire:model.live.debounce.300ms="search"', $shell);
        $this->assertStringContainsString('public int $filterGeneration = 0;', $parent);
        $this->assertStringContainsString('$this->filterGeneration++', $parent);
        $this->assertStringContainsString('public int $filterGeneration = 0;', $base);
        $this->assertStringContainsString('if ($this->priorityFilterIdentity !== $identity)', $priority);
        $this->assertStringContainsString('$this->priorityPage = 1;', $priority);
    }

    public function test_needs_attention_skips_unused_task_query(): void
    {
        $reader = file_get_contents(app_path('Queries/Dashboard/DashboardAttentionQuery.php'));
        $visible = explode('public function handleVisible(', $reader, 2)[1];
        $this->assertStringContainsString('attentionOrders(', $visible);
        $this->assertStringContainsString('attentionInquiries(', $visible);
        $this->assertStringNotContainsString('attentionTasks(', $visible);
        $panel = file_get_contents(app_path('Livewire/Dashboard/AttentionPanel.php'));
        $this->assertStringContainsString('handleVisible($actor, $filters)', $panel);
    }

    public function test_local_retry_and_legacy_fallback_are_preserved(): void
    {
        $error = file_get_contents(resource_path('views/livewire/dashboard/panel-error.blade.php'));
        $this->assertStringContainsString('wire:click="$refresh"', $error);
        $page = file_get_contents(resource_path('views/pages/dashboard.blade.php'));
        $this->assertStringContainsString('<livewire:dashboard.index defer />', $page);
        $index = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $this->assertStringContainsString("config('dashboard.progressive_loading_enabled', false)", $index);
        $this->assertStringContainsString('legacyDashboardData()', $index);
    }
}
