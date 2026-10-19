# Create Order shared attachment dropzone — 2026-08-28

## Goal
Make both Create Order upload sections visually identical to the Create Inquiry attachment uploader and keep the upload UI reusable instead of maintaining three separate implementations.

## Implementation
- Added `resources/views/components/ui/create-attachment-dropzone.blade.php` as the shared create-form upload component.
- The shared component owns drag/drop, browse activation, accepted file types, Livewire upload progress, percentage display, and the common upload-zone presentation.
- Create Inquiry now uses the shared component for its Attachments section.
- Create Order uses the same component for both Purchase Order and Other document.
- Shared visual rules live in `resources/css/components/file-upload.css`, so the Order and Inquiry versions cannot drift independently.

## Preserved behavior
- Existing Livewire properties remain unchanged: `createAttachments`, `purchaseOrderUpload`, and `jobAttachments`.
- Purchase Order remains single-file; Other document and Inquiry attachments remain multi-file.
- Existing validation, selected-file rendering, permissions, Save Draft, Create Inquiry, and Create Order logic are unchanged.
