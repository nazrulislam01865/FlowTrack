# Inquiry + Task Detail SVG Icons and Task Inline Editing — 2026-08-27

## Scope

Updated the Inquiry Details overview and Order Task Details page to use the same clean outline SVG icon language used by the Order Details header.

## Inquiry Details

- Replaced text-glyph edit icons with reusable SVG edit icons.
- Replaced square date glyphs with outline calendar icons.
- Replaced Inquiry ID/reference copy glyphs with outline copy icons.
- Preserved existing inline edit behaviour for priority, assignee, due date, start date/time and description.

## Task Details

- Replaced text-glyph edit icons with reusable SVG edit icons.
- Replaced date glyphs with outline calendar icons.
- Updated title and description edit controls to use the same SVG edit icon.
- Inline edit availability is now based on the user's task edit/assignment permissions rather than on how the Task Details page was opened.
- A user who is authorized to edit the task can inline-edit title, assignee, status, priority, start date, due date and description even when the task was reached through a generic View action.
- Backend authorization remains unchanged: `TaskService::updateDetailField()` and assignment checks continue to enforce task permissions.

## Reusable UI

Added:

`resources/views/components/ui/detail-icon.blade.php`

Supported icons currently used by these pages:

- `edit`
- `calendar`
- `copy`

## Assets

CSS was updated in:

`resources/css/modules/application/03-orders-list-detail.css`

A Vite rebuild is required after deployment.

## Database

No database migration is required.
