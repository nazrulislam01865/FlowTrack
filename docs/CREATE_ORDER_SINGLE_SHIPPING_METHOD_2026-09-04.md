# Create Order single shipping method — 2026-09-04

## Change

Create Order now allows exactly one shipping method selection. Selecting another option replaces the current method instead of adding another card. The selected card itself reopens the same picker so the user can change the method without a separate remove step.

## Compatibility

`shipment_method_ids` remains a JSON array in persistence to avoid a schema migration and preserve historical orders. New Create Order writes are normalized to zero-or-one id, and Livewire validation enforces `max:1`. Express urgency remains zero-or-one and is cleared automatically when switching to a non-express method.

## Files

- `app/Livewire/Jobs/Concerns/ManagesOrderCreation.php`
- `app/DTOs/Orders/OrderCreateData.php`
- `app/Support/CreateOrderShippingMethodPresenter.php`
- `resources/views/components/jobs/create/shipping-method-picker.blade.php`
- `resources/css/modules/orders/create.css`
- `tests/Feature/CreateOrderShippingMethodPrototypeTest.php`
