<?php

namespace App\Services;

/**
 * Phase 8 compatibility facade.
 *
 * New application code should prefer the focused services under the domain
 * namespace. Existing callers keep the historical FQCN and method surface
 * while the legacy implementation is retired capability-by-capability.
 */
class JobService extends LegacyJobService
{
    public const INACTIVE_STATUSES = LegacyJobService::INACTIVE_STATUSES;
}
