# My Tasks previous table + filter restore — 2026-08-25

## Requirement
Restore the My Tasks UI from `old file.zip` while keeping the current project's business logic. The workflow-stage cards/header shown in the newer My Tasks design must not appear. The old filter bar and old grouped Order/task table must be restored.

## Source comparison
- Current project: `Archive 2(20260825-130841).zip`
- Reference UI: `old file.zip`

The current project had changed My Tasks to the Orders-list visual model (`ft-my-task-v5`, workflow-stage overview cards, filter-toolbar, `orders-modern-table`). The reference project used the older My Tasks-specific UI (`toolbar ft-list-filter-bar`, `task-table-scroll`, `order-group`, `task-row`).

## 1. Restore My Tasks view
File:

`resources/views/livewire/my-work/index.blade.php`

The Order-side UI was restored from the old project, including:
- old search field;
- old workflow-phase toggle buttons;
- Mentions chip;
- Hide completed control;
- old Sort dropdown;
- Clear filters button;
- compact expand/collapse controls;
- old grouped Order header;
- old task row with Task / Phase / Assignee / Due / Status / Flag / Updated / View;
- old footer pagination.

The card block was intentionally NOT restored. There is no workflow stage card header and there are no summary metric cards above the filters.

The current active-assignment business text was retained for normal users:

```blade
{{ $administratorView
    ? 'All visible Order tasks, grouped by Order and ranked by what needs action first.'
    : 'Only Orders whose current active task is assigned to you appear here. You can work only on that active assigned task.'
}}
```

The current active-task refresh behavior was also retained inside the restored row:

```js
if(result.refresh || (result.completed && @js($hideCompleted))) {
    await $wire.$refresh();
}
```

This is important because a regular user's visible task can change when task status advances workflow sequencing.

## 2. Restore old Inquiry grouped rows
File:

`resources/views/livewire/my-work/_inquiry-groups.blade.php`

The old grouped Inquiry task markup was restored so the dashboard-to-My-Tasks Inquiry status view remains compatible with the restored `task-table-scroll` structure.

## 3. Stop loading Orders-list parity CSS on My Tasks
File:

`resources/views/layouts/app.blade.php`

Changed:

```blade
@if(request()->routeIs('jobs.index', 'my-work'))
    @vite('resources/css/modules/orders/index.css')
@endif

@if(request()->routeIs('all-tasks', 'my-work'))
    @vite('resources/css/modules/work/index.css')
@endif
```

back to:

```blade
@if(request()->routeIs('jobs.index'))
    @vite('resources/css/modules/orders/index.css')
@endif

@if(request()->routeIs('all-tasks'))
    @vite('resources/css/modules/work/index.css')
@endif
```

Why: the current My Tasks redesign depended on Order-list parity CSS. The previous My Tasks design is already provided globally by:

- `resources/css/modules/work/my-work.css`
- `resources/css/modules/application/11-my-work-task-board.css`

through the existing application CSS imports.

## 4. Remove unused workflow-stage card query from My Tasks render
File:

`app/Livewire/MyWork/Index.php`

Removed the render-time call:

```php
$taskStages = $service->orderPhaseCards($user);
```

and removed `taskStages` from the Blade payload because the workflow-stage cards are no longer rendered.

The current `MyWorkService` permission/visibility logic was NOT rolled back. In particular, regular users still see only the currently active Order task assigned to them, while Admin/Super Admin keep broader visibility.

## 5. Regression tests updated
Files:

- `tests/Feature/MyWorkPrototypeImplementationTest.php`
- `tests/Feature/MyWorkPersonalScopeAndMobileCardTest.php`

Tests now verify:
- previous filter bar exists;
- previous grouped table exists;
- workflow-stage cards are absent;
- summary cards are absent;
- Order-list parity table is absent;
- active-assignment scope remains intact;
- renderless inline status update still refreshes when current active work changes.

## No database changes
No migration is required.

## Deployment / local refresh
After replacing files:

```bash
php artisan view:clear
php artisan optimize:clear
```

No `npm run build` is required for this change because no CSS source file was modified; My Tasks is simply switched back to the already-existing previous CSS path.
