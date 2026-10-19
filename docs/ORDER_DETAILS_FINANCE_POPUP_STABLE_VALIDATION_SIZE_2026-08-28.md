# Order Details Finance Popup Stable Validation Size — 2026-08-28

## Problem
In the Billing **Prepare Bulk Invoice** and Payment **Record Client Payment** workflow popups, field-level validation messages were inserted into the normal CSS grid flow. When validation failed, the affected grid rows became taller, which made the modal visibly grow. On shorter viewports, the newly required scrollbar could also make the content appear to shift horizontally.

## Fix
The Order Details workflow action modal now applies a finance-only stable validation layout for `invoice_prepare` and `payment` variants.

- A permanent validation-message row is reserved under each finance field before validation runs.
- Validation text fills that row instead of increasing the field/grid height.
- The desktop row is one line; mobile reserves two lines so messages remain readable without changing popup size.
- A stable scrollbar gutter prevents horizontal content movement if scrolling is required.
- The behavior is scoped only to the Billing and Payment workflow action popups.

No invoice, payment, workflow, task sequencing, Livewire action, or validation business logic was changed.

## Regression coverage
`OrderWorkflowPopupValidationPlacementTest` now asserts the finance-only stable modal class, the reserved validation row, and the stable scrollbar gutter.
