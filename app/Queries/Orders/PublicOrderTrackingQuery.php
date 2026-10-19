<?php

namespace App\Queries\Orders;

use App\DTOs\OrderTracking\PublicOrderTrackingLookup;
use App\Models\FlowJob;
use App\Services\OrderTracking\PublicOrderTrackingFetchService;
use Illuminate\Support\Str;

/**
 * Compatibility query used by the existing tracking controller/presenter.
 *
 * The actual backend boundary now lives in PublicOrderTrackingFetchService:
 * candidate lookup -> linked-email identity resolution -> bounded data fetch.
 * This facade can be removed once the public presenter consumes the detached
 * PublicOrderTrackingSnapshot directly.
 */
final class PublicOrderTrackingQuery
{
    public function __construct(private readonly PublicOrderTrackingFetchService $tracking)
    {
    }

    public function find(string $lookupType, string $identifier, string $email): ?FlowJob
    {
        return $this->tracking->fetchOrderForLiveResult(new PublicOrderTrackingLookup(
            lookupType: $lookupType === 'reference' ? 'reference' : 'order',
            identifier: trim($identifier),
            email: Str::lower(trim($email)),
        ));
    }
}
