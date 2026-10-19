# Inquiry NEP/IID Row Color Priority Fix — 2026-08-24

## Problem
NEP and IID could both appear green in the Inquiry list. The client-specific CSS was already correct, but the Blade row-color priority replaced the client tone after any task had completed. For a fully completed Inquiry there is no active task color, so the code fell back to the Inquiry status color. `Completed` resolves to green, which made completed NEP rows green.

## Correct behavior
- IID with no active configured workflow color: light green.
- NEP with no active configured workflow color: light blue.
- After workflow progress, an actual configured **active Task Pack task color** may override the client color.
- A generic Inquiry status color must not override the client color merely because the Inquiry is completed.
- When a workflow has no active task (for example, all tasks are completed), the row returns to the client-specific base color.

## Change
Updated `resources/views/livewire/inquiries/sections/list.blade.php` so row priority is:

1. Active Task Pack task color, when the Inquiry has progressed and the active task has a configured color.
2. Otherwise IID/NEP client base color.
3. Otherwise normal table background.

No CSS or Vite rebuild is required for this fix.
