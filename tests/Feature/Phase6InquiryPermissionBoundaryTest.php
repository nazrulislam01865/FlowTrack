<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\User;
use App\Services\AccessControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase6InquiryPermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_inquiry_task_permission_remains_allowed_for_creator_and_denied_for_outsider(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        $assignee = User::factory()->create(['is_active' => true]);
        $outsider = User::factory()->create(['is_active' => true]);
        $client = Client::create(['name' => 'Phase 6 Client', 'code' => 'P6C', 'is_active' => true]);

        $inquiry = Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-P6-PERM-1',
            'client_id' => $client->id,
            'owner_id' => $assignee->id,
            'created_by' => $creator->id,
            'received_date' => today(),
            'subject' => 'Phase 6 permission boundary',
            'status' => 'In Progress',
        ]);
        $task = InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $assignee->id,
            'title' => 'Permission boundary task',
            'sequence' => 1,
            'status' => 'Ready',
        ]);

        $access = app(AccessControlService::class);

        $this->assertTrue($access->canEditInquiryTask($creator, $task));
        $this->assertTrue($access->canAssignInquiryTask($creator, $task));
        $this->assertFalse($access->canEditInquiryTask($outsider, $task));
        $this->assertFalse($access->canAssignInquiryTask($outsider, $task));
    }

    public function test_conversion_and_document_permissions_have_allowed_and_denied_actor_cases(): void
    {
        $administrator = User::factory()->create(['is_active' => true, 'is_super_admin' => true]);
        $regularUser = User::factory()->create(['is_active' => true, 'is_super_admin' => false]);
        $access = app(AccessControlService::class);

        $this->assertTrue($access->can($administrator, 'jobs', 'create'));
        $this->assertFalse($access->can($regularUser, 'jobs', 'create'));

        foreach (['create', 'link', 'delete'] as $action) {
            $this->assertTrue($access->can($administrator, 'documents', $action));
            $this->assertFalse($access->can($regularUser, 'documents', $action));
        }
    }

    public function test_phase6_actions_keep_conversion_and_document_authorization_in_inquiry_service(): void
    {
        $conversionAction = file_get_contents(app_path('Actions/Inquiries/ConvertInquiryToOrder.php'));
        $uploadAction = file_get_contents(app_path('Actions/Inquiries/UploadInquiryDocument.php'));
        $removeAction = file_get_contents(app_path('Actions/Inquiries/RemoveInquiryDocument.php'));
        $service = $this->inquiryServiceSource();

        $this->assertStringContainsString('$this->inquiries->convertToOrder($inquiry, $actor)', $conversionAction);
        $this->assertStringContainsString('$this->inquiries->upload($inquiry, $file, $actor, $task, $note)', $uploadAction);
        $this->assertStringContainsString('$this->inquiries->removeDocument($inquiry, $documentId, $actor)', $removeAction);

        $this->assertStringContainsString('abort_unless($this->canEdit($actor, $inquiry), 403);', $service);
        $this->assertStringContainsString("can(\$actor, 'jobs', 'create')", $service);
        $this->assertStringContainsString("can(\$actor, 'documents', 'create')", $service);
        $this->assertStringContainsString("can(\$actor, 'documents', 'link')", $service);
        $this->assertStringContainsString("can(\$actor, 'documents', 'delete')", $service);
        $this->assertStringContainsString('$this->canEditTask($actor, $task)', $service);
    }
}
