# FlowTrack Navigation Stability Repair V2

Base project: **Archive(11)**.

Archive 10(3) was used only as a reference to roll back the previous broken
navigation/cache patch. The application feature set from Archive(11) is preserved.

## Root causes repaired

1. Background bootstrap refreshes and operational page/list requests shared one
   generation counter. A realtime heartbeat could invalidate an in-flight
   Clients/Jobs/Tasks/Documents request, producing random waits or partial/empty
   screens.
2. On a hard reload of a Job/Task/Client URL, the application revealed the
   parent Jobs/Board/Clients page before the asynchronous detail renderer
   finished.
3. Initial read-only detail routes loaded the full parent list before fetching
   the authoritative detail record.
4. First navigation to a lazily-loaded area could feel much slower than later
   navigation because the browser had not fetched the required runtime scripts.
5. A realtime refresh that started before navigation could repaint the page that
   was open at the start of that refresh after the user had already moved away.
6. The circular navigation loader appeared even for extremely fast cached
   navigation, which looked like a flash.

## Changes

- Removed the previous session/tab page-data caching layer completely.
- Kept authoritative page-by-page API loading and safe in-memory page state.
- Added a dedicated operational request generation counter. Background bootstrap
  refreshes no longer cancel foreground Clients/Jobs/Tasks/Documents requests.
- Real mutations still invalidate older operational reads.
- Realtime refresh is deferred while foreground navigation is active.
- Realtime refresh verifies the browser path before repainting the visible page.
- Initial route restoration is asynchronous and awaited.
- Job/Task/Client hard reloads keep content hidden until the exact detail route
  finishes rendering, eliminating the parent-page jump.
- Read-only Job/Task/Client detail reloads load page configuration plus the
  authoritative detail endpoint instead of the full parent list first.
- Create/edit routes retain their full parent-page data requirements.
- Remaining runtime scripts are prefetched during browser idle time. This warms
  the normal browser cache without executing modules early or caching stale page
  data.
- The circular page loader is delayed 140 ms, so fast navigation completes with
  no loader flash; slower navigation still shows percentage feedback.
- Browser back/forward now awaits exact route restoration and participates in
  the same foreground-navigation guard.

## Cache contract after this repair

- Static JS/CSS: normal browser cache, versioned by the runtime build hash.
- Runtime page data: in-memory only and authoritative for the current tab.
- Operational Jobs/Clients/Tasks/Documents: fetched from their paginated API
  endpoints; no persistent sessionStorage snapshot is restored.
- Board-mode UI preference remains in sessionStorage because it is a UI setting,
  not operational data.
- Background refresh cannot override active foreground navigation.

No database migration is required.
