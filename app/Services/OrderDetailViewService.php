<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\User;
use App\Support\JobDetailPresenter;
use App\Support\OrderDetailPresenter;
use App\Support\CreateOrderShippingMethodPresenter;
use Illuminate\Support\Collection;

/**
 * Builds the already-authorized, query-free view context for Order Details.
 * Database hydration remains in JobService; Blade receives only presentation
 * data and loaded model relations.
 */
class OrderDetailViewService
{
    /**
     * Build only the context required by the always-visible Order Details shell.
     *
     * Workflow task descriptors, task permissions, email delivery state,
     * prepared invoices and production-monitor metadata intentionally stay out
     * of this method. Those values now belong to the isolated Workflow child
     * component, so the initial GET does not pay their CPU/hydration cost.
     */
    public function buildSummary(FlowJob $job, User $user, Collection $shipmentUrgencyOptions, ?Collection $shipmentMethodOptions = null): array
    {
        $access = app(AccessControlService::class);
        $canEdit = $access->canEditVisibleJob($user, $job);
        $inactive = (bool) $job->completed_at
            || $job->status === 'Completed'
            || in_array((string) $job->status, JobService::INACTIVE_STATUSES, true);
        $activeHold = $job->relationLoaded('activeHold') ? $job->activeHold : null;
        $activeHoldSourceName = $activeHold
            ? trim((string) ($activeHold->source_name
                ?: ((string) $activeHold->hold_from === \App\Models\OrderHold::FROM_CLIENT
                    ? ($job->client?->contact_name ?: $job->client?->name ?: 'Client')
                    : ($activeHold->holder?->name ?: $activeHold->holdFromLabel()))))
            : '';

        $shipmentUrgencyName = OrderDetailPresenter::shipmentUrgencyName($job, $shipmentUrgencyOptions);
        $shipmentMethodOptions ??= collect();
        $shippingState = CreateOrderShippingMethodPresenter::orderShippingState(
            $shipmentMethodOptions,
            $shipmentUrgencyOptions,
            (array) ($job->shipment_method_ids ?? []),
            (array) ($job->shipment_urgency_ids ?? []),
        );
        $shippingOptions = CreateOrderShippingMethodPresenter::orderShippingOptions($shipmentMethodOptions, $shipmentUrgencyOptions);
        $masterData = app(MasterDataService::class);
        $remoteArea = $masterData->remoteAreaForPostalCode($job->shipping_postal_code);

        return [
            'team' => $this->summaryTeam($job),
            'canEditJob' => $canEdit,
            'canChangeOwner' => $access->isAdministrator($user),
            'canComment' => $canEdit,
            'canCancel' => $canEdit && ! $inactive && (int) ($job->phase?->sequence ?? 999) <= 4,
            'canHold' => $canEdit && ! $inactive && ! $activeHold,
            'canReleaseHold' => $canEdit && ! $inactive && (bool) $activeHold,
            'isOnHold' => (bool) $activeHold,
            'hold' => $activeHold ? [
                'id' => (int) $activeHold->id,
                'holdFrom' => (string) $activeHold->hold_from,
                'holdFromLabel' => $activeHold->holdFromLabel(),
                'sourceName' => $activeHoldSourceName ?: '—',
                'reason' => (string) $activeHold->reason,
                'heldById' => $activeHold->held_by ? (int) $activeHold->held_by : null,
                'heldBy' => (string) ($activeHold->holder?->name ?: 'Unknown user'),
                'startedAt' => $activeHold->started_at,
            ] : null,
            'attentionLocked' => $inactive,
            'flagged' => (bool) ($job->attention_requested ?? false),
            'flagReason' => trim((string) ($job->attention_reason ?? '')),
            'orderFlagLabel' => (string) ($job->orderFlag?->name ?? ''),
            'shipmentUrgencyId' => OrderDetailPresenter::shipmentUrgencyId($job),
            'shipmentUrgencyName' => $shipmentUrgencyName,
            'shipmentUrgencyTone' => OrderDetailPresenter::urgencyTone($shipmentUrgencyName),
            // Canonical combined shipping state for the header/planning inline
            // control. Legacy urgency keys above remain for backward compatibility.
            'shipmentShippingValue' => $shippingState['value'],
            'shipmentShippingName' => $shippingState['name'],
            'shipmentShippingTone' => $shippingState['tone'],
            'shipmentShippingOptions' => $shippingOptions->all(),
            'remoteArea' => $remoteArea ? [
                'id' => (int) $remoteArea->id,
                'name' => trim((string) $remoteArea->name),
                'postal_code' => $masterData->normalizePostalCode((string) $job->shipping_postal_code),
                'location' => $remoteArea->remoteAreaLocationLabel(),
                'extra_charge' => $remoteArea->remoteAreaExtraCharge(),
            ] : null,
            // The shell never needs task-level workflow state. Keep stable empty
            // keys so existing Blade access remains backward-compatible.
            'taskPermissions' => [],
            'taskActions' => [],
            'taskActionModals' => [],
            'workflowEmailStatuses' => [],
            'workflowInvoices' => [],
            'productionMonitorDetails' => [],
            'courierOptions' => [],
        ];
    }

    /**
     * Build the header team from relations already loaded by findVisibleBase().
     * TaskService and workflow synchronization mirror task assignees into
     * FlowJobMember, so the lightweight shell does not need to touch Task::assignee.
     */
    private function summaryTeam(FlowJob $job): Collection
    {
        $members = $job->relationLoaded('members')
            ? $job->members->pluck('user')->filter()
            : collect();

        $base = collect([
            $job->relationLoaded('owner') ? $job->owner : null,
            $job->relationLoaded('coordinator') ? $job->coordinator : null,
        ])->filter();

        return $base->concat($members)->unique('id')->values();
    }

    public function build(FlowJob $job, User $user, Collection $shipmentUrgencyOptions, ?Collection $courierOptions = null): array
    {
        $access = app(AccessControlService::class);
        $courierOptions ??= collect();
        $canEdit = $access->canEditVisibleJob($user, $job);
        $inactive = (bool) $job->completed_at
            || $job->status === 'Completed'
            || in_array((string) $job->status, JobService::INACTIVE_STATUSES, true);
        $activeHold = $job->relationLoaded('activeHold') ? $job->activeHold : null;
        $activeHoldSourceName = $activeHold
            ? trim((string) ($activeHold->source_name
                ?: ((string) $activeHold->hold_from === \App\Models\OrderHold::FROM_CLIENT
                    ? ($job->client?->contact_name ?: $job->client?->name ?: 'Client')
                    : ($activeHold->holder?->name ?: $activeHold->holdFromLabel()))))
            : '';

        $shipmentUrgencyName = OrderDetailPresenter::shipmentUrgencyName($job, $shipmentUrgencyOptions);
        // Resolve the Remote Area once while building the detail context. Blade
        // remains query-free, and MasterDataService serves lookups from one cached
        // active collection instead of querying once per rendered field.
        $masterData = app(MasterDataService::class);
        $remoteArea = $masterData->remoteAreaForPostalCode($job->shipping_postal_code);
        $workflowActions = app(OrderWorkflowActionService::class);
        $documentTaskIds = $job->relationLoaded('documents')
            ? $job->documents->pluck('task_id')->filter()->map(fn ($id) => (int) $id)->flip()
            : collect();
        $taskActionDescriptors = $job->relationLoaded('tasks')
            ? $job->tasks->mapWithKeys(function ($task) use ($workflowActions, $documentTaskIds): array {
                $hasEvidence = $documentTaskIds->has((int) $task->id)
                    || ($task->relationLoaded('links') && $task->links->isNotEmpty());
                return [(int) $task->id => $workflowActions->descriptor($task, $hasEvidence)];
            })->all()
            : [];
        $taskActionModals = $job->relationLoaded('tasks')
            ? $job->tasks->mapWithKeys(fn ($task) => [(int) $task->id => $workflowActions->modalCopy($task)])->all()
            : [];

        $taskPermissions = $job->relationLoaded('tasks')
            ? $job->tasks->mapWithKeys(fn ($task) => [(int) $task->id => [
                'edit' => $access->canEditVisibleTask($user, $task, $job),
                'assign' => $access->canAssignVisibleTask($user, $task, $job),
                'delete' => $access->can($user, 'tasks', 'delete'),
            ]])->all()
            : [];

        $workflowEmailStatuses = [];
        if ($job->relationLoaded('tasks') && $job->relationLoaded('workflowEmailActivities')) {
            $emailService = app(\App\Services\Orders\OrderWorkflowEmailService::class);
            $invoiceEmailService = app(\App\Services\Orders\OrderInvoiceWorkflowEmailService::class);
            $workflowEmailStatuses = $job->tasks
                ->filter(fn ($task) => in_array($workflowActions->automationKey($task), ['ART_SEND_ORDER_TEAM', 'BILL_SEND'], true))
                ->mapWithKeys(function ($task) use ($job, $emailService, $invoiceEmailService, $workflowActions): array {
                    $task->setRelation('job', $job);
                    $status = $workflowActions->automationKey($task) === 'BILL_SEND'
                        ? $invoiceEmailService->deliveryStatus($task)
                        : $emailService->artworkHandoffDeliveryStatus($task);

                    return [(int) $task->id => $status];
                })
                ->all();
        }

        $productionMonitorDetails = [];
        if ($job->relationLoaded('tasks')) {
            $monitorActivity = $job->relationLoaded('latestProductionMonitorActivity')
                ? $job->latestProductionMonitorActivity
                : null;
            $monitorMeta = is_array($monitorActivity?->meta) ? $monitorActivity->meta : [];
            $monitorTaskId = (int) ($monitorMeta['task_id'] ?? 0);
            $monitorDate = trim((string) ($monitorMeta['supplier_delivery_date'] ?? ''));
            if ($monitorDate === '' && $job->supplier_delivery_date) {
                $monitorDate = $job->supplier_delivery_date->format('Y-m-d');
            }
            $monitorNote = trim(app(RichTextService::class)->plainText((string) ($monitorMeta['production_issue_note'] ?? '')));

            foreach ($job->tasks as $task) {
                if ($workflowActions->automationKey($task) !== 'PROD_ISSUE') {
                    continue;
                }

                // Older completed orders may not have task_id in the activity
                // metadata. A PROD_ISSUE task is unique in the standard Task
                // Pack, so that legacy activity can safely hydrate this row.
                $matchesActivity = ! $monitorActivity || $monitorTaskId === 0 || $monitorTaskId === (int) $task->id;
                $productionMonitorDetails[(int) $task->id] = [
                    'supplierDeliveryDate' => $matchesActivity ? $monitorDate : ($job->supplier_delivery_date?->format('Y-m-d') ?? ''),
                    'productionIssueNote' => $matchesActivity ? $monitorNote : '',
                    'savedAt' => $matchesActivity ? $monitorActivity?->created_at : null,
                ];
            }
        }

        $workflowInvoices = [];
        $hasPreparedWorkflowInvoice = $job->relationLoaded('workflowInvoiceActivities')
            && $job->workflowInvoiceActivities->isNotEmpty();
        if ($access->can($user, 'finance', 'view')
            && $hasPreparedWorkflowInvoice
            && $job->relationLoaded('tasks')
            && $job->relationLoaded('invoices')) {
            $preparedInvoice = app(\App\Services\Orders\OrderInvoiceWorkflowEmailService::class)
                ->preparedInvoice($job);

            if ($preparedInvoice) {
                $workflowInvoices = $job->tasks
                    ->filter(fn ($task) => $workflowActions->automationKey($task) === 'BILL_PREPARE')
                    ->mapWithKeys(fn ($task) => [(int) $task->id => [
                        'id' => (int) $preparedInvoice->id,
                        'invoice_number' => (string) $preparedInvoice->invoice_number,
                        'pdf_name' => (string) ($preparedInvoice->pdf_name ?: $preparedInvoice->invoice_number.'.pdf'),
                        'pdf_path' => (string) ($preparedInvoice->pdf_path ?: ''),
                        'creator_name' => (string) ($preparedInvoice->creator?->name ?: 'FlowTrack'),
                        'prepared_at' => $preparedInvoice->created_at,
                    ]])
                    ->all();
            }
        }

        return [
            'team' => JobDetailPresenter::team($job),
            'canEditJob' => $canEdit,
            'canChangeOwner' => $access->isAdministrator($user),
            'canViewProducts' => $access->can($user, 'catalog_products', 'view'),
            'canEditProducts' => $canEdit && $access->can($user, 'catalog_products', 'view') && $access->can($user, 'catalog_products', 'edit'),
            'canCreateProducts' => $canEdit && $access->can($user, 'catalog_products', 'view') && $access->can($user, 'catalog_products', 'create'),
            'canDeleteProducts' => $canEdit && $access->can($user, 'catalog_products', 'view') && $access->can($user, 'catalog_products', 'delete'),
            'canCreateTask' => $access->canCreateJobTask($user, $job) && !$inactive,
            'canViewDocumentArchive' => $access->can($user, 'document_archive', 'view'),
            'canDeleteDocument' => $access->can($user, 'documents', 'delete'),
            'canUploadDocument' => $access->can($user, 'documents', 'create'),
            'canLinkDocument' => $access->can($user, 'documents', 'link'),
            'canExportDocument' => $access->can($user, 'documents', 'export'),
            'canComment' => $canEdit,
            'taskPermissions' => $taskPermissions,
            'taskActions' => $taskActionDescriptors,
            'taskActionModals' => $taskActionModals,
            'workflowEmailStatuses' => $workflowEmailStatuses,
            'workflowInvoices' => $workflowInvoices,
            'productionMonitorDetails' => $productionMonitorDetails,
            'canCancel' => $canEdit && !$inactive && (int) ($job->phase?->sequence ?? 999) <= 4,
            'canHold' => $canEdit && !$inactive && !$activeHold,
            'canReleaseHold' => $canEdit && !$inactive && (bool) $activeHold,
            'isOnHold' => (bool) $activeHold,
            'hold' => $activeHold ? [
                'id' => (int) $activeHold->id,
                'holdFrom' => (string) $activeHold->hold_from,
                'holdFromLabel' => $activeHold->holdFromLabel(),
                'sourceName' => $activeHoldSourceName ?: '—',
                'reason' => (string) $activeHold->reason,
                'heldById' => $activeHold->held_by ? (int) $activeHold->held_by : null,
                'heldBy' => (string) ($activeHold->holder?->name ?: 'Unknown user'),
                'startedAt' => $activeHold->started_at,
            ] : null,
            'attentionLocked' => $inactive,
            'flagged' => (bool) ($job->attention_requested ?? false),
            'flagReason' => trim((string) ($job->attention_reason ?? '')),
            'orderFlagLabel' => (string) ($job->orderFlag?->name ?? ''),
            'shipmentUrgencyId' => OrderDetailPresenter::shipmentUrgencyId($job),
            'shipmentUrgencyName' => $shipmentUrgencyName,
            'shipmentUrgencyTone' => OrderDetailPresenter::urgencyTone($shipmentUrgencyName),
            'remoteArea' => $remoteArea ? [
                'id' => (int) $remoteArea->id,
                'name' => trim((string) $remoteArea->name),
                // Show the actual Order postal code that matched. UPS-style
                // range rows intentionally do not populate legacy postal_code.
                'postal_code' => $masterData->normalizePostalCode((string) $job->shipping_postal_code),
                'location' => $remoteArea->remoteAreaLocationLabel(),
                'extra_charge' => $remoteArea->remoteAreaExtraCharge(),
            ] : null,
            'courierOptions' => $courierOptions
                ->map(fn ($courier) => [
                    'value' => trim((string) ($courier->name ?? '')),
                    'label' => trim((string) ($courier->name ?? '')),
                ])
                ->filter(fn (array $courier) => $courier['value'] !== '')
                ->values()
                ->all(),
        ];
    }
}
