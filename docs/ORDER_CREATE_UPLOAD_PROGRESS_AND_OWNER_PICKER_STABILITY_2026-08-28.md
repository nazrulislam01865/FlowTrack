# Create Order upload progress + Order owner picker stability — 2026-08-28

## Create Order attachments
Both Create Order upload areas now show actual Livewire transfer progress:

- **Purchase Order** shows a percentage and progress bar while `purchaseOrderUpload` is transferring.
- **Other document** shows the same feedback for `jobAttachments`.
- Progress is local to the upload that is running, reaches 100%, then clears after the transfer finishes.
- Existing file validation, temporary upload handling, draft creation, and final Order creation logic are unchanged.

## Repeated owner changes on Order Details
The owner picker could visually collapse after repeatedly opening/searching/selecting owners from the header and Planning & ownership controls.

Two client-side states combined to cause it:

1. a search response uses 20 rows and its `perPage` value leaked into later blank-query/recent opens, changing the owner picker from the intended compact 5-row page to a 20-row page;
2. a previously constrained flex menu could then be remeasured at its collapsed height, leaving the option list with effectively no visible area even though rows were loaded.

The shared remote-selector runtime now keeps a separate compact `recentPerPage`, clears stale inline positioning on close, and measures the option list while both the menu and list are temporarily unconstrained. The two Order owner controls also use unique listbox IDs, and Order Details has a small scoped option-viewport safety floor.

No owner authorization, persistence, synchronization, or workflow business logic changed.
