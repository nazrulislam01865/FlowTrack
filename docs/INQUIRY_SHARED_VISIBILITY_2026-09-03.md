# Inquiry shared visibility — 2026-09-03

## Requirement

Inquiry records are shared operational records inside the current workspace.

Any active user whose effective role access grants **Inquiries → View** must see the complete Inquiry list for that workspace. Visibility must not depend on who created the Inquiry, who owns it, or who is assigned to one of its tasks.

Users without **Inquiries → View** must not see Inquiry records.

## Action permissions remain independent

Shared visibility does not grant write access. The existing Inquiry action permissions continue to control what a user can do:

- Create controls creation.
- Edit Own / Edit All controls editing according to the existing edit authorization rules.
- Delete controls deletion.
- Assign controls assignment actions.
- Export controls export actions.
- Manage continues to act as the module-wide action override where already supported.

Permissions from related modules such as Tasks, Documents, Finance, Products, and Orders continue to be checked by their existing boundaries.

## Implementation

`AccessControlService::applyInquiryScope()` is the canonical Inquiry read boundary. It now returns the workspace-scoped Inquiry query unchanged after confirming that the actor has `inquiries.view` access.

`LegacyInquiryService::visibleQuery()` already limits the query to the current workspace before applying this access boundary. Therefore the Inquiry list, list metrics, exports, detail reads, policies, and dashboard Inquiry reads all receive the same shared visibility behavior without widening access across workspaces.

## Validation

`tests/Feature/InquirySharedVisibilityTest.php` now covers:

- two users with the same Inquiry View access seeing the same complete Inquiry list,
- unrelated Inquiries remaining visible to an Inquiry viewer,
- matching list metric visibility,
- View access not implicitly granting edit/delete/assign actions,
- users without Inquiry View access seeing no Inquiry records,
- administrator and super-admin full visibility.

No database migration is required.
