@props(['job', 'summary' => []])
@php
    $stageCount = max(1, (int) ($summary['stage_count'] ?? 1));
    $currentPhaseNumber = max(1, (int) ($summary['current_phase_sequence'] ?? ($job->phase?->sequence ?? 1)));
    $completedTasks = max(0, (int) ($summary['current_completed_task_count'] ?? 0));
    $applicableCount = max(0, (int) ($summary['current_applicable_task_count'] ?? 0));
    $progress = max(0, min(100, (int) ($summary['progress_percent'] ?? ($job->progress ?? 0))));
    $nextTaskId = (int) ($summary['next_task_id'] ?? 0);
    $nextTaskTitle = trim((string) ($summary['next_task_title'] ?? ''));
    $nextOwner = trim((string) ($summary['next_task_assignee_name'] ?? '')) ?: ($job->owner?->name ?: 'Unassigned');
    $dependency = $currentPhaseNumber <= 1 ? 'No dependency' : 'Previous stage complete';
@endphp
<section class="summary-grid ft-order-summary-grid" aria-label="Order workflow summary">
    <div class="summary-card ft-order-summary-card">
        <div class="summary-ic">▣</div>
        <div>
            <div class="summary-label">Current stage</div>
            <div class="summary-value">
                <span>{{ $summary['current_phase_name'] ?? ($job->phase?->name ?: 'Not configured') }}</span>
                · Stage <span>{{ $currentPhaseNumber }}</span> of {{ $stageCount }}
            </div>
            <div class="summary-sub">{{ $completedTasks }} of {{ $applicableCount }} applicable tasks complete</div>
        </div>
    </div>

    <div class="summary-card ft-order-summary-card">
        <div class="summary-ic">↗</div>
        <div>
            <div class="summary-label">Overall progress</div>
            <div class="summary-value">{{ $progress }}%</div>
            <div class="overall-progress ft-order-overall-progress"><i style="width:{{ $progress }}%"></i></div>
            <div class="summary-sub"><span class="status-pill">{{ $job->status ?: 'New' }}</span></div>
        </div>
    </div>

    <div class="summary-card next-summary ft-order-summary-card next">
        <div class="summary-ic">⌘</div>
        <div>
            <div class="summary-label"><span class="help" title="The next unlocked task from this Order's saved workflow setup.">Next required action</span></div>
            <div class="summary-value">{{ $nextTaskTitle !== '' ? $nextTaskTitle : ($job->completed_at ? 'Order completed' : 'No action available') }}</div>
            <div class="summary-sub"><span>{{ $nextOwner }}</span> · <span>{{ $dependency }}</span></div>
            @if($nextTaskId > 0)
                <button type="button" class="btn primary small summary-cta" wire:click="openTask({{ $nextTaskId }})">Take action</button>
            @endif
        </div>
    </div>
</section>
