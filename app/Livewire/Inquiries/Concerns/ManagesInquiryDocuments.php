<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\Document;
use App\Services\AccessControlService;
use App\Support\AttachmentUpload;
use Illuminate\Validation\Rule;
use Throwable;

trait ManagesInquiryDocuments
{
    public function openTaskDocumentModal(int $taskId): void
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId, ['inquiry']);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 404);
        abort_unless(app(\App\Queries\Inquiries\InquiryDetailQuery::class)->canEditTask(auth()->user(), $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot receive documents.');
        $canCreateDocument = auth()->user()->canModule('documents', 'create');
        abort_unless($canCreateDocument, 403, 'Your role cannot upload documents.');
        abort_if(
            $task->documents()->count() >= InquiryTask::MAX_DOCUMENTS,
            422,
            'A maximum of '.InquiryTask::MAX_DOCUMENTS.' documents can be attached to one Inquiry task.'
        );

        $this->pendingCompletionTaskId = null;
        $this->resetTaskDocumentModal();
        $this->taskDocumentSource = 'upload';
        $this->taskDocumentModalTaskId = $taskId;
        $this->showTaskDocumentModal = true;
    }

    public function requestTaskCompletionFile(int $taskId): void
    {
        $detailQuery = app(\App\Queries\Inquiries\InquiryDetailQuery::class);
        $task = $detailQuery->task(auth()->user(), $taskId, ['inquiry']);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 404);
        abort_unless($detailQuery->canEditTask(auth()->user(), $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot change status.');

        // If a required submission was added by another request between renders,
        // complete immediately instead of opening an unnecessary modal.
        if (! $task->requires_submission || app(\App\Queries\Inquiries\InquiryWorkflowQuery::class)->taskHasSubmissionEvidence($task)) {
            app(\App\Actions\Inquiries\CompleteInquiryTask::class)->handle($task, auth()->user());
            $this->refreshInquiryListMetrics();
            return;
        }

        $canCreateDocument = auth()->user()->canModule('documents', 'create');
        abort_unless($canCreateDocument, 403, 'A required file is missing and your role cannot upload documents.');
        $this->pendingCompletionTaskId = $taskId;
        $this->resetTaskDocumentModal();
        $this->taskDocumentSource = 'upload';
        $this->taskDocumentModalTaskId = $taskId;
        $this->showTaskDocumentModal = true;
    }

    public function closeTaskDocumentModal(): void
    {
        $this->showTaskDocumentModal = false;
        $this->pendingCompletionTaskId = null;
        $this->resetTaskDocumentModal();
        $this->resetValidation([
            'taskDocumentUploads',
            'taskDocumentUploads.*',
            'taskExistingDocumentId',
            'taskDocumentNote',
        ]);
    }

    public function setTaskDocumentSource(string $source): void
    {
        abort_unless(in_array($source, ['upload', 'existing'], true), 422);
        if ($source === 'upload') {
            abort_unless(auth()->user()->canModule('documents', 'create'), 403);
        } else {
            abort_unless(auth()->user()->canModule('documents', 'link'), 403);
        }

        $this->taskDocumentSource = $source;
        $this->taskDocumentUploads = [];
        $this->taskExistingDocumentId = null;
        $this->resetValidation([
            'taskDocumentUploads',
            'taskDocumentUploads.*',
            'taskExistingDocumentId',
        ]);
    }

    public function updatedTaskDocumentUploads(): void
    {
        if (! $this->taskDocumentModalTaskId || $this->taskDocumentUploads === []) {
            return;
        }

        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)
            ->task(auth()->user(), (int) $this->taskDocumentModalTaskId);
        $remaining = max(0, InquiryTask::MAX_DOCUMENTS - $task->documents()->count());

        $this->resetValidation(['taskDocumentUploads', 'taskDocumentUploads.*']);
        if (count($this->taskDocumentUploads) <= $remaining) {
            return;
        }

        $this->taskDocumentUploads = array_values(array_slice($this->taskDocumentUploads, 0, $remaining));
        $message = $remaining > 0
            ? 'A maximum of '.InquiryTask::MAX_DOCUMENTS.' documents can be attached to one Inquiry task. Only the first '.$remaining.' available slot'.($remaining === 1 ? '' : 's').' were kept.'
            : 'This Inquiry task already has the maximum of '.InquiryTask::MAX_DOCUMENTS.' documents.';
        $this->addError('taskDocumentUploads', $message);
    }

    public function removeTaskDocumentUpload(int $index): void
    {
        if (! array_key_exists($index, $this->taskDocumentUploads)) {
            return;
        }

        unset($this->taskDocumentUploads[$index]);
        $this->taskDocumentUploads = array_values($this->taskDocumentUploads);
        $this->resetValidation(['taskDocumentUploads', 'taskDocumentUploads.*']);
    }

    public function saveTaskDocument(): void
    {
        abort_unless($this->taskDocumentModalTaskId, 422);
        $detailQuery = app(\App\Queries\Inquiries\InquiryDetailQuery::class);
        $task = $detailQuery->task(auth()->user(), (int) $this->taskDocumentModalTaskId, ['inquiry']);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 404);
        abort_unless($detailQuery->canEditTask(auth()->user(), $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot receive documents.');

        $this->validate([
            'taskDocumentSource' => ['required', Rule::in(['upload', 'existing'])],
            'taskDocumentNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $note = trim($this->taskDocumentNote);
        $note = $note !== '' ? $note : null;
        $shouldCompleteAfterDocument = (bool) $task->requires_submission && ! $task->completed_at;
        $documentsAdded = 0;

        if ($this->taskDocumentSource === 'upload') {
            abort_unless(auth()->user()->canModule('documents', 'create'), 403);

            $existingCount = $task->documents()->count();
            $remaining = InquiryTask::MAX_DOCUMENTS - $existingCount;
            if ($remaining <= 0) {
                $this->addError('taskDocumentUploads', 'A maximum of '.InquiryTask::MAX_DOCUMENTS.' documents can be attached to one Inquiry task.');
                return;
            }

            $this->validate([
                'taskDocumentUploads' => ['required', 'array', 'min:1', 'max:'.$remaining],
                'taskDocumentUploads.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, 20480),
            ], [
                'taskDocumentUploads.required' => 'Choose at least one file to upload.',
                'taskDocumentUploads.max' => 'You can add only '.$remaining.' more document'.($remaining === 1 ? '' : 's').' to this task ('.InquiryTask::MAX_DOCUMENTS.' maximum).',
                'taskDocumentUploads.*.max' => 'Each file must be 20 MB or smaller.',
            ]);

            $uploads = array_values($this->taskDocumentUploads);
            foreach ($uploads as $index => $upload) {
                try {
                    app(\App\Actions\Inquiries\UploadInquiryDocument::class)->handle(
                        $task->inquiry,
                        $upload,
                        auth()->user(),
                        $task,
                        $note,
                        false,
                    );
                    $documentsAdded++;
                } catch (Throwable $exception) {
                    report($exception);
                    // Keep only the files that still need to be retried. Any files
                    // stored before the failure remain attached, while the task
                    // stays open until the full selected batch succeeds.
                    $this->taskDocumentUploads = array_values(array_slice($uploads, $index));
                    $message = $documentsAdded > 0
                        ? $documentsAdded.' document'.($documentsAdded === 1 ? '' : 's').' uploaded, but the next file could not be stored. Retry the remaining files.'
                        : 'FlowTrack could not store the selected task documents. Please try again.';
                    $this->addError('taskDocumentUploads', $message);
                    return;
                }
            }
        } else {
            abort_unless(auth()->user()->canModule('documents', 'link'), 403);
            if ($task->documents()->count() >= InquiryTask::MAX_DOCUMENTS) {
                $this->addError('taskExistingDocumentId', 'A maximum of '.InquiryTask::MAX_DOCUMENTS.' documents can be attached to one Inquiry task.');
                return;
            }
            $this->validate(['taskExistingDocumentId' => ['required', 'integer', 'exists:documents,id']]);
            $source = app(AccessControlService::class)
                ->applyDocumentScope(Document::query()->whereKey((int) $this->taskExistingDocumentId), auth()->user())
                ->firstOrFail();
            app(\App\Actions\Inquiries\LinkExistingInquiryTaskDocument::class)->handle($task, $source, auth()->user(), $note);
            $documentsAdded = 1;
        }

        $task = $task->fresh();
        if ($shouldCompleteAfterDocument && ! $task->completed_at) {
            // Complete only after the full batch has been stored. This avoids a
            // required-document task becoming Completed after the first file of
            // a multi-file selection when a later file fails.
            $task = app(\App\Actions\Inquiries\CompleteInquiryTask::class)->handle($task, auth()->user());
        }
        $completedAfterDocument = $shouldCompleteAfterDocument && $task->completed_at !== null;
        if ($completedAfterDocument) {
            $this->refreshInquiryListMetrics();
        }

        $this->showTaskDocumentModal = false;
        $this->pendingCompletionTaskId = null;
        $this->resetTaskDocumentModal();

        $documentLabel = $documentsAdded === 1 ? 'Document' : $documentsAdded.' documents';
        session()->flash('success', $completedAfterDocument
            ? $documentLabel.' added and '.$task->title.' completed.'
            : $documentLabel.' added to '.$task->title.'.');
    }

    public function uploadTaskFile(): void
    {
        $this->validate(['taskUpload' => AttachmentUpload::requiredRules(AttachmentUpload::DOCUMENTS_WITH_AI, 20480)]);
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), (int) $this->selectedTaskId, ['inquiry']);
        $shouldCompleteAfterDocument = (bool) $task->requires_submission && ! $task->completed_at;
        app(\App\Actions\Inquiries\UploadInquiryDocument::class)->handle($task->inquiry, $this->taskUpload, auth()->user(), $task);
        if ($shouldCompleteAfterDocument) {
            $this->refreshInquiryListMetrics();
        }
        $this->taskUpload = null;
    }

    public function uploadQuickTaskFile(int $taskId): void
    {
        $upload = $this->taskQuickUploads[$taskId] ?? null;
        if (!$upload) return;
        $this->validate(["taskQuickUploads.$taskId" => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, 20480)]);
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId, ['inquiry']);
        $shouldCompleteAfterDocument = (bool) $task->requires_submission && ! $task->completed_at;
        app(\App\Actions\Inquiries\UploadInquiryDocument::class)->handle($task->inquiry, $upload, auth()->user(), $task);
        if ($shouldCompleteAfterDocument) {
            $this->refreshInquiryListMetrics();
        }
        unset($this->taskQuickUploads[$taskId]);
    }

    public function updatedTaskQuickUploads($value, $key): void
    {
        if (!$value || !is_numeric($key)) return;
        $this->uploadQuickTaskFile((int) $key);
    }

    public function updatedInquiryUploads(): void
    {
        if (count($this->inquiryUploads) === 0) {
            return;
        }

        // New upload and stored-document linking are mutually exclusive. Do not
        // persist from this lifecycle hook: the browser calls uploadInquiryFiles()
        // after Livewire reports that the temporary upload has actually finished.
        $this->showInquiryDocumentPicker = false;
        $this->inquiryExistingDocumentId = null;
        $this->resetValidation(['inquiryExistingDocumentId']);
    }

    public function toggleInquiryDocumentPicker(): void
    {
        abort_unless(auth()->user()->canModule('documents', 'link'), 403);

        $opening = ! $this->showInquiryDocumentPicker;
        if ($opening) {
            // Existing-document mode replaces Upload new, so clear any pending
            // temporary files instead of showing both link actions together.
            $this->inquiryUploads = [];
            $this->resetValidation(['inquiryUploads', 'inquiryUploads.*']);
        } else {
            $this->inquiryExistingDocumentId = null;
            $this->resetValidation(['inquiryExistingDocumentId']);
        }

        $this->showInquiryDocumentPicker = $opening;
    }

    public function attachExistingInquiryDocument(): void
    {
        abort_unless(auth()->user()->canModule('documents', 'link'), 403);
        $this->validate(['inquiryExistingDocumentId' => ['required', 'integer', 'exists:documents,id']]);
        $inquiry = $this->selectedInquiry();
        $source = app(AccessControlService::class)
            ->applyDocumentScope(Document::query()->whereKey((int) $this->inquiryExistingDocumentId), auth()->user())
            ->firstOrFail();
        app(\App\Actions\Inquiries\LinkExistingInquiryDocument::class)->handle($inquiry, $source, auth()->user());
        $this->inquiryUploads = [];
        $this->inquiryExistingDocumentId = null;
        $this->showInquiryDocumentPicker = false;
        $this->resetValidation(['inquiryUploads', 'inquiryUploads.*', 'inquiryExistingDocumentId']);
        session()->flash('success', 'Stored document linked to this Inquiry.');
    }

    public function uploadInquiryFiles(): array
    {
        abort_unless(auth()->user()->canModule('documents', 'create'), 403);
        $this->resetValidation(['inquiryUploads', 'inquiryUploads.*']);
        $validator = validator(['inquiryUploads' => $this->inquiryUploads], [
            'inquiryUploads' => ['required','array','min:1'],
            'inquiryUploads.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, 20480),
        ], [
            'inquiryUploads.required' => 'Choose at least one file to upload.',
            'inquiryUploads.*.max' => 'The file is too large. Maximum file size is 20 MB.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) {
                foreach ($messages as $message) $this->addError($key, $message);
            }
            return ['ok' => false, 'message' => $validator->errors()->first()];
        }

        $inquiry = $this->selectedInquiry();
        try {
            foreach ($this->inquiryUploads as $upload) {
                app(\App\Actions\Inquiries\UploadInquiryDocument::class)->handle($inquiry, $upload, auth()->user());
            }
        } catch (\Throwable $e) {
            report($e);
            $message = 'FlowTrack could not store this Inquiry attachment. Please try again.';
            $this->addError('inquiryUploads', $message);
            return ['ok' => false, 'message' => $message];
        }

        $this->inquiryUploads = [];
        $this->inquiryExistingDocumentId = null;
        $this->showInquiryDocumentPicker = false;
        $this->resetValidation(['inquiryUploads', 'inquiryUploads.*']);
        session()->flash('success', 'Attachment uploaded and linked to this Inquiry.');
        return ['ok' => true];
    }

    public function deleteInquiryDocument(int $documentId): void
    {
        app(\App\Actions\Inquiries\RemoveInquiryDocument::class)->handle($this->selectedInquiry(), $documentId, auth()->user());
        session()->flash('success', 'Inquiry attachment removed.');
    }

    public function openTaskLinkForm(int $taskId): void
    {
        $detailQuery = app(\App\Queries\Inquiries\InquiryDetailQuery::class);
        $task = $detailQuery->task(auth()->user(), $taskId, ['inquiry']);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 404);
        abort_unless($detailQuery->canEditTask(auth()->user(), $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot receive links.');

        $this->taskLinkFormTaskId = $taskId;
        $this->taskLinkUrl = '';
        $this->resetValidation(['taskLinkUrl']);
    }

    public function cancelTaskLinkForm(): void
    {
        $this->taskLinkFormTaskId = null;
        $this->taskLinkUrl = '';
        $this->resetValidation(['taskLinkUrl']);
    }

    public function saveTaskLink(int $taskId): void
    {
        abort_unless((int) $this->taskLinkFormTaskId === $taskId, 422);

        $url = trim($this->taskLinkUrl);
        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $this->taskLinkUrl = $url;

        $this->validate([
            'taskLinkUrl' => ['required', 'string', 'max:2048', 'url'],
        ], [
            'taskLinkUrl.required' => 'Enter a link to add.',
            'taskLinkUrl.url' => 'Enter a valid website or file link.',
            'taskLinkUrl.max' => 'The link is too long.',
        ]);

        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId, ['inquiry']);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 404);
        app(\App\Actions\Inquiries\AddInquiryTaskLink::class)->handle($task, $this->taskLinkUrl, auth()->user());

        $this->taskLinkFormTaskId = null;
        $this->taskLinkUrl = '';
        $this->resetValidation(['taskLinkUrl']);
        session()->flash('success', 'Link added to '.$task->title.'.');
    }

    public function deleteTaskLink(int $taskId, int $linkId): void
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId, ['inquiry']);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 404);
        $reopened = app(\App\Actions\Inquiries\RemoveInquiryTaskLink::class)->handle($task, $linkId, auth()->user());
        $this->refreshInquiryListMetrics();
        session()->flash('success', $reopened
            ? 'Task link removed. The required-submission task was reopened.'
            : 'Task link removed.');
    }

    public function deleteTaskDocument(int $taskId, int $documentId): void
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId, ['inquiry']);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 404);

        $reopened = app(\App\Actions\Inquiries\RemoveInquiryTaskDocument::class)->handle($task, $documentId, auth()->user());
        $this->refreshInquiryListMetrics();
        session()->flash('success', $reopened
            ? 'Task attachment removed. The required-file task was reopened.'
            : 'Task attachment removed.');
    }

    private function resetTaskDocumentModal(): void
    {
        $this->taskDocumentModalTaskId = null;
        $this->taskDocumentSource = 'upload';
        $this->taskDocumentUploads = [];
        $this->taskExistingDocumentId = null;
        $this->taskDocumentNote = '';
    }
}
