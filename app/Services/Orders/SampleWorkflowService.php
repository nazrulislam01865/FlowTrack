<?php

namespace App\Services\Orders;

use App\Models\FlowJob;
use App\Models\SupplierTaskAssignment;
use App\Models\Task;
use App\Models\User;
use App\Services\SupplierBridge\QueueChangedOrder;
use App\Services\SupplierBridge\SupplierDefaultTaskAssigner;
use Illuminate\Validation\ValidationException;

/** One conditional artwork task, with each handoff retained in the Order audit trail. */
final class SampleWorkflowService
{
    public const EVENT = 'job.sample_step';

    private const STATES = [
        'supplier_prepare' => 'Sample: Supplier Preparing',
        'flow_receive' => 'Sample: Awaiting Receipt',
        'flow_decision' => 'Sample: Awaiting Decision',
        'supplier_revision' => 'Sample: Supplier Revision',
        'flow_ship' => 'Sample: Awaiting Shipment',
    ];

    public static function state(Task $task): string
    {
        if ($task->completed_at || strcasecmp((string) $task->status, 'Completed') === 0) return 'approved';
        $status = trim((string) $task->status);
        foreach (self::STATES as $state => $label) {
            if ($status === $label) return $state;
        }
        return 'request';
    }

    public static function presentation(Task $task): array
    {
        return match (self::state($task)) {
            'request' => ['title' => 'Inform supplier to prepare sample', 'description' => 'Choose physical shipment or images by email', 'action' => 'Request sample', 'status' => 'Ready'],
            'supplier_prepare' => ['title' => 'Supplier preparing sample', 'description' => 'Request sent to supplier', 'action' => 'View request', 'status' => 'Waiting for Supplier'],
            'flow_receive' => ['title' => 'Confirm sample received', 'description' => 'Supplier confirmed preparation · Record receipt in Main ERP', 'action' => 'Confirm sample received', 'status' => 'Ready'],
            'flow_decision' => ['title' => 'Sample approval / revision', 'description' => 'Record the client sample decision', 'action' => 'Record sample decision', 'status' => 'Waiting for Client'],
            'supplier_revision' => ['title' => 'Supplier revising sample', 'description' => 'Revision instructions sent · Supplier preparing new version', 'action' => 'View revision', 'status' => 'Revision required'],
            'flow_ship' => ['title' => 'Ship sample to client', 'description' => 'Sample approved · Record onward shipment in Main ERP', 'action' => 'Record sample shipment', 'status' => 'Ready'],
            default => ['title' => 'Sample approved', 'description' => 'Supplier notified · Approved', 'action' => 'View sample details', 'status' => 'Completed'],
        };
    }

    public static function supplierPresentation(Task $task, array $sample): array
    {
        $kind = ($sample['kind'] ?? '') === 'physical' ? 'physical' : 'images';
        return match (self::state($task)) {
            'supplier_prepare' => ['title' => $kind === 'physical' ? 'Prepare physical sample' : 'Send sample images by email', 'description' => $sample['request_notes'] ?? '', 'status' => 'Ready'],
            'flow_receive' => ['title' => 'Sample prepared', 'description' => 'Awaiting FlowTracker confirmation of physical sample receipt', 'status' => 'Awaiting Main ERP'],
            'flow_decision' => ['title' => 'Waiting for sample decision', 'description' => 'Awaiting sample approval or revision in FlowTracker', 'status' => 'Awaiting Approval'],
            'supplier_revision' => ['title' => 'Revise sample', 'description' => 'FlowTracker requested a new sample version', 'status' => 'Revision required'],
            'flow_ship' => ['title' => 'Sample approved', 'description' => 'Onward shipment managed in FlowTracker Main ERP', 'status' => 'Approved'],
            default => ['title' => 'Sample approved', 'description' => 'FlowTracker approved the sample and informed supplier', 'status' => 'Completed'],
        };
    }

    /** One indexed, order-scoped read only when a sample modal or projection is opened. */
    public function snapshot(FlowJob $job, Task $task): array
    {
        $activity = $job->activities()->where('event', self::EVENT)->latest('id')->first();
        $meta = (array) ($activity?->meta ?? []);
        if ((int) ($meta['task_id'] ?? 0) !== (int) $task->id) $meta = [];
        return array_merge([
            'kind' => '', 'request_notes' => '', 'supplier_note' => '', 'email_to' => '',
            'image_name' => '', 'received_on' => '', 'receipt_note' => '', 'review_note' => '',
            'revisions' => 0, 'shipping' => null, 'cycle' => 0,
        ], $meta, ['transition_state' => (string) ($meta['state'] ?? ''), 'state' => self::state($task)]);
    }

    /** Read only when a user opens the sample activity dialog (never per task row). */
    public function history(FlowJob $job, Task $task): array
    {
        return $job->activities()->where('event', self::EVENT)->latest('id')->limit(30)
            ->get(['description', 'created_at', 'meta'])
            ->filter(fn ($activity) => (int) data_get($activity->meta, 'task_id', 0) === (int) $task->id)
            ->map(function ($activity): array {
                $state = (string) data_get($activity->meta, 'state', '');
                $actor = $state === 'flow_receive'
                    || ($state === 'flow_decision' && filled(data_get($activity->meta, 'image_name')))
                    ? 'Supplier' : 'FlowTracker';
                return [
                    'description' => (string) $activity->description,
                    'at' => $activity->created_at?->format('M j, Y g:i A') ?: '',
                    'actor' => $actor,
                ];
            })->values()->all();
    }

    /** Mutate only from the canonical task/order-locked action, never from Blade or browser state. */
    public function advance(Task $task, FlowJob $job, User $actor, string $decision, array $payload, bool $supplier = false): string
    {
        $state = self::state($task);
        $before = $this->snapshot($job, $task);
        $next = $before;
        $description = '';
        $text = static fn (string $key, int $limit) => mb_substr(trim((string) ($payload[$key] ?? '')), 0, $limit);
        $must = static function (bool $valid, string $field, string $message): void {
            if (! $valid) throw ValidationException::withMessages([$field => $message]);
        };

        if ($supplier) {
            $must($decision === 'sample_prepared' && in_array($state, ['supplier_prepare', 'supplier_revision'], true), 'workflow', 'The supplier sample preparation is not currently awaiting confirmation.');
            $next['supplier_note'] = $text('supplier_note', 1000);
            if (($before['kind'] ?? '') === 'images') {
                $recipient = $text('email_to', 255);
                $must((bool) filter_var($recipient, FILTER_VALIDATE_EMAIL), 'email_to', 'Enter a valid email recipient.');
                $fileName = $text('image_name', 255);
                $must($fileName !== '', 'files', 'Choose a sample image to send.');
                $next['email_to'] = $recipient;
                $next['image_name'] = $fileName;
                $nextState = 'flow_decision';
                $description = 'Supplier recorded sample image email to '.$recipient.'.';
            } else {
                $nextState = 'flow_receive';
                $description = 'Supplier confirmed physical sample preparation.';
            }
        } else {
            $must(in_array($decision, ['sample_request', 'sample_received', 'sample_approved', 'sample_revise', 'sample_shipped'], true), 'workflow', 'Unsupported sample action.');
            $must(match ($decision) {
                'sample_request' => $state === 'request',
                'sample_received' => $state === 'flow_receive',
                'sample_approved', 'sample_revise' => $state === 'flow_decision',
                'sample_shipped' => $state === 'flow_ship',
            }, 'workflow', 'This sample step has already changed. Refresh the order.');

            if ($decision === 'sample_request') {
                $kind = $text('sample_kind', 20);
                $must(in_array($kind, ['physical', 'images'], true), 'orderWorkflowActionPayload.sample_kind', 'Choose the sample type.');
                // The order-level task cannot be delegated to an arbitrary supplier
                // when the order contains product lines from multiple suppliers.
                $assigner = app(SupplierDefaultTaskAssigner::class);
                $supplierId = $assigner->uniqueSupplierForOrder((int) $job->id);
                $must($supplierId !== null, 'orderWorkflowActionPayload.sample_kind', 'A sample requires exactly one active supplier for this order.');
                SupplierTaskAssignment::query()->updateOrCreate(['task_id' => $task->id], [
                    'flow_job_id' => $job->id, 'supplier_id' => $supplierId,
                    'assigned_by' => $actor->id, 'assignment_source' => 'manual',
                ]);
                $next['kind'] = $kind;
                $next['request_notes'] = $text('sample_instructions', 1200);
                $next['cycle'] = (int) $before['cycle'] + 1;
                $nextState = 'supplier_prepare';
                $description = 'FlowTracker requested '.($kind === 'physical' ? 'a physical sample.' : 'sample images by email.');
            } elseif ($decision === 'sample_received') {
                $date = $text('received_on', 10);
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                $must($parsed && $parsed->format('Y-m-d') === $date, 'orderWorkflowActionPayload.received_on', 'Enter a valid receipt date.');
                $next['received_on'] = $date;
                $next['receipt_note'] = $text('receipt_note', 1000);
                $nextState = 'flow_decision';
                $description = 'FlowTracker confirmed sample receipt on '.$date.'.';
            } elseif ($decision === 'sample_revise') {
                $note = $text('review_note', 1500);
                $must($note !== '', 'orderWorkflowActionPayload.review_note', 'Revision instructions are required.');
                $next['review_note'] = $note;
                $next['revisions'] = (int) $before['revisions'] + 1;
                $next['received_on'] = '';
                $next['receipt_note'] = '';
                $next['shipping'] = null;
                $next['image_name'] = '';
                $nextState = 'supplier_revision';
                $description = 'FlowTracker requested sample revision: '.$note;
            } elseif ($decision === 'sample_approved') {
                $next['review_note'] = 'Approved';
                $nextState = ($before['kind'] ?? '') === 'physical' ? 'flow_ship' : 'approved';
                $description = 'FlowTracker approved the sample and notified the supplier.';
            } else {
                // Master Data is the single source of shipping-method choices.
                // Validate on save as Livewire payloads are client-controlled.
                $methodId = (int) ($payload['shipping_method_id'] ?? 0);
                $urgencyId = (int) ($payload['shipping_urgency_id'] ?? 0) ?: null;
                $master = app(\App\Services\MasterDataService::class);
                $methods = $master->active('shipment_method');
                $method = $methods->first(fn ($row) => (int) $row->id === $methodId);
                $must($method !== null, 'orderWorkflowActionPayload.shipping_method_id', 'Select a shipping method from Master Data.');
                $presenter = \App\Support\CreateOrderShippingMethodPresenter::class;
                $isExpress = $presenter::methodKind($method) === 'express';
                $must($isExpress || $urgencyId === null, 'orderWorkflowActionPayload.shipping_method_id', 'Select a valid shipping method.');
                $urgencies = $isExpress ? $master->active('shipment_urgency') : collect();
                $must($urgencyId === null || $urgencies->contains(fn ($row) => (int) $row->id === $urgencyId),
                    'orderWorkflowActionPayload.shipping_urgency_id', 'Select an active express service level.');
                $selected = $presenter::selectedCard($methods, $urgencies, [$methodId], $urgencyId ? [$urgencyId] : []);
                $carrier = $text('shipping_carrier', 120);
                $tracking = $text('shipping_tracking', 150);
                $must($carrier !== '', 'orderWorkflowActionPayload.shipping_carrier', 'Enter the shipping provider.');
                $must($tracking !== '', 'orderWorkflowActionPayload.shipping_tracking', 'Enter the tracking ID or shipping number.');
                $next['shipping'] = [
                    'method' => $selected['title'], 'method_id' => $methodId, 'urgency_id' => $urgencyId,
                    'carrier' => $carrier, 'tracking' => $tracking,
                    'destination' => $text('shipping_destination', 200), 'note' => $text('shipping_note', 1000),
                ];
                $nextState = 'approved';
                $description = 'FlowTracker recorded sample shipment with tracking '.$tracking.'.';
            }
        }

        $next['state'] = $nextState;
        $next['task_id'] = (int) $task->id;
        $job->activities()->create([
            'user_id' => $actor->id, 'event' => self::EVENT,
            'description' => $description, 'meta' => $next,
        ]);
        if ($nextState !== 'approved') {
            $task->update(['status' => self::STATES[$nextState], 'completed_at' => null, 'progress' => 50]);
        }
        app(QueueChangedOrder::class)->mark((int) $job->id);
        return $nextState;
    }
}
