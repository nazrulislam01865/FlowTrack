# Job Details complete phase/task snapshot fix

## Problem
Opening a Job from **Jobs** could render only the task slice already present in the Jobs-page state, while opening the same Job from **Board/My Work** appeared complete because those pages had already loaded more task records.

The Job Details loader also trusted `_detailLoaded`, which described the Job record itself but did not guarantee that the global frontend task array contained the complete task set for that Job.

## Fix
- Job Details now always fetches a fresh authoritative Job-detail payload before rendering, regardless of source page.
- The server marks Job-detail task payloads as a complete task set (`taskSet.complete`).
- A complete task set **replaces** that Job's cached task slice atomically instead of merely upserting into it.
- Job documents from the complete detail snapshot are replaced atomically as well.
- Detail responses now use `no-store` cache headers.
- If an older endpoint response lacks the completeness marker, the frontend falls back to the dedicated `/jobs/{job}/tasks` endpoint before rendering.
- A navigation sequence guard prevents a slower previous Job request from overwriting a newer Job Details navigation.

## Result
Jobs → Job Details, Board → Job Details, My Work → Job Details and direct Job URLs now use the same complete phase/task snapshot before the phase accordion is rendered.
