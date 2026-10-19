<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Task;
use App\Models\User;
use App\Support\ArtworkDocumentName;
use App\Support\AttachmentUpload;
use App\Support\StoredFileResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentService
{
    /** @var array<int,bool> */
    private array $workflowSummaryInvalidatedOrders = [];

    public function query(User $user, array $filters = [], string $permissionModule = 'documents')
    {
        $query = app(AccessControlService::class)->applyDocumentScope(Document::query(), $user, $permissionModule)
            ->with(['job.client','job.phase','job.tasks' => fn ($q) => app(AccessControlService::class)->applyTaskScope($q, $user),'task.phase','task.assignee','uploader']);

        return $query
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($x) => $x
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('document_number', "%{$search}%")
                ->orWhereHas('job', fn ($j) => $j->whereLike('job_number', "%{$search}%")->orWhereLike('title', "%{$search}%")->orWhereHas('client', fn ($client) => $client->whereLike('name', "%{$search}%")))
                ->orWhereHas('client', fn ($client) => $client->whereLike('name', "%{$search}%"))
                ->orWhereHas('task', fn ($t) => $t->whereLike('title', "%{$search}%"))))
            ->when($filters['category'] ?? null, fn ($q, $value) => $q->where('category', $value))
            ->when($filters['client'] ?? null, fn ($q, $value) => $q->where('client_id', $value))
            ->when($filters['job'] ?? null, fn ($q, $value) => $q->where('flow_job_id', $value))
            ->when($filters['phase'] ?? null, fn ($q, $value) => $q->whereHas('task.phase', fn ($phase) => $phase->where(fn ($x) => $x->where('workflow_phases.id', $value)->orWhere('workflow_phases.source_workflow_phase_id', $value))))
            ->when($filters['status'] ?? null, function ($q, $value) {
                match ($value) {
                    'approved' => $q->where('is_final', true),
                    'needs_action' => $q->where('is_final', false)->whereHas('task', fn ($t) => $t->where('needs_attention', true)),
                    'awaiting_approval' => $q->where('is_final', false)->whereHas('task', fn ($t) => $t->whereIn('status', ['In Review','Waiting for Internal Approval'])),
                    'recent' => $q->where('updated_at', '>=', now()->subDays(7)),
                    'current' => $q->where('is_final', false)->where(fn ($x) => $x->whereDoesntHave('task')->orWhereHas('task', fn ($t) => $t->where('needs_attention', false))),
                    default => null,
                };
            });
    }

    public function list(User $user, array $filters = [], string $permissionModule = 'documents')
    {
        return $this->query($user, $filters, $permissionModule)->latest()->limit(250)->get();
    }

    public function paginate(User $user, array $filters = [], int $perPage = 25, string $permissionModule = 'documents')
    {
        return $this->query($user, $filters, $permissionModule)->latest()->paginate($perPage);
    }

    public function store(UploadedFile $file, array $data, User $user, string $permissionModule = 'documents', ?int $securityMaxBytes = null): Document
    {
        $access = app(AccessControlService::class);
        abort_unless($access->can($user, $permissionModule, 'create'), 403);

        $task = null;
        if (!empty($data['task_id'])) {
            $task = Task::with(['job','documentCategory','setupTemplate.documentCategory'])->findOrFail((int) $data['task_id']);
            if ($permissionModule === 'document_archive') {
                $access->applyDocumentArchiveTaskScope(Task::query()->whereKey($task->id), $user)->firstOrFail();
                $access->applyDocumentArchiveJobScope(\App\Models\FlowJob::query()->whereKey($task->flow_job_id), $user)->firstOrFail();
            } else {
                $access->applyTaskScope(Task::query()->whereKey($task->id), $user)->firstOrFail();
                app(JobService::class)->findVisible($user, (int) $task->flow_job_id);
            }
            if (!empty($data['flow_job_id'])) abort_unless((int) $task->flow_job_id === (int) $data['flow_job_id'], 422, 'The selected document task does not belong to this Job.');
            if (($data['require_task_pack_requirement'] ?? false) === true) abort_unless($this->taskHasRequirement($task), 422, 'This task has no Task Pack document requirement.');
        } elseif (!empty($data['flow_job_id'])) {
            if ($permissionModule === 'document_archive') {
                $access->applyDocumentArchiveJobScope(\App\Models\FlowJob::query()->whereKey((int) $data['flow_job_id']), $user)->firstOrFail();
            } else {
                app(JobService::class)->findVisible($user, (int) $data['flow_job_id']);
            }
        }

        $orderIdForHold = $task?->flow_job_id ?: ($data['flow_job_id'] ?? null);
        if ($orderIdForHold) {
            app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $orderIdForHold);
        }

        $automationKey = $task ? app(OrderWorkflowActionService::class)->automationKey($task) : null;
        if ($securityMaxBytes === null && in_array($automationKey, ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true)) {
            // Artwork is the only normal document flow allowed above the global
            // secure-storage ceiling. Keep all other uploads on the existing cap.
            $securityMaxBytes = AttachmentUpload::ARTWORK_MAX_BYTES;
        }

        $jobId = $task?->flow_job_id ?: ($data['flow_job_id'] ?? 'general');
        $stored = app(SecureDocumentStorage::class)->store($file, 'flowtrack/documents/'.$jobId, $securityMaxBytes);
        $path = $stored['path'];

        $category = $task?->documentCategory?->name
            ?: $task?->setupTemplate?->documentCategory?->name
            ?: ($data['category'] ?? ($task ? 'Task attachment' : 'Other'));

        $originalName = $file->getClientOriginalName();
        $isArtworkTask = $task && $automationKey === 'ART_PREPARE_UPLOAD';
        $batchVersion = max(0, (int) ($data['artwork_batch_version'] ?? 0));
        $version = $isArtworkTask && $batchVersion > 0
            ? $batchVersion
            : $this->nextDocumentVersion(
                $task,
                $task?->flow_job_id ?: ($data['flow_job_id'] ?? null),
                $task?->id ?: ($data['task_id'] ?? null),
                $category,
                $originalName,
            );
        $documentName = $isArtworkTask
            ? ArtworkDocumentName::versioned((string) ($data['artwork_base_name'] ?? $originalName), $version, $originalName)
            : $originalName;

        $document = Document::create([
            'document_number' => $this->nextNumber(),
            'flow_job_id' => $task?->flow_job_id ?: ($data['flow_job_id'] ?? null),
            'client_id' => $task?->job?->client_id ?: ($data['client_id'] ?? null),
            'task_id' => $task?->id ?: ($data['task_id'] ?? null),
            'uploaded_by' => $user->id,
            'category' => $category,
            'name' => $documentName,
            'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
            'path' => $path,
            'mime_type' => StoredFileResponse::mimeType($documentName, $stored['mime']),
            'size' => $stored['size'],
            'version' => $version,
            'is_final' => false,
        ]);

        $this->recordDocumentActivity($document, $user, 'uploaded');
        $this->notifyDocumentChange($document, $user, 'uploaded');
        return $document;
    }

    /**
     * Store a user-selected file set. Artwork files selected together are one
     * revision and therefore share a version number; later artwork batches
     * increment that version once. Other document types retain their existing
     * per-file version behaviour.
     *
     * @param array<int,UploadedFile> $files
     * @return Collection<int,Document>
     */
    public function storeMany(array $files, array $data, User $user, string $permissionModule = 'documents', ?int $securityMaxBytes = null): Collection
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($files, $data, $user, $permissionModule, $securityMaxBytes, &$storedPaths): Collection {
                $documents = collect();
                $artworkBatchVersion = null;

                foreach (array_values($files) as $file) {
                    $fileData = $data;
                    if ($artworkBatchVersion !== null) {
                        $fileData['artwork_batch_version'] = $artworkBatchVersion;
                    }

                    $document = $this->store($file, $fileData, $user, $permissionModule, $securityMaxBytes);
                    $documents->push($document);
                    if (filled($document->path)) {
                        $storedPaths[] = (string) $document->path;
                    }

                    $document->loadMissing('task.setupTemplate');
                    if ($artworkBatchVersion === null
                        && $document->task
                        && app(OrderWorkflowActionService::class)->automationKey($document->task) === 'ART_PREPARE_UPLOAD') {
                        $artworkBatchVersion = max(1, (int) $document->version);
                    }
                }

                return $documents;
            });
        } catch (\Throwable $exception) {
            // Treat the file set as one upload. If a later file fails security
            // inspection, roll back the database rows and remove any files that
            // were already promoted for this batch.
            foreach (array_unique($storedPaths) as $path) {
                app(SecureDocumentStorage::class)->delete($path);
            }

            throw $exception;
        }
    }


    /**
     * Resolve the currently active Artwork files for the upload task.
     *
     * Normal uploads are still complete batches and therefore fall back to the
     * highest shared version. Selective revision uploads are different: only
     * the files that were actually replaced receive a new version, while the
     * accepted files continue to use their existing Document rows/version.
     * The revision-applied activity stores the exact current document ids so a
     * current Artwork set can legitimately contain V1, V2, V3, ... together.
     *
     * @param Collection<int,Document>|null $taskDocuments
     * @param Collection<int,mixed>|null $appliedRevisionActivities Preloaded job-level revision activities for query-free batch presentation.
     * @param Collection<int,mixed>|null $cancellationActivities Preloaded artwork cancellation activities for query-free Order detail rendering.
     * @return Collection<int,Document>
     */
    public function currentArtworkDocuments(
        Task $task,
        ?Collection $taskDocuments = null,
        ?Collection $appliedRevisionActivities = null,
        ?Collection $cancellationActivities = null,
    ): Collection
    {
        $task->loadMissing(['setupTemplate', 'job']);
        if (app(OrderWorkflowActionService::class)->automationKey($task) !== 'ART_PREPARE_UPLOAD') {
            return collect();
        }

        $documents = ($taskDocuments ?? Document::query()
            ->where('flow_job_id', $task->flow_job_id)
            ->where('task_id', $task->id)
            ->orderBy('id')
            ->get())
            ->sortBy('id')
            ->values();

        if ($documents->isEmpty()) {
            return collect();
        }

        if ($appliedRevisionActivities !== null) {
            $appliedActivities = $appliedRevisionActivities;
        } else {
            $appliedActivities = $task->job
                ? $task->job->activities()
                    ->where('event', 'job.artwork_revision_applied')
                    ->latest('id')
                    ->limit(50)
                    ->get()
                : collect();
        }

        $latestApplied = $appliedActivities->first(function ($activity) use ($task) {
            $activityTaskId = (int) data_get($activity->meta, 'target_task_id', data_get($activity->meta, 'task_id', 0));
            return $activityTaskId === (int) $task->id;
        });

        if ($latestApplied) {
            $meta = (array) $latestApplied->meta;
            $currentIds = $this->currentArtworkIdsFromAppliedRevision($meta);
            $documentsById = $documents->keyBy(fn (Document $document) => (int) $document->id);
            $current = collect($currentIds)
                ->map(fn ($id) => $documentsById->get((int) $id))
                ->filter()
                ->values();

            // All rows generated by a selective revision (including legacy
            // carry-forward clones) are inside new_document_ids. Anything
            // created after that boundary is a later normal/full upload and
            // becomes the new batch source of truth.
            $boundaryIds = collect(data_get($meta, 'new_document_ids', []))
                ->merge($currentIds)
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0);
            $boundaryId = (int) ($boundaryIds->max() ?? 0);
            $laterDocuments = $boundaryId > 0
                ? $documents->filter(fn (Document $document) => (int) $document->id > $boundaryId)->values()
                : collect();

            if ($laterDocuments->isEmpty() && $current->isNotEmpty()) {
                return $this->withoutCancelledArtwork($task, $current, $cancellationActivities);
            }

            if ($laterDocuments->isNotEmpty()) {
                $latestLaterVersion = max(1, (int) $laterDocuments->max('version'));
                return $this->withoutCancelledArtwork(
                    $task,
                    $laterDocuments->where('version', $latestLaterVersion)->sortBy('id')->values(),
                    $cancellationActivities,
                );
            }
        }

        $latestVersion = max(1, (int) $documents->max('version'));
        return $this->withoutCancelledArtwork(
            $task,
            $documents->where('version', $latestVersion)->sortBy('id')->values(),
            $cancellationActivities,
        );
    }

    /**
     * Remove artwork explicitly cancelled from this Order while retaining the
     * underlying Document rows for audit/history. Cancellation ids are immutable
     * document ids, so later full uploads/revisions are unaffected automatically.
     *
     * @param Collection<int,Document> $documents
     * @param Collection<int,mixed>|null $cancellationActivities
     * @return Collection<int,Document>
     */
    private function withoutCancelledArtwork(Task $task, Collection $documents, ?Collection $cancellationActivities = null): Collection
    {
        if ($documents->isEmpty()) {
            return $documents->values();
        }

        $activities = $cancellationActivities;
        if ($activities === null) {
            $task->loadMissing('job');
            $activities = $task->job
                ? $task->job->activities()
                    ->where('event', 'job.artwork_cancelled')
                    ->latest('id')
                    ->get(['id', 'meta'])
                : collect();
        }

        $cancelledIds = collect($activities)
            ->filter(function ($activity) use ($task): bool {
                $targetTaskId = (int) data_get($activity->meta, 'target_task_id', 0);
                return $targetTaskId === (int) $task->id;
            })
            ->flatMap(fn ($activity) => collect(data_get($activity->meta, 'document_ids', [])))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->flip();

        if ($cancelledIds->isEmpty()) {
            return $documents->values();
        }

        return $documents
            ->reject(fn (Document $document): bool => $cancelledIds->has((int) $document->id))
            ->values();
    }

    /**
     * Reconstruct the current Artwork ids from both the new selective-revision
     * metadata and activities created by the earlier carry-forward approach.
     *
     * @param array<string,mixed> $meta
     * @return array<int,int>
     */
    private function currentArtworkIdsFromAppliedRevision(array $meta): array
    {
        $currentIds = collect(data_get($meta, 'current_document_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();
        if ($currentIds->isNotEmpty()) {
            return $currentIds->all();
        }

        // Compatibility for selective revisions created before current ids
        // were persisted. Those activities created one new row for every
        // source file, including unchanged carry-forward clones. new_document_ids
        // follows the source-id sort order, so retain the original id for an
        // accepted file and use the generated id only for a replaced file.
        $replacedIds = collect(data_get($meta, 'replaced_source_document_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique();
        $sourceIds = $replacedIds
            ->merge(collect(data_get($meta, 'retained_source_document_ids', []))->map(fn ($id) => (int) $id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $newIds = collect(data_get($meta, 'new_document_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values();

        if ($sourceIds->isNotEmpty() && $sourceIds->count() === $newIds->count()) {
            return $sourceIds
                ->map(function ($sourceId, $index) use ($replacedIds, $newIds) {
                    return $replacedIds->contains((int) $sourceId)
                        ? (int) $newIds->get($index)
                        : (int) $sourceId;
                })
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * Return the outstanding selective Artwork revision for an upload task.
     * New revision events store the exact document ids selected by the reviewer.
     * Legacy events without that metadata fall back to replacing the full latest
     * artwork set, preserving the previous behaviour for already-open revisions.
     *
     * @return array{active:bool,source_version:int,document_ids:array<int,int>,documents:Collection<int,Document>,retained_documents:Collection<int,Document>,comment:string,activity_id:int}
     */
    public function pendingArtworkRevision(Task $task): array
    {
        $empty = [
            'active' => false,
            'source_version' => 0,
            'document_ids' => [],
            'documents' => collect(),
            'retained_documents' => collect(),
            'comment' => '',
            'items' => [],
            'activity_id' => 0,
        ];

        $task->loadMissing(['setupTemplate', 'job']);
        if (app(OrderWorkflowActionService::class)->automationKey($task) !== 'ART_PREPARE_UPLOAD' || ! $task->job) {
            return $empty;
        }

        $taskDocuments = Document::query()
            ->where('flow_job_id', $task->flow_job_id)
            ->where('task_id', $task->id)
            ->orderBy('id')
            ->get();
        if ($taskDocuments->isEmpty()) {
            return $empty;
        }

        $currentDocuments = $this->currentArtworkDocuments($task, $taskDocuments);
        if ($currentDocuments->isEmpty()) {
            return $empty;
        }
        $latestVersion = max(1, (int) $currentDocuments->max('version'));

        // Filter a small recent activity window in PHP rather than relying on a
        // database-specific JSON expression for meta->target_task_id.
        $activity = $task->job->activities()
            ->where('event', 'job.artwork_revision_requested')
            ->latest('id')
            ->limit(30)
            ->get()
            ->first(fn ($candidate) => (int) data_get($candidate->meta, 'target_task_id', 0) === (int) $task->id);

        if (! $activity) {
            return $empty;
        }

        $sourceVersion = max(0, (int) data_get($activity->meta, 'source_artwork_version', 0));
        $referenceDocumentId = max(0, (int) data_get($activity->meta, 'reference_document_id', 0));
        $sourceDocumentIds = collect(data_get($activity->meta, 'source_artwork_document_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        // The applied event is the definitive completion marker for selective
        // revisions. This works even when a V1 file is replaced by V2 while a
        // different accepted file is already V3 and therefore the task-wide
        // maximum version does not change.
        $wasApplied = $task->job->activities()
            ->where('event', 'job.artwork_revision_applied')
            ->latest('id')
            ->limit(50)
            ->get()
            ->contains(fn ($candidate) => (int) data_get($candidate->meta, 'revision_activity_id', 0) === (int) $activity->id);
        if ($wasApplied) {
            return $empty;
        }

        // If a newer full Artwork upload replaced the entire source set while
        // this request was open, the old request is no longer actionable.
        if ($sourceDocumentIds->isNotEmpty()) {
            $currentIds = $currentDocuments->pluck('id')->map(fn ($id) => (int) $id)->sort()->values();
            if ($currentIds->all() !== $sourceDocumentIds->sort()->values()->all()) {
                return $empty;
            }
        } elseif (($sourceVersion > 0 && $latestVersion > $sourceVersion)
            || ($sourceVersion <= 0 && $referenceDocumentId > 0 && $taskDocuments->contains(fn (Document $document) => (int) $document->id > $referenceDocumentId))) {
            return $empty;
        }

        $selectionPending = (bool) data_get($activity->meta, 'revision_selection_pending', false);
        $requestedIds = collect(data_get($activity->meta, 'revision_document_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        // Compatibility: revisions created before selective revision support
        // required the whole latest set to be uploaded again. A new internal
        // request can intentionally have no selection yet because the exact
        // artwork is chosen in the reopened upload dialog.
        if ($requestedIds->isEmpty() && ! $selectionPending) {
            $requestedIds = $currentDocuments->pluck('id')->map(fn ($id) => (int) $id)->values();
        }

        $selected = $currentDocuments
            ->filter(fn (Document $document) => $requestedIds->contains((int) $document->id))
            ->values();
        if ($selected->isEmpty() && ! $selectionPending) {
            return $empty;
        }

        $selectedIds = $selected->pluck('id')->map(fn ($id) => (int) $id)->values();

        return [
            'active' => true,
            'source_version' => $sourceVersion > 0 ? $sourceVersion : $latestVersion,
            'document_ids' => $selectedIds->all(),
            'documents' => $selected,
            'retained_documents' => $currentDocuments
                ->reject(fn (Document $document) => $selectedIds->contains((int) $document->id))
                ->values(),
            'comment' => trim((string) data_get($activity->meta, 'revision_comment', '')),
            'items' => collect(data_get($activity->meta, 'revision_items', []))->values()->all(),
            'activity_id' => (int) $activity->id,
        ];
    }

    /**
     * Update which files the reopened Artwork uploader will replace.
     *
     * The revision request itself remains the audit event; this only narrows or
     * expands its selected document ids before the replacement files are saved.
     * No schema change is required because the selection already lives in the
     * revision activity metadata.
     *
     * @param array<int,int|string> $documentIds
     * @return array{active:bool,source_version:int,document_ids:array<int,int>,documents:Collection<int,Document>,retained_documents:Collection<int,Document>,comment:string,activity_id:int}
     */
    public function updatePendingArtworkRevisionSelection(Task $task, array $documentIds): array
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $revision = $this->pendingArtworkRevision($task);
        if (! ($revision['active'] ?? false)) {
            throw ValidationException::withMessages([
                'overviewTaskRevisionDocumentIds' => 'There is no active artwork revision to update.',
            ]);
        }

        $requestedIds = collect($documentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($requestedIds->isEmpty()) {
            throw ValidationException::withMessages([
                'overviewTaskRevisionDocumentIds' => 'Select at least one artwork file for this revision.',
            ]);
        }

        $candidates = collect($revision['documents'] ?? [])
            ->concat(collect($revision['retained_documents'] ?? []))
            ->sortBy('id')
            ->values();
        $sourceVersion = max(1, (int) ($candidates->max('version') ?? ($revision['source_version'] ?? 0)));

        $candidateIds = $candidates->pluck('id')->map(fn ($id) => (int) $id);
        if ($requestedIds->contains(fn ($id) => ! $candidateIds->contains((int) $id))) {
            throw ValidationException::withMessages([
                'overviewTaskRevisionDocumentIds' => 'One of the selected artwork files is no longer part of the current artwork version. Refresh the Order and try again.',
            ]);
        }

        $selected = $candidates
            ->filter(fn (Document $document) => $requestedIds->contains((int) $document->id))
            ->values();

        $task->loadMissing('job');
        $activity = $task->job?->activities()
            ->whereKey((int) ($revision['activity_id'] ?? 0))
            ->where('event', 'job.artwork_revision_requested')
            ->first();

        if (! $activity) {
            throw ValidationException::withMessages([
                'overviewTaskRevisionDocumentIds' => 'The artwork revision request changed. Refresh the Order and try again.',
            ]);
        }

        $meta = is_array($activity->meta) ? $activity->meta : [];
        $meta['revision_document_ids'] = $selected->pluck('id')->map(fn ($id) => (int) $id)->all();
        $meta['revision_document_names'] = $selected->pluck('name')->values()->all();
        $meta['reference_document_id'] = (int) ($selected->last()?->id ?? 0) ?: null;
        $meta['source_artwork_version'] = $sourceVersion;
        $meta['source_artwork_document_ids'] = $candidateIds->values()->all();
        $meta['revision_selection_pending'] = false;
        $activity->update(['meta' => $meta]);

        return $this->pendingArtworkRevision($task->fresh(['job']));
    }

    /**
     * Resolve selective-revision uploads to the exact source artwork they replace.
     * New UI callers key each temporary upload by source document id. The list
     * fallback keeps older callers/tests compatible while the UI migrates.
     *
     * @param array<int|string,UploadedFile> $files
     * @param Collection<int,Document> $replacementSources
     * @return array<int,UploadedFile>|null
     */
    private function mapArtworkRevisionReplacementFiles(array $files, Collection $replacementSources): ?array
    {
        if (count($files) !== $replacementSources->count()) {
            return null;
        }

        $mapped = [];
        foreach ($replacementSources as $source) {
            $sourceId = (int) $source->id;
            if (array_key_exists($sourceId, $files)) {
                $mapped[$sourceId] = $files[$sourceId];
            }
        }

        if (count($mapped) === $replacementSources->count()) {
            return $mapped;
        }

        if (! array_is_list($files)) {
            return null;
        }

        foreach ($replacementSources->values() as $index => $source) {
            if (! array_key_exists($index, $files)) {
                return null;
            }
            $mapped[(int) $source->id] = $files[$index];
        }

        return $mapped;
    }

    /**
     * Replace only the artwork files selected in the outstanding revision.
     * Accepted/unselected artwork keeps the same Document row and version it had
     * before review. Only an actually uploaded replacement increments version.
     *
     * @param array<int|string,UploadedFile> $files
     * @return Collection<int,Document>
     */
    public function storeArtworkRevision(array $files, Task $task, User $user, ?string $note = null, string $permissionModule = 'documents'): Collection
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $task->loadMissing(['job', 'documentCategory', 'setupTemplate.documentCategory']);
        $revision = $this->pendingArtworkRevision($task);
        if (! ($revision['active'] ?? false)) {
            throw ValidationException::withMessages([
                'overviewTaskRevisionUpload' => 'There is no outstanding selective artwork revision for this task. Reopen the artwork review and try again.',
            ]);
        }

        $replacementSources = collect($revision['documents'] ?? [])->values();
        if ($replacementSources->isEmpty()) {
            throw ValidationException::withMessages([
                'overviewTaskRevisionDocumentIds' => 'Select at least one artwork file for this revision.',
            ]);
        }

        $replacementFiles = $this->mapArtworkRevisionReplacementFiles($files, $replacementSources);
        if ($replacementFiles === null) {
            throw ValidationException::withMessages([
                'overviewTaskRevisionUpload' => 'Upload one replacement directly under each artwork selected for revision.',
            ]);
        }

        return DB::transaction(function () use ($files, $task, $user, $note, $permissionModule): Collection {
            // Serialize selective revision writes on the Artwork upload task,
            // then re-read the request. This prevents two users from creating
            // competing next versions from the same revision request.
            Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $revision = $this->pendingArtworkRevision($task);
            if (! ($revision['active'] ?? false)) {
                throw ValidationException::withMessages([
                    'overviewTaskRevisionUpload' => 'This artwork revision was already completed or changed. Refresh the Order and review the latest artwork.',
                ]);
            }

            $replacementSources = collect($revision['documents'] ?? [])->values();
            if ($replacementSources->isEmpty()) {
                throw ValidationException::withMessages([
                    'overviewTaskRevisionDocumentIds' => 'The artwork revision no longer contains a replacement target. Reopen the upload dialog and select the required files.',
                ]);
            }
            $replacementFiles = $this->mapArtworkRevisionReplacementFiles($files, $replacementSources);
            if ($replacementFiles === null) {
                throw ValidationException::withMessages([
                    'overviewTaskRevisionUpload' => 'The artwork revision selection changed. Refresh the Order and upload one replacement under each current artwork.',
                ]);
            }

            $allCurrentDocuments = $replacementSources
                ->concat(collect($revision['retained_documents'] ?? []))
                ->sortBy('id')
                ->values();

            $created = collect();
            $replacedSourceIds = [];
            $retainedSourceIds = [];
            $replacementDocumentMap = [];
            $replacementVersions = [];
            $currentDocumentIds = [];

            foreach ($allCurrentDocuments as $source) {
                $sourceId = (int) $source->id;
                if (array_key_exists($sourceId, $replacementFiles)) {
                    // Version is file-specific during a selective revision. A
                    // V1 file becomes V2 even if another accepted artwork in the
                    // same current set is already V3.
                    $replacementVersion = max(1, (int) $source->version + 1);
                    $storeData = [
                        'flow_job_id' => $task->flow_job_id,
                        'client_id' => $task->job?->client_id,
                        'task_id' => $task->id,
                        'note' => $note,
                        'artwork_batch_version' => $replacementVersion,
                        'artwork_base_name' => (string) $source->name,
                    ];
                    if ($this->taskHasRequirement($task)) {
                        $storeData['require_task_pack_requirement'] = true;
                    } else {
                        $storeData['category'] = (string) ($source->category ?: 'Task attachment');
                    }

                    $replacement = $this->store($replacementFiles[$sourceId], $storeData, $user, $permissionModule, AttachmentUpload::ARTWORK_MAX_BYTES);
                    $created->push($replacement);
                    $replacedSourceIds[] = $sourceId;
                    $replacementDocumentMap[$sourceId] = (int) $replacement->id;
                    $replacementVersions[$sourceId] = (int) $replacement->version;
                    $currentDocumentIds[] = (int) $replacement->id;
                    continue;
                }

                // No upload means no revision and therefore no version change.
                // Keep the original Document row in the current set rather than
                // cloning it into a synthetic newer version.
                $retainedSourceIds[] = $sourceId;
                $currentDocumentIds[] = $sourceId;
            }

            $task->job?->activities()->create([
                'user_id' => $user->id,
                'event' => 'job.artwork_revision_applied',
                'description' => 'Artwork revision uploaded. '.count($replacedSourceIds).' file'.(count($replacedSourceIds) === 1 ? '' : 's').' replaced; '.count($retainedSourceIds).' accepted file'.(count($retainedSourceIds) === 1 ? '' : 's').' kept at the previous version.',
                'meta' => [
                    'task_id' => (int) $task->id,
                    'target_task_id' => (int) $task->id,
                    'revision_activity_id' => (int) ($revision['activity_id'] ?? 0),
                    'source_version' => (int) ($revision['source_version'] ?? 0),
                    'source_document_ids' => $allCurrentDocuments->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'replaced_source_document_ids' => $replacedSourceIds,
                    'retained_source_document_ids' => $retainedSourceIds,
                    'replacement_document_map' => $replacementDocumentMap,
                    'replacement_versions' => $replacementVersions,
                    'new_document_ids' => $created->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'current_document_ids' => $currentDocumentIds,
                ],
            ]);

            return $created->sortBy('id')->values();
        });
    }

    public function linkExisting(Document $source, Task $task, User $user, bool $allowGenericAttachment = false, ?string $note = null): Document
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        abort_unless(app(AccessControlService::class)->can($user, 'documents', 'link'), 403);
        app(AccessControlService::class)->applyDocumentScope(Document::query()->whereKey($source->id), $user)->firstOrFail();
        app(AccessControlService::class)->applyTaskScope(Task::query()->whereKey($task->id), $user)->firstOrFail();
        app(JobService::class)->findVisible($user, (int) $task->flow_job_id);
        $task->loadMissing(['job','documentCategory','setupTemplate.documentCategory']);
        if (!$allowGenericAttachment) abort_unless($this->taskHasRequirement($task), 422, 'The selected task does not define a Task Pack document requirement.');

        $existing = Document::where('task_id', $task->id)->where('path', $source->path)->first();
        if ($existing) return $existing;
        $category = $task->documentCategory?->name ?: $task->setupTemplate?->documentCategory?->name ?: 'Task attachment';
        $version = $this->nextDocumentVersion(
            $task,
            $task->flow_job_id,
            $task->id,
            $category,
            $source->name,
        );

        $isArtworkTask = app(OrderWorkflowActionService::class)->automationKey($task) === 'ART_PREPARE_UPLOAD';
        $linkedName = $isArtworkTask
            ? ArtworkDocumentName::versioned((string) $source->name, max(1, $version))
            : (string) $source->name;

        $document = Document::create([
            'document_number' => $this->nextNumber(), 'flow_job_id' => $task->flow_job_id, 'client_id' => $task->job?->client_id,
            'task_id' => $task->id, 'uploaded_by' => $user->id, 'category' => $category, 'name' => $linkedName,
            'note' => filled($note) ? trim((string) $note) : null, 'path' => $source->path, 'mime_type' => $source->mime_type, 'size' => $source->size, 'version' => max(1, $version), 'is_final' => (bool) $source->is_final,
        ]);
        $this->recordDocumentActivity($document, $user, 'linked');
        $this->notifyDocumentChange($document, $user, 'linked');
        return $document;
    }

    public function delete(Document $document, ?User $actor = null, string $permissionModule = 'documents'): void
    {
        $this->assertDocumentOrderNotHeld($document);
        if ($actor) {
            abort_unless(app(AccessControlService::class)->can($actor, $permissionModule, 'delete'), 403);
            app(AccessControlService::class)->applyDocumentScope(Document::query()->whereKey($document->id), $actor, $permissionModule)->firstOrFail();
        }
        $document->loadMissing(['task','job']);
        $name = $document->name; $path = $document->path;
        if ($actor) {
            if ($document->task) $document->setRelation('task', app(TaskService::class)->claimForAction($document->task, $actor, 'removed a document'));
            if ($document->task) $document->task->activities()->create(['user_id'=>$actor->id,'event'=>'task.document_deleted','description'=>'Document removed: '.$name,'meta'=>['document_id'=>$document->id,'name'=>$name]]);
            if ($document->job) $document->job->activities()->create(['user_id'=>$actor->id,'event'=>'job.document_deleted','description'=>'Document removed'.($document->task?' from '.$document->task->title:'').': '.$name,'meta'=>['document_id'=>$document->id,'name'=>$name,'task_id'=>$document->task_id]]);
            $this->notifyDocumentChange($document, $actor, 'removed');
        }
        $document->delete();
        if ($document->task_id && $document->flow_job_id) {
            $this->invalidateOrderWorkflowSummary((int) $document->flow_job_id);
        }
        if ($path && !Document::where('path', $path)->exists()) app(SecureDocumentStorage::class)->delete($path);
    }

    public function taskHasRequirement(Task $task): bool
    {
        return (bool) ($task->document_category_id ?: $task->setupTemplate?->document_category_id);
    }

    public function rename(Document $document, string $name, User $user, string $permissionModule = 'documents'): void
    {
        $this->assertDocumentOrderNotHeld($document);
        abort_unless(app(AccessControlService::class)->can($user, $permissionModule, 'edit'), 403);
        app(AccessControlService::class)->applyDocumentScope(Document::query()->whereKey($document->id), $user, $permissionModule)->firstOrFail();

        $name = trim($name);
        abort_if($name === '' || str_contains($name, '/') || str_contains($name, '\\'), 422, 'Enter a valid file name.');

        $document->loadMissing(['task.setupTemplate', 'job']);
        if ($document->task
            && app(OrderWorkflowActionService::class)->automationKey($document->task) === 'ART_PREPARE_UPLOAD') {
            $name = ArtworkDocumentName::versioned($name, max(1, (int) $document->version));
        }

        $oldName = (string) $document->name;
        Document::query()
            ->where('flow_job_id', $document->flow_job_id)
            ->where('task_id', $document->task_id)
            ->where('category', $document->category)
            ->where('name', $oldName)
            ->update(['name' => $name, 'updated_at' => now()]);

        // The grouped rename intentionally uses one SQL UPDATE, so Eloquent
        // model observers do not fire for the affected Document rows. Publish
        // one shared invalidation explicitly instead.
        app(WorkspaceRefreshService::class)->touch('Document:renamed');

        $document->name = $name;
        if ($document->task) {
            $document->setRelation('task', app(TaskService::class)->claimForAction($document->task, $user, 'renamed a document'));
            $document->task->activities()->create([
                'user_id' => $user->id,
                'event' => 'task.document_renamed',
                'description' => 'Document renamed from '.$oldName.' to '.$name.'.',
                'meta' => ['document_id' => $document->id, 'old_name' => $oldName, 'name' => $name],
            ]);
        }
        if ($document->job) {
            $document->job->activities()->create([
                'user_id' => $user->id,
                'event' => 'job.document_renamed',
                'description' => 'Document renamed from '.$oldName.' to '.$name.'.',
                'meta' => ['document_id' => $document->id, 'old_name' => $oldName, 'name' => $name, 'task_id' => $document->task_id],
            ]);
        }
    }

    public function storeVersion(Document $document, UploadedFile $file, User $user, string $permissionModule = 'documents'): Document
    {
        $this->assertDocumentOrderNotHeld($document);
        $access = app(AccessControlService::class);
        abort_unless($access->can($user, $permissionModule, 'create'), 403);
        $access->applyDocumentScope(Document::query()->whereKey($document->id), $user, $permissionModule)->firstOrFail();

        $document->loadMissing(['task.job', 'job']);
        if ($document->task) {
            if ($permissionModule === 'document_archive') {
                $access->applyDocumentArchiveTaskScope(Task::query()->whereKey($document->task_id), $user)->firstOrFail();
            } else {
                $access->applyTaskScope(Task::query()->whereKey($document->task_id), $user)->firstOrFail();
            }
        } elseif ($document->flow_job_id) {
            if ($permissionModule === 'document_archive') {
                $access->applyDocumentArchiveJobScope(\App\Models\FlowJob::query()->whereKey($document->flow_job_id), $user)->firstOrFail();
            } else {
                app(JobService::class)->findVisible($user, (int) $document->flow_job_id);
            }
        }

        $jobId = $document->flow_job_id ?: 'general';
        $stored = app(SecureDocumentStorage::class)->store($file, 'flowtrack/documents/'.$jobId);
        $path = $stored['path'];

        $version = $this->nextDocumentVersion(
            $document->task,
            $document->flow_job_id,
            $document->task_id,
            (string) $document->category,
            (string) $document->name,
        );

        $isArtworkTask = $document->task
            && app(OrderWorkflowActionService::class)->automationKey($document->task) === 'ART_PREPARE_UPLOAD';
        $versionedName = $isArtworkTask
            ? ArtworkDocumentName::versioned((string) $document->name, max(1, $version), $file->getClientOriginalName())
            : (string) $document->name;

        $created = Document::create([
            'document_number' => $this->nextNumber(),
            'flow_job_id' => $document->flow_job_id,
            'client_id' => $document->client_id,
            'task_id' => $document->task_id,
            'uploaded_by' => $user->id,
            'category' => $document->category,
            'name' => $versionedName,
            'note' => $document->note,
            'path' => $path,
            'mime_type' => StoredFileResponse::mimeType($versionedName, $stored['mime']),
            'size' => $stored['size'],
            'version' => max(1, $version),
            'is_final' => false,
        ]);

        $this->recordDocumentActivity($created, $user, 'uploaded');
        $this->notifyDocumentChange($created, $user, 'uploaded');

        return $created;
    }

    public function versions(Document $document, User $user, string $permissionModule = 'documents')
    {
        $document->loadMissing('task.setupTemplate');

        $query = app(AccessControlService::class)->applyDocumentScope(Document::query(), $user, $permissionModule)
            ->with('uploader')
            ->where('flow_job_id', $document->flow_job_id)
            ->where('task_id', $document->task_id)
            ->where('category', $document->category);

        // Artwork revisions may be uploaded with completely different original
        // filenames. They still belong to one version history, so show every
        // artwork upload for the same Artwork task. Other document types keep
        // the existing filename-based version grouping.
        $isArtworkTask = $document->task
            && app(OrderWorkflowActionService::class)->automationKey($document->task) === 'ART_PREPARE_UPLOAD';

        if (! $isArtworkTask) {
            $query->where('name', $document->name);
        }

        return $query
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get();
    }

    private function nextDocumentVersion(?Task $task, mixed $flowJobId, mixed $taskId, string $category, string $name): int
    {
        $query = Document::query()
            ->where('flow_job_id', $flowJobId)
            ->where('task_id', $taskId)
            ->where('category', $category);

        // Artwork revisions are one continuous version history even when the
        // designer uploads a revised file with a different original filename.
        // Other document categories keep the existing filename-based version
        // grouping so this change is isolated to the Artwork upload task.
        $isArtworkTask = $task
            && app(OrderWorkflowActionService::class)->automationKey($task) === 'ART_PREPARE_UPLOAD';

        if (! $isArtworkTask) {
            $query->where('name', $name);
        }

        return max(1, ((int) $query->max('version')) + 1);
    }

    private function recordDocumentActivity(Document $document, User $user, string $action): void
    {
        $document->loadMissing(['task','job']);
        $verb = $action === 'linked' ? 'linked' : 'uploaded';
        if ($document->task) $document->setRelation('task', app(TaskService::class)->claimForAction($document->task, $user, $verb.' a document'));
        if ($document->task) $document->task->activities()->create(['user_id'=>$user->id,'event'=>'task.document_'.$verb,'description'=>'Document '.$verb.': '.$document->name,'meta'=>['document_id'=>$document->id,'name'=>$document->name]]);
        if ($document->job) $document->job->activities()->create(['user_id'=>$user->id,'event'=>'job.document_'.$verb,'description'=>'Document '.$verb.($document->task?' to '.$document->task->title:'').': '.$document->name,'meta'=>['document_id'=>$document->id,'name'=>$document->name,'task_id'=>$document->task_id]]);
        if ($document->task_id && $document->flow_job_id) {
            $this->invalidateOrderWorkflowSummary((int) $document->flow_job_id);
        }
    }

    /**
     * A task document can activate a conditional Order task. Deduplicate the
     * tiny summary invalidation within this service instance so multi-file
     * artwork uploads do not issue one summary UPDATE per file.
     */
    private function invalidateOrderWorkflowSummary(int $orderId): void
    {
        if ($orderId <= 0 || isset($this->workflowSummaryInvalidatedOrders[$orderId])) return;

        app(\App\Services\Orders\OrderWorkflowSummaryService::class)->markStale($orderId);
        $this->workflowSummaryInvalidatedOrders[$orderId] = true;
    }

    private function notifyDocumentChange(Document $document, User $actor, string $action): void
    {
        $document->loadMissing(['task.job','job']);
        $message = 'Document '.$action.': '.$document->name;
        if ($document->task) {
            app(NotificationService::class)->notifyTaskParticipants(
                $document->task,
                'Document '.$action.' on '.$document->task->title,
                $message,
                'update',
                $actor,
            );
            return;
        }
        if ($document->job) {
            app(NotificationService::class)->notifyJobParticipants(
                $document->job,
                'Job document '.$action,
                $document->job->job_number.' · '.$message,
                'update',
                $actor,
            );
        }
    }

    private function nextNumber(): string
    {
        return 'DOC-'.str_pad((string) ((int) Document::max('id') + 1), 6, '0', STR_PAD_LEFT);
    }

    private function assertDocumentOrderNotHeld(Document $document): void
    {
        $jobId = $document->flow_job_id ?: $document->task()->value('flow_job_id');
        if ($jobId) {
            app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $jobId);
        }
    }

}
