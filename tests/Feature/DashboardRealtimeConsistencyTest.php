<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\FlowNotification;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\DashboardService;
use App\Services\NotificationService;
use App\Services\WorkspaceRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardRealtimeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_task_mentions_and_notifications_disappear_when_parent_order_is_deleted(): void
    {
        Cache::flush();
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        [$client, $workflow, $phase] = $this->jobSetup();

        $job = FlowJob::create([
            'job_number' => 'JOB-REALTIME-001',
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'title' => 'Realtime order',
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
        ]);
        $task = Task::create([
            'task_number' => 'TSK-REALTIME-001',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'title' => 'Mentioned task',
            'status' => 'In Progress',
            'priority' => 'Medium',
        ]);
        $notification = FlowNotification::create([
            'user_id' => $user->id,
            'flow_job_id' => $job->id,
            'flow_task_id' => $task->id,
            'type' => 'mention',
            'title' => 'Tagged in task',
            'message' => 'Please review @user',
        ]);

        $this->assertSame(1, app(NotificationService::class)->visibleQuery($user)->count());
        $this->assertSame(1, app(DashboardService::class)->mentions($user)->count());

        $job->delete();

        $this->assertSame(0, app(NotificationService::class)->visibleQuery($user)->count());
        $this->assertSame(0, app(DashboardService::class)->mentions($user)->count());

        $this->actingAs($user)
            ->get(route('notifications.open', ['notification' => $notification->id]))
            ->assertRedirect(route('notifications'))
            ->assertSessionHas('warning');
    }

    public function test_inquiry_task_mentions_and_notifications_disappear_when_parent_inquiry_is_deleted(): void
    {
        Cache::flush();
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create(['name' => 'Inquiry Client', 'code' => 'INQ-CLIENT', 'is_active' => true]);
        $inquiry = Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-REALTIME-001',
            'client_id' => $client->id,
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'received_date' => today(),
            'subject' => 'Realtime inquiry',
            'status' => 'In Progress',
        ]);
        $task = InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $user->id,
            'title' => 'Inquiry follow-up',
            'sequence' => 1,
            'status' => 'In Progress',
        ]);
        FlowNotification::create([
            'user_id' => $user->id,
            'inquiry_id' => $inquiry->id,
            'inquiry_task_id' => $task->id,
            'type' => 'mention',
            'title' => 'Tagged in inquiry task',
            'message' => 'Please check this inquiry',
        ]);

        $this->assertSame(1, app(NotificationService::class)->visibleQuery($user)->count());
        $this->assertSame(1, app(DashboardService::class)->mentions($user)->count());

        $inquiry->delete();

        $this->assertSame(0, app(NotificationService::class)->visibleQuery($user)->count());
        $this->assertSame(0, app(DashboardService::class)->mentions($user)->count());
    }

    public function test_dashboard_workspace_version_changes_and_client_inquiry_count_is_real_data(): void
    {
        Cache::flush();
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create(['name' => 'Portfolio Client', 'code' => 'PORTFOLIO', 'is_active' => true]);

        $before = app(WorkspaceRefreshService::class)->version();
        $client->update(['name' => 'Portfolio Client Updated']);
        $after = app(WorkspaceRefreshService::class)->version();
        $this->assertNotSame($before, $after);

        Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-PORTFOLIO-001',
            'client_id' => $client->id,
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'received_date' => today(),
            'subject' => 'Portfolio inquiry',
            'status' => 'In Progress',
        ]);

        $portfolioClient = app(DashboardService::class)->clientPortfolio($user)->firstWhere('id', $client->id);
        $this->assertNotNull($portfolioClient);
        $this->assertSame(1, (int) $portfolioClient->open_inquiries_count);
    }

    private function jobSetup(): array
    {
        $client = Client::create(['name' => 'Realtime Client', 'code' => 'REALTIME', 'is_active' => true]);
        $workflow = Workflow::create(['name' => 'Realtime Workflow', 'slug' => 'realtime-workflow', 'is_active' => true]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Production',
            'short_name' => 'Production',
            'allow_job_start' => true,
            'is_active' => true,
        ]);

        return [$client, $workflow, $phase];
    }
}
