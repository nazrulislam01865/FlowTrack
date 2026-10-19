<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\TaskPackTask;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Services\JobService;
use App\Services\OrderWorkflowSetupService;
use App\Services\TaskPackService;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SafeSetupDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_order_uses_the_published_seven_stage_workflow_and_materializes_all_tasks(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $this->actingAs($user);
        $client = Client::query()->create(['name' => 'Snapshot Client', 'code' => 'SNAP-1', 'is_active' => true]);
        [$legacy, $template, $phase] = $this->readyOrderWorkflow('WF-SNAP', 'Snapshot Workflow', false);

        $job = app(JobService::class)->create([
            'client_id' => $client->id,
            'workflow_id' => $legacy->id,
            'workflow_phase_id' => $phase->id,
            'owner_id' => $user->id,
            'coordinator_id' => $user->id,
            'title' => 'Independent Job',
            'product' => 'Sample Product',
            'category' => 'Samples',
            'quantity' => 10,
            'priority' => 'Medium',
            'description' => null,
            'items' => [['product' => 'Sample Product', 'category' => 'Samples', 'quantity' => 10]],
            'draft' => false,
        ], $user);

        $job->refresh()->load('workflow.phases.taskPack.items', 'tasks');
        $expectedTaskCount = collect(OrderWorkflowSetupService::fixedStages())
            ->sum(fn (array $stage): int => count($stage['tasks'] ?? []));

        // Order workflows are live operational definitions at creation time.
        // Safe-delete snapshots them later if an administrator removes setup.
        $this->assertSame($legacy->id, (int) $job->workflow_id);
        $this->assertSame($legacy->id, (int) $job->source_workflow_id);
        $this->assertFalse((bool) $job->workflow->is_snapshot);
        $this->assertCount(count(OrderWorkflowSetupService::fixedStages()), $template->phases()->where('is_active', true)->get());
        $this->assertCount($expectedTaskCount, $job->tasks);
        $publishedPhaseIds = $template->phases()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertTrue($job->tasks->every(fn ($task) => in_array((int) $task->workflow_phase_id, $publishedPhaseIds, true)));
    }

    public function test_deleting_reusable_setup_snapshots_existing_order_without_losing_tasks(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $this->actingAs($user);
        $client = Client::query()->create(['name' => 'Independent Setup Client', 'code' => 'IND-1', 'is_active' => true]);
        [$legacy, $template, $phase] = $this->readyOrderWorkflow('WF-IND', 'Independent Workflow', false);
        $pack = $phase->taskPack()->firstOrFail();

        $job = app(JobService::class)->create([
            'client_id' => $client->id,
            'workflow_id' => $legacy->id,
            'workflow_phase_id' => $phase->id,
            'owner_id' => $user->id,
            'coordinator_id' => $user->id,
            'title' => 'Setup-independent Job',
            'product' => 'Independent Product',
            'category' => 'Samples',
            'quantity' => 1,
            'priority' => 'Medium',
            'description' => null,
            'items' => [['product' => 'Independent Product', 'category' => 'Samples', 'quantity' => 1]],
            'draft' => false,
        ], $user);

        $job->refresh()->load('tasks');
        $taskIds = $job->tasks->pluck('id')->sort()->values()->all();
        $taskTitles = $job->tasks->pluck('title')->sort()->values()->all();
        $this->assertSame($legacy->id, (int) $job->workflow_id);

        // Safe workflow deletion snapshots the linked Order first; after the
        // mapping is removed, its reusable Task Pack can be deleted safely.
        $workflowResult = app(WorkflowService::class)->deleteWorkflow($template->id);
        $packResult = app(TaskPackService::class)->deletePack($pack->id);

        $this->assertSame(1, $workflowResult['job_count']);
        $this->assertSame(0, $workflowResult['task_count']);
        $this->assertSame(0, $packResult['job_count']);
        $this->assertSame(0, $packResult['task_count']);

        $preserved = FlowJob::query()->with(['workflow', 'tasks'])->findOrFail($job->id);
        $this->assertNotSame($legacy->id, (int) $preserved->workflow_id);
        $this->assertSame($legacy->id, (int) $preserved->source_workflow_id);
        $this->assertTrue((bool) $preserved->workflow->is_snapshot);
        $this->assertSame($job->id, (int) $preserved->workflow->snapshot_job_id);
        $this->assertSame($taskIds, $preserved->tasks->pluck('id')->sort()->values()->all());
        $this->assertSame($taskTitles, $preserved->tasks->pluck('title')->sort()->values()->all());

        $this->assertDatabaseMissing('workflow_templates', ['id' => $template->id]);
        $this->assertDatabaseMissing('workflows', ['id' => $legacy->id]);
        $this->assertDatabaseMissing('task_packs', ['id' => $pack->id]);
        $this->assertDatabaseHas('workflows', ['id' => $preserved->workflow_id, 'is_snapshot' => 1, 'snapshot_job_id' => $job->id]);
        foreach ($taskIds as $taskId) {
            $this->assertDatabaseHas('tasks', ['id' => $taskId, 'flow_job_id' => $job->id]);
        }
    }

    public function test_workflow_delete_preserves_linked_jobs_and_tasks_by_snapshotting_them_first(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'is_active' => true]));
        $client = Client::query()->create(['name' => 'Safe Delete Client', 'code' => 'SAFE-1', 'is_active' => true]);

        [$legacyA] = $this->workflowPair('WF-A', 'Primary Workflow', true);
        [$legacyB, $templateB] = $this->workflowPair('WF-B', 'Workflow To Delete', false);
        $phaseB = $this->phase($legacyB, $templateB, 1, 'Delete Phase', null, true);

        // Reproduce the old production inconsistency: workflow_id points at A,
        // while the current phase belongs to B. Safe delete must still preserve
        // the Job instead of raising an FK error or deleting operational data.
        $job = FlowJob::query()->create([
            'job_number' => 'JOB-SAFE-001',
            'client_id' => $client->id,
            'workflow_id' => $legacyA->id,
            'workflow_phase_id' => $phaseB->id,
            'started_from_phase_id' => $phaseB->id,
            'title' => 'Phase-linked Job',
        ]);
        $task = Task::query()->create([
            'task_number' => 'TASK-SAFE-001',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phaseB->id,
            'title' => 'Linked Task',
        ]);

        $service = app(WorkflowService::class);
        $impact = $service->workflowDeleteImpact($templateB->id);
        $this->assertSame(1, $impact['job_count']);
        $this->assertSame(1, $impact['task_count']);

        $result = $service->deleteWorkflow($templateB->id);

        $this->assertSame(1, $result['job_count']);
        $this->assertSame(0, $result['task_count']);
        $this->assertDatabaseHas('flow_jobs', ['id' => $job->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('workflow_phases', ['id' => $phaseB->id]);
        $this->assertDatabaseMissing('workflow_templates', ['id' => $templateB->id]);
        $this->assertDatabaseMissing('workflows', ['id' => $legacyB->id]);

        $preservedJob = FlowJob::query()->findOrFail($job->id);
        $preservedTask = Task::query()->findOrFail($task->id);
        $this->assertNotSame($legacyB->id, $preservedJob->workflow_id);
        $this->assertSame($legacyB->id, $preservedJob->source_workflow_id);
        $this->assertNotSame($phaseB->id, $preservedJob->workflow_phase_id);
        $this->assertSame($preservedJob->workflow_phase_id, $preservedTask->workflow_phase_id);
    }


    public function test_last_default_workflow_can_be_deleted_and_leave_setup_empty(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'is_active' => true]));
        [$legacy, $template] = $this->workflowPair('WF-LAST', 'Last Workflow', true);
        $this->phase($legacy, $template, 1, 'Only Phase', null, true);

        $service = app(WorkflowService::class);
        $impact = $service->workflowDeleteImpact($template->id);

        $this->assertTrue($impact['can_delete']);
        $this->assertTrue($impact['will_leave_no_default']);
        $this->assertNull($impact['blocked_reason']);

        $service->deleteWorkflow($template->id);

        $this->assertDatabaseMissing('workflow_templates', ['id' => $template->id]);
        $this->assertDatabaseMissing('workflows', ['id' => $legacy->id]);
        $this->assertSame(0, WorkflowTemplate::query()->count());
    }

    public function test_default_workflow_can_be_deleted_and_another_workflow_is_promoted_automatically(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'is_active' => true]));
        [$legacyA, $templateA] = $this->workflowPair('WF-DEFAULT-A', 'Default A', true);
        [$legacyB, $templateB] = $this->workflowPair('WF-DEFAULT-B', 'Other Workflow', false);

        $service = app(WorkflowService::class);
        $impact = $service->workflowDeleteImpact($templateA->id);

        $this->assertTrue($impact['can_delete']);
        $this->assertFalse($impact['will_leave_no_default']);
        $this->assertNull($impact['blocked_reason']);
        $this->assertSame($templateB->id, $impact['replacement_default']['id']);

        $service->deleteWorkflow($templateA->id);

        $this->assertDatabaseMissing('workflow_templates', ['id' => $templateA->id]);
        $this->assertDatabaseMissing('workflows', ['id' => $legacyA->id]);
        $this->assertDatabaseHas('workflow_templates', [
            'id' => $templateB->id,
            'is_default' => 1,
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('workflows', ['id' => $legacyB->id, 'is_active' => 1]);
    }

    public function test_deleting_default_promotes_inactive_replacement_and_activates_it(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'is_active' => true]));
        [, $templateA] = $this->workflowPair('WF-DEFAULT-C', 'Default C', true);
        [$legacyB, $templateB] = $this->workflowPair('WF-INACTIVE-D', 'Inactive Replacement', false);
        $templateB->update(['is_active' => false]);
        $legacyB->update(['is_active' => false]);

        $service = app(WorkflowService::class);
        $impact = $service->workflowDeleteImpact($templateA->id);

        $this->assertTrue($impact['can_delete']);
        $this->assertSame($templateB->id, $impact['replacement_default']['id']);
        $this->assertFalse($impact['replacement_default']['was_active']);

        $service->deleteWorkflow($templateA->id);

        $this->assertDatabaseHas('workflow_templates', [
            'id' => $templateB->id,
            'is_default' => 1,
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('workflows', ['id' => $legacyB->id, 'is_active' => 1]);
    }

    public function test_first_workflow_created_after_empty_setup_becomes_default_automatically(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'is_active' => true]));
        [$legacy, $template] = $this->workflowPair('WF-OLD-LAST', 'Old Last Workflow', true);

        $service = app(WorkflowService::class);
        $service->deleteWorkflow($template->id);

        $created = $service->saveWorkflow([
            'code' => 'WF-NEW-FIRST',
            'name' => 'New First Workflow',
            'description' => null,
            'is_active' => false,
            'version' => 1,
        ]);

        $this->assertTrue((bool) $created->is_default);
        $this->assertTrue((bool) $created->is_active);
        $this->assertDatabaseHas('workflows', ['id' => $created->id, 'is_active' => 1]);
        $this->assertDatabaseMissing('workflows', ['id' => $legacy->id]);
    }

    public function test_task_pack_delete_is_blocked_while_mapped_to_an_order_workflow(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'is_active' => true]));
        [, , $phase] = $this->readyOrderWorkflow('WF-TP', 'Task Pack Workflow', true);
        $pack = $phase->taskPack()->firstOrFail();

        $service = app(TaskPackService::class);
        $impact = $service->packDeleteImpact($pack->id);

        $this->assertFalse($impact['can_delete']);
        $this->assertGreaterThanOrEqual(1, $impact['mapped_phase_count']);
        $this->assertStringContainsString('mapped to an Order workflow', (string) $impact['blocked_reason']);

        try {
            $service->deletePack($pack->id);
            $this->fail('Mapped Order workflow Task Packs must not be deleted directly.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('mapped to an Order workflow', $exception->getMessage());
        }

        $this->assertDatabaseHas('task_packs', ['id' => $pack->id]);
        $this->assertDatabaseHas('workflow_phases', ['id' => $phase->id, 'task_pack_id' => $pack->id]);
    }

    private function readyOrderWorkflow(string $code, string $name, bool $default): array
    {
        $template = WorkflowTemplate::query()->create([
            'workspace_id' => 1,
            'code' => $code,
            'name' => $name,
            'applies_to' => 'orders',
            'client_availability' => 'all',
            'is_active' => true,
            'is_default' => $default,
            'version' => 1,
        ]);

        $setup = app(OrderWorkflowSetupService::class);
        $setup->initializeWorkflowTemplate($template);
        $legacy = Workflow::query()->findOrFail($template->id);
        $phase = WorkflowPhase::query()
            ->where('workflow_template_id', $template->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->with('taskPack')
            ->firstOrFail();

        return [$legacy, $template->refresh(), $phase];
    }

    private function workflowPair(string $code, string $name, bool $default): array
    {
        $legacy = Workflow::query()->create([
            'name' => $name,
            'slug' => strtolower($code).'-'.uniqid(),
            'is_active' => true,
        ]);

        $template = WorkflowTemplate::query()->create([
            'id' => $legacy->id,
            'workspace_id' => 1,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'is_default' => $default,
            'version' => 1,
        ]);

        return [$legacy, $template];
    }

    private function taskPack(string $code, string $name, array $tasks): TaskPack
    {
        $pack = TaskPack::query()->create([
            'workspace_id' => 1,
            'code' => $code,
            'name' => $name,
            'slug' => strtolower($code).'-'.uniqid(),
            'is_active' => true,
        ]);

        foreach ($tasks as $index => $title) {
            $sharedId = max((int) TaskPackItem::query()->max('id'), (int) TaskPackTask::query()->max('id')) + 1;
            TaskPackTask::query()->create([
                'id' => $sharedId,
                'task_pack_id' => $pack->id,
                'title' => $title,
                'sequence' => $index + 1,
                'is_required' => true,
            ]);
            TaskPackItem::query()->create([
                'id' => $sharedId,
                'task_pack_id' => $pack->id,
                'title' => $title,
                'due_offset_days' => $index + 1,
                'is_required' => true,
                'sort_order' => $index,
            ]);
        }

        return $pack->refresh();
    }

    private function phase(Workflow $legacy, WorkflowTemplate $template, int $sequence, string $name, ?TaskPack $pack, bool $canStart): WorkflowPhase
    {
        return WorkflowPhase::query()->create([
            'workflow_id' => $legacy->id,
            'workflow_template_id' => $template->id,
            'task_pack_id' => $pack?->id,
            'sequence' => $sequence,
            'name' => $name,
            'short_name' => $name,
            'allow_job_start' => $canStart,
            'can_skip' => false,
            'is_skippable' => false,
            'requires_approval' => false,
            'auto_advance_on_ready' => false,
            'is_active' => true,
        ]);
    }
}
