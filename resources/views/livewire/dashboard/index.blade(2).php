@php
    $today = app(\App\Services\WorkspaceSettingsService::class)->localToday();
    $masterData = app(\App\Services\MasterDataService::class);
    $taskFlagService = app(\App\Services\TaskFlagService::class);
    $inquiryService = app(\App\Services\InquiryService::class);
    $canCreateOrder = auth()->user()->canAccess('jobs.create');
    $canCreateClient = auth()->user()->canModule('clients', 'create');
    $canCreateInquiry = auth()->user()->canModule('inquiries', 'create');

    $badgeTone = static function (?string $value): string {
        $value = mb_strtolower(trim((string) $value));
        if (str_contains($value, 'overdue') || str_contains($value, 'attention') || str_contains($value, 'blocked') || str_contains($value, 'risk')) return 'red';
        if (str_contains($value, 'due') || str_contains($value, 'waiting') || str_contains($value, 'hold') || str_contains($value, 'payment')) return 'amber';
        if (str_contains($value, 'complete') || str_contains($value, 'track') || str_contains($value, 'healthy') || str_contains($value, 'ready')) return 'green';
        if (str_contains($value, 'artwork') || str_contains($value, 'review') || str_contains($value, 'revision')) return 'purple';
        if (str_contains($value, 'unassigned') || str_contains($value, 'no flag')) return 'gray';
        return 'blue';
    };

    $taskFlag = static function ($task) use ($today, $taskFlagService, $badgeTone): array {
        $label = $taskFlagService->labelForTask($task);
        if ($label) return [$label, $badgeTone($label)];
        if ($task->due_date && $task->due_date->lt($today)) return ['Overdue '.$task->due_date->diffInDays($today).'d', 'red'];
        if ($task->due_date && $task->due_date->isSameDay($today)) return ['Due today', 'amber'];
        if ((bool) $task->needs_attention) return ['Needs attention', 'red'];
        return ['On track', 'green'];
    };

    $jobFlag = static function ($job) use ($taskFlagService, $badgeTone): array {
        if ((bool) ($job->attention_requested ?? false) || (bool) ($job->needs_attention ?? false)) return ['Needs attention', 'red'];
        $label = $taskFlagService->labelForOrder($job);
        if ($label) return [$label, $badgeTone($label)];
        return ['No flag', 'gray'];
    };

    $inquiryFlag = static function ($inquiry) use ($today): array {
        $task = $inquiry->currentTask;
        $status = mb_strtolower((string) ($task?->status ?: $inquiry->status));
        if ($task?->needs_attention) return ['Needs attention', 'red'];
        if ($task?->due_date && $task->due_date->lt($today)) return ['Overdue '.$task->due_date->diffInDays($today).'d', 'red'];
        if ($task?->due_date && $task->due_date->isSameDay($today)) return ['Due today', 'amber'];
        if (str_contains($status, 'block')) return ['Blocked', 'red'];
        if (str_contains($status, 'revision')) return ['Revision required', 'red'];
        if (str_contains($status, 'wait')) return ['Waiting', 'amber'];
        if (!$inquiry->owner_id) return ['Unassigned', 'gray'];
        return ['On track', 'green'];
    };

    $attentionOverdueLabel = static function ($dueDate) use ($today): string {
        $overdueDays = (float) $dueDate->diffInDays($today);

        return 'Overdue '.number_format($overdueDays, 2, '.', '').'d';
    };

    $attentionInquiryFlag = static function ($inquiry) use ($today, $inquiryFlag, $attentionOverdueLabel): array {
        $task = $inquiry->currentTask;
        if ($task?->due_date && $task->due_date->lt($today)) {
            return [$attentionOverdueLabel($task->due_date), 'red'];
        }

        return $inquiryFlag($inquiry);
    };

    $attentionJobFlag = static function ($job) use ($today, $jobFlag, $attentionOverdueLabel): array {
        $task = $job->tasks?->first();
        if ($task?->due_date && $task->due_date->lt($today)) {
            return [$attentionOverdueLabel($task->due_date), 'red'];
        }
        if ($task?->due_date && $task->due_date->isSameDay($today)) return ['Due today', 'amber'];
        $taskStatus = mb_strtolower(trim((string) ($task?->status ?? '')));
        if (str_contains($taskStatus, 'block')) return ['Blocked', 'red'];
        if ($task?->needs_attention) return ['Needs attention', 'red'];
        if ($job->delivery_date && $job->delivery_date->lt($today)) {
            return [$attentionOverdueLabel($job->delivery_date), 'red'];
        }
        if ($job->delivery_date && $job->delivery_date->isSameDay($today)) return ['Due today', 'amber'];
        if (!$job->owner_id) return ['Unassigned', 'gray'];
        return $jobFlag($job);
    };

    $attentionViewAllRoute = match ($attentionTab ?? 'all') {
        'inquiries' => route('inquiries.index', ['metric' => 'attention']),
        default => route('jobs.index', ['metric' => 'dashboardAttention']),
    };

    $orderTerminology = static function (?string $value): string {
        return preg_replace_callback('/\bjobs?\b/i', static function (array $match): string {
            return match ($match[0]) {
                'Jobs' => 'Orders', 'jobs' => 'orders', 'JOB' => 'ORDER', 'JOBS' => 'ORDERS',
                default => ctype_upper($match[0][0] ?? '') ? 'Order' : 'order',
            };
        }, (string) $value) ?: (string) $value;
    };

    $flowRows = collect($flowDistribution[$flowTab] ?? []);
    $flowMax = max(1, (int) $flowRows->max('count'));
    $flowRows = $flowRows->map(static function (array $row) use ($flowMax): array {
        $count = (int) ($row['count'] ?? 0);
        $scopeText = trim((string) ($row['scope_text'] ?? ''));

        return [
            'label' => (string) ($row['label'] ?? 'Unassigned'),
            'short_label' => (string) ($row['short_label'] ?? $row['label'] ?? 'Unassigned'),
            'color' => $row['color'] ?? null,
            'count' => $count,
            'width' => $count > 0 ? max(2, (int) round(($count / $flowMax) * 100)) : 0,
            'scope_text' => $scopeText,
            'scope_label' => trim((string) ($row['scope_label'] ?? '')),
            'is_mismatch' => (bool) ($row['is_mismatch'] ?? false),
        ];
    })->values();
    $selectedTaskStatusDistribution = $taskStatusDistribution[$taskStatusTab] ?? ['total' => 0, 'rows' => []];
    $statusRows = collect($selectedTaskStatusDistribution['rows'] ?? [])->values();
    $statusTotal = max(0, (int) ($selectedTaskStatusDistribution['total'] ?? 0));
    $cursor = 0.0;
    $gradientSegments = [];
    foreach ($statusRows as $row) {
        $count = max(0, (int) ($row['count'] ?? 0));
        if ($count <= 0 || $statusTotal <= 0) continue;
        $start = $cursor;
        $cursor += ($count / $statusTotal) * 100;
        $color = (string) ($row['color'] ?? '#64748B');
        $gradientSegments[] = $color.' '.$start.'% '.$cursor.'%';
    }
    if ($cursor < 100) $gradientSegments[] = '#edf2f5 '.$cursor.'% 100%';
    $donutBackground = $statusTotal > 0 ? 'conic-gradient('.implode(',', $gradientSegments).')' : '#edf2f5';
@endphp

<x-ui.management-theme class="ft-mgmt-dashboard">
    <div class="ft-mgmt-page-head">
        <div>
            <h1>Management Dashboard</h1>
            <p>Live operational overview across inquiries, orders, tasks, clients and product data.</p>
        </div>
        <div class="ft-mgmt-head-actions">
            @if($canCreateOrder)<a class="ft-mgmt-btn primary" href="{{ route('jobs.index', ['create' => 1]) }}" wire:navigate>＋ Create Order</a>@endif
            @if($canCreateInquiry)<a class="ft-mgmt-btn" href="{{ route('inquiries.index', ['create' => 1]) }}" wire:navigate>＋ Create Inquiry</a>@endif
            @if($canCreateClient)<a class="ft-mgmt-btn" href="{{ route('clients.index', ['create' => 1]) }}" wire:navigate>＋ Add Client</a>@endif
        </div>
    </div>

    <section class="ft-mgmt-control-bar" aria-label="Dashboard filters">
        <div class="ft-mgmt-range" wire:key="dashboard-range-control" wire:loading.class="is-loading" wire:target="setRange">
            <button type="button" wire:click="setRange(1)" wire:loading.attr="disabled" wire:target="setRange" aria-pressed="{{ $rangeDays === 1 ? 'true' : 'false' }}" class="{{ $rangeDays === 1 ? 'active' : '' }}">Today</button>
            <button type="button" wire:click="setRange(7)" wire:loading.attr="disabled" wire:target="setRange" aria-pressed="{{ $rangeDays === 7 ? 'true' : 'false' }}" class="{{ $rangeDays === 7 ? 'active' : '' }}">7 days</button>
            <button type="button" wire:click="setRange(30)" wire:loading.attr="disabled" wire:target="setRange" aria-pressed="{{ $rangeDays === 30 ? 'true' : 'false' }}" class="{{ $rangeDays === 30 ? 'active' : '' }}">30 days</button>
        </div>
        <x-ui.search-select
            class="ft-mgmt-remote-filter ft-mgmt-client-filter"
            label="Client"
            property="clientFilter"
            type="clients"
            context="dashboard"
            action="setDashboardFilter"
            :value="$clientFilter"
            placeholder="All clients"
            :initial-options="$dashboardClientFilterOptions"
            :menu-width="300"
            :fixed-menu="true"
            wire:key="dashboard-client-filter-{{ $clientFilter ?: 'all' }}"
        />
        <x-ui.search-select
            class="ft-mgmt-remote-filter ft-mgmt-team-filter"
            label="Team"
            property="teamFilter"
            type="departments"
            context="dashboard"
            action="setDashboardFilter"
            :value="$teamFilter"
            placeholder="All teams"
            :initial-options="$dashboardTeamFilterOptions"
            :menu-width="300"
            :fixed-menu="true"
            wire:key="dashboard-team-filter-{{ $teamFilter ?: 'all' }}"
        />
        <input class="ft-mgmt-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Search orders, inquiries or tasks" aria-label="Search dashboard">
    </section>

    <x-orders.workflow-stage-overview
        :stages="$orderStages"
        mode="navigate"
        :show-header="false"
        :navigation-query="$orderStageNavigationQuery"
    />



    <section class="ft-mgmt-panel ft-mgmt-panel-spaced">
        <div class="ft-mgmt-panel-head">
            <div><h2>Priority work</h2><p>Top urgent Orders, Inquiries and Tasks ranked by attention, due date and priority</p></div>
            <div class="ft-mgmt-tabs">
                <button type="button" wire:click="setPriorityTab('orders')" class="ft-mgmt-tab {{ $priorityTab === 'orders' ? 'active' : '' }}">Orders</button>
                <button type="button" wire:click="setPriorityTab('inquiries')" class="ft-mgmt-tab {{ $priorityTab === 'inquiries' ? 'active' : '' }}">Inquiries</button>
                <button type="button" wire:click="setPriorityTab('tasks')" class="ft-mgmt-tab {{ $priorityTab === 'tasks' ? 'active' : '' }}">Tasks</button>
            </div>
        </div>
        <div class="ft-mgmt-table-wrap">
            <table class="ft-mgmt-table">
                @if($priorityTab === 'orders')
                    <thead><tr><th>Order</th><th>Client</th><th>Stage</th><th>Progress</th><th>Attention</th><th>Owner</th><th>Delivery</th><th></th></tr></thead>
                    <tbody>
                        @forelse($priorityJobs as $job)
                            @php
                                [$flagLabel, $flagTone] = $jobFlag($job);
                            @endphp
                            <tr wire:key="mgmt-priority-job-{{ $job->id }}">
                                <td><a class="ft-mgmt-primary-text" href="{{ route('jobs.index', ['open' => $job->id]) }}" wire:navigate>{{ $job->displayOrderNumber() }}</a><div class="ft-mgmt-sub">{{ $job->title }}</div></td>
                                <td>{{ $job->client?->name ?? '—' }}</td><td><x-ui.phase-label :phase="$job->phase" short fallback="Unassigned" class="ft-mgmt-badge" /></td>
                                <td><div class="ft-mgmt-progress-cell"><div class="ft-mgmt-track"><span class="ft-mgmt-fill" style="width:{{ min(100, max(0, (int) $job->progress)) }}%"></span></div><b>{{ (int) $job->progress }}%</b></div></td>
                                <td><span class="ft-mgmt-badge {{ $flagTone }}">{{ $flagLabel }}</span></td>
                                <td>@if($job->owner)<span class="ft-mgmt-person"><x-ui.avatar :user="$job->owner" :name="$job->owner->name" :size="27" />{{ $job->owner->name }}</span>@else Unassigned @endif</td>
                                <td>{{ $job->delivery_date?->format('M j') ?? '—' }}</td><td><a class="ft-mgmt-tiny-action" href="{{ route('jobs.index', ['open' => $job->id]) }}" wire:navigate>View</a></td>
                            </tr>
                        @empty<tr><td colspan="8" class="ft-mgmt-empty">No matching orders found.</td></tr>@endforelse
                    </tbody>
                @elseif($priorityTab === 'inquiries')
                    <thead><tr><th>Inquiry</th><th>Client</th><th>Current task</th><th>Status</th><th>Flag</th><th>Owner</th><th>Due</th><th></th></tr></thead>
                    <tbody>
                        @forelse($priorityInquiries as $inquiry)
                            @php
                                [$flagLabel, $flagTone] = $inquiryFlag($inquiry);
                                $statusColor = $inquiryService->inquiryStatusColor($inquiry->status ?: 'To do', (string) ($inquiry->currentTask?->status ?: ''));
                            @endphp
                            <tr wire:key="mgmt-priority-inquiry-{{ $inquiry->id }}">
                                <td><a class="ft-mgmt-primary-text" href="{{ route('inquiries.index', ['open' => $inquiry->id]) }}" wire:navigate>{{ $inquiry->inquiry_number }}</a><div class="ft-mgmt-sub">{{ $inquiry->subject }}</div></td>
                                <td>{{ $inquiry->client?->name ?? '—' }}</td><td>{{ $inquiry->currentTask?->title ?? 'No current task' }}</td>
                                <td><span class="ft-mgmt-badge {{ $badgeTone($inquiry->status) }}">{{ $inquiry->status ?: 'To do' }}</span></td><td><span class="ft-mgmt-badge {{ $flagTone }}">{{ $flagLabel }}</span></td>
                                <td>@if($inquiry->owner)<span class="ft-mgmt-person"><x-ui.avatar :user="$inquiry->owner" :name="$inquiry->owner->name" :size="27" />{{ $inquiry->owner->name }}</span>@else Unassigned @endif</td>
                                <td>{{ $inquiry->currentTask?->due_date?->format('M j') ?? '—' }}</td><td><a class="ft-mgmt-tiny-action" href="{{ route('inquiries.index', ['open' => $inquiry->id]) }}" wire:navigate>View</a></td>
                            </tr>
                        @empty<tr><td colspan="8" class="ft-mgmt-empty">No matching inquiries found.</td></tr>@endforelse
                    </tbody>
                @else
                    <thead><tr><th>Task</th><th>Order</th><th>Phase</th><th>Status</th><th>Attention</th><th>Assignee</th><th>Due</th><th></th></tr></thead>
                    <tbody>
                        @forelse($priorityTasks as $task)
                            @php
                            [$flagLabel, $flagTone] = $taskFlag($task);
                        @endphp
                            <tr wire:key="mgmt-priority-task-{{ $task->id }}">
                                <td><a class="ft-mgmt-primary-text" href="{{ route('jobs.index', ['open' => $task->flow_job_id, 'task' => $task->id]) }}" wire:navigate>{{ $task->title }}</a><div class="ft-mgmt-sub">{{ $task->task_number }}</div></td>
                                <td>{{ $task->job?->displayOrderNumber() ?? '—' }}</td><td><x-ui.phase-label :phase="$task->phase" short /></td>
                                <td><span class="ft-mgmt-badge {{ $badgeTone($task->status) }}">{{ $task->status }}</span></td><td><span class="ft-mgmt-badge {{ $flagTone }}">{{ $flagLabel }}</span></td>
                                <td>@if($task->assignee)<span class="ft-mgmt-person"><x-ui.avatar :user="$task->assignee" :name="$task->assignee->name" :size="27" />{{ $task->assignee->name }}</span>@else Unassigned @endif</td>
                                <td>{{ $task->due_date?->format('M j') ?? '—' }}</td><td><a class="ft-mgmt-tiny-action" href="{{ route('jobs.index', ['open' => $task->flow_job_id, 'task' => $task->id]) }}" wire:navigate>View</a></td>
                            </tr>
                        @empty<tr><td colspan="8" class="ft-mgmt-empty">No matching tasks found.</td></tr>@endforelse
                    </tbody>
                @endif
            </table>
        </div>
        <div class="ft-mgmt-priority-pagination" aria-label="Priority work pagination">
            <span class="ft-mgmt-priority-page-status">
                @if(($priorityPagination['total'] ?? 0) > 0)
                    {{ $priorityPagination['from'] }}–{{ $priorityPagination['to'] }} of {{ $priorityPagination['total'] }}
                @else
                    0 items
                @endif
            </span>
            <button
                type="button"
                class="ft-mgmt-priority-page-btn"
                wire:click="previousPriorityPage"
                wire:loading.attr="disabled"
                wire:target="previousPriorityPage,nextPriorityPage"
                @disabled(!($priorityPagination['hasPrevious'] ?? false))
                aria-label="Previous priority work page"
                title="Previous page"
            >←</button>
            <button
                type="button"
                class="ft-mgmt-priority-page-btn"
                wire:click="nextPriorityPage"
                wire:loading.attr="disabled"
                wire:target="previousPriorityPage,nextPriorityPage"
                @disabled(!($priorityPagination['hasNext'] ?? false))
                aria-label="Next priority work page"
                title="Next page"
            >→</button>
        </div>
    </section>

    <section class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid">
        <article class="ft-mgmt-panel ft-mgmt-attention-prototype ft-mgmt-attention-compact ft-mgmt-dashboard-half-panel">
            <div class="ft-mgmt-panel-head">
                <div><h2>Needs attention</h2><p>Orders and Inquiries ranked by urgency and impact</p></div>
                <a class="ft-mgmt-link" href="{{ $attentionViewAllRoute }}" wire:navigate>View all</a>
            </div>
            <div class="ft-mgmt-attention-tabs" role="tablist" aria-label="Needs attention type">
                <button type="button" wire:click="setAttentionTab('all')" class="{{ $attentionTab === 'all' ? 'active' : '' }}">All {{ $attentionTotalCount }}</button>
                <button type="button" wire:click="setAttentionTab('orders')" class="{{ $attentionTab === 'orders' ? 'active' : '' }}">Orders {{ $attentionOrderCount }}</button>
                <button type="button" wire:click="setAttentionTab('inquiries')" class="{{ $attentionTab === 'inquiries' ? 'active' : '' }}">Inquiries {{ $attentionInquiryCount }}</button>
            </div>
            <div class="ft-mgmt-attention-list">
                @forelse($attentionItems as $attentionItem)
                    @php
                        $kind = $attentionItem['kind'];
                        $record = $attentionItem['record'];
                        $isOrder = $kind === 'orders';
                        [$flagLabel, $flagTone] = $isOrder ? $attentionJobFlag($record) : $attentionInquiryFlag($record);
                        $reference = $isOrder ? $record->displayOrderNumber() : $record->inquiry_number;
                        $headline = trim((string) ($isOrder ? ($record->tasks?->first()?->title ?: $record->title) : ($record->currentTask?->title ?: $record->subject)));
                        $ownerName = $isOrder ? ($record->owner?->name ?? 'Unassigned') : ($record->owner?->name ?? 'Unassigned');
                        $reason = trim((string) ($isOrder
                            ? ($record->attention_reason ?: $record->tasks?->first()?->attention_reason ?: $record->flaggedTasks?->first()?->attention_reason ?: 'Attention required')
                            : ($record->currentTask?->attention_reason ?: ($record->needs_attention ? 'Attention required' : $record->currentTask?->status))));
                        $rowRoute = $isOrder
                            ? route('jobs.index', ['open' => $record->id])
                            : route('inquiries.index', ['open' => $record->id]);
                    @endphp
                    <a class="ft-mgmt-attention" href="{{ $rowRoute }}" wire:navigate wire:key="mgmt-attention-{{ $kind }}-{{ $record->id }}">
                        <span class="ft-mgmt-severity {{ $flagTone }}"></span>
                        <span class="ft-mgmt-attention-type-icon {{ $isOrder ? 'order' : 'inquiry' }}" aria-hidden="true">
                            @if($isOrder)
                                <svg viewBox="0 0 24 24"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20 8H7"/><circle cx="10" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/></svg>
                            @else
                                <svg viewBox="0 0 24 24"><path d="M20 15a4 4 0 0 1-4 4H9l-5 3 1.4-4.2A7 7 0 1 1 20 15Z"/></svg>
                            @endif
                        </span>
                        <span class="ft-mgmt-attention-kind {{ $isOrder ? 'order' : 'inquiry' }}">{{ $isOrder ? 'Order' : 'Inquiry' }}</span>
                        <span class="ft-mgmt-attention-copy">
                            <strong>{{ $reference }}@if($headline !== '') · {{ $headline }}@endif</strong>
                            <small>{{ $reason !== '' ? $reason : 'Requires attention' }} · Owner {{ $ownerName }}</small>
                        </span>
                        <span class="ft-mgmt-badge {{ $flagTone }}">{{ $flagLabel }}</span>
                        <span class="ft-mgmt-attention-arrow" aria-hidden="true">›</span>
                    </a>
                @empty
                    <div class="ft-mgmt-empty">No attention items match the current filters.</div>
                @endforelse
            </div>
        </article>

    <livewire:dashboard.tagged-comments
        :range-days="$rangeDays"
        :client-filter="$clientFilter"
        :team-filter="$teamFilter"
        :search="$search"
    />
    </section>

    <section class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid">
        <article class="ft-mgmt-panel ft-mgmt-flow-prototype">
            <div class="ft-mgmt-panel-head">
                <div><h2>Work moving through FlowTrack</h2><p>Active records grouped by configured workflow phase. Client-specific phase differences are labelled.</p></div>
                <div class="ft-mgmt-tabs">
                    <button type="button" wire:click="setFlowTab('orders')" class="ft-mgmt-tab {{ $flowTab === 'orders' ? 'active' : '' }}">Orders</button>
                    <button type="button" wire:click="setFlowTab('inquiries')" class="ft-mgmt-tab {{ $flowTab === 'inquiries' ? 'active' : '' }}">Inquiries</button>
                </div>
            </div>
            <div class="ft-mgmt-panel-body">
                <div class="ft-mgmt-flow-bars">
                    @forelse($flowRows as $row)
                        <div class="ft-mgmt-flow-row">
                            <div class="ft-mgmt-flow-label-wrap" title="{{ $row['label'] }}{{ $row['scope_text'] !== '' ? ' — '.$row['scope_text'] : '' }}">
                                <span class="ft-mgmt-flow-label">{{ $row['short_label'] ?? $row['label'] }}</span>
                                @if($row['is_mismatch'] && $row['scope_text'] !== '')
                                    <span class="ft-mgmt-flow-scope">{{ $row['scope_text'] }}</span>
                                @endif
                            </div>
                            <div class="ft-mgmt-track">
                                <span class="ft-mgmt-fill ft-phase-fill {{ (int) $row['count'] === 0 ? 'is-empty' : '' }}" style="{{ \App\Support\MasterColor::style($row['color'] ?? null) }}width:{{ $row['width'] }}%"></span>
                            </div>
                            <span class="ft-mgmt-flow-value">{{ $row['count'] }}</span>
                        </div>
                    @empty
                        <div class="ft-mgmt-empty">No active workflow data.</div>
                    @endforelse
                </div>
            </div>
        </article>

        <article class="ft-mgmt-panel">
            <div class="ft-mgmt-panel-head">
                <div><h2>Task status distribution</h2><p>Current {{ $taskStatusTab === 'orders' ? 'Order' : 'Inquiry' }} task status from Master Data</p></div>
                <div class="ft-mgmt-tabs">
                    <button type="button" wire:click="setTaskStatusTab('orders')" class="ft-mgmt-tab {{ $taskStatusTab === 'orders' ? 'active' : '' }}">Orders</button>
                    <button type="button" wire:click="setTaskStatusTab('inquiries')" class="ft-mgmt-tab {{ $taskStatusTab === 'inquiries' ? 'active' : '' }}">Inquiries</button>
                </div>
            </div>
            <div class="ft-mgmt-panel-body ft-mgmt-status-layout">
                <div class="ft-mgmt-donut" style="background:{{ $donutBackground }}"><div class="ft-mgmt-donut-center"><strong>{{ $statusTotal }}</strong><span>tasks</span></div></div>
                <div class="ft-mgmt-legend">
                    @forelse($statusRows as $row)
                        <a href="{{ route('my-work', ['source' => $taskStatusTab, 'status' => $row['label']]) }}" wire:navigate title="View My Tasks filtered by {{ $row['label'] }}"><span class="dot" style="background:{{ $row['color'] }}"></span>{{ $row['label'] }}</a><b>{{ $row['count'] }}</b>
                    @empty
                        <span class="ft-mgmt-sub">No active task statuses.</span><b>0</b>
                    @endforelse
                </div>
            </div>
        </article>
    </section>

    @if(auth()->user()->canAccess('reports.view'))
    @php
        $teamReportParams = array_filter([
            // Keep "View all" aligned with the dashboard's global period. The
            // full report still supports its own reporting-period controls after
            // the user leaves the dashboard.
            'period' => 'custom',
            'from' => $teamReportingPeriod['from'] ?? null,
            'to' => $teamReportingPeriod['to'] ?? null,
            'client' => $clientFilter,
            'department' => $teamFilter,
            'q' => $search,
        ], static fn ($value) => $value !== null && $value !== '');
    @endphp
    <section class="ft-mgmt-panel ft-mgmt-team-panel ft-mgmt-panel-spaced">
        <div class="ft-mgmt-panel-head ft-mgmt-team-panel-head">
            <div>
                <h2>Team performance &amp; workload</h2>
                <p>Top 4 assignees from actual Inquiry and Order task records · {{ $teamReportingPeriod['label'] ?? 'Last 7 days' }}.</p>
            </div>
            <a class="ft-mgmt-btn ft-mgmt-team-view-all" href="{{ route('team-performance.report', $teamReportParams) }}" wire:navigate>View all</a>
        </div>
        <div class="ft-mgmt-panel-body">
            <div class="ft-mgmt-team-grid">
                @forelse($assigneePerformance as $person)
                    <x-dashboard.team-performance-card :person="$person" wire:key="mgmt-person-{{ $person->id }}" />
                @empty
                    <div class="ft-mgmt-empty">No team workload matches the current dashboard filters and period.</div>
                @endforelse
            </div>
        </div>
    </section>

    @endif

    <section class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid">
        <article class="ft-mgmt-panel ft-mgmt-client-panel">
            <div class="ft-mgmt-panel-head"><div><h2>Client portfolio</h2><p>Activity and completion in the selected dashboard period</p></div><a class="ft-mgmt-link" href="{{ route('clients.index') }}" wire:navigate>All clients</a></div>
            <div class="ft-mgmt-panel-body ft-mgmt-client-portfolio-body">
                @if($clientPortfolio->isNotEmpty())
                    <div class="ft-mgmt-client-head" aria-hidden="true">
                        <span>Client</span>
                        <span>Inquiries</span>
                        <span>Orders</span>
                        <span>Created vs completed</span>
                    </div>
                @endif
                @forelse($clientPortfolio as $portfolioClient)
                    @php
                        $inquiries = (int) ($portfolioClient->inquiries_count ?? 0);
                        $orders = (int) ($portfolioClient->orders_count ?? 0);
                        $created = (int) ($portfolioClient->total_records_count ?? ($inquiries + $orders));
                        $completed = (int) ($portfolioClient->completed_records_count ?? 0);
                        $completion = $created > 0 ? min(100, max(0, (int) round(($completed / $created) * 100))) : 0;
                        $attention = (int) ($portfolioClient->attention_items_count ?? 0);
                    @endphp
                    <div class="ft-mgmt-client-row" wire:key="mgmt-client-{{ $portfolioClient->id }}">
                        <div class="ft-mgmt-client-name">
                            <span class="ft-mgmt-client-logo"><x-ui.client-logo :client="$portfolioClient" :name="$portfolioClient->name" :size="38" /></span>
                            <div class="ft-mgmt-client-copy">
                                <strong>{{ $portfolioClient->name }}</strong>
                                <span>{{ $attention }} attention item{{ $attention === 1 ? '' : 's' }}</span>
                            </div>
                        </div>
                        <b class="ft-mgmt-client-number">{{ $inquiries }}</b>
                        <b class="ft-mgmt-client-number">{{ $orders }}</b>
                        <div class="ft-mgmt-client-completion">
                            <div class="ft-mgmt-client-progress"><span style="width:{{ $completion }}%"></span></div>
                            <div>{{ $completed }} of {{ $created }} completed · {{ $completion }}%</div>
                        </div>
                    </div>
                @empty
                    <div class="ft-mgmt-empty">No client portfolio data matches the current filters.</div>
                @endforelse
            </div>
        </article>

        <article class="ft-mgmt-panel ft-mgmt-catalogue-side ft-mgmt-catalogue-prototype ft-mgmt-dashboard-half-panel">
            <div class="ft-mgmt-catalogue-head">
                <div class="ft-mgmt-catalogue-title">
                    <h2>Catalogue readiness</h2>
                    <p>Product data, classification, availability and document coverage</p>
                </div>
                <div class="ft-mgmt-catalogue-actions">
                    <span class="ft-mgmt-catalogue-ready">{{ (int) ($catalogueReadiness['readyPercent'] ?? 0) }}% ready</span>
                    <a class="ft-mgmt-link" href="{{ route('master-data', ['group' => 'product']) }}" wire:navigate>Open catalogue</a>
                </div>
            </div>

            <div class="ft-mgmt-catalogue-body">
                <div class="ft-mgmt-catalogue-summary">
                    <span><strong>{{ number_format((int) ($catalogueReadiness['activeProducts'] ?? 0)) }}</strong> products</span>
                    <i aria-hidden="true"></i>
                    <span>{{ number_format((int) ($catalogueReadiness['mainCategories'] ?? 0)) }} main categories</span>
                    <i aria-hidden="true"></i>
                    <span>{{ number_format((int) ($catalogueReadiness['activeSuppliers'] ?? 0)) }} active {{ (int) ($catalogueReadiness['activeSuppliers'] ?? 0) === 1 ? 'supplier' : 'suppliers' }}</span>
                </div>

                <div class="ft-mgmt-catalogue-rows">
                    @foreach($catalogueReadiness['rows'] ?? [] as $row)
                        @php
                            $tone = in_array(($row['tone'] ?? ''), ['amber', 'green', 'red', 'blue'], true)
                                ? $row['tone']
                                : 'green';
                        @endphp
                        <div class="ft-mgmt-catalogue-row">
                            <div class="ft-mgmt-catalogue-label">
                                <span class="ft-mgmt-catalogue-dot {{ $tone }}" aria-hidden="true"></span>
                                <strong>{{ $row['label'] }}</strong>
                            </div>
                            <div class="ft-mgmt-catalogue-track" aria-label="{{ $row['label'] }} {{ (int) $row['value'] }} percent">
                                <span class="ft-mgmt-catalogue-fill {{ $tone }}" style="width:{{ max(0, min(100, (int) $row['value'])) }}%"></span>
                            </div>
                            <div class="ft-mgmt-catalogue-value">
                                <strong>{{ (int) $row['value'] }}%</strong>
                                <span>{{ $row['detail'] ?? '' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </article>
    </section>

    <article class="ft-mgmt-panel ft-mgmt-recent-prototype">
            <div class="ft-mgmt-panel-head">
                <div><h2>Recent activity</h2><p>Latest changes from Orders, Inquiries and Tasks</p></div>
                <div class="ft-mgmt-tabs">
                    <button type="button" wire:click="setActivityTab('all')" class="ft-mgmt-tab {{ $activityTab === 'all' ? 'active' : '' }}">All</button>
                    <button type="button" wire:click="setActivityTab('orders')" class="ft-mgmt-tab {{ $activityTab === 'orders' ? 'active' : '' }}">Orders</button>
                    <button type="button" wire:click="setActivityTab('inquiries')" class="ft-mgmt-tab {{ $activityTab === 'inquiries' ? 'active' : '' }}">Inquiries</button>
                    <button type="button" wire:click="setActivityTab('tasks')" class="ft-mgmt-tab {{ $activityTab === 'tasks' ? 'active' : '' }}">Tasks</button>
                </div>
            </div>
            <div class="ft-mgmt-activity-list">
                @forelse($recentActivity as $activity)
                    @php
                        $activityDetail = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $orderTerminology($activity->dashboard_detail), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
                        $activityKind = (string) ($activity->dashboard_kind ?? 'orders');
                        $activityIsInquiry = $activity->subject_type === \App\Models\Inquiry::class;
                        $activityRoute = ($activityKind === 'inquiries' || $activityIsInquiry)
                            ? route('inquiries.index', ['open' => (int) ($activity->dashboard_parent_id ?? 0)])
                            : route('jobs.index', array_filter([
                                'open' => (int) ($activity->dashboard_parent_id ?? 0),
                                'task' => $activityKind === 'tasks' && (int) ($activity->dashboard_task_id ?? 0) > 0 ? (int) $activity->dashboard_task_id : null,
                            ]));
                    @endphp
                    <a class="ft-mgmt-activity" href="{{ $activityRoute }}" wire:navigate wire:key="mgmt-activity-{{ $activity->id }}">
                        <x-ui.avatar :user="$activity->user" :name="$activity->user?->name ?? 'FlowTrack'" :size="38" />
                        <span class="ft-mgmt-activity-copy">
                            <strong>{{ $orderTerminology($activity->dashboard_title) }}</strong>
                            <p>{{ $activityDetail !== '' ? $activityDetail : 'Record updated' }}</p>
                        </span>
                        <time>{{ $activity->created_at?->diffForHumans(short: true) }}</time>
                    </a>
                @empty<div class="ft-mgmt-empty">No Order, Inquiry or Task changes match the selected period or filters.</div>@endforelse
            </div>
        </article>

</x-ui.management-theme>
