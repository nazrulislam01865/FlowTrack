        <section class="view">
            <div class="pagehead">
                <div><h1>Inquiries</h1><p>Manage client requests from first inquiry through tasks, conversion, or closure.</p></div>
                <div class="actions">
                    @if(auth()->user()->canModule('reports', 'export'))
                        <x-ui.list-export-period-modal
                            :action="route('inquiries.export')"
                            :filters="$inquiryExportQuery"
                            button-class="secondary ft-list-export-button"
                            entity-label="inquiries"
                        />
                    @endif
                    @if(auth()->user()->canModule('inquiries','create'))<button class="primary" type="button" wire:click="openCreate">＋ New Inquiry</button>@endif
                </div>
            </div>

            <div class="metrics ft-summary-card-grid ft-inquiry-summary-grid" aria-label="Inquiry summary filters">
                <x-ui.summary-card label="Created Today" :value="$metrics['createdToday'] ?? 0" icon="created" tone="blue" caption="New inquiries received" :active="$metricFilter === 'createdToday'" wire:click="setMetricFilter('createdToday')" aria-pressed="{{ $metricFilter === 'createdToday' ? 'true' : 'false' }}" />
                <x-ui.summary-card label="Not Started" :value="$metrics['notStarted'] ?? 0" icon="not-started" tone="slate" caption="Waiting for first action" :active="$metricFilter === 'notStarted'" wire:click="setMetricFilter('notStarted')" aria-pressed="{{ $metricFilter === 'notStarted' ? 'true' : 'false' }}" />
                <x-ui.summary-card label="In Progress" :value="$metrics['inProgress'] ?? 0" icon="in-progress" tone="blue" caption="Work currently underway" :active="$metricFilter === 'inProgress'" wire:click="setMetricFilter('inProgress')" aria-pressed="{{ $metricFilter === 'inProgress' ? 'true' : 'false' }}" />
                <x-ui.summary-card label="Due This Week" :value="$metrics['dueThisWeek'] ?? 0" icon="due-week" tone="amber" caption="Required date this week" :active="$metricFilter === 'dueThisWeek'" wire:click="setMetricFilter('dueThisWeek')" aria-pressed="{{ $metricFilter === 'dueThisWeek' ? 'true' : 'false' }}" />
                <x-ui.summary-card label="Completed" :value="$metrics['completed'] ?? 0" icon="completed" tone="green" caption="All completed inquiries" :active="$metricFilter === 'completed'" wire:click="setMetricFilter('completed')" aria-pressed="{{ $metricFilter === 'completed' ? 'true' : 'false' }}" />
                <x-ui.summary-card label="Needs Attention" :value="$metrics['attention'] ?? 0" icon="attention" tone="red" caption="Blocked, overdue or unassigned" :active="$metricFilter === 'attention'" wire:click="setMetricFilter('attention')" aria-pressed="{{ $metricFilter === 'attention' ? 'true' : 'false' }}" />
            </div>

            <div class="shell inquiry-list-v2">
                <div class="toolbar">
                    <x-ui.search-input
                        class="search ft-inquiry-search-control"
                        property="search"
                        :value="$search"
                        label="Search inquiries"
                        placeholder="Search inquiry, title, client, task or assignee"
                        :debounce="350"
                        :hide-label="true"
                    />
                    <x-ui.filter-bar class="filters inquiry-filter-controls" label="Inquiry filters">
                        <div class="ft-inquiry-filter-grid">
                            <div class="ft-inquiry-filter-group ft-inquiry-filter-group--quick" role="group" aria-label="Inquiry view">
                                <x-ui.filter-chip class="chip" :active="$metricFilter === '' && $inquiryToolbarIsClear" wire:click="setQuick('all')">All</x-ui.filter-chip>
                                <x-ui.filter-chip class="chip ft-inquiry-attention-filter" :active="$quick === 'attention'" wire:click="setQuick('attention')">
                                    <span aria-hidden="true">⚠</span> Attention needed
                                </x-ui.filter-chip>
                            </div>

                            <div class="ft-inquiry-filter-field ft-inquiry-filter-field--status">
                                <x-ui.search-select
                                    class="ft-inquiry-status-filter"
                                    label="Task status"
                                    property="pendingListStatus"
                                    :value="$pendingListStatus"
                                    placeholder="All task statuses"
                                    :options="collect($listStatusOptions)->map(fn ($statusOption) => ['id' => $statusOption, 'label' => $statusOption])"
                                    :hide-label="true"
                                    :fixed-menu="true"
                                    :menu-width="220"
                                />
                            </div>

                            <div class="ft-inquiry-filter-field ft-inquiry-filter-field--client">
                                <x-ui.search-select
                                    class="ft-inquiry-list-client-filter"
                                    label="Client"
                                    property="pendingListClient"
                                    type="clients"
                                    context="inquiries"
                                    action="setInquiryPendingListFilter"
                                    :value="$pendingListClient"
                                    placeholder="All clients"
                                    :selected-label="$pendingListClientLabel ?: null"
                                    :initial-options="$listClientFilterOptions"
                                    :menu-width="300"
                                    :fixed-menu="true"
                                    wire:key="inquiry-list-client-filter-{{ $pendingListClient ?: 'all' }}-{{ substr(md5($pendingListClientLabel ?: 'all'), 0, 8) }}"
                                />
                            </div>

                            <div class="ft-inquiry-filter-group ft-inquiry-filter-group--dates" role="group" aria-label="Created date filter">
                                <x-ui.date-range
                                    class="ft-inquiry-date-range"
                                    from-property="pendingDateFrom"
                                    to-property="pendingDateTo"
                                    :from-value="$pendingDateFrom"
                                    :to-value="$pendingDateTo"
                                    label="Created date"
                                    from-label="From"
                                    to-label="To"
                                />
                            </div>

                            <label class="completed-toggle ft-inquiry-hide-completed {{ $pendingHideCompleted ? 'active' : '' }}">
                                <input type="checkbox" wire:model.live="pendingHideCompleted" aria-label="Hide completed inquiries">
                                <span class="completed-check" aria-hidden="true">✓</span>
                                <span>Hide completed</span>
                            </label>

                            <div class="ft-inquiry-filter-actions ft-inquiry-filter-group ft-inquiry-filter-group--actions" aria-label="Inquiry filter actions">
                                <button
                                    type="button"
                                    class="ft-inquiry-filter-action ft-inquiry-apply-filter"
                                    wire:click="applyFilters"
                                    wire:loading.attr="disabled"
                                    wire:target="applyFilters"
                                    @disabled(! $inquiryFilterDraftDirty)
                                    aria-label="Apply inquiry filter"
                                >Apply filter</button>
                                <x-ui.filter-reset
                                    class="chip ft-inquiry-filter-action ft-inquiry-clear-filter"
                                    action="clearFilters"
                                    label="Clear"
                                    icon="×"
                                    :disabled="! $inquiryAnyFilterActive"
                                    aria-label="Clear active inquiry filter"
                                />
                            </div>
                        </div>
                    </x-ui.filter-bar>
                </div>
                <div class="inquiry-list-table" role="region" aria-label="Inquiry list" tabindex="0">
                    <div class="listhead">
                        <div>Inquiry</div>
                        <div>Title</div>
                        <div>Client / Item</div>
                        <div>Priority</div>
                        <div>Due Date</div>
                        <div>Status</div>
                        <div>Flag</div>
                        <div>Current Task</div>
                        <div>Assignee</div>
                        <div>Task Status</div>
                        <div>Started At</div>
                        <div>Progress</div>
                        <div>Updated At</div>
                        <div aria-label="Actions"></div>
                    </div>
                    <div class="inquiry-list-body">
                        @forelse($inquiryRows as $row)
                            @php
                                $clientCode = strtoupper(trim((string) ($row['clientCode'] ?? '')));
                                $clientName = strtoupper(trim((string) ($row['client'] ?? '')));
                                $clientRowTone = ($clientCode === 'IID' || preg_match('/\bIID\b/i', $clientName))
                                    ? 'iid'
                                    : (($clientCode === 'NEP' || preg_match('/\bNEP\b/i', $clientName)) ? 'nep' : '');
                                $hasCompletedTask = (bool) ($row['hasCompletedTask'] ?? false);

                                // Client color is the visual baseline for an Inquiry:
                                // IID = light green, NEP = light blue. Once work advances,
                                // only a configured ACTIVE Task Pack task color may override
                                // that client tone. Do not fall back to Inquiry/Completed status
                                // color here, otherwise every fully-completed NEP Inquiry turns
                                // green and becomes visually indistinguishable from IID.
                                $taskDrivenRowColor = $hasCompletedTask
                                    ? \App\Support\MasterColor::normalize((string) ($row['activeTaskColor'] ?? ''))
                                    : null;
                                $useClientBaseTone = $clientRowTone !== '' && blank($taskDrivenRowColor);
                            @endphp
                            <article
                                @class([
                                    'row',
                                    'ft-client-row-'.$clientRowTone => $useClientBaseTone,
                                    'has-task-color' => filled($taskDrivenRowColor),
                                ])
                                style="{{ \App\Support\MasterColor::taskRowStyle($taskDrivenRowColor) }}"
                                wire:key="inquiry-list-{{ $row['id'] }}"
                            >
                                <div class="cell ft-inquiry-list-identity" data-label="Inquiry">
                                    <span class="ft-copyable-id-wrap ft-inquiry-list-code-wrap">
                                        <a class="id" href="{{ route('inquiries.index', ['open' => $row['id']]) }}" wire:navigate>{{ $row['number'] }}</a>
                                        <button type="button" class="ft-copy-id-btn" title="Copy Inquiry ID" aria-label="Copy {{ $row['number'] }}" onclick="event.preventDefault(); event.stopPropagation(); navigator.clipboard?.writeText(@js($row['number'])); this.classList.add('copied'); setTimeout(()=>this.classList.remove('copied'),900)">⧉</button>
                                    </span>
                                    <span class="sub ft-inquiry-created-by" title="Created by {{ $row['createdBy'] }}">Created by {{ $row['createdBy'] }}</span>
                                    <span class="sub ft-inquiry-created-at">{{ $row['createdDate'] }} · {{ $row['createdTime'] }}</span>
                                </div>
                                <div class="cell ft-inquiry-list-title-cell" data-label="Title">
                                    <span class="title ft-inquiry-title-preview ft-inquiry-title-desktop" title="{{ $row['title'] }}">{{ $row['titlePreview'] }}</span>
                                    <span class="title ft-inquiry-title-mobile" title="{{ $row['title'] }}">{{ $row['title'] }}</span>
                                    <span class="sub ft-inquiry-mobile-created">Created by {{ $row['createdBy'] }} · {{ $row['createdDate'] }} · {{ $row['createdTime'] }}</span>
                                </div>
                                <div class="ft-inquiry-mobile-separator ft-inquiry-mobile-separator-before-task" aria-hidden="true"></div>
                                <div class="cell ft-inquiry-list-client-cell" data-label="Client / Item">
                                    <span class="ft-client-name-with-logo"><x-ui.client-logo :name="$row['client']" :src="$row['clientLogoUrl'] ?? null" :size="24" /><span class="title">{{ $row['client'] }}</span></span>
                                    <span class="sub">Contact: {{ $row['clientContact'] ?: '—' }}</span>
                                    @if($row['item'])<span class="sub">{{ $row['item'] }}</span>@endif
                                </div>
                                @php
                                    $rowTaskStatusColor = $masterData->displayColorFor('inquiry_task_status', $row['taskStatus']);
                                    $rowTaskFlagTone = match ($row['flag']) {
                                        'Requires attention', 'Overdue' => 'red',
                                        'Due Today' => 'amber',
                                        'No flag' => 'green',
                                        default => 'blue',
                                    };
                                    $rowInquiryPriorityColor = $masterData->displayColorFor('priority', $row['priority']);
                                    $rowInquiryStatusColor = $row['statusColor'] ?? null;
                                @endphp
                                <div class="cell ft-inquiry-list-priority-cell" data-label="Priority"><span class="pill {{ $rowInquiryPriorityColor ? 'ft-master-color' : $priorityTone($row['priority']) }}" style="{{ \App\Support\MasterColor::style($rowInquiryPriorityColor) }}">{{ $row['priority'] }}</span></div>
                                <div class="cell ft-inquiry-list-due-cell" data-label="Due Date"><span class="title">{{ $row['due'] }}</span></div>
                                <div class="cell ft-inquiry-list-status-cell" data-label="Status"><span class="pill {{ $rowInquiryStatusColor ? 'ft-master-color' : $tone($row['status']) }}" style="{{ \App\Support\MasterColor::style($rowInquiryStatusColor) }}">{{ $row['status'] }}</span></div>
                                <div class="cell ft-inquiry-list-flag-cell" data-label="Flag">
                                    @if($row['flag'] === 'No flag')
                                        <span class="ft-inquiry-no-flag">No flag</span>
                                    @else
                                        <span class="pill {{ $rowTaskFlagTone }}">{{ $row['flag'] }}</span>
                                    @endif
                                </div>
                                <div class="cell ft-inquiry-list-task-cell" data-label="Current Task"><span class="title">{{ $row['currentTask'] }}</span><span class="sub">{{ $row['taskCaption'] }}</span></div>
                                <div class="cell ft-inquiry-list-assignee-cell" data-label="Assignee">
                                    <div class="ownerline">
                                        <x-ui.avatar
                                            class="ft-inquiry-assignee-avatar"
                                            :name="$row['assignee']"
                                            :src="$row['assigneeAvatar'] ?? null"
                                            :size="34"
                                        />
                                        <span class="title" title="{{ $row['assignee'] }}">{{ $row['assignee'] }}</span>
                                    </div>
                                </div>
                                <div class="cell ft-inquiry-list-task-status-cell" data-label="Task Status"><span class="pill {{ $rowTaskStatusColor ? 'ft-master-color' : $tone($row['taskStatus']) }}" style="{{ \App\Support\MasterColor::style($rowTaskStatusColor) }}">{{ $row['taskStatus'] }}</span></div>
                                <div class="ft-inquiry-mobile-separator ft-inquiry-mobile-separator-after-task" aria-hidden="true"></div>
                                <div class="cell ft-inquiry-list-started-cell" data-label="Started At">
                                    @if($row['hasStarted'])
                                        <span class="title">{{ $row['startedDate'] }}</span>
                                        <span class="sub">{{ $row['startedTime'] }}</span>
                                    @else
                                        <span class="title ft-inquiry-not-started">Not Started</span>
                                    @endif
                                </div>
                                <div class="cell ft-inquiry-list-progress-cell" data-label="Progress">
                                    <div class="ft-inquiry-list-progress">
                                        <div class="ft-inquiry-list-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $row['progressPercent'] }}" aria-label="{{ $row['progress'] }} of {{ $row['total'] }} tasks completed"><span style="width:{{ $row['progressPercent'] }}%"></span></div>
                                        <b>{{ $row['progress'] }}/{{ $row['total'] }}</b>
                                    </div>
                                </div>
                                <div class="cell ft-inquiry-list-updated-cell" data-label="Updated At">
                                    <span class="title">{{ $row['updatedDate'] }}</span>
                                    <span class="sub">{{ $row['updatedTime'] }}</span>
                                </div>
                                <div class="ft-inquiry-mobile-separator ft-inquiry-mobile-separator-before-footer" aria-hidden="true"></div>
                                <div class="cell ft-inquiry-list-actions-cell" data-label="Actions" x-data="{ open: false }">
                                        <button
                                            class="ft-inquiry-row-action-trigger"
                                            type="button"
                                            :aria-expanded="open ? 'true' : 'false'"
                                            aria-haspopup="menu"
                                            aria-controls="inquiry-actions-{{ $row['id'] }}"
                                            aria-label="Actions for {{ $row['number'] }}"
                                            x-on:click.stop="
                                                const menu = $refs.menu;
                                                if (menu.matches(':popover-open')) { menu.hidePopover(); return; }
                                                const rect = $el.getBoundingClientRect();
                                                const menuWidth = 166;
                                                const menuHeight = {{ $canDeleteInquiries ? 88 : 46 }};
                                                const edge = 10;
                                                const gap = 6;
                                                const left = Math.min(window.innerWidth - menuWidth - edge, Math.max(edge, rect.right - menuWidth));
                                                const openAbove = (window.innerHeight - rect.bottom) < (menuHeight + gap + edge) && rect.top > (menuHeight + gap + edge);
                                                const top = openAbove ? rect.top - menuHeight - gap : rect.bottom + gap;
                                                menu.style.left = `${left}px`;
                                                menu.style.top = `${Math.max(edge, top)}px`;
                                                menu.showPopover();
                                            "
                                        >⋮</button>
                                        <div
                                            id="inquiry-actions-{{ $row['id'] }}"
                                            class="ft-inquiry-row-action-menu"
                                            x-ref="menu"
                                            popover="auto"
                                            role="menu"
                                            x-on:toggle="open = $event.newState === 'open'"
                                        >
                                            <a
                                                class="ft-inquiry-row-action-view"
                                                href="{{ route('inquiries.index', ['open' => $row['id']]) }}"
                                                role="menuitem"
                                                wire:navigate
                                                x-on:click="$refs.menu.hidePopover()"
                                                aria-label="View details for {{ $row['number'] }}"
                                            >
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                                                <span>View</span>
                                            </a>
                                            @if($canDeleteInquiries)
                                                <button class="ft-inquiry-row-action-danger" type="button" role="menuitem" x-on:click="$refs.menu.hidePopover()" wire:click="deleteInquiry({{ $row['id'] }})" wire:confirm="Delete {{ $row['number'] }}? This removes the inquiry from active lists. Any converted order remains available.">
                                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
                                                    <span>Delete inquiry</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                            </article>
                        @empty
                            <div class="ft-inquiry-list-empty">No matching inquiries.</div>
                        @endforelse
                    </div>
                </div>
                <div class="footer">
                    <span>Showing {{ $inquiryPaginator->firstItem() ?? 0 }}–{{ $inquiryPaginator->lastItem() ?? 0 }} of {{ $inquiryPaginator->total() }} inquiries</span>
                    <span>
                        @if($inquiryPaginator->lastPage() > 1)
                            <button class="chip" type="button" wire:click="previousPage('inquiryPage')" @disabled($inquiryPaginator->onFirstPage())>←</button>
                            Page {{ $inquiryPaginator->currentPage() }} of {{ $inquiryPaginator->lastPage() }}
                            <button class="chip" type="button" wire:click="nextPage('inquiryPage')" @disabled(!$inquiryPaginator->hasMorePages())>→</button>
                        @else
                            Page 1 of 1
                        @endif
                    </span>
                </div>
            </div>
        </section>

