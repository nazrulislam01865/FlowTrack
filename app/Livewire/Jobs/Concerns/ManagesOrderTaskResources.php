<?php

namespace App\Livewire\Jobs\Concerns;

use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Task;
use App\Services\AccessControlService;
use App\Services\ArtworkUploadStagingService;
use App\Services\DocumentService;
use App\Services\TaskService;
use App\Support\AttachmentUpload;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderTaskResources
{
    public function openOverviewTaskDocumentModal(int $taskId): void
    {
        $this->discardOverviewTaskStagedUploads();
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job', 'documentCategory', 'setupTemplate.documentCategory', 'documents:id,task_id,name'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

        $canCreate = auth()->user()->canModule('documents', 'create');
        $canLink = auth()->user()->canModule('documents', 'link');
        abort_unless($canCreate || $canLink, 403, 'Your role cannot add documents.');

        $this->showOverviewTaskDocumentModal = false;
        $this->overviewTaskLinkFormTaskId = null;
        $this->overviewTaskLinkUrl = '';
        $this->overviewTaskDocumentModalTaskId = $task->id;
        $this->overviewTaskDocumentSource = $canCreate ? 'upload' : 'existing';
        $this->overviewTaskDocumentUpload = [];
        $this->overviewTaskRevisionUpload = [];
        $this->overviewTaskStagedUploads = [];
        $this->overviewTaskStagedRevisionUploads = [];
        $pendingArtworkRevision = app(\App\Services\OrderWorkflowActionService::class)->automationKey($task) === 'ART_PREPARE_UPLOAD'
            ? app(DocumentService::class)->pendingArtworkRevision($task)
            : ['active' => false, 'document_ids' => []];
        $this->overviewTaskRevisionDocumentIds = (bool) ($pendingArtworkRevision['active'] ?? false)
            ? array_values(array_map('intval', $pendingArtworkRevision['document_ids'] ?? []))
            : [];
        $this->overviewTaskExistingDocumentId = null;
        $this->overviewTaskDocumentNote = '';
        $this->resetValidation([
            'overviewTaskDocumentUpload',
            'overviewTaskRevisionUpload',
            'overviewTaskRevisionDocumentIds',
            'overviewTaskExistingDocumentId',
            'overviewTaskDocumentNote',
        ]);
        $this->showOverviewTaskDocumentModal = true;
    }

    public function closeOverviewTaskDocumentModal(): void
    {
        $this->discardOverviewTaskStagedUploads();
        $this->showOverviewTaskDocumentModal = false;
        $this->overviewTaskDocumentModalTaskId = null;
        $this->overviewTaskDocumentSource = 'upload';
        $this->overviewTaskDocumentUpload = [];
        $this->overviewTaskRevisionUpload = [];
        $this->overviewTaskStagedUploads = [];
        $this->overviewTaskStagedRevisionUploads = [];
        $this->overviewTaskRevisionDocumentIds = [];
        $this->overviewTaskExistingDocumentId = null;
        $this->overviewTaskDocumentNote = '';
        $this->resetValidation([
            'overviewTaskDocumentUpload',
            'overviewTaskRevisionUpload',
            'overviewTaskRevisionDocumentIds',
            'overviewTaskExistingDocumentId',
            'overviewTaskDocumentNote',
        ]);
    }

    public function setOverviewTaskDocumentSource(string $source): void
    {
        $this->discardOverviewTaskStagedUploads();
        abort_unless(in_array($source, ['upload', 'existing'], true), 422);
        if ($source === 'upload') {
            abort_unless(auth()->user()->canModule('documents', 'create'), 403);
        } else {
            abort_unless(auth()->user()->canModule('documents', 'link'), 403);
        }

        $this->overviewTaskDocumentSource = $source;
        $this->overviewTaskDocumentUpload = [];
        $this->overviewTaskRevisionUpload = [];
        $this->overviewTaskStagedUploads = [];
        $this->overviewTaskStagedRevisionUploads = [];
        $this->overviewTaskExistingDocumentId = null;
        $this->resetValidation(['overviewTaskDocumentUpload', 'overviewTaskRevisionUpload', 'overviewTaskExistingDocumentId']);
    }

    /**
     * Register completed chunk-staged Artwork files in compact Livewire state.
     * Only tokens/metadata cross the Livewire boundary; the file bytes already
     * live on the server quarantine disk.
     *
     * @param array<int,string> $tokens
     */
    public function registerOverviewTaskArtworkUploads(array $tokens): void
    {
        $task = $this->overviewArtworkUploadTask();
        $staging = app(ArtworkUploadStagingService::class);
        $existingTokens = collect($this->overviewTaskStagedUploads)->pluck('token')->map('strval')->all();

        foreach (array_values(array_unique(array_filter(array_map('strval', $tokens)))) as $token) {
            if (in_array($token, $existingTokens, true)) continue;
            abort_if(count($this->overviewTaskStagedUploads) >= 50, 422, 'You can upload a maximum of 50 artwork files at a time.');

            $descriptor = $staging->describe($token, auth()->user(), $task);
            abort_if(($descriptor['revision_document_id'] ?? null) !== null, 422, 'This staged file belongs to a selective artwork revision.');
            $this->overviewTaskStagedUploads[] = $descriptor;
            $existingTokens[] = $token;
        }

        $this->resetValidation(['overviewTaskDocumentUpload', 'overviewTaskDocumentUpload.*']);
    }

    public function registerOverviewTaskArtworkRevisionUpload(int $documentId, string $token): void
    {
        $task = $this->overviewArtworkUploadTask();
        abort_unless(in_array($documentId, array_map('intval', $this->overviewTaskRevisionDocumentIds), true), 422, 'This artwork is not selected for revision.');

        $staging = app(ArtworkUploadStagingService::class);
        $descriptor = $staging->describe($token, auth()->user(), $task);
        abort_unless((int) ($descriptor['revision_document_id'] ?? 0) === $documentId, 422, 'This replacement belongs to a different artwork file.');

        $oldToken = (string) data_get($this->overviewTaskStagedRevisionUploads, $documentId.'.token', '');
        if ($oldToken !== '' && $oldToken !== $token) {
            $staging->discard($oldToken, auth()->user());
        }

        $this->overviewTaskStagedRevisionUploads[$documentId] = $descriptor;
        $this->resetValidation(['overviewTaskRevisionUpload', 'overviewTaskRevisionUpload.'.$documentId]);
    }

    private function overviewArtworkUploadTask(): Task
    {
        abort_unless($this->selectedJobId && $this->overviewTaskDocumentModalTaskId && $this->detailTab === 'overview', 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job', 'documentCategory', 'setupTemplate.documentCategory'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail((int) $this->overviewTaskDocumentModalTaskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        abort_unless(in_array(app(\App\Services\OrderWorkflowActionService::class)->automationKey($task), ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true), 422);
        return $task;
    }

    private function discardOverviewTaskStagedUploads(): void
    {
        if (! auth()->check()) return;
        $staging = app(ArtworkUploadStagingService::class);
        $tokens = collect($this->overviewTaskStagedUploads)
            ->concat(collect($this->overviewTaskStagedRevisionUploads)->values())
            ->pluck('token')
            ->map('strval')
            ->filter()
            ->unique();
        foreach ($tokens as $token) {
            $staging->discard($token, auth()->user());
        }
    }

    public function saveOverviewTaskDocument(): void
    {
        abort_unless($this->selectedJobId && $this->overviewTaskDocumentModalTaskId, 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job', 'documentCategory', 'setupTemplate.documentCategory'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail((int) $this->overviewTaskDocumentModalTaskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

        $this->validate([
            'overviewTaskDocumentSource' => ['required', Rule::in(['upload', 'existing'])],
            'overviewTaskDocumentNote' => ['nullable', 'string', 'max:2000'],
        ]);
        $note = trim($this->overviewTaskDocumentNote);
        $note = $note !== '' ? $note : null;
        $documentService = app(DocumentService::class);

        if ($this->overviewTaskDocumentSource === 'upload') {
            abort_unless(auth()->user()->canModule('documents', 'create'), 403);
            $automationKey = app(\App\Services\OrderWorkflowActionService::class)->automationKey($task);
            $isArtworkUpload = in_array($automationKey, ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true);
            $isPurchaseOrderUpload = $automationKey === 'NEW_UPLOAD_PO';
            $artworkRevision = $isArtworkUpload ? $documentService->pendingArtworkRevision($task) : ['active' => false, 'documents' => collect()];
            $isArtworkRevision = $isArtworkUpload && (bool) ($artworkRevision['active'] ?? false);
            $revisionDocuments = collect();
            $expectedRevisionCount = 0;
            if ($isArtworkRevision) {
                $revisionDocuments = collect($artworkRevision['documents'] ?? [])->values();
                $expectedRevisionCount = $revisionDocuments->count();

                $this->validate([
                    'overviewTaskRevisionDocumentIds' => ['required', 'array', 'min:1'],
                    'overviewTaskRevisionDocumentIds.*' => ['integer', 'distinct'],
                ], [
                    'overviewTaskRevisionDocumentIds.required' => 'No artwork is selected for this revision.',
                    'overviewTaskRevisionDocumentIds.min' => 'No artwork is selected for this revision.',
                ]);

                $expectedIds = $revisionDocuments->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                $stagedIds = collect(array_keys($this->overviewTaskStagedRevisionUploads))->map(fn ($id) => (int) $id)->sort()->values()->all();
                if ($expectedRevisionCount < 1 || $expectedIds !== $stagedIds) {
                    $this->addError(
                        'overviewTaskRevisionUpload',
                        'Choose one completed replacement file under each of the '.$expectedRevisionCount.' selected artwork file'.($expectedRevisionCount === 1 ? '' : 's').'.',
                    );
                    return;
                }

                $artworkRevision = $documentService->updatePendingArtworkRevisionSelection(
                    $task,
                    $this->overviewTaskRevisionDocumentIds,
                );
            }

            $revisionFileCount = (bool) ($artworkRevision['active'] ?? false)
                ? collect($artworkRevision['documents'] ?? [])->count()
                : 0;
            $allowsMultiple = $isArtworkUpload || $isPurchaseOrderUpload || (bool) ($task->setupTemplate?->allow_multiple_documents ?? false);

            if (! $isArtworkRevision) {
                if ($isArtworkUpload) {
                    $stagedCount = count($this->overviewTaskStagedUploads);
                    if ($stagedCount < 1) {
                        $this->addError('overviewTaskDocumentUpload', 'Choose at least one artwork file to upload.');
                        return;
                    }
                    if ($stagedCount > 50) {
                        $this->addError('overviewTaskDocumentUpload', 'You can upload a maximum of 50 artwork files at a time.');
                        return;
                    }
                } else {
                    // All non-Artwork order document flows retain the existing
                    // Livewire upload transport and 20MB business limit.
                    $uploadMaxKilobytes = AttachmentUpload::STANDARD_MAX_KILOBYTES;
                    $maxUploads = $allowsMultiple ? 10 : 1;
                    $maxUploadsMessage = $allowsMultiple
                        ? 'You can upload a maximum of '.$maxUploads.' files at a time.'
                        : 'Choose one file for this task.';

                    $this->validate([
                        'overviewTaskDocumentUpload' => ['required', 'array', 'min:1', 'max:'.$maxUploads],
                        'overviewTaskDocumentUpload.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, $uploadMaxKilobytes),
                    ], [
                        'overviewTaskDocumentUpload.max' => $maxUploadsMessage,
                        'overviewTaskDocumentUpload.*.max' => 'Each file must be 20 MB or smaller.',
                    ]);
                }
            }

            $staging = app(ArtworkUploadStagingService::class);
            $materializedPaths = [];
            $stagedTokens = [];
            $uploads = $isArtworkRevision ? $this->overviewTaskRevisionUpload : $this->overviewTaskDocumentUpload;

            try {
                if ($isArtworkUpload) {
                    if ($isArtworkRevision) {
                        foreach ($revisionDocuments as $revisionDocument) {
                            $documentId = (int) $revisionDocument->id;
                            $token = (string) data_get($this->overviewTaskStagedRevisionUploads, $documentId.'.token', '');
                            abort_if($token === '', 422, 'One replacement artwork upload is missing.');
                            $stagedTokens[$documentId] = $token;
                        }
                    } else {
                        $stagedTokens = collect($this->overviewTaskStagedUploads)
                            ->pluck('token')
                            ->map('strval')
                            ->filter()
                            ->values()
                            ->all();
                    }

                    $materialized = $staging->materialize($stagedTokens, auth()->user(), $task);
                    $uploads = $materialized['files'];
                    $materializedPaths = $materialized['temporary_paths'];
                }

                if ($revisionFileCount > 0) {
                    $documentService->storeArtworkRevision(
                        $uploads,
                        $task,
                        auth()->user(),
                        $note,
                    );
                } else {
                    $storeData = [
                        'flow_job_id' => $task->flow_job_id,
                        'client_id' => $task->job?->client_id,
                        'task_id' => $task->id,
                        'note' => $note,
                    ];
                    if ($documentService->taskHasRequirement($task)) {
                        $storeData['require_task_pack_requirement'] = true;
                    } else {
                        $storeData['category'] = 'Task attachment';
                    }
                    $documentService->storeMany($uploads, $storeData, auth()->user());
                }

                if ($isArtworkUpload) {
                    $staging->consume($stagedTokens, auth()->user());
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() !== 422) {
                    throw $exception;
                }

                $message = trim((string) $exception->getMessage());
                $this->addError(
                    $isArtworkRevision ? 'overviewTaskRevisionUpload' : 'overviewTaskDocumentUpload',
                    $message !== '' ? $message : 'One of the selected files could not be verified. Re-export it and try again.',
                );
                return;
            } finally {
                $staging->releaseMaterialized($materializedPaths);
            }
        } else {
            abort_unless(auth()->user()->canModule('documents', 'link'), 403);
            $this->validate(['overviewTaskExistingDocumentId' => ['required', 'integer', 'exists:documents,id']]);
            $source = app(AccessControlService::class)
                ->applyDocumentScope(Document::query()->whereKey((int) $this->overviewTaskExistingDocumentId), auth()->user())
                ->firstOrFail();
            abort_unless((int) $source->client_id === (int) $task->job?->client_id, 403, 'The selected document does not belong to this client.');
            $documentService->linkExisting($source, $task, auth()->user(), true, $note);
        }

        // Prototype document actions (PO, Artwork, Sample Approval) are
        // task actions, not passive attachments. Let the backend complete the
        // configured task and unlock/advance the workflow after evidence saves.
        app(\App\Services\OrderWorkflowActionService::class)->afterDocumentAdded($task->refresh(), auth()->user());
        $this->dispatchTaskAssigneeSync($task->id);

        // A file-backed task can be the final applicable task in a stage (for
        // example Sample Approval). If it auto-advances the Order, move the
        // visible task panel to the new current phase immediately rather than
        // leaving Livewire focused on the stage that just completed.
        $currentPhaseId = FlowJob::query()->whereKey($this->selectedJobId)->value('workflow_phase_id');
        if ($currentPhaseId) $this->overviewPhaseId = (int) $currentPhaseId;

        $title = (string) $task->title;
        $this->closeOverviewTaskDocumentModal();
        session()->flash('success', 'Document added to '.$title.'.');
    }

    public function removeOverviewTaskDocumentUpload(int $index): void
    {
        $staging = app(ArtworkUploadStagingService::class);

        if (array_key_exists($index, $this->overviewTaskStagedRevisionUploads)) {
            $token = (string) data_get($this->overviewTaskStagedRevisionUploads, $index.'.token', '');
            if ($token !== '') $staging->discard($token, auth()->user());
            unset($this->overviewTaskStagedRevisionUploads[$index]);
            $this->resetValidation(['overviewTaskRevisionUpload', 'overviewTaskRevisionUpload.'.$index]);
            return;
        }

        if (array_key_exists($index, $this->overviewTaskStagedUploads)) {
            $token = (string) data_get($this->overviewTaskStagedUploads, $index.'.token', '');
            if ($token !== '') $staging->discard($token, auth()->user());
            unset($this->overviewTaskStagedUploads[$index]);
            $this->overviewTaskStagedUploads = array_values($this->overviewTaskStagedUploads);
            $this->resetValidation(['overviewTaskDocumentUpload', 'overviewTaskDocumentUpload.*']);
            return;
        }

        if (array_key_exists($index, $this->overviewTaskRevisionUpload)) {
            unset($this->overviewTaskRevisionUpload[$index]);
            $this->resetValidation(['overviewTaskRevisionUpload', 'overviewTaskRevisionUpload.'.$index]);
            return;
        }

        if (! array_key_exists($index, $this->overviewTaskDocumentUpload)) return;

        unset($this->overviewTaskDocumentUpload[$index]);
        $this->overviewTaskDocumentUpload = array_values($this->overviewTaskDocumentUpload);
        $this->resetValidation(['overviewTaskDocumentUpload', 'overviewTaskDocumentUpload.*']);
    }

    public function openOverviewTaskLinkForm(int $taskId): void
    {
        $this->discardOverviewTaskStagedUploads();
        $task = $this->editableOverviewTask($taskId);
        $this->showOverviewTaskDocumentModal = false;
        $this->overviewTaskDocumentModalTaskId = null;
        $this->overviewTaskDocumentUpload = [];
        $this->overviewTaskRevisionUpload = [];
        $this->overviewTaskStagedUploads = [];
        $this->overviewTaskStagedRevisionUploads = [];
        $this->overviewTaskExistingDocumentId = null;
        $this->overviewTaskDocumentNote = '';
        $this->overviewTaskLinkFormTaskId = $task->id;
        $this->overviewTaskLinkUrl = '';
        $this->resetValidation(['overviewTaskLinkUrl']);
    }

    public function cancelOverviewTaskLinkForm(): void
    {
        $this->overviewTaskLinkFormTaskId = null;
        $this->overviewTaskLinkUrl = '';
        $this->resetValidation(['overviewTaskLinkUrl']);
    }

    public function saveOverviewTaskLink(int $taskId): void
    {
        abort_unless((int) $this->overviewTaskLinkFormTaskId === $taskId, 422);
        $url = trim($this->overviewTaskLinkUrl);
        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $this->overviewTaskLinkUrl = $url;
        $this->validate([
            'overviewTaskLinkUrl' => ['required', 'string', 'max:2048', 'url'],
        ], [
            'overviewTaskLinkUrl.required' => 'Enter a link to add.',
            'overviewTaskLinkUrl.url' => 'Enter a valid website or file link.',
            'overviewTaskLinkUrl.max' => 'The link is too long.',
        ]);

        $task = $this->editableOverviewTask($taskId);
        $link = app(TaskService::class)->addExternalLink($task, $this->overviewTaskLinkUrl, auth()->user());
        $this->dispatchTaskAssigneeSync($task->id);

        // Close the inline form only after persistence is confirmed. The next
        // Livewire render then re-queries the task with its links relation and
        // keeps the newly added resource visible instead of morphing it away.
        abort_unless($task->links()->whereKey($link->id)->exists(), 500, 'The task link could not be saved.');
        $this->overviewTaskLinkFormTaskId = null;
        $this->overviewTaskLinkUrl = '';
        $this->resetValidation(['overviewTaskLinkUrl']);
        session()->flash('success', 'Link added to '.$task->title.'.');
    }

    public function deleteOverviewTaskLink(int $taskId, int $linkId): void
    {
        $task = $this->editableOverviewTask($taskId);
        app(TaskService::class)->removeExternalLink($task, $linkId, auth()->user());
        $this->dispatchTaskAssigneeSync($task->id);
        session()->flash('success', 'Task link removed.');
    }

    private function editableOverviewTask(int $taskId): Task
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);

        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);

        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

        return $task;
    }

    public function updatedOverviewTaskUploads(mixed $value, string|int $key): void
    {
        if (!$value || !is_numeric($key)) return;

        $this->uploadOverviewTaskFile((int) $key);
    }

    public function uploadOverviewTaskFile(int $taskId): array
    {
        abort_unless($this->selectedJobId, 422);
        abort_unless(auth()->user()->canModule('documents', 'create'), 403);

        $property = 'overviewTaskUploads.'.$taskId;
        $this->resetValidation([$property]);
        $upload = $this->overviewTaskUploads[$taskId] ?? null;

        if (!$upload) {
            return ['ok' => false, 'message' => 'Choose a file to upload.'];
        }

        $validator = validator(['overviewTaskUploads' => [$taskId => $upload]], [
            $property => AttachmentUpload::requiredRules(AttachmentUpload::DOCUMENTS, 20480),
        ], [
            $property.'.required' => 'Choose a file to upload.',
            $property.'.max' => 'The file is too large. Maximum file size is 20 MB.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->get($property) as $message) {
                $this->addError($property, $message);
            }
            return ['ok' => false, 'message' => $validator->errors()->first($property)];
        }

        $task = app(TaskService::class)
            ->visibleQuery(auth()->user())
            ->with(['job', 'documentCategory', 'setupTemplate.documentCategory'])
            ->where('flow_job_id', $this->selectedJobId)
            ->findOrFail($taskId);

        $documentService = app(DocumentService::class);
        $storeData = [
            'flow_job_id' => $task->flow_job_id,
            'client_id' => $task->job?->client_id,
            'task_id' => $task->id,
        ];

        if ($documentService->taskHasRequirement($task)) {
            // Preserve Task Pack requirement semantics so the Overview upload
            // immediately satisfies the same required-document gate used by
            // Workflow and Order Documents.
            $storeData['require_task_pack_requirement'] = true;
        } else {
            $storeData['category'] = 'Task attachment';
        }

        try {
            $documentService->store($upload, $storeData, auth()->user());
        } catch (\Throwable $e) {
            report($e);
            $message = 'FlowTrack could not store this task attachment. Please try again.';
            $this->addError($property, $message);
            return ['ok' => false, 'message' => $message];
        }

        $this->dispatchTaskAssigneeSync($task->id);

        unset($this->overviewTaskUploads[$taskId]);
        $this->resetValidation([$property]);
        session()->flash('success', 'Document uploaded and linked to '.$task->title.'.');

        return ['ok' => true];
    }

    public function updatedTaskDocumentUploads(): void
    {
        if (count($this->taskDocumentUploads) === 0) {
            return;
        }

        // Selecting a new file switches the source back to Upload new. Permanent
        // storage is triggered by the browser after livewire-upload-finish so the
        // temporary upload cannot race the save/link request.
        $this->showTaskDocumentPicker = false;
        $this->taskExistingDocumentId = null;
        $this->resetValidation(['taskExistingDocumentId']);
    }

    public function uploadSelectedTaskDocuments(): array
    {
        abort_unless($this->selectedTaskId, 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['job','documentCategory','setupTemplate.documentCategory'])
            ->findOrFail($this->selectedTaskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        abort_unless(auth()->user()->canModule('documents','create'), 403);
        $this->resetValidation(['taskDocumentUploads', 'taskDocumentUploads.*']);

        $validator = validator(['taskDocumentUploads' => $this->taskDocumentUploads], [
            'taskDocumentUploads' => ['required','array','min:1'],
            'taskDocumentUploads.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS, 20480),
        ], [
            'taskDocumentUploads.required' => 'Choose at least one file to upload.',
            'taskDocumentUploads.*.max' => 'The file is too large. Maximum file size is 20 MB.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $key => $messages) {
                foreach ($messages as $message) $this->addError($key, $message);
            }
            return ['ok' => false, 'message' => $validator->errors()->first()];
        }

        try {
            $documentService = app(DocumentService::class);
            $storeData = [
                'flow_job_id' => $task->flow_job_id,
                'client_id' => $task->job?->client_id,
                'task_id' => $task->id,
            ];

            // Uploading from Task Details must satisfy the same Task Pack
            // document requirement as uploading from the Order taskflow.
            if ($documentService->taskHasRequirement($task)) {
                $storeData['require_task_pack_requirement'] = true;
            } else {
                $storeData['category'] = 'Task attachment';
            }

            $documentService->storeMany($this->taskDocumentUploads, $storeData, auth()->user());
        } catch (\Throwable $e) {
            report($e);
            $message = 'FlowTrack could not store this attachment. Please try again.';
            $this->addError('taskDocumentUploads', $message);
            return ['ok' => false, 'message' => $message];
        }

        $this->dispatchTaskAssigneeSync($task->id);

        $this->taskDocumentUploads = [];
        $this->taskExistingDocumentId = null;
        $this->showTaskDocumentPicker = false;
        $this->resetValidation(['taskDocumentUploads', 'taskDocumentUploads.*']);
        session()->flash('success', 'Attachment uploaded and linked to this Task Pack task.');
        return ['ok' => true];
    }

    public function attachExistingToSelectedTask(): void
    {
        abort_unless(auth()->user()->canModule('documents','link'), 403);
        abort_unless($this->selectedTaskId, 422);
        $this->validate(['taskExistingDocumentId'=>['required','integer','exists:documents,id']]);
        $task = app(TaskService::class)->visibleQuery(auth()->user())->with(['job','documentCategory','setupTemplate.documentCategory'])->findOrFail($this->selectedTaskId);
        $source = Document::findOrFail((int)$this->taskExistingDocumentId);
        abort_unless((int) $source->client_id === (int) $task->job?->client_id, 403, 'The selected document does not belong to this client.');
        app(\App\Services\DocumentService::class)->linkExisting($source, $task, auth()->user(), true);
        $this->dispatchTaskAssigneeSync($task->id);
        $this->taskDocumentUploads = [];
        $this->taskExistingDocumentId = null;
        $this->showTaskDocumentPicker = false;
        $this->resetValidation(['taskDocumentUploads', 'taskDocumentUploads.*', 'taskExistingDocumentId']);
        session()->flash('success', 'Stored document linked to this task.');
    }

    public function deleteSelectedTaskDocument(int $documentId): void
    {
        abort_unless($this->selectedTaskId, 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedTaskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        abort_unless(auth()->user()->canModule('documents','delete'), 403);
        $document = Document::where('task_id',$task->id)->findOrFail($documentId);
        app(\App\Services\DocumentService::class)->delete($document, auth()->user());
        $this->dispatchTaskAssigneeSync($task->id);
    }

    public function toggleTaskDocumentPicker(): void
    {
        abort_unless(auth()->user()->canModule('documents', 'link'), 403);

        $opening = ! $this->showTaskDocumentPicker;
        if ($opening) {
            // Existing-document mode replaces Upload new; do not leave selected
            // temporary files and an Upload & link action active underneath it.
            $this->taskDocumentUploads = [];
            $this->resetValidation(['taskDocumentUploads', 'taskDocumentUploads.*']);
        } else {
            $this->taskExistingDocumentId = null;
            $this->resetValidation(['taskExistingDocumentId']);
        }

        $this->showTaskDocumentPicker = $opening;
    }

}
