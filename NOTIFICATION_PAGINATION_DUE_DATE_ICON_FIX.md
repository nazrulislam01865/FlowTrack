# Notification Pagination + Due Date Calendar Consistency

This update was applied to Archive 11(1).

## Notifications

- `/flowtrack/notifications/feed` now uses Laravel server-side pagination.
- Default Notifications page size: 20.
- Available page sizes: 10, 20, 30, 50.
- Added Previous / Next controls and `Page X of Y` display.
- Added `Showing X-Y of Z Notifications` display.
- The global unread badge is independent of the current notification page and continues to show the total unread count.
- Background/realtime notification refreshes no longer replace a paginated Notifications page with an unrelated 50-row feed.
- Stale notification requests cannot overwrite newer page navigation.
- Mark All Read updates the global unread count immediately.
- Reading one notification uses the unread count returned by the backend.

## Due-date/calendar consistency

The Task Details calendar SVG is now reused for:

- Task Board task cards.
- My Work task cards.
- Job Board next-action due dates.
- Job Board delivery display.
- Jobs table delivery column.

Removed the mixed emoji/square calendar symbols from those areas.

The card `Set due date` action now uses the same quiet 11px/500-weight visual treatment as the card `Updated today` text, while remaining clickable and retaining the native date picker.

## Main files changed

- `app/Http/Controllers/FlowTrack/NotificationController.php`
- `app/Http/Controllers/FlowTrack/Api/ReadApiController.php`
- `resources/js/flowtrack/modules/core.js`
- `resources/js/flowtrack/modules/operations.js`
- `resources/css/flowtrack/app.css`
- `resources/css/flowtrack/runtime/17-notification-pagination-calendar-consistency.css`
- Generated `public/flowtrack/*` runtime files
- `public/build/manifest.json`
- Generated cache-busted CSS asset in `public/build/assets/`

## Validation

- PHP syntax validation passed.
- FlowTrack JavaScript syntax validation passed.
- Generated runtime verification passed.
- CSS manifest/build verification passed.
