<?php

namespace Tests\Feature;

use App\Actions\Inquiries\CompleteInquiryTask;
use App\Actions\Inquiries\UploadInquiryDocument;
use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\InquiryTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InquiryTaskMultiDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_task_can_receive_a_batch_before_completion(): void
    {
        $this->fakeDocumentStorage();
        [$user, $inquiry] = $this->makeInquiry();

        $task = InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $user->id,
            'title' => 'Upload quotation pack',
            'sequence' => 1,
            'status' => 'In Progress',
            'requires_submission' => true,
            'started_at' => now(),
        ]);

        foreach (['quotation.pdf', 'cost-breakdown.pdf', 'supplier-terms.pdf'] as $name) {
            app(UploadInquiryDocument::class)->handle(
                $inquiry,
                UploadedFile::fake()->createWithContent($name, "%PDF-1.7\n{$name}\n"),
                $user,
                $task,
                'Quotation pack',
                false,
            );
        }

        $task->refresh();
        $this->assertNull($task->completed_at);
        $this->assertSame(3, $task->documents()->count());

        app(CompleteInquiryTask::class)->handle($task, $user);

        $task->refresh();
        $this->assertNotNull($task->completed_at);
        $this->assertSame('Completed', $task->status);
    }

    public function test_task_rejects_an_eleventh_document(): void
    {
        $this->fakeDocumentStorage();
        [$user, $inquiry] = $this->makeInquiry();

        $task = InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $user->id,
            'title' => 'Supporting documents',
            'sequence' => 1,
            'status' => 'In Progress',
            'requires_submission' => false,
            'started_at' => now(),
        ]);

        for ($index = 1; $index <= InquiryTask::MAX_DOCUMENTS; $index++) {
            InquiryDocument::create([
                'inquiry_id' => $inquiry->id,
                'inquiry_task_id' => $task->id,
                'uploaded_by' => $user->id,
                'name' => 'existing-'.$index.'.pdf',
                'path' => 'flowtrack/inquiries/'.$inquiry->id.'/existing-'.$index.'.pdf',
                'mime_type' => 'application/pdf',
                'size' => 128,
            ]);
        }

        try {
            app(UploadInquiryDocument::class)->handle(
                $inquiry,
                UploadedFile::fake()->createWithContent('eleventh.pdf', "%PDF-1.7\nEleventh\n"),
                $user,
                $task,
            );
            $this->fail('The eleventh task document should have been rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertStringContainsString('maximum of 10 documents', strtolower($exception->getMessage()));
        }

        $this->assertSame(InquiryTask::MAX_DOCUMENTS, $task->documents()->count());
    }

    private function fakeDocumentStorage(): void
    {
        Storage::fake('flowtrack_private');
        Storage::fake('flowtrack_quarantine');
        config()->set('flowtrack.document_disk', 'flowtrack_private');
        config()->set('flowtrack.quarantine_disk', 'flowtrack_quarantine');
        config()->set('flowtrack.upload_security.scanner', 'basic');
    }

    /** @return array{0:User,1:Inquiry} */
    private function makeInquiry(): array
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        $client = Client::create([
            'name' => 'Multi-document Client',
            'code' => 'MULTI-DOC',
            'is_active' => true,
        ]);

        $inquiry = Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-MULTI-DOC-001',
            'client_id' => $client->id,
            'owner_id' => $user->id,
            'created_by' => $user->id,
            'received_date' => now()->toDateString(),
            'subject' => 'Multi-document Inquiry task',
            'status' => 'In Progress',
        ]);

        return [$user, $inquiry];
    }
}
