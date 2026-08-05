# Document Upload First-Click Route Fix

## Problem
On the first click of a required-document Upload control, the UI could send a request to `/flowtrack/template-api/tasks` with no Task identifier. Laravel correctly returned a route-not-found error. A later click often worked because the final document bindings/state had completed by then.

## Root cause
Some Job Details document requirement rows were rendered with the generic `data-task` navigation attribute even when no concrete Task ID was available (`data-task=""`). The shared Task click binder treats `data-task` as a navigation contract, so an empty document requirement could be interpreted as an empty Task link.

## Fix
- Job document requirement rows now use a document-specific `data-requirement-task` metadata attribute only when a real Task is resolved.
- Ungrouped Job document rows no longer emit `data-task=""`.
- Shared `[data-task]` navigation binds only when the Task identifier is non-empty.
- Task navigation ignores clicks originating from buttons, links, inputs, selects, textareas, and labels.
- All Task opening stages now reject blank identifiers before rendering or requesting Task Details.
- The authoritative Task Details loader also rejects a blank identifier before constructing an API URL.
- Runtime assets were rebuilt with a new build hash so browsers load the corrected handlers immediately.

No database migration is required.
