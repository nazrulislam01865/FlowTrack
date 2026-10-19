<?php

namespace App\Services\OrderTracking;

use App\DTOs\OrderTracking\PublicOrderTrackingCandidate;
use App\DTOs\OrderTracking\PublicOrderTrackingIdentity;
use App\DTOs\OrderTracking\PublicOrderTrackingLookup;
use App\DTOs\OrderTracking\PublicOrderTrackingSnapshot;
use App\Models\FlowJob;
use App\Queries\OrderTracking\PublicOrderTrackingCandidateQuery;
use App\Queries\OrderTracking\PublicOrderTrackingDataQuery;

/**
 * Application/business boundary for phase 1 of the public tracking backend.
 *
 * Candidate lookup, identity resolution, disclosure policy and tracking-data
 * hydration are separate steps. Full order data is never hydrated until the
 * relevant access decision has passed.
 */
final class PublicOrderTrackingFetchService
{
    public function __construct(
        private readonly PublicOrderTrackingCandidateQuery $candidates,
        private readonly PublicOrderTrackingIdentityResolver $identities,
        private readonly PublicOrderTrackingDataQuery $orders,
        private readonly PublicOrderTrackingSnapshotFactory $snapshots,
        private readonly PublicOrderTrackingDisclosurePolicy $disclosure,
    ) {
    }

    /** Internal/backend read contract for an order-linked email. */
    public function fetch(PublicOrderTrackingLookup $lookup): ?PublicOrderTrackingSnapshot
    {
        return $this->fetchWithIdentity($lookup)['snapshot'];
    }

    /**
     * Internal application result for the next backend phase and tests.
     * No email value or Eloquent model crosses this boundary.
     *
     * @return array{snapshot:?PublicOrderTrackingSnapshot,identity:PublicOrderTrackingIdentity}
     */
    public function fetchWithIdentity(PublicOrderTrackingLookup $lookup): array
    {
        [$candidate, $identity] = $this->identify($lookup);
        if (! $candidate || ! $identity->matched()) {
            return ['snapshot' => null, 'identity' => $identity];
        }

        $order = $this->orders->fetch($candidate->orderId);

        return [
            'snapshot' => $order ? $this->snapshots->make($order) : null,
            'identity' => $identity,
        ];
    }

    /**
     * Temporary compatibility method for the already-approved query-free view
     * presenter. The conservative disclosure policy is checked before full data
     * hydration, so recognizing a supplier/internal user does not automatically
     * bridge the customer result to that audience in this phase.
     */
    public function fetchOrderForLiveResult(PublicOrderTrackingLookup $lookup): ?FlowJob
    {
        [$candidate, $identity] = $this->identify($lookup);
        if (! $candidate || ! $this->disclosure->allowsLiveResult($identity)) {
            return null;
        }

        return $this->orders->fetch($candidate->orderId);
    }

    /** @return array{0:?PublicOrderTrackingCandidate,1:PublicOrderTrackingIdentity} */
    private function identify(PublicOrderTrackingLookup $lookup): array
    {
        $candidate = $this->candidates->find($lookup);
        if (! $candidate) {
            return [null, new PublicOrderTrackingIdentity([])];
        }

        return [$candidate, $this->identities->resolve($candidate, $lookup->email)];
    }
}
