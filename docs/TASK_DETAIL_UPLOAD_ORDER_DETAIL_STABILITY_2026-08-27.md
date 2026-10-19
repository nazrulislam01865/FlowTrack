# Task Detail Upload + Order Detail Stability Fix — 2026-08-27

## Task Details attachments

The Task Details attachment area keeps the current compact drag/drop design and no longer depends on the page being opened in a separate `taskEditMode` state. Upload availability now follows the actual task authorization plus the existing Documents/Create permission.

The backend now also verifies that the current user can edit the selected task before accepting an upload or delete request. Required Task Pack documents uploaded from Task Details use the same requirement-aware storage path as uploads from the Order taskflow, so the requirement is satisfied consistently.

The removed `Choose from Documents` control was not restored.

## Intermittent Order Details break

The issue was caused by progressive section observers being able to finish after Livewire had already switched to a different Order/Task/tab. Those stale requests could hit the shared `loadDetailSection()` method with the wrong page context. In addition, sections force-loaded by a modal/form were only marked ready in a local render variable; after the interaction closed, the real section could revert to a skeleton and cause a large DOM/layout remorph.

The fix:

- scopes Order/Task progressive loaders to the concrete entity id;
- includes that context in the loader `wire:key`;
- ignores stale observer callbacks instead of returning a 422 into the active Livewire page;
- persists any interaction-forced section readiness in Livewire state so loaded sections do not disappear back to skeletons;
- keeps the existing viewport-based progressive loading rather than disabling it.

No database migration is required.
