<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobPhaseHistory;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;

class PhaseReadinessEvaluator
{
    public function __construct(
        private readonly ActivityLogger $activities,
        private readonly TransitionAuditLogger $transitions,
        private readonly JobDocumentRequirementService $documentRequirements,
    ) {}

    public function evaluate(FlowJob $job, WorkflowPhase $phase): array
    {
        $phase->loadMissing('taskPack.items', 'documentCategory');

        $requiredIds = $phase->taskPack && $phase->taskPack->is_active
            ? $phase->taskPack->items->where('is_required', true)->pluck('id')->values()
            : collect();

        $generatedIds = $requiredIds->isEmpty()
            ? collect()
            : $job->tasks()
                ->where('workflow_phase_id', $phase->id)
                ->whereIn('task_pack_item_id', $requiredIds)
                ->pluck('task_pack_item_id')
                ->unique();

        $completedIds = $requiredIds->isEmpty()
            ? collect()
            : $job->tasks()
                ->where('workflow_phase_id', $phase->id)
                ->whereIn('task_pack_item_id', $requiredIds)
                ->where(function ($query) {
                    $query->whereNotNull('completed_at')
                        ->orWhereHas('status', fn ($status) => $status->where(function ($configuredStatus) {
                            $configuredStatus->where('code', 'CMP')
                                ->orWhereRaw('lower(name) in (?, ?, ?)', ['completed', 'complete', 'done']);
                        }));
                })
                ->pluck('task_pack_item_id')
                ->unique();

        $missingRequired = $requiredIds->diff($generatedIds)->values();
        $taskGateMet = $missingRequired->isEmpty()
            && $completedIds->count() === $requiredIds->unique()->count();
        $requiredDocuments = $this->documentRequirements->readinessRequirements($job, $phase);
        $missingDocuments = $requiredDocuments
            ->reject(fn (array $requirement): bool => $this->documentRequirements->isSatisfied($job, $requirement))
            ->values();
        $documentGateMet = $missingDocuments->isEmpty();

        $blockingTask = $job->tasks()
            ->where('workflow_phase_id', $phase->id)
            ->where(function ($query) {
                $query->where('attention_required', true)
                    ->orWhereNotNull('blocker_reason')
                    ->orWhereHas('status', fn ($status) => $status->where(function ($configuredStatus) {
                        $configuredStatus->whereIn('code', ['BLK', 'ATTN', 'HOLD'])
                            ->orWhereRaw('lower(name) in (?, ?, ?)', ['blocked', 'attention', 'on hold']);
                    }));
            })
            ->exists();

        $ready = $taskGateMet && $documentGateMet && ! $blockingTask;
        $blockers = collect()
            ->when($missingRequired->isNotEmpty(), fn ($items) => $items->push('Required Task Pack tasks are being generated. Refresh and complete them.'))
            ->when($missingRequired->isEmpty() && ! $taskGateMet, fn ($items) => $items->push('Complete all required phase tasks.'))
            ->when(! $documentGateMet, function ($items) use ($missingDocuments) {
                foreach ($missingDocuments as $requirement) {
                    $items->push('Upload the required '.$requirement['category_name'].' document.');
                }

                return $items;
            })
            ->when($blockingTask, fn ($items) => $items->push('Resolve active task blockers or attention flags.'))
            ->values()
            ->all();

        return [
            'ready' => $ready,
            'status' => $ready ? 'ready' : ($blockingTask ? 'blocked' : 'in_progress'),
            'task_gate' => [
                'required' => $requiredIds->count(),
                'generated' => $generatedIds->count(),
                'completed' => $completedIds->count(),
                'missing' => $missingRequired->count(),
                'met' => $taskGateMet,
            ],
            'document_gate' => [
                'required' => $requiredDocuments->isNotEmpty(),
                'category_id' => $requiredDocuments->count() === 1 ? $requiredDocuments->first()['category_id'] : null,
                'categories' => $requiredDocuments->values()->all(),
                'missing' => $missingDocuments->values()->all(),
                'met' => $documentGateMet,
            ],
            'has_blocking_task' => $blockingTask,
            'blockers' => $blockers,
        ];
    }

    public function synchronize(FlowJob $job, WorkflowPhase $phase, ?User $actor = null, string $source = 'system'): array
    {
        $result = $this->evaluate($job, $phase);
        $history = FlowJobPhaseHistory::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $phase->id)
            ->first();

        if (! $history) {
            return $result;
        }

        $previous = (string) ($history->readiness_status ?: 'pending');
        $newStatus = $history->status === 'current' ? $result['status'] : $previous;

        $history->forceFill([
            'readiness_status' => $newStatus,
            'ready_at' => $newStatus === 'ready' ? ($history->ready_at ?: now()) : null,
            'readiness_checked_at' => now(),
            'readiness_details' => $result,
        ])->saveQuietly();

        if ($previous !== $newStatus) {
            $this->activities->log(
                $job->workspace_id,
                $actor,
                $job,
                'job.phase_readiness_changed',
                "{$phase->name} readiness changed from {$previous} to {$newStatus}",
                ['phase_id' => $phase->id, 'from' => $previous, 'to' => $newStatus, 'details' => $result, 'source' => $source],
            );
            $this->transitions->log($job, 'phase.readiness', $actor, $source, $previous, $newStatus, implode(' ', $result['blockers']), $result, phase: $phase);
        }

        return $result;
    }
}
