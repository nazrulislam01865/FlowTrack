<?php

namespace App\Support;

use App\Models\FlowJob;
use App\Models\OrderShipment;
use App\Models\WorkflowPhase;
use App\Services\OrderShipmentService;
use App\Services\OrderWorkflowActionService;
use Illuminate\Support\Collection;

/**
 * Query-free view model for the Shipment phase on Order Details.
 *
 * The service layer owns shipment persistence and aggregate task completion;
 * this class only translates already-hydrated Order/Shipment records into the
 * exact prototype vocabulary used by Blade.
 */
final class OrderShipmentPresenter
{
    /** @param Collection<int,\App\Models\Task> $tasks */
    public static function isShipmentPhase(?WorkflowPhase $phase, Collection $tasks): bool
    {
        if (! $phase) return false;
        if (strcasecmp(trim((string) $phase->name), 'Shipment') === 0) return true;
        if ((int) $phase->sequence === 5) return true;

        $workflowActions = new OrderWorkflowActionService();
        return $tasks->contains(fn ($task) => in_array(
            $workflowActions->automationKey($task),
            ['SHIP_CONFIRM_INFO', 'SHIP_LABEL', 'SHIP_PACKAGE'],
            true,
        ));
    }

    /** @param Collection<int,\App\Models\Task> $tasks */
    public static function present(FlowJob $job, WorkflowPhase $phase, Collection $tasks, array $context = []): array
    {
        $workflowActions = new OrderWorkflowActionService();
        $taskByKey = $tasks->keyBy(fn ($task) => $workflowActions->automationKey($task))->all();
        $shipmentTasks = collect(['SHIP_CONFIRM_INFO', 'SHIP_LABEL', 'SHIP_PACKAGE'])
            ->map(fn (string $key) => $taskByKey[$key] ?? null)
            ->filter()
            ->values();
        $isCancelled = strcasecmp((string) $job->status, 'Cancelled') === 0;
        $addressMode = (string) ($job->shipment_address_mode ?: OrderShipmentService::MODE_SAME_ADDRESS);

        $rows = $shipmentTasks->map(function ($task, int $index) use ($job, $phase, $context, $workflowActions, $isCancelled, $addressMode): array {
            $key = $workflowActions->automationKey($task);
            $mode = OrderDetailPresenter::taskMode($job, $task);
            $permissions = data_get($context, 'taskPermissions.'.(int) $task->id, []);
            $assigneeName = trim((string) ($task->assignee?->name ?: 'Unassigned'));
            $isDone = OrderDetailPresenter::isCompletedTask($task);
            [$title, $description] = match ($key) {
                'SHIP_CONFIRM_INFO' => [
                    'Review or update shipment details',
                    $addressMode === OrderShipmentService::MODE_MULTIPLE_ADDRESS
                        ? 'Review recipient and shipping methods for each shipment. Shipments may use different delivery addresses.'
                        : 'Review recipient and shipping methods for each shipment. All shipments will be sent to the same address.',
                ],
                'SHIP_LABEL' => [
                    'Add courier & tracking number',
                    'Select the courier and add or update the tracking number for each shipment.',
                ],
                'SHIP_PACKAGE' => [
                    'Dispatch shipment',
                    'Mark each shipment as dispatched after the package has been handed to the courier.',
                ],
                default => [
                    (string) $task->title,
                    (string) ($task->description ?: $task->setupTemplate?->description ?: ''),
                ],
            };

            return [
                'task' => $task,
                'key' => $key,
                'mode' => $mode,
                'is_done' => $isDone,
                'can_edit' => (bool) data_get($permissions, 'edit', false) && ! $isCancelled,
                'can_assign' => (bool) data_get($permissions, 'assign', false) && ! $isCancelled,
                'display_code' => OrderDetailPresenter::taskDisplayCode($phase, $task, $index),
                'title' => $title,
                'description' => $description,
                'assignee_name' => $assigneeName,
                'assignee_initial' => mb_strtoupper(mb_substr($assigneeName, 0, 1)) ?: 'U',
                'assignee_avatar' => $task->assignee?->profileImageUrl(),
                'due_date' => $task->due_date?->format('M j, Y') ?: 'Set due date',
            ];
        })->values();

        $methods = collect(data_get($context, 'shipmentMethods', []))->values();
        $urgencies = collect(data_get($context, 'shipmentUrgencies', []))->values();
        $couriers = collect(data_get($context, 'shipmentCouriers', []))->values();
        $shipments = $job->relationLoaded('shipments') ? $job->shipments->values() : collect();

        $shipmentRows = $shipments->map(function (OrderShipment $shipment) use ($methods, $urgencies, $couriers): array {
            $methodOptions = $methods;
            if ($shipment->relationLoaded('shippingMethod') && $shipment->shippingMethod
                && ! $methodOptions->contains(fn ($option) => (int) data_get($option, 'id') === (int) $shipment->shippingMethod->id)) {
                $methodOptions = $methodOptions->prepend($shipment->shippingMethod);
            }
            $urgencyOptions = $urgencies;
            if ($shipment->relationLoaded('shipmentUrgency') && $shipment->shipmentUrgency
                && ! $urgencyOptions->contains(fn ($option) => (int) data_get($option, 'id') === (int) $shipment->shipmentUrgency->id)) {
                $urgencyOptions = $urgencyOptions->prepend($shipment->shipmentUrgency);
            }

            $courier = $shipment->relationLoaded('courier') ? $shipment->courier : null;
            if (! $courier && $shipment->courier_id) {
                $courier = $couriers->first(fn ($option) => (int) data_get($option, 'id') === (int) $shipment->courier_id);
            }

            $methodCard = CreateOrderShippingMethodPresenter::selectedCard(
                $methodOptions,
                $urgencyOptions,
                $shipment->shipment_method_id ? [(int) $shipment->shipment_method_id] : [],
                $shipment->shipment_urgency_id ? [(int) $shipment->shipment_urgency_id] : [],
            );

            return [
                'id' => (int) $shipment->id,
                'sequence' => (int) $shipment->sequence,
                'is_primary' => (bool) $shipment->is_primary,
                'recipient' => trim((string) ($shipment->recipient ?? '')),
                'phone' => $shipment->fullPhone(),
                'address' => trim((string) ($shipment->address ?? '')),
                'city' => trim((string) ($shipment->city ?? '')),
                'state' => trim((string) ($shipment->state ?? '')),
                'postal_code' => trim((string) ($shipment->postal_code ?? '')),
                'country' => trim((string) ($shipment->country ?? '')),
                'quantity' => $shipment->quantity !== null ? (int) $shipment->quantity : null,
                'package_reference' => trim((string) ($shipment->package_reference ?? '')),
                'shipment_method_id' => $shipment->shipment_method_id ? (int) $shipment->shipment_method_id : null,
                'shipment_urgency_id' => $shipment->shipment_urgency_id ? (int) $shipment->shipment_urgency_id : null,
                'courier_id' => $shipment->courier_id ? (int) $shipment->courier_id : null,
                'courier_name' => trim((string) data_get($courier, 'name', '')),
                'tracking_number' => trim((string) ($shipment->tracking_number ?? '')),
                'dispatched' => (bool) $shipment->dispatched_at,
                'dispatched_on' => $shipment->dispatched_at?->format('M j, Y h:i A') ?: '—',
                'method_card' => $methodCard,
            ];
        })->values();

        $primary = $shipmentRows->firstWhere('is_primary', true) ?: $shipmentRows->first();

        return [
            'tasks' => $rows,
            'task_by_key' => $rows->keyBy('key')->all(),
            'completed_count' => $rows->where('is_done', true)->count(),
            'total_count' => $rows->count(),
            'shipments' => $shipmentRows->all(),
            'shipment_count' => $shipmentRows->count(),
            'next_sequence' => ((int) $shipmentRows->max('sequence')) + 1,
            'allow_multiple_shipments' => (bool) $job->allow_multiple_shipments,
            'address_mode' => $addressMode,
            'primary_shipment' => $primary,
            'shipment_methods' => $methods,
            'shipment_urgencies' => $urgencies,
            'couriers' => $couriers
                ->map(fn ($courier) => [
                    'id' => (int) data_get($courier, 'id'),
                    'name' => trim((string) data_get($courier, 'name', '')),
                ])
                ->filter(fn (array $courier) => $courier['id'] > 0 && $courier['name'] !== '')
                ->values()
                ->all(),
            'countries' => (array) data_get($context, 'shipmentCountries', []),
            'states' => (array) data_get($context, 'shipmentStates', []),
            'phone_country_codes' => (array) data_get($context, 'shipmentPhoneCountryCodes', []),
            'saved_shipping_addresses' => (array) data_get($context, 'shipmentSavedAddresses', []),
        ];
    }
}
