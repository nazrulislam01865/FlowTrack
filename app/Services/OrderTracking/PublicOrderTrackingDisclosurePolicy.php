<?php

namespace App\Services\OrderTracking;

use App\DTOs\OrderTracking\PublicOrderTrackingIdentity;

/**
 * Phase-1 public disclosure policy.
 *
 * The identity resolver is intentionally broader than the public page so the
 * backend can recognize order-linked users/suppliers without immediately
 * granting them the same customer-facing disclosure. Until audience-specific
 * rules are approved, the live public result keeps the pre-existing customer
 * boundary only: client, structured client contact, or invoice billing contact.
 */
final class PublicOrderTrackingDisclosurePolicy
{
    public function allowsLiveResult(PublicOrderTrackingIdentity $identity): bool
    {
        return $identity->has('client')
            || $identity->has('client_contact')
            || $identity->has('billing_contact');
    }
}
