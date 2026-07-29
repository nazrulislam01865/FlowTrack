<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class JobDocumentRequirementService
{
    /**
     * Return every document requirement configured for the selected Phase.
     * Requirements are linked to the actual Workflow Phase and, when relevant,
     * the exact Task Pack item that requested the document.
     */
    public function requirements(FlowJob $job, ?WorkflowPhase $phase = null): Collection
    {
        $phase ??= $job->currentPhase;
        if (! $phase) {
            return collect();
        }

        $phase = WorkflowPhase::query()
            ->whereKey($phase->id)
            ->where('workflow_template_id', $job->workflow_template_id)
            ->with(['documentCategory', 'taskPack.items.documentCategory'])
            ->first();

        if (! $phase) {
            return collect();
        }

        $requirements = collect();
        $removedTaskPackItemIds = FlowTask::withTrashed()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $phase->id)
            ->whereNotNull('task_pack_item_id')
            ->whereNotNull('removed_from_job_at')
            ->pluck('task_pack_item_id')
            ->map(fn ($id) => (int) $id);

        if ($phase->document_category_id && $phase->documentCategory) {
            $requirements->push([
                'requirement_key' => "phase:{$phase->id}:{$phase->document_category_id}",
                'workflow_phase_id' => (int) $phase->id,
                'workflow_phase_name' => $phase->name,
                'task_pack_item_id' => null,
                'category_id' => (int) $phase->document_category_id,
                'category_name' => $phase->documentCategory->name,
                'source' => 'workflow_phase',
                'source_label' => "Workflow Phase: {$phase->name}",
                'sources' => ["Workflow Phase: {$phase->name}"],
                'required_for_readiness' => true,
            ]);
        }

        if ($phase->taskPack?->is_active) {
            foreach ($phase->taskPack->items as $item) {
                if ($removedTaskPackItemIds->contains((int) $item->id)) {
                    continue;
                }

                if (! $item->document_category_id || ! $item->documentCategory) {
                    continue;
                }

                $requirements->push([
                    'requirement_key' => "task:{$item->id}:{$item->document_category_id}",
                    'workflow_phase_id' => (int) $phase->id,
                    'workflow_phase_name' => $phase->name,
                    'task_pack_item_id' => (int) $item->id,
                    'category_id' => (int) $item->document_category_id,
                    'category_name' => $item->documentCategory->name,
                    'source' => 'task_pack_item',
                    'source_label' => "Task: {$item->title}",
                    'sources' => ["Task: {$item->title}"],
                    'required_for_readiness' => (bool) $item->is_required,
                ]);
            }
        }

        return $requirements
            ->unique('requirement_key')
            ->values();
    }

    /**
     * Resolve and validate the exact requirement selected from the Documents page.
     */
    public function resolveUploadRequirement(
        FlowJob $job,
        ?int $categoryId,
        ?int $workflowPhaseId = null,
        ?int $taskPackItemId = null,
    ): array {
        $requirements = $this->requirements($job);

        if ($requirements->isEmpty()) {
            throw ValidationException::withMessages([
                'document_category_id' => 'No document upload is required for the current Job phase.',
            ]);
        }

        if ($workflowPhaseId !== null && (int) $workflowPhaseId !== (int) $job->current_phase_id) {
            throw ValidationException::withMessages([
                'workflow_phase_id' => 'Documents can only be linked to the Job’s current Workflow Phase.',
            ]);
        }

        $matches = $requirements->filter(function (array $requirement) use ($categoryId, $workflowPhaseId, $taskPackItemId): bool {
            if (! $categoryId || (int) $requirement['category_id'] !== (int) $categoryId) {
                return false;
            }
            if ($workflowPhaseId !== null && (int) $requirement['workflow_phase_id'] !== (int) $workflowPhaseId) {
                return false;
            }
            if ($taskPackItemId !== null && (int) ($requirement['task_pack_item_id'] ?? 0) !== (int) $taskPackItemId) {
                return false;
            }

            return true;
        })->values();

        if ($matches->count() !== 1) {
            throw ValidationException::withMessages([
                'document_requirement' => $matches->isEmpty()
                    ? 'Select a document requirement configured for this Job’s current Workflow Phase or Task Pack.'
                    : 'Select the exact Workflow Phase or Task Pack document requirement.',
            ]);
        }

        return $matches->first();
    }

    public function assertUploadAllowed(FlowJob $job, ?int $categoryId, ?int $workflowPhaseId = null, ?int $taskPackItemId = null): void
    {
        $this->resolveUploadRequirement($job, $categoryId, $workflowPhaseId, $taskPackItemId);
    }

    public function readinessRequirements(FlowJob $job, ?WorkflowPhase $phase = null): Collection
    {
        return $this->requirements($job, $phase)
            ->where('required_for_readiness', true)
            ->values();
    }

    public function isSatisfied(FlowJob $job, array $requirement): bool
    {
        return $job->documents()
            ->where('workflow_phase_id', $requirement['workflow_phase_id'])
            ->where('document_category_id', $requirement['category_id'])
            ->when(
                $requirement['task_pack_item_id'] !== null,
                fn ($query) => $query->where('task_pack_item_id', $requirement['task_pack_item_id']),
                fn ($query) => $query->whereNull('task_pack_item_id'),
            )
            ->exists();
    }
}
