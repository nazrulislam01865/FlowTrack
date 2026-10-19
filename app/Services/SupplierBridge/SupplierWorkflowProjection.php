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
        'ART_CLIENT_ERP_DECISION', 'ART_SAMPLE_APPROVAL',
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

    public const DOCUMENT_KEYS = ['NEW_UPLOAD_PO', 'ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'];

    public static function decisions(string $key): array
    {
        return match ($key) {
            'NEW_UPLOAD_PO', 'ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL' => ['upload'],
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
        $mode = OrderDetailPresenter::taskMode($order, $task);
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

    public function build(FlowJob $order, int $supplierId): array
    {
        $order->loadMissing([
            'workflow.phases.taskPack.items', 'tasks.setupTemplate', 'tasks.assignee:id,name',
            'tasks.documents:id,task_id', 'tasks.links:id,task_id',
            'phase:id,name,short_name,sequence',
        ]);
        $assigned = SupplierTaskAssignment::query()->where('flow_job_id', $order->id)
            ->where('supplier_id', $supplierId)->pluck('task_id')->flip();
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
                $mode = self::taskMode($order, $task);
                $key = self::actionKey(OrderTaskRequirement::automationKey($task), (int) $phase->sequence);
                $isAssigned = $assigned->has((int) $task->id);
                // Scheduling dates are buyer-managed: an assigned supplier can view this
                // task but cannot set an estimated delivery date through the portal.
                $canAct = $isAssigned && $mode === 'active' && $key !== null
                    && $key !== 'PROD_SET_ESTIMATED_DELIVERY';
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
                    'title' => mb_substr((string) $task->title, 0, 255),
                    'status' => mb_substr((string) ($task->status ?: 'Ready'), 0, 100),
                    'mode' => $mode, 'required' => OrderTaskRequirement::isRequired($task),
                    'assignee' => $key === 'PROD_SET_ESTIMATED_DELIVERY'
                        ? 'FlowTracker team'
                        : ($isAssigned ? 'Your company' : ($task->assignee?->name ?: 'Unassigned')),
                    'assigned_to_supplier' => $isAssigned,
                    'due_date' => $task->due_date?->format('Y-m-d'),
                    'can_act' => $canAct,
                    'date_controlled_by_flowtracker' => $key === 'PROD_SET_ESTIMATED_DELIVERY',
                    'action_key' => $canAct ? $key : null,
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
