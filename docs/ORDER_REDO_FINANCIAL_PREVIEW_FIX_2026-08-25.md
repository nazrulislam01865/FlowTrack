# Order Redo Financial Preview Fix — 2026-08-25

## Problem

The Redo Commercial step reacted to the freight checkbox/amount but showed `$0.00` for:

- Affected order value
- Customer charge / credit
- Supplier redo charge / supplier recovery

This occurred even when customer discount and supplier recovery percentages were entered correctly.

## Root cause

`OrderRedoService::averageUnitValue()` only used two price sources:

1. `flow_job_items.unit_price`
2. `flow_jobs.commercial_value`

The current Create Order flow intentionally does not ask the user to override the unit price. New Order item rows can therefore have `unit_price = 0`, while `commercial_value` can also remain `0` before finance/invoice entry. The Product Master can still contain the real quantity-based price, but Redo was not reading it.

The Livewire fields were already updating correctly; freight changed in the preview, proving that the live render path worked. The missing source order value was the actual calculation failure.

## Changed file

`app/Services/OrderRedoService.php`

### Replaced method

`averageUnitValue(FlowJob $order)`

The method now resolves the order unit value in this order:

1. Persisted active Order-line unit prices when every line is priced.
2. `flow_jobs.commercial_value` when present.
3. Latest non-draft/non-cancelled invoice line/total value.
4. Product Master quantity-break pricing through `MasterRecord::productPriceForQuantity()` for every active Order line.

The Product Master fallback is important for the current Create Order design because the selected Product already owns its pricing table and the user is not expected to type another unit price.

## Financial formula remains unchanged

Once unit value is resolved:

- `Affected value = affected/redo quantity × unit value`
- `Customer credit = affected value × customer discount %`
- `Supplier recovery = affected value × supplier recovery %`
- `Total supplier recovery = supplier recovery + freight deduction`

This applies to all three scopes:

- Artwork + production redo
- Production-only redo
- Discount instead of redo

## No migration required

This update does not change the database schema.

## Verification

PHP syntax checks pass for:

- `app/Services/OrderRedoService.php`
- `tests/Feature/OrderRedoImplementationTest.php`

A regression assertion was added to confirm that Product Master pricing and invoice fallback remain part of the Redo preview calculation path.
