<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('flowtrack:sync-legacy', function (): int {
    $this->info('Synchronizing legacy Master Data...');
    app(\App\Services\MasterDataService::class)->syncLegacy();

    $this->info('Synchronizing legacy Task Packs...');
    app(\App\Services\TaskPackService::class)->syncLegacy();

    $this->info('Synchronizing legacy Workflows...');
    app(\App\Services\WorkflowService::class)->syncLegacy();

    $this->info('Legacy synchronization completed.');
    return 0;
})->purpose('Run legacy compatibility synchronization explicitly outside web requests');

Artisan::command('flowtrack:sync-order-flags', function (): int {
    $count = app(\App\Services\OrderTaskFlagService::class)->syncDueTransitions(true);
    $this->info('Order task/order flags synchronized for '.$count.' due-date transition(s).');

    return 0;
})->purpose('Persist automatic overdue Order Task Flags and parent Order Flags');

// Due-date flags must change even when nobody edits the task. Keep this work
// completely outside normal web requests so page loads never become responsible
// for scanning and updating open tasks. The scheduler is the single owner.
Schedule::command('flowtrack:sync-order-flags')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();


Artisan::command('flowtrack:send-rfq-reminders', function (): int {
    $result = app(\App\Services\Inquiries\InquiryRfqService::class)->sendDueReminders();
    $this->info('RFQ due-date reminders sent: '.(int) $result['sent'].'. Failed: '.(int) $result['failed'].'.');
    return (int) $result['failed'] > 0 ? 1 : 0;
})->purpose('Send supplier RFQ reminders at each inquiry configured reminder window');

Schedule::command('flowtrack:send-rfq-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Artisan::command('flowtrack:performance:explain {--user=1 : User ID used for assignee/member query plans}', function (): int {
    $userId = max(1, (int) $this->option('user'));
    $driver = DB::connection()->getDriverName();
    $prefix = $driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';

    $queries = [
        'Open Jobs for owner / delivery' => [
            'flow_jobs', 'ft_jobs_owner_open_due_idx',
            "select id from flow_jobs where owner_id = ? and deleted_at is null and completed_at is null order by delivery_date asc limit 60",
            [$userId],
        ],
        'Open Jobs in workflow phase' => [
            'flow_jobs', 'ft_jobs_phase_open_idx',
            "select id from flow_jobs where workflow_phase_id = ? and deleted_at is null and completed_at is null order by id desc limit 60",
            [1],
        ],
        'Attention Jobs ordered by update' => [
            'flow_jobs', 'ft_jobs_attention_updated_idx',
            "select id from flow_jobs where attention_requested = ? and completed_at is null order by updated_at desc limit 60",
            [1],
        ],
        'Open Tasks for Job / due date' => [
            'tasks', 'ft_tasks_job_open_due_idx',
            "select id from tasks where flow_job_id = ? and deleted_at is null and completed_at is null order by due_date asc limit 60",
            [1],
        ],
        'Open Tasks for workflow phase' => [
            'tasks', 'ft_tasks_phase_open_due_idx',
            "select id from tasks where workflow_phase_id = ? and deleted_at is null and completed_at is null order by due_date asc limit 60",
            [1],
        ],
        'Open Inquiries in workspace' => [
            'inquiries', 'ft_inquiries_workspace_open_updated_idx',
            "select id from inquiries where workspace_id = ? and deleted_at is null and completed_at is null order by updated_at desc limit 60",
            [1],
        ],
        'Open Inquiries for owner' => [
            'inquiries', 'ft_inquiries_owner_open_idx',
            "select id from inquiries where workspace_id = ? and owner_id = ? and deleted_at is null and completed_at is null limit 60",
            [1, $userId],
        ],
        'Client Inquiries ordered by update' => [
            'inquiries', 'ft_inquiries_client_updated_idx',
            "select id from inquiries where workspace_id = ? and client_id = ? and deleted_at is null order by updated_at desc limit 60",
            [1, 1],
        ],
        'Open Inquiry Tasks in sequence' => [
            'inquiry_tasks', 'ft_inquiry_tasks_parent_open_seq_idx',
            "select id from inquiry_tasks where inquiry_id = ? and deleted_at is null and completed_at is null order by sequence asc limit 60",
            [1],
        ],
        'Open Inquiry Tasks for assignee' => [
            'inquiry_tasks', 'ft_inquiry_tasks_assignee_open_due_idx',
            "select id from inquiry_tasks where assignee_id = ? and deleted_at is null and completed_at is null order by due_date asc limit 60",
            [$userId],
        ],
        'Inquiry Documents by parent update' => [
            'inquiry_documents', 'ft_inquiry_documents_parent_updated_idx',
            "select id from inquiry_documents where inquiry_id = ? order by updated_at desc limit 60",
            [1],
        ],
        'Active non-archived Clients' => [
            'clients', 'ft_clients_active_archived_name_idx',
            "select id from clients where is_active = ? and archived_at is null order by name limit 60",
            [1],
        ],
        // Existing indexes are intentionally re-used instead of being duplicated
        // by Phase 11. Keep representative checks for those query families too.
        'Unread notifications (existing index)' => [
            'flow_notifications', 'ft_notifications_user_read_created_idx',
            "select id from flow_notifications where user_id = ? and read_at is null order by created_at desc limit 30",
            [$userId],
        ],
        'Recent subject activity (existing index)' => [
            'activities', 'ft_activities_subject_created_idx',
            "select id from activities where subject_type = ? and subject_id = ? order by created_at desc limit 30",
            ['App\\Models\\FlowJob', 1],
        ],
        'Active Master Data ordering (existing index)' => [
            'master_records', 'ft_master_active_deleted_sort_idx',
            "select id from master_records where workspace_id = ? and type = ? and status = ? and deleted_at is null order by sort_order, name",
            [1, 'order_task_status', 'active'],
        ],
    ];
    foreach ($queries as $label => [$table, $expectedIndex, $sql, $bindings]) {
        if (!Schema::hasTable($table)) {
            $this->warn($label.': skipped because '.$table.' does not exist.');
            continue;
        }

        $this->newLine();
        $this->info($label);
        $rows = collect(DB::select($prefix.$sql, $bindings))->map(fn ($row) => (array) $row);
        if ($rows->isEmpty()) {
            $this->line('No EXPLAIN rows returned.');
            continue;
        }
        $this->table(array_keys($rows->first()), $rows->map(fn ($row) => array_values($row))->all());
        $chosenKeys = $rows->pluck('key')->filter()->map('strval')->all();
        $this->line('Expected index: '.$expectedIndex);
        if ($driver !== 'sqlite' && $chosenKeys !== [] && ! in_array($expectedIndex, $chosenKeys, true)) {
            $this->warn('Optimizer selected a different index. Review rows/access type before deciding whether the expected index is useful.');
        }
    }

    $this->newLine();
    $this->comment('Review the chosen key/access type/rows against each expected index on a representative production-like dataset.');

    return 0;
})->purpose('Run EXPLAIN against FlowTrack high-frequency query shapes');

// Rejected/failed uploads are never promoted to business records. Keep them
// private for a short operator-review window, then purge them automatically.
Schedule::command('flowtrack:purge-document-quarantine')->dailyAt('02:30')->withoutOverlapping()->onOneServer();


// Phase 14: queue depth monitoring is deliberately scheduler-owned so web
// requests never spend time inspecting worker state.
if ((bool) config('scalability.queues.monitor_enabled', true)) {
    $connection = (string) config('queue.default', 'database');
    $queues = (array) config('scalability.queues.names', ['realtime', 'notifications', 'default']);
    $targets = collect($queues)->filter()->map(fn ($queue) => $connection.':'.$queue)->implode(',');
    if ($targets !== '') {
        Schedule::command('queue:monitor '.$targets.' --max='.(int) config('scalability.queues.max_depth', 100))
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();
    }
}

if ((bool) config('scalability.backup.enabled', false)) {
    Schedule::command('flowtrack:database:backup')
        ->dailyAt((string) config('scalability.backup.schedule', '01:30'))
        ->withoutOverlapping()
        ->onOneServer();
}

// Phase 15: Redis-backed operational snapshot/alert surface. This stays CLI
// only so observability does not add another authenticated web UI or expose
// internal metrics through a public endpoint.
Artisan::command('flowtrack:observability:snapshot {--minutes= : Rolling window in minutes} {--json : Emit machine-readable JSON}', function (): int {
    $minutes = $this->option('minutes');
    $snapshot = app(\App\Services\Observability\OperationsMetrics::class)
        ->snapshot($minutes !== null ? max(1, (int) $minutes) : null);

    if ($this->option('json')) {
        $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return ($snapshot['available'] ?? true) ? 0 : 2;
    }

    $this->info('FlowTrack observability snapshot ('.$snapshot['window_minutes'].' minute window)');
    $this->table(['Metric', 'Value'], [
        ['Requests', $snapshot['requests'] ?? 0],
        ['HTTP 5xx rate', ($snapshot['http_error_rate_percent'] ?? 0).'%'],
        ['Request p95', data_get($snapshot, 'request_ms.p95', 0).' ms'],
        ['Query-time p95', data_get($snapshot, 'query_time_ms.p95', 0).' ms'],
        ['Memory p95', data_get($snapshot, 'memory_peak_mb.p95', 0).' MB'],
        ['Slow queries', $snapshot['slow_queries'] ?? 0],
        ['Cache hit rate', ($snapshot['cache_hit_rate_percent'] ?? 'n/a').(is_numeric($snapshot['cache_hit_rate_percent'] ?? null) ? '%' : '')],
        ['Queue delay p95', data_get($snapshot, 'queue.delay_seconds.p95', 0).' s'],
        ['Queue failures', data_get($snapshot, 'queue.failures', 0)],
        ['Realtime reconnects', data_get($snapshot, 'realtime.reconnect', 0)],
        ['Realtime error rate', data_get($snapshot, 'realtime.error_rate_percent', 0).'%'],
    ]);

    return ($snapshot['available'] ?? true) ? 0 : 2;
})->purpose('Show rolling FlowTrack HTTP/DB/cache/queue/realtime operational metrics');

Artisan::command('flowtrack:observability:check {--minutes= : Rolling window in minutes}', function (): int {
    $metrics = app(\App\Services\Observability\OperationsMetrics::class);
    $minutes = $this->option('minutes');
    $snapshot = $metrics->snapshot($minutes !== null ? max(1, (int) $minutes) : null);
    if (($snapshot['available'] ?? true) === false) {
        logger()->error('flowtrack.observability.check_unavailable', ['snapshot' => $snapshot]);
        $this->error('Observability store is unavailable.');
        return 2;
    }

    $alerts = $metrics->alerts($snapshot);
    if ($alerts === []) {
        $this->info('FlowTrack observability thresholds are healthy.');
        return 0;
    }

    foreach ($alerts as $alert) logger()->warning('flowtrack.observability.alert', $alert + ['window_minutes' => $snapshot['window_minutes']]);
    $this->warn(count($alerts).' FlowTrack observability threshold(s) exceeded.');
    $this->table(['Metric', 'Value', 'Direction', 'Limit'], array_map(
        static fn (array $alert): array => [$alert['metric'], $alert['value'], $alert['direction'], $alert['limit']],
        $alerts,
    ));
    return 1;
})->purpose('Evaluate FlowTrack rolling operational metrics against alert thresholds');

if ((bool) config('observability.enabled', false)) {
    Schedule::command('flowtrack:observability:check')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer();
}

// Durable one-way supplier delivery; independent of worker availability.
Artisan::command('flowtrack:sync-supplier-orders', function (): int {
    if (!config('supplier_bridge.enabled')) {
        $this->line('Supplier bridge disabled.');
        return 0;
    }
    $ids = DB::table('supplier_order_deliveries')
        ->where(function ($query): void {
            $query->where('status', 'pending')
                ->orWhere(fn ($stale) => $stale->where('status', 'sending')->where('locked_at', '<', now()->subMinutes(5)));
        })
        ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
        ->orderBy('id')->limit(50)->pluck('id');
    $sent = 0;
    foreach ($ids as $id) {
        if (app(\App\Services\SupplierBridge\DeliverOrder::class)->send((int) $id)) $sent++;
    }
    $this->info("Supplier order deliveries: {$sent} sent, ".($ids->count() - $sent).' not sent.');
    return 0;
})->purpose('Retry supplier order-created API notifications without exposing FlowTracker');

// Refresh only delivered orders whose workflow, assigned lines, or artwork changed.
Artisan::command('flowtrack:refresh-supplier-orders {--all : Queue every previously delivered order once} {--order= : Only this FlowTracker order ID} {--dry-run : Count without modifying records}', function (): int {
    if (! config('supplier_bridge.enabled')) { $this->error('Supplier bridge disabled.'); return 1; }
    $query = DB::table('supplier_order_deliveries as d')
        ->join('flow_jobs as j', 'j.id', '=', 'd.flow_job_id')
        ->where('d.status', 'delivered');
    if ($this->option('order') !== null) {
        $id = filter_var($this->option('order'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) { $this->error('Invalid order ID.'); return 1; }
        $query->where('d.flow_job_id', $id);
    } elseif (! $this->option('all')) {
        $query->where(function ($q): void {
            // A deployed projection fix can change artwork and task actions
            // without touching any order/task row. Refresh older accepted
            // formats once, then use the existing event and timestamp checks.
            $q->whereNull('d.payload->projection_version')
                ->orWhere('d.payload->projection_version', '!=',
                    \App\Services\SupplierBridge\SupplierOrderProjection::FORMAT_VERSION)
                ->orWhereColumn('j.updated_at', '>=', 'd.delivered_at')
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('tasks as t')
                    ->whereColumn('t.flow_job_id', 'd.flow_job_id')->whereColumn('t.updated_at', '>=', 'd.delivered_at'))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('documents as doc')
                    ->whereColumn('doc.flow_job_id', 'd.flow_job_id')->whereColumn('doc.updated_at', '>=', 'd.delivered_at'))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('flow_job_items as i')
                    ->whereColumn('i.flow_job_id', 'd.flow_job_id')->whereColumn('i.updated_at', '>=', 'd.delivered_at'))
                // Assigning or clearing a supplier task does not necessarily
                // modify tasks.updated_at. Include assignment-only changes.
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('supplier_task_assignments as a')
                    ->whereColumn('a.flow_job_id', 'd.flow_job_id')->whereColumn('a.updated_at', '>=', 'd.delivered_at'));
        });
    }
    $count = 0;
    $lastId = 0;
    // A manual --all walks the entire archive in bounded batches, not the same first 500 repeatedly.
    do {
        $ids = (clone $query)->where('d.id', '>', $lastId)->orderBy('d.id')
            ->limit(100)->pluck('d.id');
        if ($ids->isEmpty()) break;
        $lastId = (int) $ids->last();
        $count += $ids->count();
        if (! $this->option('dry-run')) {
            DB::table('supplier_order_deliveries')->whereIn('id', $ids->all())
                ->where('status', 'delivered')->update([
                    'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null,
                    'locked_at' => null, 'updated_at' => now(),
                ]);
        }
    } while (true);
    $this->info($count.($this->option('dry-run') ? ' matching deliveries (dry run).' : ' supplier order refreshes queued.'));
    return 0;
})->purpose('Queue changed supplier order details and approved artwork for retry-safe delivery');

// Reconcile before sending on the same minute. Stage and assignment updates
// also enqueue immediately via the existing model observers.
Schedule::command('flowtrack:refresh-supplier-orders')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('flowtrack:sync-supplier-orders')->everyMinute()->withoutOverlapping()->onOneServer();

// Retry both existing and newly sent supplier RFQs without blocking inquiry email delivery.
Artisan::command('flowtrack:sync-supplier-rfqs', function (): int {
    if (! config('supplier_bridge.enabled')) {
        $this->line('Supplier bridge disabled.');
        return 0;
    }
    $ids = DB::table('supplier_rfq_deliveries')
        ->where(function ($q): void {
            $q->where('status', 'pending')
                ->orWhere(fn ($stale) => $stale->where('status', 'sending')->where('locked_at', '<', now()->subMinutes(5)));
        })
        ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
        ->orderBy('id')->limit(50)->pluck('id');
    $sent = 0;
    foreach ($ids as $id) {
        if (app(\App\Services\SupplierBridge\DeliverRfq::class)->send((int) $id)) $sent++;
    }
    $this->info("Supplier RFQ deliveries: {$sent} sent, ".($ids->count() - $sent).' pending.');
    return 0;
})->purpose('Retry supplier RFQ invitations to the isolated Supplier Portal');

Schedule::command('flowtrack:sync-supplier-rfqs')->everyMinute()->withoutOverlapping()->onOneServer();

// Coalesced, supplier-scoped changes. No periodic expensive full-catalogue scans.
\Illuminate\Support\Facades\Schedule::command('flowtrack:sync-pending-supplier-catalog')->everyMinute()->withoutOverlapping();
// Branding is a separate durable outbox and never forces a product/cost sync.
\Illuminate\Support\Facades\Schedule::command('flowtrack:sync-pending-supplier-branding')->everyMinute()->withoutOverlapping();

// Low-frequency safety reconciliation also catches changes performed by external imports.
\Illuminate\Support\Facades\Schedule::command('flowtrack:sync-supplier-catalog')->dailyAt('02:30')->withoutOverlapping();
