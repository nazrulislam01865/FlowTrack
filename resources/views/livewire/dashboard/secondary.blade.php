@php
    $today = app(\App\Services\WorkspaceSettingsService::class)->localToday();
    $statusTone = static function (?string $status): string {
        $value = strtolower((string) $status);
        if (str_contains($value, 'wait') || str_contains($value, 'risk')) return 'amber';
        if (str_contains($value, 'revision') || str_contains($value, 'artwork')) return 'purple';
        if (str_contains($value, 'not started')) return 'gray';
        if (str_contains($value, 'progress') || str_contains($value, 'production') || str_contains($value, 'request')) return 'blue';
        return '';
    };
    $masterData = app(\App\Services\MasterDataService::class);
    $taskFlagService = app(\App\Services\TaskFlagService::class);
    $administrator = app(\App\Services\AccessControlService::class)->isAdministrator(auth()->user());
    $orderTerminology = static function (?string $value): string {
        return preg_replace_callback('/\bjobs?\b/i', static function (array $match): string {
            return match ($match[0]) {
                'Jobs' => 'Orders',
                'jobs' => 'orders',
                'JOB' => 'ORDER',
                'JOBS' => 'ORDERS',
                default => ctype_upper($match[0][0] ?? '') ? 'Order' : 'order',
            };
        }, (string) $value) ?: (string) $value;
    };
    $taskFlag = static function ($task) use ($masterData, $taskFlagService): array {
        $label = $taskFlagService->labelForTask($task);
        if ($label) return [$label, 'amber', $masterData->displayColorFor('order_task_flag', $label)];
        return ['No flag', 'green', null];
    };
    $jobFlag = static function ($job) use ($masterData, $taskFlagService): array {
        if ((bool) ($job->attention_requested ?? false)) return ['Requires attention', 'red', null];
        $label = $taskFlagService->labelForOrder($job);
        if ($label) return [$label, 'amber', $masterData->displayColorFor('order_flag', $label)];
        return ['No flag', 'green', null];
    };
@endphp

<div class="ft-dashboard-secondary-sections">
    <div class="ft-grid ft-grid-balanced">
        <section class="ft-panel ft-dashboard-assignee-panel">
            <div class="ft-panel-head"><div><h2 class="ft-panel-title">Assignee performance</h2><div class="ft-panel-note">Open and completed Inquiry + Order tasks for the selected reporting cohort</div></div>{{-- Reports Details link disabled with the Reports page. --}}</div>
            <div class="ft-table-wrap">
                <table class="ft-table responsive ft-dashboard-assignee-table">
                    <colgroup><col class="ft-dashboard-col--29"><col class="ft-dashboard-col--16"><col class="ft-dashboard-col--18"><col class="ft-dashboard-col--19"><col class="ft-dashboard-col--18"></colgroup>
                    <thead><tr><th>Assignee</th><th>Open</th><th>Completed</th><th>On time</th><th>Workload</th></tr></thead>
                    <tbody>
                        @forelse($assigneePerformance as $person)
                            @php
                                $onTime = $person->on_time_rate;
                                $workloadPct = min(100, max(8, (int) $person->ongoing_count * 12));
                                $workloadLabel = $person->ongoing_count >= 8 ? 'High' : ($person->ongoing_count >= 5 ? 'Med' : 'Good');
                            @endphp
                            <tr wire:key="dashboard-assignee-{{ $person->id }}">
                                <td data-label="Assignee"><span class="ft-person"><x-ui.avatar :user="$person" :name="$person->name" :size="22" /><span class="ft-cell-clip">{{ $person->name }}</span></span></td>
                                <td data-label="Open">
                                    @if($administrator)
                                        <a class="ft-text-link" href="{{ route('all-tasks', ['assignee' => $person->id]) }}" wire:navigate>{{ $person->ongoing_count }} ↗</a>
                                    @else
                                        {{ $person->ongoing_count }}
                                    @endif
                                </td>
                                <td data-label="Completed">{{ $person->done_count }}</td>
                                <td data-label="On time">{{ $onTime === null ? '—' : $onTime.'%' }}</td>
                                <td data-label="Workload"><span class="ft-load"><i class="ft-load-track"><span style="width:{{ $workloadPct }}%"></span></i>{{ $workloadLabel }}</span></td>
                            </tr>
                        @empty
                            <tr class="ft-table-empty-row"><td colspan="5">No active assignee workload.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ft-panel ft-dashboard-attention-panel">
            <div class="ft-panel-head"><div><h2 class="ft-panel-title">Needs attention</h2><div class="ft-panel-note">Highest-priority tasks across current orders</div></div>@if($administrator)<a class="ft-link" href="{{ route('all-tasks') }}" wire:navigate>View all tasks</a>@endif</div>
            <div class="ft-risk-list">
                @forelse($attentionTasks as $task)
                    @php
                        [$flagLabel, $flagTone, $flagColor] = $taskFlag($task);
                    @endphp
                    <div class="ft-risk" wire:key="dashboard-risk-{{ $task->id }}">
                        <a class="ft-risk-name ft-text-link" href="{{ route('jobs.index', ['open' => $task->flow_job_id, 'task' => $task->id]) }}" wire:navigate>{{ $task->title }}</a>
                        <span class="ft-flag {{ $flagColor ? 'ft-master-color' : $flagTone }}" style="{{ \App\Support\MasterColor::style($flagColor) }}">{{ $flagLabel }}</span>
                        <span class="ft-risk-meta">{{ $task->task_number }} · {{ $task->job?->displayOrderNumber() ?? 'Order' }} · {{ $task->assignee?->name ?? 'Unassigned' }} · {{ $task->due_date ? 'Due '.$task->due_date->format('M j') : 'No due date' }}</span>
                    </div>
                @empty
                    <div class="ft-panel-empty">No tasks currently need attention.</div>
                @endforelse
            </div>
        </section>
    </div>

    <div class="ft-grid ft-grid-balanced">
        <section class="ft-panel ft-dashboard-jobs-panel">
            <div class="ft-panel-head"><div><h2 class="ft-panel-title">Ongoing Orders</h2><div class="ft-panel-note">Current stage and exception flags</div></div><a class="ft-link" href="{{ route('jobs.index') }}" wire:navigate>View orders</a></div>
            <div class="ft-table-wrap">
                <table class="ft-table responsive ft-dashboard-jobs-table">
                    <colgroup><col class="ft-dashboard-col--31"><col class="ft-dashboard-col--18"><col class="ft-dashboard-col--23"><col class="ft-dashboard-col--18"><col class="ft-dashboard-col--10"></colgroup>
                    <thead><tr><th>Order</th><th>Client</th><th>Status</th><th>Flag</th><th>View</th></tr></thead>
                    <tbody>
                        @forelse($ongoingJobs as $job)
                            @php
                                [$flagLabel, $flagTone, $flagColor] = $jobFlag($job);
                            @endphp
                            <tr wire:key="dashboard-job-{{ $job->id }}">
                                <td data-label="Order"><a class="ft-text-link ft-cell-clip" href="{{ route('jobs.index', ['open' => $job->id]) }}" wire:navigate>{{ $job->title }}</a><span class="ft-ref">{{ $job->displayOrderNumber() }}</span></td>
                                <td data-label="Client"><span class="ft-client-name-with-logo"><x-ui.client-logo :client="$job->client" :name="$job->client?->name ?: 'Client'" :size="22" /><span class="ft-cell-clip">{{ $job->client?->name ?? '—' }}</span></span></td>
                                <td data-label="Status"><x-ui.phase-label :phase="$job->phase" short fallback="Unassigned" class="ft-pill" /></td>
                                <td data-label="Flag"><span class="ft-flag {{ $flagColor ? 'ft-master-color' : $flagTone }}" style="{{ \App\Support\MasterColor::style($flagColor) }}">{{ $flagLabel }}</span></td>
                                <td data-label="View"><a class="ft-view" href="{{ route('jobs.index', ['open' => $job->id]) }}" wire:navigate>View</a></td>
                            </tr>
                        @empty
                            <tr class="ft-table-empty-row"><td colspan="5">No ongoing orders.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ft-panel ft-dashboard-tasks-panel">
            <div class="ft-panel-head"><div><h2 class="ft-panel-title">Ongoing tasks</h2><div class="ft-panel-note">Tasks before Done with current work status and flags</div></div>@if($administrator)<a class="ft-link" href="{{ route('all-tasks') }}" wire:navigate>Open all tasks</a>@endif</div>
            <div class="ft-table-wrap">
                <table class="ft-table responsive ft-dashboard-tasks-table">
                    <colgroup><col class="ft-dashboard-col--29"><col class="ft-dashboard-col--13"><col class="ft-dashboard-col--17"><col class="ft-dashboard-col--20"><col class="ft-dashboard-col--13"><col class="ft-dashboard-col--8"></colgroup>
                    <thead><tr><th>Task</th><th>Order</th><th>Assignee</th><th>Status</th><th>Flag</th><th>View</th></tr></thead>
                    <tbody>
                        @forelse($ongoingTasks as $task)
                            @php
                        [$flagLabel, $flagTone, $flagColor] = $taskFlag($task);
                    @endphp
                            <tr wire:key="dashboard-task-{{ $task->id }}">
                                <td data-label="Task"><a class="ft-text-link ft-cell-clip" href="{{ route('jobs.index', ['open' => $task->flow_job_id, 'task' => $task->id]) }}" wire:navigate>{{ $task->title }}</a><span class="ft-ref">{{ $task->task_number }}</span></td>
                                <td data-label="Order"><a class="ft-text-link" href="{{ route('jobs.index', ['open' => $task->flow_job_id]) }}" wire:navigate>{{ str($task->job?->displayOrderNumber() ?? '—')->afterLast('-') }}</a></td>
                                <td data-label="Assignee"><span class="ft-cell-clip">{{ $task->assignee?->name ?? 'Unassigned' }}</span></td>
                                <td data-label="Status">@php $taskStatusColor = $masterData->colorFor('order_task_status', (string) $task->status); @endphp<span class="ft-pill {{ $taskStatusColor ? 'ft-master-color' : $statusTone($task->status) }}" style="{{ \App\Support\MasterColor::style($taskStatusColor) }}">{{ $task->status }}</span></td>
                                <td data-label="Flag"><span class="ft-flag {{ $flagColor ? 'ft-master-color' : $flagTone }}" style="{{ \App\Support\MasterColor::style($flagColor) }}">{{ $flagLabel }}</span></td>
                                <td data-label="View"><a class="ft-view" href="{{ route('jobs.index', ['open' => $task->flow_job_id, 'task' => $task->id]) }}" wire:navigate>View</a></td>
                            </tr>
                        @empty
                            <tr class="ft-table-empty-row"><td colspan="6">No ongoing tasks.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="ft-grid ft-grid-balanced">
        <section class="ft-panel">
            <div class="ft-panel-head"><div><h2 class="ft-panel-title">Recent activity</h2><div class="ft-panel-note">Latest order, task, inquiry, document and comment events</div></div><a class="ft-link" href="{{ route('notifications') }}" wire:navigate>All activity</a></div>
            <div class="ft-activity-list">
                @forelse($recentActivity as $notification)
                    <div class="ft-activity" wire:key="dashboard-activity-{{ $notification->id }}">
                        <span class="ft-activity-icon">{{ in_array($notification->type, ['mention', 'mention_admin'], true) ? '@' : '✓' }}</span>
                        <span><strong>{{ $orderTerminology($notification->title) }}</strong><span class="ft-activity-copy">{{ app(\App\Services\MentionService::class)->displayText($notification->message) }}</span></span>
                        <time class="ft-activity-time">{{ $notification->created_at?->diffForHumans(short: true) }}</time>
                    </div>
                @empty
                    <div class="ft-panel-empty">No recent activity.</div>
                @endforelse
            </div>
        </section>

        <section class="ft-panel ft-dashboard-clients-panel">
            <div class="ft-panel-head"><div><h2 class="ft-panel-title">Client portfolio</h2><div class="ft-panel-note">Active work, inquiry volume and delivery performance</div></div><a class="ft-link" href="{{ route('clients.index') }}" wire:navigate>All clients</a></div>
            <div class="ft-table-wrap">
                <table class="ft-table responsive ft-dashboard-clients-table">
                    <colgroup><col class="ft-dashboard-col--28"><col class="ft-dashboard-col--15"><col class="ft-dashboard-col--18"><col class="ft-dashboard-col--19"><col class="ft-dashboard-col--20"></colgroup>
                    <thead><tr><th>Client</th><th>Orders</th><th>Inquiries</th><th>Attention</th><th>On time</th></tr></thead>
                    <tbody>
                        @forelse($clientPortfolio as $portfolioClient)
                            @php
                                $portfolioOpenTasks = (int) ($portfolioClient->open_tasks_count ?? 0);
                                $portfolioOverdueTasks = (int) ($portfolioClient->overdue_tasks_count ?? 0);
                                $portfolioAttentionJobs = (int) ($portfolioClient->attention_jobs_count ?? 0);
                                $portfolioOnTime = $portfolioOpenTasks > 0
                                    ? max(0, (int) round((($portfolioOpenTasks - $portfolioOverdueTasks) / $portfolioOpenTasks) * 100))
                                    : 100;
                                $portfolioAttentionTone = $portfolioAttentionJobs > 1 ? 'red' : ($portfolioAttentionJobs === 1 ? 'amber' : 'green');
                            @endphp
                            <tr wire:key="dashboard-client-portfolio-{{ $portfolioClient->id }}">
                                <td data-label="Client"><a class="ft-text-link ft-dashboard-client-logo-link" href="{{ route('clients.index') }}" wire:navigate><x-ui.client-logo :client="$portfolioClient" :name="$portfolioClient->name" :size="24" /><span>{{ $portfolioClient->name }}</span></a></td>
                                <td data-label="Orders"><a class="ft-text-link" href="{{ route('jobs.index', ['client' => $portfolioClient->id]) }}" wire:navigate>{{ (int) ($portfolioClient->active_jobs_count ?? 0) }} ↗</a></td>
                                <td data-label="Inquiries"><a class="ft-text-link" href="{{ route('inquiries.index') }}" wire:navigate>{{ (int) ($portfolioClient->open_inquiries_count ?? 0) }} ↗</a></td>
                                <td data-label="Attention"><span class="ft-flag {{ $portfolioAttentionTone }}">{{ $portfolioAttentionJobs }}</span></td>
                                <td data-label="On time">{{ $portfolioOnTime }}%</td>
                            </tr>
                        @empty
                            <tr class="ft-table-empty-row"><td colspan="5">No active clients.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
