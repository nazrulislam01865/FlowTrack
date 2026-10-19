<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\AccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorOwnershipAndTaskPackAssigneeTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_creator_can_edit_and_assign_every_task_in_their_order(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        [$client, $workflow, $phase] = $this->orderDependencies();

        $job = FlowJob::create([
            'job_number' => 'ORDER-CREATOR-1',
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'created_by' => $creator->id,
            'title' => 'Creator-owned order',
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
        ]);
        $task = Task::create([
            'task_number' => 'TASK-CREATOR-1',
            'flow_job_id' => $job->id,
            'workflow_phase_id' => $phase->id,
            'assignee_id' => $assignee->id,
            'title' => 'Creator editable task',
            'status' => 'Ready',
            'priority' => 'Medium',
        ]);

        $access = app(AccessControlService::class);
        $this->assertTrue($access->canEditJob($creator, $job));
        $this->assertTrue($access->canEditTask($creator, $task));
        $this->assertTrue($access->canAssignTask($creator, $task));
    }

    public function test_inquiry_creator_can_edit_and_assign_every_task_in_their_inquiry(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $client = Client::create(['name' => 'Inquiry Creator Client', 'code' => 'ICC', 'is_active' => true]);

        $inquiry = Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-CREATOR-1',
            'client_id' => $client->id,
            'owner_id' => $assignee->id,
            'created_by' => $creator->id,
            'received_date' => today(),
            'subject' => 'Creator-owned inquiry',
            'status' => 'In Progress',
        ]);
        $task = InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $assignee->id,
            'title' => 'Creator editable inquiry task',
            'sequence' => 1,
            'status' => 'Ready',
        ]);

        $access = app(AccessControlService::class);
        $this->assertTrue($access->isInquiryCreator($creator, $inquiry));
        $this->assertTrue($access->canEditInquiryTask($creator, $task));
        $this->assertTrue($access->canAssignInquiryTask($creator, $task));
    }

    private function orderDependencies(): array
    {
        $client = Client::create(['name' => 'Creator Client', 'code' => 'CREATOR', 'is_active' => true]);
        $workflow = Workflow::create(['name' => 'Creator Workflow', 'slug' => 'creator-workflow', 'is_active' => true]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Start',
            'short_name' => 'Start',
            'allow_job_start' => true,
            'is_active' => true,
        ]);

        return [$client, $workflow, $phase];
    }
}
