<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardGlobalPeriodSectionsTest extends TestCase
{
    public function test_team_performance_and_client_portfolio_use_global_dashboard_period(): void
    {
        $dashboard = file_get_contents(resource_path('views/livewire/dashboard/index.blade.php'));
        $component = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $service = file_get_contents(app_path('Services/LegacyDashboardService.php'));
        $primaryQuery = file_get_contents(app_path('Queries/Dashboard/DashboardPrimaryQuery.php'));

        $this->assertStringContainsString('wire:click="setRange(1)"', $dashboard);
        $this->assertStringContainsString('wire:click="setRange(7)"', $dashboard);
        $this->assertStringContainsString('wire:click="setRange(30)"', $dashboard);

        // The dashboard Team Performance card must not keep a second period selector.
        $this->assertStringNotContainsString('wire:model.live="teamPeriod"', $dashboard);
        $this->assertStringNotContainsString('Team performance reporting period', $dashboard);

        $this->assertStringContainsString('$this->rangeDays,', $component);
        $this->assertStringContainsString('DashboardClientPortfolioQuery', $primaryQuery);
        $this->assertStringContainsString('DashboardTeamPerformanceQuery', $primaryQuery);
        $this->assertStringContainsString('dashboardPeriod($actor, $filters->rangeDays)', $primaryQuery);
        $this->assertStringContainsString("whereBetween('flow_jobs.updated_at', \$rangeBounds)", $service);
        $this->assertStringContainsString("whereBetween('inquiries.updated_at', \$rangeBounds)", $service);
    }
}
