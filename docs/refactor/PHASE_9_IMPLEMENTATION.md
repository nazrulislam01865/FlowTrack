# Phase 9 implementation — Authorization, mass-assignment and workspace isolation hardening

Date: 2026-08-22

## Objective

Harden the current FlowTrack authorization/workspace model without changing the established `AccessControlService` semantics, routes, database schema or UI.

## Explicit model assignment

All 41 application models now declare explicit `$fillable` allowlists. There are no application models using unrestricted `$guarded = []`.

A small compatibility exception is intentional: models participating in the existing Workflow/Task Pack mirror/snapshot mechanism explicitly allow the `id` field because the current data model synchronizes shared identifiers across legacy and current setup tables. The Phase 9 gate specifically protects these identity allowlists so removal cannot silently break workflow synchronization.

No timestamps or soft-delete fields were broadly made assignable.

## Authorization architecture

The existing `AccessControlService` remains byte-for-byte unchanged and is still the dynamic RBAC/scope engine.

Policies were added for the principal record boundaries:

- `FlowJobPolicy`
- `InquiryPolicy`
- `TaskPolicy`
- `InquiryTaskPolicy`
- `DocumentPolicy`
- `ClientPolicy`

They delegate to `AccessControlService` instead of inventing a second permission model. Existing Actions/services continue to re-authorize on the server; Blade visibility is presentation-only.

Phase 9 also moved the invoice-email and client-contact replacement Action boundaries to accept the acting `User` and re-authorize before performing their direct writes.

## WorkspaceContext

`App\Services\WorkspaceContext` is request-scoped and is the source of truth for the active workspace. `SetupContext` remains as a compatibility adapter so existing setup code does not need a risky all-at-once rewrite.

The context provides:

- explicit request workspace selection (`set`);
- workspace resolution (`id`);
- query scoping before hydration (`scope`);
- membership of a record in the active workspace (`contains` / `assertModel`).

### Schema compatibility

The current executable schema is only partially workspace-keyed. Inquiry/setup/master-data/role/workflow records already carry workspace ownership and are hardened through the current context/scopes. Orders and Clients do not currently have a `workspace_id` column in the executable schema.

Phase 9 deliberately does **not** fabricate those columns or introduce an unreviewed tenant migration. Their current record isolation remains the existing `AccessControlService` scope. A future true multi-workspace storage migration must be explicit, data-migrated and rollback-tested rather than hidden inside this hardening phase.

## Security headers

`SecurityHeaders` is registered centrally in the web middleware stack and emits:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy`
- `Permissions-Policy`
- `Content-Security-Policy-Report-Only`

HSTS is emitted only when the application is running in production **and** the request is HTTPS.

CSP remains report-only in this phase so existing Livewire/Alpine behavior cannot be broken silently. Enforcement should happen only after CSP reports show the required source policy is complete.

## Cross-workspace coverage

`Phase9WorkspaceIsolationTest` verifies:

1. the request context includes only the selected workspace;
2. workspace-aware Inquiry queries receive the active `workspace_id` predicate before hydration;
3. `InquiryPolicy` rejects a record from a different workspace before applying normal RBAC record scope.

## Protected compatibility boundaries

Phase 9 requires these to remain unchanged from Phase 7:

- `AccessControlService` semantics;
- `routes/web.php`;
- database migrations;
- CSS source tree.

## Quality gate

Run:

```bash
npm run quality:phase9
```

The gate includes every Phase 0–8 gate and additionally verifies model assignment policy, policies, WorkspaceContext, security headers, cross-workspace test presence and the protected hashes above.

## Rollback

Policy/WorkspaceContext integration can be reverted independently because `AccessControlService` remains authoritative and `SetupContext` is retained as a compatibility adapter. The model allowlists should not be reverted to `$guarded=[]`; if a legacy write requires a missing field, add only that explicitly reviewed field.
