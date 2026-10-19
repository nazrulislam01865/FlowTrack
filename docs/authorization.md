# FlowTrack authorization and workspace boundary

## Request authorization flow

```text
Route authentication / permission middleware
        ↓
Policy or application Action/Query authorization
        ↓
AccessControlService dynamic RBAC + record scope
        ↓
WorkspaceContext for workspace-keyed records
        ↓
Scoped query / authorized write
        ↓
Eloquent + audit / notification / transaction
```

`AccessControlService` is the source of truth for dynamic module/action/scope semantics. Policies are adapters around that service, not a replacement permission system.

## Rules

- Sensitive records must be scoped before hydration; never fetch by ID and decide visibility only afterwards.
- Every write re-authorizes server-side in its Action/domain service boundary.
- Blade button visibility is not authorization.
- Actions that can be called outside Livewire must not rely on a prior UI check.
- Workspace-aware records must use the request-scoped `WorkspaceContext`/existing workspace-aware service scopes.
- Do not add a global workspace scope to a model until cross-workspace reads, background jobs, migrations and administration use cases are covered by tests.
- `AccessControlService` behavior must remain stable unless a separately approved permission change is requested.

## Current policies

- Orders: `FlowJobPolicy`
- Inquiries: `InquiryPolicy`
- Order tasks: `TaskPolicy`
- Inquiry tasks: `InquiryTaskPolicy`
- Documents: `DocumentPolicy`
- Clients: `ClientPolicy`

## Current workspace limitation

The executable schema does not place `workspace_id` on every business table. WorkspaceContext is therefore enforced only where the data model already has a real workspace key. Existing AccessControl record scopes remain authoritative for non-workspace-keyed tables. Do not simulate tenant isolation with guessed joins or implicit IDs.
