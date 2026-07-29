<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\DocumentWorkflowLinker;
use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Business\Services\JobDocumentRequirementService;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UploadDocumentAction
{
    private array $lastWorkflowResult = [];

    public function __construct(
        private readonly IdentifierGenerator $identifiers,
        private readonly ActivityLogger $activityLogger,
        private readonly DocumentWorkflowLinker $workflowLinker,
        private readonly JobDocumentRequirementService $documentRequirements,
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
        $requirement = $this->documentRequirements->resolveUploadRequirement(
            $job,
            $effectiveCategoryId,
            $effectivePhaseId,
            $effectiveTaskPackItemId,
        );

        $document = DB::transaction(function () use ($job, $file, $isFinal, $actor, $existingDocument, $notes, $requirement): FlowDocument {
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
            $path = $file->store("flowtrack/{$job->code}/documents/{$document->code}", config('filesystems.default'));

            $document->versions()->create([
                'uploaded_by' => $actor->id,
                'version' => $version,
                'disk' => config('filesystems.default'),
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

        $this->lastWorkflowResult = $this->workflowLinker->synchronize($job, $actor, $advanceWhenReady);

        return $document->fresh(['category', 'phase', 'taskPackItem', 'versions.uploader']);
    }

    public function workflowResult(): array
    {
        return $this->lastWorkflowResult;
    }
}
