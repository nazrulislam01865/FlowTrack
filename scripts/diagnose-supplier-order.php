<?php
// Read-only diagnosis of the exact supplier order projection and its underlying records.
// Run: php scripts/diagnose-supplier-order.php ORDER-2026-00455 34785
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$reference = (string) ($argv[1] ?? '');
$supplierId = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($reference === '' || $supplierId === false) { fwrite(STDERR, "Usage: php scripts/diagnose-supplier-order.php ORDER-2026-00455 34785\n"); exit(2); }
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$jobNumber = str_starts_with($reference, 'ORDER-') ? 'JOB-'.substr($reference, 6) : $reference;
$order = ctype_digit($reference)
    ? \App\Models\FlowJob::query()->find((int) $reference)
    : \App\Models\FlowJob::query()->where('job_number', $jobNumber)->orWhere('job_number', $reference)->first();
if (! $order) { fwrite(STDERR, "Order not found. Use its displayed ORDER-* reference or numeric FlowTracker order ID.\n"); exit(2); }
$order->loadMissing(['phase', 'workflow.phases.taskPack.items', 'tasks.setupTemplate', 'tasks.documents:id,task_id', 'tasks.links:id,task_id']);
$projection = app(\App\Services\SupplierBridge\SupplierOrderProjection::class)
    ->build((int) $order->id, (int) $supplierId, (string) \Illuminate\Support\Str::uuid());
$view = $projection['order'] ?? [];
$workflow = $view['workflow']['stages'] ?? [];
$assignments = \App\Models\SupplierTaskAssignment::query()->where('flow_job_id', $order->id)
    ->where('supplier_id', $supplierId)->pluck('task_id')->map(fn ($v) => (int) $v)->all();
$first = app(\App\Services\OrderTaskSequenceService::class)->firstIncompleteRequiredTask(
    $order, (int) $order->workflow_phase_id
);
$taskRows = \Illuminate\Support\Facades\DB::table('tasks as t')
    ->join('task_pack_items as pack', 'pack.id', '=', 't.task_pack_task_id')
    ->where('t.flow_job_id', $order->id)->whereNull('t.deleted_at')
    ->where('pack.automation_key', 'like', 'ART_%')
    ->orderByDesc('t.id')->get(['t.id', 't.status', 't.completed_at', 'pack.automation_key']);
$artwork = app(\App\Services\SupplierBridge\ApprovedOrderArtwork::class);
$resolved = \App\Support\OrderStageResolver::resolve($order->phase?->name,
    $order->phase?->short_name, $order->phase?->sequence, $order->status);
$confirmed = $artwork->confirmed((int) $resolved['sequence'], $taskRows, $order->completed_at !== null);
$uploadTask = $taskRows->firstWhere('automation_key', 'ART_PREPARE_UPLOAD');
$uploadDocuments = $uploadTask
    ? \Illuminate\Support\Facades\DB::table('documents')->where('task_id', (int) $uploadTask->id)
        ->where('flow_job_id', (int) $order->id)->count() : 0;
$selected = $confirmed ? $artwork->documents((int) $order->id, $taskRows) : collect();
$delivery = \Illuminate\Support\Facades\DB::table('supplier_order_deliveries')
    ->where('flow_job_id', $order->id)->where('supplier_id', $supplierId)
    ->first(['status','attempts','last_error','next_attempt_at','delivered_at','updated_at']);
$detail = [
    'order_id' => (int) $order->id,
    'reference' => $order->displayOrderNumber(),
    'current_phase_id' => (int) $order->workflow_phase_id,
    'current_phase_sequence' => (int) ($order->phase?->sequence ?? 0),
    'order_status' => (string) $order->status,
    'supplier_id' => (int) $supplierId,
    'first_incomplete_required_task_id' => $first?->id,
    'assigned_task_ids' => $assignments,
    'source_artwork_tasks' => $taskRows->map(fn ($t) => [
        'id' => (int) $t->id, 'key' => $t->automation_key, 'status' => $t->status,
        'completed' => $t->completed_at !== null,
    ])->values()->all(),
    'artwork_review_confirmed' => $confirmed,
    'raw_documents_on_upload_task' => $uploadDocuments,
    'approved_documents_selected' => $selected->count(),
    'documents_in_signed_projection' => count($view['documents'] ?? []),
    'projection_workflow' => array_values(array_map(static fn (array $stage) => [
        'sequence' => $stage['sequence'] ?? null, 'stage_state' => $stage['state'] ?? null,
        'tasks' => array_values(array_map(static fn (array $task) => [
            'id' => $task['id'], 'code' => $task['code'], 'status' => $task['status'],
            'mode' => $task['mode'], 'required' => $task['required'],
            'assigned_to_supplier' => $task['assigned_to_supplier'],
            'can_act' => $task['can_act'], 'action_key' => $task['action_key'],
        ], $stage['tasks'] ?? [])),
    ], array_filter($workflow, static fn (array $stage) => (int) ($stage['sequence'] ?? 0) === 2))),
    'delivery_outbox' => $delivery ? [
        'status' => $delivery->status, 'attempts' => (int) $delivery->attempts,
        'last_error' => $delivery->last_error, 'next_attempt_at' => $delivery->next_attempt_at,
        'delivered_at' => $delivery->delivered_at,
    ] : null,
];
echo json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
