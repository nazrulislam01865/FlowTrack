<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\DashboardService;
use App\Services\SetupContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTeamCompletionRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_completion_rate_combines_inquiry_and_order_tasks_without_false_hundred_percent(): void
    {
        $viewer = User::factory()->create(['is_super_admin' => true, 'is_active' => true, 'name' => 'Dashboard Admin']);
        $assignee = User::factory()->create(['is_active' => true, 'name' => 'Qiao']);
        $zeroCompleted = User::factory()->create(['is_active' => true, 'name' => 'Open Only']);
        $noTasks = User::factory()->create(['is_active' => true, 'name' => 'No Tasks']);
        $this->addToCurrentWorkspace($assignee);
        $this->addToCurrentWorkspace($zeroCompleted);
        $this->addToCurrentWorkspace($noTasks);

        [$client, $job, $phase] = $this->orderFixture();

        Task::create([
            'task_number' => 'TEAM-ORDER-OPEN',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $assignee->id,
            'title' => 'Open order task',
            'status' => 'In Progress',
        ]);
        Task::create([
            'task_number' => 'TEAM-ORDER-DONE',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $assignee->id,
            'title' => 'Completed order task',
            'status' => 'Completed',
            'due_date' => today(),
            'completed_at' => now(),
        ]);
        Task::create([
            'task_number' => 'TEAM-ORDER-CANCELLED',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $assignee->id,
            'title' => 'Cancelled order task',
            'status' => 'Cancelled',
        ]);
        Task::create([
            'task_number' => 'TEAM-ORDER-ZERO-DONE',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $zeroCompleted->id,
            'title' => 'Still open',
            'status' => 'Ready',
        ]);

        $inquiry = Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-TEAM-RATE',
            'client_id' => $client->id,
            'owner_id' => $viewer->id,
            'created_by' => $viewer->id,
            'received_date' => today(),
            'subject' => 'Team completion rate inquiry',
            'status' => 'In Progress',
        ]);
        InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $assignee->id,
            'title' => 'Open inquiry task',
            'sequence' => 1,
            'status' => 'Waiting',
        ]);
        InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $assignee->id,
            'title' => 'Completed inquiry task',
            'sequence' => 2,
            'status' => 'Completed',
            'completed_at' => now(),
        ]);

        $performance = app(DashboardService::class)->assigneePerformance($viewer, period: 'this_week');

        $qiao = $performance->firstWhere('id', $assignee->id);
        $this->assertNotNull($qiao);
        $this->assertSame(4, $qiao->total_task_count);
        $this->assertSame(2, $qiao->open_count);
        $this->assertSame(2, $qiao->completed_count);
        $this->assertSame(2, $qiao->order_task_count);
        $this->assertSame(2, $qiao->inquiry_task_count);
        $this->assertSame(50, $qiao->completion_rate);
        $this->assertNull($qiao->on_time_rate);

        $openOnly = $performance->firstWhere('id', $zeroCompleted->id);
        $this->assertNotNull($openOnly);
        $this->assertSame(1, $openOnly->total_task_count);
        $this->assertSame(0, $openOnly->completed_count);
        $this->assertSame(0, $openOnly->completion_rate);
        $this->assertNull($openOnly->on_time_rate);

        $empty = $performance->firstWhere('id', $noTasks->id);
        $this->assertNotNull($empty);
        $this->assertSame(0, $empty->total_task_count);
        $this->assertNull($empty->completion_rate);
        $this->assertNull($empty->on_time_rate);
    }

    public function test_team_performance_uses_the_current_assignee_from_actual_task_rows(): void
    {
        $viewer = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $firstAssignee = User::factory()->create(['is_active' => true, 'name' => 'First Assignee']);
        $secondAssignee = User::factory()->create(['is_active' => true, 'name' => 'Second Assignee']);
        $this->addToCurrentWorkspace($firstAssignee);
        $this->addToCurrentWorkspace($secondAssignee);
        [, $job, $phase] = $this->orderFixture();

        $task = Task::create([
            'task_number' => 'TEAM-CREDIT-LOCK',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $firstAssignee->id,
            'title' => 'Historical completion credit',
            'status' => 'Completed',
            'completed_at' => now(),
        ]);

        $this->assertSame($firstAssignee->id, (int) $task->assignee_at_completion);
        $this->assertNotNull($task->assignee_assigned_at_completion);

        $task->update(['assignee_id' => $secondAssignee->id]);
        $task->refresh();
        $this->assertSame($firstAssignee->id, (int) $task->assignee_at_completion);

        $performance = app(DashboardService::class)->assigneePerformance($viewer, period: 'this_week');
        $this->assertSame(0, $performance->firstWhere('id', $firstAssignee->id)->total_task_count);
        $this->assertSame(1, $performance->firstWhere('id', $secondAssignee->id)->completed_count);

        $task->update(['status' => 'In Progress', 'completed_at' => null]);
        $task->refresh();
        $this->assertNull($task->assignee_at_completion);
        $this->assertNull($task->assignee_assigned_at_completion);

        $reopenedPerformance = app(DashboardService::class)->assigneePerformance($viewer, period: 'this_week');
        $this->assertSame(0, $reopenedPerformance->firstWhere('id', $firstAssignee->id)->total_task_count);
        $this->assertSame(1, $reopenedPerformance->firstWhere('id', $secondAssignee->id)->open_count);
        $this->assertSame(0, $reopenedPerformance->firstWhere('id', $secondAssignee->id)->completion_rate);
    }

    public function test_team_card_uses_explicit_completion_rate_and_period_controls(): void
    {
        $dashboardView = file_get_contents(resource_path('views/livewire/dashboard/index.blade.php'));
        $cardView = file_get_contents(resource_path('views/components/dashboard/team-performance-card.blade.php'));
        $reportView = file_get_contents(resource_path('views/livewire/team-performance/report.blade.php'));
        $view = $dashboardView.$cardView.$reportView;
        $service = file_get_contents(app_path('Services/LegacyDashboardService.php'));

        $this->assertStringContainsString('Completion rate', $view);
        $this->assertStringContainsString('Total tasks', $view);
        $this->assertStringContainsString('Open tasks', $view);
        $this->assertStringContainsString('Completed tasks', $view);
        $this->assertStringContainsString('Last 30 days', $view);
        $this->assertStringContainsString('Custom range', $view);
        $this->assertStringContainsString('Team Performance Report', $reportView);
        $this->assertStringContainsString('View all', $dashboardView);
        $this->assertStringContainsString('loadMoreTeamPerformance', $reportView);
        $this->assertStringContainsString('hasMoreTeamPerformance', $reportView);
        $this->assertStringContainsString('has-department-color', $cardView);
        $this->assertStringNotContainsString('score / 100', strtolower($view));
        $this->assertStringContainsString("'completion_rate'", $service);
        $this->assertStringContainsString("'on_time_rate'", $service);
    }


    public function test_team_performance_excludes_active_users_without_current_workspace_membership(): void
    {
        $viewer = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $realUser = User::factory()->create(['is_active' => true, 'name' => 'Real Workspace User']);
        $legacyDemoUser = User::factory()->create(['is_active' => true, 'name' => 'Legacy Demo User']);
        $this->addToCurrentWorkspace($realUser);

        [, $job, $phase] = $this->orderFixture();
        Task::create([
            'task_number' => 'TEAM-REAL-USER',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $realUser->id,
            'title' => 'Real user task',
            'status' => 'Ready',
        ]);
        Task::create([
            'task_number' => 'TEAM-DEMO-USER',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $legacyDemoUser->id,
            'title' => 'Legacy demo task',
            'status' => 'Completed',
            'completed_at' => now(),
        ]);

        $performance = app(DashboardService::class)->assigneePerformance($viewer, period: 'this_week');

        $this->assertNotNull($performance->firstWhere('id', $realUser->id));
        $this->assertNull($performance->firstWhere('id', $legacyDemoUser->id));
    }

    private function addToCurrentWorkspace(User $user): void
    {
        WorkspaceMembership::updateOrCreate(
            [
                'workspace_id' => app(SetupContext::class)->workspaceId(),
                'user_id' => $user->id,
            ],
            [
                'role_id' => $user->role_id,
                'department_id' => $user->department_id,
                'status' => 'active',
                'joined_at' => now(),
            ],
        );
    }

    private function orderFixture(): array
    {
        $client = Client::create(['name' => 'Team Rate Client '.uniqid(), 'code' => 'TR'.uniqid(), 'is_active' => true]);
        $workflow = Workflow::create(['name' => 'Team Rate Workflow '.uniqid(), 'slug' => 'team-rate-'.uniqid(), 'is_active' => true]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Artwork',
            'short_name' => 'Artwork',
            'allow_job_start' => true,
            'is_active' => true,
        ]);
        $job = FlowJob::create([
            'job_number' => 'ORDER-TEAM-'.uniqid(),
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'title' => 'Team rate order',
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
        ]);

        return [$client, $job, $phase];
    }
}
