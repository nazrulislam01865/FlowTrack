# FlowTracker order reminder: dynamic recipients

Updated from Archive 3(4).zip. The Reminder Setup screen retains its existing Step Promo layout, theme variables and form controls.

## Recipient selection

Each reminder has one recipient target configured in **Trigger & Recipients**:

- Supplier assigned to the actual order product (multiple suppliers produce one supplier-specific email per supplier)
- Active order owner
- Active order coordinator
- Active completed-task assignee
- Client email from the linked Client master record
- Primary contact of the linked Client
- Specific active FlowTracker user selected from the workspace (subject to that user's Order view permission at send time)

Create additional reminder templates for the same workflow event to send to other recipient targets. Publishing a template replaces an active template only for the **same event, recipient type, and selected user**. Different recipients can have separately published templates and email bodies.

The configured recipient is resolved **when the workflow action completes**, never from the browser's preview values. Unknown, inactive, unrelated or invalid recipients are skipped and appear in Delivery History. No guessed destination, unrelated product-linked supplier, or alternate contact is substituted. Order workflow completion is not blocked.

All real delivery remains gated by the central **Order Email Service** admin control and is queued after workflow completion commits. A paused template no longer sends, even if its dispatch job was queued earlier. The delivery table now has a `recipient_key` for safe unique task/recipient claims; older supplier records are backfilled with their existing supplier identities.

## Setup

Run the regular Laravel migration command from your project root:

```bash
php artisan migrate
php artisan optimize:clear
```

**New migration:** `database/migrations/2026_10_08_000003_add_reminder_recipient_key.php`. It is safe to retry if MySQL applied the column but stopped before creating the index.

Use your existing email queue worker and centralized provider configuration. A published configuration is required to send real reminders.

## Tests / deployment validation

PHP syntax and JavaScript syntax were checked, as were source-level paths for recipient resolution, saved configuration, duplicate-send uniqueness and centralized email control. Laravel/MySQL integration tests and real provider sends cannot be completed with the supplied archive because Composer vendor dependencies and a connected test database are not included.

After deployment, test one published supplier reminder and one published order owner/client reminder against a sample completed workflow task. Check Delivered/Skipped status in Delivery History and the centralized email control. Confirm that unrelated order users are skipped.
