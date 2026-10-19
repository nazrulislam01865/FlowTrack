<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InquiryIntelligenceService
{
    public function data(User $user, array $filters = []): array
    {
        $this->authorize($user);

        [$fromLocal, $toLocal, $periodLabel] = $this->periodBounds((string) ($filters['period'] ?? 'month'));
        $timezone = app(WorkspaceSettingsService::class)->displayTimezone();
        $fromUtc = $fromLocal->utc();
        $toUtc = $toLocal->utc();

        $query = app(InquiryService::class)->visibleQuery($user)
            ->whereBetween('inquiries.created_at', [$fromUtc, $toUtc]);

        $search = trim((string) ($filters['search'] ?? ''));
        $priority = trim((string) ($filters['priority'] ?? ''));
        $assigneeId = (int) ($filters['assignee_id'] ?? 0);

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $match) use ($like): void {
                $match->whereLike('inquiry_number', $like)
                    ->orWhereLike('reference_number', $like)
                    ->orWhereLike('subject', $like)
                    ->orWhereLike('requirement_notes', $like)
                    ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $like))
                    ->orWhereHas('items', fn (Builder $item) => $item->whereLike('item_name', $like)->orWhereLike('category', $like))
                    ->orWhereHas('tasks.assignee', fn (Builder $assignee) => $assignee->whereLike('name', $like));
            });
        }

        if ($priority !== '') $query->where('inquiries.priority', $priority);
        if ($assigneeId > 0) $query->whereHas('tasks', fn (Builder $task) => $task->where('assignee_id', $assigneeId));

        $inquiries = $query
            ->reorder()
            ->orderByDesc('inquiries.created_at')
            ->with([
                'client:id,name',
                'owner:id,name,profile_image_path',
                'creator:id,name,profile_image_path',
                'items:id,inquiry_id,category,item_name,quantity,unit,notes,sort_order',
                'tasks' => fn ($task) => $task->select([
                    'id','inquiry_id','assignee_id','title','sequence','due_date','status','requires_submission','started_at','completed_at','created_at','updated_at','needs_attention','attention_reason',
                ])->with([
                    'assignee:id,name,profile_image_path',
                    'documents:id,inquiry_id,inquiry_task_id',
                    'links:id,inquiry_task_id,url',
                    'inquiry:id,inquiry_number,required_delivery_date',
                ]),
                'convertedJob:id,source_inquiry_id,job_number,order_number,status,created_at,completed_at',
                'sourceOrder:id,source_inquiry_id,job_number,order_number,status,created_at,completed_at',
            ])
            ->get([
                'id','inquiry_number','client_id','owner_id','created_by','reference_number','subject','requirement_notes','priority','status','result','needs_attention','attention_reason','started_at','completed_at','converted_job_id','created_at','updated_at',
            ]);

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $inquiries = $inquiries->filter(fn (Inquiry $inquiry) => strcasecmp($this->inquiryDisplayStatus($inquiry), $status) === 0)->values();
        }

        $inquiryIds = $inquiries->pluck('id')->map(fn ($id) => (int) $id)->all();
        $lifecycleStats = $this->taskLifecycleStats($inquiryIds);
        $reopenEventCounts = $lifecycleStats['reopen_counts'];
        $reopenLatestTimestamps = $lifecycleStats['reopen_latest_timestamps'];
        $taskTimingStats = $lifecycleStats['timings'];

        $portfolio = $this->portfolio($inquiries, $fromLocal, $toLocal, $timezone);
        $people = $this->people($inquiries, $reopenEventCounts, $reopenLatestTimestamps, $taskTimingStats, $timezone, $assigneeId);
        $products = $this->products($inquiries);

        return [
            'period' => [
                'label' => $periodLabel,
                'from' => $fromLocal,
                'to' => $toLocal,
            ],
            'filters' => [
                'statuses' => $this->statusOptions($user, $fromUtc, $toUtc),
                'priorities' => $this->priorityOptions($user, $fromUtc, $toUtc),
                'assignees' => $this->assigneeOptions(),
            ],
            'portfolio' => $portfolio,
            'people' => $people,
            'products' => $products,
        ];
    }

    public function exportRows(User $user, array $filters = []): array
    {
        abort_unless(app(AccessControlService::class)->can($user, 'reports', 'export'), 403);
        $report = $this->data($user, $filters);

        return $report['portfolio']['rows'];
    }

    private function portfolio(Collection $inquiries, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $total = $inquiries->count();
        $completed = $inquiries->filter(fn (Inquiry $inquiry) => $this->isCompleted($inquiry))->count();
        $open = max(0, $total - $completed);
        $tasks = $inquiries->flatMap(fn (Inquiry $inquiry) => $inquiry->tasks)->values();
        $completedTasks = $tasks->whereNotNull('completed_at')->values();
        $taskTotal = $tasks->count();
        $taskDone = $completedTasks->count();

        $submissionTasks = $completedTasks->where('requires_submission', true)->values();
        $submissionWithEvidence = $submissionTasks
            ->filter(fn (InquiryTask $task) => $task->documents->isNotEmpty() || $task->links->isNotEmpty())
            ->count();
        $fileCompliance = $submissionTasks->count() > 0
            ? round($submissionWithEvidence / $submissionTasks->count() * 100, 1)
            : null;

        $structuredCount = $inquiries->filter(fn (Inquiry $inquiry) => $inquiry->items->isNotEmpty())->count();
        $structuredPct = $total > 0 ? round($structuredCount / $total * 100, 1) : 0;
        $attentionCount = $inquiries->filter(fn (Inquiry $inquiry) => $this->needsAttention($inquiry))->count();
        $unstructuredCount = max(0, $total - $structuredCount);
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $overdueTasks = $tasks->filter(fn (InquiryTask $task) => !$task->completed_at
            && $task->due_date
            && $task->due_date->toDateString() < $today)->count();
        $unassignedOpenTasks = $tasks->filter(fn (InquiryTask $task) => !$task->completed_at && !$task->assignee_id)->count();

        $prioritySequence = app(MasterDataService::class)->active('priority')
            ->values()
            ->mapWithKeys(fn ($record, int $index) => [mb_strtolower(trim((string) $record->name)) => $index]);
        $priorityMix = $inquiries
            ->groupBy(fn (Inquiry $inquiry) => trim((string) $inquiry->priority) ?: 'Unspecified')
            ->map->count()
            ->map(fn (int $count, string $name) => [
                'name' => $name,
                'count' => $count,
                'sort' => $prioritySequence->get(mb_strtolower($name), 9999),
                'color' => app(MasterDataService::class)->displayColorFor('priority', $name),
            ])
            ->sort(function (array $a, array $b): int {
                if ($a['sort'] !== $b['sort']) return $a['sort'] <=> $b['sort'];
                if ($a['count'] !== $b['count']) return $b['count'] <=> $a['count'];
                return strcasecmp($a['name'], $b['name']);
            })
            ->values();
        $priorityMax = max(1, (int) ($priorityMix->max('count') ?? 1));
        $priorityMix = $priorityMix->map(function (array $row) use ($priorityMax, $total): array {
            unset($row['sort']);
            $row['width'] = (int) round($row['count'] / $priorityMax * 100);
            $row['share'] = $total > 0 ? round($row['count'] / $total * 100, 1) : 0;
            return $row;
        })->all();

        $rows = $inquiries->map(function (Inquiry $inquiry) use ($timezone): array {
            $tasks = $inquiry->tasks;
            $done = $tasks->whereNotNull('completed_at')->count();
            $taskCount = $tasks->count();
            $progress = $taskCount > 0 ? (int) round($done / $taskCount * 100) : 0;
            $currentTask = $this->currentOpenTask($tasks);
            $lead = $currentTask?->assignee
                ?: $inquiry->owner
                ?: $tasks->sortByDesc('sequence')->first()?->assignee
                ?: $inquiry->creator;
            $product = $inquiry->items->first();
            $attention = $this->attentionLabel($inquiry);
            $created = $inquiry->created_at?->copy()->setTimezone($timezone);
            $displayStatus = $this->inquiryDisplayStatus($inquiry);

            return [
                'id' => (int) $inquiry->id,
                'reference' => (string) $inquiry->inquiry_number,
                'subject' => (string) $inquiry->subject,
                'product' => $product?->item_name ?: ($product?->category ?: 'No structured product'),
                'created' => $created?->format('M j, Y') ?: '—',
                'priority' => trim((string) $inquiry->priority) ?: 'Unspecified',
                'priority_color' => app(MasterDataService::class)->displayColorFor('priority', trim((string) $inquiry->priority) ?: 'Unspecified'),
                'assignee' => $lead?->name ?: 'Unassigned',
                'progress' => $progress,
                'progress_text' => $taskCount > 0 ? $done.' of '.$taskCount.' tasks' : 'No workflow tasks',
                'status' => $displayStatus,
                'status_color' => app(InquiryService::class)->inquiryStatusColor($displayStatus, $currentTask?->status),
                'attention' => $attention['label'],
                'attention_tone' => $attention['tone'],
                'url' => route('inquiries.index', ['open' => $inquiry->id]),
            ];
        })->values()->all();

        $attentionSignals = [
            [
                'key' => 'attention',
                'count' => $attentionCount,
                'label' => $attentionCount === 1 ? 'inquiry requires attention' : 'inquiries require attention',
                'description' => 'Includes inquiry-level attention and open task statuses configured to require attention in Master Data.',
                'tone' => 'amber',
            ],
            [
                'key' => 'unstructured',
                'count' => $unstructuredCount,
                'label' => $unstructuredCount === 1 ? 'inquiry lacks structured product lines' : 'inquiries lack structured product lines',
                'description' => 'Product analysis is limited where requests remain only in descriptions or attachments.',
                'tone' => 'red',
            ],
            [
                'key' => 'overdue',
                'count' => $overdueTasks,
                'label' => $overdueTasks === 1 ? 'open inquiry task is overdue' : 'open inquiry tasks are overdue',
                'description' => 'Calculated from open task due dates in the workspace timezone.',
                'tone' => 'neutral',
            ],
            [
                'key' => 'unassigned',
                'count' => $unassignedOpenTasks,
                'label' => $unassignedOpenTasks === 1 ? 'open inquiry task is unassigned' : 'open inquiry tasks are unassigned',
                'description' => 'Unassigned work follows the same attention logic used by the Inquiry list.',
                'tone' => 'neutral',
            ],
        ];

        $previewLimit = 8;
        $previewRows = array_slice($rows, 0, $previewLimit);

        return [
            'kpis' => [
                'total' => $total,
                'open' => $open,
                'completed' => $completed,
                'task_completion' => $taskTotal > 0 ? round($taskDone / $taskTotal * 100, 1) : 0,
                'task_done' => $taskDone,
                'task_total' => $taskTotal,
                'file_compliance' => $fileCompliance,
                'evidenced' => $submissionWithEvidence,
                'evidence_total' => $submissionTasks->count(),
                'structured_products' => $structuredPct,
                'structured_count' => $structuredCount,
            ],
            'trend' => $this->trend($inquiries, $from, $to, $timezone),
            'priority_mix' => $priorityMix,
            'attention' => [
                'attention_count' => $attentionCount,
                'unstructured_count' => $unstructuredCount,
                'overdue_tasks' => $overdueTasks,
                'unassigned_open_tasks' => $unassignedOpenTasks,
                'signals' => $attentionSignals,
            ],
            'rows' => $rows,
            'preview_rows' => $previewRows,
            'preview_count' => count($previewRows),
            'preview_limit' => $previewLimit,
            'row_count' => count($rows),
            'view_all_url' => route('inquiries.index'),
        ];
    }

    private function people(Collection $inquiries, array $reopenEventCounts, array $reopenLatestTimestamps, array $taskTimingStats, string $timezone, int $assigneeId = 0): array
    {
        $performanceUserIds = $this->performanceUserIds();
        $tasks = $inquiries->flatMap(fn (Inquiry $inquiry) => $inquiry->tasks)
            ->filter(fn (InquiryTask $task) => $task->assignee_id
                && $task->assignee
                && in_array((int) $task->assignee_id, $performanceUserIds, true))
            ->when($assigneeId > 0, fn (Collection $rows) => $rows->where('assignee_id', $assigneeId)->values())
            ->values();
        $groups = $tasks->groupBy('assignee_id');
        $completedTasks = $tasks->whereNotNull('completed_at')->values();
        $durations = $completedTasks
            ->map(fn (InquiryTask $task) => $this->taskHours($task, $taskTimingStats))
            ->filter(fn ($hours) => $hours !== null && $hours >= 0)
            ->values();
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

        $ranking = $groups->map(function (Collection $personTasks) use ($reopenEventCounts, $taskTimingStats, $today, $timezone): array {
            /** @var InquiryTask $first */
            $first = $personTasks->first();
            $completed = $personTasks->whereNotNull('completed_at')->values();
            $assigned = $personTasks->count();
            $completedCount = $completed->count();
            $completionPct = $assigned > 0 ? $completedCount / $assigned * 100 : 0;
            $hours = $completed
                ->map(fn (InquiryTask $task) => $this->taskHours($task, $taskTimingStats))
                ->filter(fn ($value) => $value !== null && $value >= 0)
                ->values();
            $avgHours = $hours->count() ? (float) $hours->avg() : null;
            $dueEligible = $completed->filter(fn (InquiryTask $task) => $task->due_date !== null)->values();
            $onTimeCount = $dueEligible->filter(function (InquiryTask $task) use ($timezone): bool {
                if (!$task->completed_at || !$task->due_date) return false;
                return $task->completed_at->copy()->setTimezone($timezone)->toDateString() <= $task->due_date->toDateString();
            })->count();
            $onTime = $dueEligible->count() > 0 ? $onTimeCount / $dueEligible->count() * 100 : null;
            $reopenCount = (int) $personTasks->sum(
                fn (InquiryTask $task): int => (int) ($reopenEventCounts[(int) $task->id] ?? 0)
            );
            $open = $personTasks->whereNull('completed_at')->values();
            $overdue = $open->filter(fn (InquiryTask $task) => $task->due_date && $task->due_date->toDateString() < $today)->count();

            return [
                'id' => (int) $first->assignee_id,
                'name' => (string) $first->assignee->name,
                'initials' => $this->initials((string) $first->assignee->name),
                'assigned' => $assigned,
                'open' => $open->count(),
                'overdue' => $overdue,
                'completed' => $completedCount,
                'completion' => round($completionPct, 1),
                'avg_hours' => $avgHours !== null ? round($avgHours, 1) : null,
                'avg_hours_samples' => $hours->count(),
                'on_time' => $onTime !== null ? round($onTime, 1) : null,
                'due_samples' => $dueEligible->count(),
                'reopen_count' => $reopenCount,
            ];
        })->sort(function (array $a, array $b): int {
            if ($a['completion'] !== $b['completion']) return $b['completion'] <=> $a['completion'];

            $aOnTime = $a['on_time'];
            $bOnTime = $b['on_time'];
            if ($aOnTime !== $bOnTime) {
                if ($aOnTime === null) return 1;
                if ($bOnTime === null) return -1;
                return $bOnTime <=> $aOnTime;
            }

            if ($a['completed'] !== $b['completed']) return $b['completed'] <=> $a['completed'];

            $aHours = $a['avg_hours'];
            $bHours = $b['avg_hours'];
            if ($aHours !== $bHours) {
                if ($aHours === null) return 1;
                if ($bHours === null) return -1;
                return $aHours <=> $bHours;
            }

            if ($a['reopen_count'] !== $b['reopen_count']) return $a['reopen_count'] <=> $b['reopen_count'];
            return strcasecmp($a['name'], $b['name']);
        })->values();

        $ranking = $ranking->map(function (array $row, int $index): array {
            $row['rank'] = $index + 1;
            return $row;
        });

        $teamDue = $completedTasks->filter(fn (InquiryTask $task) => $task->due_date !== null)->values();
        $teamOnTime = $teamDue->count() > 0
            ? $teamDue->filter(function (InquiryTask $task) use ($timezone): bool {
                return $task->completed_at
                    && $task->due_date
                    && $task->completed_at->copy()->setTimezone($timezone)->toDateString() <= $task->due_date->toDateString();
            })->count() / $teamDue->count() * 100
            : null;
        $teamReopenEvents = (int) $tasks->sum(
            fn (InquiryTask $task): int => (int) ($reopenEventCounts[(int) $task->id] ?? 0)
        );

        $taskDetails = $tasks
            ->sortByDesc(fn (InquiryTask $task) => $task->completed_at?->timestamp ?? $task->updated_at?->timestamp ?? 0)
            ->map(function (InquiryTask $task) use ($reopenEventCounts, $reopenLatestTimestamps, $taskTimingStats, $timezone, $today): array {
                $timing = $this->taskTiming($task, $taskTimingStats);
                $started = $timing['started_at']?->copy()->setTimezone($timezone);
                $completed = $timing['completed_at']?->copy()->setTimezone($timezone);
                $hours = $this->taskHours($task, $taskTimingStats);
                $reopenCount = (int) ($reopenEventCounts[(int) $task->id] ?? 0);
                // Task detail SLA follows the Inquiry's client-facing required delivery date when available.
                // If the Inquiry has no delivery target, fall back to the task's own due date.
                $inquiryDue = $task->inquiry?->required_delivery_date;
                $slaTarget = $inquiryDue ?: $task->due_date;
                $slaSource = $inquiryDue ? 'Inquiry due' : ($task->due_date ? 'Task due' : 'No SLA target');
                $sla = 'No SLA target';
                $slaTone = 'blue';

                if ($slaTarget) {
                    $slaDate = $slaTarget->toDateString();
                    if (!$task->completed_at) {
                        if ($slaDate < $today) {
                            $sla = 'Overdue';
                            $slaTone = 'red';
                        } elseif ($slaDate === $today) {
                            $sla = 'Due today';
                            $slaTone = 'amber';
                        } else {
                            $sla = 'On track';
                            $slaTone = 'green';
                        }
                    } elseif ($completed && $completed->toDateString() <= $slaDate) {
                        $sla = 'On time';
                        $slaTone = 'green';
                    } else {
                        $sla = 'Late';
                        $slaTone = 'red';
                    }
                }

                return [
                    'assignee_id' => (int) $task->assignee_id,
                    'assignee' => $task->assignee?->name ?: 'Unassigned',
                    'inquiry_id' => (int) $task->inquiry_id,
                    'inquiry' => $task->inquiry?->inquiry_number ?: '',
                    'inquiry_url' => $task->inquiry_id ? route('inquiries.index', ['open' => $task->inquiry_id, 'task' => $task->id]) : null,
                    'task_id' => (int) $task->id,
                    'task' => (string) $task->title,
                    'status' => trim((string) $task->status) ?: '—',
                    'started' => $started?->format('M j, Y · g:i A') ?: '—',
                    'completed' => $completed?->format('M j, Y · g:i A') ?: ($reopenCount > 0 ? 'Reopened · currently open' : '—'),
                    'task_due' => $task->due_date?->format('M j, Y') ?: '—',
                    'inquiry_due' => $inquiryDue?->format('M j, Y') ?: '—',
                    'sla_target' => $slaTarget?->format('M j, Y') ?: '—',
                    'sla_source' => $slaSource,
                    'hours' => $hours !== null ? $this->formatHours($hours) : ($task->completed_at ? '—' : 'Open'),
                    'hours_value' => $hours,
                    'sla' => $sla,
                    'sla_tone' => $slaTone,
                    'reopen_count' => $reopenCount,
                    'reopened' => $reopenCount > 0,
                    'is_open' => !$task->completed_at,
                    'is_completed' => (bool) $task->completed_at,
                    'completed_timestamp' => $timing['completed_at']?->timestamp ?? $task->completed_at?->timestamp ?? 0,
                    'updated_timestamp' => $task->updated_at?->timestamp ?? 0,
                    'reopened_timestamp' => (int) ($reopenLatestTimestamps[(int) $task->id] ?? 0),
                ];
            })->values()->all();

        $conversion = $this->recentConversions($inquiries, $timezone, $assigneeId, $performanceUserIds);
        $activeRanking = $ranking->where('assigned', '>', 0)->values();
        $best = $activeRanking->first();
        $throughput = $activeRanking
            ->sort(function (array $a, array $b): int {
                if ($a['completed'] !== $b['completed']) return $b['completed'] <=> $a['completed'];
                if ($a['completion'] !== $b['completion']) return $b['completion'] <=> $a['completion'];
                return strcasecmp($a['name'], $b['name']);
            })->first();
        $coaching = $activeRanking
            ->sort(function (array $a, array $b): int {
                if ($a['completion'] !== $b['completion']) return $a['completion'] <=> $b['completion'];
                if ($a['reopen_count'] !== $b['reopen_count']) return $b['reopen_count'] <=> $a['reopen_count'];

                $aOnTime = $a['on_time'];
                $bOnTime = $b['on_time'];
                if ($aOnTime !== $bOnTime) {
                    if ($aOnTime === null) return 1;
                    if ($bOnTime === null) return -1;
                    return $aOnTime <=> $bOnTime;
                }

                if ($a['completed'] !== $b['completed']) return $a['completed'] <=> $b['completed'];
                return strcasecmp($a['name'], $b['name']);
            })->first();
        $roster = count($performanceUserIds);

        return [
            'kpis' => [
                'roster' => $roster,
                'active' => $groups->count(),
                'assigned' => $tasks->count(),
                'completed' => $completedTasks->count(),
                'completion_rate' => $tasks->count() > 0 ? round($completedTasks->count() / $tasks->count() * 100, 1) : 0,
                'avg_hours' => $durations->count() ? round((float) $durations->avg(), 1) : null,
                'avg_hours_samples' => $durations->count(),
                'on_time' => $teamOnTime !== null ? round($teamOnTime, 1) : null,
                'on_time_samples' => $teamDue->count(),
                'reopen_events' => $teamReopenEvents,
            ],
            'ranking' => $ranking->all(),
            'highlights' => [
                'best' => $best,
                'throughput' => $throughput,
                'coaching' => $coaching,
            ],
            'conversion' => $conversion,
            'task_details' => $taskDetails,
        ];
    }

    private function products(Collection $inquiries): array
    {
        $withProducts = $inquiries->filter(fn (Inquiry $inquiry) => $inquiry->items->isNotEmpty())->values();
        $allItems = $withProducts->flatMap(fn (Inquiry $inquiry) => $inquiry->items->map(function ($item) use ($inquiry) {
            $item->setRelation('inquiry', $inquiry);
            return $item;
        }))->values();
        $categories = $allItems->groupBy(fn ($item) => trim((string) $item->category) ?: 'Uncategorized');
        $totalProductInquiries = $withProducts->count();

        $categoryRows = $categories->map(function (Collection $items, string $category) use ($totalProductInquiries): array {
            $categoryInquiries = $items->pluck('inquiry')->unique('id')->values();
            $completed = $categoryInquiries->filter(fn (Inquiry $inquiry) => $this->isCompleted($inquiry))->values();
            $converted = $categoryInquiries->filter(fn (Inquiry $inquiry) => $this->isConverted($inquiry))->values();
            $hours = $completed
                ->map(fn (Inquiry $inquiry) => $this->inquiryCycleHours($inquiry))
                ->filter(fn ($value) => $value !== null)
                ->values();
            $quantityValues = $items
                ->map(fn ($item) => is_numeric($item->quantity) ? (float) $item->quantity : null)
                ->filter(fn ($value) => $value !== null && $value >= 0)
                ->values();
            $avgQty = $quantityValues->count() ? (float) $quantityValues->avg() : null;
            $conversion = $completed->count() > 0 ? $converted->count() / $completed->count() * 100 : null;
            $avgHours = $hours->count() ? (float) $hours->avg() : null;

            $signal = match (true) {
                $avgHours !== null && $avgHours > 24 => ['label' => 'Slow workflow', 'tone' => 'amber'],
                $conversion !== null && $completed->count() >= 3 && $conversion >= 40 => ['label' => 'Strong conversion', 'tone' => 'green'],
                $categoryInquiries->count() >= max(3, (int) ceil(max(1, $totalProductInquiries) * 0.2)) => ['label' => 'High demand', 'tone' => 'green'],
                $completed->count() === 0 => ['label' => 'Awaiting outcomes', 'tone' => 'blue'],
                default => ['label' => 'Healthy', 'tone' => 'blue'],
            };

            return [
                'category' => $category,
                'sample_product' => (string) ($items->pluck('item_name')->filter()->first() ?: ''),
                'inquiries' => $categoryInquiries->count(),
                'share' => $totalProductInquiries > 0 ? round($categoryInquiries->count() / $totalProductInquiries * 100, 1) : 0,
                'avg_hours' => $avgHours !== null ? round($avgHours, 1) : null,
                'completed' => $completed->count(),
                'converted' => $converted->count(),
                'conversion' => $conversion !== null ? round($conversion, 1) : null,
                'avg_quantity' => $avgQty !== null ? round($avgQty) : null,
                'signal' => $signal,
            ];
        })->sort(function (array $a, array $b): int {
            if ($a['inquiries'] !== $b['inquiries']) return $b['inquiries'] <=> $a['inquiries'];
            return strcasecmp($a['category'], $b['category']);
        })->values();

        $completedInquiries = $withProducts->filter(fn (Inquiry $inquiry) => $this->isCompleted($inquiry))->values();
        $convertedInquiries = $withProducts->filter(fn (Inquiry $inquiry) => $this->isConverted($inquiry))->values();
        $cycleHours = $completedInquiries
            ->map(fn (Inquiry $inquiry) => $this->inquiryCycleHours($inquiry))
            ->filter(fn ($value) => $value !== null)
            ->values();
        $dataCoverage = $inquiries->count() > 0 ? round($withProducts->count() / $inquiries->count() * 100, 1) : 0;
        $topCategory = $categoryRows->first();
        $slowest = $categoryRows->filter(fn (array $row) => $row['avg_hours'] !== null)->sortByDesc('avg_hours')->first();
        $bestConversion = $categoryRows->filter(fn (array $row) => $row['conversion'] !== null)->sortByDesc('conversion')->first();
        $queryThemes = $this->queryThemes($inquiries);
        $maxDemand = max(1, (int) ($categoryRows->max('inquiries') ?? 1));

        return [
            'kpis' => [
                'product_inquiries' => $totalProductInquiries,
                'completed' => $completedInquiries->count(),
                'converted' => $convertedInquiries->count(),
                'conversion' => $completedInquiries->count() > 0 ? round($convertedInquiries->count() / $completedInquiries->count() * 100, 1) : null,
                'avg_quote_hours' => $cycleHours->count() ? round((float) $cycleHours->avg(), 1) : null,
                'data_coverage' => $dataCoverage,
                'top_category' => $topCategory['category'] ?? '—',
                'top_category_share' => $topCategory['share'] ?? 0,
            ],
            'categories' => $categoryRows->take(7)->all(),
            'demand_bars' => $categoryRows->take(5)->map(fn (array $row) => $row + ['width' => (int) round($row['inquiries'] / $maxDemand * 100)])->all(),
            'query_themes' => $queryThemes,
            'insights' => [
                'top' => $topCategory,
                'slowest' => $slowest,
                'best_conversion' => $bestConversion,
                'coverage_gap' => max(0, 100 - $dataCoverage),
            ],
        ];
    }

    /**
     * Return the five most recent real Inquiry -> Order conversion events in
     * the current filtered period. Each row also carries the assignee's
     * completed-inquiry sample and conversion rate for useful context.
     */
    private function recentConversions(Collection $inquiries, string $timezone, int $assigneeId = 0, array $performanceUserIds = []): array
    {
        $leadFor = function (Inquiry $inquiry): ?User {
            return $inquiry->owner
                ?: $inquiry->tasks->sortByDesc('sequence')->first()?->assignee
                ?: $inquiry->creator;
        };

        $stats = [];
        foreach ($inquiries as $inquiry) {
            if (!$this->isCompleted($inquiry)) continue;
            $lead = $leadFor($inquiry);
            if (!$lead) continue;

            $id = (int) $lead->id;
            if (!in_array($id, $performanceUserIds, true)) continue;
            if ($assigneeId > 0 && $id !== $assigneeId) continue;
            if (!isset($stats[$id])) {
                $stats[$id] = ['completed_inquiries' => 0, 'orders_converted' => 0];
            }

            $stats[$id]['completed_inquiries']++;
            if ($this->isConverted($inquiry)) $stats[$id]['orders_converted']++;
        }

        return $inquiries
            ->filter(fn (Inquiry $inquiry) => $this->isConverted($inquiry))
            ->map(function (Inquiry $inquiry) use ($timezone, $assigneeId, $leadFor, $stats, $performanceUserIds): ?array {
                $order = $inquiry->convertedJob ?: $inquiry->sourceOrder;
                if (!$order) return null;

                $lead = $leadFor($inquiry);
                if (!$lead) return null;
                $id = (int) $lead->id;
                if (!in_array($id, $performanceUserIds, true)) return null;
                if ($assigneeId > 0 && $id !== $assigneeId) return null;

                $completedInquiries = (int) ($stats[$id]['completed_inquiries'] ?? 0);
                $ordersConverted = (int) ($stats[$id]['orders_converted'] ?? 0);
                $conversionRate = $completedInquiries > 0 ? round($ordersConverted / $completedInquiries * 100, 1) : 0;
                $convertedAt = strcasecmp((string) $inquiry->result, 'converted') === 0 && $inquiry->completed_at
                    ? $inquiry->completed_at
                    : ($order->created_at ?: $inquiry->updated_at);

                return [
                    'assignee_id' => $id,
                    'assignee' => (string) $lead->name,
                    'inquiry_id' => (int) $inquiry->id,
                    'inquiry' => (string) $inquiry->inquiry_number,
                    'inquiry_url' => route('inquiries.index', ['open' => $inquiry->id]),
                    'order_id' => (int) $order->id,
                    'order' => method_exists($order, 'displayOrderNumber') ? $order->displayOrderNumber() : ((string) ($order->order_number ?: $order->job_number)),
                    'order_url' => route('jobs.index', ['open' => $order->id]),
                    'completed_inquiries' => $completedInquiries,
                    'orders_converted' => $ordersConverted,
                    'conversion_rate' => $conversionRate,
                    'converted_at' => $convertedAt?->copy()->setTimezone($timezone)->format('M j, Y · g:i A') ?: '—',
                    'converted_timestamp' => $convertedAt?->timestamp ?? 0,
                ];
            })
            ->filter()
            ->sortByDesc('converted_timestamp')
            ->take(5)
            ->values()
            ->all();
    }

    private function queryThemes(Collection $inquiries): array
    {
        $definitions = [
            'Personalization / logo' => ['logo','personalization','personalise','personalize','embroidery','printing','print'],
            'Shipping cost' => ['shipping','freight','delivery cost','courier'],
            'Quantity-tier pricing' => ['quantity','qty','pricing','price','tier','moq'],
            'Production lead time' => ['lead time','production time','deadline','delivery date'],
            'Color / material options' => ['color','colour','material','fabric'],
            'Sample request' => ['sample','prototype'],
            'Artwork format' => ['artwork','ai file','eps','vector','design file'],
            'Packaging' => ['packaging','packing','box','bagging'],
        ];
        $texts = $inquiries->map(fn (Inquiry $inquiry) => mb_strtolower(strip_tags(implode(' ', [
            (string) $inquiry->subject,
            (string) $inquiry->requirement_notes,
            $inquiry->items->pluck('notes')->filter()->implode(' '),
        ]))));

        return collect($definitions)->map(function (array $needles, string $label) use ($texts): array {
            $count = $texts->filter(function (string $text) use ($needles): bool {
                foreach ($needles as $needle) if (Str::contains($text, mb_strtolower($needle))) return true;
                return false;
            })->count();
            return ['label' => $label, 'count' => $count];
        })->filter(fn (array $row) => $row['count'] > 0)->sortByDesc('count')->values()->all();
    }

    private function trend(Collection $inquiries, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $span = max(1, $from->diffInSeconds($to));
        $buckets = 7;
        $labels = [];
        $created = array_fill(0, $buckets, 0);
        $completed = array_fill(0, $buckets, 0);

        for ($i = 0; $i < $buckets; $i++) {
            $at = $from->addSeconds((int) floor($span * $i / max(1, $buckets - 1)));
            $labels[] = $at->format('M j');
        }

        $bucketIndex = static function ($date) use ($from, $to, $span, $buckets): ?int {
            if (!$date || $date->lt($from) || $date->gt($to)) return null;
            $offset = max(0, $from->diffInSeconds($date, false));
            return min($buckets - 1, (int) floor(($offset / $span) * $buckets));
        };

        foreach ($inquiries as $inquiry) {
            if ($inquiry->created_at) {
                $local = $inquiry->created_at->copy()->setTimezone($timezone);
                $index = $bucketIndex($local);
                if ($index !== null) $created[$index]++;
            }

            if ($this->isCompleted($inquiry)) {
                $completedAt = $this->inquiryCompletedAt($inquiry);
                if ($completedAt) {
                    $local = $completedAt->copy()->setTimezone($timezone);
                    $index = $bucketIndex($local);
                    if ($index !== null) $completed[$index]++;
                }
            }
        }

        $max = max(1, ...$created, ...$completed);
        $createdPoints = [];
        $completedPoints = [];
        for ($i = 0; $i < $buckets; $i++) {
            $x = round($i * 700 / max(1, $buckets - 1), 1);
            $createdPoints[] = $x.','.round(165 - ($created[$i] / $max * 130), 1);
            $completedPoints[] = $x.','.round(165 - ($completed[$i] / $max * 130), 1);
        }

        return [
            'labels' => $labels,
            'created' => $created,
            'completed' => $completed,
            'created_points' => implode(' ', $createdPoints),
            'completed_points' => implode(' ', $completedPoints),
            'created_fill_points' => '0,180 '.implode(' ', $createdPoints).' 700,180',
        ];
    }

    /**
     * Build task lifecycle timing from the audit trail in one query.
     *
     * The report clock starts when a task actually enters a working/In Progress
     * status and stops on the matching completion event. Reopened tasks begin a
     * fresh cycle, so an old completion never contaminates the next duration.
     * Historical rows written before explicit status metadata was added are
     * recovered from their activity descriptions where possible.
     *
     * @return array{
     *   reopen_counts: array<int,int>,
     *   reopen_latest_timestamps: array<int,int>,
     *   timings: array<int,array{started_at:mixed,completed_at:mixed,seconds:?int,active_started_at?:mixed}>
     * }
     */
    private function taskLifecycleStats(array $inquiryIds): array
    {
        if ($inquiryIds === []) {
            return [
                'reopen_counts' => [],
                'reopen_latest_timestamps' => [],
                'timings' => [],
            ];
        }

        $reopenCounts = [];
        $reopenLatest = [];
        $timings = [];
        $activeStarts = [];
        $inquiryService = app(InquiryService::class);

        Activity::query()
            ->where('subject_type', Inquiry::class)
            ->whereIn('subject_id', $inquiryIds)
            ->whereIn('event', [
                'inquiry.task_updated',
                'inquiry.task_status_changed',
                'inquiry.task_completed',
                'inquiry.task_reopened',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'event', 'description', 'meta', 'created_at'])
            ->each(function (Activity $activity) use (&$reopenCounts, &$reopenLatest, &$timings, &$activeStarts, $inquiryService): void {
                $taskId = (int) data_get($activity->meta, 'inquiry_task_id');
                if ($taskId <= 0 || !$activity->created_at) return;

                $at = $activity->created_at;
                $event = (string) $activity->event;
                $toStatus = $this->activityTaskStatus($activity);

                if ($event === 'inquiry.task_reopened') {
                    $reopenCounts[$taskId] = (int) ($reopenCounts[$taskId] ?? 0) + 1;
                    $reopenLatest[$taskId] = max(
                        (int) ($reopenLatest[$taskId] ?? 0),
                        (int) $at->timestamp
                    );

                    // A reopen closes the previous completed cycle. If the task
                    // reopens directly into a working status, that transition is
                    // the start of the new cycle; otherwise wait for In Progress.
                    $activeStarts[$taskId] = null;
                    if ($toStatus !== null && $inquiryService->isWorkingTaskStatus($toStatus)) {
                        $activeStarts[$taskId] = $at;
                    }
                    return;
                }

                if ($event === 'inquiry.task_completed') {
                    $startedAt = $activeStarts[$taskId] ?? null;
                    $seconds = null;
                    if ($startedAt && $at->gte($startedAt)) {
                        $seconds = max(1, $startedAt->diffInSeconds($at));
                    }

                    // Always keep the real completion-event timestamp. The start
                    // remains null when no genuine In Progress transition exists.
                    $timings[$taskId] = [
                        'started_at' => $startedAt,
                        'completed_at' => $at,
                        'seconds' => $seconds,
                    ];
                    $activeStarts[$taskId] = null;
                    return;
                }

                if ($toStatus === null) return;

                $isCompletedStatus = strcasecmp(
                    $inquiryService->autoInquiryStatusForTaskStatus($toStatus),
                    InquiryService::AUTO_COMPLETED_STATUS
                ) === 0;

                if ($isCompletedStatus) {
                    // Older/custom writers may have logged only a status change.
                    $startedAt = $activeStarts[$taskId] ?? null;
                    $seconds = $startedAt && $at->gte($startedAt)
                        ? max(1, $startedAt->diffInSeconds($at))
                        : null;
                    $timings[$taskId] = [
                        'started_at' => $startedAt,
                        'completed_at' => $at,
                        'seconds' => $seconds,
                    ];
                    $activeStarts[$taskId] = null;
                    return;
                }

                if ($inquiryService->isWorkingTaskStatus($toStatus) && empty($activeStarts[$taskId])) {
                    $activeStarts[$taskId] = $at;
                }
            });

        foreach ($activeStarts as $taskId => $activeStart) {
            if (!$activeStart) continue;
            $timings[(int) $taskId] = array_merge(
                $timings[(int) $taskId] ?? [
                    'started_at' => null,
                    'completed_at' => null,
                    'seconds' => null,
                ],
                ['active_started_at' => $activeStart]
            );
        }

        return [
            'reopen_counts' => $reopenCounts,
            'reopen_latest_timestamps' => $reopenLatest,
            'timings' => $timings,
        ];
    }

    /**
     * Resolve the target status from new structured metadata first, then from
     * historical human-readable activity descriptions for backward compatibility.
     */
    private function activityTaskStatus(Activity $activity): ?string
    {
        foreach (['to_status', 'new_status'] as $key) {
            $status = trim((string) data_get($activity->meta, $key, ''));
            if ($status !== '') return $status;
        }

        $description = trim((string) $activity->description);
        $event = (string) $activity->event;

        if ($event === 'inquiry.task_completed') return InquiryService::AUTO_COMPLETED_STATUS;

        if ($event === 'inquiry.task_status_changed'
            && preg_match('/\bstatus changed from .+? to (.+?)\.\s*$/ui', $description, $matches)) {
            return trim((string) ($matches[1] ?? '')) ?: null;
        }

        if ($event === 'inquiry.task_reopened'
            && preg_match('/\bstatus changed to (.+?)\.\s*$/ui', $description, $matches)) {
            return trim((string) ($matches[1] ?? '')) ?: null;
        }

        if ($event === 'inquiry.task_updated'
            && preg_match('/\bupdated\s+[—-]\s+(.+?)\.\s*$/ui', $description, $matches)) {
            return trim((string) ($matches[1] ?? '')) ?: null;
        }

        return null;
    }

    private function statusOptions(User $user, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
    {
        $configured = app(InquiryService::class)->inquiryStatusOptions();
        $stored = app(InquiryService::class)->visibleQuery($user)
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->whereNotNull('status')
            ->distinct()
            ->pluck('status');

        return $configured
            ->merge($stored)
            ->merge(['Completed', 'Converted', 'Dead'])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique(fn (string $value) => mb_strtolower($value))
            ->values()
            ->all();
    }

    private function priorityOptions(User $user, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
    {
        $configured = app(MasterDataService::class)->active('priority')
            ->pluck('name')
            ->map(fn ($value) => trim((string) $value))
            ->filter();
        $stored = app(InquiryService::class)->visibleQuery($user)
            ->whereBetween('created_at', [$fromUtc, $toUtc])
            ->whereNotNull('priority')
            ->distinct()
            ->pluck('priority')
            ->map(fn ($value) => trim((string) $value))
            ->filter();

        return $configured
            ->merge($stored)
            ->unique(fn (string $value) => mb_strtolower($value))
            ->values()
            ->all();
    }

    private function assigneeOptions(): array
    {
        // Inquiry Intelligence must use the real active workspace roster only.
        // Administrator and Super Admin accounts are management/system accounts
        // and are intentionally excluded from employee performance selectors.
        return $this->performanceUsersQuery()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $assignee) => [
                'id' => (int) $assignee->id,
                'name' => (string) $assignee->name,
            ])
            ->all();
    }

    /** @return list<int> */
    private function performanceUserIds(): array
    {
        return $this->performanceUsersQuery()
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function performanceUsersQuery(): Builder
    {
        $administratorSlugs = ['super-admin', 'admin', 'administrator'];

        // Performance reporting follows the real FlowTrack user roster. Do not
        // require a workspace_memberships row here: older/imported users can be
        // valid active assignees even when that auxiliary row was never
        // backfilled. The users table is authoritative for whether the account
        // exists/is active; Admin and Super Admin accounts are excluded.
        return User::query()
            ->where('users.is_active', true)
            ->where('users.is_super_admin', false)
            ->whereDoesntHave('roles', fn (Builder $role) => $role
                ->where('is_active', true)
                ->whereIn('slug', $administratorSlugs))
            ->whereDoesntHave('role', fn (Builder $role) => $role
                ->where('is_active', true)
                ->whereIn('slug', $administratorSlugs));
    }

    private function periodBounds(string $period): array
    {
        $now = app(WorkspaceSettingsService::class)->localNow();
        $period = in_array($period, ['month','30d','qtd','ytd'], true) ? $period : 'month';

        return match ($period) {
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay(), 'Last 30 days'],
            'qtd' => [$now->startOfQuarter()->startOfDay(), $now->endOfDay(), 'Quarter to date'],
            'ytd' => [$now->startOfYear()->startOfDay(), $now->endOfDay(), 'Year to date'],
            default => [$now->startOfMonth()->startOfDay(), $now->endOfDay(), $now->format('F Y')],
        };
    }

    private function inquiryDisplayStatus(Inquiry $inquiry): string
    {
        if ($this->isConverted($inquiry)) return 'Converted';
        if (strcasecmp((string) $inquiry->result, 'Dead') === 0 || strcasecmp((string) $inquiry->status, 'Dead') === 0) return 'Dead';
        if ($this->isCompleted($inquiry)) return 'Completed';
        return trim((string) $inquiry->status) ?: 'In Progress';
    }

    private function isCompleted(Inquiry $inquiry): bool
    {
        if ($this->isConverted($inquiry)) return true;
        if ($inquiry->completed_at || filled($inquiry->result)) return true;

        $status = mb_strtolower(trim((string) $inquiry->status));
        if (in_array($status, ['completed', 'converted', 'closed', 'dead'], true)) return true;

        return $inquiry->tasks->isNotEmpty()
            && $inquiry->tasks->every(fn (InquiryTask $task) => $task->completed_at !== null);
    }

    private function isConverted(Inquiry $inquiry): bool
    {
        return (bool) ($inquiry->converted_job_id || $inquiry->convertedJob || $inquiry->sourceOrder || strcasecmp((string) $inquiry->result, 'Converted') === 0);
    }

    private function currentOpenTask(Collection $tasks): ?InquiryTask
    {
        return $tasks
            ->whereNull('completed_at')
            ->sortBy(function (InquiryTask $task): string {
                $startedBucket = $task->started_at ? '0' : '1';
                $sequence = $task->started_at ? (999999 - (int) $task->sequence) : (int) $task->sequence;
                return $startedBucket.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            })
            ->first();
    }

    private function needsAttention(Inquiry $inquiry): bool
    {
        if ($inquiry->needs_attention) return true;

        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

        return $inquiry->tasks->contains(function (InquiryTask $task) use ($today): bool {
            if ($task->completed_at) return false;
            if (!$task->assignee_id) return true;
            if ($task->due_date && $task->due_date->toDateString() < $today) return true;
            return $task->needs_attention || app(InquiryService::class)->taskStatusNeedsAttention((string) $task->status);
        });
    }

    private function attentionLabel(Inquiry $inquiry): array
    {
        if ($this->isCompleted($inquiry)) {
            return ['label' => 'No open task', 'tone' => 'green'];
        }

        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $openTasks = $inquiry->tasks->whereNull('completed_at')->values();

        $overdue = $openTasks->first(fn (InquiryTask $task) => $task->due_date && $task->due_date->toDateString() < $today);
        if ($overdue) return ['label' => 'Overdue', 'tone' => 'red'];

        $attentionTask = $openTasks->first(fn (InquiryTask $task) => $task->needs_attention
            || app(InquiryService::class)->taskStatusNeedsAttention((string) $task->status));
        if ($attentionTask) {
            return ['label' => trim((string) $attentionTask->status) ?: 'Requires attention', 'tone' => 'amber'];
        }

        if ($inquiry->needs_attention) {
            return ['label' => trim((string) $inquiry->attention_reason) ?: 'Requires attention', 'tone' => 'amber'];
        }

        if ($openTasks->contains(fn (InquiryTask $task) => !$task->assignee_id)) {
            return ['label' => 'Unassigned', 'tone' => 'amber'];
        }

        return ['label' => 'On track', 'tone' => 'blue'];
    }

    /**
     * Return the effective task timing for reporting. Activity-derived timing is
     * authoritative because it represents the real In Progress -> Completed
     * cycle. The model timestamps are only a legacy fallback when they form a
     * positive interval; equal timestamps are a direct-completion artifact and
     * must not be reported as a genuine 0-minute work duration.
     *
     * @return array{started_at:mixed,completed_at:mixed,seconds:?int}
     */
    private function taskTiming(InquiryTask $task, array $taskTimingStats = []): array
    {
        $history = $taskTimingStats[(int) $task->id] ?? null;

        // For a reopened/currently open task, never display its previous cycle's
        // completion as though it were the current one. Show the current working
        // start when the audit trail has it and keep the duration explicitly open.
        if (!$task->completed_at) {
            return [
                'started_at' => is_array($history) ? ($history['active_started_at'] ?? null) : null,
                'completed_at' => null,
                'seconds' => null,
            ];
        }

        if (is_array($history) && ($history['completed_at'] ?? null)) {
            $historyCompleted = $history['completed_at'];
            $historyStarted = $history['started_at'] ?? null;
            $historySeconds = isset($history['seconds']) ? (int) $history['seconds'] : null;

            // Some older activities recorded the completion event but not the
            // preceding status value. In that case, preserve a trustworthy legacy
            // started_at only when it is strictly before the real completion event.
            if (!$historyStarted && $task->started_at && $historyCompleted->gt($task->started_at)) {
                $historyStarted = $task->started_at;
                $historySeconds = max(1, $task->started_at->diffInSeconds($historyCompleted));
            }

            return [
                'started_at' => $historyStarted,
                'completed_at' => $historyCompleted,
                'seconds' => $historySeconds,
            ];
        }

        if (!$task->started_at || !$task->completed_at || !$task->completed_at->gt($task->started_at)) {
            return [
                'started_at' => null,
                'completed_at' => $task->completed_at,
                'seconds' => null,
            ];
        }

        return [
            'started_at' => $task->started_at,
            'completed_at' => $task->completed_at,
            'seconds' => $task->started_at->diffInSeconds($task->completed_at),
        ];
    }

    private function taskHours(InquiryTask $task, array $taskTimingStats = []): ?float
    {
        $timing = $this->taskTiming($task, $taskTimingStats);
        if ($timing['seconds'] === null) return null;

        return max(0, (int) $timing['seconds']) / 3600;
    }

    private function inquiryCycleHours(Inquiry $inquiry): ?float
    {
        $end = $this->inquiryCompletedAt($inquiry);
        $start = $inquiry->started_at ?: $inquiry->created_at;
        if (!$start || !$end) return null;
        return max(0, $start->diffInSeconds($end) / 3600);
    }

    private function inquiryCompletedAt(Inquiry $inquiry)
    {
        if ($inquiry->completed_at) return $inquiry->completed_at;
        if ($inquiry->convertedJob?->created_at) return $inquiry->convertedJob->created_at;
        if ($inquiry->sourceOrder?->created_at) return $inquiry->sourceOrder->created_at;

        $taskCompletedAt = $inquiry->tasks->whereNotNull('completed_at')->max('completed_at');
        if ($taskCompletedAt && $inquiry->tasks->isNotEmpty() && $inquiry->tasks->every(fn (InquiryTask $task) => $task->completed_at !== null)) {
            return $taskCompletedAt;
        }

        if (filled($inquiry->result) || in_array(mb_strtolower(trim((string) $inquiry->status)), ['completed', 'converted', 'closed', 'dead'], true)) {
            return $inquiry->updated_at;
        }

        return null;
    }

    private function formatHours(float $hours): string
    {
        $minutes = (int) round($hours * 60);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($minutes <= 0 && $hours > 0) return '<1m';
        if ($h <= 0) return $m.'m';
        return $h.'h '.str_pad((string) $m, 2, '0', STR_PAD_LEFT).'m';
    }

    private function initials(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'U';
    }

    private function authorize(User $user): void
    {
        abort_unless(app(AccessControlService::class)->can($user, 'reports', 'view'), 403);
    }
}
