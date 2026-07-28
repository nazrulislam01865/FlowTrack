<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use DomainException;

class PhaseGateValidator
{
    public function assertWorkComplete(FlowJob $job, WorkflowPhase $phase): void
    {
        $phase->loadMissing('taskPack.items', 'documentCategory');
        $requiredItemIds = $phase->taskPack?->items
            ->where('is_required', true)
            ->pluck('id')
            ->values() ?? collect();

        if ($requiredItemIds->isNotEmpty()) {
            $completedItemIds = $job->tasks()
                ->where('workflow_phase_id', $phase->id)
                ->whereIn('task_pack_item_id', $requiredItemIds)
                ->whereNotNull('completed_at')
                ->pluck('task_pack_item_id');

            if ($completedItemIds->unique()->count() !== $requiredItemIds->unique()->count()) {
                throw new DomainException("Complete every required {$phase->name} task before continuing.");
            }
        }

        if ($phase->document_category_id && ! $job->documents()->where('document_category_id', $phase->document_category_id)->exists()) {
            $category = $phase->documentCategory?->name ?: 'phase';
            throw new DomainException("Upload the required {$category} document before continuing.");
        }
    }

    public function assertApproved(FlowJob $job, WorkflowPhase $phase): void
    {
        if ($phase->requires_approval && ! $job->phaseApprovals()->where('workflow_phase_id', $phase->id)->where('status', 'approved')->exists()) {
            throw new DomainException("Approve the {$phase->name} phase before advancing the Job.");
        }
    }
}
