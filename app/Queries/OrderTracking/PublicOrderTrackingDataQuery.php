<?php

namespace App\Queries\OrderTracking;

use App\Models\FlowJob;
use App\Support\OrderStageResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Full tracking read performed only after the public access check has passed.
 *
 * Relations are loaded in bounded batches. No client/supplier/staff profile,
 * address, document or free-form note relation is loaded through this boundary.
 */
final class PublicOrderTrackingDataQuery
{
    public function fetch(int $orderId): ?FlowJob
    {
        $job = $this->baseOrderQuery()
            ->whereKey($orderId)
            ->first();

        return $this->hydrateStageData($job);
    }

    /**
     * Token lookup uses the same bounded public data query as manual tracking.
     * The opaque token is the access credential, so no customer identity data
     * is loaded merely to resolve the token.
     */
    public function fetchByTrackingToken(string $token): ?FlowJob
    {
        $token = trim($token);

        if ($token === '' || ! preg_match('/^[A-Za-z0-9]{32,64}$/', $token)) {
            return null;
        }

        $job = $this->baseOrderQuery()
            ->where('tracking_token', $token)
            ->first();

        return $this->hydrateStageData($job);
    }

    private function baseOrderQuery(): Builder
    {
        return FlowJob::query()
            ->select([
                'id',
                'job_number',
                'order_number',
                'workflow_phase_id',
                'status',
                'completed_at',
                'allow_multiple_shipments',
                'updated_at',
            ])
            ->whereNull('cancelled_at')
            ->with([
                'phase:id,name,short_name,sequence',
                'tasks' => fn ($query) => $query
                    ->select([
                        'id',
                        'flow_job_id',
                        'workflow_phase_id',
                        'task_pack_task_id',
                        'title',
                        'status',
                        'progress',
                        'completed_at',
                        'updated_at',
                    ])
                    ->with('setupTemplate:id,task_pack_id,automation_key,sort_order,is_required')
                    ->orderBy('id'),
                'activities' => fn ($query) => $query
                    ->select(['id', 'subject_type', 'subject_id', 'event', 'meta', 'created_at'])
                    ->whereIn('event', $this->publicActivityEvents())
                    ->latest('id'),
            ]);
    }

    private function hydrateStageData(?FlowJob $job): ?FlowJob
    {
        if (! $job) {
            return null;
        }

        $stage = OrderStageResolver::resolve(
            $job->phase?->name,
            $job->phase?->short_name,
            $job->phase?->sequence,
            $job->status,
        );
        $stageSequence = (int) $stage['sequence'];

        if ($stageSequence >= 5 || $job->completed_at !== null) {
            $job->load([
                'shipments' => fn ($query) => $query
                    ->select([
                        'id',
                        'flow_job_id',
                        'sequence',
                        'is_primary',
                        'courier_id',
                        'tracking_number',
                        'dispatched_at',
                        'updated_at',
                    ])
                    ->with('courier:id,name')
                    ->orderBy('sequence')
                    ->orderBy('id'),
            ]);
        } else {
            $job->setRelation('shipments', new EloquentCollection());
        }

        if ($stageSequence >= 6 || $job->completed_at !== null) {
            $job->load([
                'invoices' => fn ($query) => $query
                    ->select([
                        'id',
                        'flow_job_id',
                        'sequence',
                        'status',
                        'total',
                        'sent_at',
                        'emailed_at',
                        'updated_at',
                    ])
                    ->latest('id'),
            ]);
        } else {
            $job->setRelation('invoices', new EloquentCollection());
        }

        if ($stageSequence >= 7 || $job->completed_at !== null) {
            $job->load([
                'payments' => fn ($query) => $query
                    ->select(['id', 'flow_job_id', 'invoice_id', 'amount', 'updated_at'])
                    ->latest('id'),
            ]);
        } else {
            $job->setRelation('payments', new EloquentCollection());
        }

        return $job;
    }

    /** @return list<string> */
    private function publicActivityEvents(): array
    {
        return [
            'job.purchase_order_emailed_to_artwork_team',
            'job.artwork_revision_requested',
            'job.artwork_client_erp_uploaded',
            'job.sample_required',
            'job.sample_not_required',
            'job.qc_passed',
            'job.qc_issue_reported',
            'job.shipment_information_confirmed',
            'job.courier_label_generated',
            'job.package_shipped',
            'job.courier_transit_confirmed',
            'job.shipment_in_transit',
            'job.courier_delivery_confirmed',
            'job.shipment_delivered',
            'job.workflow_invoice_prepared',
            'job.workflow_invoice_sent',
            'job.payment_recorded',
            'job.workflow_payment_recorded',
        ];
    }
}
