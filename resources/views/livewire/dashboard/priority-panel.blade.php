@php
    $today = app(\App\Services\WorkspaceSettingsService::class)->localToday();
    $masterData = app(\App\Services\MasterDataService::class);
    $taskFlagService = app(\App\Services\TaskFlagService::class);
    $inquiryService = app(\App\Services\InquiryService::class);

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

@endphp
    <article class="ft-mgmt-panel ft-mgmt-panel-spaced" data-dashboard-panel="priority">
    <div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-priority-content">
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
        </div>
</article>
