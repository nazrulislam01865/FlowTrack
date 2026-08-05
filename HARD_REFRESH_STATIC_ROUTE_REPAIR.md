# Hard Refresh Static/Form Route Repair

## Root cause

The standalone route mapper checked `record-task-*` before the more specific
`record-task-pack-*` pages. As a result, the Task Pack create page ID
`record-task-pack-new` was converted to `/flowtrack/view/tasks/pack-new`.
On browser refresh that URL was classified as a Task Details URL and the API
attempted to find a task named `pack-new`, producing `No query results for
model ... FlowTask` and leaving/falling back to the wrong parent page.

The same route architecture also left workflow-copy URLs non-canonical and
allowed asynchronous create/edit forms to be revealed before their standalone
page was actually mounted.

## Repair

- Specific create/edit/copy routes are resolved before generic task/job/client
  record prefixes.
- Task Pack create/edit now use canonical `/task-packs/...` URLs.
- Workflow duplicate uses canonical `/workflows/{record}/copy`.
- Old malformed Task Pack/workflow URLs are recognised and canonicalised.
- Laravel has explicit compatibility redirects before `/tasks/{record}`.
- Exact hard-refresh form restoration now waits until the standalone form is
  mounted before the application content is revealed.
- Background refresh treats workflow-copy as an editing route and does not
  interrupt it.
- Runtime assets were regenerated after the source changes.

## Regression coverage

The route regression checks cover Task Pack create/edit, Task Details, Job
create, Client create, and Workflow copy path generation/parsing. A Laravel
feature test was also added for canonical configuration-form routes and legacy
Task Pack redirects.
