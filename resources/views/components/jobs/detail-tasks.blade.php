@props(['job'])
<section class="ft-detail-card ft-phase-table-card">
    <div class="ft-card-row-head"><div><h2>All phase tasks</h2><p>{{ $job->tasks->count() }} tasks across {{ $job->workflow->phases->count() }} phases</p></div><div class="ft-row-actions"><input placeholder="Search tasks"><button class="ft-outline-btn">Filter</button><button class="ft-new-job-btn">Expand all</button></div></div>
    <div class="ft-phase-task-table">
        @foreach($job->workflow->phases as $phase)
            @php
                $phaseTasks = \App\Support\JobDetailPresenter::phaseTasks($job,$phase);
            @endphp
            <div class="ft-phase-group open" style="{{ \App\Support\MasterColor::style($phase->color) }}">
                <div class="ft-phase-group-head"><span>⌄</span><b>{{ $phase->sequence }}</b><strong>{{ $phase->name }}</strong><small>{{ \App\Support\JobDetailPresenter::completedCount($phaseTasks) }} of {{ $phaseTasks->count() }} complete</small><em style="width:{{ $phaseTasks->count() ? \App\Support\JobDetailPresenter::completedCount($phaseTasks)/max(1,$phaseTasks->count())*100 : 0 }}%"></em><button type="button">＋</button></div>
                @forelse($phaseTasks as $task)
                    <button class="ft-phase-task-line" wire:click="openTask({{ $task->id }})"><span>{{ $phase->sequence }}.{{ $loop->iteration }}</span><b>{{ $task->title }}</b><span><x-ui.avatar :user="$task->assignee" :name="$task->assignee?->name ?? 'Unassigned'" :size="24"/>{{ $task->assignee?->name ?? 'Unassigned' }}</span><span>{{ $task->due_date?->format('M j, Y') ?? '—' }}</span>@php $taskStatusColor = app(\App\Services\MasterDataService::class)->colorFor('order_task_status', (string) $task->status); @endphp<span class="ft-soft-pill {{ $taskStatusColor ? 'ft-master-color' : \App\Support\JobDetailPresenter::taskStatusClass($task->status) }}" style="{{ \App\Support\MasterColor::style($taskStatusColor) }}">{{ $task->status }}</span><span>•••</span></button>
                @empty
                    <div class="ft-phase-empty-row">No tasks in this phase yet</div>
                @endforelse
            </div>
        @endforeach
    </div>
</section>
