# My Tasks old table view restoration - 2026-08-25

## Goal
Restore the My Tasks page table/grouped-task UI from `FlowTrack_My_Tasks_Order_List_Design_Updated(1).zip` while keeping the latest active-assignee visibility rule from the current project.

## Updated files

### 1. `resources/views/livewire/my-work/index.blade.php`
Restored the old grouped My Tasks table layout:
- workflow stage cards
- search / phase / status / sort controls
- summary metric chips
- grouped Order header rows
- task table rows
- pagination
- expand/collapse controls

The non-admin help text now reflects the latest rule: only the Order whose current active task is assigned to the user appears.

### 2. `resources/views/livewire/my-work/_order-groups-v5.blade.php`
Kept the old task-row design and inline editing. After a regular user changes task status, the list refreshes so workflow sequencing immediately removes the old task or reveals the next active task when applicable.

### 3. `app/Livewire/MyWork/Index.php`
Restored the old My Tasks Livewire controller that supports the grouped task-table UI, including:
- task status inline editing
- assignee inline editing
- due-date inline editing
- phase/status/summary filters
- grouped Order pagination
- Inquiry dashboard-status compatibility

A regular-user status save now requests a list refresh because active-task sequencing can change visibility.

### 4. `app/Services/MyWorkService.php`
Preserved the latest access rule while using the old table UI:
- Admin/Super Admin: broad operational visibility.
- Other users: only the current active Order task assigned to them.
- Future assigned tasks are hidden until their phase/task becomes active.
- Creator-only Orders are not added to My Tasks.
- Completed/inactive/locked/skipped/non-actionable tasks are excluded for regular users.
- Summary metrics and workflow-stage counts use the same active-assignment scope.
- Restored the old table pagination size of 3 Order groups per page.

### 5. `resources/views/layouts/app.blade.php`
The `my-work` route now loads both:
- `resources/css/modules/orders/index.css`
- `resources/css/modules/work/index.css`

This is required because the restored My Tasks table reuses the Orders visual language plus the My Tasks-specific table CSS.

### 6. `tests/Feature/MyWorkPersonalScopeAndMobileCardTest.php`
Updated the static regression expectations for active-assignee-only behavior.

## Not changed
The Orders page itself, Order workflow setup, task sequencing service, Inquiry pages, report/export code, and global theme files were not replaced.
