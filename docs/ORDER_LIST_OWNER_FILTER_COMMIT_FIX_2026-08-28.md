# Order list owner filter commit fix — 2026-08-28

## Problem

The Orders page owner selector could display the chosen user in Alpine while the table remained unfiltered. The table query itself already supported `flow_jobs.owner_id`; the failure was in the UI-to-Livewire commit path for the remote selector.

## Fix

- The Orders owner filter now uses the explicit `applyOwnerFilter` Livewire action.
- The action validates the target property, normalizes the selected user id, clears the summary metric, resets row selections, and resets pagination before the next render.
- Action-backed shared search selects call their Livewire action immediately from the selection click instead of deferring that action to a later Alpine `$nextTick`.
- Generic search selects that use `$wire.set(...)` keep their existing behavior, so this change does not alter unrelated filters.
- The existing server query remains the source of truth and continues filtering by `flow_jobs.owner_id`.

## Result

Selecting an owner immediately rerenders the Orders table with only Orders whose `owner_id` matches the selected FlowTrack user. Clearing the selector restores all owners.
