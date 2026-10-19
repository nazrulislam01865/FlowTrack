<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListClientPhaseAssigneeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_list_query_combines_client_phase_and_task_assignee_filters(): void
    {
        $viewer = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $firstAssignee = User::factory()->create(['is_active' => true]);
        $secondAssignee = User::factory()->create(['is_active' => true]);

        [$firstClient, $firstWorkflow, $firstPhase] = $this->orderDependencies('First');
        [$secondClient, $secondWorkflow, $secondPhase] = $this->orderDependencies('Second');

        $firstOrder = $this->createOrder($firstClient, $firstWorkflow, $firstPhase, 'ORDER-FILTER-FIRST', $firstAssignee);
        $secondOrder = $this->createOrder($secondClient, $secondWorkflow, $secondPhase, 'ORDER-FILTER-SECOND', $secondAssignee);

        Task::create([
            'task_number' => 'TASK-FILTER-FIRST',
            'flow_job_id' => $firstOrder->id,
            'workflow_phase_id' => $firstPhase->id,
            'assignee_id' => $firstAssignee->id,
            'title' => 'First assigned task',
            'status' => 'In Progress',
            'priority' => 'Medium',
        ]);
        Task::create([
            'task_number' => 'TASK-FILTER-SECOND',
            'flow_job_id' => $secondOrder->id,
            'workflow_phase_id' => $secondPhase->id,
            'assignee_id' => $secondAssignee->id,
            'title' => 'Second assigned task',
            'status' => 'In Progress',
            'priority' => 'Medium',
        ]);

        $service = app(JobService::class);

        $this->assertSame([$firstOrder->id], collect($service->paginateOrders($viewer, '', 25, $firstClient->id)->items())->pluck('id')->all());
        $this->assertSame([$firstOrder->id], collect($service->paginateOrders($viewer, '', 25, null, $firstPhase->id)->items())->pluck('id')->all());
        $this->assertSame([$firstOrder->id], collect($service->paginateOrders($viewer, '', 25, null, null, $firstAssignee->id)->items())->pluck('id')->all());
        $this->assertSame([$firstOrder->id], collect($service->paginateOrders($viewer, '', 25, $firstClient->id, $firstPhase->id, $firstAssignee->id)->items())->pluck('id')->all());
        $this->assertSame([$firstOrder->id], collect($service->paginateOrders($viewer, '', 25, null, null, null, $firstAssignee->id)->items())->pluck('id')->all());
    }


    public function test_phase_filter_uses_source_phase_id_for_snapshotted_orders(): void
    {
        $viewer = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $owner = User::factory()->create(['is_active' => true]);
        [$client, $workflow, $sourcePhase] = $this->orderDependencies('Snapshot');

        $snapshotWorkflow = Workflow::create([
            'name' => 'Snapshot Workflow',
            'slug' => 'snapshot-workflow-'.uniqid(),
            'is_active' => false,
            'is_snapshot' => true,
            'source_workflow_id' => $workflow->id,
        ]);
        $snapshotPhase = WorkflowPhase::create([
            'workflow_id' => $snapshotWorkflow->id,
            'source_workflow_phase_id' => $sourcePhase->id,
            'sequence' => $sourcePhase->sequence,
            'name' => $sourcePhase->name,
            'short_name' => 'Generic short label',
            'allow_job_start' => true,
            'is_active' => true,
        ]);

        $order = FlowJob::create([
            'job_number' => 'ORDER-SNAPSHOT-PHASE',
            'client_id' => $client->id,
            'workflow_id' => $snapshotWorkflow->id,
            'source_workflow_id' => $workflow->id,
            'workflow_phase_id' => $snapshotPhase->id,
            'source_workflow_phase_id' => $sourcePhase->id,
            'owner_id' => $owner->id,
            'title' => 'Snapshot phase order',
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
        ]);

        $result = app(JobService::class)->paginateOrders($viewer, '', 25, null, $sourcePhase->id);

        $this->assertSame([$order->id], collect($result->items())->pluck('id')->all());
        $this->assertSame($sourcePhase->name, $result->items()[0]->phase->name);
    }

    public function test_order_list_uses_remote_searchable_filters_for_client_phase_and_owner_with_clear_all(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/table.blade.php'));
        $component = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $options = file_get_contents(app_path('Services/FilterOptionService.php'));

        $this->assertStringContainsString('property="client"', $view);
        $this->assertStringContainsString('type="clients"', $view);
        $this->assertStringContainsString('property="phase"', $view);
        $this->assertStringContainsString('type="phases"', $view);
        $this->assertStringContainsString(':property="$peopleProperty"', $view);
        $this->assertStringContainsString('type="users"', $view);
        $this->assertStringContainsString("'order-list-owner'", $view);
        $this->assertStringContainsString('<x-ui.filter-reset', $view);
        $this->assertStringContainsString('label="Clear filters"', $view);
        $this->assertStringNotContainsString('Type to search all', $view);
        $this->assertStringContainsString("public string \$owner = '';", $component);
        $this->assertStringContainsString('public function clearFilters(): void', $component);
        $this->assertStringContainsString("if (\$context === 'order-list-owner')", $options);
        $this->assertStringContainsString("->where('applies_to', 'orders')", $options);
        $this->assertStringContainsString('<span>Phase</span>', $view);
        $this->assertStringContainsString('$job->phase?->name', $view);
        $this->assertStringNotContainsString('Order stage', $view);
    }

    private function orderDependencies(string $suffix): array
    {
        $client = Client::create([
            'name' => $suffix.' Client',
            'code' => strtoupper($suffix).'-CLIENT',
            'is_active' => true,
        ]);
        $workflow = Workflow::create([
            'name' => $suffix.' Workflow',
            'slug' => strtolower($suffix).'-workflow',
            'is_active' => true,
        ]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => $suffix.' Phase',
            'short_name' => $suffix.' Phase',
            'allow_job_start' => true,
            'is_active' => true,
        ]);

        return [$client, $workflow, $phase];
    }

    private function createOrder(Client $client, Workflow $workflow, WorkflowPhase $phase, string $number, User $owner): FlowJob
    {
        return FlowJob::create([
            'job_number' => $number,
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'owner_id' => $owner->id,
            'title' => $number,
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
        ]);
    }
}
