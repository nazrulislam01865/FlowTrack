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

        $removedTaskPackItemIds = FlowTask::withTrashed()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $phase->id)
            ->whereNotNull('task_pack_item_id')
            ->whereNotNull('removed_from_job_at')
            ->pluck('task_pack_item_id')
            ->map(fn ($id) => (int) $id);

        return $this->requirementsForPhase($phase, $removedTaskPackItemIds);
    }

    /**
     * Return requirements for every active Workflow Phase that belongs to the Job.
     * The Job Documents page uses this collection so required documents remain
     * visible before and after a phase becomes current.
     */
    public function allRequirements(FlowJob $job): Collection
    {
        if (! $job->workflow_template_id) {
            return collect();
        }

        $excludedPhaseIds = $job->excludedPhases()
            ->pluck('workflow_phase_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $phases = WorkflowPhase::query()
            ->where('workflow_template_id', $job->workflow_template_id)
            ->where('is_active', true)
            ->when($excludedPhaseIds !== [], fn ($query) => $query->whereNotIn('id', $excludedPhaseIds))
            ->with(['documentCategory', 'taskPack.items.documentCategory'])
            ->orderBy('sequence')
            ->get();

        if ($phases->isEmpty()) {
            return collect();
        }

        $removedByPhase = FlowTask::withTrashed()
            ->where('flow_job_id', $job->id)
            ->whereIn('workflow_phase_id', $phases->pluck('id'))
            ->whereNotNull('task_pack_item_id')
            ->whereNotNull('removed_from_job_at')
            ->get(['workflow_phase_id', 'task_pack_item_id'])
            ->groupBy(fn (FlowTask $task) => (int) $task->workflow_phase_id)
            ->map(fn (Collection $tasks) => $tasks->pluck('task_pack_item_id')->map(fn ($id) => (int) $id)->values());

        return $phases
            ->flatMap(fn (WorkflowPhase $phase) => $this->requirementsForPhase(
                $phase,
                $removedByPhase->get((int) $phase->id, collect()),
            ))
            ->unique('requirement_key')
            ->values();
    }

    private function requirementsForPhase(WorkflowPhase $phase, Collection $removedTaskPackItemIds): Collection
    {
        $requirements = collect();

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
        $requirements = $this->allRequirements($job);

        if ($requirements->isEmpty()) {
            throw ValidationException::withMessages([
                'document_category_id' => 'No document upload requirement is configured for this Job workflow.',
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
                    ? 'Select a document requirement configured for this Job workflow or Task Pack.'
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
        if ($requirement['task_pack_item_id'] !== null) {
            $task = FlowTask::query()
                ->with(['packItem.documentCategory', 'documentCategory', 'phase'])
                ->where('flow_job_id', $job->id)
                ->where('workflow_phase_id', $requirement['workflow_phase_id'])
                ->where('task_pack_item_id', $requirement['task_pack_item_id'])
                ->whereNull('removed_from_job_at')
                ->first();

            return $task ? $this->isTaskRequirementSatisfied($task) : false;
        }

        // A Workflow-Phase-level requirement is a separate evidence slot. A
        // document linked to a Task in the same phase/category must not satisfy it.
        return $job->documents()
            ->where('workflow_phase_id', $requirement['workflow_phase_id'])
            ->where('document_category_id', $requirement['category_id'])
            ->whereNull('task_pack_item_id')
            ->whereNull('flow_task_id')
            ->exists();
    }

    /**
     * Resolve evidence for an explicitly configured Task document requirement.
     *
     * New uploads are linked by exact Flow Task ID. For older documents created
     * before that link existed, we safely repair the link only when the target
     * is unambiguous. This keeps the Documents page and Task completion gate in
     * sync without allowing another Task's similarly named/category file to pass.
     */
    public function isTaskRequirementSatisfied(FlowTask $task): bool
    {
        $task->loadMissing(['job', 'phase', 'packItem.documentCategory', 'documentCategory']);
        $categoryId = (int) ($task->document_category_id ?: $task->packItem?->document_category_id ?: 0);
        if (! $task->job || ! $task->workflow_phase_id || ! $categoryId) {
            return false;
        }

        $base = $task->job->documents()
            ->where('workflow_phase_id', $task->workflow_phase_id)
            ->where('document_category_id', $categoryId);

        if ((clone $base)->where('flow_task_id', $task->id)->exists()) {
            return true;
        }

        // Older Task-Pack uploads already carried the exact Task Pack item but
        // predated flow_task_id. That identifier is sufficient to repair safely.
        if ($task->task_pack_item_id) {
            $legacyPackDocuments = (clone $base)
                ->where('task_pack_item_id', $task->task_pack_item_id)
                ->whereNull('flow_task_id');

            if ((clone $legacyPackDocuments)->exists()) {
                $legacyPackDocuments->update(['flow_task_id' => $task->id]);
                return true;
            }
        }

        // A generic legacy phase/category document can be adopted only if there
        // is exactly one configured Task requirement for that category and the
        // Workflow Phase itself does not reserve the same category separately.
        $phaseHasSameRequirement = WorkflowPhase::query()
            ->whereKey($task->workflow_phase_id)
            ->where('document_category_id', $categoryId)
            ->exists();
        if ($phaseHasSameRequirement) {
            return false;
        }

        $candidateIds = FlowTask::query()
            ->where('flow_job_id', $task->flow_job_id)
            ->where('workflow_phase_id', $task->workflow_phase_id)
            ->whereNull('removed_from_job_at')
            ->whereNotNull('task_pack_item_id')
            ->where(function ($query) use ($categoryId): void {
                $query->where('document_category_id', $categoryId)
                    ->orWhereHas('packItem', fn ($packItem) => $packItem->where('document_category_id', $categoryId));
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($candidateIds->count() !== 1 || (int) $candidateIds->first() !== (int) $task->id) {
            return false;
        }

        $legacyGenericDocuments = (clone $base)
            ->whereNull('flow_task_id')
            ->whereNull('task_pack_item_id');
        if (! (clone $legacyGenericDocuments)->exists()) {
            return false;
        }

        $legacyGenericDocuments->update([
            'flow_task_id' => $task->id,
            'task_pack_item_id' => $task->task_pack_item_id,
        ]);

        return true;
    }
}
