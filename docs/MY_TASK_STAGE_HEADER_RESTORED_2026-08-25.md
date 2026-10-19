# My Tasks workflow-stage header restored

Correction to the previous My Tasks UI restoration.

The workflow-stage overview at the top of My Tasks remains unchanged. Only the table/filter area below it uses the previous My Tasks design.

## Files changed

### resources/views/livewire/my-work/index.blade.php
Re-added the existing workflow-stage overview immediately before the restored previous filter/table:

```blade
<x-orders.workflow-stage-overview
    :stages="$taskStages"
    :selected-stage-value="$phaseFilter"
    mode="wire-filter"
    filter-method="setPhaseFilter"
    title="My tasks by workflow stage"
    description="Click a stage to filter the tasks below on this page."
    count-label="Open tasks"
/>
```

The previous table and filter bar below it are unchanged.

### app/Livewire/MyWork/Index.php
Re-added the data required by the stage overview:

```php
$taskStages = $service->orderPhaseCards($user);
```

and passed it to the view:

```php
'taskStages' => $taskStages,
```

The stage cards still call the existing `setPhaseFilter()` method, so clicking a stage filters the restored table below. `Show all` clears the same phase filter.

## Result

My Tasks now has:

1. Existing `My tasks by workflow stage` header/cards - unchanged.
2. Previous My Tasks filter bar.
3. Previous grouped Order/task table.
4. Current active-assignee visibility and task-update logic preserved.
