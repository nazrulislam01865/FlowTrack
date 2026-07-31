# Task Attachment, Job Documents, and Board Expand Update

## Completed

- Redesigned Task Details document attachment upload area to match the provided document attachment template:
  - document icon/title header
  - required/attachment badge
  - document type selector
  - large drag-and-drop upload zone
  - Browse files button
  - Choose from Documents visual action
  - info message confirming the file is only stored after Save
  - selected-file preview before saving
- Applied the same upload/dropzone styling to Job document upload modal.
- Fixed Job Details → Documents → Add document so it opens the upload/link modal from the Job Details page instead of redirecting away.
- Added a generic fallback so a job can upload a document even when there is no missing required-document gate, using active document categories and the current phase.
- Made the Board expand/minimize control visible in the Operations Board header next to New Job, while keeping the toolbar control available.
- Existing selected workflow phase history continues to show the tasks and audit entries for the selected phase.

## Verification

- PHP syntax passed.
- JavaScript syntax passed.
- Blade asset verification passed.
