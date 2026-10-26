<?php

namespace App\Services;

use App\Exceptions\EmailDeliveryException;
use App\Models\Activity;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use App\Support\AttachmentUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Backend action engine for the prototype-matched Order Details task flow.
 *
 * The prototype defines the user interaction (direct actions, purpose-built
 * dialogs, revision loops and conditional branches). This service preserves
 * those rules server-side so Blade only renders the UI and never decides which
 * task/stage unlocks next.
 */
class OrderWorkflowActionService
{
    private const TITLE_KEYS = [
        'upload purchase order' => 'NEW_UPLOAD_PO',
        'send purchase order to artwork team' => 'NEW_SEND_PO_ARTWORK',
        'prepare & upload artwork' => 'ART_PREPARE_UPLOAD',
        'prepare and upload artwork' => 'ART_PREPARE_UPLOAD',
        'internal artwork review' => 'ART_INTERNAL_REVIEW',
        'send artwork to order team' => 'ART_SEND_ORDER_TEAM',
        'client erp / approval' => 'ART_CLIENT_ERP_DECISION',
        'sample approval (when required)' => 'ART_SAMPLE_APPROVAL',
        'set estimated delivery date' => 'PROD_SET_ESTIMATED_DELIVERY',
        'start production' => 'PROD_START',
        'monitor / resolve production issue' => 'PROD_ISSUE',
        'finish production' => 'PROD_FINISH',
        'perform qc check' => 'QC_CHECK',
        'resolve qc issue (when needed)' => 'QC_ISSUE',
        'approve for shipment' => 'QC_APPROVE_SHIPMENT',
        'confirm shipment information' => 'SHIP_CONFIRM_INFO',
        'confirm shipping information' => 'SHIP_CONFIRM_INFO',
        'review shipment info' => 'SHIP_CONFIRM_INFO',
        'review or update shipment details' => 'SHIP_CONFIRM_INFO',
        'generate & print courier label' => 'SHIP_LABEL',
        'generate courier label' => 'SHIP_LABEL',
        'preview & print courier label' => 'SHIP_LABEL',
        'add tracking number & print courier label' => 'SHIP_LABEL',
        'ship package' => 'SHIP_PACKAGE',
        'ship the package' => 'SHIP_PACKAGE',
        'dispatch shipment' => 'SHIP_PACKAGE',
        'prepare invoice' => 'BILL_PREPARE',
        'send invoice' => 'BILL_SEND',
        'receive & process payment' => 'PAY_PROCESS',
    ];

    private const DOCUMENT_ACTIONS = ['NEW_UPLOAD_PO', 'ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'];

    public function automationKey(Task $task): ?string
    {
        $task->loadMissing('setupTemplate');
        $key = trim((string) ($task->setupTemplate?->automation_key ?? ''));
        if ($key !== '') return $key;

        return self::TITLE_KEYS[strtolower(trim((string) $task->title))] ?? null;
    }

    /**
     * Presentation descriptor only. $hasEvidence is supplied by the already
     * hydrated Order detail context so rendering the task list performs no
     * per-task evidence queries.
     */
    public function descriptor(Task $task, ?bool $hasEvidence = null): array
    {
        $key = $this->automationKey($task);
        $status = strtolower(trim((string) $task->status));
        $hasEvidence ??= $this->loadedEvidenceState($task);

        $label = match ($key) {
            'NEW_UPLOAD_PO' => $hasEvidence ? 'Add other documents' : 'Upload Purchase Order',
            'NEW_SEND_PO_ARTWORK' => 'Send to Artwork Team',
            'ART_PREPARE_UPLOAD' => $hasEvidence ? 'Upload Revised Artwork' : 'Upload Artwork',
            'ART_INTERNAL_REVIEW' => 'Review Artwork',
            'ART_SEND_ORDER_TEAM' => 'Send Artwork',
            'ART_CLIENT_ERP_DECISION' => str_contains($status, 'waiting for client') || str_contains($status, 'client approval')
                ? 'Record Client Decision'
                : 'Artwork Uploaded to Client ERP',
            'ART_SAMPLE_APPROVAL' => 'Upload Sample Approval',
            'PROD_SET_ESTIMATED_DELIVERY' => 'Set date',
            'PROD_START' => 'Production Ongoing',
            'PROD_ISSUE' => 'Monitor Production',
            'PROD_FINISH' => 'Production Finished',
            'QC_CHECK' => 'Open QC Check',
            'QC_ISSUE' => str_contains($status, 'issue') ? 'Issue Resolved' : 'Continue',
            'QC_APPROVE_SHIPMENT' => 'Proceed to Shipment',
            'SHIP_CONFIRM_INFO' => 'Review shipment details',
            'SHIP_LABEL' => 'Add tracking number',
            'SHIP_PACKAGE' => 'Dispatch shipment',
            'BILL_PREPARE' => 'Prepare Invoice',
            'BILL_SEND' => 'Preview & Send',
            'PAY_PROCESS' => 'Record Payment',
            default => 'Take action',
        };

        $interaction = match (true) {
            in_array($key, self::DOCUMENT_ACTIONS, true) => 'document',
            in_array($key, ['PROD_START', 'PROD_FINISH', 'QC_APPROVE_SHIPMENT'], true) => 'direct',
            // Shipment tracking now requires courier + tracking input. Keep this
            // as a modal action on the Orders list so it matches Task 5.2 on
            // Order Details instead of trying the legacy one-click label flow.
            default => 'modal',
        };

        return ['key' => $key, 'label' => $label, 'type' => $interaction, 'interaction' => $interaction];
    }

    public function modalCopy(Task $task): array
    {
        $key = $this->automationKey($task);
        $status = strtolower(trim((string) $task->status));

        return match ($key) {
            'NEW_SEND_PO_ARTWORK' => [
                'variant' => 'purchase_order_email',
                'title' => 'Send Purchase Order to Artwork Team',
                'copy' => 'Review the destination team and uploaded Purchase Order before sending.',
                'choices' => ['confirm' => 'Send Purchase Order'],
            ],
            'ART_INTERNAL_REVIEW' => [
                'variant' => 'artwork_review',
                'title' => 'Review Artwork',
                'copy' => 'Review the latest uploaded artwork before sending it to the Order Team.',
                'choices' => ['revise' => 'Revise Artwork', 'cancel_artwork' => 'Cancel Artwork', 'confirm' => 'Artwork Confirmed'],
            ],
            'ART_SEND_ORDER_TEAM' => [
                'variant' => 'artwork_email',
                'title' => 'Send Artwork to Order Team',
                'copy' => 'Preview the latest confirmed artwork and internal handoff before sending.',
                'choices' => ['confirm' => 'Send Artwork'],
            ],
            'ART_CLIENT_ERP_DECISION' => str_contains($status, 'waiting for client') || str_contains($status, 'client approval')
                ? [
                    'variant' => 'client_decision',
                    'title' => 'Record Client Artwork Decision',
                    'copy' => 'Choose the decision received from the client for the latest artwork.',
                    'choices' => ['revise' => 'Client Requested Revision', 'approved' => 'Client Approved Artwork'],
                ]
                : [
                    'variant' => 'client_erp',
                    'title' => 'Confirm Client ERP Upload',
                    'copy' => 'Confirm that the latest artwork has been uploaded to the client ERP.',
                    'choices' => ['erp_uploaded' => 'Artwork Uploaded'],
                ],
            'PROD_SET_ESTIMATED_DELIVERY' => [
                'variant' => 'estimated_delivery',
                'title' => 'Set estimated delivery date',
                'copy' => 'Assign the estimated delivery date before Production can start.',
                'choices' => ['confirm' => 'Save date'],
            ],
            'PROD_ISSUE' => [
                'variant' => 'production_monitor',
                'title' => 'Monitor / Resolve Production Issue',
                'copy' => 'Record the supplier delivery date and any production issue note before saving this task.',
                'choices' => ['confirm' => 'Save'],
            ],
            'QC_CHECK' => [
                'variant' => 'qc_check',
                'title' => 'Product Quality Check',
                'copy' => 'Record received/inspected quantities and either pass QC or report an issue.',
                'choices' => ['issue' => 'Report Issue', 'pass' => 'QC Passed'],
            ],
            'QC_ISSUE' => [
                'variant' => 'issue_resolution',
                'issue_type' => 'QC',
                'title' => 'Resolve QC Issue',
                'copy' => 'Record the corrective action before Shipment can be activated.',
                'choices' => ['resolve' => 'Issue Resolved'],
            ],
            'SHIP_CONFIRM_INFO' => [
                'variant' => 'shipment_info',
                'title' => 'Update shipment details',
                'copy' => 'Review and edit the delivery information for this shipment.',
                'choices' => ['confirm' => 'Save & complete task'],
            ],
            'SHIP_LABEL' => [
                'variant' => 'shipment_tracking',
                'title' => 'Add tracking number & print courier label',
                'copy' => 'Select the courier and enter the tracking number. Completing this task unlocks Dispatch shipment.',
                'choices' => ['complete' => 'Continue to next task'],
            ],
            'SHIP_PACKAGE' => [
                'variant' => 'ship_package',
                'title' => 'Dispatch shipment',
                'copy' => 'Record the carrier, tracking number and shipment date.',
                'choices' => ['confirm' => 'Confirm Shipment'],
            ],
            'BILL_PREPARE' => [
                'variant' => 'invoice_prepare',
                'title' => 'Prepare Invoice',
                'copy' => 'Prepare the invoice details for this shipped Order.',
                'choices' => ['confirm' => 'Prepare Invoice'],
            ],
            'BILL_SEND' => [
                'variant' => 'invoice_send',
                'title' => 'Send Invoice to Client',
                'copy' => 'Preview the invoice email before sending it to the client.',
                'choices' => ['confirm' => 'Send Invoice'],
            ],
            'PAY_PROCESS' => [
                'variant' => 'payment',
                'title' => 'Record Client Payment',
                'copy' => 'Record the payment amount, date and reference.',
                'choices' => ['confirm' => 'Process Payment'],
            ],
            default => [
                'variant' => 'confirm',
                'title' => $this->descriptor($task)['label'],
                'copy' => 'Confirm this workflow action.',
                'choices' => ['confirm' => $this->descriptor($task)['label']],
            ],
        };
    }

    /** @return array<string, mixed> */
    public function invoiceEmailPreview(Task $task, array $payload = []): array
    {
        return app(\App\Services\Orders\OrderInvoiceWorkflowEmailService::class)
            ->preview($task, null, $payload);
    }

    public function preparedWorkflowInvoice(FlowJob $job): ?\App\Models\Invoice
    {
        return app(\App\Services\Orders\OrderInvoiceWorkflowEmailService::class)
            ->preparedInvoice($job);
    }

    /** @return array<string, mixed> */
    public function initialPayload(Task $task, FlowJob $job): array
    {
        $key = $this->automationKey($task);
        $invoiceActivity = in_array($key, ['BILL_PREPARE', 'BILL_SEND'], true)
            ? $job->activities()->where('event', 'job.workflow_invoice_prepared')->latest('id')->first()
            : null;
        $preparedInvoice = in_array($key, ['BILL_PREPARE', 'BILL_SEND'], true)
            ? app(\App\Services\Orders\OrderInvoiceWorkflowEmailService::class)->preparedInvoice($job)
            : null;
        $total = $this->orderTotal($job);
        $remoteArea = $key === 'BILL_PREPARE'
            ? app(MasterDataService::class)->remoteAreaForPostalCode($job->shipping_postal_code)
            : null;
        $remoteAreaCharge = max(0, (float) ($remoteArea?->remoteAreaExtraCharge() ?? 0));
        $paid = $this->recordedPaymentTotal($job);
        $units = (int) $job->items->filter(fn ($item) => ! ($item->is_removed ?? false))->sum(fn ($item) => (int) ($item->quantity ?? 0));
        $inspected = $units > 0 ? max(1, min($units, (int) ceil($units * 0.10))) : 1;

        $payload = [
            'revision_document_id' => null,
            'revision_document_ids' => [],
            'revision_items' => [],
            'cancel_artwork_document_ids' => [],
            'cancel_product_item_ids' => [],
            'recipient_type' => 'team',
            'to_user_id' => null,
            'to_email' => '',
            'to_emails' => '',
            'external_to_name' => '',
            'external_to_email' => '',
            'assignee_user_id' => null,
            'cc_user_ids' => [],
            'cc_emails' => '',
            'external_cc_emails' => '',
            'customer_comment' => '',
            'qty_received' => $units ?: 1,
            'qty_inspected' => $inspected,
            'qty_accepted' => $inspected,
            'qty_rejected' => 0,
            'qc_comments' => '',
            'erp_reference' => trim((string) ($job->order_number ?: $job->job_number)),
            'issue_category' => 'Other',
            'recipient' => trim((string) ($job->client?->name ?: 'Client')).' — Receiving',
            'contact' => trim((string) (($job->shipping_phone_country_code ? $job->shipping_phone_country_code.' ' : '').($job->shipping_phone ?: ''))),
            'address' => trim((string) ($job->shipping_address ?: '')),
            'packages' => '',
            'weight' => '',
            'dimensions' => '',
            'declared_value' => $total > 0 ? number_format($total, 2, '.', '') : '',
            'carrier' => 'UPS',
            'tracking_number' => '',
            'shipment_date' => app(WorkspaceSettingsService::class)->localToday()->toDateString(),
            'estimated_delivery_date' => $job->estimated_delivery_date?->format('Y-m-d') ?: '',
            'supplier_delivery_date' => $job->supplier_delivery_date?->format('Y-m-d') ?: '',
            'invoice_number' => (string) ($preparedInvoice?->invoice_number ?: data_get($invoiceActivity?->meta, 'invoice_number', '')),
            'invoice_date' => app(WorkspaceSettingsService::class)->localToday()->toDateString(),
            'invoice_amount' => $total > 0 ? number_format($total, 2, '.', '') : '0.00',
            'remote_area_charge' => $remoteAreaCharge,
            'remote_area_name' => trim((string) ($remoteArea?->name ?? '')),
            'remote_area_postal_code' => $remoteArea
                ? app(MasterDataService::class)->normalizePostalCode((string) $job->shipping_postal_code)
                : '',
            'invoice_currency' => 'USD',
            'payment_terms' => 'Net 30',
            'invoice_due_date' => app(WorkspaceSettingsService::class)->localToday()->addDays(30)->toDateString(),
            'payment_amount' => max(0, $total - $paid) > 0 ? number_format(max(0, $total - $paid), 2, '.', '') : '',
            'payment_date' => app(WorkspaceSettingsService::class)->localToday()->toDateString(),
            'payment_reference' => '',
            'payment_notes' => '',
        ];

        if ($preparedInvoice && $key === 'BILL_SEND') {
            $payload['invoice_id'] = (int) $preparedInvoice->id;
            $payload['invoice_number'] = (string) $preparedInvoice->invoice_number;
            $payload['invoice_date'] = $preparedInvoice->issue_date?->format('Y-m-d') ?: $payload['invoice_date'];
            $payload['invoice_amount'] = number_format((float) $preparedInvoice->total, 2, '.', '');
            $payload['invoice_currency'] = (string) $preparedInvoice->currency;
            $payload['invoice_due_date'] = $preparedInvoice->due_date?->format('Y-m-d') ?: $payload['invoice_due_date'];
            $payload['payment_terms'] = (string) data_get($invoiceActivity?->meta, 'payment_terms', $payload['payment_terms']);
            $payload['to_email'] = trim((string) ($preparedInvoice->billing_contact_email ?: $job->client?->email ?: ''));
            $payload['external_to_name'] = trim((string) ($job->client?->billing_recipient ?: $preparedInvoice->billing_contact_name ?: $job->client?->contact_name ?: $job->client?->name ?: 'Client accounts contact'));
        }

        if (in_array($key, ['SHIP_LABEL', 'SHIP_PACKAGE'], true)) {
            $courierOptions = app(MasterDataService::class)->active('courier')
                ->map(fn ($record) => [
                    'value' => trim((string) $record->name),
                    'label' => trim((string) $record->name),
                ])
                ->filter(fn (array $option) => $option['value'] !== '')
                ->unique(fn (array $option) => mb_strtolower($option['value']))
                ->values();
            $payload['courier_options'] = $courierOptions->all();

            $shipmentInfoActivity = $job->activities()
                ->where('event', 'job.shipment_information_confirmed')
                ->latest('id')
                ->first();
            if ($shipmentInfoActivity) {
                $shipmentMeta = (array) ($shipmentInfoActivity->meta ?? []);
                $payload['recipient'] = trim((string) ($shipmentMeta['contact_name'] ?? $shipmentMeta['recipient'] ?? $payload['recipient']));
                $payload['contact'] = trim((string) (($shipmentMeta['phone_country_code'] ?? '').' '.($shipmentMeta['phone_number'] ?? '')));
                $payload['address'] = trim((string) ($shipmentMeta['address'] ?? $payload['address']));
            }

            $labelActivity = $job->activities()
                ->where('event', 'job.courier_label_generated')
                ->latest('id')
                ->first();
            $savedCarrier = trim((string) data_get($labelActivity?->meta, 'carrier', ''));
            if ($savedCarrier !== '') {
                $payload['carrier'] = $savedCarrier;
            } else {
                $configuredCarrier = trim((string) ($payload['carrier'] ?? ''));
                $carrierIsActive = $courierOptions->contains(
                    fn (array $option) => strcasecmp($option['value'], $configuredCarrier) === 0,
                );
                $payload['carrier'] = $carrierIsActive
                    ? $configuredCarrier
                    : (string) data_get($courierOptions->first(), 'value', '');
            }
            $payload['tracking_number'] = trim((string) data_get($labelActivity?->meta, 'tracking_number', ''));
        }

        if ($key === 'SHIP_CONFIRM_INFO') {
            $payload = array_merge($payload, $this->shipmentContactPayload($job));
        }

        if ($key === 'BILL_PREPARE') {
            // Billing workflow invoice numbers are system-generated so users do
            // not need to invent or coordinate invoice identifiers manually.
            // Keep the format aligned with finance invoices while accounting for
            // both finance records and earlier workflow-prepared invoices.
            $payload['invoice_number'] = $this->nextWorkflowInvoiceNumber($job);
        }

        return $payload;
    }

    /**
     * Update shipment information after its workflow task has already been
     * completed. This writes a new activity snapshot without reopening the task
     * or moving the Order backwards in the workflow.
     *
     * @param array<string,mixed> $payload
     */
    public function updateCompletedShipmentInformation(Task $task, User $actor, array $payload): Task
    {
        return DB::transaction(function () use ($task, $actor, $payload): Task {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->with(['job.phase', 'setupTemplate'])->firstOrFail();
            abort_unless($this->automationKey($locked) === 'SHIP_CONFIRM_INFO', 422, 'This task does not manage shipment information.');
            abort_unless((bool) $locked->completed_at || strcasecmp(trim((string) $locked->status), 'Completed') === 0, 422, 'Complete the shipment information task before editing historical shipment details.');

            $job = FlowJob::query()->whereKey($locked->flow_job_id)->lockForUpdate()->with(['client', 'items'])->firstOrFail();
            abort_if(strcasecmp((string) $job->status, 'Cancelled') === 0, 422, 'Cancelled Orders cannot be edited.');
            app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);

            $this->validateShipmentInfo($payload);
            if ((bool) ($payload['update_saved_contact'] ?? false)) {
                $this->updateSavedShipmentContact($job, $actor, $payload);
            }

            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.shipment_information_confirmed',
                'description' => 'Shipment information updated after task completion.',
                'meta' => $this->onlyPayload($payload, [
                    'client_name','contact_name','contact_type','phone_country_code','phone_number',
                    'address','city','state','country','postal_code','recipient','contact',
                ]),
            ]);

            return $locked->refresh();
        }, 3);
    }

    /**
     * Update courier/tracking data after the tracking task has completed. The
     * latest activity remains the source of truth used by Shipment presentation.
     */
    public function updateCompletedShipmentTracking(Task $task, User $actor, string $carrier, string $trackingNumber): Task
    {
        $carrier = trim($carrier);
        $trackingNumber = trim($trackingNumber);

        return DB::transaction(function () use ($task, $actor, $carrier, $trackingNumber): Task {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->with(['job.phase', 'setupTemplate'])->firstOrFail();
            abort_unless($this->automationKey($locked) === 'SHIP_LABEL', 422, 'This task does not manage shipment tracking.');
            abort_unless((bool) $locked->completed_at || strcasecmp(trim((string) $locked->status), 'Completed') === 0, 422, 'Complete the tracking task before editing historical tracking details.');

            $job = FlowJob::query()->whereKey($locked->flow_job_id)->lockForUpdate()->firstOrFail();
            abort_if(strcasecmp((string) $job->status, 'Cancelled') === 0, 422, 'Cancelled Orders cannot be edited.');
            app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);

            if ($carrier === '' || $trackingNumber === '') {
                throw ValidationException::withMessages([
                    'shipmentLabel' => 'Select a courier and enter the tracking number first.',
                ]);
            }
            $this->validateShipmentCourier($carrier);

            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.courier_label_generated',
                'description' => 'Courier and tracking details updated after task completion.',
                'meta' => ['carrier' => $carrier, 'tracking_number' => $trackingNumber],
            ]);

            // Once the package has been dispatched, Order lists read carrier and
            // tracking from the dispatch snapshot. Keep that denormalized snapshot
            // synchronized so a tracking correction is reflected everywhere.
            $dispatchActivity = $job->activities()
                ->where('event', 'job.package_shipped')
                ->latest('id')
                ->first();
            if ($dispatchActivity) {
                $dispatchActivity->update([
                    'description' => $carrier.' tracking '.$trackingNumber.' recorded.',
                    'meta' => array_merge((array) ($dispatchActivity->meta ?? []), [
                        'carrier' => $carrier,
                        'tracking_number' => $trackingNumber,
                    ]),
                ]);
            }

            return $locked->refresh();
        }, 3);
    }

    /**
     * Edit the canonical estimated delivery date after its Production task has
     * completed. Keep the task SLA due date independent from the Order's
     * estimated delivery date so overdue/task timing reports are not changed.
     */
    public function updateCompletedEstimatedDeliveryDate(Task $task, User $actor, string $estimatedDeliveryDate): Task
    {
        $estimatedDeliveryDate = trim($estimatedDeliveryDate);

        return DB::transaction(function () use ($task, $actor, $estimatedDeliveryDate): Task {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->with(['job.phase', 'setupTemplate'])->firstOrFail();
            abort_unless($this->automationKey($locked) === 'PROD_SET_ESTIMATED_DELIVERY', 422, 'This task does not manage the estimated delivery date.');
            abort_unless((bool) $locked->completed_at || strcasecmp(trim((string) $locked->status), 'Completed') === 0, 422, 'Complete the estimated delivery date task before editing it.');

            $job = FlowJob::query()->whereKey($locked->flow_job_id)->lockForUpdate()->firstOrFail();
            abort_if(strcasecmp((string) $job->status, 'Cancelled') === 0, 422, 'Cancelled Orders cannot be edited.');
            app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);

            if ($estimatedDeliveryDate === '') {
                throw ValidationException::withMessages([
                    'estimated_delivery_date' => 'Estimated delivery date is required.',
                ]);
            }

            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $estimatedDeliveryDate);
            if (! $parsedDate || $parsedDate->format('Y-m-d') !== $estimatedDeliveryDate) {
                throw ValidationException::withMessages([
                    'estimated_delivery_date' => 'Enter a valid estimated delivery date.',
                ]);
            }

            $currentDate = $job->estimated_delivery_date?->format('Y-m-d');
            if ($currentDate === $estimatedDeliveryDate) {
                return $locked->refresh();
            }

            $job->update(['estimated_delivery_date' => $estimatedDeliveryDate]);
            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.estimated_delivery_date_set',
                'description' => 'Estimated delivery date updated to '.$parsedDate->format('M j, Y').' after task completion.',
                'meta' => [
                    'estimated_delivery_date' => $estimatedDeliveryDate,
                    'task_id' => (int) $locked->id,
                    'edited_after_completion' => true,
                ],
            ]);

            return $locked->refresh();
        }, 3);
    }

    /**
     * Apply a task action. All task completion goes through TaskService so the
     * normal sequencing, progress, audit and automatic stage advance hooks run.
     *
     * @param array<string,mixed> $payload
     */
    public function perform(Task $task, User $actor, ?string $decision = null, ?string $comment = null, array $payload = [], array $attachments = []): Task
    {
        $baselineStart = \App\Support\Performance\OrderBaselineRecorder::enabled() ? microtime(true) : null;
        $baselineSuccess = false;
        try {
            $result = $this->performCanonical($task, $actor, $decision, $comment, $payload, $attachments);
            $baselineSuccess = true;
            return $result;
        } finally {
            if ($baselineStart !== null) {
                \App\Support\Performance\OrderBaselineRecorder::record('task_workflow_action', [
                    'order_id' => (int) $task->flow_job_id,
                    'task_id' => (int) $task->id,
                    'success' => $baselineSuccess,
                    'duration_ms' => round((microtime(true) - $baselineStart) * 1000, 2),
                ]);
            }
        }
    }

    private function performCanonical(Task $task, User $actor, ?string $decision, ?string $comment, array $payload, array $attachments): Task
    {
        return DB::transaction(function () use ($task, $actor, $decision, $comment, $payload, $attachments): Task {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->with(['job.phase', 'setupTemplate'])->firstOrFail();
            $job = FlowJob::query()->whereKey($locked->flow_job_id)->lockForUpdate()->with(['client', 'items'])->firstOrFail();

            // Workflow actions are allowed to be retried safely. A duplicate
            // Livewire request can arrive after the first request completed the
            // task (and may already have advanced the Order to the next stage).
            // Returning the completed task here prevents duplicate side effects
            // and avoids turning a successful stage transition into a stale 422.
            if ($this->isCompletedWorkflowTask($locked)) {
                return $locked->refresh();
            }

            app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);
            abort_unless((int) $locked->workflow_phase_id === (int) $job->workflow_phase_id, 422, 'This task is locked until its workflow stage is active.');
            app(OrderTaskSequenceService::class)->assertStatusActionable($locked);
            $locked = app(TaskService::class)->claimForAction($locked, $actor, 'performed a workflow action');

            $key = $this->automationKey($locked);
            $decision = strtolower(trim((string) $decision));
            $comment = app(RichTextService::class)->normalize(
                $comment,
                10000,
                'orderWorkflowActionComment',
            ) ?? '';

            if ($key === 'ART_INTERNAL_REVIEW' && $decision === 'revise') {
                $activity = $this->restartArtwork($locked, $actor, 'Internal artwork revision requested', $payload, $comment);
                $this->storeArtworkRevisionAttachments($activity, $locked, $actor, $attachments);
                return $locked->refresh();
            }

            if ($key === 'ART_INTERNAL_REVIEW' && $decision === 'cancel_artwork') {
                app(OrderArtworkCancellationService::class)->cancel($job, $locked, $actor, $payload, $comment);
                return $locked->refresh();
            }

            if ($key === 'ART_INTERNAL_REVIEW' && $decision === 'confirm') {
                // TaskService generates the PDF at the completed-status transition,
                // including direct task completion; do not generate it twice.
                return $this->complete($locked, $actor)->refresh();
            }

            if ($key === 'ART_CLIENT_ERP_DECISION' && $decision === 'erp_uploaded') {
                $reference = trim((string) ($payload['erp_reference'] ?? ''));
                $locked->update(['status' => 'Waiting for Client', 'completed_at' => null, 'progress' => 60]);
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.artwork_client_erp_uploaded',
                    'description' => 'Artwork uploaded to Client ERP'.($reference !== '' ? ' · Reference '.$reference : '').'.',
                    'meta' => ['erp_reference' => $reference],
                ]);
                app(JobService::class)->syncAutomaticStatus($job->refresh(), $actor);
                return $locked->refresh();
            }

            if ($key === 'ART_CLIENT_ERP_DECISION' && $decision === 'revise') {
                $activity = $this->restartArtwork($locked, $actor, 'Client artwork revision requested', $payload, $comment);
                $this->storeArtworkRevisionAttachments($activity, $locked, $actor, $attachments);
                return $locked->refresh();
            }

            if ($key === 'ART_CLIENT_ERP_DECISION' && $decision === 'sample') {
                $sample = Task::query()
                    ->where('flow_job_id', $job->id)
                    ->where('workflow_phase_id', $locked->workflow_phase_id)
                    ->whereNotNull('task_pack_task_id')
                    ->with('setupTemplate')
                    ->get()
                    ->first(fn (Task $candidate) => $this->automationKey($candidate) === 'ART_SAMPLE_APPROVAL');
                abort_unless($sample, 422, 'The Sample Approval task is not configured for this Order.');

                $ready = app(OrderTaskFlagService::class)->readyStatus();
                $readyId = app(OrderTaskFlagService::class)->statusRecord($ready, false)?->id;
                $locked->update(['status' => 'Waiting for Sample Approval', 'completed_at' => null, 'progress' => 80]);
                $sample->update([
                    'status' => $ready,
                    'order_task_status_id' => $readyId,
                    'completed_at' => null,
                    'progress' => 0,
                    'start_date' => $sample->start_date ?: app(WorkspaceSettingsService::class)->localToday(),
                ]);
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.sample_required',
                    'description' => 'Client approved artwork. Sample approval is required before Production.',
                ]);
                return $locked->refresh();
            }

            if ($key === 'ART_CLIENT_ERP_DECISION' && $decision === 'confirm') {
                // Prototype normal path: Client approved the artwork and chose
                // NO sample/swatch. The optional Sample Approval branch must be
                // explicitly skipped before the client-decision task completes;
                // otherwise an untouched optional row can look actionable and
                // its document requirement can keep Artwork from advancing.
                $sample = Task::query()
                    ->where('flow_job_id', $job->id)
                    ->where('workflow_phase_id', $locked->workflow_phase_id)
                    ->whereNotNull('task_pack_task_id')
                    ->with('setupTemplate')
                    ->get()
                    ->first(fn (Task $candidate) => $this->automationKey($candidate) === 'ART_SAMPLE_APPROVAL');

                if ($sample && ! $sample->completed_at) {
                    $sample->update([
                        'status' => 'Skipped',
                        'order_task_status_id' => null,
                        'completed_at' => null,
                        'progress' => 0,
                    ]);
                }

                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.sample_not_required',
                    'description' => 'Client approved artwork. No sample or swatch is required; Production can start directly.',
                ]);

                return $this->complete($locked, $actor);
            }

            if ($key === 'PROD_SET_ESTIMATED_DELIVERY') {
                $estimatedDeliveryDate = trim((string) ($payload['estimated_delivery_date'] ?? ''));
                if ($estimatedDeliveryDate === '') {
                    throw ValidationException::withMessages([
                        'orderWorkflowActionPayload.estimated_delivery_date' => 'Estimated delivery date is required.',
                    ]);
                }

                $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $estimatedDeliveryDate);
                if (! $parsedDate || $parsedDate->format('Y-m-d') !== $estimatedDeliveryDate) {
                    throw ValidationException::withMessages([
                        'orderWorkflowActionPayload.estimated_delivery_date' => 'Enter a valid estimated delivery date.',
                    ]);
                }

                $job->update(['estimated_delivery_date' => $estimatedDeliveryDate]);
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.estimated_delivery_date_set',
                    'description' => 'Estimated delivery date set to '.$parsedDate->format('M j, Y').'.',
                    'meta' => ['estimated_delivery_date' => $estimatedDeliveryDate],
                ]);

                return $this->complete($locked, $actor);
            }

            if ($key === 'PROD_ISSUE' && $decision === 'confirm') {
                $supplierDeliveryDate = trim((string) ($payload['supplier_delivery_date'] ?? ''));
                $parsedDate = null;
                if ($supplierDeliveryDate !== '') {
                    $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $supplierDeliveryDate);
                    if (! $parsedDate || $parsedDate->format('Y-m-d') !== $supplierDeliveryDate) {
                        throw ValidationException::withMessages([
                            'orderWorkflowActionPayload.supplier_delivery_date' => 'Enter a valid supplier delivery date.',
                        ]);
                    }

                    $job->update(['supplier_delivery_date' => $supplierDeliveryDate]);
                }

                $plainNote = trim(app(RichTextService::class)->plainText($comment));
                $description = $parsedDate
                    ? 'Supplier delivery date set to '.$parsedDate->format('M j, Y').'.'
                    : ($plainNote !== '' ? 'Production issue note saved.' : 'Production monitoring task completed.');
                if ($parsedDate && $plainNote !== '') $description .= ' Production issue note: '.$plainNote;

                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.supplier_delivery_date_set',
                    'description' => $description,
                    'meta' => [
                        'supplier_delivery_date' => $supplierDeliveryDate !== '' ? $supplierDeliveryDate : null,
                        'production_issue_note' => $comment !== '' ? $comment : null,
                        'task_id' => (int) $locked->id,
                    ],
                ]);

                return $this->complete($locked, $actor);
            }

            if ($key === 'QC_CHECK' && $decision === 'issue') {
                $this->validateQcPayload($payload);
                $this->requireComment($comment, 'Describe the QC issue before reporting it.');
                $issueTask = Task::query()
                    ->where('flow_job_id', $job->id)
                    ->where('workflow_phase_id', $locked->workflow_phase_id)
                    ->whereNotNull('task_pack_task_id')
                    ->with('setupTemplate')
                    ->get()
                    ->first(fn (Task $candidate) => $this->automationKey($candidate) === 'QC_ISSUE');
                abort_unless($issueTask, 422, 'The QC issue task is not configured for this Order.');

                $ready = app(OrderTaskFlagService::class)->readyStatus();
                $locked->update(['status' => 'Waiting for QC Issue Resolution', 'completed_at' => null, 'progress' => 70]);
                $issueTask->update([
                    'status' => 'QC Issue Reported',
                    'order_task_status_id' => app(OrderTaskFlagService::class)->statusRecord($ready, false)?->id,
                    'completed_at' => null,
                    'progress' => 50,
                    'start_date' => $issueTask->start_date ?: app(WorkspaceSettingsService::class)->localToday(),
                ]);
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.qc_issue_reported',
                    'description' => $comment,
                    'meta' => $this->qcMeta($payload),
                ]);
                return $locked->refresh();
            }

            if ($key === 'QC_CHECK' && in_array($decision, ['pass', 'confirm'], true)) {
                $this->validateQcPayload($payload);
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.qc_passed',
                    'description' => 'QC passed.',
                    'meta' => $this->qcMeta($payload),
                ]);
                return $this->complete($locked, $actor);
            }

            if (in_array($key, ['PROD_ISSUE', 'QC_ISSUE'], true) && $decision === 'issue') {
                $this->requireComment($comment, 'Describe the issue before reporting it.');
                $category = trim((string) ($payload['issue_category'] ?? 'Other')) ?: 'Other';
                $locked->update([
                    'status' => $key === 'PROD_ISSUE' ? 'Production Issue Reported' : 'QC Issue Reported',
                    'completed_at' => null,
                    'progress' => 50,
                ]);
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => $key === 'PROD_ISSUE' ? 'job.production_issue_reported' : 'job.qc_issue_reported',
                    'description' => $comment,
                    'meta' => ['category' => $category],
                ]);
                app(JobService::class)->syncAutomaticStatus($job->refresh(), $actor);
                return $locked->refresh();
            }

            if (in_array($key, ['PROD_ISSUE', 'QC_ISSUE'], true) && $decision === 'resolve') {
                $this->requireComment($comment, 'Add the resolution before closing this issue.');
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => $key === 'PROD_ISSUE' ? 'job.production_issue_resolved' : 'job.qc_issue_resolved',
                    'description' => $comment,
                ]);
                $completed = $this->complete($locked, $actor);
                if ($key === 'QC_ISSUE') {
                    $waitingQc = Task::query()
                        ->where('flow_job_id', $job->id)
                        ->where('workflow_phase_id', $locked->workflow_phase_id)
                        ->where('status', 'Waiting for QC Issue Resolution')
                        ->with('setupTemplate')
                        ->get()
                        ->first(fn (Task $candidate) => $this->automationKey($candidate) === 'QC_CHECK');
                    if ($waitingQc) $this->complete($waitingQc, $actor);
                }
                return $completed;
            }

            if ($key === 'SHIP_CONFIRM_INFO') {
                $this->validateShipmentInfo($payload);
                if ((bool) ($payload['update_saved_contact'] ?? false)) {
                    $this->updateSavedShipmentContact($job, $actor, $payload);
                }
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.shipment_information_confirmed',
                    'description' => 'Shipment information confirmed.',
                    'meta' => $this->onlyPayload($payload, [
                        'client_name','contact_name','contact_type','phone_country_code','phone_number',
                        'address','city','state','country','postal_code','recipient','contact',
                    ]),
                ]);
                return $this->complete($locked, $actor);
            }

            if ($key === 'SHIP_LABEL' && $decision === 'complete') {
                $carrier = trim((string) ($payload['carrier'] ?? ''));
                $trackingNumber = trim((string) ($payload['tracking_number'] ?? ''));

                if ($carrier === '' || $trackingNumber === '') {
                    throw ValidationException::withMessages([
                        'shipmentLabel' => 'Select a courier and enter the tracking number first.',
                    ]);
                }
                $this->validateShipmentCourier($carrier);

                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.courier_label_generated',
                    'description' => 'Courier and tracking details recorded.',
                    'meta' => $this->onlyPayload($payload, ['carrier','tracking_number']),
                ]);

                return $this->complete($locked, $actor);
            }

            if ($key === 'SHIP_LABEL' && $decision === 'generate') {
                $carrier = trim((string) ($payload['carrier'] ?? ''));
                $trackingNumber = trim((string) ($payload['tracking_number'] ?? ''));
                if ($carrier === '' || $trackingNumber === '') {
                    throw ValidationException::withMessages([
                        'shipmentLabel' => 'Select a courier and enter the tracking number first.',
                    ]);
                }
                $this->validateShipmentCourier($carrier);

                $locked->update(['status' => 'Courier Label Generated', 'completed_at' => null, 'progress' => 55]);
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.courier_label_generated',
                    'description' => 'Courier label generated and ready for preview/print.',
                    'meta' => $this->onlyPayload($payload, ['carrier','tracking_number']),
                ]);
                app(JobService::class)->syncAutomaticStatus($job->refresh(), $actor);
                return $locked->refresh();
            }

            if ($key === 'SHIP_LABEL' && in_array($decision, ['print', 'confirm'], true)) {
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.courier_label_printed',
                    'description' => 'Courier label preview confirmed and marked as printed.',
                ]);
                return $this->complete($locked, $actor);
            }

            if ($key === 'SHIP_PACKAGE') {
                $this->validateShipPackage($payload);
                $carrier = trim((string) $payload['carrier']);
                $tracking = trim((string) $payload['tracking_number']);
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.package_shipped',
                    'description' => $carrier.' tracking '.$tracking.' recorded.',
                    'meta' => $this->onlyPayload($payload, ['carrier','tracking_number','shipment_date','estimated_delivery_date']),
                ]);
                return $this->complete($locked, $actor);
            }

            if ($key === 'BILL_PREPARE') {
                $this->validateInvoice($payload);
                $invoice = app(\App\Services\Orders\OrderInvoiceWorkflowEmailService::class)
                    ->prepare($job, $actor, $payload);

                $preparedMeta = $this->onlyPayload($payload, ['payment_terms']);
                $preparedMeta = array_merge($preparedMeta, [
                    'invoice_id' => (int) $invoice->id,
                    'invoice_number' => (string) $invoice->invoice_number,
                    'invoice_date' => $invoice->issue_date?->format('Y-m-d'),
                    // Keep both the user-entered base amount and generated
                    // total. If a legacy activity ever has to recreate a missing
                    // Invoice record, the Remote Area surcharge will not be added
                    // a second time.
                    'invoice_base_amount' => (float) ($payload['invoice_amount'] ?? 0),
                    'remote_area_charge' => max(0, (float) $invoice->total - (float) ($payload['invoice_amount'] ?? 0)),
                    'invoice_amount' => (float) $invoice->total,
                    'invoice_currency' => (string) $invoice->currency,
                    'invoice_due_date' => $invoice->due_date?->format('Y-m-d'),
                    'pdf_name' => (string) ($invoice->pdf_name ?: ''),
                ]);

                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.workflow_invoice_prepared',
                    'description' => 'Invoice '.$invoice->invoice_number.' prepared.',
                    'meta' => $preparedMeta,
                ]);
                return $this->complete($locked, $actor);
            }

            if ($key === 'BILL_SEND') {
                $invoiceEmail = app(\App\Services\Orders\OrderInvoiceWorkflowEmailService::class);

                try {
                    $trackingId = $invoiceEmail->send($locked, $actor, $payload);
                } catch (EmailDeliveryException $exception) {
                    // Billing must continue even when the provider is down. Keep
                    // the failed delivery as a separate, resendable state just
                    // like the completed Artwork email handoff.
                    $invoiceEmail->recordFailedDelivery($locked, $actor, $payload, $exception);

                    return $this->complete($locked, $actor);
                }

                // A disabled Order email service returns a durable skipped marker
                // from the invoice service. Do not misreport that as a sent email.
                if (! str_starts_with($trackingId, 'disabled-')) {
                    $invoiceEmail->recordSuccessfulDelivery($locked, $actor, $payload, $trackingId);
                }

                return $this->complete($locked, $actor);
            }

            if (in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true)) {
                abort_unless(in_array($decision, ['confirm', 'without_email'], true), 422, 'Choose Send or Complete without email.');

                if ($decision === 'without_email') {
                    // Explicit direct action: never use recipient data from the modal.
                    // The existing email service records the skipped handoff and
                    // its standard workflow completion still advances the stage.
                    app(\App\Services\Orders\OrderWorkflowEmailService::class)->send($locked, $actor, []);
                } else {
                    $recipient = $key === 'NEW_SEND_PO_ARTWORK'
                        ? trim((string) (($payload['to_email'] ?? '') ?: ($payload['external_to_email'] ?? '')))
                        : trim((string) (($payload['to_emails'] ?? '') ?: ($payload['to_email'] ?? '')));
                    if ($recipient === '' && $key === 'NEW_SEND_PO_ARTWORK' && ! empty($payload['to_user_id'])) {
                        $recipient = (string) $payload['to_user_id']; // Older open modal selection.
                    }
                    if ($recipient === '') {
                        throw ValidationException::withMessages([
                            'orderWorkflowActionEmail' => 'Enter a To recipient to send the email, or use Complete without email on the task.',
                        ]);
                    }
                    if (! app(\App\Services\Email\ModuleEmailControlService::class)->orderEnabled()) {
                        throw ValidationException::withMessages([
                            'orderWorkflowActionEmail' => 'Order email is disabled. Use Complete without email on the task.',
                        ]);
                    }
                    app(\App\Services\Orders\OrderWorkflowEmailService::class)->send($locked, $actor, $payload);
                }

                return $this->complete($locked, $actor);
            }

            if ($key === 'PAY_PROCESS') {
                $this->validatePayment($payload);
                $amount = (float) $payload['payment_amount'];
                $total = $this->orderTotal($job);
                $alreadyPaid = $this->recordedPaymentTotal($job);
                $newPaid = $alreadyPaid + $amount;
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.workflow_payment_recorded',
                    'description' => 'Payment '.number_format($amount, 2).' recorded.',
                    'meta' => $this->onlyPayload($payload, ['payment_amount','payment_date','payment_reference','payment_notes']),
                ]);

                if ($total > 0 && $newPaid + 0.0001 < $total) {
                    $progress = max(1, min(99, (int) round(($newPaid / $total) * 100)));
                    $locked->update(['status' => 'Partially Paid', 'completed_at' => null, 'progress' => $progress]);
                    app(JobService::class)->syncAutomaticStatus($job->refresh(), $actor);
                    return $locked->refresh();
                }

                return $this->complete($locked, $actor);
            }

            // Client approved with no sample and normal task confirmations
            // finish through the common lifecycle. Email handoffs are handled
            // above so a task cannot complete unless delivery is accepted.
            return $this->complete($locked, $actor);
        }, 3);
    }

    /**
     * Explicit escape hatch for the two email-blocking workflow handoffs.
     * This can only be called with the server-side failure marker created after
     * the current user exhausted all three delivery attempts for this task.
     *
     * @param array<string,mixed> $failure
     */
    public function completeEmailHandoffAfterFailure(Task $task, User $actor, array $failure): Task
    {
        return DB::transaction(function () use ($task, $actor, $failure): Task {
            $locked = Task::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->with(['job.phase', 'setupTemplate'])
                ->firstOrFail();
            $job = FlowJob::query()
                ->whereKey($locked->flow_job_id)
                ->lockForUpdate()
                ->firstOrFail();

            app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);

            abort_unless((int) $locked->workflow_phase_id === (int) $job->workflow_phase_id, 422, 'This task is locked until its workflow stage is active.');
            app(OrderTaskSequenceService::class)->assertStatusActionable($locked);
            $locked = app(TaskService::class)->claimForAction($locked, $actor, 'completed an email handoff manually');

            $key = $this->automationKey($locked);
            abort_unless(in_array($key, ['NEW_SEND_PO_ARTWORK', 'ART_SEND_ORDER_TEAM'], true), 422, 'This task does not support manual completion after email failure.');
            abort_unless((int) ($failure['task_id'] ?? 0) === (int) $locked->id, 422, 'The email-failure confirmation does not belong to this task.');
            abort_unless((string) ($failure['handoff_key'] ?? '') === (string) $key, 422, 'The email-failure confirmation is no longer valid for this task.');
            abort_unless((int) ($failure['attempts'] ?? 0) >= 3, 422, 'Manual completion is available only after three failed email delivery attempts.');

            if ($key === 'NEW_SEND_PO_ARTWORK') {
                $recipientId = (int) ($failure['assignment_user_id'] ?? $failure['primary_recipient_user_id'] ?? 0);
                if ($recipientId > 0) {
                    $recipient = User::query()->where('is_active', true)->find($recipientId);
                    abort_unless($recipient, 422, 'The selected Artwork recipient is no longer active. Retry the handoff with another user.');
                    $artworkTask = Task::query()
                        ->where('flow_job_id', $job->id)
                        ->with('setupTemplate')
                        ->get()
                        ->first(fn (Task $candidate) => $this->automationKey($candidate) === 'ART_PREPARE_UPLOAD');
                    abort_unless($artworkTask, 422, 'The Prepare & Upload Artwork task is not configured for this Order.');
                    app(TaskService::class)->assignFromWorkflowHandoff($artworkTask, $recipient, $actor);
                }
            }

            $attachmentLabel = $key === 'ART_SEND_ORDER_TEAM' ? 'Artwork' : 'Purchase Order';
            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.workflow_email_manual_completion',
                'description' => $attachmentLabel.' email delivery failed after three attempts. The task was manually completed so the file can be sent outside FlowTrack.',
                'meta' => [
                    'task_id' => (int) $locked->id,
                    'document_id' => (int) ($failure['document_id'] ?? 0) ?: null,
                    'document_name' => (string) ($failure['document_name'] ?? ''),
                    'delivery_attempts' => (int) ($failure['attempts'] ?? 3),
                    'tracking_id' => (string) ($failure['tracking_id'] ?? ''),
                    'manual_delivery_required' => true,
                ],
            ]);

            return $this->complete($locked, $actor);
        }, 3);
    }

    public function afterDocumentAdded(Task $task, User $actor): void
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $task->flow_job_id);
        $task->refresh()->loadMissing(['job.phase', 'setupTemplate']);
        $key = $this->automationKey($task);
        if (! in_array($key, self::DOCUMENT_ACTIONS, true)) return;

        // Completed file-backed tasks can still accept supporting documents.
        // Adding those files is evidence management only and must not run the
        // completion/phase-advance side effects for a second time.
        if ($task->completed_at || strcasecmp(trim((string) $task->status), 'Completed') === 0) return;

        if ($key === 'ART_SAMPLE_APPROVAL') {
            $this->complete($task, $actor);
            $waiting = Task::query()
                ->where('flow_job_id', $task->flow_job_id)
                ->where('workflow_phase_id', $task->workflow_phase_id)
                ->where('status', 'Waiting for Sample Approval')
                ->with('setupTemplate')
                ->get()
                ->first(fn (Task $candidate) => $this->automationKey($candidate) === 'ART_CLIENT_ERP_DECISION');
            if ($waiting) $this->complete($waiting, $actor);
            return;
        }

        // PO and artwork upload are file-backed actions in the prototype.
        $this->complete($task, $actor);
    }

    private function complete(Task $task, User $actor): Task
    {
        $alreadyCompleted = (bool) $task->completed_at;
        $key = $this->automationKey($task);
        $completed = app(TaskService::class)->moveStatus($task, app(OrderTaskFlagService::class)->completedStatus(), $actor);
        if (! $alreadyCompleted && $completed->completed_at && $key) {
            // Optional notifications must never undo a successfully completed order action.
            try {
                app(\App\Services\Reminders\SupplierArtworkReminderService::class)->queueForCompletion($completed, $key);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        return $completed;
    }

    private function isCompletedWorkflowTask(Task $task): bool
    {
        return (bool) $task->completed_at
            || strcasecmp(trim((string) $task->status), 'Completed') === 0;
    }

    /**
     * Reopen Artwork preparation for one or more current artwork files.
     * Every selected artwork carries its own required-change note and can carry
     * its own set of supporting attachments. Unselected artwork is preserved in
     * the next version by DocumentService.
     *
     * @param array<string,mixed> $payload
     */
    private function restartArtwork(
        Task $current,
        User $actor,
        string $description,
        array $payload = [],
        string $legacyComment = '',
    ): Activity {
        $job = $current->job;
        $tasks = Task::query()
            ->where('flow_job_id', $current->flow_job_id)
            ->where('workflow_phase_id', $current->workflow_phase_id)
            ->whereNotNull('task_pack_task_id')
            ->with('setupTemplate')
            ->get();
        $upload = $tasks->first(fn (Task $candidate) => $this->automationKey($candidate) === 'ART_PREPARE_UPLOAD');
        abort_unless($upload, 422, 'Artwork upload task is not configured.');

        $latestDocuments = app(DocumentService::class)->currentArtworkDocuments($upload);
        $latestVersion = max(0, (int) ($latestDocuments->max('version') ?? 0));

        if ($latestDocuments->isEmpty()) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.revision_document_ids' => 'No current artwork files are available to revise.',
            ]);
        }

        $requestedIds = collect($payload['revision_document_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        // Backward compatibility with an already-open dialog from the previous
        // single-artwork implementation.
        if ($requestedIds->isEmpty()) {
            $legacyId = (int) ($payload['revision_document_id'] ?? 0);
            if ($legacyId > 0) $requestedIds = collect([$legacyId]);
        }

        if ($requestedIds->isEmpty()) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.revision_document_ids' => 'Select at least one artwork file that needs revision.',
            ]);
        }

        $latestIds = $latestDocuments->pluck('id')->map(fn ($id) => (int) $id);
        if ($requestedIds->contains(fn ($id) => ! $latestIds->contains((int) $id))) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.revision_document_ids' => 'One of the selected artwork files is no longer part of the latest artwork set. Reopen the review and try again.',
            ]);
        }

        $revisionDocuments = $latestDocuments
            ->filter(fn (Document $document) => $requestedIds->contains((int) $document->id))
            ->values();
        $revisionDocumentIds = $revisionDocuments->pluck('id')->map(fn ($id) => (int) $id)->all();

        $payloadItems = collect($payload['revision_items'] ?? [])
            ->mapWithKeys(function ($item) {
                $id = (int) data_get($item, 'document_id', 0);
                return $id > 0 ? [$id => (array) $item] : [];
            });

        $richText = app(RichTextService::class);
        $revisionItems = [];
        $mentionIds = collect();
        foreach ($revisionDocuments as $document) {
            $documentId = (int) $document->id;
            $rawComment = trim((string) data_get($payloadItems->get($documentId, []), 'comment', ''));
            if ($rawComment === '' && count($revisionDocumentIds) === 1) {
                $rawComment = trim($legacyComment);
            }

            $comment = $richText->normalize(
                $rawComment,
                10000,
                'orderWorkflowActionRevisionComments.'.$documentId,
            ) ?? '';
            if (trim((string) $richText->withoutImages($comment)) === '' && $richText->imageAttachments($comment) === []) {
                throw ValidationException::withMessages([
                    'orderWorkflowActionRevisionComments.'.$documentId => 'Describe the required change for this artwork.',
                ]);
            }

            $itemMentionIds = app(MentionService::class)->userIdsFromText($comment);
            $mentionIds = $mentionIds->merge($itemMentionIds);
            $revisionItems[] = [
                'document_id' => $documentId,
                'document_name' => (string) $document->name,
                'comment' => $comment,
                'mention_user_ids' => array_values(array_unique(array_map('intval', $itemMentionIds))),
                'revision_attachment_document_ids' => [],
                'revision_attachment_document_names' => [],
            ];
        }
        $mentionIds = $mentionIds->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        $ready = app(OrderTaskFlagService::class)->readyStatus();
        $notStarted = app(OrderTaskFlagService::class)->notStartedStatus();
        $upload->update([
            'status' => $ready,
            'order_task_status_id' => app(OrderTaskFlagService::class)->statusRecord($ready, false)?->id,
            'completed_at' => null,
            'progress' => 0,
            'start_date' => app(WorkspaceSettingsService::class)->localToday(),
        ]);
        foreach ($tasks as $candidate) {
            if ((int) $candidate->id === (int) $upload->id) continue;
            $candidate->update([
                'status' => $notStarted,
                'order_task_status_id' => app(OrderTaskFlagService::class)->statusRecord($notStarted, false)?->id,
                'completed_at' => null,
                'progress' => 0,
            ]);
        }

        $referenceDocumentId = (int) ($revisionDocuments->last()?->id ?? 0);
        $legacyRevisionComment = count($revisionItems) === 1
            ? (string) ($revisionItems[0]['comment'] ?? '')
            : collect($revisionItems)
                ->map(fn ($item) => ($item['document_name'] ?? 'Artwork').': '.trim((string) ($item['comment'] ?? '')))
                ->implode("\n");
        $activityDescription = count($revisionItems) === 1
            ? $richText->prependText($description.':', $legacyRevisionComment)
            : $description.' for '.count($revisionItems).' artworks.';

        $activity = $job?->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.artwork_revision_requested',
            'description' => $activityDescription,
            'meta' => [
                'revision_comment' => $legacyRevisionComment,
                'revision_items' => $revisionItems,
                'mention_user_ids' => $mentionIds,
                'source_task_id' => (int) $current->id,
                'target_task_id' => (int) $upload->id,
                'workflow_phase_id' => (int) $current->workflow_phase_id,
                'reference_document_id' => $referenceDocumentId > 0 ? $referenceDocumentId : null,
                'revision_document_ids' => $revisionDocumentIds,
                'revision_document_id' => count($revisionDocumentIds) === 1 ? $revisionDocumentIds[0] : null,
                'revision_document_names' => $revisionDocuments->pluck('name')->values()->all(),
                'revision_selection_pending' => false,
                'source_artwork_version' => $latestVersion,
                'source_artwork_document_ids' => $latestDocuments->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            ],
        ]);

        abort_unless($activity, 500, 'The artwork revision activity could not be recorded.');

        app(NotificationService::class)->notifyMentionedUsers(
            $mentionIds,
            $actor->name.' mentioned you in '.$job->displayOrderNumber(),
            $legacyRevisionComment,
            $job,
            $upload,
            $actor,
        );

        return $activity;
    }

    /**
     * Store supporting files under the review task, grouped by the artwork they
     * explain. A flat attachment id list is also kept for backward-compatible
     * activity rendering and exports.
     *
     * @param array<int|string,mixed> $attachments
     */
    private function storeArtworkRevisionAttachments(Activity $activity, Task $reviewTask, User $actor, array $attachments): void
    {
        $meta = (array) $activity->meta;
        $revisionItems = collect($meta['revision_items'] ?? [])->values();
        if ($revisionItems->isEmpty()) return;

        // Compatibility with callers that still pass one flat file array.
        $isFlat = collect($attachments)->filter()->contains(
            fn ($value) => is_object($value) && method_exists($value, 'getClientOriginalName')
        );
        if ($isFlat) {
            $firstDocumentId = (int) data_get($revisionItems->first(), 'document_id', 0);
            $attachments = $firstDocumentId > 0 ? [$firstDocumentId => $attachments] : [];
        }

        $allStored = collect();
        $updatedItems = $revisionItems->map(function ($item) use ($attachments, $reviewTask, $actor, $allStored) {
            $item = (array) $item;
            $documentId = (int) ($item['document_id'] ?? 0);
            $files = array_values(array_filter((array) ($attachments[$documentId] ?? $attachments[(string) $documentId] ?? [])));
            if ($files === []) return $item;

            $documents = app(DocumentService::class)->storeMany($files, [
                'flow_job_id' => $reviewTask->flow_job_id,
                'client_id' => $reviewTask->job?->client_id,
                'task_id' => $reviewTask->id,
                'category' => 'Artwork revision evidence',
                'note' => 'Supporting attachment for artwork revision request: '.($item['document_name'] ?? 'Artwork').'.',
            ], $actor, 'documents', AttachmentUpload::ARTWORK_MAX_BYTES);

            foreach ($documents as $document) $allStored->push($document);
            $item['revision_attachment_document_ids'] = $documents->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $item['revision_attachment_document_names'] = $documents->pluck('name')->values()->all();
            return $item;
        })->values()->all();

        $meta['revision_items'] = $updatedItems;
        $meta['revision_attachment_document_ids'] = $allStored->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $meta['revision_attachment_document_names'] = $allStored->pluck('name')->values()->all();
        $activity->update(['meta' => $meta]);
    }

    private function loadedEvidenceState(Task $task): bool
    {
        if ($task->relationLoaded('documents') && $task->documents->isNotEmpty()) return true;
        if ($task->relationLoaded('links') && $task->links->isNotEmpty()) return true;
        return false;
    }

    private function requireComment(string $comment, string $message): void
    {
        if ($comment === '') {
            throw ValidationException::withMessages(['orderWorkflowActionComment' => $message]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function validateQcPayload(array $payload): void
    {
        $received = max(0, (int) ($payload['qty_received'] ?? 0));
        $inspected = max(0, (int) ($payload['qty_inspected'] ?? 0));
        $accepted = max(0, (int) ($payload['qty_accepted'] ?? 0));
        $rejected = max(0, (int) ($payload['qty_rejected'] ?? 0));

        $errors = [];
        if ($received <= 0) {
            $errors['orderWorkflowActionPayload.qty_received'] = 'Quantity received is required.';
        }
        if ($inspected <= 0) {
            $errors['orderWorkflowActionPayload.qty_inspected'] = 'Quantity inspected is required.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($inspected > $received || ($accepted + $rejected) > $inspected) {
            throw ValidationException::withMessages(['orderWorkflowActionPayload.qty_inspected' => 'QC quantities are inconsistent.']);
        }
    }

    /** @param array<string,mixed> $payload */
    private function qcMeta(array $payload): array
    {
        return $this->onlyPayload($payload, ['qty_received','qty_inspected','qty_accepted','qty_rejected','qc_comments','issue_category']);
    }

    /** @param array<string,mixed> $payload */
    private function validateShipmentInfo(array $payload): void
    {
        $errors = $this->requiredPayloadErrors($payload, [
            'client_name' => 'Client name',
            'contact_name' => 'Contact person',
            'phone_country_code' => 'Country code',
            'phone_number' => 'Phone number',
            'address' => 'Shipping address',
            'country' => 'Country',
            'postal_code' => 'Postal code',
        ]);

        $masterData = app(MasterDataService::class);
        $countryCode = trim((string) ($payload['phone_country_code'] ?? ''));
        $country = trim((string) ($payload['country'] ?? ''));

        if ($countryCode !== '' && ! $masterData->active('phone_country_code')->contains(
            fn ($record) => strcasecmp(trim((string) $record->name), $countryCode) === 0
        )) {
            $errors['orderWorkflowActionPayload.phone_country_code'] = 'Choose an active phone country code from Master Data.';
        }

        if ($country !== '' && ! $masterData->active('country')->contains(
            fn ($record) => strcasecmp(trim((string) $record->name), $country) === 0
        )) {
            $errors['orderWorkflowActionPayload.country'] = 'Choose an active country from Master Data.';
        }

        $this->throwPayloadErrors($errors);
    }

    /** @return array<string,mixed> */
    private function shipmentContactPayload(FlowJob $job): array
    {
        $job->loadMissing([
            'client.contacts:id,client_id,name,job_title,phone,is_primary,sort_order',
            'client.deliveryContacts:id,client_id,contact_type,name,phone_country_code,phone,last_used_at',
            'client.shippingAddresses:id,client_id,label,recipient,address_line1,suite,city,state,zip,country,is_default,sort_order',
            'shippingSourceAddress:id,client_id,label,recipient,address_line1,suite,city,state,zip,country,is_default,sort_order',
        ]);

        $client = $job->client;
        $latestShipmentActivity = $job->activities()
            ->where('event', 'job.shipment_information_confirmed')
            ->latest('id')
            ->first();
        $latestShipmentMeta = (array) ($latestShipmentActivity?->meta ?? []);

        $contactType = trim((string) ($latestShipmentMeta['contact_type'] ?? ($job->shipping_contact_type ?: 'middle_client')));
        if (! in_array($contactType, ['middle_client', 'end_customer', 'other_contact'], true)) {
            $contactType = 'middle_client';
        }

        $contactOptions = collect();
        if ($client) {
            foreach ($client->contacts as $contact) {
                [$countryCode, $phone] = $this->splitShipmentPhone((string) ($contact->phone ?? ''));
                $contactOptions->push([
                    'value' => 'middle_client:'.$contact->id,
                    'label' => trim($contact->name.($contact->job_title ? ' · '.$contact->job_title : '')),
                    'contact_type' => 'middle_client',
                    'name' => (string) $contact->name,
                    'country_code' => $countryCode,
                    'phone' => $phone,
                ]);
            }
            foreach ($client->deliveryContacts as $contact) {
                $contactOptions->push([
                    'value' => $contact->contact_type.':'.$contact->id,
                    'label' => (string) $contact->name,
                    'contact_type' => (string) $contact->contact_type,
                    'name' => (string) $contact->name,
                    'country_code' => (string) ($contact->phone_country_code ?? ''),
                    'phone' => (string) ($contact->phone ?? ''),
                ]);
            }
        }

        $contactName = trim((string) ($latestShipmentMeta['contact_name'] ?? ($job->shipping_contact_name ?: $client?->contact_name ?: $client?->name ?: '')));
        $selectedContact = $contactOptions->first(function (array $option) use ($contactName, $contactType): bool {
            return $option['contact_type'] === $contactType
                && mb_strtolower(trim((string) $option['name'])) === mb_strtolower($contactName);
        });

        if (! $selectedContact && $contactName !== '') {
            $contactOptions->prepend([
                'value' => 'current',
                'label' => $contactName,
                'contact_type' => $contactType,
                'name' => $contactName,
                'country_code' => (string) ($latestShipmentMeta['phone_country_code'] ?? $job->shipping_phone_country_code ?? ''),
                'phone' => (string) ($latestShipmentMeta['phone_number'] ?? $job->shipping_phone ?? ''),
            ]);
            $selectedContact = $contactOptions->first();
        }

        $sourceAddress = $job->shippingSourceAddress;
        $addressOptions = collect();
        if ($client) {
            foreach ($client->shippingAddresses as $address) {
                $addressOptions->push([
                    'value' => (string) $address->id,
                    'label' => trim((string) ($address->label ?: $address->recipient ?: 'Saved address')),
                    'address' => $this->shipmentAddressText($address),
                    'city' => (string) ($address->city ?? ''),
                    'state' => (string) ($address->state ?? ''),
                    'country' => (string) ($address->country ?? ''),
                    'postal_code' => (string) ($address->zip ?? ''),
                    'is_default' => (bool) $address->is_default,
                ]);
            }
        }

        $selectedAddress = $sourceAddress?->id ? (string) $sourceAddress->id : '';
        if ($selectedAddress === '' && $addressOptions->isNotEmpty()) {
            $selectedAddress = (string) (($addressOptions->firstWhere('is_default', true) ?? $addressOptions->first())['value'] ?? '');
        }

        $countryCode = trim((string) ($latestShipmentMeta['phone_country_code'] ?? ($job->shipping_phone_country_code ?: data_get($selectedContact, 'country_code', ''))));
        $phoneNumber = trim((string) ($latestShipmentMeta['phone_number'] ?? ($job->shipping_phone ?: data_get($selectedContact, 'phone', ''))));
        $address = trim((string) ($latestShipmentMeta['address'] ?? ($job->shipping_address ?: ($sourceAddress ? $this->shipmentAddressText($sourceAddress) : ''))));
        $masterData = app(MasterDataService::class);
        $phoneCountryCodeOptions = $masterData->active('phone_country_code')
            ->map(fn ($record) => [
                'id' => (string) $record->name,
                'label' => (string) $record->name,
                'meta' => trim((string) ($record->description ?? '')),
            ])
            ->values()
            ->all();
        $countryOptions = $masterData->active('country')
            ->map(fn ($record) => [
                'id' => (string) $record->name,
                'label' => (string) $record->name,
                'meta' => trim((string) ($record->code ?? '')),
            ])
            ->values()
            ->all();

        return [
            'client_name' => trim((string) ($latestShipmentMeta['client_name'] ?? ($client?->name ?: 'Client'))),
            'contact_name' => $contactName,
            'contact_type' => $contactType,
            'contact_selection' => (string) data_get($selectedContact, 'value', 'current'),
            'contact_options' => $contactOptions->values()->all(),
            'phone_country_code' => $countryCode,
            'phone_country_code_options' => $phoneCountryCodeOptions,
            'phone_number' => $phoneNumber,
            'address' => $address,
            'city' => (string) ($latestShipmentMeta['city'] ?? $sourceAddress?->city ?? ''),
            'state' => (string) ($latestShipmentMeta['state'] ?? $sourceAddress?->state ?? ''),
            'country' => (string) ($latestShipmentMeta['country'] ?? $sourceAddress?->country ?? $client?->country ?? ''),
            'country_options' => $countryOptions,
            'postal_code' => trim((string) ($latestShipmentMeta['postal_code'] ?? ($job->shipping_postal_code ?: $sourceAddress?->zip ?: ''))),
            'address_selection' => $selectedAddress,
            'address_options' => $addressOptions->values()->all(),
            'update_saved_contact' => false,
            // Compatibility keys retained for the label preview and audit data.
            'recipient' => $contactName,
            'contact' => trim($countryCode.' '.$phoneNumber),
        ];
    }

    /** @return array{0:string,1:string} */
    private function splitShipmentPhone(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^(\+\d{1,4})[\s-]*(.*)$/', $value, $matches) === 1) {
            return [trim((string) $matches[1]), trim((string) $matches[2])];
        }

        return ['', $value];
    }

    private function shipmentAddressText(object $address): string
    {
        $street = trim(collect([$address->address_line1 ?? null, $address->suite ?? null])->filter()->implode(', '));
        $locality = trim(collect([$address->city ?? null, $address->state ?? null, $address->country ?? null])->filter()->implode(', '));

        return collect([$street, $locality])->filter()->implode("\n");
    }

    /** @param array<string,mixed> $payload */
    private function updateSavedShipmentContact(FlowJob $job, User $actor, array $payload): void
    {
        if (! $job->client) return;

        $type = (string) ($payload['contact_type'] ?? 'middle_client');
        $name = (string) ($payload['contact_name'] ?? '');
        $countryCode = (string) ($payload['phone_country_code'] ?? '');
        $phone = (string) ($payload['phone_number'] ?? '');

        if ($type === 'middle_client') {
            (new \App\Actions\Clients\SaveClientOrderContact())->execute($job->client, $name, $countryCode, $phone);
            return;
        }

        if (in_array($type, ['end_customer', 'other_contact'], true)) {
            (new \App\Actions\Clients\SaveClientDeliveryContact())->execute($actor, $job->client, $type, $name, $countryCode, $phone);
        }
    }

    private function validateShipmentCourier(string $carrier): void
    {
        $normalized = mb_strtolower(trim($carrier));
        $allowed = app(MasterDataService::class)->active('courier')
            ->contains(fn ($record) => mb_strtolower(trim((string) $record->name)) === $normalized);

        if (! $allowed) {
            throw ValidationException::withMessages([
                'shipmentLabel' => 'Choose an active courier from Master Data.',
            ]);
        }
    }

    private function nextWorkflowInvoiceNumber(FlowJob $job): string
    {
        $workflowSequence = (int) $job->activities()
            ->where('event', 'job.workflow_invoice_prepared')
            ->count();
        $financeSequence = (int) \App\Models\Invoice::query()
            ->where('flow_job_id', $job->id)
            ->max('sequence');
        $sequence = max($workflowSequence, $financeSequence) + 1;

        return sprintf('INV-%05d-%02d', (int) $job->id, $sequence);
    }

    /** @param array<string,mixed> $payload */
    private function validateShipPackage(array $payload): void
    {
        $errors = $this->requiredPayloadErrors($payload, [
            'carrier' => 'Shipping provider',
            'tracking_number' => 'Tracking number',
            'shipment_date' => 'Shipment date',
        ]);
        $this->throwPayloadErrors($errors);
    }

    /** @param array<string,mixed> $payload */
    private function validateInvoice(array $payload): void
    {
        $errors = $this->requiredPayloadErrors($payload, [
            'invoice_number' => 'Invoice number',
            'invoice_date' => 'Invoice date',
            'invoice_amount' => 'Invoice amount',
            'invoice_due_date' => 'Due date',
        ]);

        if (! isset($errors['orderWorkflowActionPayload.invoice_amount'])
            && (float) ($payload['invoice_amount'] ?? 0) <= 0) {
            $errors['orderWorkflowActionPayload.invoice_amount'] = 'Invoice amount must be greater than zero.';
        }

        $this->throwPayloadErrors($errors);
    }

    /** @param array<string,mixed> $payload */
    private function validatePayment(array $payload): void
    {
        $errors = $this->requiredPayloadErrors($payload, [
            'payment_amount' => 'Payment amount',
            'payment_date' => 'Payment date',
            'payment_reference' => 'Payment reference',
        ]);

        if (! isset($errors['orderWorkflowActionPayload.payment_amount'])
            && (float) ($payload['payment_amount'] ?? 0) <= 0) {
            $errors['orderWorkflowActionPayload.payment_amount'] = 'Payment amount must be greater than zero.';
        }

        $this->throwPayloadErrors($errors);
    }

    /**
     * Return all missing workflow payload fields at once so Livewire can render
     * every validation message directly beneath the field that needs attention.
     *
     * @param array<string,mixed> $payload
     * @param array<string,string> $fields
     * @return array<string,string>
     */
    private function requiredPayloadErrors(array $payload, array $fields): array
    {
        $errors = [];
        foreach ($fields as $key => $label) {
            if (trim((string) ($payload[$key] ?? '')) === '') {
                $errors['orderWorkflowActionPayload.'.$key] = $label.' is required.';
            }
        }
        return $errors;
    }

    /** @param array<string,string> $errors */
    private function throwPayloadErrors(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function orderTotal(FlowJob $job): float
    {
        if (! $job->relationLoaded('items')) $job->load('items');
        return round((float) $job->items
            ->filter(fn ($item) => ! ($item->is_removed ?? false))
            ->sum(fn ($item) => (float) ($item->unit_price ?? 0) * (int) ($item->quantity ?? 0)), 2);
    }

    private function recordedPaymentTotal(FlowJob $job): float
    {
        return round((float) $job->activities()
            ->where('event', 'job.workflow_payment_recorded')
            ->get(['meta'])
            ->sum(fn ($activity) => (float) data_get($activity->meta, 'payment_amount', 0)), 2);
    }

    /** @param array<string,mixed> $payload @param array<int,string> $keys */
    private function onlyPayload(array $payload, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) $out[$key] = $payload[$key] ?? null;
        return $out;
    }
}
