# Create Form Typography Standard — 2026-08-26

## Goal
Use Create Order as the visual typography/control reference for Create Inquiry and Create Client without merging page logic or creating another monolithic CSS file.

## Central control
All shared values now live in:

- `resources/theme/flowtrack/settings.css`

Search for `Standard create-form typography and controls`.

The central variables control page titles, page copy, breadcrumbs, section titles, labels, controls, placeholders, helper text, buttons, control height/radius/borders and numbered step size.

## Modular adapters
The final theme imports `resources/theme/flowtrack/forms/index.css`, which composes four small files:

- `forms/foundation.css` — shared form contract
- `forms/order.css` — Create Order reference adapter
- `forms/inquiry.css` — Create Inquiry adapter
- `forms/client.css` — Create Client adapter

This keeps the legacy feature CSS untouched and gives the final theme package one scalable place to standardize future forms.

## View opt-in classes
The following create views opt into the form system:

- `resources/views/components/jobs/create.blade.php`
  - `ft-form-standard ft-form-standard--order`
- `resources/views/livewire/inquiries/sections/create.blade.php`
  - `ft-form-standard ft-form-standard--inquiry`
- `resources/views/components/clients/create.blade.php`
  - create mode only: `ft-form-standard ft-form-standard--client`

Client edit mode intentionally keeps its existing styling to avoid changing the edit experience as part of this create-form task.

## Visual result
Create Inquiry and Create Client now inherit the Create Order standard for:

- title size, weight and color
- subtitle/body copy
- breadcrumb typography
- section heading typography
- label size, weight and color
- field text size and color
- 44px control height and 8px radius
- helper/error text sizing
- primary/secondary button typography
- teal numbered section markers

Page structure and Livewire behavior are unchanged.
