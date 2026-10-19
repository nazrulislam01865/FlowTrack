@props(['tasks', 'statuses', 'draggable' => false, 'keyPrefix' => 'task-matrix', 'allGroupsExpanded' => true, 'groupStateKey' => 'default'])
@php
    $matrixTasks = collect($tasks);
    $laneStatuses = collect($statuses)->values();
    $groupedJobs = $matrixTasks->groupBy('flow_job_id');
@endphp

<div class="ft-task-job-matrix" style="--ft-lane-count: {{ max(1, $laneStatuses->count()) }};">
    @forelse($groupedJobs as $jobId => $jobTasks)
        @php
            $job = $jobTasks->first()?->job;
            $resolvedJobId = $job?->id ?? ('unassigned-'.$loop->index);
        @endphp
        <section
            class="ft-task-job-matrix-group"
            x-data="{ open: {{ $allGroupsExpanded ? 'true' : 'false' }} }"
            wire:key="{{ $keyPrefix }}-job-{{ $resolvedJobId }}-{{ $groupStateKey }}"
        >
            <div class="ft-task-job-row-head">
                <button type="button" class="ft-task-job-row-toggle" x-on:click="open = !open" :title="open ? 'Collapse order tasks' : 'Expand order tasks'" :aria-expanded="open.toString()">
                    <svg :class="{'rotated': !open}" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>

                @if($job)
                    <a class="ft-task-job-row-number" href="{{ route('jobs.index', ['open' => $job->id]) }}" wire:navigate>{{ $job->displayOrderNumber() }}</a>
                    <span class="ft-task-job-copy" aria-hidden="true">▣</span>
                    <span class="ft-task-job-dot">·</span>
                    <a class="ft-task-job-row-title" href="{{ route('jobs.index', ['open' => $job->id]) }}" wire:navigate>{{ $job->title }}</a>
                    <span class="ft-task-job-client-pill">{{ $job->client?->name ?? 'No client' }}</span>
                @else
                    <span class="ft-task-job-row-number">No order</span>
                @endif
                <span class="ft-task-job-row-total">{{ $jobTasks->count() }} {{ \Illuminate\Support\Str::plural('task', $jobTasks->count()) }}</span>
            </div>

            <div class="ft-task-job-row-grid" x-show="open">
                @foreach($laneStatuses as $laneStatus)
                    @php
                        $laneTasks = $jobTasks->filter(fn($task) => \App\Support\BoardLaneResolver::taskStatusMatches($task->status, $laneStatus));
                        $laneColor = app(\App\Services\MasterDataService::class)->colorFor('order_task_status', (string) $laneStatus);
                    @endphp
                    <div
                        class="ft-task-job-status-cell {{ $laneTasks->isEmpty() ? 'is-empty' : 'has-tasks' }}"
                        data-status="{{ $laneStatus }}"
                        @if($draggable)
                            x-on:dragover.prevent
                            x-on:drop.prevent="if(draggedTask){ $wire.moveTask(draggedTask, {{ \Illuminate\Support\Js::from($laneStatus) }}); draggedTask=null }"
                        @endif
                    >
                        <div class="ft-mobile-lane-label {{ $laneColor ? 'ft-master-color' : '' }}" style="{{ \App\Support\MasterColor::style($laneColor) }}"><span>{{ $laneStatus }}</span><b>{{ $laneTasks->count() }}</b></div>
                        @forelse($laneTasks as $taskRow)
                            @php
                                $canDragTask = $draggable && app(\App\Services\AccessControlService::class)->canEditVisibleTask(auth()->user(), $taskRow);
                            @endphp
                            @if($canDragTask)
                                <x-board.task-card :task="$taskRow" draggable="true" x-on:dragstart="draggedTask={{ $taskRow->id }}" x-on:dragend="draggedTask=null" wire:key="{{ $keyPrefix }}-{{ str($laneStatus)->slug() }}-task-{{ $taskRow->id }}" />
                            @else
                                <x-board.task-card :task="$taskRow" wire:key="{{ $keyPrefix }}-{{ str($laneStatus)->slug() }}-task-{{ $taskRow->id }}" />
                            @endif
                        @empty
                            <div class="ft-task-job-empty-cell">No tasks</div>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="ft-task-job-matrix-empty">No tasks match the current filters.</div>
    @endforelse
</div>
