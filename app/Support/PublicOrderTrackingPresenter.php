<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\Invoice;
use App\Models\OrderShipment;
use App\Models\Task;
use App\Services\OrderWorkflowSetupService;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Query-free presenter for the public Order tracking screen.
 *
 * PublicOrderTrackingQuery owns every database read. This class consumes only
 * relations already eager-loaded by that query so rendering cannot introduce
 * N+1 or hidden database reads.
 */
final class PublicOrderTrackingPresenter
{
    /** @return array<string,mixed> */
    public function present(FlowJob $job): array
    {
        $resolvedStage = OrderStageResolver::resolve(
            $job->phase?->name,
            $job->phase?->short_name,
            $job->phase?->sequence,
            $job->status,
        );
        $currentSequence = (int) $resolvedStage['sequence'];
        $workflowCompleted = $job->completed_at !== null || strcasecmp(trim((string) $job->status), 'Completed') === 0;
        $currentTask = $this->currentTask($job);
        $shipment = $this->displayShipment($job);
        $shipmentState = $this->shipmentState($job);
        $latestInvoice = $job->invoices->first();
        $paymentState = $this->paymentState($job, $latestInvoice);
        $billingState = $this->billingState($job, $latestInvoice);
        $publicOrderCompleted = $workflowCompleted && $shipmentState === 'delivered' && $paymentState === 'paid';

        $publicStatus = $this->publicStatus(
            $job,
            $currentSequence,
            $currentTask,
            $shipmentState,
            $billingState,
            $paymentState,
            $publicOrderCompleted,
        );
        $shipmentData = $this->shipmentData($job, $shipment);
        $shipmentRows = $this->shipmentRows($job);

        $stages = collect(OrderWorkflowSetupService::fixedStages())
            ->values()
            ->map(function (array $stage, int $index) use ($job, $currentSequence, $publicOrderCompleted, $publicStatus): array {
                $sequence = $index + 1;
                $state = $publicOrderCompleted || $sequence < $currentSequence
                    ? 'completed'
                    : ($sequence === $currentSequence ? 'current' : 'upcoming');

                return [
                    'sequence' => $sequence,
                    'name' => (string) $stage['name'],
                    'state' => $state,
                    'status' => match ($state) {
                        'completed' => 'Completed',
                        'current' => 'Current · In progress',
                        default => 'Upcoming',
                    },
                    'progress' => match ($state) {
                        'completed' => 100,
                        'current' => $this->currentStageProgress($job),
                        default => 0,
                    },
                    'icon' => $this->stageIcon($sequence),
                    'description' => $this->stageDescription($sequence, $state, $publicStatus['message']),
                    'marker' => $state === 'completed'
                        ? 'check'
                        : ($state === 'current' && $sequence === 1
                            ? 'dot'
                            : ($state === 'current' && $sequence === 5 && in_array($publicStatus['key'], ['dispatched', 'partial-dispatched', 'partial-delivered', 'in-transit', 'delivered'], true) ? 'check' : 'none')),
                ];
            })
            ->all();

        return [
            'order_number' => $job->displayOrderNumber(),
            'reference_number' => trim((string) $job->order_number),
            'last_updated' => $this->formatDateTimeInline($this->lastUpdated($job)),
            'sample_order' => $job->activities->contains(fn (Activity $activity) => $activity->event === 'job.sample_required'),
            'current_stage' => $currentSequence,
            'screen_variant' => $this->screenVariant($currentSequence),
            'current_status' => $publicStatus,
            'summary' => [
                'delivery' => $this->deliverySummary($currentSequence, $shipmentState),
                'billing' => $this->billingSummary($currentSequence, $shipmentState, $billingState),
                'payment' => $this->paymentSummary($currentSequence, $paymentState),
            ],
            'stages' => $stages,
            'timeline' => $this->timeline($currentSequence, $stages, $publicStatus, $shipmentState),
            'shipment' => $shipmentData,
            'shipment_rows' => $shipmentRows,
            'next' => $this->nextStep($currentSequence, $publicStatus, $shipmentData, $publicOrderCompleted),
            'payment_detail' => $this->paymentDetail($paymentState),
            'public_order_completed' => $publicOrderCompleted,
            // Variant catalogue is prototype documentation. Keep it out of the
            // live public result so alternate workflow states are not exposed.
            'design_preview' => false,
            'status_variants' => [],
        ];
    }

    private function screenVariant(int $stage): string
    {
        return match ($stage) {
            1 => 'new-order',
            2 => 'artwork',
            3 => 'production',
            4 => 'qc',
            5 => 'shipment',
            6 => 'billing',
            default => 'payment',
        };
    }

    /** @return array{key:string,title:string,message:string,icon:string,tone:string,action:?string} */
    private function publicStatus(
        FlowJob $job,
        int $stage,
        ?Task $task,
        string $shipmentState,
        string $billingState,
        string $paymentState,
        bool $publicOrderCompleted,
    ): array {
        if ($publicOrderCompleted) {
            return $this->status('order-completed', 'Order completed', 'Your order is delivered and payment is confirmed.', 'check', 'success');
        }

        $key = $this->taskKey($task);
        $taskStatus = Str::lower(trim((string) ($task?->status ?? '')));

        if ($stage === 1) {
            if ($key === 'NEW_SEND_PO_ARTWORK' || $this->activity($job, 'job.purchase_order_emailed_to_artwork_team')) {
                return $this->status('preparing-artwork', 'Preparing artwork', 'Your order is being sent to our artwork team.', 'palette', 'purple');
            }

            if ($task && ((int) $task->progress > 0 || ! $this->isInitialTaskStatus($taskStatus))) {
                return $this->status('order-review', 'Order being reviewed', 'We are reviewing your order details.', 'eye', 'info');
            }

            return $this->status('order-received', 'Order received', 'We have received your order.', 'document', 'success');
        }

        if ($stage === 2) {
            $revisionRequested = $this->activity($job, 'job.artwork_revision_requested') !== null;
            $erpUploaded = $this->activity($job, 'job.artwork_client_erp_uploaded') !== null;

            if ($key === 'ART_PREPARE_UPLOAD' && $revisionRequested) {
                return $this->status('artwork-revision', 'Artwork revision in progress', 'We are updating your artwork based on your feedback.', 'document', 'purple');
            }

            if ($key === 'ART_PREPARE_UPLOAD') {
                return $this->status('artwork-progress', 'Artwork in progress', 'We are preparing your artwork.', 'document', 'purple');
            }

            if (in_array($key, ['ART_INTERNAL_REVIEW', 'ART_SEND_ORDER_TEAM'], true)) {
                $message = $key === 'ART_SEND_ORDER_TEAM'
                    ? 'Your artwork is being prepared for approval.'
                    : 'Our team is checking your artwork.';

                return $this->status('artwork-review', 'Artwork under review', $message, 'document', 'purple-soft');
            }

            if ($key === 'ART_CLIENT_ERP_DECISION' && (str_contains($taskStatus, 'waiting for client') || $erpUploaded)) {
                return $this->status('artwork-approval', 'Awaiting artwork approval', 'Please review and approve your artwork.', 'alert', 'warning', 'review-artwork');
            }

            if ($key === 'ART_SAMPLE_APPROVAL' || str_contains($taskStatus, 'waiting for sample')) {
                return $this->status('artwork-approved', 'Artwork approved', 'Your artwork is approved. We are preparing for production.', 'document', 'success');
            }

            return $this->status('artwork-review', 'Artwork under review', 'Our team is checking your artwork.', 'document', 'purple-soft');
        }

        if ($stage === 3) {
            if ($this->taskFinishedByKey($job, 'PROD_FINISH')) {
                return $this->status('production-completed', 'Production completed', 'Production is complete. Your order is ready for quality checking.', 'check', 'success');
            }

            if ($this->taskHasOpenIssue($this->taskByKey($job, 'PROD_ISSUE'))) {
                return $this->status('production-hold', 'Production on hold', 'Production is temporarily on hold. We will share an update.', 'stop', 'pink');
            }

            if ($key === 'PROD_SET_ESTIMATED_DELIVERY') {
                return $this->status('production-scheduling', 'Scheduling production', 'We are confirming the production schedule.', 'calendar', 'info');
            }

            if ($key === 'PROD_START') {
                return $this->status('production-ready', 'Ready for production', 'Your order is scheduled for production.', 'clock', 'purple');
            }

            return $this->status('production', 'In production', 'Your order is being made.', 'gear', 'orange');
        }

        if ($stage === 4) {
            $qcIssue = $this->taskByKey($job, 'QC_ISSUE');
            if ($this->taskHasOpenIssue($qcIssue) || $key === 'QC_ISSUE') {
                return $this->status('quality-issue', 'Quality issue being resolved', 'We are resolving a quality issue before shipment.', 'warning-triangle', 'orange');
            }

            if ($this->taskFinishedByKey($job, 'QC_CHECK') || $key === 'QC_APPROVE_SHIPMENT') {
                return $this->status('quality-passed', 'Quality check passed', 'Your order has passed quality checking.', 'check', 'success');
            }

            return $this->status('quality-check', 'Quality check in progress', 'We are checking the quality of your order.', 'search', 'teal');
        }

        if ($stage === 5) {
            if ($shipmentState === 'delivered') {
                return $this->status('delivered', 'Delivered', 'Your shipments have been delivered.', 'truck', 'info');
            }
            if ($shipmentState === 'partial-delivered') {
                return $this->status('partial-delivered', 'Partially delivered', 'Some shipments have been delivered.', 'truck', 'success');
            }
            if ($shipmentState === 'in-transit') {
                return $this->status('in-transit', 'In transit', 'Your shipment is on its way.', 'truck', 'orange');
            }
            if ($shipmentState === 'partial-dispatched') {
                return $this->status('partial-dispatched', 'Partially dispatched', 'Some shipments have been handed to the courier.', 'truck', 'pink');
            }
            if ($shipmentState === 'dispatched') {
                return $this->status('dispatched', 'Dispatched', 'Your order has been handed to the courier.', 'truck', 'success');
            }
            if (in_array($key, ['SHIP_LABEL', 'SHIP_PACKAGE'], true)) {
                return $this->status('shipment-ready', 'Ready for shipment', 'Your order is ready to be handed to the courier.', 'truck', 'purple');
            }

            return $this->status('shipment-preparing', 'Preparing shipment', 'We are confirming the shipment details.', 'truck', 'info');
        }

        if ($stage === 6) {
            return match ($billingState) {
                'sent' => $this->status('invoice-sent', 'Invoice sent', 'Your invoice has been sent to your registered email.', 'billing', 'pink'),
                'ready' => $this->status('invoice-ready', 'Invoice ready', 'Your invoice is ready and will be sent to you.', 'billing', 'pink'),
                default => $this->status('invoice-preparing', 'Preparing invoice', 'We are preparing your invoice.', 'billing', 'pink'),
            };
        }

        return match ($paymentState) {
            'paid' => $this->status('paid', 'Paid', 'Your payment has been confirmed.', 'payment', 'success'),
            'partial' => $this->status('partial-payment', 'Partially paid', 'A payment has been received. A balance remains.', 'billing', 'pink', 'payment-instructions'),
            'review' => $this->status('payment-review', 'Payment under review', 'We are checking your payment.', 'clock', 'orange'),
            default => $this->status('awaiting-payment', 'Awaiting payment', 'Payment is pending for your order.', 'payment', 'info', 'payment-instructions'),
        };
    }

    /** @return array{key:string,title:string,message:string,icon:string,tone:string,action:?string} */
    private function status(string $key, string $title, string $message, string $icon, string $tone, ?string $action = null): array
    {
        return compact('key', 'title', 'message', 'icon', 'tone', 'action');
    }

    private function currentTask(FlowJob $job): ?Task
    {
        return $job->tasks
            ->filter(fn (Task $task) => (int) $task->workflow_phase_id === (int) $job->workflow_phase_id)
            ->sortBy(fn (Task $task) => [(int) ($task->setupTemplate?->sort_order ?? 999999), (int) $task->id])
            ->first(fn (Task $task) => ! $this->taskIsFinished($task));
    }

    private function taskByKey(FlowJob $job, string $wanted): ?Task
    {
        return $job->tasks->first(fn (Task $task) => $this->taskKey($task) === $wanted);
    }

    private function taskFinishedByKey(FlowJob $job, string $key): bool
    {
        $task = $this->taskByKey($job, $key);
        return $task ? $this->taskIsFinished($task) : false;
    }

    private function taskKey(?Task $task): string
    {
        if (! $task) return '';

        $configured = Str::upper(trim((string) ($task->setupTemplate?->automation_key ?? '')));
        if ($configured !== '') return $configured;

        return match (Str::lower(trim((string) $task->title))) {
            'upload purchase order' => 'NEW_UPLOAD_PO',
            'send purchase order to artwork team' => 'NEW_SEND_PO_ARTWORK',
            'prepare & upload artwork', 'prepare and upload artwork' => 'ART_PREPARE_UPLOAD',
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
            'confirm shipment information', 'confirm shipping information', 'review shipment info', 'review or update shipment details' => 'SHIP_CONFIRM_INFO',
            'generate & print courier label', 'generate courier label', 'preview & print courier label', 'add tracking number & print courier label' => 'SHIP_LABEL',
            'ship package', 'ship the package', 'dispatch shipment' => 'SHIP_PACKAGE',
            'prepare invoice' => 'BILL_PREPARE',
            'send invoice' => 'BILL_SEND',
            'receive & process payment' => 'PAY_PROCESS',
            default => '',
        };
    }

    private function taskIsFinished(Task $task): bool
    {
        if ($task->completed_at || strcasecmp(trim((string) $task->status), 'Completed') === 0) return true;

        return in_array(Str::lower(trim((string) $task->status)), ['skipped', 'not applicable', 'n/a'], true);
    }

    private function taskHasOpenIssue(?Task $task): bool
    {
        if (! $task || $this->taskIsFinished($task)) return false;

        $status = Str::lower(trim((string) $task->status));
        return Str::contains($status, ['issue', 'hold', 'blocked', 'resolution']);
    }

    private function isInitialTaskStatus(string $status): bool
    {
        return in_array($status, ['', 'not start', 'not started', 'not ready', 'ready', 'locked'], true);
    }

    private function currentStageProgress(FlowJob $job): int
    {
        $tasks = $job->tasks
            ->filter(fn (Task $task) => (int) $task->workflow_phase_id === (int) $job->workflow_phase_id)
            ->reject(fn (Task $task) => in_array(Str::lower(trim((string) $task->status)), ['skipped', 'not applicable', 'n/a'], true))
            ->sortBy(fn (Task $task) => [(int) ($task->setupTemplate?->sort_order ?? 999999), (int) $task->id])
            ->values();

        if ($tasks->isEmpty()) return 20;

        $completed = $tasks->filter(fn (Task $task) => $this->taskIsFinished($task))->count();
        $active = $tasks->first(fn (Task $task) => ! $this->taskIsFinished($task));
        $activeProgress = max(0, min(99, (int) ($active?->progress ?? 0)));
        $progress = (($completed + ($activeProgress / 100)) / max(1, $tasks->count())) * 100;

        return max(8, min(95, (int) round($progress)));
    }

    private function stageIcon(int $sequence): string
    {
        return match ($sequence) {
            1, 5 => 'truck',
            2 => 'document',
            3 => 'gear',
            4 => 'search',
            6 => 'billing',
            default => 'payment',
        };
    }

    private function stageDescription(int $sequence, string $state, string $currentMessage): string
    {
        if ($state === 'current') return $currentMessage;
        if ($state === 'upcoming') return 'Upcoming';

        return match ($sequence) {
            1 => 'Your order has been received.',
            2 => 'Your artwork has been approved.',
            3 => 'Your order has completed production.',
            4 => 'Your items have passed quality check.',
            5 => 'Your order has been dispatched.',
            6 => 'Your invoice has been sent.',
            default => 'Your payment has been confirmed.',
        };
    }

    /** @return list<array{sequence:int,title:string,message:string,state:string}> */
    private function timeline(int $stage, array $stages, array $status, string $shipmentState): array
    {
        $limit = match ($stage) {
            1 => 1,
            2 => 7,
            3 => 3,
            4 => 4,
            5 => 4,
            6, 7 => 5,
            default => $stage,
        };

        $items = [];
        foreach (array_slice($stages, 0, $limit) as $entry) {
            $sequence = (int) $entry['sequence'];
            $message = (string) $entry['description'];
            $state = (string) $entry['state'];

            if ($stage === 5 && $sequence === 1) {
                $message = 'Your order has been received and is being processed.';
            }
            if ($stage === 5 && $sequence === 4) {
                $message = 'Your order has passed quality inspection.';
            }
            if ($stage === 6 && $sequence === 5) {
                $message = 'Your order has been dispatched.';
            }
            if ($stage === 7 && $sequence === 5) {
                $message = $shipmentState === 'delivered'
                    ? 'Your order has been delivered.'
                    : 'Your order has been dispatched.';
            }
            if ($sequence === $stage && $stage <= 4) {
                $message = (string) $status['message'];
            }

            $items[] = [
                'sequence' => $sequence,
                'title' => (string) $entry['name'],
                'message' => $message,
                'state' => $state,
            ];
        }

        return $items;
    }

    /** @return array{title:?string,message:string,intro:?string,note_title:?string,note_message:?string,action:?string,action_label:?string} */
    private function nextStep(int $stage, array $status, array $shipment, bool $publicOrderCompleted): array
    {
        if ($publicOrderCompleted) {
            return [
                'title' => 'No further action is needed.',
                'message' => 'Your order is complete. Thank you for your business.',
                'intro' => null,
                'note_title' => null,
                'note_message' => null,
                'action' => null,
                'action_label' => null,
            ];
        }

        if (($status['action'] ?? null) === 'review-artwork') {
            return [
                'title' => 'Your approval is needed',
                'message' => 'Sign in to review and approve your artwork.',
                'intro' => 'Please sign in to view your artwork and approve it so we can move to production.',
                'note_title' => null,
                'note_message' => null,
                'action' => 'review-artwork',
                'action_label' => 'Sign in to review artwork',
            ];
        }

        if ($stage === 3) {
            return [
                'title' => 'Next step: Quality check',
                'message' => 'Once production is complete, your order will be quality checked.',
                'intro' => 'We will move to quality checking once production is complete.',
                'note_title' => 'No action is needed.',
                'note_message' => 'We will notify you when there is an update.',
                'action' => null,
                'action_label' => null,
            ];
        }

        if ($stage === 4) {
            return [
                'title' => 'No action is needed.',
                'message' => 'We are checking the quality of your order. We will update you once quality checking is complete.',
                'intro' => null,
                'note_title' => null,
                'note_message' => null,
                'action' => null,
                'action_label' => null,
            ];
        }

        if ($stage === 5 && ($shipment['tracking_url'] ?? null)) {
            return [
                'title' => 'Track your shipment',
                'message' => 'Your courier tracking is available.',
                'intro' => null,
                'note_title' => null,
                'note_message' => null,
                'action' => 'track-shipment',
                'action_label' => 'Track shipment',
            ];
        }

        if ($stage === 6) {
            $invoiceSent = ($status['key'] ?? null) === 'invoice-sent';

            return [
                'title' => $status['title'],
                'message' => $status['message'],
                'intro' => null,
                'note_title' => $invoiceSent ? 'Invoice sent.' : 'No action is needed right now.',
                'note_message' => $invoiceSent
                    ? 'Check your registered email or sign in to view your invoice.'
                    : 'We will send your invoice to your registered email once it’s ready.',
                'action' => $invoiceSent ? 'view-invoice' : null,
                'action_label' => $invoiceSent ? 'Sign in' : null,
            ];
        }

        if ($stage === 7) {
            $requiresSignIn = in_array(($status['action'] ?? null), ['payment-instructions'], true);

            return [
                'title' => $requiresSignIn ? 'Payment action required' : 'No further action is needed.',
                'message' => $requiresSignIn
                    ? 'Sign in to view payment instructions for your order.'
                    : 'Your payment has been confirmed.',
                'intro' => null,
                'note_title' => null,
                'note_message' => null,
                'action' => $requiresSignIn ? 'payment-instructions' : null,
                'action_label' => $requiresSignIn ? 'Sign in' : null,
            ];
        }

        return [
            'title' => null,
            'message' => 'No action is needed. We will update this page as your order progresses.',
            'intro' => null,
            'note_title' => null,
            'note_message' => null,
            'action' => null,
            'action_label' => null,
        ];
    }

    private function shipmentState(FlowJob $job): string
    {
        $shipments = $job->shipments;
        $total = $shipments->count();
        $isMultiple = (bool) $job->allow_multiple_shipments || $total > 1;
        $deliveryEvents = $job->activities->filter(
            fn (Activity $activity) => in_array($activity->event, ['job.courier_delivery_confirmed', 'job.shipment_delivered'], true)
        );

        if ($deliveryEvents->isNotEmpty()) {
            if (! $isMultiple || $total <= 1) {
                return 'delivered';
            }

            $deliveredShipmentIds = $deliveryEvents
                ->map(fn (Activity $activity) => data_get($activity->meta, 'order_shipment_id') ?? data_get($activity->meta, 'shipment_id'))
                ->filter(fn ($value) => is_numeric($value))
                ->map(fn ($value) => (int) $value)
                ->unique();

            if ($deliveredShipmentIds->count() >= $total) {
                return 'delivered';
            }
            if ($deliveredShipmentIds->isNotEmpty()) {
                return 'partial-delivered';
            }
            // A non-specific delivery event is not enough to claim all parcels
            // delivered for a multi-shipment order.
        }

        if ($this->activity($job, 'job.courier_transit_confirmed') || $this->activity($job, 'job.shipment_in_transit')) {
            return 'in-transit';
        }

        $dispatched = $shipments->filter(fn (OrderShipment $shipment) => $shipment->dispatched_at !== null)->count();

        if ($total > 1 && $dispatched > 0 && $dispatched < $total) return 'partial-dispatched';
        if ($dispatched > 0 || $this->activity($job, 'job.package_shipped')) return 'dispatched';

        return 'pending';
    }

    private function billingState(FlowJob $job, ?Invoice $invoice): string
    {
        if ($invoice && ($invoice->sent_at || $invoice->emailed_at)) return 'sent';
        if ($this->activity($job, 'job.workflow_invoice_sent')) return 'sent';
        if ($invoice || $this->activity($job, 'job.workflow_invoice_prepared') || $this->taskFinishedByKey($job, 'BILL_PREPARE')) return 'ready';

        return 'preparing';
    }

    private function paymentState(FlowJob $job, ?Invoice $invoice): string
    {
        $task = $this->taskByKey($job, 'PAY_PROCESS');
        $taskStatus = Str::lower(trim((string) ($task?->status ?? '')));
        $payments = $invoice
            ? $job->payments->filter(fn ($payment) => (int) $payment->invoice_id === (int) $invoice->id)
            : $job->payments;
        $paid = (float) $payments->sum('amount');
        $total = (float) ($invoice?->total ?? 0);

        if (Str::lower((string) ($invoice?->status ?? '')) === 'paid'
            || ($total > 0 && $paid + 0.0001 >= $total)
            || ($task && $this->taskIsFinished($task))) {
            return 'paid';
        }

        if (str_contains($taskStatus, 'partial') || ($paid > 0 && ($total <= 0 || $paid + 0.0001 < $total))) {
            return 'partial';
        }

        if (Str::contains($taskStatus, ['review', 'verify', 'checking'])) {
            return 'review';
        }

        return 'awaiting';
    }

    /** @return array{label:string,state:string} */
    private function deliverySummary(int $stage, string $shipmentState): array
    {
        return match ($shipmentState) {
            'delivered' => ['label' => 'Delivered', 'state' => 'completed'],
            'in-transit' => ['label' => 'In transit', 'state' => 'active'],
            'partial-delivered' => ['label' => 'Partially delivered', 'state' => 'active'],
            'partial-dispatched' => ['label' => 'Partially dispatched', 'state' => 'active'],
            'dispatched' => ['label' => $stage === 5 ? 'With courier' : 'Dispatched', 'state' => 'active'],
            default => match ($stage) {
                1 => ['label' => 'Awaiting update', 'state' => 'active'],
                3 => ['label' => 'To be confirmed', 'state' => 'upcoming'],
                4 => ['label' => 'Shipment not dispatched yet', 'state' => 'upcoming'],
                5 => ['label' => 'Preparing shipment', 'state' => 'active'],
                default => ['label' => 'Upcoming', 'state' => 'upcoming'],
            },
        };
    }

    /** @return array{label:string,state:string} */
    private function billingSummary(int $stage, string $shipmentState, string $billingState): array
    {
        if ($stage < 6) {
            if ($stage === 5 && in_array($shipmentState, ['dispatched', 'partial-dispatched', 'partial-delivered', 'in-transit', 'delivered'], true)) {
                return ['label' => 'Preparing invoice', 'state' => 'active'];
            }
            return ['label' => 'Upcoming', 'state' => 'upcoming'];
        }

        return match ($billingState) {
            'sent' => ['label' => 'Invoice sent', 'state' => 'completed'],
            'ready' => ['label' => 'Invoice ready', 'state' => 'active'],
            default => ['label' => 'Preparing invoice', 'state' => 'active'],
        };
    }

    /** @return array{label:string,state:string} */
    private function paymentSummary(int $stage, string $paymentState): array
    {
        if ($stage < 7) return ['label' => 'Upcoming', 'state' => 'upcoming'];

        return match ($paymentState) {
            'paid' => ['label' => 'Paid', 'state' => 'completed'],
            'partial' => ['label' => 'Partially paid', 'state' => 'active'],
            'review' => ['label' => 'Under review', 'state' => 'active'],
            default => ['label' => 'Awaiting payment', 'state' => 'active'],
        };
    }


    /** @return array{title:string,message:string,icon:string,tone:string} */
    private function paymentDetail(string $paymentState): array
    {
        return match ($paymentState) {
            'paid' => ['title' => 'Paid', 'message' => 'Your payment has been confirmed.', 'icon' => 'payment', 'tone' => 'success'],
            'partial' => ['title' => 'Partially paid', 'message' => 'A payment has been received. A balance remains.', 'icon' => 'billing', 'tone' => 'pink'],
            'review' => ['title' => 'Payment under review', 'message' => 'We are checking your payment.', 'icon' => 'clock', 'tone' => 'orange'],
            default => ['title' => 'Awaiting payment', 'message' => 'Payment is pending for your order.', 'icon' => 'payment', 'tone' => 'info'],
        };
    }

    private function activity(FlowJob $job, string $event): ?Activity
    {
        return $job->activities->first(fn (Activity $activity) => $activity->event === $event);
    }

    private function displayShipment(FlowJob $job): ?OrderShipment
    {
        return $job->shipments
            ->sort(function (OrderShipment $left, OrderShipment $right): int {
                $leftRank = [
                    $left->dispatched_at?->getTimestamp() ?? 0,
                    filled($left->tracking_number) ? 1 : 0,
                    (int) $left->is_primary,
                    (int) $left->id,
                ];
                $rightRank = [
                    $right->dispatched_at?->getTimestamp() ?? 0,
                    filled($right->tracking_number) ? 1 : 0,
                    (int) $right->is_primary,
                    (int) $right->id,
                ];

                return $rightRank <=> $leftRank;
            })
            ->first();
    }

    /** @return list<array{courier:string,tracking_number:string,tracking_url:?string,available:bool}> */
    private function shipmentRows(FlowJob $job): array
    {
        return $job->shipments
            ->sortBy(fn (OrderShipment $shipment) => [(int) $shipment->sequence, (int) $shipment->id])
            ->map(function (OrderShipment $shipment): array {
                $courier = trim((string) ($shipment->courier?->name ?? ''));
                $tracking = trim((string) ($shipment->tracking_number ?? ''));

                return [
                    'courier' => $courier !== '' ? $courier : 'To be assigned',
                    'tracking_number' => $tracking !== '' ? $tracking : '—',
                    'tracking_url' => $this->trackingUrl($courier, $tracking),
                    'available' => $tracking !== '',
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{courier:string,tracking_number:string,tracking_url:?string,available:bool} */
    private function shipmentData(FlowJob $job, ?OrderShipment $shipment): array
    {
        $shipActivity = $this->activity($job, 'job.package_shipped')
            ?: $this->activity($job, 'job.courier_label_generated');
        $courier = trim((string) ($shipment?->courier?->name ?? data_get($shipActivity?->meta, 'carrier', '')));
        $tracking = trim((string) ($shipment?->tracking_number ?? data_get($shipActivity?->meta, 'tracking_number', '')));

        return [
            'courier' => $courier !== '' ? $courier : 'To be assigned',
            'tracking_number' => $tracking !== '' ? $tracking : '—',
            'tracking_url' => $this->trackingUrl($courier, $tracking),
            'available' => $tracking !== '',
        ];
    }

    private function trackingUrl(string $courier, string $tracking): ?string
    {
        if ($tracking === '') return null;

        $carrier = Str::lower($courier);
        $encoded = rawurlencode($tracking);

        return match (true) {
            str_contains($carrier, 'fedex') => 'https://www.fedex.com/fedextrack/?trknbr='.$encoded,
            str_contains($carrier, 'ups') => 'https://www.ups.com/track?loc=en_US&tracknum='.$encoded,
            str_contains($carrier, 'dhl') => 'https://www.dhl.com/global-en/home/tracking/tracking-express.html?submit=1&tracking-id='.$encoded,
            str_contains($carrier, 'usps') => 'https://tools.usps.com/go/TrackConfirmAction?tLabels='.$encoded,
            default => null,
        };
    }

    private function lastUpdated(FlowJob $job): ?CarbonInterface
    {
        return collect([
            $job->updated_at,
            ...$job->tasks->pluck('updated_at')->all(),
            ...$job->activities->pluck('created_at')->all(),
            ...$job->shipments->pluck('updated_at')->all(),
            ...$job->invoices->pluck('updated_at')->all(),
            ...$job->payments->pluck('updated_at')->all(),
        ])
            ->filter()
            ->sortByDesc(fn (CarbonInterface $date) => $date->getTimestamp())
            ->first();
    }

    private function formatDateTimeInline(?CarbonInterface $date): string
    {
        return UserLocalTime::localize($date)?->format('M d, Y, h:i A') ?? '—';
    }
}
