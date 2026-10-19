# Order Details inline picker anchor fix — 2026-08-21

## Problem

Order Details reused the shared remote-user picker, but the existing assignee/owner value and the picker trigger were both rendered. This produced a second select-like control below the visible name. In stage cards, the generic `.stage-owner button` rule also leaked into the remote menu and collapsed option spacing/typography.

## Fix

- The currently displayed avatar/name is now the actual picker anchor for:
  - task assignee
  - stage/phase assignee
  - Planning & ownership → Order owner
  - Order header owner chip
- Added an `externalTrigger` mode to `x-ui.inline-remote-user` so the reusable remote menu can be opened from an existing visible control without rendering a duplicate trigger.
- `FlowTrackInlineEdit.openRemotePicker()` now passes the real anchor element to the shared search-select runtime.
- Shared dropdown positioning accepts an external anchor element and recalculates its position on resize/scroll.
- Stage-card button styling is scoped so it no longer collapses dropdown option buttons.
- Stage assignee picker typography/spacing is explicitly protected inside the narrow workflow cards.

## Behavior

The value remains visible in place. Clicking the avatar/name/pencil opens the searchable dropdown directly beneath that same visible value. Selecting an option still uses the existing immediate-save Livewire actions and existing shared user search endpoint.

No workflow sequencing, permissions, task persistence, owner persistence, queries, or migrations were changed.
