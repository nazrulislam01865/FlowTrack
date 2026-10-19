<?php

namespace App\Queries\OrderTracking;

use App\DTOs\OrderTracking\PublicOrderTrackingCandidate;
use App\DTOs\OrderTracking\PublicOrderTrackingLookup;
use App\Models\FlowJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Minimal identifier lookup for the public tracking boundary.
 *
 * Only relationship IDs needed for email ownership checks are selected here.
 * Full order/workflow data is not hydrated until identity has been verified.
 */
final class PublicOrderTrackingCandidateQuery
{
    public function find(PublicOrderTrackingLookup $lookup): ?PublicOrderTrackingCandidate
    {
        if ($lookup->identifier === '' || $lookup->email === '') {
            return null;
        }

        $column = $lookup->lookupType === 'reference' ? 'order_number' : 'job_number';

        $row = FlowJob::query()
            ->select([
                'id',
                'client_id',
                'owner_id',
                'coordinator_id',
                'created_by',
                'supplier_id',
            ])
            ->whereNull('cancelled_at')
            ->where(function (Builder $query) use ($column, $lookup): void {
                $query->whereIn($column, $this->identifierCandidates($lookup->identifier, $lookup->lookupType));
            })
            ->latest('id')
            ->first();

        if (! $row) {
            return null;
        }

        return new PublicOrderTrackingCandidate(
            orderId: (int) $row->id,
            clientId: $row->client_id ? (int) $row->client_id : null,
            ownerId: $row->owner_id ? (int) $row->owner_id : null,
            coordinatorId: $row->coordinator_id ? (int) $row->coordinator_id : null,
            creatorId: $row->created_by ? (int) $row->created_by : null,
            supplierId: $row->supplier_id ? (int) $row->supplier_id : null,
        );
    }

    /** @return list<string> */
    private function identifierCandidates(string $identifier, string $lookupType): array
    {
        $trimmed = trim($identifier);
        $upper = Str::upper($trimmed);
        $lower = Str::lower($trimmed);
        $candidates = [$trimmed, $upper, $lower];

        if ($lookupType === 'order' && str_starts_with($upper, 'ORDER-')) {
            $legacy = 'JOB-'.substr($upper, 6);
            $candidates[] = $legacy;
            $candidates[] = Str::lower($legacy);
        }

        if ($lookupType === 'order' && str_starts_with($upper, 'FO-')) {
            $legacy = 'JOB-'.substr($upper, 3);
            $candidates[] = $legacy;
            $candidates[] = Str::lower($legacy);
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $value): bool => $value !== '')));
    }
}
