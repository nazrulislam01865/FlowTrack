# FlowTracker — Dynamic Order Reminder Events

## Scope

Updated from `Archive 2(4).zip`. The existing five Reminder Setup screens are preserved, with a dynamic Create Reminder event selector. No new database table or migration is required.

### Source of event choices

Active Order workflows in `workflow_templates`, their `workflow_phases`, their active `task_packs`, and task actions in `task_pack_items.automation_key`. Choices are loaded in a single scoped query on the admin reminder page. A legacy `ART_INTERNAL_REVIEW → confirm` choice remains for existing installations before their setup is populated.

Only **Order** actions are currently enabled. Inquiry-specific actions and other recipient roles are *not* silently advertised as working. A stable task action key is the event identity across reused Order workflows.

### Behavior

- Create Reminder: select stage and configured task action, then save draft.
- Generic task events trigger on successful completion through `OrderWorkflowActionService::complete()`.
- Artwork confirmation retains its original precise confirm action; revision/cancellation does not emit the reminder.
- Each event has at most one published active reminder per workspace; publishing a different event does not disable other events.
- The existing supplier selection is authoritative: the recipient is the supplier on each order item, **not** all product-linked suppliers.
- Missing/inactive suppliers and missing/invalid email addresses are skipped without reverting the workflow; email failures are handled by the existing queued delivery logic.
- Existing company branding, central Order Email Service enable/disable control, duplicate protections, template preview, test, visibility settings, and Delivery History are preserved.
- Non-artwork templates default to generic task-completion content, not an artwork confirmation claim.

### Deployment

Replace the modified project files, then run `php artisan optimize:clear`. Ensure `bootstrap/cache` exists and is writable; the package includes `bootstrap/cache/.gitignore` and does **not** bundle stale compiled routes/configuration. No migration is introduced by this update.

The queue worker for the configured email queue must be running to deliver automatic notifications. **Drafts do not send; publishing is required.**

### Verification / limits

PHP syntax and JavaScript syntax checks passed. Browser-level simulated Create Draft selected a Production event, submitted one correctly keyed request, populated the reminder list, and navigated to the correct rule screen without errors. Generic/artwork default event configuration passed standalone PHP checks.

A complete Laravel database-backed and provider-backed integration test requires your configured database and Composer vendor dependencies, which were not in the archive. Confirm on your local environment before production publishing.
