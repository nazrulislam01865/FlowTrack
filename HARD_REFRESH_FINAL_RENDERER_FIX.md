# Hard Refresh Final Renderer Fix

## Problem
A hard refresh of an exact Task Details URL could keep the correct URL but show the legacy Task Details UI. The final Task Details template was implemented as a wrapper around the older staged `openTask` renderer. That meant the legacy renderer still ran first, and startup timing could expose or leave that older UI during a browser reload.

Job Details had a related timing risk because its final reference template was applied from `setTimeout(0)` after the older renderer had already resolved.

## Fix
- Task Details final renderer no longer calls the legacy `openTask` stage chain.
- The final Task Details module now fetches the authoritative task payload directly and owns creation/activation/rendering of the standalone Task page.
- The same final Task renderer is used for hard refresh, Board, My Work, Job Details task links, browser Back/Forward, and direct Task URLs.
- Exact Task route restoration refuses to fall back to the old renderer if the final Task module failed to initialize.
- The loader verifies the final Task/Job renderer markers before revealing exact detail routes.
- Job Details applies its approved final template synchronously before `openJob` resolves; the delayed second pass remains only as a layout self-heal.
- Runtime assets were regenerated with a new build hash, so browsers request the updated modules.

## Result
Refreshing `/flowtrack/view/tasks/{task}` now keeps the exact Task URL and renders the same approved Task Details design as in-app navigation. The legacy Task renderer is no longer part of the final Task Details paint path.

No database migration is required.
