# Hard Refresh Exact Route Fix

## Problem
Refreshing a standalone record URL such as `/flowtrack/view/tasks/tsk-000352` could end on the Board page. Startup first called the normal application renderer with `board` as the Task route's parent page. That parent navigation could become the final route before the standalone Task renderer completed.

## Fix
- Exact Task/Job/Client/form URLs are now treated as authoritative during startup.
- Startup reveals the application shell without navigating to the parent page first.
- The parent page is only primed as sidebar/back-navigation state.
- The standalone record is opened directly from the URL.
- Browser back/forward uses the same exact-route behavior and loads only page context for record routes instead of the full parent list.
- Normal top-level page routes continue to use the existing page-by-page navigation path.

## Result
A hard reload on Task Details remains on that Task Details URL and screen. The same behavior applies to Job Details and Client Details/forms.
