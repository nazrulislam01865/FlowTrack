<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\DocumentWorkflowLinker;
use App\Modules\OrderFlow\Business\Services\FlowNotificationService;
use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Business\Services\JobDocumentRequirementService;
use App\Modules\OrderFlow\Business\Services\TaskPackActivator;
use App\Modules\OrderFlow\Business\Services\TransitionAuditLogger;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use Illuminate\Validation\ValidationException;

class UploadDocumentAction
{
    private array $lastWorkflowResult = [];

    public function __construct(
        private readonly IdentifierGenerator $identifiers,
        private readonly ActivityLogger $activityLogger,
        private readonly DocumentWorkflowLinker $workflowLinker,
        private readonly JobDocumentRequirementService $documentRequirements,
        private readonly TaskPackActivator $taskPackActivator,
        private readonly FlowNotificationService $notifications,
        private readonly TransitionAuditLogger $transitions,
    ) {}

    public function execute(
        FlowJob $job,
        UploadedFile $file,
        ?int $categoryId,
        bool $isFinal,
        User $actor,
        ?int $documentId = null,
        ?string $notes = null,
        ?int $workflowPhaseId = null,
        ?int $taskPackItemId = null,
        bool $advanceWhenReady = false,
        ?int $taskId = null,
    ): FlowDocument {
        $existingDocument = $documentId
            ? FlowDocument::query()->where('flow_job_id', $job->id)->findOrFail($documentId)
            : null;

        if ($existingDocument && $categoryId && (int) $categoryId !== (int) $existingDocument->document_category_id) {
            throw ValidationException::withMessages([
                'document_category_id' => 'A document category cannot be changed when uploading a new version.',
            ]);
        }

        $effectiveCategoryId = $existingDocument?->document_category_id ?: $categoryId;
        $effectivePhaseId = $existingDocument?->workflow_phase_id ?: $workflowPhaseId;
        $effectiveTaskPackItemId = $existingDocument?->task_pack_item_id ?: $taskPackItemId;

        $task = $taskId
            ? FlowTask::query()->with('phase')->where('flow_job_id', $job->id)->findOrFail($taskId)
            : null;

        if ($task) {
            if ((int) $task->workflow_phase_id !== (int) $job->current_phase_id) {
                throw ValidationException::withMessages([
                    'workflow_phase_id' => 'Task documents can only be uploaded while the Task is in the Job’s current Workflow Phase.',
                ]);
            }

            $category = MasterRecord::query()
                ->where('workspace_id', $job->workspace_id)
                ->where('type', MasterDataType::DocumentCategory->value)
                ->where('status', 'active')
                ->find($effectiveCategoryId);

            if (! $category) {
                throw ValidationException::withMessages([
                    'document_category_id' => 'Choose an active document category for this Task attachment.',
                ]);
            }

            if ($existingDocument && $task->task_pack_item_id
                && (int) ($existingDocument->task_pack_item_id ?? 0) !== (int) $task->task_pack_item_id) {
                throw ValidationException::withMessages([
                    'document_id' => 'The selected document is not linked to this Task.',
                ]);
            }

            $requirement = [
                'requirement_key' => "task-attachment:{$task->id}:{$category->id}",
                'workflow_phase_id' => (int) $task->workflow_phase_id,
                'workflow_phase_name' => $task->phase?->name ?? 'Current Phase',
                'task_pack_item_id' => $task->task_pack_item_id ? (int) $task->task_pack_item_id : null,
                'category_id' => (int) $category->id,
                'category_name' => $category->name,
                'source' => 'task_attachment',
                'source_label' => "Task: {$task->title}",
                'required_for_readiness' => false,
            ];
        } else {
            $requirement = $this->documentRequirements->resolveUploadRequirement(
                $job,
                $effectiveCategoryId,
                $effectivePhaseId,
                $effectiveTaskPackItemId,
            );
        }

        $storedFile = null;

        try {
            $document = DB::transaction(function () use ($job, $file, $isFinal, $actor, $existingDocument, $notes, $requirement, &$storedFile): FlowDocument {
            $document = $existingDocument
                ?: FlowDocument::create([
                    'workspace_id' => $job->workspace_id,
                    'flow_job_id' => $job->id,
                    'workflow_phase_id' => $requirement['workflow_phase_id'],
                    'task_pack_item_id' => $requirement['task_pack_item_id'],
                    'document_category_id' => $requirement['category_id'],
                    'uploaded_by' => $actor->id,
                    'linked_by' => $actor->id,
                    'linked_at' => now(),
                    'code' => $this->identifiers->document(),
                    'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'is_final' => $isFinal,
                ]);

            $version = ((int) $document->versions()->max('version')) + 1;
            $disk = config('filesystems.default');
            $path = $file->store("flowtrack/{$job->code}/documents/{$document->code}", $disk);
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('The uploaded document could not be stored.');
            }
            $storedFile = ['disk' => $disk, 'path' => $path];

            $document->versions()->create([
                'uploaded_by' => $actor->id,
                'version' => $version,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'notes' => $notes,
            ]);

            $document->update([
                'is_final' => $isFinal,
                'document_category_id' => $requirement['category_id'],
                'workflow_phase_id' => $requirement['workflow_phase_id'],
                'task_pack_item_id' => $requirement['task_pack_item_id'],
                'linked_by' => $actor->id,
                'linked_at' => now(),
            ]);

            $this->activityLogger->log(
                $job->workspace_id,
                $actor,
                $document,
                'document.uploaded',
                "{$file->getClientOriginalName()} uploaded and linked to {$requirement['workflow_phase_name']}",
                [
                    'version' => $version,
                    'workflow_phase_id' => $requirement['workflow_phase_id'],
                    'task_pack_item_id' => $requirement['task_pack_item_id'],
                    'requirement_key' => $requirement['requirement_key'],
                ],
            );

                return $document;
            });
        } catch (Throwable $exception) {
            if ($storedFile) {
                Storage::disk($storedFile['disk'])->delete($storedFile['path']);
            }

            throw $exception;
        }

        // The Documents page can be used before the Task Board has been opened.
        // Reconcile the current Phase first so its required-document Task exists
        // and can be completed by this upload in the same request.
        $job->loadMissing('currentPhase');
        if ($job->currentPhase && (int) $job->current_phase_id === (int) $requirement['workflow_phase_id']) {
            $this->taskPackActivator->activate($job, $job->currentPhase, $actor, 'document_upload');
        }

        $this->completeMatchingDocumentTasks($job, $requirement, $actor, $file->getClientOriginalName());
        $this->lastWorkflowResult = $this->workflowLinker->synchronize($job->fresh(), $actor, $advanceWhenReady);
        $this->notifications->documentUploaded(
            $document->id,
            $actor->id,
            (bool) ($this->lastWorkflowResult['moved'] ?? false),
        );

        return $document->fresh(['category', 'phase', 'taskPackItem', 'versions.uploader']);
    }

    public function workflowResult(): array
    {
        return $this->lastWorkflowResult;
    }

    /**
     * A required-document Task is completed by the successful upload itself.
     * This keeps the Task Board, phase readiness and audit history in sync.
     */
    private function completeMatchingDocumentTasks(FlowJob $job, array $requirement, User $actor, string $fileName): void
    {
        $completedStatus = MasterRecord::query()
            ->where('workspace_id', $job->workspace_id)
            ->where('type', MasterDataType::TaskStatus->value)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereIn('code', ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'])
                    ->orWhereRaw('lower(name) in (?, ?, ?)', ['completed', 'complete', 'done']);
            })
            ->orderByRaw("case when upper(code) = 'CMP' then 1 else 2 end")
            ->first();

        if (! $completedStatus) {
            return;
        }

        $tasks = FlowTask::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $requirement['workflow_phase_id'])
            ->when(
                $requirement['task_pack_item_id'] !== null,
                fn ($query) => $query->where('task_pack_item_id', $requirement['task_pack_item_id']),
                fn ($query) => $query
                    ->whereNull('task_pack_item_id')
                    ->where('document_category_id', $requirement['category_id'])
                    ->where('document_requirement_source', 'workflow_phase'),
            )
            ->whereNull('completed_at')
            ->with(['job', 'phase', 'status'])
            ->get();

        foreach ($tasks as $task) {
            $previousStatus = $task->status?->name ?: 'Not Started';
            $previousProgress = (int) $task->progress;
            $task->forceFill([
                'task_status_id' => $completedStatus->id,
                'progress' => 100,
                'completed_at' => now(),
                'attention_required' => false,
                'blocker_reason' => null,
            ])->save();

            $this->activityLogger->log(
                $job->workspace_id,
                $actor,
                $task,
                'task.document_uploaded',
                "Task {$task->code} completed by uploading {$fileName}",
                [
                    'changes' => [
                        ['field' => 'Status', 'from' => $previousStatus, 'to' => $completedStatus->name],
                        ['field' => 'Progress', 'from' => $previousProgress.'%', 'to' => '100%'],
                    ],
                    'document_requirement' => $requirement['requirement_key'],
                    'file_name' => $fileName,
                ],
            );

            $this->transitions->log(
                $job,
                'task.status',
                $actor,
                'document_upload',
                $previousStatus,
                $completedStatus->name,
                "Required document {$fileName} uploaded.",
                ['document_requirement' => $requirement['requirement_key']],
                task: $task,
                phase: $task->phase,
            );

            $this->notifications->taskChanged($task->id, $actor->id, $task->assignee_id, $previousStatus);
        }
    }
}
