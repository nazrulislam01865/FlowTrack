# Order Redo Activity Section — 2026-08-25

## Requirement
Match the approved Redo prototype by keeping the existing Redo audit trail inside the Redo cards and also showing the normal Order **Activity** section underneath the Redo page.

## Files changed

### `resources/views/components/jobs/detail.blade.php`
The Redo tab now renders the shared `x-jobs.order-detail.activity` component immediately below `x-jobs.order-detail.redo-panel`.

This preserves the existing Activity UI and behavior:
- All / Comments / History tabs
- Rich-text comments
- @mentions
- pasted screenshots/images
- pagination
- existing permissions

### `app/Livewire/Jobs/Concerns/BuildsOrderPageData.php`
The paginated activity loader now runs for both `overview` and `redo` tabs.

```php
if (in_array($this->detailTab, ['overview', 'redo'], true)) {
    $orderQuery->loadOverviewActivity(
        $selected,
        $this->jobActivityTab,
        $this->jobActivityPage,
        10,
    );
}
```

## Why reuse the existing Activity component?
The prototype shows two different concepts:
1. **Redo audit trail** — the compact Redo lifecycle summary.
2. **Activity** — the normal Order activity stream beneath the Redo area.

FlowTrack already has the authoritative Activity implementation, so the Redo tab reuses it rather than duplicating comments/history or storing a second activity feed.

## Result
The Redo tab now renders in this order:

1. Redo order relationship
2. Redo financial impact
3. Redo audit trail
4. Activity — All / Comments / History

No migration is required.
