# Task/Job ID Copy + Work Surface Height Fix

## Task Details identifiers
- The Task ID remains in the breadcrumb and is copyable.
- The identifier above the Task title now shows the related Job ID instead of repeating the Task ID.
- The new Job ID uses the same copy-to-clipboard interaction style as Job Details.
- The Job Context card also exposes a compact copy action next to the linked Job ID.

## IDs elsewhere
- Job IDs shown in My Work task cards/job groups are copyable.
- Job IDs shown on the Operations Board are copyable.
- Job IDs shown in the Jobs list are copyable.
- Existing click-to-open behavior remains unchanged; the copy icon stops propagation and only copies the ID.

## My Work / Jobs height
- Desktop My Work board height is calculated from the live viewport and its actual top position.
- The footer remains near the bottom of the available screen instead of leaving a large blank area below the board.
- The board becomes its own vertical scroller when the content exceeds the available height.
- The Jobs table uses the same viewport-fit behavior before its pagination footer.
- Tablet/mobile layouts retain natural document scrolling and are not forced to a fixed height.

## Main source files changed
- `resources/js/flowtrack/modules/core.js`
- `resources/js/flowtrack/modules/task-details.js`
- `resources/js/flowtrack/modules/operations.js`
- `resources/css/flowtrack/runtime/16-id-copy-viewport-fit.css`
- `resources/css/flowtrack/app.css`
- generated `public/flowtrack/*` runtime assets
- generated `public/build/manifest.json` and compiled CSS asset
