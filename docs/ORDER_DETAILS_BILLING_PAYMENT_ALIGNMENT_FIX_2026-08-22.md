# Order Details Billing / Payment Validation Alignment Fix — 2026-08-22

## Problem
In two-column Order workflow popups, a validation message under one field increased that grid row's height. CSS Grid then stretched the neighbouring field to the same height. Because each field is itself a grid, the extra height was distributed inside the shorter field, moving its input downward.

This was visible in the Billing and Payment stages, for example:
- Outstanding balance was vertically offset from Payment amount.
- Payment date was vertically offset from Payment reference.
- The same behavior could occur in invoice fields when only one field in a pair had an error.

## Fix
Updated `resources/css/modules/orders/detail.css` so workflow form-grid children are top-aligned:
- `.ft-prototype-form-grid { align-items: start; }`
- `.ft-prototype-field { align-self: start; align-content: start; }`

Validation messages remain directly below their exact fields. The fix is presentation-only and does not change workflow, billing, payment, phase, task, or validation business logic.

## Regression coverage
Updated `tests/Feature/OrderWorkflowPopupValidationPlacementTest.php` with static assertions for the alignment guards.
