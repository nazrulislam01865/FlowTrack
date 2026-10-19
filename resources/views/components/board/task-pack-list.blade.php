@props([
    'groups' => collect(),
    'paginator' => null,
    'statusOptions' => [],
    'taskCount' => 0,
    'allGroupsExpanded' => true,
    'groupStateKey' => 'open',
    'administratorView' => false,
    'embedded' => false,
    'showFooter' => true,
])

<section class="ft-board-taskpack-list-shell {{ $embedded ? 'is-embedded' : '' }}" aria-label="Task Pack tasks grouped by Order">
    <div class="ft-board-taskpack-task-head">
        <span>Task</span>
        <span>Assignee</span>
        <span>Phase</span>
        <span>Due</span>
        <span>Status</span>
        <span>Flag</span>
        <span>Updated</span>
        <span>View</span>
    </div>

    <div>
        @forelse($groups as $group)
            <article
                class="ft-board-taskpack-job-group"
                wire:key="board-task-pack-job-{{ $group['id'] }}-{{ $groupStateKey }}"
                x-data="{ open: @js((bool) $allGroupsExpanded) }"
            >
                <header class="ft-board-taskpack-job-head">
                    <button
                        type="button"
                        class="ft-board-taskpack-collapse"
                        x-on:click="open = !open"
                        x-bind:aria-expanded="open.toString()"
                        aria-label="Toggle {{ $group['number'] }}"
                    ><span x-text="open ? '⌄' : '›'">⌄</span></button>

                    <span class="ft-board-taskpack-job-identity">
                        @if($group['route'])
                            <a class="ft-board-taskpack-job-id" href="{{ $group['route'] }}" wire:navigate>{{ $group['number'] }}</a>
                        @else
                            <span class="ft-board-taskpack-job-id">{{ $group['number'] }}</span>
                        @endif
                        <span class="ft-board-taskpack-job-title">{{ $group['title'] }}</span>
                    </span>

                    <span class="ft-board-taskpack-job-client">{{ $group['client'] }}</span>
                    <span class="ft-board-taskpack-job-stage">{{ $group['stage'] }}</span>
                    <span class="ft-board-taskpack-progress"><i><i style="width:{{ $group['progress'] }}%"></i></i>{{ $group['progress'] }}%</span>
                    <span class="ft-board-taskpack-task-count">{{ $group['taskCount'] }} {{ $group['taskCount'] === 1 ? 'task' : 'tasks' }}</span>
                </header>

                <div class="ft-board-taskpack-task-rows" x-show="open">
                    @foreach($group['tasks'] as $task)
                        <div
                            class="ft-board-taskpack-task-row"
                            style="{{ \App\Support\MasterColor::style($task['taskColor'] ?? null) }}border-left:4px solid var(--ft-master-color,#2563EB)"
                            wire:key="board-task-pack-task-{{ $task['id'] }}"
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
                                    }catch(error){select.value=previous;window.FlowTrack.ui.masterColor?.applySelect(select);}
                                    finally{this.saving=false;select.disabled={{ $task['canEdit'] ? 'false' : 'true' }};}
                                }
                            }"
                            x-bind:class="{ 'saving': saving }"
                        >
                            <div class="ft-board-taskpack-task-main">
                                @if($task['route'])
                                    <a class="ft-board-taskpack-task-link" href="{{ $task['route'] }}" wire:navigate>{{ $task['title'] }}</a>
                                @else
                                    <span class="ft-board-taskpack-task-link is-static">{{ $task['title'] }}</span>
                                @endif
                                <span class="ft-board-taskpack-task-ref">{{ $task['number'] }}</span>
                            </div>

                            <span class="ft-board-taskpack-assignee">
                                <b>{{ $task['assignee'] }}</b>
                                @if($task['isMine'])<small>You</small>@endif
                            </span>

                            <span class="ft-board-taskpack-phase ft-phase-color-label" style="{{ \App\Support\MasterColor::style($task['phaseColor'] ?? null) }}">{{ $task['phase'] }}</span>
                            <time class="ft-board-taskpack-due {{ $task['dueTone'] }}">{{ $task['due'] }}</time>

                            <select
                                data-master-color-select
                                class="ft-board-taskpack-status-select {{ $task['statusColor'] ? 'ft-master-color' : '' }}"
                                style="{{ \App\Support\MasterColor::style($task['statusColor']) }}"
                                @if($task['canEdit']) x-on:change="saveStatus($event); window.FlowTrack.ui.masterColor?.applySelect($event.currentTarget)" @else disabled title="Read only" @endif
                                aria-label="Status for {{ $task['title'] }}"
                            >
                                @if(!in_array($task['status'], $statusOptions, true))
                                    <option value="{{ $task['status'] }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('order_task_status', $task['status']) }}" selected>{{ $task['status'] }}</option>
                                @endif
                                @foreach($statusOptions as $statusOption)
                                    <option value="{{ $statusOption }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('order_task_status', $statusOption) }}" @selected($statusOption === $task['status'])>{{ $statusOption }}</option>
                                @endforeach
                            </select>

                            <span class="ft-board-taskpack-flag {{ $task['flagColor'] ? 'ft-master-color' : $task['flagTone'] }}" style="{{ \App\Support\MasterColor::style($task['flagColor']) }}">{{ $task['flag'] }}</span>
                            <span class="ft-board-taskpack-updated">{{ $task['updated'] }}</span>

                            @if($task['route'])
                                <a class="ft-board-taskpack-row-action" href="{{ $task['route'] }}" wire:navigate>Open</a>
                            @else
                                <span class="ft-board-taskpack-row-action is-disabled" title="Order detail access is not enabled for your role">—</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>
        @empty
            <div class="ft-board-taskpack-empty">
                <strong>No matching Task Pack work</strong>
                @if($administratorView)
                    Try another Order, client, assignee, status, or due-date filter.
                @else
                    Only Orders containing at least one task assigned to you are available here.
                @endif
            </div>
        @endforelse
    </div>

    @if($paginator && $showFooter)
        <footer class="ft-board-taskpack-footer">
            <span>
                @if($paginator->total())
                    Orders {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }} · {{ $taskCount }} tasks on this page
                @elseif($administratorView)
                    All active Order task lists
                @else
                    Associated Order task lists
                @endif
            </span>

            @php
                $currentPage = $paginator->currentPage();
                $lastPage = max(1, $paginator->lastPage());
                $pageStart = max(1, $currentPage - 2);
                $pageEnd = min($lastPage, $currentPage + 2);
            @endphp

            <nav class="ft-board-taskpack-pages" aria-label="Task Pack pagination">
                <button type="button" class="ft-board-taskpack-page-button" wire:click="previousPage('taskPackPage')" @disabled($paginator->onFirstPage())>Previous</button>
                @for($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++)
                    <button
                        type="button"
                        class="ft-board-taskpack-page-button {{ $pageNumber === $currentPage ? 'active' : '' }}"
                        wire:click="gotoPage({{ $pageNumber }}, 'taskPackPage')"
                        @if($pageNumber === $currentPage) aria-current="page" @endif
                    >{{ $pageNumber }}</button>
                @endfor
                <button type="button" class="ft-board-taskpack-page-button" wire:click="nextPage('taskPackPage')" @disabled(!$paginator->hasMorePages())>Next</button>
            </nav>
        </footer>
    @endif
</section>
