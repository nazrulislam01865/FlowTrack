<?php

namespace Tests\Feature;

use App\Actions\Inquiries\UploadInquiryDocument;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InquiryRequiredDocumentAutoCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_upload_completes_only_an_open_task_that_requires_a_document(): void
    {
        Storage::fake('flowtrack_private');
        Storage::fake('flowtrack_quarantine');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.quarantine_disk', 'flowtrack_quarantine');
        config()->set('flowtrack.upload_security.scanner', 'basic');

        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        $client = Client::create([
            'name' => 'Required Document Client',
            'code' => 'REQ-DOC',
            'is_active' => true,
        ]);
        $inquiry = Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-AUTO-DOC-001',
            'client_id' => $client->id,
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'received_date' => now()->toDateString(),
            'subject' => 'Required document completion',
            'status' => 'In Progress',
        ]);

        $requiredTask = InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $user->id,
            'title' => 'Upload required specification',
            'sequence' => 1,
            'status' => 'In Progress',
            'requires_submission' => true,
            'started_at' => now(),
        ]);
        $optionalTask = InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $user->id,
            'title' => 'Optional supporting file',
            'sequence' => 2,
            'status' => 'Waiting',
            'requires_submission' => false,
        ]);

        app(UploadInquiryDocument::class)->handle(
            $inquiry,
            UploadedFile::fake()->createWithContent('required-spec.pdf', "%PDF-1.7\nRequired specification\n"),
            $user,
            $requiredTask,
        );
        app(UploadInquiryDocument::class)->handle(
            $inquiry->fresh(),
            UploadedFile::fake()->createWithContent('optional-note.pdf', "%PDF-1.7\nOptional note\n"),
            $user,
            $optionalTask,
        );

        $requiredTask->refresh();
        $optionalTask->refresh();

        $this->assertNotNull($requiredTask->completed_at);
        $this->assertSame('Completed', $requiredTask->status);
        $this->assertDatabaseHas('inquiry_documents', [
            'inquiry_task_id' => $requiredTask->id,
            'name' => 'required-spec.pdf',
        ]);
        $this->assertNull($optionalTask->completed_at);
        $this->assertSame('Waiting', $optionalTask->status);
    }
}
