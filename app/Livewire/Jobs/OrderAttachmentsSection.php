<?php

namespace App\Livewire\Jobs;

use App\Models\Document;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\DocumentService;
use App\Support\AttachmentUpload;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Isolated Order Details general attachments section.
 *
 * The previous parent request hydrated the full Jobs coordinator and, on
 * upload/delete, could also use the full Order detail graph. This component
 * keeps the interaction scoped to the Order and its general documents only.
 */
final class OrderAttachmentsSection extends Component
{
    use WithFileUploads;

    public int $orderId;
    public bool $ready = false;
    public array $jobDocumentUploads = [];
    public int $documentPage = 1;
    private const DOCUMENTS_PER_PAGE = 20;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function loadAttachmentsSection(string $section, ?string $contextType = null, ?int $contextId = null): void
    {
        if ($section !== 'attachments' || $contextType !== 'order' || (int) $contextId !== $this->orderId) {
            return;
        }

        $this->ready = true;
    }

    public function loadMoreOrderDocuments(): void
    {
        if (! $this->ready) {
            return;
        }

        // One user action extends the existing bounded list by one page.
        // The real count and authorization are checked again in render().
        $this->documentPage = min(1000, max(1, $this->documentPage) + 1);
    }

    public function updatedJobDocumentUploads(): void
    {
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*']);
    }

    public function removeJobDocumentUpload(int $index): void
    {
        if (! array_key_exists($index, $this->jobDocumentUploads)) {
            return;
        }

        unset($this->jobDocumentUploads[$index]);
        $this->jobDocumentUploads = array_values($this->jobDocumentUploads);
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*']);
    }

    public function clearJobDocumentUploads(): void
    {
        $this->jobDocumentUploads = [];
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*']);
    }

    public function uploadGeneralOrderDocuments(): array
    {
        abort_unless(auth()->user()->canModule('documents', 'create'), 403);
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*']);

        $validator = validator(['jobDocumentUploads' => $this->jobDocumentUploads], [
            'jobDocumentUploads' => ['required', 'array', 'min:1'],
            'jobDocumentUploads.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS, 20480),
        ], [
            'jobDocumentUploads.required' => 'Choose at least one file to upload.',
            'jobDocumentUploads.*.max' => 'The file is too large. Maximum file size is 20 MB.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }

            return ['ok' => false, 'message' => $validator->errors()->first()];
        }

        $job = app(VisibleOrderQuery::class)->base(auth()->user(), $this->orderId);

        try {
            foreach ($this->jobDocumentUploads as $upload) {
                app(DocumentService::class)->store($upload, [
                    'flow_job_id' => $job->id,
                    'client_id' => $job->client_id,
                    'task_id' => null,
                ], auth()->user());
            }
        } catch (Throwable $exception) {
            report($exception);
            $message = 'FlowTrack could not store this attachment. Please try again.';
            $this->addError('jobDocumentUploads', $message);

            return ['ok' => false, 'message' => $message];
        }

        $this->jobDocumentUploads = [];
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*']);
        session()->flash('success', 'Other document uploaded.');

        return ['ok' => true];
    }

    public function deleteJobDocument(int $documentId): void
    {
        abort_unless(auth()->user()->canModule('documents', 'delete'), 403);
        $job = app(VisibleOrderQuery::class)->base(auth()->user(), $this->orderId);
        $document = Document::query()
            ->where('flow_job_id', $job->id)
            ->whereNull('task_id')
            ->findOrFail($documentId);

        app(DocumentService::class)->delete($document, auth()->user());
    }

    #[On('flowtrack-order-changed')]
    public function refreshTargetedOrder(int $orderId): void
    {
        // The client-side order ID is only a refresh hint. Each render keeps
        // its existing server-side VisibleOrderQuery/access-control scope.
        if ($orderId !== $this->orderId || ! $this->ready) {
            $this->skipRender();
        }
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        // Refresh only the document list.
    }

    public function render()
    {
        if (! $this->ready) {
            return view('livewire.jobs.order-attachments-section', [
                'job' => null,
                'context' => [],
            ]);
        }

        $user = auth()->user();
        $query = app(VisibleOrderQuery::class);
        $job = $query->base($user, $this->orderId);
        // The paginated reader can be independently rolled back while the
        // original authorized document loader remains available for parity.
        // The stored relation keeps the exact existing Blade contract.
        if ((bool) config('flowtrack.order_attachment_pagination_enabled', false)) {
            $documentsQuery = $job->documents()->whereNull('task_id');
            $documentTotal = (clone $documentsQuery)->count();
            $documentLimit = min($documentTotal, min(1000, max(1, $this->documentPage)) * self::DOCUMENTS_PER_PAGE);
            $documents = $documentsQuery
                ->with('uploader:id,name,profile_image_path')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($documentLimit)
                ->get();
            $job->setRelation('documents', $documents);
        } else {
            $query->loadOverviewDocuments($job);
            $documents = $job->documents->filter(static fn (Document $document): bool => blank($document->task_id));
            $documentTotal = $documents->count();
        }

        $access = app(AccessControlService::class);
        $context = [
            'canUploadDocument' => $access->can($user, 'documents', 'create'),
            'canDeleteDocument' => $access->can($user, 'documents', 'delete'),
            'canExportDocument' => $access->can($user, 'documents', 'export'),
            'attachmentTotal' => $documentTotal,
            'attachmentHasMore' => $documentTotal > $documents->count(),
        ];

        return view('livewire.jobs.order-attachments-section', compact('job', 'context'));
    }
}
