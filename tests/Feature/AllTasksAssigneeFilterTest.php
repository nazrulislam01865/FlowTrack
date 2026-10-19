<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\BoardTaskPackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllTasksAssigneeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_tasks_can_filter_by_a_user_or_only_unassigned_tasks(): void
    {
        $viewer = User::factory()->create(['is_super_admin' => true]);
        $assignee = User::factory()->create(['name' => 'Assigned Person']);
        [$job, $phase] = $this->orderFixture();

        $assignedTask = $this->task($job, $phase, 'Assigned task', $assignee);
        $unassignedTask = $this->task($job, $phase, 'Unassigned task');
        $service = app(BoardTaskPackService::class);

        $assignedPage = $service->paginate($viewer, $this->filters((string) $assignee->id));
        $this->assertSame([$assignedTask->id], $assignedPage['groups']->flatMap->tasks->pluck('id')->all());

        $unassignedPage = $service->paginate($viewer, $this->filters('unassigned'));
        $this->assertSame([$unassignedTask->id], $unassignedPage['groups']->flatMap->tasks->pluck('id')->all());
        $this->assertSame('Unassigned', $unassignedPage['groups']->first()['tasks']->first()['assignee']);
    }

    public function test_assignee_dropdown_contains_users_with_visible_tasks_and_an_unassigned_choice(): void
    {
        $viewer = User::factory()->create(['is_super_admin' => true]);
        $assignee = User::factory()->create(['name' => 'Visible Assignee']);
        $withoutTasks = User::factory()->create(['name' => 'No Task User']);
        [$job, $phase] = $this->orderFixture();
        $this->task($job, $phase, 'Visible assigned task', $assignee);

        $options = app(BoardTaskPackService::class)->assigneeOptions($viewer);

        $this->assertTrue($options->contains('id', $assignee->id));
        $this->assertFalse($options->contains('id', $withoutTasks->id));

        $view = file_get_contents(resource_path('views/livewire/board/index.blade.php'));
        $this->assertStringContainsString('<x-ui.search-select', $view);
        $this->assertStringContainsString('property="assignee"', $view);
        $this->assertStringContainsString("['id' => 'unassigned', 'label' => 'Unassigned']", $view);
        $this->assertStringContainsString('search-placeholder="Search people…"', $view);
    }

    private function filters(string $assignee): array
    {
        return [
            'search' => '',
            'assignee' => $assignee,
            'quick' => 'all',
            'phase' => '',
            'sort' => 'action',
            'hide_completed' => true,
        ];
    }

    /** @return array{FlowJob, WorkflowPhase} */
    private function orderFixture(): array
    {
        $client = Client::create([
            'name' => 'Assignee Filter Client '.uniqid(),
            'code' => 'AFC'.uniqid(),
            'is_active' => true,
        ]);
        $workflow = Workflow::create([
            'name' => 'Assignee Filter Workflow '.uniqid(),
            'slug' => 'assignee-filter-'.uniqid(),
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
            'job_number' => 'ORDER-AF-'.uniqid(),
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'title' => 'Assignee filter order',
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
        ]);

        return [$job, $phase];
    }

    private function task(FlowJob $job, WorkflowPhase $phase, string $title, ?User $assignee = null): Task
    {
        return Task::create([
            'task_number' => 'TASK-AF-'.uniqid(),
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $assignee?->id,
            'title' => $title,
            'status' => 'Ready',
            'priority' => 'Medium',
        ]);
    }
}
