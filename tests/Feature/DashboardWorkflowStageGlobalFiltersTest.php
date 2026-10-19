<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardWorkflowStageGlobalFiltersTest extends TestCase
{
    public function test_dashboard_stage_cards_use_filter_aware_stage_query(): void
    {
        $component = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $query = file_get_contents(app_path('Queries/Orders/OrderListQuery.php'));
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));

        $this->assertStringContainsString('->dashboardStages(', $component);
        $this->assertStringContainsString('$clientId,', $component);
        $this->assertStringContainsString('$departmentId,', $component);
        $this->assertStringContainsString('$this->rangeDays,', $component);
        $this->assertStringNotContainsString("app(OrderListQuery::class)->stages(\$user)", $component);

        $this->assertStringContainsString('public function dashboardStages(User $actor, int $clientId, int $departmentId, int $rangeDays): Collection', $query);
        $this->assertStringContainsString('public function dashboardStages(', $service);
        $this->assertStringContainsString("whereBetween('flow_jobs.updated_at', [\$rangeFrom, \$rangeTo])", $service);
        $this->assertStringContainsString("where('flow_jobs.client_id', \$clientId)", $service);
        $this->assertStringContainsString("where('users.department_id', \$departmentId)", $service);
        $this->assertStringContainsString('in_array($rangeDays, [1, 7, 30], true)', $service);
    }

    public function test_dashboard_stage_counts_keep_the_canonical_seven_stage_mapping(): void
    {
        $service = file_get_contents(app_path('Services/OrderListPrototypeService.php'));

        $this->assertStringContainsString('OrderStageResolver::resolve(', $service);
        $this->assertStringContainsString('OrderWorkflowSetupService::fixedStages()', $service);
        $this->assertStringContainsString("whereNull('flow_jobs.completed_at')", $service);
        $this->assertStringContainsString("whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES)", $service);
    }
}
