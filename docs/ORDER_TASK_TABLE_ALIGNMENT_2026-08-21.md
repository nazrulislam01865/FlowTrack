# Order task table alignment update

The Order Details workflow task table now uses one shared six-column grid for the header and every task row.

- task icon, task details, assignee, due date, status/files, and action use identical column tracks
- assignee avatar/name/reassign are grouped into a stable two-row cell
- due date is centered consistently
- status/files is left-aligned and stacked cleanly
- actions are right-aligned with non-wrapping buttons
- responsive breakpoints preserve alignment and switch to a stacked mobile layout below 820px

No workflow sequencing or backend task logic was changed.
