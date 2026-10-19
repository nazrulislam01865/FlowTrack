# Create Order Shipping Reference Number Prop Fix - 2026-09-04

## Issue
Opening `/orders?create=1` failed while rendering `resources/views/components/jobs/create.blade.php` because the Blade component tried to pass `$referenceNumber` into the reusable shipping setup component without declaring or receiving that value as a component prop.

## Root cause
`referenceNumber` is Livewire state on `App\\Livewire\\Jobs\\Index`, but `<x-jobs.create>` is a Blade component boundary. Livewire variables are not implicitly available inside that component. The shipping setup change introduced a direct `$referenceNumber` read, exposing the missing prop wiring.

## Fix
- Forward `referenceNumber` from `resources/views/livewire/jobs/index.blade.php` to `<x-jobs.create>`.
- Declare `referenceNumber` in the `@props` contract of `resources/views/components/jobs/create.blade.php` with a safe empty-string default.
- Continue forwarding it from `<x-jobs.create>` to `<x-jobs.create.shipping-setup>`.
- Added a source-level regression test covering the full prop chain.

## Validation
Modified PHP/Blade files pass `php -l`. The archive does not contain `vendor`, so the Laravel/PHPUnit suite cannot be executed in this package directly.
