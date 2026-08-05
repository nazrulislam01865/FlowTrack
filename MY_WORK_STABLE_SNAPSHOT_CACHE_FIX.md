# My Work stable snapshot/cache repair

## Problem
My Work shared the same paginated `tasks[]` collection used by Board/Reports. Loading another task page while scrolling re-rendered the whole My Work surface, which recreated the board DOM and reset scroll position. Realtime workspace refreshes could also force-refresh every already-loaded task page.

## Fix
- Added `/flowtrack/template-api/my-work-snapshot`.
- The endpoint returns the complete visible My Work task set plus its related Jobs/Clients in one response.
- The server response is cached for 60 seconds using the existing workspace bootstrap cache version, so any successful FlowTrack mutation automatically moves future requests to a new cache key.
- Added a 90-second user-scoped `sessionStorage` snapshot on the frontend. The snapshot is accepted only when its workspace version still matches the server bootstrap version.
- My Work no longer participates in generic task auto-pagination.
- Board/Reports keep their existing page-by-page loading behavior.
- Realtime refresh of My Work is AJAX-only, refreshes the snapshot in the background, compares the snapshot signature, and only re-renders when data changed.
- When a background re-render is required, both window and board scroll positions are restored.
- Local Task status/due-date changes update the in-memory My Work snapshot immediately.
- Any successful mutation invalidates the short-lived browser snapshot so the next visit revalidates from the server.
- My Work navigation always shows the circular loader, including an empty-result transition.

## Configuration
Optional `.env` override:

`FLOWTRACK_MY_WORK_SNAPSHOT_CACHE_SECONDS=60`

No database migration is required.
