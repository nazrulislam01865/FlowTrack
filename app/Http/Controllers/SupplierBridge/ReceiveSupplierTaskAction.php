<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\FlowJob;
use App\Models\SupplierTaskAssignment;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\OrderWorkflowActionService;
use App\Services\WorkspaceSettingsService;
use App\Services\DocumentService;
use App\Services\SecureDocumentStorage;
use App\Support\AttachmentUpload;
use App\Services\SupplierBridge\SupplierWorkflowProjection;
use App\Support\OrderDetailPresenter;
use App\Support\OrderTaskRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Only FlowTracker can decide whether a signed supplier task request is authorized. */
final class ReceiveSupplierTaskAction extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $baselineStart = \App\Support\Performance\OrderBaselineRecorder::enabled() ? microtime(true) : null;
        $baselineStatus = null;
        try {
            $response = $this->receiveSignedAction($request);
            $baselineStatus = $response->getStatusCode();
            return $response;
        } finally {
            if ($baselineStart !== null) {
                \App\Support\Performance\OrderBaselineRecorder::record('supplier_task_action_http', [
                    'status' => $baselineStatus,
                    'success' => $baselineStatus !== null && $baselineStatus >= 200 && $baselineStatus < 300,
                    'order_id' => $request->attributes->get('flowtrack_baseline_order_id'),
                    'duration_ms' => round((microtime(true) - $baselineStart) * 1000, 2),
                ]);
            }
        }
    }

    private function receiveSignedAction(Request $request): JsonResponse
    {
        $secret = (string) config('supplier_bridge.secret');
        $multipart = $request->isMethod('post') && str_starts_with((string) $request->header('Content-Type'), 'multipart/form-data');
        // The file bytes are streamed by PHP into a temporary file. The digest is
        // signed together with the exact metadata bytes and verified before use.
        $body = $multipart ? $request->input('data') : $request->getContent();
        $time = $request->header('X-Supplier-Timestamp');
        $event = $request->header('X-Supplier-Event');
        $signature = $request->header('X-Supplier-Signature');
        $digestHeader = $request->header('X-Supplier-Digests');
        $files = $multipart ? $request->file('files', []) : [];
        $files = is_array($files) ? array_values($files) : [];
        abort_unless(is_string($body) && strlen($body) <= 65536 && strlen($body) > 0, 401);
        abort_unless(config('supplier_bridge.enabled') && strlen($secret) >= 32
            && is_string($time) && ctype_digit($time) && abs(time() - (int) $time) <= 300
            && is_string($event) && preg_match('/^[0-9a-f-]{36}$/i', $event)
            && is_string($signature) && preg_match('/^[0-9a-f]{64}$/i', $signature), 401);
        if ($multipart) {
            abort_unless(count($files) >= 1 && count($files) <= 5
                && is_string($digestHeader) && preg_match('/^[a-f0-9]{64}(,[a-f0-9]{64}){0,4}$/', $digestHeader), 401);
            $expectedDigests = explode(',', $digestHeader);
            abort_unless(count($expectedDigests) === count($files), 401);
        } else {
            abort_unless($digestHeader === null || $digestHeader === '', 401);
        }
        $signedContent = $time."\n".$event."\n".$body.($multipart ? "\n".$digestHeader : '');
        abort_unless(hash_equals(hash_hmac('sha256', $signedContent, $secret), $signature), 401);
        // Verify the HMAC before hashing potentially large uploads. Reject
        // tampered bytes without storing a document or advancing a task.
        foreach ($files as $i => $file) {
            abort_unless($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()
                && hash_equals($expectedDigests[$i], hash_file('sha256', $file->getRealPath())), 422);
        }

        $data = json_decode($body, true);
        $validated = Validator::make($data ?? [], [
            'event_id' => ['required', 'uuid', 'in:'.$event],
            'request_uuid' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'order_id' => ['required', 'integer', 'min:1'],
            'task_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'integer', 'min:1'],
            'user_name' => ['required', 'string', 'max:120'],
            'decision' => ['required', 'string', 'max:30'],
            'expected_status' => ['required', 'string', 'max:100'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'payload' => ['array'],
            'payload.estimated_delivery_date' => ['sometimes', 'date_format:Y-m-d'],
            'payload.supplier_delivery_date' => ['sometimes', 'date_format:Y-m-d'],
            'payload.shipment_date' => ['sometimes', 'date_format:Y-m-d'],
            'payload.qty_received' => ['sometimes', 'integer', 'min:0'],
            'payload.qty_inspected' => ['sometimes', 'integer', 'min:0'],
            'payload.qty_accepted' => ['sometimes', 'integer', 'min:0'],
            'payload.qty_rejected' => ['sometimes', 'integer', 'min:0'],
            'payload.issue_category' => ['sometimes', 'string', 'max:100'],
            'payload.erp_reference' => ['sometimes', 'string', 'max:150'],
            'payload.carrier' => ['sometimes', 'string', 'max:150'],
            'payload.tracking_number' => ['sometimes', 'string', 'max:150'],
            'payload.client_name' => ['sometimes', 'string', 'max:255'],
            'payload.contact_name' => ['sometimes', 'string', 'max:255'],
            'payload.phone_country_code' => ['sometimes', 'string', 'max:50'],
            'payload.phone_number' => ['sometimes', 'string', 'max:70'],
            'payload.address' => ['sometimes', 'string', 'max:500'],
            'payload.city' => ['sometimes', 'string', 'max:150'],
            'payload.state' => ['sometimes', 'string', 'max:150'],
            'payload.country' => ['sometimes', 'string', 'max:150'],
            'payload.postal_code' => ['sometimes', 'string', 'max:40'],
            'payload.file_source_ids' => ['sometimes', 'array', 'max:5'],
            'payload.file_source_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'payload.revision_document_ids' => ['sometimes', 'array', 'max:20'],
            'payload.revision_document_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'payload.cancel_artwork_document_ids' => ['sometimes', 'array', 'max:20'],
            'payload.cancel_artwork_document_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'payload.revision_items' => ['sometimes', 'array', 'max:20'],
            'payload.revision_items.*.document_id' => ['required', 'integer', 'min:1'],
            'payload.revision_items.*.comment' => ['required', 'string', 'max:2000'],
        ])->validate();
        // Supplier principals must never choose or overwrite buyer scheduling dates.
        // This remains enforced even for an old/stale signed portal projection.
        foreach (['estimated_delivery_date', 'supplier_delivery_date', 'shipment_date'] as $dateField) {
            abort_if(array_key_exists($dateField, $validated['payload'] ?? []), 403);
        }
        $supplierId = (int) $validated['supplier_id'];
        $orderId = (int) $validated['order_id'];
        $taskId = (int) $validated['task_id'];
        if (\App\Support\Performance\OrderBaselineRecorder::enabled()) {
            $request->attributes->set('flowtrack_baseline_order_id', $orderId);
        }
        // Check the live order-product supplier relationship, never a cached browser role.
        $links = DB::table('flow_job_items as i')
            ->join('master_records as s', 's.id', '=', 'i.supplier_id')
            ->join('master_records as p', 'p.id', '=', 'i.catalog_product_id')
            ->leftJoin('product_supplier_links as l', function ($join): void {
                $join->on('l.product_id', '=', 'p.id')->on('l.supplier_id', '=', 's.id')
                    ->on('l.workspace_id', '=', 'p.workspace_id');
            })
            ->where('i.flow_job_id', $orderId)->where('i.supplier_id', $supplierId)
            ->where('i.is_removed', false)->where('i.quantity', '>', 0)
            ->where('p.type', 'product')->whereNull('p.deleted_at')
            ->where('s.type', 'supplier')->where('s.status', 'active')
            ->whereNull('s.deleted_at')->whereColumn('p.workspace_id', 's.workspace_id')
            ->limit(101)->get(['l.id as linked_id', 'p.metadata']);
        $eligible = $links->contains(static function ($row) use ($supplierId): bool {
            if ($row->linked_id) return true;
            $product = new \App\Models\MasterRecord;
            $product->setRawAttributes(['type' => 'product', 'metadata' => $row->metadata]);
            return $product->hasProductSupplier($supplierId);
        });
        abort_unless($eligible, 403);

        $storedPaths = [];
        try {
            return DB::transaction(function () use ($validated, $supplierId, $orderId, $taskId, $files, $multipart, &$storedPaths): JsonResponse {
            // Match OrderWorkflowActionService's task-before-order lock order to avoid deadlocks.
            $task = Task::query()->where('flow_job_id', $orderId)->whereKey($taskId)
                ->lockForUpdate()->with('setupTemplate')->firstOrFail();
            $job = FlowJob::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            abort_if($job->trashed() || in_array(strtolower((string) $job->status), ['draft', 'cancelled'], true), 409);
            $assignment = SupplierTaskAssignment::query()->where('task_id', $taskId)
                ->where('flow_job_id', $orderId)->where('supplier_id', $supplierId)->first();
            abort_unless($assignment, 403);
            if (DB::table('supplier_task_action_receipts')->where('request_uuid', $validated['request_uuid'])->exists()) {
                return response()->json(['received' => true, 'duplicate' => true]);
            }
            abort_unless(hash_equals((string) ($task->status ?: 'Ready'), $validated['expected_status']), 409);
            $job->loadMissing(['phase', 'workflow.phases.taskPack.items', 'tasks.setupTemplate']);
            abort_unless(SupplierWorkflowProjection::taskMode($job, $task) === 'active', 409);
            $key = SupplierWorkflowProjection::actionKey(
                OrderTaskRequirement::automationKey($task), (int) ($job->phase?->sequence ?? 0));
            abort_unless($key !== null && $key !== 'PROD_SET_ESTIMATED_DELIVERY', 403);
            $decision = $validated['decision'];
            // The ERP upload and the client's later decision are separate
            // canonical transitions; do not accept a shortcut across them.
            abort_unless(in_array($decision,
                SupplierWorkflowProjection::decisionsForStatus($key, (string) $task->status), true), 409);
            abort_unless($multipart === (count($files) > 0), 422);
            $isDocumentTask = in_array($key, SupplierWorkflowProjection::DOCUMENT_KEYS, true);
            abort_unless(($isDocumentTask && $multipart && $decision === 'upload')
                || (! $isDocumentTask && $decision !== 'upload'), 422);
            $actor = User::query()->where('is_active', true)->findOrFail($assignment->assigned_by);
            $access = app(AccessControlService::class);
            abort_unless($access->canEditTask($actor, $task), 403);

            if ($multipart) {
                abort_unless($access->can($actor, 'documents', 'create'), 403);
                // Same document formats and limits as internal FlowTracker uploads.
                $maxKb = in_array($key, ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true)
                    ? AttachmentUpload::ARTWORK_MAX_KILOBYTES : AttachmentUpload::STANDARD_MAX_KILOBYTES;
                Validator::make(['files' => $files], [
                    'files' => ['required', 'array', 'min:1', 'max:5'],
                    'files.*' => AttachmentUpload::itemRules(AttachmentUpload::BUSINESS_DOCUMENTS, $maxKb),
                ])->validate();
                $documentService = app(DocumentService::class);
                $task->loadMissing(['job', 'documentCategory', 'setupTemplate.documentCategory']);
                $data = ['flow_job_id' => $orderId, 'task_id' => $taskId,
                    'client_id' => $task->job?->client_id,
                    'note' => 'Uploaded from Supplier Portal by '.mb_substr((string) $validated['user_name'], 0, 120)];
                if ($documentService->taskHasRequirement($task)) {
                    $data['require_task_pack_requirement'] = true;
                } else {
                    $data['category'] = 'Task attachment';
                }
                if ($key === 'ART_PREPARE_UPLOAD'
                    && ($documentService->pendingArtworkRevision($task)['active'] ?? false)) {
                    // Reuse the existing selective replacement service. A digest-
                    // signed source ID must match each current revision target.
                    $sourceIds = $validated['payload']['file_source_ids'] ?? [];
                    abort_unless(count($sourceIds) === count($files), 422);
                    $replacementFiles = [];
                    foreach ($files as $i => $file) {
                        $replacementFiles[(int) $sourceIds[$i]] = $file;
                    }
                    abort_unless(count($replacementFiles) === count($files), 422);
                    $documents = $documentService->storeArtworkRevision(
                        $replacementFiles, $task, $actor, $data['note'], 'documents');
                } else {
                    abort_unless(empty($validated['payload']['file_source_ids']), 422);
                    $documents = $documentService->storeMany($files, $data, $actor, 'documents');
                }
                $storedPaths = $documents->pluck('path')->filter()->all();
            }
            $actionPayload = $validated['payload'] ?? [];
            if ($key === 'SHIP_PACKAGE') {
                // Shipment date is the verified server-side dispatch date, not
                // a value supplied by the supplier. The planned supplier ship
                // date stored on the order is not modified by dispatch.
                $actionPayload['shipment_date'] = app(WorkspaceSettingsService::class)->localToday()->toDateString();
            }
            $changed = $isDocumentTask
                ? $this->completeUploadedTask($task, $actor)
                : app(OrderWorkflowActionService::class)->perform(
                    $task, $actor, $decision, $validated['comment'] ?? '', $actionPayload,
                );
            // Immutable audit identifying the actual supplier principal (not the FlowTracker delegate).
            $job->activities()->create([
                'user_id' => $actor->id, 'event' => 'supplier.workflow_task_action',
                'description' => 'Supplier '.mb_substr($validated['user_name'], 0, 120).' performed '
                    .$decision.' on task '.mb_substr((string) $task->title, 0, 120).'.',
                'meta' => ['supplier_id' => $supplierId, 'supplier_user_id' => (int) $validated['user_id'],
                    'task_id' => $taskId, 'decision' => $decision,
                    'uploaded_files' => count($files),
                    'request_uuid' => $validated['request_uuid']],
            ]);
            DB::table('supplier_task_action_receipts')->insert([
                'request_uuid' => $validated['request_uuid'], 'task_id' => $taskId,
                'supplier_id' => $supplierId, 'created_at' => now(),
            ]);
            app(\App\Services\SupplierBridge\QueueChangedOrder::class)->mark($orderId);
            return response()->json(['received' => true, 'task_status' => $changed->status]);
            }, $multipart ? 1 : 3);
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                try { app(SecureDocumentStorage::class)->delete($path); }
                catch (\Throwable $cleanup) { report($cleanup); }
            }
            throw $exception;
        }
    }

    private function completeUploadedTask(Task $task, User $actor): Task
    {
        app(OrderWorkflowActionService::class)->afterDocumentAdded($task, $actor);
        return $task->refresh();
    }
}
