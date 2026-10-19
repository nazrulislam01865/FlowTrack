# Order Details workflow upload modal fixed-size update

Date: 2026-08-22

## Scope

This change is limited to file-backed workflow upload dialogs on Order Details, including Purchase Order, Artwork, Revised Artwork, and Sample Approval uploads.

## Behaviour

- Desktop modal size is fixed at 590px × 500px, constrained only when the browser viewport is smaller.
- Selecting a file no longer changes the modal width or height.
- Header and footer remain fixed while the modal body becomes the internal scroll area if extra validation/content is shown.
- The upload dropzone keeps a fixed 180px height before and after file selection.
- Long selected filenames are truncated with an ellipsis rather than widening or increasing the modal. The complete name remains available through the filename tooltip.
- The invisible native file input no longer contributes border/padding layout artifacts.
- Other Order Details workflow action modals are unchanged.

## Files changed

- `resources/css/modules/orders/detail.css`
- `resources/views/components/jobs/order-detail/document-modal.blade.php`
- `tests/Feature/OrderDocumentUploadPrototypeImplementationTest.php`
