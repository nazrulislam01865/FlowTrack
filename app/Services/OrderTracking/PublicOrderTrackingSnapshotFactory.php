<?php

namespace App\Services\OrderTracking;

use App\DTOs\OrderTracking\PublicOrderTrackingSnapshot;
use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\Invoice;
use App\Models\OrderShipment;
use App\Models\Task;
use App\Support\OrderStageResolver;

/** Converts an authorized FlowTrack read into the isolated tracking contract. */
final class PublicOrderTrackingSnapshotFactory
{
    public function make(FlowJob $job): PublicOrderTrackingSnapshot
    {
        $resolved = OrderStageResolver::resolve(
            $job->phase?->name,
            $job->phase?->short_name,
            $job->phase?->sequence,
            $job->status,
        );

        $shipmentSequences = $job->shipments
            ->mapWithKeys(fn (OrderShipment $shipment): array => [(int) $shipment->id => (int) $shipment->sequence])
            ->all();

        $paymentsByInvoice = $job->payments
            ->groupBy(fn ($payment): int => (int) ($payment->invoice_id ?? 0))
            ->map(fn ($payments): float => (float) $payments->sum('amount'))
            ->all();

        return new PublicOrderTrackingSnapshot(
            orderNumber: $job->displayOrderNumber(),
            referenceNumber: trim((string) $job->order_number),
            orderStatus: trim((string) $job->status),
            allowMultipleShipments: (bool) $job->allow_multiple_shipments,
            completedAt: $job->completed_at?->toIso8601String(),
            updatedAt: $job->updated_at?->toIso8601String(),
            phase: [
                'sequence' => (int) $resolved['sequence'],
                'name' => (string) ($job->phase?->name ?? $resolved['name']),
                'short_name' => (string) ($job->phase?->short_name ?? $resolved['name']),
            ],
            tasks: $job->tasks->map(fn (Task $task): array => [
                'automation_key' => trim((string) ($task->setupTemplate?->automation_key ?? '')),
                'title' => (string) $task->title,
                'status' => (string) $task->status,
                'progress' => (int) $task->progress,
                'completed_at' => $task->completed_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
            ])->values()->all(),
            activities: $job->activities->map(function (Activity $activity) use ($shipmentSequences): array {
                $shipmentId = $this->internalShipmentId($activity);

                return [
                    'event' => (string) $activity->event,
                    'created_at' => $activity->created_at?->toIso8601String(),
                    'shipment_sequence' => $shipmentId && array_key_exists($shipmentId, $shipmentSequences)
                        ? (int) $shipmentSequences[$shipmentId]
                        : null,
                ];
            })->values()->all(),
            shipments: $job->shipments->map(fn (OrderShipment $shipment): array => [
                'sequence' => (int) $shipment->sequence,
                'is_primary' => (bool) $shipment->is_primary,
                'courier' => $shipment->courier?->name ? (string) $shipment->courier->name : null,
                'tracking_number' => $shipment->tracking_number ? trim((string) $shipment->tracking_number) : null,
                'dispatched_at' => $shipment->dispatched_at?->toIso8601String(),
                'updated_at' => $shipment->updated_at?->toIso8601String(),
            ])->values()->all(),
            invoices: $job->invoices->map(function (Invoice $invoice) use ($paymentsByInvoice): array {
                $paid = (float) ($paymentsByInvoice[(int) $invoice->id] ?? 0.0);
                $total = (float) $invoice->total;
                $invoiceStatus = strtolower(trim((string) $invoice->status));

                $paymentState = $invoiceStatus === 'paid' || ($total > 0 && $paid + 0.0001 >= $total)
                    ? 'paid'
                    : ($paid > 0 ? 'partial' : 'none');

                return [
                    'sequence' => (int) $invoice->sequence,
                    'status' => trim((string) $invoice->status),
                    'payment_state' => $paymentState,
                    'sent_at' => $invoice->sent_at?->toIso8601String(),
                    'emailed_at' => $invoice->emailed_at?->toIso8601String(),
                    'updated_at' => $invoice->updated_at?->toIso8601String(),
                ];
            })->values()->all(),
        );
    }

    private function internalShipmentId(Activity $activity): ?int
    {
        $value = data_get($activity->meta, 'order_shipment_id') ?? data_get($activity->meta, 'shipment_id');
        return is_numeric($value) ? (int) $value : null;
    }
}
