<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTaskAutomaticAssigneeTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_action_assigns_the_order_task_to_the_actor_and_records_history(): void
    {
        $originalAssignee = User::factory()->create(['name' => 'Original Assignee']);
        $actor = User::factory()->create([
            'name' => 'Status Actor',
            'is_super_admin' => true,
        ]);
        [$job, $task] = $this->orderTask($originalAssignee);

        app(TaskService::class)->moveStatus($task, 'In Progress', $actor);

        $task->refresh();
        $this->assertSame($actor->id, $task->assignee_id);
        $this->assertSame('In Progress', $task->status);

        $assignment = $task->activities()
            ->where('event', 'task.assignee_auto_assigned')
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame($actor->id, $assignment->user_id);
        $this->assertSame($originalAssignee->id, data_get($assignment->meta, 'old_assignee_id'));
        $this->assertSame($actor->id, data_get($assignment->meta, 'new_assignee_id'));
        $this->assertTrue((bool) data_get($assignment->meta, 'automatic'));
        $this->assertStringContainsString('Original Assignee', $assignment->description);
        $this->assertStringContainsString('Status Actor', $assignment->description);

        $this->assertTrue($job->activities()
            ->where('event', 'job.task_activity')
            ->where('meta->task_event', 'task.assignee_auto_assigned')
            ->exists());
    }

    public function test_comment_claims_an_unassigned_task_only_once_and_manual_assignment_remains_explicit(): void
    {
        $actor = User::factory()->create([
            'name' => 'Comment Actor',
            'is_super_admin' => true,
        ]);
        $manualAssignee = User::factory()->create(['name' => 'Manual Assignee']);
        [, $task] = $this->orderTask();
        $tasks = app(TaskService::class);

        $tasks->addComment($task, 'I am taking care of this task.', $actor);
        $tasks->addComment($task->refresh(), 'A follow-up update.', $actor);

        $this->assertSame($actor->id, $task->refresh()->assignee_id);
        $this->assertSame(1, $task->activities()->where('event', 'task.assignee_auto_assigned')->count());
        $this->assertSame(2, $task->comments()->count());
        $this->assertSame('Unassigned', data_get(
            $task->activities()->where('event', 'task.assignee_auto_assigned')->first()?->meta,
            'old',
        ));

        $tasks->updateDetailField($task->refresh(), 'assignee_id', $manualAssignee->id, $actor);

        $this->assertSame($manualAssignee->id, $task->refresh()->assignee_id);
        $this->assertSame(1, $task->activities()->where('event', 'task.assignee_auto_assigned')->count());
        $this->assertTrue($task->activities()
            ->where('event', 'task.field_updated')
            ->where('meta->field', 'assignee_id')
            ->exists());
    }

    /** @return array{FlowJob, Task} */
    private function orderTask(?User $assignee = null): array
    {
        $client = Client::create([
            'name' => 'Automatic Assignment Client '.uniqid(),
            'code' => 'AAC'.uniqid(),
            'is_active' => true,
        ]);
        $workflow = Workflow::create([
            'name' => 'Automatic Assignment Workflow '.uniqid(),
            'slug' => 'automatic-assignment-'.uniqid(),
            'is_active' => true,
        ]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Active Phase',
            'short_name' => 'Active',
            'allow_job_start' => true,
            'is_active' => true,
        ]);
        $job = FlowJob::create([
            'job_number' => 'ORDER-AUTO-'.uniqid(),
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'title' => 'Automatic assignment order',
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
        ]);
        $task = Task::create([
            'task_number' => 'TASK-AUTO-'.uniqid(),
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $assignee?->id,
            'title' => 'Automatic assignment task',
            'status' => 'Ready',
            'priority' => 'Medium',
        ]);

        return [$job, $task];
    }
}
