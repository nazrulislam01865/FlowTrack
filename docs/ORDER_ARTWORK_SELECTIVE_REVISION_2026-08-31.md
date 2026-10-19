# Order Artwork Selective Revision — 2026-08-31

## Goal

Improve the Artwork phase when one Order contains multiple artwork files:

- reviewers can preview every file in the current artwork version;
- a reviewer can select only the artwork file(s) that need correction;
- the upload task asks only for replacements for those selected files;
- unselected artwork remains part of the new version automatically;
- previous artwork versions stay available as history.

## Workflow

1. **Internal review / client decision**
   - The artwork modal includes a **Current artwork files** picker.
   - Selecting a row changes the large preview without leaving the modal.

2. **Request revision**
   - The revision dialog lists every artwork file in the latest version with a checkbox.
   - At least one file must be selected.
   - The selected document ids, names and source artwork version are stored on the `job.artwork_revision_requested` activity.

3. **Upload corrected artwork**
   - The reopened Artwork upload task shows two groups: **Replace** and **Keep unchanged**.
   - The uploader requires exactly one replacement for each selected document.
   - When several documents need replacement, files are mapped in the numbered order shown by the upload UI.

4. **Create the next complete version**
   - Replacement files are stored normally in the next artwork version.
   - Unchanged artwork receives a new `documents` row for the next version while reusing the existing secure stored-file path.
   - This keeps the latest version complete without asking the user to upload duplicate files.
   - The write is serialized with a task-row lock to avoid competing revision versions.

5. **Audit/history**
   - `job.artwork_revision_applied` records which source files were replaced and which were retained.
   - The outstanding revision card lists every file selected for correction.
   - The revision warning disappears only after a newer artwork version exists.

## Compatibility

Revision requests created before selective revision metadata existed continue to use the previous full-latest-set replacement behaviour.

No database schema change is required.
