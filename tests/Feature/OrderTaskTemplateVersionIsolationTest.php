<?php

namespace Tests\Feature;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\TaskPackItem;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\JobService;
use App\Services\OrderWorkflowBindingService;
use App\Services\OrderWorkflowSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderTaskTemplateVersionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_order_owns_frozen_seven_stage_definitions_and_tasks(): void
    {
        config()->set('flowtrack.order_template_version_isolation_enabled', true);
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $workflow = $this->createWorkflow();
        $sourcePhases = $workflow->phases()->where('is_active', true)->orderBy('sequence')->get();
        $sourceItem = $sourcePhases->first()->taskPack->items()->orderBy('sort_order')->firstOrFail();

        $order = $this->createOrder($workflow, $admin, 'Frozen Order');
        $this->assertNotSame((int) $workflow->id, (int) $order->workflow_id);
        $this->assertSame((int) $workflow->id, (int) $order->source_workflow_id);
        $this->assertTrue($order->workflow->is_snapshot);
        $this->assertSame((int) $order->id, (int) $order->workflow->snapshot_job_id);
        $this->assertCount(7, $order->workflow->phases);

        $task = Task::query()->where('flow_job_id', $order->id)
            ->whereHas('setupTemplate', fn ($q) => $q->where('source_task_pack_item_id', $sourceItem->id))
            ->firstOrFail();
        $this->assertSame((int) $order->id, (int) $task->flow_job_id);
        $this->assertNotSame((int) $sourceItem->id, (int) $task->task_pack_task_id);
        $this->assertSame((string) $sourceItem->title, (string) $task->setupTemplate->title);
        $this->assertSame((string) $sourceItem->title, (string) $task->title);

        // Published-template edits cannot silently change the Order copy.
        $originalTitle = $task->title;
        $sourceItem->update(['title' => 'Changed task in Workflow Setup']);
        $this->assertFalse(app(OrderWorkflowBindingService::class)->syncSingleActiveOrder($order->id));
        $this->assertSame($originalTitle, $task->fresh()->title);
        $this->assertSame($originalTitle, $task->fresh('setupTemplate')->setupTemplate->title);
        $this->assertSame((int) $order->workflow_phase_id, (int) $order->fresh()->workflow_phase_id);

        // The next Order gets its OWN copy of the updated template definition.
        $newOrder = $this->createOrder($workflow, $admin, 'New Template Version');
        $this->assertNotSame((int) $order->workflow_id, (int) $newOrder->workflow_id);
        $newTask = Task::query()->where('flow_job_id', $newOrder->id)
            ->whereHas('setupTemplate', fn ($q) => $q->where('source_task_pack_item_id', $sourceItem->id))
            ->firstOrFail();
        $this->assertSame('Changed task in Workflow Setup', $newTask->title);
    }

    public function test_existing_live_order_creation_path_is_unchanged_with_flag_off(): void
    {
        config()->set('flowtrack.order_template_version_isolation_enabled', false);
        $admin = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $workflow = $this->createWorkflow();

        $order = $this->createOrder($workflow, $admin, 'Legacy Compatible Order');
        $this->assertSame((int) $workflow->id, (int) $order->workflow_id);
        $this->assertFalse($order->workflow->is_snapshot);
        $this->assertGreaterThan(0, Task::query()->where('flow_job_id', $order->id)->count());
    }

    private function createWorkflow(): WorkflowTemplate
    {
        $workflow = WorkflowTemplate::query()->create([
            'workspace_id' => 1,
            'name' => 'Template Isolation Test',
            'code' => 'ORDER-ISOLATION',
            'applies_to' => 'orders',
            'client_availability' => 'all',
            'is_active' => true,
            'is_default' => true,
            'version' => 1,
        ]);
        app(OrderWorkflowSetupService::class)->initializeWorkflowTemplate($workflow);
        return $workflow->refresh();
    }

    private function createOrder(WorkflowTemplate $workflow, User $admin, string $title): FlowJob
    {
        $phase = $workflow->phases()->where('is_active', true)->orderBy('sequence')->firstOrFail();
        $order = app(JobService::class)->create([
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'owner_id' => $admin->id,
            'coordinator_id' => $admin->id,
            'client_id' => null,
            'title' => $title,
            'product' => 'Test Product',
            'category' => 'Test',
            'quantity' => 2,
            'items' => [['product' => 'Test Product', 'quantity' => 2]],
            'draft' => true,
        ], $admin);

        return $order->fresh('workflow.phases');
    }
}
