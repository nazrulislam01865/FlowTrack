<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardStageCardTableScopeTest extends TestCase
{
    public function test_dashboard_stage_cards_carry_the_active_period_into_orders_table(): void
    {
        $dashboard = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/dashboard/index.blade.php'));
        $stageComponent = file_get_contents(resource_path('views/components/orders/workflow-stage-overview.blade.php'));

        $this->assertStringContainsString("'dashboard_scope' => 1", $dashboard);
        $this->assertStringContainsString("'dashboard_range' => \$this->rangeDays", $dashboard);
        $this->assertStringContainsString("'date_from' => \$cutoff->toDateString()", $dashboard);
        $this->assertStringContainsString("'date_to' => \$today->toDateString()", $dashboard);
        $this->assertStringContainsString(':navigation-query="$orderStageNavigationQuery"', $view);
        $this->assertStringContainsString("array_merge(\$navigationQuery, ['phase' => \$stageId])", $stageComponent);
    }

    public function test_orders_table_uses_dashboard_updated_at_and_team_scope(): void
    {
        $orders = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));

        $this->assertStringContainsString("request('dashboard_scope', 0)", $orders);
        $this->assertStringContainsString("numericFilterFromRequest('dashboard_team')", $orders);
        $this->assertStringContainsString("'dashboard_scope' => \$this->dashboardScope === 1", $orders);
        $this->assertStringContainsString("'dashboard_team_id' => \$this->filterId(\$this->dashboardTeam)", $orders);

        $this->assertStringContainsString("\$dashboardScope ? '' : \$dateFrom", $service);
        $this->assertStringContainsString("where('flow_jobs.updated_at', '>=', \$rangeFrom)", $service);
        $this->assertStringContainsString("where('flow_jobs.updated_at', '<=', \$rangeTo)", $service);
        $this->assertStringContainsString("where('users.department_id', \$dashboardTeamId)", $service);
        $this->assertStringContainsString("where('clients.is_active', true)", $service);
    }
}
