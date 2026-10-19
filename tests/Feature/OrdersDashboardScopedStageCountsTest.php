<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrdersDashboardScopedStageCountsTest extends TestCase
{
    public function test_orders_stage_cards_use_the_same_dashboard_scope_as_the_table(): void
    {
        $orders = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $query = file_get_contents(app_path('Queries/Orders/OrderListQuery.php'));
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));

        $this->assertStringContainsString("'dashboard_scope' => \$this->dashboardScope === 1", $orders);
        $this->assertStringContainsString("'date_from' => \$this->dateFrom", $orders);
        $this->assertStringContainsString("'date_to' => \$this->dateTo", $orders);
        $this->assertStringContainsString("'client_id' => \$this->filterId(\$this->client)", $orders);
        $this->assertStringContainsString("'dashboard_team_id' => \$this->filterId(\$this->dashboardTeam)", $orders);
        $this->assertStringContainsString('->stagesForFilters($user, $filters)', $orders);

        $this->assertStringContainsString('public function dashboardScopedStages(', $query);
        $this->assertStringContainsString('public function dashboardScopedStages(', $service);
        $this->assertStringContainsString("whereBetween('flow_jobs.updated_at', [\$rangeFrom, \$rangeTo])", $service);
        $this->assertStringContainsString("where('flow_jobs.client_id', \$clientId)", $service);
        $this->assertStringContainsString("where('users.department_id', \$departmentId)", $service);
        $this->assertStringContainsString("'from', \$dateFrom", $service);
        $this->assertStringContainsString("'to', \$dateTo", $service);
    }
}
