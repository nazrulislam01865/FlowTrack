# Order Details Owner Instant Sync — 2026-08-28

## Problem

Order Details renders the Order owner in two independent Alpine inline-edit controls:

- the compact owner chip in the page header; and
- the **Planning & ownership** card.

`updateJobOwner()` is intentionally `#[Renderless]`, so saving the header owner persisted the database change without re-rendering the second control. The Planning & ownership card therefore kept its original Blade/Alpine value until a page refresh.

## Fix

Both owner controls now use a small page-local synchronization contract:

1. Save the owner through the existing renderless `updateJobOwner()` action.
2. On a successful save, publish `ft-order-owner-updated` with the Order id, canonical owner id/name, avatar URL, and source control key.
3. Both visible owner controls listen for the event and update their current value, saved value, display label, draft value, and avatar state.
4. The event is scoped by Order id so no other Order can be affected.

The server action now also returns canonical `value`, `display`, and `avatarUrl` fields after a successful owner save. This keeps the client synchronized with the actual persisted owner rather than relying only on the selected dropdown label.

## Behavior preserved

- Existing authorization and `UpdateOrderOwner` business logic are unchanged.
- The owner save remains renderless; no full page refresh was added.
- Existing optimistic save/error handling and retry behavior remain intact.
- The remote user picker behavior is unchanged.
- Changing the owner from either location now updates the other location immediately after the save succeeds.

## Regression coverage

`tests/Feature/OrderDetailOwnerInstantSyncTest.php` verifies that both controls publish/listen to the shared owner update event and that the renderless backend returns canonical owner display state.
