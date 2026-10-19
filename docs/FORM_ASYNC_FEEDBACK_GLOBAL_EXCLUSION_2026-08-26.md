# Form async feedback global exclusion — 2026-08-26

## Problem
The global contextual `Saving… / Saved` badge could still appear on Create Client and other data-entry forms. Livewire requests from form controls are not reliable evidence that a database record was persisted; validation or draft-state synchronization can complete successfully while nothing is saved.

## Rule
Global contextual async feedback is now **disabled for form scopes**. Forms keep their own local feedback: validation errors, button-specific `wire:loading`, upload progress, and disabled states. The sidebar remains excluded as before.

## Central implementation
`resources/js/components/async-feedback.js` now treats these as silent scopes:

- native `<form>` elements
- `[data-ft-feedback-scope="form"]`
- `.ft-form-standard`

The form-scope check runs both when user intent is captured and again when a Livewire request consumes that intent, preventing stale or delayed global badges.

## Explicitly marked form surfaces
- Create Order
- Create Inquiry (including its quick-create modals because they are descendants)
- Create/Edit Client
- Create/Edit Product
- Product category editor/creator
- Master Data editor modal
- Administration User and Role editor modals
- Company Setup form
- Task Pack Setup form
- User Editor form
- Workflow Setup form and setup editor modals
- Order Workflow Setup editor
- Profile edit form
- Branding settings form
- Order/Inquiry attention forms
- Order/Inquiry task document forms
- Invoice and collection update forms
- Order product edit modal
- Order workflow action form modal
- Document upload, rename, and version-upload forms

## Expected UX
Inside forms: no floating `Loading`, `Saving…`, or `Saved` badge. Existing local button/upload/validation feedback continues to work. Outside forms, the centralized contextual feedback remains available for suitable inline actions and data loading.
