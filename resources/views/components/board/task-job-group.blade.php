@props(['tasks', 'draggable' => false, 'keyPrefix' => 'task-job'])
@php
    $groupTasks = collect($tasks);
    $job = $groupTasks->first()?->job;
    $jobId = $job?->id ?? 'unassigned';
@endphp
<section class="ft-task-job-group" wire:key="{{ $keyPrefix }}-{{ $jobId }}">
    <div class="ft-task-job-group-head">
        <div class="ft-task-job-group-main">
            <a class="ft-task-job-group-title" href="{{ $job ? route('jobs.index', ['open' => $job->id]) : '#' }}" @if($job) wire:navigate @endif>
                {{ $job?->title ?? 'No job' }}
            </a>
            <div class="ft-task-job-group-meta">
                @if($job)
                    <a href="{{ route('jobs.index', ['open' => $job->id]) }}" wire:navigate>{{ $job->displayOrderNumber() }}</a>
                    <span>·</span>
                @endif
                <span>{{ $job?->client?->name ?? 'No client' }}</span>
            </div>
        </div>
        <span class="ft-task-job-group-count">{{ $groupTasks->count() }}</span>
    </div>

    <div class="ft-task-job-group-list">
        @foreach($groupTasks as $taskRow)
            @if($draggable)
                <x-board.task-card :task="$taskRow" draggable="true" x-on:dragstart="draggedTask={{ $taskRow->id }}" wire:key="{{ $keyPrefix }}-task-{{ $taskRow->id }}" />
            @else
                <x-board.task-card :task="$taskRow" wire:key="{{ $keyPrefix }}-task-{{ $taskRow->id }}" />
            @endif
        @endforeach
    </div>
</section>
