# Master Data Task Status / Priority Color System

## What changed

- Added a configurable HEX color code to Master Data → Task Statuses.
- Added a configurable HEX color code to Master Data → Priorities.
- Colors are stored in the existing `master_records.metadata.color` JSON field, so no schema migration is required.
- Existing records without an explicit color use semantic fallback colors until saved/edited.
- Fresh demo seeds now include default colors for all seeded Task Statuses and Priorities.

## Where colors are used

Configured colors are resolved centrally and used across the maintained FlowTrack UI, including:

- Task Details status and priority indicators
- Task Details status menu
- Task dependency status text
- Task Board / My Work status headers
- Task Board / My Work priority badges
- Job Board expanded phase-task status pills
- Job Details task status controls/badges
- Job Details job priority badge / planning priority
- Task/phase history status badges
- legacy/fallback task surfaces that are still retained for compatibility
- Job creation priority choices
- Task Pack priority summaries

## Validation

Only 6-digit HEX values are accepted, e.g. `#2563EB`.

The backend validates the value and persists it in Master Data. Frontend validation prevents malformed values from being submitted.
