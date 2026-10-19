<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryPrototypeImplementationTest extends TestCase
{

    public function test_inquiry_list_uses_searchable_client_filter_without_active_or_closed_quick_filters(): void
    {
        $view = $this->inquiryViewSource();
        $component = $this->inquiryLivewireSource();
        $service = $this->inquiryServiceSource();
        $css = $this->compatibilityCss('flowtrack-inquiries.css');

        $this->assertStringNotContainsString('wire:click="setQuick(\'active\')">Active</button>', $view);
        $this->assertStringNotContainsString('wire:click="setQuick(\'dead\')">Closed</button>', $view);
        $this->assertStringContainsString('class="ft-inquiry-list-client-filter"', $view);
        $this->assertStringContainsString('property="pendingListClient"', $view);
        $this->assertStringContainsString('action="setInquiryPendingListFilter"', $view);
        $this->assertStringContainsString(':selected-label="$pendingListClientLabel ?: null"', $view);
        $this->assertStringContainsString('wire:key="inquiry-list-client-filter-', $view);
        $this->assertStringContainsString('type="clients"', $view);
        $this->assertStringContainsString('context="inquiries"', $view);
        $this->assertStringContainsString(':fixed-menu="true"', $view);
        $this->assertStringContainsString('public string $listClient', $component);
        $this->assertStringContainsString('public string $listClientLabel', $component);
        $this->assertStringContainsString('public string $pendingListClient', $component);
        $this->assertStringContainsString('public string $pendingListClientLabel', $component);
        $this->assertStringContainsString('wire:click="applyFilters"', $view);
        $this->assertStringContainsString('public function applyFilters(): void', $component);
        $this->assertStringContainsString('public function setInquiryPendingListFilter(string $property, mixed $value): void', $component);
        $this->assertStringContainsString("->options(auth()->user(), 'clients', 'inquiries', '', \$id, 20)", $component);
        $this->assertStringContainsString("'client_id' => \$selectedClientId", $component);
        $this->assertStringContainsString("->options(\$user, 'clients', 'inquiries', '', \$pendingClientId, 6)", $component);
        $this->assertStringContainsString('private const INQUIRIES_PER_PAGE = 10;', $component);
        $this->assertStringContainsString('], self::INQUIRIES_PER_PAGE);', $component);
        $this->assertStringContainsString("->when(\$clientId > 0, fn (Builder \$q) => \$q->where('inquiries.client_id', \$clientId))", $service);
        $this->assertStringContainsString('.ft-inquiry-prototype .inquiry-list-v2 .ft-inquiry-list-client-filter{', $css);
        $this->assertStringContainsString("'fixedMenu' => false", file_get_contents(resource_path('views/components/ui/remote-filter.blade.php')));
        $this->assertStringContainsString("wire:click=\"setMetricFilter('createdToday')\"", $view);
        $this->assertStringContainsString("wire:click=\"setMetricFilter('notStarted')\"", $view);
        $this->assertStringContainsString("wire:click=\"setMetricFilter('inProgress')\"", $view);
        $this->assertStringContainsString("wire:click=\"setMetricFilter('dueThisWeek')\"", $view);
        $this->assertStringContainsString("wire:click=\"setMetricFilter('completedThisWeek')\"", $view);
        $this->assertStringContainsString("wire:click=\"setMetricFilter('attention')\"", $view);
        $this->assertStringContainsString('public string $metricFilter', $component);
        $this->assertStringContainsString('public function setMetricFilter(string $metric): void', $component);
        $this->assertStringContainsString("'metric_filter' => \$this->metricFilter", $component);
        $this->assertStringContainsString('private function applyMetricListScope', $service);
        $this->assertStringContainsString('private function applyCreatedTodayListScope', $service);
        $this->assertStringContainsString('private function applyNotStartedListScope', $service);
        $this->assertStringContainsString('private function applyInProgressListScope', $service);
        $this->assertStringContainsString('private function applyDueThisWeekListScope', $service);
        $this->assertStringContainsString('private function applyCompletedThisWeekListScope', $service);
        $this->assertStringContainsString("->whereBetween('inquiries.required_delivery_date', [\$weekStart, \$weekEnd])", $service);
        $this->assertStringContainsString("currentTaskSubquery('due_date', \$currentTaskDueDate)", $service);
        $this->assertStringContainsString('private function currentTaskSubquery(string $column, ?string $dueDate = null): Builder', $service);
        $this->assertStringContainsString('public bool $hideCompleted = false;', $component);
        $this->assertStringContainsString('.ft-inquiry-prototype .metric-filter-card:hover{', $css);
        $this->assertStringContainsString('<div>Task Status</div>', $view);
        $this->assertStringContainsString('<div>Flag</div>', $view);
        $this->assertStringContainsString("currentTaskSubquery('status', \$currentTaskDueDate)", $service);
        $this->assertStringContainsString("->orWhereNull('inquiry_tasks.assignee_id')", $service);
        $this->assertStringContainsString("->orWhereDate('inquiry_tasks.due_date', '<', \$today)", $service);
        $this->assertStringContainsString("LIKE '%blocked%'", $service);
    }

    public function test_inquiry_create_matches_prototype_and_uses_workflow_setup(): void
    {
        $view = $this->inquiryViewSource();
        $component = $this->inquiryLivewireSource();
        $service = $this->inquiryServiceSource();
        $css = $this->compatibilityCss('flowtrack-inquiries.css');

        $this->assertStringContainsString('<h1>Create Inquiry</h1>', $view);
        $this->assertStringContainsString('How was this inquiry received? *', $view);
        $this->assertStringContainsString('Add new client', $view);
        $this->assertStringContainsString('Client contact', $view);
        $this->assertStringContainsString('Reference number', $view);
        $this->assertStringContainsString('Assigned to', $view);
        $this->assertStringContainsString('Inquiry title *', $view);
        $this->assertStringContainsString('Request details', $view);
        $this->assertStringContainsString('ft-inquiry-selected-file-preview', $view);
        $this->assertStringContainsString('$upload->temporaryUrl()', $view);
        $this->assertStringContainsString('What happens next', $view);
        $this->assertStringContainsString('Add new client', $view);
        $this->assertStringContainsString('Add &amp; select client', $view);
        $this->assertStringContainsString('wire:click="createClientAndSelect"', $view);
        $this->assertStringContainsString('action="setCreateSelector"', $view);
        $this->assertStringContainsString('selection-property="createWorkflowId"', $view);
        $this->assertStringContainsString("->availableFor('inquiries', \$this->clientId)", $component);
        $this->assertStringContainsString("CASE WHEN client_availability = 'specific' THEN 0 ELSE 1 END", $component);
        $this->assertStringContainsString("'request_source' => \$data['requestSource']", $component);
        $this->assertStringContainsString("'owner_id' => (int) \$data['createOwnerId']", $component);
        $this->assertStringContainsString('public function createClientAndSelect(): void', $component);
        $this->assertStringContainsString('.ft-inquiry-prototype .ft-inquiry-create-v3', $css);
        $this->assertStringContainsString('.ft-inquiry-prototype .ft-inquiry-quick-client-modal', $css);

        $this->assertStringContainsString('$workflowQuery->rows(', $component);
        $this->assertStringContainsString("'source_workflow_template_id' => (int) \$data['createWorkflowId']", $component);
        $this->assertStringContainsString('public function workflowRows(int $workflowId', $service);
        $this->assertStringContainsString("'phases.taskPack.items.defaultAssignee:id,name'", $service);
        $this->assertStringContainsString("'phases.taskPack.items.documentCategory:id,name'", $service);
    }

    public function test_inquiry_livewire_render_is_branch_and_tab_aware(): void
    {
        $component = $this->inquiryLivewireSource();
        $view = $this->inquiryViewSource();

        $this->assertStringContainsString("if (\$this->showCreate) return view('livewire.inquiries.index', \$this->createPageData());", $component);
        $this->assertStringContainsString("if (\$this->selectedInquiryId) return view('livewire.inquiries.index', \$this->detailPageData(\$user));", $component);
        $this->assertStringContainsString("public string \$detailTab = 'overview';", $component);
        $this->assertStringContainsString("The separate Taskflow tab was removed", $component);
        $this->assertStringContainsString("in_array(\$tab, ['overview', 'workflow'], true)", $component);
        $this->assertStringContainsString('<button class="tab active" type="button">Overview</button>', $view);
        $this->assertStringContainsString('<x-inquiries.product-rfq-overview', $view);
        $this->assertStringNotContainsString("setDetailTab('products')", $view);
        $this->assertStringNotContainsString("setDetailTab('finance')", $view);
        $this->assertStringNotContainsString("setDetailTab('documents')", $view);
        $this->assertStringNotContainsString("setDetailTab('activity')", $view);
        $this->assertStringContainsString("@include('livewire.inquiries._attachments')", $view);
        $this->assertStringContainsString("@include('livewire.inquiries._activity')", $view);
        $this->assertStringContainsString('progressive-section-loader section="activity"', $view);
        $this->assertStringContainsString('ft-inquiry-overview-activity-card', file_get_contents(resource_path('views/livewire/inquiries/_activity.blade.php')));
        $this->assertStringContainsString("if (in_array(\$tab, ['workflow', 'activity'], true)) \$tab = 'overview';", $component);
        $this->assertStringContainsString("in_array(\$tab, ['overview', 'rfq', 'comparison'], true)", $component);
        $this->assertStringContainsString("\$this->detailTab === 'overview' && \$user->canModule('documents', 'view') ? \$detailQuery->documents", $component);
        $this->assertStringContainsString("\$this->detailTab === 'overview' && \$detailSectionsReady['activity'] ? \$detailQuery->activity", $component);
        $this->assertStringContainsString('#[Renderless]', $component);
    }


    public function test_inquiry_detail_has_compact_workflow_and_inline_description(): void
    {
        $view = $this->inquiryViewSource();
        $component = $this->inquiryLivewireSource();
        $service = $this->inquiryServiceSource();
        $taskModel = file_get_contents(app_path('Models/InquiryTask.php'));
        $migration = file_get_contents(database_path('migrations/2026_08_09_000200_add_started_at_to_inquiry_tasks.php'));
        $css = $this->compatibilityCss('flowtrack-inquiries.css');

        $this->assertStringContainsString('id="tab-workflow"', $view);
        $this->assertStringContainsString("updateInquiryField('requirement_notes'", $view);
        $taskflow = file_get_contents(resource_path('views/livewire/inquiries/_taskflow.blade.php'));
        $this->assertStringContainsString('deleteTaskDocument(', $taskflow);
        $this->assertStringNotContainsString('<small>Client contact</small>', $view);
        $this->assertStringNotContainsString('<small>Office address</small>', $view);
        $this->assertStringNotContainsString('<small>Workflow</small>', $view);
        $this->assertStringContainsString("'requirement_notes'", $component);
        $this->assertStringContainsString('public function removeTaskDocument', $service);
        $this->assertStringContainsString('#tab-workflow .ft-inquiry-task-document-row', $css);
        $this->assertStringNotContainsString('>Taskflow</button>', $view);
        $this->assertStringNotContainsString("@elseif(\$detailTab === 'workflow')", $view);
        $this->assertStringContainsString('<h2>Inquiry Taskflow</h2>', $taskflow);
        $this->assertStringContainsString("<small>Assignee</small>", $view);
        $this->assertStringNotContainsString("'inquiryStatusOptions' =>", $component);
        $this->assertStringContainsString("@include('livewire.inquiries._taskflow')", $view);
        $this->assertStringContainsString("\$canCompleteThisTask", $taskflow);
        $this->assertStringContainsString("\$canChangeStatusThisTask", $taskflow);
        $this->assertStringContainsString('class="ft-inline-task-status', $taskflow);
        $this->assertStringContainsString('$inquiryTaskStatusOptions as $statusOption', $taskflow);
        $this->assertStringNotContainsString('ft-inquiry-status-pill done', $taskflow);
        $this->assertStringContainsString("\$task->started_at ? 'active' : 'wait'", $taskflow);
        $this->assertStringNotContainsString("Complete the previous taskflow task first.", $service);
        $this->assertStringContainsString('<h2>Inquiry Taskflow</h2>', file_get_contents(resource_path('views/livewire/inquiries/_taskflow.blade.php')));
        $this->assertStringNotContainsString('View only', $view);
        $this->assertStringContainsString("'started_at' => 'datetime'", $taskModel);
        $this->assertStringContainsString("timestamp('started_at')", $migration);
        $this->assertStringContainsString('...$this->defaultTaskStatusPayload()', $service);
        $this->assertStringContainsString("'started_at' => null", $service);
        $this->assertStringContainsString('public function taskStatusOptions(?string $currentStatus = null): Collection', $service);
        $this->assertStringContainsString("->active('inquiry_task_status')", $service);
        $this->assertStringContainsString("\$this->detailTab === 'overview'", $component);
        $this->assertStringContainsString('.ft-inquiry-task-document-modal', $css);
        $this->assertStringContainsString('openTaskDocumentModal(', $taskflow);
        $this->assertStringContainsString('Add new document to task', $view);
        $this->assertStringContainsString('Choose existing', $view);
        $this->assertStringContainsString('Document note (optional)', $view);
        $this->assertStringContainsString('public function linkExistingDocumentToTask', $service);
        $this->assertStringContainsString('class="inquiry-list-table"', $view);
        $this->assertStringContainsString('ft-inquiry-created-by', $view);
        $this->assertStringContainsString('aria-label="Actions"', $view);
        $this->assertStringContainsString('--ft-inquiry-list-min-width:', $css);
        $this->assertStringNotContainsString('<span class="sub">Assignee</span>', $view);
        $this->assertStringNotContainsString('<span class="sub">Due date</span>', $view);
        $this->assertStringContainsString('ft-inquiry-header-meta', $view);
        $this->assertStringContainsString('Client <strong>{{ $inquiry->client?->name', $view);
        $this->assertStringContainsString('Reference <strong>{{ $inquiry->reference_number', $view);
        $this->assertStringContainsString('Created by <strong>{{ $inquiry->creator?->name', $view);
        $this->assertStringNotContainsString('ft-inquiry-information-legacy">', $view);
        $this->assertStringNotContainsString('Not created yet', $view);
        $this->assertStringContainsString('ft-inquiry-auto-status-property', $view);
        $this->assertStringNotContainsString('inquiryOverviewStatus', $view);
        $this->assertStringContainsString("public const AUTO_READY_STATUS = 'To do';", $service);
        $this->assertStringContainsString("public const AUTO_IN_PROGRESS_STATUS = 'In Progress';", $service);
        $this->assertStringContainsString("public const AUTO_COMPLETED_STATUS = 'Completed';", $service);
        $this->assertStringContainsString('public function syncAutomaticStatus(Inquiry $inquiry', $service);
    }

    public function test_inquiry_list_mobile_card_keeps_the_approved_prototype_fields_only(): void
    {
        $view = $this->inquiryViewSource();
        $css = $this->compatibilityCss('flowtrack-inquiries.css');
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('2026-08-15: Final lightweight mobile Inquiry list authority.', $css);
        $this->assertStringContainsString('"status status priority priority view view"', $css);
        $this->assertStringContainsString('.ft-inquiry-list-task-status-cell,', $css);
        $this->assertStringContainsString('.ft-inquiry-list-flag-cell,', $css);
        $this->assertStringContainsString('.ft-inquiry-list-updated-cell{', $css);
        $this->assertStringContainsString('display:none!important;', $css);
        $this->assertStringContainsString('aria-label="Actions"', $view);
        $this->assertLayoutLoadsViteCss('resources/css/application/after-dashboard.css', $layout);
        $this->assertStringContainsString("@import '../modules/inquiries/core.css';", file_get_contents(resource_path('css/application/after-dashboard.css')));
    }

    public function test_inquiry_hide_completed_uses_actual_taskflow_completion(): void
    {
        $service = $this->inquiryServiceSource();
        $component = $this->inquiryLivewireSource();
        $view = $this->inquiryViewSource();

        $this->assertStringContainsString('private function applyUnfinishedListScope(Builder $query): Builder', $service);
        $this->assertStringContainsString("->whereDoesntHave('tasks')", $service);
        $this->assertStringContainsString("->orWhereHas('tasks', fn (Builder \$task) => \$task->whereNull('completed_at'))", $service);
        $this->assertStringContainsString("\$hideCompleted && !in_array(\$metricFilter, ['completed', 'completedThisWeek'], true)", $service);
        $this->assertStringContainsString('public bool $hideCompleted = false;', $component);
        $this->assertStringContainsString('public function updatedHideCompleted(): void', $component);
        $this->assertStringContainsString('wire:model.live="pendingHideCompleted"', $view);
    }

    public function test_inquiry_tasks_use_explicit_my_work_adapters_without_legacy_group_merging(): void
    {
        $myWork = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertStringNotContainsString('myTaskGroups(auth()->user()', $myWork);
        $this->assertStringContainsString('updateInquiryTaskStatus', $myWork);
        $this->assertStringContainsString('updateInquiryTaskDueDate', $myWork);
        $this->assertStringContainsString("@include('livewire.my-work._inquiry-groups'", $view);
    }

    public function test_inquiry_start_timestamp_is_persisted_auto_started_and_inline_editable(): void
    {
        $model = file_get_contents(app_path('Models/Inquiry.php'));
        $service = $this->inquiryServiceSource();
        $component = $this->inquiryLivewireSource();
        $view = $this->inquiryViewSource();
        $migration = file_get_contents(database_path('migrations/2026_08_10_130000_add_started_at_to_inquiries.php'));

        $this->assertStringContainsString("'started_at' => 'datetime'", $model);
        $this->assertStringContainsString("timestamp('started_at')", $migration);
        $this->assertStringContainsString('$this->isWorkingTaskStatus($nextStatus)', $service);
        $this->assertStringContainsString("whereNull('started_at')", $service);
        $this->assertStringContainsString('public function updateStartedAt(Inquiry $inquiry', $service);
        $this->assertStringContainsString('public function updateInquiryStartInline', $component);
        $this->assertStringContainsString('inquiryStartValue', $component);
        $this->assertStringContainsString('type="datetime-local"', $view);
        $this->assertStringContainsString('formatDateTime($event.target.value)', $view);
        $this->assertStringContainsString('flowtrack-inquiry-started', $view);
    }

    public function test_inquiry_list_tracks_parallel_taskflow_and_last_assignee_picker_is_not_clipped(): void
    {
        $service = $this->inquiryServiceSource();
        $model = file_get_contents(app_path('Models/Inquiry.php'));
        $taskflow = file_get_contents(resource_path('views/livewire/inquiries/_taskflow.blade.php'));
        $css = $this->compatibilityCss('flowtrack-inquiries.css');
        $inlineUser = file_get_contents(resource_path('views/components/ui/inline-remote-user.blade.php'));
        $filterJs = file_get_contents(resource_path('js/components/list-filters.js'));

        $this->assertStringContainsString("currentTaskSubquery('sequence', \$currentTaskDueDate)", $service);
        $this->assertStringContainsString("CASE WHEN inquiry_tasks.started_at IS NOT NULL THEN 0 ELSE 1 END", $service);
        $this->assertStringContainsString("CASE WHEN inquiry_tasks.started_at IS NOT NULL THEN inquiry_tasks.sequence END DESC", $service);
        $this->assertStringContainsString("'tasks as progressed_tasks_count'", $service);
        $this->assertStringContainsString("'progress' => \$canViewTasks ? \$progress : 0", $service);
        $this->assertStringContainsString("'taskCaption' => \$canViewTasks ? (\$done === \$total", $service);
        $this->assertStringContainsString("\$task->inquiry->touch();", $service);
        $this->assertStringContainsString("CASE WHEN inquiry_tasks.started_at IS NOT NULL THEN inquiry_tasks.sequence END DESC", $model);
        $this->assertStringContainsString('class="panel ft-inquiry-taskflow-panel"', $taskflow);
        $this->assertStringContainsString('.ft-inquiry-taskflow-panel{', $css);
        $this->assertStringContainsString('overflow:visible;', $css);
        $this->assertStringContainsString('.ft-inquiry-taskflow-panel .ft-inline-remote-user-menu{', $css);
        $this->assertStringContainsString("'fixedMenu' => true", $inlineUser);
        $this->assertStringContainsString('fixedMenu: @js((bool) $fixedMenu)', $inlineUser);
        $this->assertStringContainsString('if (component.fixedMenu)', $filterJs);
        $this->assertStringContainsString("'position:fixed!important'", $filterJs);
        $this->assertStringContainsString("'z-index:2450!important'", $filterJs);
    }


    public function test_completed_inquiry_tasks_keep_assignee_and_due_date_inline_editing(): void
    {
        $taskflow = file_get_contents(resource_path('views/livewire/inquiries/_taskflow.blade.php'));
        $component = $this->inquiryLivewireSource();
        $service = $this->inquiryServiceSource();

        $this->assertStringContainsString('$canEditTaskFields = $canChangeStatusThisTask;', $taskflow);
        $this->assertStringContainsString('@if($canAssignThisTask)<button x-show="!editing"', $taskflow);
        $this->assertStringContainsString('@if($canEditTaskFields)<button', $taskflow);
        $this->assertStringContainsString('Edit task assignee', $taskflow);
        $this->assertStringContainsString('Edit task due date', $taskflow);
        $this->assertStringContainsString('UpdateInquiryTaskAssignee::class)->handle($task, $assigneeId, auth()->user())', $component);
        $this->assertStringContainsString('public function updateTaskAssignee(InquiryTask $task, ?int $assigneeId, User $actor): InquiryTask', $service);
        $this->assertStringContainsString('Due date remains editable after task completion.', $service);
        $this->assertStringContainsString('Updating it must not', $service);
    }



    public function test_completed_inquiry_task_documents_remain_manageable_without_breaking_required_file_completion(): void
    {
        $taskflow = file_get_contents(resource_path('views/livewire/inquiries/_taskflow.blade.php'));
        $component = $this->inquiryLivewireSource();
        $service = $this->inquiryServiceSource();

        $this->assertStringContainsString('$canAttachThisTask = $canAttachFileThisTask;', $taskflow);
        $this->assertStringContainsString('@if($canAttachFileThisTask || $canChangeStatusThisTask)', $taskflow);
        $this->assertStringContainsString('@if($canAttachFileThisTask)', $taskflow);
        $this->assertStringContainsString('wire:click="deleteTaskDocument(', $taskflow);
        $this->assertStringContainsString('The task will reopen to In Progress', $taskflow);
        $this->assertStringContainsString('public function removeTaskDocument(InquiryTask $task, int $documentId, User $actor): bool', $service);
        $this->assertStringContainsString("'status' => \$this->resumeTaskStatus()", $service);
        $this->assertStringContainsString("'completed_at' => null", $service);
        $this->assertStringContainsString('final required file/link evidence was removed', $service);
        $this->assertStringContainsString('$this->syncAutomaticStatus($lockedTask->inquiry, $actor);', $service);
        $this->assertStringContainsString('$this->refreshInquiryListMetrics();', $component);
        $this->assertStringContainsString('public function taskHasSubmissionEvidence(InquiryTask $task): bool', $service);
        $this->assertStringContainsString('$task->documents()->exists() || $task->links()->exists()', $service);
        $this->assertStringContainsString('Required file or link', $taskflow);
        $this->assertStringContainsString('✓ Link submitted', $taskflow);
        $this->assertStringContainsString('public function removeTaskLink(InquiryTask $task, int $linkId, User $actor): bool', $service);
    }

}
