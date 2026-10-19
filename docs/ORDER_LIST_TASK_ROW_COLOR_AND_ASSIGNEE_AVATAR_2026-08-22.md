# Order list task row color and assignee avatar — 2026-08-22

## Changes

- Each Order row now takes its subtle background/accent color from the current active task's Task Pack item color.
- The row no longer uses the workflow-stage color as its row background. Stage chips continue to use stage colors.
- The active task is resolved with the same next-task logic already used for the Order list action.
- If a manually added task has no configured Task Pack color, the row remains neutral instead of inventing a color.
- Owner and current-stage assignee profile images now render through the existing protected `profile-images.show` route.
- Initials remain the fallback when a user has no uploaded photo or an image cannot be loaded.

## Scope

No workflow transitions, filtering, assignment logic, stage logic, or Order visibility rules were changed.
