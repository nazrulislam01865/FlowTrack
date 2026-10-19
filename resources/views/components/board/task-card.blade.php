@props(['task'])
@php
    $overdueDays = \App\Support\BoardPresenter::overdueDays($task);
    $waiting = \App\Support\BoardPresenter::waitingLabel($task);
    $checkTotal = (int) ($task->checklist_items_count ?? ($task->relationLoaded('checklistItems') ? $task->checklistItems->count() : 0));
    $checkDone = (int) ($task->completed_checklist_items_count ?? ($task->relationLoaded('checklistItems') ? $task->checklistItems->where('is_completed', true)->count() : 0));
    $documentCount = (int) ($task->documents_count ?? ($task->relationLoaded('documents') ? $task->documents->count() : 0));
    $commentCount = (int) ($task->comments_count ?? ($task->relationLoaded('comments') ? $task->comments->count() : 0));
    $taskFlagLabel = app(\App\Services\OrderTaskFlagService::class)->labelForTask($task);
    $masterData = app(\App\Services\MasterDataService::class);
    $taskFlagColor = $taskFlagLabel ? $masterData->colorFor('order_task_flag', $taskFlagLabel) : null;
    $taskPriorityColor = $masterData->displayColorFor('priority', (string) $task->priority);
@endphp
<article {{ $attributes->class(['ft-task-board-card']) }}>
    <div class="ft-task-board-top">
        <div class="ft-task-card-badges"><span class="ft-priority-pill {{ $taskPriorityColor ? 'ft-master-color' : strtolower($task->priority) }}" style="{{ \App\Support\MasterColor::style($taskPriorityColor) }}">{{ $task->priority }}</span>@if($taskFlagLabel)<span class="ft-attention-pill {{ $taskFlagColor ? 'ft-master-color' : '' }}" style="{{ \App\Support\MasterColor::style($taskFlagColor) }}">⚑ {{ $taskFlagLabel }}</span>@endif</div>
        <div class="ft-task-board-top-right">
            @if($overdueDays > 0)<span class="ft-overdue-pill">Overdue · {{ $overdueDays }}d</span>@endif
            <a class="ft-card-kebab" href="{{ route('jobs.index', ['open'=>$task->flow_job_id, 'task'=>$task->id]) }}" wire:navigate aria-label="Open task">
                <span></span><span></span><span></span>
            </a>
        </div>
    </div>

    <a class="ft-task-title" href="{{ route('jobs.index', ['open'=>$task->flow_job_id, 'task'=>$task->id]) }}" wire:navigate>{{ $task->title }}</a>
    <div class="ft-task-job-ref">
        <a href="{{ route('jobs.index', ['open'=>$task->flow_job_id]) }}" wire:navigate>{{ $task->job?->displayOrderNumber() }}</a>
        <span>·</span><span>{{ $task->job?->client?->name ?? 'No client' }}</span>
    </div>
    <div class="ft-task-phase-name"><x-ui.phase-label :phase="$task->phase" short /></div>

    @if($waiting)
        <div class="ft-waiting-panel">
            <span class="ft-waiting-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m10.5 13.5 3-3"/><path d="m7.5 15.5-1 1a4 4 0 0 1-5.7-5.7l3-3a4 4 0 0 1 5.7 0"/><path d="m16.5 8.5 1-1a4 4 0 0 1 5.7 5.7l-3 3a4 4 0 0 1-5.7 0"/></svg></span>
            <div><b>Waiting on: <span>{{ $waiting }}</span></b><small>Since {{ \App\Support\UserLocalTime::format($task->updated_at, 'M j') }}</small></div>
        </div>
    @endif

    <div class="ft-task-assignee-row">
        <div class="ft-task-assignee"><x-ui.avatar :user="$task->assignee" :name="$task->assignee?->name ?? 'Unassigned'" :size="38" /><b>{{ $task->assignee?->name ?? 'Unassigned' }}</b></div>
        <span
            class="ft-inline-date ft-inline-edit-shell {{ ($task->due_date && \App\Support\UserLocalTime::isDatePast($task->due_date)) && !$task->completed_at ? 'overdue' : '' }}"
            x-data="window.FlowTrack.ui.inlineEdit({ key: @js('task-'.$task->id.'-due-date'), label: 'task due date', value: @js($task->due_date?->format('Y-m-d') ?? ''), display: @js($task->due_date?->format('M j') ?? 'Set due date') })"
            :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
        >
            <button type="button" class="ft-inline-date-display" x-show="!editing" :disabled="status === 'saving'" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.dateInput.showPicker ? $refs.dateInput.showPicker() : $refs.dateInput.focus())" title="Set due date">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></svg>
                <span x-text="display">{{ $task->due_date?->format('M j') ?? 'Set due date' }}</span>
            </button>
            <input x-ref="dateInput" x-cloak x-show="editing" x-model="draftValue" x-on:blur="if (editing) cancelEdit()" x-on:keydown.escape.prevent="cancelEdit()" x-on:change="commit($event.target.value, formatDate($event.target.value, true), () => $wire.updateTaskDueDate({{ $task->id }}, draftValue))" type="date" aria-label="Task due date">
            <x-ui.inline-save-state compact />
        </span>
    </div>

    <div class="ft-task-meta-footer">
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m7 12 3 3 7-7"/></svg>{{ $checkDone }}/{{ $checkTotal }}</span>
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m20.5 11.5-8.2 8.2a6 6 0 1 1-8.5-8.5l9-9a4 4 0 0 1 5.7 5.7l-9.1 9.1a2 2 0 0 1-2.8-2.8l8.4-8.4"/></svg>{{ $documentCount }}</span>
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3v-7a6 6 0 0 1-1-3.3V8a5 5 0 0 1 5-5h10a4 4 0 0 1 4 4z"/></svg>{{ $commentCount }}</span>
        <span class="ft-task-updated">Updated {{ \App\Support\BoardPresenter::lastUpdatedText($task) }} ago</span>
    </div>
</article>
