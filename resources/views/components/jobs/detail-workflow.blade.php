@props(['job','users'=>collect()])
@php
    $blockers = \App\Support\JobDetailPresenter::blockers($job);
    $currentTasks = \App\Support\JobDetailPresenter::phaseTasks($job);
    $requiredTasks = $currentTasks->filter(fn($task) => ($task->setupTemplate?->is_required ?? $task->template?->is_required ?? true) !== false)->values();
    $done = \App\Support\JobDetailPresenter::completedCount($currentTasks);
    $requiredDone = \App\Support\JobDetailPresenter::completedCount($requiredTasks);
    $next = \App\Support\JobDetailPresenter::nextPhase($job);
    $rows = \App\Support\JobDetailPresenter::phaseHistoryRows($job);
    $currentRequired = \App\Support\JobDetailPresenter::phaseRequiredDocuments($job,$job->phase);
    $receivedCurrent = $currentRequired->where('complete',true)->count();
    $missingCurrent = $currentRequired->where('complete',false);
    $blockingTask = $requiredTasks->first(fn($task) => !$task->completed_at && $task->status !== 'Completed');
    $progress = $currentTasks->count() ? round($done/max(1,$currentTasks->count())*100) : 0;
    $tasksReady = $requiredTasks->filter(fn($task) => !$task->completed_at && $task->status !== 'Completed')->isEmpty();
    $documentsReady = $missingCurrent->isEmpty();
    $canChangeJobStatus = app(\App\Services\AccessControlService::class)->canChangeVisibleJobStatus(auth()->user(), $job);
@endphp
<div class="ft-workflow-detail-section ft-exact-workflow">
    <div class="ft-section-title-row"><div><h2>Workflow</h2><p>{{ $job->workflow->name }} · Version 1 · {{ \App\Support\UserLocalTime::format($job->created_at, 'M j, Y') }}</p></div></div>

    @if($blockers->isNotEmpty())
        <div class="ft-warning-banner">
            <span>!</span>
            <div>
                <b>{{ $blockers->count() === 1 ? $blockers->first()->label : $blockers->count().' Task Pack requirements block the next phase' }}</b>
                <p>{{ $blockers->pluck('description')->implode(' ') }}</p>
            </div>
            @if($blockingTask)<button type="button" wire:click="openTask({{ $blockingTask->id }})">View blocking task</button>@else<button type="button" wire:click="setDetailTab('overview')">Review tasks</button>@endif
        </div>
    @else
        <div class="ft-success-banner"><span>✓</span><div><b>Ready for the next phase</b><p>All required Task Pack tasks and Task Pack documents are complete.</p></div></div>
    @endif

    <section class="ft-workflow-stepper-card"><div class="ft-workflow-stepper">
        @foreach($job->workflow->phases as $phase)
            @php
                $phaseTasks = \App\Support\JobDetailPresenter::phaseTasks($job,$phase);
                $phaseDone = \App\Support\JobDetailPresenter::completedCount($phaseTasks);
                $phaseComplete = \App\Support\JobDetailPresenter::isPhaseComplete($job,$phase);
            @endphp
            <div class="ft-workflow-step {{ $phaseComplete ? 'done' : '' }}" style="{{ \App\Support\MasterColor::style($phase->color) }}" @if((int) $phase->id === (int) $job->workflow_phase_id) aria-current="step" @endif>
                <span>{{ $phaseComplete ? '✓' : $phase->sequence }}</span>
                <small>{{ $phase->short_name }}</small>
                @if($phase->id === $job->phase->id)<em>Current · {{ $phaseDone }}/{{ $phaseTasks->count() }}</em>@endif
            </div>
        @endforeach
    </div></section>

    <div class="ft-workflow-main-full">
            <section class="ft-detail-card ft-current-phase-card">
                <div class="ft-card-row-head">
                    <div>
                        <h2>Current phase · {{ $job->phase->name }}</h2>
                        <div class="ft-phase-progress-copy"><span>{{ $done }} of {{ $currentTasks->count() }} tasks complete</span><div class="ft-line-progress"><span style="width:{{ $progress }}%"></span></div><b>{{ $progress }}%</b></div>
                    </div>
                    <span class="ft-phase-count-pill">Phase {{ $job->phase->sequence }} of {{ $job->workflow->phases->count() }}</span>
                </div>
                <div class="ft-readiness-table ft-taskpack-readiness-only">
                    <div>
                        <span class="{{ $tasksReady ? 'ok' : 'warn' }}">{{ $tasksReady ? '✓' : '!' }}</span><b>1</b><span>Task Pack tasks</span>
                        <strong>{{ $requiredDone }} of {{ $requiredTasks->count() }} required complete</strong>
                        <em class="{{ $tasksReady ? 'complete' : 'remain' }}">{{ $tasksReady ? 'Complete' : max(0,$requiredTasks->count()-$requiredDone).' required remaining' }}</em>
                        @if($blockingTask)<button wire:click="openTask({{ $blockingTask->id }})">Open task</button>@else<button type="button" wire:click="setDetailTab('overview')">View tasks</button>@endif
                    </div>
                    <div>
                        <span class="{{ $documentsReady ? 'ok' : 'warn' }}">{{ $documentsReady ? '✓' : '!' }}</span><b>2</b><span>Task Pack documents</span>
                        <strong>{{ $currentRequired->isEmpty() ? 'Not required' : $receivedCurrent.' of '.$currentRequired->count().' received' }}</strong>
                        <em class="{{ $documentsReady ? 'complete' : 'blocked' }}">{{ $documentsReady ? 'Complete' : 'Review' }}</em>
                        <button wire:click="setDetailTab('overview')">Review tasks</button>
                    </div>
                </div>

                <div class="ft-next-phase-box">
                    <span>▣</span>
                    <div><b>Next phase: {{ $next?->name ?? 'Completed' }}</b><p>{{ $blockers->isEmpty() ? 'All Task Pack requirements are ready.' : 'Complete the remaining Task Pack requirements.' }}</p></div>
                    @if($blockingTask)<button class="ft-outline-btn" type="button" wire:click="openTask({{ $blockingTask->id }})">Open blocking task</button>@elseif(!$documentsReady)<button class="ft-outline-btn" type="button" wire:click="setDetailTab('overview')">Review required files</button>@else<button class="ft-outline-btn" type="button" wire:click="setDetailTab('overview')">Review</button>@endif
                    @if($canChangeJobStatus)
                        <button class="{{ $blockers->isEmpty() ? 'ft-new-job-btn' : 'ft-disabled-btn' }}" wire:click="completePhase" @disabled($blockers->isNotEmpty())>Move to {{ $next?->name ?? 'Completed' }}</button>
                    @else
                        <span class="ft-permission-note">Only the assigned Job owner can move this Job to another phase.</span>
                    @endif
                </div>
                @error('phaseCompletion')<div class="ft-warning-banner slim"><span>!</span><p>{{ $message }}</p></div>@enderror
            </section>

            <section class="ft-detail-card ft-history-card">
                <h2>Phase history</h2><p>Each phase is calculated only from its selected Task Pack tasks and Task Pack document requirements.</p>
                <table class="ft-history-table"><thead><tr><th>Phase</th><th>Status</th><th>Entered</th><th>Completed</th><th>Time in phase</th><th>Outcome</th></tr></thead><tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td data-label="Phase"><x-ui.phase-label :phase="$row->phase" short /></td>
                            <td data-label="Status"><span class="ft-soft-pill {{ $row->status==='Completed'?'green':($row->status==='Current'?'blue':'gray') }}">{{ $row->status }}</span></td>
                            <td data-label="Entered">{{ \App\Support\UserLocalTime::format($row->entered, 'M j Y') }}</td>
                            <td data-label="Completed">{{ \App\Support\UserLocalTime::format($row->completed, 'M j Y') }}</td>
                            <td data-label="Time in phase">{{ $row->time ? $row->time.' day'.($row->time>1?'s':'') : '—' }}</td>
                            <td data-label="Outcome" class="{{ $row->outcome==='Passed'?'green-text':($row->outcome==='Blocked'?'warn-text':'') }}">{{ $row->outcome }}</td>
                        </tr>
                    @endforeach
                </tbody></table>
            </section>
    </div>
</div>
