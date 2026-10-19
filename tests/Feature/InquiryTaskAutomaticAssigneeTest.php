<?php

namespace Tests\Feature;

use App\Actions\Inquiries\AddInquiryTaskComment;
use App\Actions\Inquiries\CompleteInquiryTask;
use App\Actions\Inquiries\UpdateInquiryTaskAssignee;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InquiryTaskAutomaticAssigneeTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_an_inquiry_task_assigns_it_to_the_person_who_completed_it(): void
    {
        $originalAssignee = User::factory()->create(['name' => 'Original Inquiry Assignee']);
        $actor = User::factory()->create([
            'name' => 'Inquiry Completion Actor',
            'is_super_admin' => true,
        ]);
        [, $task] = $this->inquiryTask($originalAssignee);

        app(CompleteInquiryTask::class)->handle($task, $actor);

        $task->refresh();
        $this->assertSame($actor->id, $task->assignee_id);
        $this->assertNotNull($task->completed_at);
        $this->assertSame($actor->id, $task->assignee_at_completion);

        $assignment = $task->inquiry->activities()
            ->where('event', 'inquiry.task_assignee_auto_assigned')
            ->where('meta->inquiry_task_id', $task->id)
            ->first();

        $this->assertNotNull($assignment);
        $this->assertSame($actor->id, $assignment->user_id);
        $this->assertSame($originalAssignee->id, data_get($assignment->meta, 'old_assignee_id'));
        $this->assertSame($actor->id, data_get($assignment->meta, 'new_assignee_id'));
        $this->assertTrue((bool) data_get($assignment->meta, 'automatic'));
    }

    public function test_task_activity_reassigns_to_actor_but_manual_assignment_remains_explicit_until_work_happens(): void
    {
        $actor = User::factory()->create([
            'name' => 'Inquiry Activity Actor',
            'is_super_admin' => true,
        ]);
        $manualAssignee = User::factory()->create(['name' => 'Manual Inquiry Assignee']);
        [, $task] = $this->inquiryTask();

        app(AddInquiryTaskComment::class)->handle($task, 'I am working on this Inquiry task.', $actor);
        app(AddInquiryTaskComment::class)->handle($task->refresh(), 'Follow-up activity.', $actor);

        $this->assertSame($actor->id, $task->refresh()->assignee_id);
        $this->assertSame(1, $task->inquiry->activities()
            ->where('event', 'inquiry.task_assignee_auto_assigned')
            ->where('meta->inquiry_task_id', $task->id)
            ->count());

        app(UpdateInquiryTaskAssignee::class)->handle($task->refresh(), $manualAssignee->id, $actor);
        $this->assertSame($manualAssignee->id, $task->refresh()->assignee_id);

        app(AddInquiryTaskComment::class)->handle($task->refresh(), 'Taking action again.', $actor);
        $this->assertSame($actor->id, $task->refresh()->assignee_id);
        $this->assertSame(2, $task->inquiry->activities()
            ->where('event', 'inquiry.task_assignee_auto_assigned')
            ->where('meta->inquiry_task_id', $task->id)
            ->count());
    }

    /** @return array{Inquiry, InquiryTask} */
    private function inquiryTask(?User $assignee = null): array
    {
        $owner = User::factory()->create(['is_super_admin' => true]);
        $client = Client::create([
            'name' => 'Inquiry Auto Assignment Client '.uniqid(),
            'code' => 'IAAC'.uniqid(),
            'is_active' => true,
        ]);
        $inquiry = Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-AUTO-'.uniqid(),
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'received_date' => now()->toDateString(),
            'subject' => 'Inquiry automatic task assignment',
            'status' => 'In Progress',
        ]);
        $task = InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $assignee?->id,
            'title' => 'Inquiry automatic assignment task',
            'sequence' => 1,
            'status' => 'Ready',
            'requires_submission' => false,
        ]);

        return [$inquiry, $task];
    }
}
