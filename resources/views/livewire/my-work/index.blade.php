<div
    id="my-work-app"
    x-data="{ metrics: @js($metrics), groupsExpanded: true }"
    x-on:my-work-metrics.window="metrics = $event.detail"
>


    <div class="page-head">
        <div>
            <h1>My Tasks</h1>
            <p>
                @if($sourceFilter === 'inquiries' && $statusFilter !== '')
                    Only your assigned Inquiry tasks matching the selected dashboard status filter.
                @else
                    Only your currently active assigned tasks are shown here. The same personal assignment rule applies to every role, including Admin and Super Admin.
                @endif
            </p>
        </div>
    </div>


    @if($sourceFilter === 'inquiries' && $statusFilter !== '')
    <section class="work-view" aria-busy="false">
        <div class="toolbar ft-list-filter-bar">
            <div class="toolbar-primary">
                <label class="search-wrap">
                    <span class="search-icon">⌕</span>
                    <input class="search" type="search" wire:model.live.debounce.650ms="search" autocomplete="off" placeholder="Search filtered Inquiry tasks" aria-label="Search filtered Inquiry tasks">
                    @if($search !== '')<button class="clear" type="button" wire:click="clearSearch">Clear</button>@endif
                </label>
                <div class="quick-filters" aria-label="Active dashboard task filter">
                    <span class="chip active">Inquiry tasks</span>
                    <span class="chip active">Status: {{ $statusFilter }}</span>
                </div>
            </div>
            <div class="toolbar-secondary">
                <select class="sort" wire:model.live="sort" aria-label="Sort filtered Inquiry tasks">
                    <option value="action">Sort: Action priority</option>
                    <option value="due">Sort: Due soon</option>
                    <option value="job">Sort: Inquiry number</option>
                </select>
                <button type="button" class="chip clear-filters" wire:click="clearStatusFilter">Clear status filter</button>
            </div>
        </div>

        <div class="load-state">
            <span><strong>{{ $statusFilter }}</strong> Inquiry tasks</span>
            <span class="loading-copy">
                <span wire:loading.delay.long wire:target="search,sort,clearSearch,clearStatusFilter"><i class="spinner"></i> Updating tasks…</span>
            </span>
        </div>

        <div class="work-progress" wire:loading.delay.long.flex wire:target="search,sort,clearSearch,clearStatusFilter" aria-live="polite"><span></span> Updating tasks…</div>

        <section class="list-shell" aria-label="My Inquiry Tasks filtered by status" wire:loading.class="is-refreshing" wire:target="search,sort,clearSearch,clearStatusFilter">
            <div class="task-table-scroll">
                <div class="task-head"><span>Task</span><span>Phase</span><span>Assignee</span><span>Due</span><span>Status</span><span>Flag</span><span>Updated</span><span>View</span></div>
                <div>
                    @include('livewire.my-work._inquiry-groups', ['inquiryGroups' => $inquiryGroups])
                    @if($inquiryGroups->isEmpty())
                        <div class="empty"><strong>No matching Inquiry tasks</strong>No My Task records currently use the selected status.</div>
                    @endif
                </div>
            </div>
            <footer class="footer">
                <span>{{ $inquiryVisibleTaskCount }} {{ $inquiryVisibleTaskCount === 1 ? 'task' : 'tasks' }} with status “{{ $statusFilter }}”</span>
            </footer>
        </section>
    </section>
    @else

    {{-- Keep the current workflow-stage overview exactly as the current My Tasks design.
         Only the table/filter area below is restored to the previous design. --}}
    <x-orders.workflow-stage-overview
        :stages="$taskStages"
        :selected-stage-value="$phaseFilter"
        mode="wire-filter"
        filter-method="setPhaseFilter"
        title="My tasks by workflow stage"
        description="Click a stage to filter the tasks below on this page."
        count-label="Open tasks"
    />

    <section class="work-view" aria-busy="false">
        <div class="toolbar ft-list-filter-bar">
            <div class="toolbar-primary">
                <label class="search-wrap">
                    <span class="search-icon">⌕</span>
                    <input class="search" type="search" wire:model.live.debounce.650ms="search" autocomplete="off" placeholder="Search tasks, Orders, clients or flags" aria-label="Search my work">
                    @if($search !== '')<button class="clear" type="button" wire:click="clearSearch">Clear</button>@endif
                </label>
            </div>
        </div>

        @if($phaseFilter !== '')
            @php
                $hiddenTaskStatusFilters = [
                    '', 'not start', 'not started', 'not ready', 'locked', 'skipped',
                    'not applicable', 'n/a', 'completed', 'cancelled', 'canceled',
                    'waiting for sample approval', 'waiting for qc issue resolution',
                ];
                $stageTaskStatusOptions = collect($statusOptions)
                    ->filter(fn ($statusOption) => ! in_array(mb_strtolower(trim((string) $statusOption)), $hiddenTaskStatusFilters, true))
                    ->values();
                $selectedMyTaskStage = collect($taskStages ?? [])->first(
                    fn ($stage) => mb_strtolower(trim((string) data_get($stage, 'name'))) === mb_strtolower(trim($phaseFilter))
                );
                $selectedMyTaskStageSequence = (int) data_get($selectedMyTaskStage, 'sequence', 0);
            @endphp
            <div class="ft-order-list-v5 my-task-stage-filter-parity">
                <div class="stage-inline-controls" aria-label="{{ $phaseFilter }} task filters">
                    <span class="stage-inline-label">{{ $phaseFilter }}</span>
                    <div class="stage-inline-quick" role="group" aria-label="Task status">
                        <button
                            type="button"
                            class="stage-inline-chip {{ $statusFilter === '' ? 'active' : '' }}"
                            style="--quick-color:#0F8F7C"
                            wire:click="setTaskStatusFilter('')"
                            aria-pressed="{{ $statusFilter === '' ? 'true' : 'false' }}"
                        >
                            <span class="stage-inline-check" aria-hidden="true">✓</span>
                            <span>All</span>
                        </button>
                        @foreach($stageTaskStatusOptions as $statusOption)
                            @php
                                $taskStatusColor = \App\Support\MasterColor::normalize(
                                    app(\App\Services\MasterDataService::class)->colorFor('order_task_status', (string) $statusOption)
                                ) ?: '#0F8F7C';
                            @endphp
                            <button
                                type="button"
                                class="stage-inline-chip {{ $statusFilter === $statusOption ? 'active' : '' }}"
                                style="--quick-color:{{ $taskStatusColor }}"
                                wire:click='setTaskStatusFilter(@js($statusOption))'
                                aria-pressed="{{ $statusFilter === $statusOption ? 'true' : 'false' }}"
                            >
                                <span class="stage-inline-check" aria-hidden="true">✓</span>
                                <span>{{ $statusOption }}</span>
                            </button>
                        @endforeach
                    </div>
                    <span class="stage-view-note">Row colors match task status</span>

                    <div class="stage-inline-selects">
                        <div class="stage-filter-field">
                            <span class="stage-filter-caption">Supplier</span>
                            <x-ui.search-select
                                class="ft-order-v5-stage-search-select ft-order-v5-supplier-filter"
                                label="Supplier"
                                property="stageSupplier"
                                type="suppliers"
                                context="order-list"
                                :value="$stageSupplier"
                                placeholder="All suppliers"
                                :initial-options="$stageSupplierOptions"
                                search-placeholder="Search supplier..."
                                footer-message="Type 2 characters to search suppliers."
                                :hide-label="true"
                                :fixed-menu="true"
                                :menu-width="320"
                                wire:key="my-task-stage-supplier-{{ $selectedMyTaskStageSequence }}-{{ filled($stageSupplier) ? $stageSupplier : 'all' }}"
                            />
                        </div>

                        <div class="stage-filter-field stage-filter-field-user">
                            <span class="stage-filter-caption">{{ $phaseFilter }} assignee</span>
                            <x-ui.search-select
                                class="ft-order-v5-stage-search-select ft-order-v5-stage-assignee-filter"
                                :label="$phaseFilter.' assignee'"
                                property="stageAssignee"
                                type="users"
                                context="order-list-user-filter"
                                :value="$stageAssignee"
                                :placeholder="'All '.strtolower($phaseFilter).' assignees'"
                                :initial-options="$stageAssigneeOptions"
                                :show-avatar="true"
                                search-placeholder="Search user..."
                                footer-message="All active FlowTrack users are available."
                                :hide-label="true"
                                :fixed-menu="true"
                                :menu-width="340"
                                wire:key="my-task-stage-assignee-{{ $selectedMyTaskStageSequence }}-{{ filled($stageAssignee) ? $stageAssignee : 'all' }}"
                            />
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="load-state">
            <span></span>
            <span class="load-actions">
                <span class="loading-copy">
                    <span wire:loading.delay.long wire:target="search,phaseFilter,statusFilter,stageSupplier,stageAssignee,quick,sort,hideCompleted,setMetricFilter,setPhaseFilter,setTaskStatusFilter,setQuick,clearFilters,clearSearch,gotoPage,previousPage,nextPage"><i class="spinner"></i> Updating tasks…</span>
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

        <div class="work-progress" wire:loading.delay.long.flex wire:target="search,phaseFilter,statusFilter,stageSupplier,stageAssignee,sort,hideCompleted,setMetricFilter,setTaskStatusFilter,setQuick,clearFilters,clearSearch,gotoPage,previousPage,nextPage" aria-live="polite"><span></span> Updating tasks…</div>

        <section class="list-shell" aria-label="My Tasks grouped by Order" wire:loading.class="is-refreshing" wire:target="search,phaseFilter,statusFilter,stageSupplier,stageAssignee,sort,hideCompleted,setMetricFilter,setTaskStatusFilter,setQuick,clearFilters,clearSearch,gotoPage,previousPage,nextPage">
            <div class="task-table-scroll">
                <div class="task-head"><span>Task</span><span>Phase</span><span>Assignee</span><span>Due</span><span>Status</span><span>Flag</span><span>Updated</span><span>View</span></div>

                <div>
                @foreach($workGroups as $group)
                    <article class="order-group" wire:key="my-work-order-{{ $group['id'] }}" x-data="{ open: true }" x-effect="open = groupsExpanded">
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
                                    wire:key="my-work-task-{{ $task['id'] }}"
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
                                                // Keep the renderless status update, but re-query once when
                                                // completion changes list membership. This removes the task now,
                                                // and removes its Order group too if it was the final visible task.
                                                if(result.refresh || (result.completed && @js($hideCompleted)))await $wire.$refresh();
                                            }catch(error){select.value=previous;window.FlowTrack.ui.masterColor?.applySelect(select);}
                                            finally{this.saving=false;select.disabled=false;}
                                        }
                                    }"
                                    x-bind:class="{ 'saving': saving }"
                                    x-on:my-work-task-version.stop="if ($event.detail?.version) version = String($event.detail.version)"
                                >
                                    <div class="task-main">
                                        <a class="task-link" href="{{ $task['route'] }}" wire:navigate>{{ $task['title'] }}</a>
                                        <span class="task-ref">{{ $task['number'] }}</span>
                                    </div>
                                    <span class="phase ft-phase-color-label" data-label="Phase" style="{{ \App\Support\MasterColor::style($task['phaseColor'] ?? null) }}">{{ $task['phase'] }}</span>
                                    <div
                                        class="assignee assignee-editor ft-inline-edit-shell"
                                        wire:key="my-work-task-{{ $task['id'] }}-assignee-{{ $task['assigneeId'] ?: 0 }}"
                                        data-label="Assignee"
                                        title="{{ $task['assignee'] }}"
                                        x-data="window.FlowTrack.ui.inlineEdit({ key: @js('my-work-task-'.$task['id'].'-assignee'), label: 'task assignee', value: @js($task['assigneeId'] ?? ''), display: @js($task['assignee']), avatarUrl: @js($task['assigneeAvatar'] ?? '') })"
                                        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                                        x-on:click.outside="if (editing) cancelEdit()"
                                        x-on:ft-inline-remote-cancel.stop="cancelEdit()"
                                        x-on:ft-inline-remote-selected.stop="commit(String($event.detail?.value ?? ''), String($event.detail?.label ?? 'Unassigned'), () => $wire.updateTaskAssignee({{ $task['id'] }}, draftValue, version), { avatarUrl: String($event.detail?.avatarUrl ?? '') }).then(async (ok) => { if (!ok) return; if (lastResponse?.version) $dispatch('my-work-task-version', { version: lastResponse.version }); if (lastResponse?.refresh) await $wire.$refresh(); })"
                                    >
                                        <div class="assignee-display" x-show="!editing">
                                            <span class="assignee-avatar"><x-ui.inline-live-avatar :size="22" /></span>
                                            <span class="assignee-name" x-text="display">{{ $task['assignee'] }}</span>
                                            @if($task['canAssign'])
                                                <button x-show="!editing" :disabled="status === 'saving'" type="button" class="ft-inline-edit-button compact assignee-edit-button" title="Edit assignee" aria-label="Edit assignee for {{ $task['title'] }}" x-on:click.stop="openRemotePicker($event.currentTarget)">✎</button>
                                            @endif
                                        </div>
                                        @if($task['canAssign'])
                                            <div x-cloak x-show="editing" class="assignee-picker">
                                                <x-ui.inline-remote-user
                                                    :value="$task['assigneeId'] ?? ''"
                                                    :selected-label="$task['assignee']"
                                                    parent-type="job"
                                                    :parent-id="$group['id']"
                                                    trigger-class="assignee-picker-trigger"
                                                    variant="compact"
                                                    :menu-width="280"
                                                />
                                            </div>
                                            <x-ui.inline-save-state compact />
                                        @endif
                                    </div>
                                    <span
                                        class="due-editor ft-inline-edit-shell {{ $task['dueTone'] }}" data-label="Due"
                                        x-data="window.FlowTrack.ui.inlineEdit({ key: @js('my-work-task-'.$task['id'].'-due-date'), label: 'task due date', value: @js($task['dueValue']), display: @js($task['dueDisplay']) })"
                                        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                                    >
                                        <span x-show="!editing" x-text="display" class="ft-task-inline-display">{{ $task['dueDisplay'] }}</span>
                                        @if($task['canEdit'])
                                            <button x-show="!editing" :disabled="status === 'saving'" type="button" class="ft-inline-edit-button compact" title="Edit due date" aria-label="Edit due date for {{ $task['title'] }}" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.myWorkDue.showPicker ? $refs.myWorkDue.showPicker() : $refs.myWorkDue.focus())">✎</button>
                                            <input x-ref="myWorkDue" x-cloak x-show="editing" x-model="draftValue" class="ft-task-inline-input" type="date"
                                                x-on:keydown.escape.prevent="cancelEdit()"
                                                x-on:blur="if (editing) cancelEdit()"
                                                x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateTaskDueDate({{ $task['id'] }}, draftValue))">
                                            <x-ui.inline-save-state compact />
                                        @endif
                                    </span>
                                    <span class="status-wrap" data-label="Status">
                                        <select data-master-color-select class="status-select {{ $task['statusColor'] ? 'ft-master-color' : '' }}" style="{{ \App\Support\MasterColor::style($task['statusColor']) }}" @if($task['canEdit']) x-on:change="saveStatus($event); window.FlowTrack.ui.masterColor?.applySelect($event.currentTarget)" @else disabled @endif aria-label="Status for {{ $task['title'] }}">
                                            @if(!in_array($task['status'], $statusOptions, true))<option value="{{ $task['status'] }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('order_task_status', $task['status']) }}" selected>{{ $task['status'] }}</option>@endif
                                            @foreach($statusOptions as $statusOption)<option value="{{ $statusOption }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('order_task_status', $statusOption) }}" @selected($statusOption === $task['status'])>{{ $statusOption }}</option>@endforeach
                                        </select>
                                    </span>
                                    <span class="flag {{ $task['flagColor'] ? 'ft-master-color' : $task['flagTone'] }}" style="{{ \App\Support\MasterColor::style($task['flagColor']) }}" data-label="Flag">{{ $task['flag'] }}</span>
                                    <span class="updated" data-label="Updated">{{ $task['updated'] }}</span>
                                    <a class="row-action" href="{{ $task['route'] }}" wire:navigate><span class="row-action-desktop">Open</span><span class="row-action-mobile">Details</span><span class="row-action-arrow" aria-hidden="true">→</span></a>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach

                @if($workGroups->isEmpty())
                    <div class="empty"><strong>No matching work</strong>Try another task, Order, client, or flag.</div>
                @endif
                </div>
            </div>

            <footer class="footer">
                <span>
                    @if($workPaginator->total())
                        Orders {{ $workPaginator->firstItem() }}–{{ $workPaginator->lastItem() }} of {{ $workPaginator->total() }} · {{ $visibleTaskCount }} tasks on this page
                    @else
                        My Orders and tasks
                    @endif
                </span>
                @php
                    $currentPage = $workPaginator->currentPage();
                    $lastPage = max(1, $workPaginator->lastPage());
                    $pageStart = max(1, $currentPage - 2);
                    $pageEnd = min($lastPage, $currentPage + 2);
                @endphp
                <nav class="pages" aria-label="Pagination">
                    <button type="button" class="page-button" wire:click="previousPage('workPage')" @disabled($workPaginator->onFirstPage())>Previous</button>
                    @for($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++)
                        <button type="button" class="page-button {{ $pageNumber === $currentPage ? 'active' : '' }}" wire:click="gotoPage({{ $pageNumber }}, 'workPage')" @if($pageNumber === $currentPage) aria-current="page" @endif>{{ $pageNumber }}</button>
                    @endfor
                    <button type="button" class="page-button" wire:click="nextPage('workPage')" @disabled(!$workPaginator->hasMorePages())>Next</button>
                </nav>
            </footer>
        </section>
    </section>
    @endif

</div>
