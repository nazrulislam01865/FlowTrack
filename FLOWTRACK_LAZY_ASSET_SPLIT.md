# FlowTrack route-aware frontend modules

The preserved compatibility renderer is maintained in:

```text
resources/js/flowtrack/flowtrack-dynamic-source.js
```

`npm run build` validates the source boundaries and generates:

| Module | Responsibility |
| --- | --- |
| `core.js` | API, CSRF, session, bootstrap and realtime runtime; login credentials are never handled here |
| `workspace.js` | Lightweight Clients and Documents behavior shared across authenticated screens |
| `operations.js` | Dashboard, Jobs, Tasks, Board, My Work and Reports behavior |
| `board.js` | Board and My Work responsive behavior |
| `configuration.js` | Workflow, Task Pack, Master Data and Setup |
| `records.js` | Standalone record routing and shell |
| `job-shared.js` | Shared detail loading and document integration |
| `task-details.js` | Final Task Details frontend |
| `job-details.js` | Jobs list, Create Job and final Job Details frontend |

The small `public/flowtrack-dynamic.js` loader downloads modules sequentially
only when their route or first interaction requires them. Generated files under
`public/flowtrack/modules/` must not be edited directly.

The login page loads only the core module and submits credentials with a native
HTTPS form. Board does not load Task Details or
Job Details until a record is opened, and Jobs does not load Task Details.

The former embedded prototype fallback is maintained separately in
`resources/js/flowtrack/prototype-fallback.js` and copied to `public/` during
the build. It is no longer embedded in a Blade template.
