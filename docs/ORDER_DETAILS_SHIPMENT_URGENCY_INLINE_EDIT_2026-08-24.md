# Order Details Shipment Urgency Inline Edit — 2026-08-24

## Scope

Refined shipment urgency editing on the Order Details page without changing the underlying order urgency persistence logic.

## Behaviour

- The shipment urgency badge in the Order Details header is now directly editable in place.
- Clicking the pencil opens a compact urgency dropdown beside the existing command-bar controls instead of scrolling to Planning & ownership.
- Planning & ownership uses the same reusable urgency editor.
- The previous full-width `Cancel` and `Save` buttons were replaced with compact cross (`×`) and tick (`✓`) icon controls.
- `Enter` saves and `Escape` cancels while the urgency select is open.
- Saves continue to use `updateJobUrgencies(..., 'shipment', ...)` and the existing `UpdateOrderUrgencies` action.
- The shared inline-edit helper preserves optimistic UI, rollback on failure, and retry/error-toast behaviour.
- A successful save refreshes the Livewire order detail once so both header and Planning & ownership show the same persisted urgency immediately.

## Files

- `resources/views/components/jobs/order-detail/shipment-urgency-inline.blade.php`
- `resources/views/components/jobs/order-detail/header.blade.php`
- `resources/views/components/jobs/order-detail/planning.blade.php`
- `resources/views/components/jobs/detail.blade.php`
- `resources/css/modules/orders/detail/detail-02.css`
- `public/build/manifest.json`
- `public/build/assets/index-f86ad065.css`
