<?php

namespace App\Services\SupplierBridge;

use App\Models\FlowJob;
use App\Models\SupplierTaskAssignment;
use App\Services\DocumentService;
use App\Support\OrderDetailPresenter;
use App\Support\OrderTaskRequirement;
use Illuminate\Support\Facades\DB;

/** Read the saved seven-stage Order workflow in bounded queries, never from hard-coded task names. */
final class SupplierWorkflowProjection
{
    // Only supplier-visible workflow stages (New Order through Shipment).
    // All decisions still execute through FlowTracker's canonical action service.
    public const ACTION_KEYS = [
        'NEW_UPLOAD_PO', 'NEW_SEND_PO_ARTWORK',
        'ART_PREPARE_UPLOAD', 'ART_INTERNAL_REVIEW', 'ART_SEND_ORDER_TEAM',
        'ART_CLIENT_ERP_DECISION', 'ART_SAMPLE_APPROVAL', 'ART_SUPPLIER_PO_OPTIONAL',
        'PROD_SET_ESTIMATED_DELIVERY', 'PROD_START', 'PROD_ISSUE', 'PROD_FINISH',
        'QC_CHECK', 'QC_ISSUE', 'QC_APPROVE_SHIPMENT',
        'SHIP_CONFIRM_INFO', 'SHIP_LABEL', 'SHIP_PACKAGE', 'GENERIC_TASK',
    ];

    public static function actionKey(?string $key, int $phaseSequence): ?string
    {
        if ($phaseSequence < 1 || $phaseSequence > 5) return null;
        $key = trim((string) $key);
        if ($key === '') $key = 'GENERIC_TASK';
        return in_array($key, self::ACTION_KEYS, true) ? $key : null;
    }

    public const DOCUMENT_KEYS = ['NEW_UPLOAD_PO', 'ART_PREPARE_UPLOAD'];

    public static function decisions(string $key): array
    {
        return match ($key) {
            'NEW_UPLOAD_PO', 'ART_PREPARE_UPLOAD' => ['upload'],
            'ART_SAMPLE_APPROVAL' => ['sample_prepared'],
            'ART_SUPPLIER_PO_OPTIONAL' => ['skip'],
            'ART_INTERNAL_REVIEW' => ['confirm', 'revise', 'cancel_artwork'],
            'ART_CLIENT_ERP_DECISION' => ['erp_uploaded', 'confirm', 'sample', 'revise'],
            'QC_CHECK' => ['pass', 'issue'],
            'QC_ISSUE' => ['resolve', 'issue'],
            'PROD_ISSUE' => ['confirm', 'issue', 'resolve'],
            'SHIP_LABEL' => ['complete'],
            default => in_array($key, self::ACTION_KEYS, true) ? ['confirm'] : [],
        };
    }


    /** Allowed decisions depend on the actual artwork approval state. */
    public static function decisionsForStatus(string $key, string $status): array
    {
        if ($key === 'ART_SAMPLE_APPROVAL') {
            return in_array($status, ['Sample: Supplier Preparing', 'Sample: Supplier Revision'], true)
                ? ['sample_prepared'] : [];
        }
        if ($key !== 'ART_CLIENT_ERP_DECISION') return self::decisions($key);

        $status = strtolower(trim($status));
        // A client-decision task has two distinct steps. Once the ERP upload is
        // confirmed, the supplier must record a client decision, not upload to
        // ERP again. A pending sample decision belongs to the sample task.
        return match ($status) {
            // Older/imported orders sometimes leave the first uncompleted
            // required task at Not Started. It is actionable ONLY if taskMode
            // separately confirms it is the first eligible task in this phase.
            'ready', 'not started', 'not start' => ['erp_uploaded'],
            'waiting for client', 'client approval' => ['confirm', 'sample', 'revise'],
            default => [],
        };
    }

    /**
     * Use the same stage and task gates as Order Details. For imported/rebound
     * orders, the presenter can still select an obsolete predecessor while the
     * canonical Task Pack has already promoted its first open required task to
     * Ready. Only allow that first, explicitly Ready required task. Every
     * submitted action is checked again against the live order and the
     * canonical OrderTaskSequenceService before any write.
     *
     * This is a collection-only fallback: no additional per-task DB queries.
     */
    public static function taskMode(FlowJob $order, \App\Models\Task $task): string
    {
        // An explicitly reported QC issue is the one sanctioned exception to
        // sequential required-task gating. Resolve from loaded task state only.
        if (self::activeQcResolution($order, $task)) return 'active';

        // The client ERP task deliberately stays incomplete while the single
        // conditional sample task is handed to the supplier. The normal
        // first-open-required-task gate can therefore report this sample as
        // locked in the supplier projection even though FlowTracker has sent
        // the request. Permit only the supplier's two preparation states,
        // with the client decision explicitly waiting on this sample and all
        // other required predecessors complete. This identical guard is used
        // by the signed buyer endpoint; it is not a portal-only UI override.
        if (OrderTaskRequirement::automationKey($task) === 'ART_SAMPLE_APPROVAL'
            && in_array(\App\Services\Orders\SampleWorkflowService::state($task), ['supplier_prepare', 'supplier_revision'], true)
            && ! OrderDetailPresenter::isCompletedTask($task)
            && ! $order->completed_at
            && (int) $task->workflow_phase_id === (int) $order->workflow_phase_id
            && ! in_array(strtolower(trim((string) $order->status)), ['draft', 'cancelled', 'completed'], true)
            && $order->relationLoaded('tasks')) {
            $phaseTasks = $order->tasks->filter(fn ($candidate) =>
                (int) $candidate->workflow_phase_id === (int) $task->workflow_phase_id);
            $clientWaiting = $phaseTasks->contains(fn ($candidate) =>
                OrderTaskRequirement::automationKey($candidate) === 'ART_CLIENT_ERP_DECISION'
                && strcasecmp(trim((string) $candidate->status), 'Waiting for Sample Approval') === 0
                && ! OrderDetailPresenter::isCompletedTask($candidate));
            $predecessorsComplete = $phaseTasks->every(fn ($candidate) =>
                (int) $candidate->id === (int) $task->id
                || ! OrderTaskRequirement::isRequired($candidate)
                || OrderTaskRequirement::automationKey($candidate) === 'ART_CLIENT_ERP_DECISION'
                || OrderDetailPresenter::isCompletedTask($candidate)
                || OrderDetailPresenter::isSkippedTask($candidate));
            if ($clientWaiting && $predecessorsComplete) return 'active';
        }

        $mode = OrderDetailPresenter::taskMode($order, $task);
        if (OrderTaskRequirement::automationKey($task) === 'ART_SUPPLIER_PO_OPTIONAL'
            && (int) $task->workflow_phase_id === (int) $order->workflow_phase_id
            && $order->relationLoaded('tasks') && ! $order->completed_at
            && ! (bool) ($order->active_hold_exists ?? false)
            && ! in_array(strtolower(trim((string) $order->status)), ['draft', 'cancelled', 'completed'], true)
            && ! OrderDetailPresenter::isCompletedTask($task)
            && ! OrderDetailPresenter::isSkippedTask($task)
            && ! $order->tasks->where('workflow_phase_id', $task->workflow_phase_id)
                ->contains(fn ($other) => OrderTaskRequirement::isRequired($other)
                    && ! OrderDetailPresenter::isCompletedTask($other)
                    && ! OrderDetailPresenter::isSkippedTask($other))) {
            return 'active';
        }
        // Receipt, client decision and onward shipment belong to Main ERP.
        // The supplier may inspect the same row but must never act in these states.
        if (OrderTaskRequirement::automationKey($task) === 'ART_SAMPLE_APPROVAL'
            && ! in_array(\App\Services\Orders\SampleWorkflowService::state($task), ['supplier_prepare', 'supplier_revision'], true)
            && ! OrderDetailPresenter::isCompletedTask($task)) {
            return 'locked';
        }
        $status = strtolower(trim((string) $task->status));
        $key = OrderTaskRequirement::automationKey($task);
        $waitingForClientDecision = $key === 'ART_CLIENT_ERP_DECISION'
            && in_array($status, ['waiting for client', 'client approval'], true);
        $unstartedFirstTask = in_array($status, ['not started', 'not start'], true);

        // After the client opts for a sample, the client-decision task stays
        // incomplete only as a stage blocker. Its own action must not reopen;
        // the conditional Sample Approval task is responsible for the next step.
        if ($key === 'ART_CLIENT_ERP_DECISION'
            && ! OrderDetailPresenter::isCompletedTask($task)
            && ! in_array($status, ['ready', 'not started', 'not start', 'waiting for client', 'client approval'], true)) {
            return 'locked';
        }

        if ($mode !== 'locked'
            || (int) $task->workflow_phase_id !== (int) $order->workflow_phase_id
            || ($status !== 'ready' && ! $unstartedFirstTask && ! $waitingForClientDecision)
            || ! $task->task_pack_task_id
            || ! OrderTaskRequirement::isRequired($task)
            || in_array(strtolower((string) $order->status), ['draft', 'cancelled', 'completed'], true)
            || $order->completed_at !== null) {
            return $mode;
        }

        $phase = $order->workflow?->phases?->firstWhere('id', (int) $task->workflow_phase_id);
        if (! $phase || (int) $phase->sequence > 5 || (int) $phase->sequence < 1) return $mode;

        // Match OrderTaskSequenceService::requiredGeneratedTasks: all generated
        // tasks for the phase ordered by Task Pack sequence, not a subset
        // filtered for the UI. This keeps the signed action endpoint in sync
        // with the same backend rule when it validates the submission.
        $firstOpen = $order->tasks
            ->filter(fn ($item) => (int) $item->workflow_phase_id === (int) $phase->id
                && $item->task_pack_task_id && OrderTaskRequirement::isRequired($item))
            ->sortBy(fn ($item) => [OrderTaskRequirement::sequence($item), (int) $item->id])
            ->first(fn ($item) => ! OrderDetailPresenter::isCompletedTask($item));

        return $firstOpen && (int) $firstOpen->id === (int) $task->id ? 'active' : $mode;
    }

    /** Pure, query-free guard for the QC exception to normal task order. */
    public static function activeQcResolution(FlowJob $order, \App\Models\Task $task): bool
    {
        if (OrderTaskRequirement::automationKey($task) !== 'QC_ISSUE'
            || strcasecmp(trim((string) $task->status), 'QC Issue Reported') !== 0
            || $task->completed_at !== null
            || (int) $task->workflow_phase_id !== (int) $order->workflow_phase_id
            || $order->completed_at !== null
            || in_array(strtolower(trim((string) $order->status)), ['draft', 'cancelled', 'completed'], true)
            || ! $order->relationLoaded('tasks')) {
            return false;
        }

        // Never unlock a standalone issue task: the failed check must be in
        // FlowTracker's explicit waiting state in this same order and stage.
        return $order->tasks->contains(static fn ($candidate): bool =>
            (int) $candidate->workflow_phase_id === (int) $task->workflow_phase_id
            && OrderTaskRequirement::automationKey($candidate) === 'QC_CHECK'
            && strcasecmp(trim((string) $candidate->status), 'Waiting for QC Issue Resolution') === 0
            && $candidate->completed_at === null
        );
    }

    public function build(FlowJob $order, int $supplierId): array
    {
        $order->loadMissing([
            'workflow.phases.taskPack.items', 'tasks.setupTemplate', 'tasks.assignee:id,name',
            'tasks.documents:id,task_id,name,size', 'tasks.links:id,task_id',
            'phase:id,name,short_name,sequence',
        ]);
        $assigned = SupplierTaskAssignment::query()->where('flow_job_id', $order->id)
            ->where('supplier_id', $supplierId)->pluck('task_id')->flip();
        $sampleTask = $order->tasks->first(fn ($item) =>
            OrderTaskRequirement::automationKey($item) === 'ART_SAMPLE_APPROVAL'
            && \App\Support\OrderDetailPresenter::isConditionalTaskActivated($item));
        $sampleSnapshot = $sampleTask && $assigned->has((int) $sampleTask->id)
            ? app(\App\Services\Orders\SampleWorkflowService::class)->snapshot($order, $sampleTask) : [];
        $phases = OrderDetailPresenter::phases($order);
        // Resolve current artwork evidence only for the supplier actually
        // assigned an active artwork review/upload task. No new page-wide query.
        $artworkDocuments = null;
        $artworkRevision = null;
        $artworkUpload = $order->tasks->first(fn ($task) =>
            OrderTaskRequirement::automationKey($task) === 'ART_PREPARE_UPLOAD');
        $data = [];
        foreach ($phases as $phase) {
            $state = OrderDetailPresenter::phaseState($order, $phase);
            $tasks = OrderDetailPresenter::displayTasksForPhase($order, $phase);
            $required = $tasks->filter(fn ($task) => OrderTaskRequirement::isRequired($task));
            $phaseRows = [];
            foreach ($tasks as $i => $task) {
                $isSample = OrderTaskRequirement::automationKey($task) === 'ART_SAMPLE_APPROVAL';
                // Do not show the supplier a request which FlowTracker has not sent.
                if ($isSample && \App\Services\Orders\SampleWorkflowService::state($task) === 'request') continue;
                $mode = self::taskMode($order, $task);
                $key = self::actionKey(OrderTaskRequirement::automationKey($task), (int) $phase->sequence);
                $clientSampleDecided = $key === 'ART_CLIENT_ERP_DECISION'
                    && strcasecmp(trim((string) $task->status), 'Waiting for Sample Approval') === 0;
                $isAssigned = $assigned->has((int) $task->id);
                $supplierSample = $isSample && $isAssigned ? $sampleSnapshot : [];
                $sampleDisplay = $isSample ? \App\Services\Orders\SampleWorkflowService::supplierPresentation($task, $supplierSample) : null;
                $isQcDocumentTask = $isAssigned && in_array($key, ['QC_CHECK', 'QC_ISSUE'], true);
                // A QC attachment is independent of task completion, just like
                // FlowTracker's QC Document link. No new queries: task documents
                // were eager-loaded for the entire Order in one bounded select.
                $canAttachQc = $isQcDocumentTask
                    && (int) $task->workflow_phase_id === (int) $order->workflow_phase_id
                    && ! $order->completed_at
                    && ! in_array(strtolower((string) $order->status), ['draft', 'cancelled', 'completed'], true);
                // Scheduling dates are buyer-managed: an assigned supplier can view this
                // task but cannot set an estimated delivery date through the portal.
                $canAct = $isAssigned && $mode === 'active' && $key !== null
                    && ! in_array($key, ['PROD_SET_ESTIMATED_DELIVERY', 'ART_CLIENT_ERP_DECISION'], true)
                    && (! $isSample || in_array(\App\Services\Orders\SampleWorkflowService::state($task),
                        ['supplier_prepare', 'supplier_revision'], true));
                $reviewArtwork = [];
                $replaceArtwork = [];
                if ($canAct && $artworkUpload && in_array($key, ['ART_INTERNAL_REVIEW', 'ART_CLIENT_ERP_DECISION', 'ART_PREPARE_UPLOAD'], true)) {
                    $documents = app(DocumentService::class);
                    if ($key === 'ART_PREPARE_UPLOAD') {
                        $artworkRevision ??= $documents->pendingArtworkRevision($artworkUpload);
                        if ($artworkRevision['active'] ?? false) {
                            $replaceArtwork = collect($artworkRevision['documents'] ?? [])->take(20)
                                ->map(fn ($file) => ['id' => (int) $file->id,
                                    'name' => mb_substr((string) $file->name, 0, 255)])->values()->all();
                        }
                    } else {
                        $artworkDocuments ??= $documents->currentArtworkDocuments($artworkUpload);
                        $reviewArtwork = $artworkDocuments->take(20)
                            ->map(fn ($file) => ['id' => (int) $file->id,
                                'name' => mb_substr((string) $file->name, 0, 255)])->values()->all();
                    }
                }
                $phaseRows[] = [
                    'id' => (int) $task->id,
                    'code' => mb_substr(OrderDetailPresenter::taskDisplayCode($phase, $task, $i), 0, 40),
                    'title' => $sampleDisplay['title'] ?? mb_substr((string) $task->title, 0, 255),
                    'status' => mb_substr((string) ($task->status ?: 'Ready'), 0, 100),
                    'display_status' => $clientSampleDecided ? 'Completed' : ($sampleDisplay['status'] ?? null),
                    'sample' => $supplierSample,
                    'sample_description' => $sampleDisplay['description'] ?? null,
                    'mode' => $clientSampleDecided ? 'done' : $mode, 'required' => OrderTaskRequirement::isRequired($task),
                    'assignee' => $key === 'PROD_SET_ESTIMATED_DELIVERY'
                        ? 'FlowTracker team'
                        : ($isAssigned ? 'Your company' : ($task->assignee?->name ?: 'Unassigned')),
                    'assigned_to_supplier' => $isAssigned,
                    'due_date' => $task->due_date?->format('Y-m-d'),
                    'can_act' => $canAct,
                    'date_controlled_by_flowtracker' => $key === 'PROD_SET_ESTIMATED_DELIVERY',
                    'action_key' => $canAct ? $key : null,
                    'document_action_key' => $isQcDocumentTask ? $key : null,
                    'can_attach' => $canAttachQc,
                    'document_files' => $isQcDocumentTask ? $task->documents->take(30)
                        ->map(fn ($doc) => [
                            'id' => (int) $doc->id,
                            'name' => mb_substr(basename(str_replace('\\', '/', (string) $doc->name)), 0, 255),
                            'size' => (int) $doc->size,
                        ])->values()->all() : [],
                    'artwork_files' => $reviewArtwork, 'revision_sources' => $replaceArtwork,
                ];
            }
            $data[] = [
                'id' => (int) $phase->id,
                'sequence' => (int) $phase->sequence,
                'name' => mb_substr((string) $phase->name, 0, 100),
                'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) $phase->color) ? $phase->color : '#94a3b8',
                'state' => $state,
                'state_label' => OrderDetailPresenter::phaseStateLabel($order, $phase),
                'dependency' => OrderDetailPresenter::phaseDependencyLabel($order, $phase),
                'owner' => $phaseRows[0]['assignee'] ?? 'Unassigned',
                'progress' => OrderDetailPresenter::phaseProgress($order, $phase),
                'required_complete' => OrderDetailPresenter::completedCount($required),
                'required_total' => $required->count(),
                'tasks' => $phaseRows,
            ];
        }
        return ['stages' => $data, 'current_phase_id' => (int) $order->workflow_phase_id];
    }
}
