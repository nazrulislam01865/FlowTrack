<?php

namespace Tests\Feature;

use Tests\TestCase;

class Phase12DashboardReadModelArchitectureTest extends TestCase
{
    public function test_primary_dashboard_composes_focused_queries(): void
    {
        $source = file_get_contents(app_path('Queries/Dashboard/DashboardPrimaryQuery.php'));
        foreach ([
            'DashboardSummaryQuery', 'DashboardPriorityWorkQuery', 'DashboardAttentionQuery',
            'DashboardTeamPerformanceQuery', 'DashboardClientPortfolioQuery',
            'DashboardDistributionQuery', 'DashboardActivityQuery',
            'DashboardCatalogueReadinessQuery',
        ] as $query) {
            $this->assertStringContainsString($query, $source);
        }
        $this->assertStringNotContainsString('primaryData(', $source);
    }

    public function test_dashboard_and_reports_livewire_enter_through_query_boundaries(): void
    {
        $dashboard = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $team = file_get_contents(app_path('Livewire/TeamPerformance/Report.php'));
        $report = file_get_contents(app_path('Livewire/Reports/Index.php'));

        $this->assertStringContainsString('DashboardPrimaryQuery::class', $dashboard);
        $this->assertStringContainsString('DashboardTeamPerformanceQuery::class', $team);
        $this->assertStringContainsString('InquiryIntelligenceReportQuery::class', $report);
        $this->assertStringNotContainsString('DashboardReportingService::class', $team);
        $this->assertStringNotContainsString('InquiryIntelligenceService::class', $report);
    }

    public function test_phase12_cache_has_explicit_ttl_and_invalidation_ownership(): void
    {
        $cache = file_get_contents(app_path('Services/Dashboard/DashboardReadModelCache.php'));
        $facade = file_get_contents(app_path('Services/DashboardService.php'));

        $this->assertStringContainsString('dashboard_cache_seconds', $cache);
        $this->assertStringContainsString('Cache::supportsTags()', $cache);
        $this->assertStringContainsString('generationKey', $cache);
        $this->assertStringContainsString('WorkspaceRefreshService', $cache);
        $this->assertStringContainsString('ClientService', $cache);
        $this->assertStringContainsString('forgetUser($user)', $facade);
        $this->assertStringContainsString('forgetMentions($user)', $facade);
    }

    public function test_dashboard_aggregate_implementation_remains_sql_driven(): void
    {
        $legacy = file_get_contents(app_path('Services/LegacyDashboardService.php'));
        $this->assertStringContainsString("selectRaw('count(*) as active_jobs')", $legacy);
        $this->assertStringContainsString("groupBy('tasks.assignee_id')", $legacy);
        $this->assertStringContainsString("'jobs as active_jobs_count'", $legacy);
        $this->assertStringContainsString("selectRaw('COUNT(*) as inquiries_count')", $legacy);
        $this->assertStringContainsString("selectRaw('count(*) as aggregate')", $legacy);
    }
}
