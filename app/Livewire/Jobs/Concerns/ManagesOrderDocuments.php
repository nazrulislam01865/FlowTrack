<?php

namespace App\Livewire\Jobs\Concerns;

use App\Queries\Orders\VisibleOrderQuery;
use App\Models\Document;
use App\Models\Task;
use App\Services\DocumentService;
use App\Support\AttachmentUpload;
use Throwable;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderDocuments
{
    public function updatedJobRequiredDocumentUpload(): void
    {
        // Livewire's JavaScript upload API sets this property after the temporary
        // upload finishes. Permanent storage is deliberately handled by the
        // explicit persistJobRequiredDocumentUpload() action below so reaching
        // 100% can never be confused with a completed document save.
        $this->resetValidation(['jobRequiredDocumentUpload']);
    }

    public function persistJobRequiredDocumentUpload(): array
    {
        $this->resetValidation(['jobRequiredDocumentUpload', 'jobDocumentTaskId']);

        // A late Livewire upload callback must never save a new file after the
        // user has switched to "Choose existing" while that upload was in flight.
        if ($this->showDocumentPicker) {
            $this->jobRequiredDocumentUpload = null;
            return ['ok' => false, 'message' => 'Upload new is no longer the active document source.'];
        }

        if (!$this->jobRequiredDocumentUpload) {
            $message = 'The temporary upload was not received. Please choose the file again.';
            $this->addError('jobRequiredDocumentUpload', $message);
            return ['ok' => false, 'message' => $message];
        }

        if (!$this->selectedJobId) {
            $message = 'Open an Order before uploading a document.';
            $this->addError('jobRequiredDocumentUpload', $message);
            $this->jobRequiredDocumentUpload = null;
            return ['ok' => false, 'message' => $message];
        }

        try {
            $this->validate([
                'jobDocumentTaskId' => ['required','integer','exists:tasks,id'],
                'jobRequiredDocumentUpload' => AttachmentUpload::requiredRules(AttachmentUpload::ORDER_REQUIRED, 20480),
            ], [
                'jobDocumentTaskId.required' => 'Choose a document type before uploading a file.',
                'jobRequiredDocumentUpload.max' => 'The file is too large. The maximum size is 20 MB.',
            ]);

            $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId);
            $task = $job->tasks->firstWhere('id', (int) $this->jobDocumentTaskId);
            if (!$task || !($task->document_category_id || $task->setupTemplate?->document_category_id)) {
                $message = 'Choose a Task Pack document requirement for this Order.';
                $this->addError('jobDocumentTaskId', $message);
                $this->jobRequiredDocumentUpload = null;
                return ['ok' => false, 'message' => $message];
            }

            // The prototype is intentionally a one-document interaction. When a
            // user uploads again while the success state is still showing for the
            // same requirement, the new upload replaces that just-uploaded link.
            // Store the replacement first so a failed upload can never destroy the
            // document that is already attached.
            $replace = null;
            if ($this->lastJobDocumentUploadId && (int) $this->lastJobDocumentTaskId === (int) $task->id) {
                $replace = Document::where('flow_job_id', $job->id)
                    ->where('task_id', $task->id)
                    ->find($this->lastJobDocumentUploadId);
            }

            $document = app(DocumentService::class)->store($this->jobRequiredDocumentUpload, [
                'flow_job_id' => $job->id,
                'client_id' => $job->client_id,
                'task_id' => $task->id,
                'require_task_pack_requirement' => true,
            ], auth()->user());

            if ($replace && (int) $replace->id !== (int) $document->id) {
                app(DocumentService::class)->delete($replace, auth()->user());
            }

            $this->lastJobDocumentUploadId = (int) $document->id;
            $this->lastJobDocumentTaskId = (int) $task->id;
            $this->jobRequiredDocumentUpload = null;
            $this->resetValidation(['jobRequiredDocumentUpload', 'jobDocumentTaskId']);

            return [
                'ok' => true,
                'documentId' => (int) $document->id,
                'name' => (string) $document->name,
            ];
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $message = collect($exception->validator->errors()->all())->first()
                ?: 'The selected document could not be validated.';
            $this->jobRequiredDocumentUpload = null;
            $this->addError('jobRequiredDocumentUpload', $message);
            return ['ok' => false, 'message' => $message];
        } catch (Throwable $exception) {
            report($exception);
            $message = 'The document could not be saved. Please try again.';
            $this->jobRequiredDocumentUpload = null;
            $this->addError('jobRequiredDocumentUpload', $message);
            return ['ok' => false, 'message' => $message];
        }
    }

    public function clearJobRequiredDocumentUpload(): void
    {
        $this->jobRequiredDocumentUpload = null;
        $this->resetValidation(['jobRequiredDocumentUpload']);
    }

    public function removeLastJobDocumentUpload(): void
    {
        abort_unless(auth()->user()->canModule('documents','delete'), 403);
        abort_unless($this->selectedJobId && $this->lastJobDocumentUploadId, 422);

        $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId);
        $document = Document::where('flow_job_id', $job->id)->findOrFail($this->lastJobDocumentUploadId);
        app(DocumentService::class)->delete($document, auth()->user());

        $this->lastJobDocumentUploadId = null;
        $this->lastJobDocumentTaskId = null;
        $this->jobRequiredDocumentUpload = null;
        $this->resetValidation(['jobRequiredDocumentUpload']);
    }

    public function setDocumentUploadMode(string $mode): void
    {
        abort_unless(in_array($mode, ['upload', 'existing'], true), 422);

        if ($mode === 'existing') {
            // Document source modes are mutually exclusive. Any pending new-file
            // selection must be discarded before the existing-document picker is
            // opened, otherwise both actions can remain active at the same time.
            $this->jobDocumentUploads = [];
            $this->jobRequiredDocumentUpload = null;
            $this->showDocumentPicker = true;
        } else {
            $this->existingDocumentId = null;
            $this->showDocumentPicker = false;
        }

        $this->resetValidation([
            'existingDocumentId',
            'jobRequiredDocumentUpload',
            'jobDocumentUploads',
            'jobDocumentUploads.*',
        ]);
    }

    public function updatedJobDocumentUploads(): void
    {
        // Temporary upload completion and permanent document persistence are two
        // separate steps in Livewire. Keep this hook limited to source-state
        // cleanup. The browser calls uploadJobOverviewDocuments() only after the
        // livewire-upload-finish event, which avoids racing the temporary upload.
        if (count($this->jobDocumentUploads) === 0) {
            return;
        }

        $this->showDocumentPicker = false;
        $this->existingDocumentId = null;
        $this->resetValidation(['existingDocumentId']);
    }

    /**
     * Upload files to the Order-level attachment area without forcing them into
     * a Task Pack document requirement. Task-specific evidence continues to use
     * saveOverviewTaskDocument(), where document gates are enforced.
     */
    public function uploadGeneralOrderDocuments(): array
    {
        abort_unless(auth()->user()->canModule('documents', 'create'), 403);
        abort_unless($this->selectedJobId, 422);
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
                foreach ($messages as $message) $this->addError($key, $message);
            }
            return ['ok' => false, 'message' => $validator->errors()->first()];
        }

        $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId);
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

    public function uploadJobOverviewDocuments(): array
    {
        if ($this->selectedJobId && !$this->jobDocumentTaskId) {
            $this->setDefaultDocumentTask();
        }

        if (!$this->jobDocumentTaskId) {
            $message = 'No required document task is available for this Order.';
            $this->addError('jobDocumentUploads', $message);
            return ['ok' => false, 'message' => $message];
        }

        return $this->uploadJobDocuments();
    }

    public function removeJobDocumentUpload(int $index): void
    {
        if (! array_key_exists($index, $this->jobDocumentUploads)) return;

        unset($this->jobDocumentUploads[$index]);
        $this->jobDocumentUploads = array_values($this->jobDocumentUploads);
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*']);
    }

    public function clearJobDocumentUploads(): void
    {
        $this->jobDocumentUploads = [];
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*']);
    }

    public function uploadJobDocuments(): array
    {
        abort_unless(auth()->user()->canModule('documents','create'), 403);
        abort_unless($this->selectedJobId, 422);
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*', 'jobDocumentTaskId']);

        $validator = validator([
            'jobDocumentTaskId' => $this->jobDocumentTaskId,
            'jobDocumentUploads' => $this->jobDocumentUploads,
        ], [
            'jobDocumentTaskId' => ['required','integer','exists:tasks,id'],
            'jobDocumentUploads' => ['required','array','min:1'],
            'jobDocumentUploads.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS, 20480),
        ], [
            'jobDocumentUploads.required' => 'Choose at least one file to upload.',
            'jobDocumentUploads.*.max' => 'The file is too large. Maximum file size is 20 MB.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) {
                foreach ($messages as $message) $this->addError($key, $message);
            }
            return ['ok' => false, 'message' => $validator->errors()->first()];
        }

        $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId);
        $task = $job->tasks->firstWhere('id', (int) $this->jobDocumentTaskId);
        if (! $task || ! ($task->document_category_id || $task->setupTemplate?->document_category_id)) {
            $message = 'Select a Task Pack document requirement for this Order.';
            $this->addError('jobDocumentTaskId', $message);
            return ['ok' => false, 'message' => $message];
        }

        try {
            foreach ($this->jobDocumentUploads as $upload) {
                app(\App\Services\DocumentService::class)->store($upload, [
                    'flow_job_id' => $job->id,
                    'client_id' => $job->client_id,
                    'task_id' => $task->id,
                    'require_task_pack_requirement' => true,
                ], auth()->user());
            }
        } catch (\Throwable $e) {
            report($e);
            $message = 'FlowTrack could not store this document. Please try again.';
            $this->addError('jobDocumentUploads', $message);
            return ['ok' => false, 'message' => $message];
        }

        $this->jobDocumentUploads = [];
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*', 'jobDocumentTaskId']);

        $fresh = app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId);
        $missing = \App\Support\JobDetailPresenter::requiredDocuments($fresh)
            ->first(fn ($requirement) => !$requirement->complete);
        $this->jobDocumentTaskId = $missing?->task?->id ?: $task->id;

        session()->flash('success', 'Document uploaded and linked to '.$task->title.'.');
        return ['ok' => true];
    }

    public function attachExistingDocument(): void
    {
        abort_unless(auth()->user()->canModule('documents','link'), 403);
        abort_unless($this->selectedJobId, 422);
        $this->validate([
            'jobDocumentTaskId' => ['required','integer','exists:tasks,id'],
            'existingDocumentId' => ['required','integer','exists:documents,id'],
        ]);

        $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId);
        $task = $job->tasks->firstWhere('id', (int) $this->jobDocumentTaskId);
        if (! $task || ! ($task->document_category_id || $task->setupTemplate?->document_category_id)) {
            $this->addError('jobDocumentTaskId', 'Select a Task Pack document requirement for this Order.');
            return;
        }
        $source = Document::findOrFail((int) $this->existingDocumentId);
        if ((int) $source->client_id !== (int) $job->client_id) {
            $this->addError('existingDocumentId', 'The selected document does not belong to this client.');
            return;
        }
        $linked = app(\App\Services\DocumentService::class)->linkExisting($source, $task, auth()->user());
        $this->lastJobDocumentUploadId = (int) $linked->id;
        $this->lastJobDocumentTaskId = (int) $task->id;
        $this->jobDocumentUploads = [];
        $this->jobRequiredDocumentUpload = null;
        $this->existingDocumentId = null;
        $this->showDocumentPicker = false;
        $this->resetValidation(['jobDocumentUploads', 'jobDocumentUploads.*', 'jobRequiredDocumentUpload', 'existingDocumentId']);
        session()->flash('success', 'Existing document linked to the selected Task Pack task.');
    }

    public function deleteJobDocument(int $documentId): void
    {
        abort_unless(auth()->user()->canModule('documents','delete'), 403);
        abort_unless($this->selectedJobId, 422);
        $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId);
        $document = Document::where('flow_job_id', $job->id)->findOrFail($documentId);
        app(\App\Services\DocumentService::class)->delete($document, auth()->user());
        if ((int) $this->lastJobDocumentUploadId === (int) $documentId) {
            $this->lastJobDocumentUploadId = null;
            $this->lastJobDocumentTaskId = null;
        }
    }

    public function toggleDocumentPicker(): void
    {
        $this->showDocumentPicker = !$this->showDocumentPicker;
    }

}
