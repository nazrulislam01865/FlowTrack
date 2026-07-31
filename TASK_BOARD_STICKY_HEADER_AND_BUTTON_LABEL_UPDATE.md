# Task Board Sticky Header and Task Button Label Update

## Changes

- Made the Task Board status/header row sticky while scrolling vertically.
- Kept the sticky row aligned with horizontal board scrolling.
- Added shadow/background to keep the sticky header readable over task cards.
- Renamed Task Details buttons:
  - Secondary button: `Draft`
  - Primary button: `Save`
- `Draft` saves editable task fields without uploading a selected document file.
- `Save` keeps the existing full-save behavior and uploads the selected document only when Save is clicked.

## Validation

- JavaScript syntax check passed.
- PHP syntax check passed for app, config, routes, database, and Blade files.
- FlowTrack asset verification passed.
