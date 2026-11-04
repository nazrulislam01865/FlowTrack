<?php

namespace Tests\Feature;

use App\Models\FlowJob;
use App\Models\FlowJobMember;
use App\Models\OrderWorkflowSummary;
use App\Models\User;
use App\Services\Orders\OrderWorkflowSummaryService;
use ReflectionMethod;
use Tests\TestCase;

class OrderDetailsReleaseCDSummaryOptimizationTest extends TestCase
{
    public function test_loaded_assignee_is_reused_without_an_extra_user_lookup(): void
    {
        $service = app(OrderWorkflowSummaryService::class);
        $job = new FlowJob;
        $owner = new User;
        $owner->id = 21;
        $owner->name = 'Current Owner';
        $job->setRelation('owner', $owner);

        $summary = new OrderWorkflowSummary;
        $summary->next_task_assignee_id = 21;
        $method = new ReflectionMethod($service, 'toArray');

        $result = $method->invoke($service, $summary, $job);
        $this->assertSame('Current Owner', $result['next_task_assignee_name']);
        $this->assertSame(21, $result['next_task_assignee_id']);
    }

    public function test_loaded_order_member_user_can_supply_next_assignee_name(): void
    {
        $service = app(OrderWorkflowSummaryService::class);
        $job = new FlowJob;
        $assignee = new User;
        $assignee->id = 34;
        $assignee->name = 'Task Assignee';
        $member = new FlowJobMember;
        $member->user_id = 34;
        $member->setRelation('user', $assignee);
        $job->setRelation('members', collect([$member]));

        $method = new ReflectionMethod($service, 'loadedAssigneeName');
        $this->assertSame('Task Assignee', $method->invoke($service, $job, 34));
        $this->assertNull($method->invoke($service, $job, 35));
    }

    public function test_no_unloaded_relation_is_read_when_assignee_is_not_present(): void
    {
        $service = app(OrderWorkflowSummaryService::class);
        $job = new FlowJob;
        $method = new ReflectionMethod($service, 'loadedAssigneeName');

        $this->assertNull($method->invoke($service, $job, 34));
    }

    public function test_redo_summary_keeps_both_counts_in_one_aggregate_query(): void
    {
        $source = file_get_contents(app_path('Services/OrderRedoService.php'));
        $this->assertStringContainsString('COUNT(*) AS redo_count, COUNT(redo_order_id) AS redo_order_count', $source);
        $this->assertStringContainsString('$displayRecord = $incoming;', $source);
        $this->assertStringContainsString('->orderByDesc(\'sequence\')', $source);
        $this->assertStringContainsString("'hasRedo' => (bool) \$incoming || \$redoCount > 0", $source);
    }

    public function test_summary_schema_checks_are_request_scoped(): void
    {
        $this->assertSame(
            app(OrderWorkflowSummaryService::class),
            app(OrderWorkflowSummaryService::class),
        );
    }
}
