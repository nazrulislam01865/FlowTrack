<?php

namespace App\Services;

/**
 * Phase 8 compatibility facade.
 *
 * New application code should prefer the focused services under the domain
 * namespace. Existing callers keep the historical FQCN and method surface
 * while the legacy implementation is retired capability-by-capability.
 */
class InquiryService extends LegacyInquiryService
{
    public const FINAL_STATUSES = LegacyInquiryService::FINAL_STATUSES;
    public const AUTO_READY_STATUS = LegacyInquiryService::AUTO_READY_STATUS;
    public const AUTO_IN_PROGRESS_STATUS = LegacyInquiryService::AUTO_IN_PROGRESS_STATUS;
    public const AUTO_COMPLETED_STATUS = LegacyInquiryService::AUTO_COMPLETED_STATUS;
}
