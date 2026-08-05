# Navigation, Reload and Cache Stability Repair

This update replaces the previous mix of route restoration, lazy module loading,
page-data loading and realtime refresh with a deterministic navigation contract.

## Root causes found

1. Hard reloads revealed the parent page (Jobs/Board/etc.) before the requested
   Job/Task/Client detail route finished opening.
2. First-time navigation could require several JavaScript modules to download in
   sequence, while revisits used the browser cache and appeared almost instant.
   This made navigation timing feel random.
3. Page context and list requests were fully refetched after reload even when the
   tab already had a recent version-matched snapshot.
4. Background bootstrap refreshes used the same generation counter as operational
   list requests, so a realtime refresh could make a foreground list request stale.
5. A slow background refresh could repaint/reopen the page captured at the start
   of the refresh even after the user had navigated somewhere else.
6. Background stale-while-revalidate could re-render a page even when the data was
   unchanged, creating unnecessary visual flashes.
7. Page context queries were repeatedly rebuilt server-side even though workspace
   writes already have a versioned cache invalidation mechanism.
8. Some internal FlowTrack links still used full document navigation instead of
   the in-place route loader.

## Permanent changes

- The exact URL is prepared while the app shell is hidden on browser reload.
  Job/Task/Client details no longer briefly show their parent list page first.
- Recent page contexts and bounded first-page list snapshots are stored in
  `sessionStorage` for 120 seconds and are accepted only when their workspace
  version matches the server bootstrap version.
- Every successful mutation clears the tab-local runtime cache immediately.
- External changes invalidate the cache automatically when the workspace version
  changes.
- Cached screens use stale-while-revalidate. Fresh data is fetched in the
  background and the page is only re-rendered when the visible data signature
  actually changed.
- Bootstrap requests and operational list requests now have separate generation
  counters. A realtime/bootstrap refresh can no longer cancel a foreground list
  fetch.
- Foreground navigation pauses scheduled background workspace refreshes.
- A background refresh verifies that the user is still on the same path/page
  before it is allowed to repaint anything.
- Independent first-load requests run concurrently where safe. Jobs can load its
  context, Clients and Jobs without an unnecessary waterfall. Jobs/Tasks remain
  ordered on pages where their relation requires deterministic merging.
- Direct Job/Task/Client detail reloads load only the required page context plus
  the authoritative detail endpoint instead of loading the entire parent list
  first.
- Remaining runtime modules are preloaded only during browser idle time, so first
  app paint is not delayed while later page navigation is faster.
- Internal FlowTrack URLs handled through `flowtrackNavigateTo()` now use the
  in-place route loader when possible instead of forcing a full page reload.
- Page context payloads are now cached server-side for 30 seconds using the same
  workspace-version invalidation already used by the bootstrap cache.
- Page/list reads use bounded request timeouts (15s page/list, 18s bootstrap) so a
  bad request cannot leave the interface waiting indefinitely.
- The circular page-navigation loader now has a short minimum visible duration so
  cached navigation does not produce a one-frame flash.

## Cache layers after this repair

1. **HTML shell:** deliberately `no-store` because it contains authenticated,
   session-specific state.
2. **Static JS/CSS:** build-versioned browser/Nginx caching remains unchanged.
3. **Bootstrap configuration:** Laravel versioned cache (existing).
4. **Page context:** Laravel versioned 30-second cache (new).
5. **Operational list pages:** authoritative paginated API; not server-cached to
   avoid hiding real operational changes.
6. **Tab runtime cache:** version-matched `sessionStorage` snapshots for fast
   reload/revisit, automatically revalidated and invalidated after writes.

No database migration is required.
