# Upload / Attachment and Task Detail Freshness Fix

## Fixed upload controls

FlowTrack now uses a stable delegated native file-picker bridge for all dynamically rendered attachment/file controls. The bridge covers:

- Task Details Browse
- legacy Task attachment Browse
- Job Details document Browse
- Job document upload modal Browse
- New Job attachment Browse
- Profile photo upload
- System logo/favicon upload

The Browse controls are excluded from the generic button loading interceptor, and selecting the same file again after a failed upload is supported.

## Fixed Choose from Documents

Stored-document choosers no longer depend on the current page's in-memory `docs` array. Opening a chooser explicitly fetches fresh paginated document records from `/flowtrack/template-api/lists/documents` (bounded to five 100-record pages), merges them into the local store, and then renders the chooser.

This preserves normal page-by-page loading during navigation while making explicit stored-document selection reliable from Job Details, Task Details, document-upload modals, and New Job.

## Fixed stale Task Details

Job Details preloads its phase tasks and marks them detail-loaded. Previously, that caused `openTask()` to reuse the Job Details snapshot and skip the dedicated Task endpoint. Board tasks frequently did not have that flag, so opening the same Task from Board could show a newer record.

Task Details now always refreshes from `/flowtrack/template-api/tasks/{id}` with `cache: no-store` before rendering. In-flight requests are deduplicated per Task and a navigation sequence guard prevents an older response from replacing a newer Task the user opened.
