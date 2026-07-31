# Document Attachment Template System Update

Updated all document/attachment upload sections to use the provided template-style upload block instead of preserving the older table-first attachment layout.

## Updated
- Task Details document/attachment area now shows the upload template directly.
- Required document tasks and generic attachment tasks use the same component.
- Job document upload modal uses the same drag-and-drop upload style and includes Browse files + Choose from Documents action.
- New Job attachment upload area uses the same template style.
- Existing task attachments are shown inside the same template card style when upload is not currently available.

## Important behavior preserved
- Selected files are previewed in the browser first.
- Files are stored on the server only when the user saves/uploads.
- Required document/category metadata is still sent to the backend correctly.
