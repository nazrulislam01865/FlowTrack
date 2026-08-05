# FlowTrack Clients / Jobs / My Work Data Stability Fix

## Root cause
The shared bootstrap endpoint is intentionally shell-only, but it returned empty `clients`, `jobs`, `tasks`, and `documents` arrays for compatibility. Background/realtime calls to `loadBootstrap({ silent: true })` were still passing those empty arrays to `applyBootstrap()`. That temporarily cleared operational state before the current page reloaded its segment. If the second request was delayed, aborted, failed, or navigation happened in between, Clients, Jobs, My Work, Board, Dashboard, or Reports could appear empty until a later refresh.

## Fixes
- Operational records can now be reset only during the first authenticated application startup.
- Background bootstrap, realtime refresh, configuration refresh, and page-context payloads cannot replace operational arrays.
- Clients/Jobs/Tasks/Documents are now replaced only by their dedicated paginated list endpoints.
- The initial loader explicitly calls `loadBootstrap({ resetOperational: true })`.
- The bootstrap backend no longer emits empty operational placeholders in new responses.
- Cached/older bootstrap responses that still contain empty placeholders are safely stripped on the frontend.
- Forced background refreshes verify a shrinking snapshot a second time before committing it, so one transient empty/partial API response cannot wipe a populated list.
- Genuine deletions/permission changes still propagate because a second matching server response confirms the smaller list.

## Preserved behavior
- Page-by-page/lazy loading remains enabled.
- My Work incremental loading remains enabled.
- Realtime/background refresh remains enabled.
- Existing UI, routing, permissions, workflow behavior, and backend business logic are unchanged.

## Validation
- 185 PHP files passed syntax validation.
- All FlowTrack source and generated JavaScript passed `node --check`.
- Runtime assets were rebuilt.
- `scripts/verify-assets.mjs` passed.
