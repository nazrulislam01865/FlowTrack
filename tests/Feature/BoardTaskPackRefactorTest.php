<?php

namespace Tests\Feature;

use Tests\TestCase;

class BoardTaskPackRefactorTest extends TestCase
{
    public function test_all_tasks_uses_the_literal_my_work_design_without_job_board_navigation(): void
    {
        $board = file_get_contents(resource_path('views/livewire/board/index.blade.php'));
        $css = file_get_contents(resource_path('css/modules/work/all-tasks.css'));
        $this->assertStringNotContainsString('<style>', $board);
        $this->assertStringContainsString('#my-work-app .list-shell{overflow-x:auto', $css);
        $this->assertStringContainsString('id="my-work-app"', $board);
        $this->assertStringContainsString('class="page-head"', $board);
        $this->assertStringContainsString('<h1>All Tasks</h1>', $board);
        $this->assertStringNotContainsString('Job Board', $board);
        $this->assertStringNotContainsString("setMode('jobs')", $board);
        $this->assertStringContainsString('class="metrics ft-summary-card-grid"', $board);
        $this->assertStringContainsString('class="toolbar ft-list-filter-bar"', $board);
        $this->assertSame(6, substr_count($board, '<x-ui.summary-card '));
        $this->assertStringContainsString('class="phase-filters"', $board);
        $this->assertStringContainsString('class="phase-toggle', $board);
        $this->assertStringContainsString('Clear filters', $board);
        $this->assertStringContainsString('class="list-shell"', $board);
        $this->assertStringContainsString('overflow-x:auto', $css);
        $this->assertStringContainsString('-webkit-overflow-scrolling:touch', $css);
        $this->assertStringContainsString('class="task-head"', $board);
        $this->assertStringContainsString('class="order-head"', $board);
        $this->assertStringContainsString('class="task-row"', $board);
        $this->assertStringContainsString('class="footer"', $board);

        $this->assertStringNotContainsString('ft-task-board-redesign', $board);
        $this->assertStringNotContainsString('ft-task-board-filter-grid', $board);
        $this->assertStringNotContainsString('x-board.task-pack-list', $board);
        $this->assertStringContainsString('<span>Assignee</span>', $board);
        $this->assertStringContainsString('class="assignee"', $board);
        $this->assertStringContainsString('assigneeImage', $board);
        $this->assertStringNotContainsString('ft-task-board-pagination', $board);
        $this->assertStringNotContainsString('x-board.task-job-matrix', $board);
    }

    public function test_task_pack_scope_follows_the_tasks_matrix_without_exposing_sibling_tasks(): void
    {
        $service = file_get_contents(app_path('Services/BoardTaskPackService.php'));

        $this->assertStringContainsString('applyTaskScope(Task::query(), $user)', $service);
        $this->assertStringContainsString("->select('tasks.flow_job_id')", $service);
        $this->assertStringContainsString("->whereIn('flow_jobs.id', \$visibleTaskIds)", $service);
        $this->assertStringContainsString('Never hydrate sibling tasks outside the Tasks matrix scope', $service);
        $this->assertStringContainsString("Task::query()->whereIn('tasks.flow_job_id', \$jobIds)", $service);
        $this->assertStringContainsString("'assignee:id,name,department_id,profile_image_path'", $service);
        $this->assertStringContainsString("'assignee' => (string) (\$task->assignee?->name ?: 'Unassigned')", $service);
    }

    public function test_qualifying_job_never_loads_out_of_scope_siblings_and_mentions_remain_task_level(): void
    {
        $service = file_get_contents(app_path('Services/BoardTaskPackService.php'));

        $this->assertStringContainsString('summary cards,', $service);
        $this->assertStringContainsString("\$taskLevelFilter = \$quick !== 'all'", $service);
        $this->assertStringContainsString("\$search = trim((string) (\$filters['search'] ?? ''));", $service);
        $this->assertStringContainsString("|| \$search !== '';", $service);
        $this->assertStringContainsString("? (clone \$baseTasks)->whereIn('tasks.flow_job_id', \$jobIds)", $service);
        $this->assertStringContainsString('app(AccessControlService::class)->applyTaskScope(', $service);
        $this->assertStringContainsString("Task::query()->whereIn('tasks.flow_job_id', \$jobIds)", $service);
        $this->assertStringContainsString("->whereColumn('board_task_mention_activity.subject_id', 'tasks.id')", $service);
        $this->assertStringContainsString("->where('board_task_mention_activity.event', 'task.comment')", $service);
        $this->assertStringContainsString('mention_user_ids', $service);
        $this->assertStringNotContainsString("->where('flow_notifications.user_id', \$user->id)", $service);
    }

    public function test_all_tasks_search_matches_assignee_name_and_keeps_only_matching_task_rows(): void
    {
        $service = file_get_contents(app_path('Services/BoardTaskPackService.php'));
        $view = file_get_contents(resource_path('views/livewire/board/index.blade.php'));

        $this->assertStringContainsString('matchingAssigneeIdsForSearch', $service);
        $this->assertStringContainsString("->whereLike('name', '%'.\$search.'%')", $service);
        $this->assertStringContainsString("\$query->whereIn('tasks.assignee_id', \$matchingAssigneeIds);", $service);
        $this->assertStringContainsString('do not fall through to an Order/client', $service);
        $this->assertStringContainsString("if (\$taskLevelFilter && \$taskRows->isEmpty()) return null;", $service);
        $this->assertStringContainsString("\$search = trim((string) (\$filters['search'] ?? ''));", $service);
        $this->assertStringContainsString("|| \$search !== '';", $service);
        $this->assertStringContainsString("? (clone \$baseTasks)->whereIn('tasks.flow_job_id', \$jobIds)", $service);
        $this->assertStringContainsString('Search tasks, Orders, clients, assignees or flags', $view);
    }

    public function test_all_tasks_reuses_my_tasks_summary_and_filter_definitions(): void
    {
        $component = file_get_contents(app_path('Livewire/Board/Index.php'));
        $service = file_get_contents(app_path('Services/BoardTaskPackService.php'));
        $view = file_get_contents(resource_path('views/livewire/board/index.blade.php'));

        foreach (['Created Today', 'Not Started', 'In Progress', 'Due This Week', 'Completed This Week', 'Needs Attention'] as $label) {
            $this->assertStringContainsString('label="'.$label.'"', $view);
        }

        $this->assertStringContainsString('setTaskMetricFilter', $component);
        $this->assertStringContainsString('setTaskPhaseFilter', $component);
        $this->assertStringContainsString("'phase' => \$this->taskPhaseFilter", $component);
        $this->assertStringContainsString('return app(MyWorkService::class)->metrics($user);', $service);
        $this->assertStringContainsString("'createdToday' => \$query->whereBetween('tasks.created_at'", $service);
        $this->assertStringContainsString("'completedThisWeek' => \$query", $service);
        $this->assertStringContainsString('orderPhaseSourceIdsForName', $service);
    }

    public function test_task_pack_paginates_jobs_before_loading_page_tasks(): void
    {
        $service = file_get_contents(app_path('Services/BoardTaskPackService.php'));
        $component = file_get_contents(app_path('Livewire/Board/Index.php'));

        $this->assertStringContainsString('Paginate Job groups first', $service);
        $this->assertStringContainsString("->groupBy('tasks.flow_job_id')", $service);
        $this->assertStringContainsString('->paginate(max(1, min(25, $perPage))', $service);
        $this->assertStringContainsString("->whereIn('tasks.flow_job_id', \$jobIds)", $service);
        $this->assertStringContainsString('use WithPagination;', $component);
        $this->assertStringContainsString('BoardTaskPackService::JOBS_PER_PAGE', $component);
        $this->assertStringContainsString('public const JOBS_PER_PAGE = 3;', $service);
        $this->assertStringContainsString("resetPage('taskPackPage')", $component);
    }

    public function test_task_pack_inline_status_updates_are_renderless_and_keep_mutation_scope_strict(): void
    {
        $component = file_get_contents(app_path('Livewire/Board/Index.php'));
        $service = file_get_contents(app_path('Services/BoardTaskPackService.php'));

        $this->assertMatchesRegularExpression('/#\[Renderless\]\s+public function updateTaskStatus\b/', $component);
        $this->assertStringContainsString('TaskService::class)->visibleQuery($actor)', $component);
        $this->assertStringContainsString('canEditTaskWithoutQuery', $service);
        $this->assertStringContainsString('Mirror task-edit authorization using already eager-loaded fields', $service);
    }

    public function test_task_pack_association_has_a_dedicated_database_index(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_08_045500_optimize_board_task_pack_scope.php'));

        $this->assertStringContainsString("['assignee_id', 'deleted_at', 'flow_job_id']", $migration);
        $this->assertStringContainsString('ft_tasks_board_assignee_job_idx', $migration);
    }
}
