<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use DomainException;

class PhaseGateValidator
{
    public function __construct(
        private readonly PhaseReadinessEvaluator $readiness,
        private readonly TaskPackActivator $taskPackActivator,
    ) {}

    public function assertWorkComplete(FlowJob $job, WorkflowPhase $phase): void
    {
        // A Task Pack can be assigned or expanded after a Job has already entered
        // the Phase. Reconcile first so the gate never checks invisible/missing Tasks.
        $this->taskPackActivator->activate($job, $phase);
        $result = $this->readiness->evaluate($job, $phase);

        if (! $result['task_gate']['met']) {
            $missing = (int) ($result['task_gate']['missing'] ?? 0);
            $remaining = max(0, (int) $result['task_gate']['required'] - (int) $result['task_gate']['completed']);

            if ($missing > 0) {
                throw new DomainException("{$missing} required {$phase->name} task(s) were missing and have now been generated. Complete them before continuing.");
            }

            throw new DomainException("Complete the remaining {$remaining} required {$phase->name} task(s) before continuing.");
        }

        if (! $result['document_gate']['met']) {
            $missing = collect($result['document_gate']['missing'] ?? [])
                ->map(fn (array $requirement): string => $requirement['category_name'].(! empty($requirement['source_label']) ? " ({$requirement['source_label']})" : ''))
                ->join(', ');
            throw new DomainException('Upload and link the required '.($missing ?: 'phase document').' from the Documents page before continuing.');
        }

        if ($result['has_blocking_task']) {
            throw new DomainException("Resolve active {$phase->name} task blockers before continuing.");
        }
    }

}
