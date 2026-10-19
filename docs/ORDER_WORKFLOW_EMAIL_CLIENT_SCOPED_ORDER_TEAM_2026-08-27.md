# Order workflow email: client-scoped Order Team recipients

## Requirement

`Send Artwork to Order Team` must not email every user who has the Order Team role. Recipient selection must also respect the user's **Business unit** configured in **Administration -> Users & role assignments -> Edit user**.

- NEP Order: recipients are active Order Team users whose Business unit is `NEP` or `Both IID & NEP`.
- IID Order: recipients are active Order Team users whose Business unit is `IID` or `Both IID & NEP`.
- A user configured for `Both IID & NEP` receives artwork handoffs for both clients.
- Users for the other business unit are excluded even when they have the Order Team role.

## Implementation

`App\Services\Orders\OrderWorkflowEmailService` now derives the business unit from the Order's client (`clients.code`, with a legacy name fallback) and filters the active workspace membership (`workspace_memberships.business_unit`) while resolving Order Team role members.

The same resolver is used by both the email preview and the actual send, so the recipient list shown before sending is the exact list passed to the centralized email service.

The preview's Recipient rule now identifies the client/business-unit filter, for example:

`Users & role assignments — Order Team role + NEP business unit (NEP or Both)`

If no matching recipient exists, the modal explains that an active user needs the Order Team role, a valid email, and the matching Business unit or Both.

## Purchase Order -> Artwork Team

This change does not alter the Purchase Order handoff. It continues to send to all unique active assignees in the Order's Artwork phase.

## Database

No new migration is required. The existing `workspace_memberships.business_unit` field is used.
