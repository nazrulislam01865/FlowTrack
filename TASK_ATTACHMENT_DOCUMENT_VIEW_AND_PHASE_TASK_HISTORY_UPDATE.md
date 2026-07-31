# Task Attachment, Job Documents and Phase Task History Update

Updated areas:

- Task Details document/attachment block now uses the same document-management layout language as the Job Documents screen:
  - document table
  - selected document preview panel
  - required document checklist panel
  - upload form inside the document panel
  - selected files are still only stored after pressing Save
- Workflow phase history now displays the tasks of the selected phase directly under the Phase History table.
- Clicking any workflow phase row or phase dot refreshes the job tasks and then shows that exact phase’s tasks, completion summary, uploads, comments, actor, and time.
- The Job Documents tab keeps the reference layout and skips the red-marked unnecessary controls.

Deployment keeps existing database data. Use `php artisan migrate --force`, not `migrate:fresh --seed`.
