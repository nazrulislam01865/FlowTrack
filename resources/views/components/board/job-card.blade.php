@props(['job', 'expanded' => false])
@php
    $currentTasks = \App\Support\BoardPresenter::currentTasks($job);
    $openTasks = \App\Support\BoardPresenter::openTasks($job);
    $nextTask = \App\Support\BoardPresenter::nextTask($job);
    $team = \App\Support\BoardPresenter::team($job);
    $completedCurrent = $currentTasks->filter(fn($task) => $task->completed_at || $task->status === 'Completed')->count();
    $attentionActive = (bool) ($job->attention_requested ?? false) || (bool) $job->needs_attention;
@endphp
<article {{ $attributes->class(['ft-job-card', 'is-expanded' => $expanded]) }}>

    <div class="ft-job-card-top">
        <div class="ft-job-card-signals">
            @if($attentionActive)
                <span class="ft-health-pill red"><span class="ft-health-dot"></span>Needs Attention</span>
            @endif
            <span class="ft-phase-age">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                {{ \App\Support\BoardPresenter::phaseDays($job) }}d in phase
            </span>
        </div>
        <button type="button" class="ft-card-kebab" wire:click.stop="toggleJobCard({{ $job->id }})" title="{{ $expanded ? 'Collapse card' : 'Expand card' }}" aria-label="{{ $expanded ? 'Collapse card' : 'Expand card' }}">
            <span></span><span></span><span></span>
        </button>
    </div>

    <h3 class="ft-job-card-title"><a href="{{ route('jobs.index', ['open' => $job->id]) }}" wire:navigate>{{ $job->title }}</a></h3>
    <div class="ft-job-reference">
        <a href="{{ route('jobs.index', ['open' => $job->id]) }}" wire:navigate>{{ $job->displayOrderNumber() }}</a>
        <span>·</span>
        <span>{{ $job->client?->name ?? 'No client' }}</span>
    </div>

    <div class="ft-job-products">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/></svg>
        <span><b>{{ \App\Support\BoardPresenter::productCount($job) }} {{ \Illuminate\Support\Str::plural('product', \App\Support\BoardPresenter::productCount($job)) }}</b></span>
        <span class="ft-dot-separator">·</span>
        <span>{{ number_format(\App\Support\BoardPresenter::totalUnits($job)) }} units</span>
    </div>

    <div class="ft-job-progress-head"><span>Overall progress</span><b>{{ $job->progress }}%</b></div>
    <div class="ft-job-progress"><span style="width:{{ max(0,min(100,(int)$job->progress)) }}%"></span></div>

    <div class="ft-job-stats">
        <span class="ft-stat-chip neutral">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>
            {{ $openTasks->count() }} open
        </span>
        <span class="ft-stat-chip amber">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            {{ \App\Support\BoardPresenter::dueSoonCount($job) }} due soon
        </span>
        <span class="ft-stat-chip green">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>
            {{ \App\Support\BoardPresenter::blockedCount($job) }} blocked
        </span>
    </div>

    @if($expanded)
        <div class="ft-phase-task-panel">
            <div class="ft-phase-task-head">
                <span>PHASE TASKS</span>
                <span>{{ $completedCurrent }} of {{ $currentTasks->count() }} complete</span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>
            </div>
            <div class="ft-phase-task-list">
                @forelse($currentTasks as $phaseTask)
                    @php
                        $isNext = $nextTask && $nextTask->id === $phaseTask->id;
                    @endphp
                    <a class="ft-phase-task-row" href="{{ route('jobs.index', ['open'=>$job->id, 'task'=>$phaseTask->id]) }}" wire:navigate>
                        <div class="ft-phase-task-main">
                            @if($isNext)<span class="ft-next-badge">NEXT</span>@else<span class="ft-next-spacer"></span>@endif
                            <div class="ft-phase-task-copy">
                                <b>{{ $phaseTask->title }}</b>
                                <div class="ft-phase-task-person">
                                    <x-ui.avatar :user="$phaseTask->assignee" :name="$phaseTask->assignee?->name ?? 'Unassigned'" :size="30" />
                                    <span>{{ $phaseTask->assignee?->name ?? 'Unassigned' }}</span>
                                </div>
                            </div>
                        </div>
                        <span class="ft-task-status-mini {{ $phaseTask->status === 'Completed' ? 'done' : (str_contains($phaseTask->status,'Waiting') ? 'waiting' : ($phaseTask->status === 'Blocked' ? 'blocked' : 'ready')) }}">{{ $phaseTask->status }}</span>
                        <span class="ft-phase-task-due {{ ($phaseTask->due_date && \App\Support\UserLocalTime::isDatePast($phaseTask->due_date)) && !$phaseTask->completed_at ? 'overdue' : '' }}">{{ $phaseTask->due_date?->format('M j') ?? '—' }}</span>
                    </a>
                @empty
                    <div class="ft-phase-task-empty">No phase tasks configured.</div>
                @endforelse
            </div>
        </div>
    @elseif($nextTask)
        <a class="ft-next-action" href="{{ route('jobs.index', ['open'=>$job->id, 'task'=>$nextTask->id]) }}" wire:navigate>
            <span class="ft-next-action-label">NEXT ACTION</span>
            <b>{{ $nextTask->title }}</b>
            <div class="ft-next-action-meta">
                <span class="ft-next-assignee"><x-ui.avatar :user="$nextTask->assignee" :name="$nextTask->assignee?->name ?? 'Unassigned'" :size="34" /> {{ $nextTask->assignee?->name ?? 'Unassigned' }}</span>
                <span class="ft-next-divider"></span>
                <span class="ft-next-due {{ ($nextTask?->due_date && \App\Support\UserLocalTime::isDatePast($nextTask->due_date)) ? 'overdue' : '' }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></svg>
                    Due {{ $nextTask->due_date?->format('M j') ?? '—' }}
                </span>
            </div>
        </a>
    @elseif($job->next_action)
        <div class="ft-next-action">
            <span class="ft-next-action-label">NEXT ACTION</span>
            <b>{{ $job->next_action }}</b>
            <div class="ft-next-action-meta"><span class="ft-next-assignee"><x-ui.avatar :user="$job->coordinator" :name="$job->coordinator?->name ?? 'Unassigned'" :size="34" /> {{ $job->coordinator?->name ?? 'Unassigned' }}</span></div>
        </div>
    @endif

    <div class="ft-job-team">
        <span class="ft-job-team-label">Team</span>
        <div class="ft-team-avatars">
            @foreach($team->take(3) as $member)
                <x-ui.avatar :user="$member" :name="$member->name" :size="32" class="{{ $loop->even ? 'ft-avatar-green' : '' }}" />
            @endforeach
            @if($team->count() > 3)<span class="ft-avatar-more">+{{ $team->count()-3 }}</span>@endif
        </div>
        <span class="ft-team-lead">Lead: {{ $job->owner?->name ?? $job->coordinator?->name ?? 'Unassigned' }}</span>
    </div>

    <div class="ft-job-footer-grid">
        <div class="ft-job-footer-cell">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>
            <div><span>Delivery</span>
                <span
                    class="ft-inline-date ft-job-inline-date ft-inline-edit-shell {{ ($job->delivery_date && \App\Support\UserLocalTime::isDatePast($job->delivery_date)) && !$job->completed_at ? 'overdue' : '' }}"
                    x-data="window.FlowTrack.ui.inlineEdit({ key: @js('job-'.$job->id.'-delivery-date'), label: 'Job delivery date', value: @js($job->delivery_date?->format('Y-m-d') ?? ''), display: @js($job->delivery_date?->format('M j') ?? 'Set due date') })"
                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                >
                    <span class="ft-inline-date-display" x-show="!editing"><b x-text="display">{{ $job->delivery_date?->format('M j') ?? 'Set due date' }}</b></span>
                    @if(app(\App\Services\AccessControlService::class)->canEditVisibleJob(auth()->user(), $job))
                        <button x-show="!editing" :disabled="status === 'saving'" type="button" class="ft-inline-edit-button compact" aria-label="Edit delivery date" title="Edit" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.jobDate.showPicker ? $refs.jobDate.showPicker() : $refs.jobDate.focus())">✎</button>
                        <input x-ref="jobDate" x-cloak x-show="editing" x-model="draftValue" x-on:blur="if (editing) cancelEdit()" x-on:keydown.escape.prevent="cancelEdit()" x-on:change="commit($event.target.value, formatDate($event.target.value, true), () => $wire.updateJobDueDate({{ $job->id }}, draftValue))" type="date" aria-label="Job delivery date">
                        <x-ui.inline-save-state compact />
                    @endif
                </span>
            </div>
        </div>
        <div class="ft-job-footer-cell commercial">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6z"/><path d="M15 2v6h5"/><path d="M13.5 11.5c-.5-.6-1.2-.9-2-.9-1.2 0-2 .6-2 1.5 0 2.3 4.5 1.1 4.5 3.5 0 .9-.9 1.6-2.2 1.6-.9 0-1.8-.4-2.4-1.1M11.8 9v10"/></svg>
            <div><span>Commercial</span><b>{{ \App\Support\BoardPresenter::commercialLabel($job) }}</b></div>
        </div>
    </div>

    <div class="ft-job-updated">Updated {{ \App\Support\BoardPresenter::lastUpdatedText($job) }} ago by {{ \App\Support\BoardPresenter::updatedBy($job) }}</div>
</article>
