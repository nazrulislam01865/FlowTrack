<div
    id="my-work-app"
    x-data="{ metrics: @js($taskPackMetrics), groupsExpanded: true }"
    x-on:board-task-metrics.window="metrics = $event.detail"
>
<div class="page-head">
        <div>
            <h1>All Tasks</h1>
            <p>{{ $taskPackAdministratorView
                ? 'All active Job tasks, grouped by Order and ranked by what needs action first.'
                : 'Tasks from Jobs associated with your assigned work, grouped by Order and ranked by what needs action first.' }}</p>
        </div>
    </div>

    <section class="work-view" aria-busy="false">
        <div class="metrics ft-summary-card-grid" aria-label="All Tasks summary filters">
            <x-ui.summary-card label="Created Today" :value="$taskPackMetrics['createdToday'] ?? 0" value-expression="metrics.createdToday ?? '—'" icon="created" tone="blue" caption="Tasks created today" :active="$taskQuick === 'createdToday'" wire:click="setTaskMetricFilter('createdToday')" aria-pressed="{{ $taskQuick === 'createdToday' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="Not Started" :value="$taskPackMetrics['notStarted'] ?? 0" value-expression="metrics.notStarted ?? '—'" icon="not-started" tone="slate" caption="Waiting for first action" :active="$taskQuick === 'notStarted'" wire:click="setTaskMetricFilter('notStarted')" aria-pressed="{{ $taskQuick === 'notStarted' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="In Progress" :value="$taskPackMetrics['inProgress'] ?? 0" value-expression="metrics.inProgress ?? '—'" icon="in-progress" tone="blue" caption="Work currently underway" :active="$taskQuick === 'inProgress'" wire:click="setTaskMetricFilter('inProgress')" aria-pressed="{{ $taskQuick === 'inProgress' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="Due This Week" :value="$taskPackMetrics['dueThisWeek'] ?? 0" value-expression="metrics.dueThisWeek ?? '—'" icon="due-week" tone="amber" caption="Tasks due this week" :active="$taskQuick === 'dueThisWeek'" wire:click="setTaskMetricFilter('dueThisWeek')" aria-pressed="{{ $taskQuick === 'dueThisWeek' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="Completed This Week" :value="$taskPackMetrics['completedThisWeek'] ?? 0" value-expression="metrics.completedThisWeek ?? '—'" icon="completed" tone="green" caption="Finished this week" :active="$taskQuick === 'completedThisWeek'" wire:click="setTaskMetricFilter('completedThisWeek')" aria-pressed="{{ $taskQuick === 'completedThisWeek' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="Needs Attention" :value="$taskPackMetrics['attention'] ?? 0" value-expression="metrics.attention ?? '—'" icon="attention" tone="red" caption="Blocked, overdue or unassigned" :active="$taskQuick === 'attention'" wire:click="setTaskMetricFilter('attention')" aria-pressed="{{ $taskQuick === 'attention' ? 'true' : 'false' }}" />
        </div>

        @php
            $allTaskStageColors = collect(\App\Services\OrderWorkflowSetupService::fixedStages())
                ->mapWithKeys(fn (array $stage) => [
                    mb_strtolower(trim((string) ($stage['name'] ?? ''))) => (string) ($stage['color'] ?? '#0F8F7C'),
                ]);
        @endphp
        <div class="toolbar ft-list-filter-bar all-tasks-filter-bar">
            <label class="search-wrap all-tasks-search-row">
                <span class="search-icon">⌕</span>
                <input class="search" type="search" wire:model.live.debounce.650ms="search" autocomplete="off" placeholder="Search tasks, Orders, clients, assignees or flags" aria-label="Search All Tasks">
                @if($search !== '')<button class="clear" type="button" wire:click="clearTaskSearch">Clear</button>@endif
            </label>
            <div class="phase-filters all-tasks-stage-filters" aria-label="Filter by Order workflow phase">
                @foreach($taskPackPhaseOptions as $phaseOption)
                    @php
                        $phaseColor = $allTaskStageColors->get(mb_strtolower(trim((string) $phaseOption)), '#0F8F7C');
                    @endphp
                    <button
                        type="button"
                        class="phase-toggle {{ $taskPhaseFilter === $phaseOption ? 'active' : '' }}"
                        style="--phase-color: {{ $phaseColor }}"
                        wire:click="setTaskPhaseFilter({{ \Illuminate\Support\Js::from($phaseOption) }})"
                        aria-pressed="{{ $taskPhaseFilter === $phaseOption ? 'true' : 'false' }}"
                        title="{{ $phaseOption }}"
                    >
                        <span class="phase-check" aria-hidden="true">✓</span>
                        <span>{{ $phaseOption }}</span>
                    </button>
                @endforeach
            </div>
            <div class="toolbar-secondary all-tasks-secondary-filters">
                <div class="quick-filters">
                    <button type="button" class="chip {{ $taskQuick === 'mentions' ? 'active' : '' }}" wire:click="setTaskQuick('{{ $taskQuick === 'mentions' ? 'all' : 'mentions' }}')">Mentions (<span x-text="metrics.mentions ?? '—'">{{ $taskPackMetrics['mentions'] ?? '—' }}</span>)</button>
                </div>
                <label class="completed-toggle {{ $hideCompleted ? 'active' : '' }}">
                    <input type="checkbox" wire:model.live="hideCompleted" aria-label="Hide completed tasks">
                    <span class="completed-check" aria-hidden="true">✓</span>
                    <span>Hide completed</span>
                </label>
                <x-ui.search-select
                    class="assignee-filter"
                    label="Assignee"
                    property="assignee"
                    :value="$assignee"
                    placeholder="Assignee"
                    :options="collect([['id' => 'unassigned', 'label' => 'Unassigned']])->concat(
                        $assigneeFilterOptions->map(fn ($option) => [
                            'id' => (string) $option->id,
                            'label' => $option->name,
                        ])
                    )"
                    search-placeholder="Search assignee…"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="300"
                    wire:key="all-tasks-assignee-filter-{{ $assignee ?: 'all' }}"
                />
                <select class="sort" wire:model.live="taskSort" aria-label="Sort All Tasks">
                    <option value="action">Sort: Action priority</option>
                    <option value="due">Sort: Due soon</option>
                    <option value="job">Sort: Order number</option>
                </select>
                <button
                    type="button"
                    class="clear-filters all-tasks-clear-filters"
                    wire:click="clearFilters"
                    title="Clear filters"
                    aria-label="Clear filters"
                    @disabled($search === '' && $taskPhaseFilter === '' && $taskQuick === 'all' && $hideCompleted && $assignee === '' && $job === '' && $client === '' && $status === '' && $due === '')
                >
                    <span class="clear-filter-icon" aria-hidden="true">×</span>
                    <span>Clear filters</span>
                </button>
            </div>
        </div>

        <div class="load-state">
            <span>
                @if($taskPackPaginator && $taskPackPaginator->total())
                    Showing {{ $taskPackGroups->count() }} of {{ $taskPackPaginator->total() }} matching Orders · {{ $taskPackTaskCount }} visible tasks
                @elseif($taskPackAdministratorView)
                    Showing all active Job Task Packs
                @else
                    Showing associated Job Task Packs only
                @endif
            </span>
            <span class="load-actions">
                <span class="loading-copy">
                    <span wire:loading.remove wire:target="search,assignee,taskPhaseFilter,taskQuick,taskSort,hideCompleted,setTaskMetricFilter,setTaskPhaseFilter,setTaskQuick,clearFilters,clearTaskSearch,gotoPage,previousPage,nextPage">Results update after 650 ms</span>
                    <span wire:loading.delay.long wire:target="search,assignee,taskPhaseFilter,taskQuick,taskSort,hideCompleted,setTaskMetricFilter,setTaskPhaseFilter,setTaskQuick,clearFilters,clearTaskSearch,gotoPage,previousPage,nextPage"><i class="spinner"></i> Searching all visible work…</span>
                </span>
                <span class="group-controls" aria-label="Order group controls">
                    <button type="button" class="group-control" x-on:click="groupsExpanded = true" title="Expand all Orders" aria-label="Expand all Orders">
                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 6 5 5 5-5M5 11l5 5 5-5"/></svg>
                    </button>
                    <button type="button" class="group-control" x-on:click="groupsExpanded = false" title="Collapse all Orders" aria-label="Collapse all Orders">
                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 14 5-5 5 5M5 9l5-5 5 5"/></svg>
                    </button>
                </span>
            </span>
        </div>

        <section class="list-shell" aria-label="All Tasks grouped by Order">
            <div class="task-head"><span>Task</span><span>Phase</span><span>Assignee</span><span>Due</span><span>Status</span><span>Flag</span><span>Updated</span><span>View</span></div>

            <div>
                @forelse($taskPackGroups as $group)
                    <article class="order-group" wire:key="board-task-order-{{ $group['id'] }}" x-data="{ open: true }" x-effect="open = groupsExpanded">
                        <header class="order-head">
                            <button type="button" class="collapse" x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-label="Collapse {{ $group['number'] }}"><span x-text="open ? '⌄' : '›'">⌄</span></button>
                            <span class="order-identity">
                                @if($group['route'])<a class="order-id" href="{{ $group['route'] }}" wire:navigate>{{ $group['number'] }}</a>@else<span class="order-id">{{ $group['number'] }}</span>@endif
                                <span class="order-title">{{ $group['title'] }}</span>
                            </span>
                            <span class="order-client">{{ $group['client'] }}</span>
                            <span class="order-stage">{{ $group['stage'] }}</span>
                            <span class="order-progress"><i class="progress-track"><i style="width:{{ $group['progress'] }}%"></i></i>{{ $group['progress'] }}%</span>
                            <span class="task-count">{{ $group['taskCount'] }} {{ $group['taskCount'] === 1 ? 'task' : 'tasks' }}</span>
                        </header>

                        <div class="task-rows" x-show="open">
                            @foreach($group['tasks'] as $task)
                                <div
                                    class="task-row"
                                    style="{{ \App\Support\MasterColor::style($task['taskColor'] ?? null) }}border-left:4px solid var(--ft-master-color,#2563EB)"
                                    wire:key="board-task-{{ $task['id'] }}"
                                    x-data="{
                                        saving:false,
                                        version:@js($task['version']),
                                        currentStatus:@js($task['status']),
                                        async saveStatus(event){
                                            const select=event.currentTarget;
                                            const previous=this.currentStatus;
                                            const next=select.value;
                                            if(next===previous||this.saving)return;
                                            this.saving=true;
                                            select.disabled=true;
                                            try{
                                                const result=await $wire.updateTaskStatus({{ $task['id'] }},next,this.version);
                                                if(!result?.ok){select.value=previous;window.FlowTrack.ui.masterColor?.applySelect(select);return;}
                                                this.currentStatus=result.status||next;
                                                this.version=result.version||this.version;
                                                if(result.metrics)window.dispatchEvent(new CustomEvent('board-task-metrics',{detail:result.metrics}));
                                                // Status saves are normally renderless for speed. When a task is
                                                // completed while Hide completed is active, refresh the grouped
                                                // list once so the completed row disappears immediately and the
                                                // Order disappears too when it no longer has any visible tasks.
                                                if(result.completed && @js($hideCompleted))await $wire.$refresh();
                                            }catch(error){select.value=previous;window.FlowTrack.ui.masterColor?.applySelect(select);}
                                            finally{this.saving=false;select.disabled=false;}
                                        }
                                    }"
                                    x-bind:class="{ 'saving': saving }"
                                >
                                    <div class="task-main">
                                        @if($task['route'])<a class="task-link" href="{{ $task['route'] }}" wire:navigate>{{ $task['title'] }}</a>@else<span class="task-link">{{ $task['title'] }}</span>@endif
                                        <span class="task-ref">{{ $task['number'] }}</span>
                                    </div>
                                    <span class="phase ft-phase-color-label" style="{{ \App\Support\MasterColor::style($task['phaseColor'] ?? null) }}">{{ $task['phase'] }}</span>
                                    <span class="assignee" title="{{ $task['assignee'] }}">
                                        <x-ui.avatar :name="$task['assignee']" :src="$task['assigneeImage']" :size="22" />
                                        <span class="assignee-name">{{ $task['assignee'] }}</span>
                                    </span>
                                    <time class="due {{ $task['dueTone'] }}">{{ $task['due'] }}</time>
                                    <select data-master-color-select class="status-select {{ $task['statusColor'] ? 'ft-master-color' : '' }}" style="{{ \App\Support\MasterColor::style($task['statusColor']) }}" @if($task['canEdit']) x-on:change="saveStatus($event); window.FlowTrack.ui.masterColor?.applySelect($event.currentTarget)" @else disabled @endif aria-label="Status for {{ $task['title'] }}">
                                        @if(!in_array($task['status'], $taskPackStatusOptions, true))<option value="{{ $task['status'] }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('order_task_status', $task['status']) }}" selected>{{ $task['status'] }}</option>@endif
                                        @foreach($taskPackStatusOptions as $statusOption)<option value="{{ $statusOption }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('order_task_status', $statusOption) }}" @selected($statusOption === $task['status'])>{{ $statusOption }}</option>@endforeach
                                    </select>
                                    <span class="flag {{ $task['flagColor'] ? 'ft-master-color' : $task['flagTone'] }}" style="{{ \App\Support\MasterColor::style($task['flagColor']) }}">{{ $task['flag'] }}</span>
                                    <span class="updated">{{ $task['updated'] }}</span>
                                    @if($task['route'])<a class="row-action" href="{{ $task['route'] }}" wire:navigate>Open</a>@else<span class="row-action" aria-disabled="true">—</span>@endif
                                </div>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <div class="empty"><strong>No matching work</strong>Try another task, Order, client, assignee, or flag.</div>
                @endforelse
            </div>

            <footer class="footer">
                <span>
                    @if($taskPackPaginator && $taskPackPaginator->total())
                        Orders {{ $taskPackPaginator->firstItem() }}–{{ $taskPackPaginator->lastItem() }} of {{ $taskPackPaginator->total() }} · {{ $taskPackTaskCount }} tasks on this page
                    @elseif($taskPackAdministratorView)
                        All active Job tasks
                    @else
                        Associated Job tasks
                    @endif
                </span>
                @php
                    $currentPage = $taskPackPaginator?->currentPage() ?? 1;
                    $lastPage = max(1, $taskPackPaginator?->lastPage() ?? 1);
                    $pageStart = max(1, $currentPage - 2);
                    $pageEnd = min($lastPage, $currentPage + 2);
                @endphp
                <nav class="pages" aria-label="Pagination">
                    <button type="button" class="page-button" wire:click="previousPage('taskPackPage')" @disabled(!$taskPackPaginator || $taskPackPaginator->onFirstPage())>Previous</button>
                    @for($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++)
                        <button type="button" class="page-button {{ $pageNumber === $currentPage ? 'active' : '' }}" wire:click="gotoPage({{ $pageNumber }}, 'taskPackPage')" @if($pageNumber === $currentPage) aria-current="page" @endif>{{ $pageNumber }}</button>
                    @endfor
                    <button type="button" class="page-button" wire:click="nextPage('taskPackPage')" @disabled(!$taskPackPaginator || !$taskPackPaginator->hasMorePages())>Next</button>
                </nav>
            </footer>
        </section>
    </section>
</div>
