# FlowTrack route-aware runtime

The previous application-sized JavaScript source has been removed. Canonical source is split under `resources/js/flowtrack/modules/` and generated into `public/flowtrack/modules/` by `scripts/build-flowtrack-runtime.mjs`.

The loader starts with core/workspace behavior, then loads operational, board, configuration, record or detail modules only when the current URL or user interaction needs them.

Main groups:

| Module | Responsibility |
| --- | --- |
| `core.js` | API, CSRF, session, segmented bootstrap, realtime primitives and common state |
| `workspace.js` | shared workspace/client/document behavior |
| `operations.js` | dashboard, jobs, task/job boards, My Work and reports |
| `board.js` | board/My Work responsive interaction helpers |
| `configuration.js` | job configuration, workflows, task packs, master data, access and profile UI |
| `records.js` | standalone record routing/shell behavior |
| `job-shared.js` | shared detail/document integration |
| `task-details.js` | Task Details behavior |
| `job-details.js` | Jobs list/create/edit/details behavior |

Login does not load operational/detail modules. Record/detail code is deferred until a matching route or interaction requires it.
