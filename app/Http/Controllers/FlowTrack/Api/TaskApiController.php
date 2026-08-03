<?php

namespace App\Http\Controllers\FlowTrack\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreClientRequest;
use App\Http\Requests\FlowTrack\StoreJobRequest;
use App\Http\Requests\FlowTrack\StoreTaskRequest;
use App\Http\Requests\FlowTrack\StoreDocumentRequest;
use App\Http\Requests\FlowTrack\UpdateTaskRequest;
use App\Http\Requests\FlowTrack\UpdateTaskDueDateRequest;
use App\Http\Requests\FlowTrack\UpdateJobRequest;
use App\Models\User;
use App\Modules\OrderFlow\Application\Actions\CreateClientAction;
use App\Modules\OrderFlow\Application\Actions\CreateJobAction;
use App\Modules\OrderFlow\Application\Actions\CreateTaskAction;
use App\Modules\OrderFlow\Application\Actions\DeleteClientAction;
use App\Modules\OrderFlow\Application\Actions\DeleteDocumentAction;
use App\Modules\OrderFlow\Application\Actions\DeleteJobAction;
use App\Modules\OrderFlow\Application\Actions\MoveJobPhaseAction;
use App\Modules\OrderFlow\Application\Actions\RemoveJobPhaseAction;
use App\Modules\OrderFlow\Application\Actions\RemoveTaskFromJobAction;
use App\Modules\OrderFlow\Application\Actions\UpdateTaskAction;
use App\Modules\OrderFlow\Application\Actions\UpdateClientAction;
use App\Modules\OrderFlow\Application\Actions\UpdateTaskDueDateAction;
use App\Modules\OrderFlow\Application\Actions\UpdateJobAction;
use App\Modules\OrderFlow\Application\Actions\UploadDocumentAction;
use App\Modules\OrderFlow\Application\Actions\UploadJobAttachmentAction;
use App\Modules\OrderFlow\Application\DTOs\CreateJobData;
use App\Modules\OrderFlow\Application\DTOs\UpdateJobData;
use App\Modules\OrderFlow\Application\Presenters\CurrentUserPresenter;
use App\Modules\OrderFlow\Application\Support\FlowTrackBootstrapCache;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\AccessResolver;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\FlowNotificationService;
use App\Modules\OrderFlow\Business\Services\NotificationFeedService;
use App\Modules\OrderFlow\Business\Services\JobDocumentRequirementService;
use App\Modules\OrderFlow\Business\Services\OperationalMetricsService;
use App\Modules\OrderFlow\Business\Services\RoleMatrixCatalog;
use App\Modules\OrderFlow\Business\Services\TaskPackActivator;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Business\Services\RoleMatrixService;
use App\Modules\OrderFlow\Data\Models\Client;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowActivity;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobPhaseHistory;
use App\Modules\OrderFlow\Data\Models\FlowJobMember;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\TaskPack;
use App\Modules\OrderFlow\Data\Models\TaskPackItem;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use App\Modules\OrderFlow\Data\Repositories\JobRepository;
use App\Modules\OrderFlow\Data\Repositories\TaskRepository;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;


final class TaskApiController extends BaseApiController
{
    public function destroyTask(
        Request $request,
        FlowTask $task,
        FlowAuthorizationService $authorization,
        RemoveTaskFromJobAction $action,
    ): JsonResponse
    {
        $task->loadMissing('job', 'packItem');
        abort_unless($task->job?->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($authorization->canDeleteTask($request->user(), $task), 403);
        $job = $action->execute($task, $request->user(), 'exact_template_task_deletion');

        return response()->json([
            'message' => 'Task deleted successfully.',
            'deletedTask' => ['dbId' => (int) $task->id, 'id' => $task->code],
            'job' => $this->mapJob($this->loadJob($job), $this->canViewFinancials($request->user())),
        ]);
    }

    public function storeTask(
        StoreTaskRequest $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        CreateTaskAction $action,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'tasks', 'create', $visible), 403);

        try {
            $created = $action->execute($visible, $request->validated(), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['task' => $exception->getMessage()]);
        }

        $updatedJob = $this->loadJob($created->job);

        return response()->json([
            'message' => "Task {$created->code} created successfully.",
            'task' => $this->mapTask($created->loadMissing([
                'job.client', 'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
                'phase.taskPack', 'packItem.documentCategory', 'documentCategory', 'assignee.workspaceMemberships.department', 'setupAssignee:id,name,initials', 'setupAssignee.workspaceMemberships.department',
                'status', 'priority', 'checklistItems', 'comments.user', 'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
            ])),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
        ], 201);
    }

    public function updateTask(
        UpdateTaskRequest $request,
        FlowTask $task,
        TaskRepository $tasks,
        FlowAuthorizationService $authorization,
        UpdateTaskAction $action,
    ): JsonResponse {
        $visibleTask = $tasks->findVisible($request->user(), $task->id);
        abort_unless($authorization->canUpdateTask($request->user(), $visibleTask), 403);

        $data = $request->validated();
        if (array_key_exists('assignee_id', $data) && (int) ($data['assignee_id'] ?? 0) !== (int) ($visibleTask->assignee_id ?? 0)) {
            abort_unless($authorization->canAssignExistingTask($request->user(), $visibleTask), 403);
        }

        if (filled($data['checklist_add'] ?? null) || array_key_exists('checklist_delete_id', $data)) {
            abort_unless($authorization->canManageTaskChecklist($request->user(), $visibleTask), 403);
        }

        try {
            $updated = $action->execute($visibleTask, $data, $request->user(), 'exact_template_task_drawer');
        } catch (DomainException $exception) {
            $message = $exception->getMessage();
            $lowerMessage = strtolower($message);
            $field = str_contains($lowerMessage, 'document')
                ? 'document'
                : (str_contains($lowerMessage, 'status')
                ? 'task_status_id'
                : (str_contains($lowerMessage, 'assignee')
                    ? 'assignee_id'
                    : (str_contains($lowerMessage, 'checklist') ? 'checklist_add' : 'blocker_reason')));

            throw ValidationException::withMessages([$field => $message]);
        }

        $job = $this->loadJob($updated->job);
        $updated->load([
            'job.client', 'job.owner:id,name,initials', 'job.coordinator:id,name,initials', 'job.members.user:id,name,initials',
            'phase.taskPack', 'assignee.workspaceMemberships.department', 'setupAssignee:id,name,initials', 'setupAssignee.workspaceMemberships.department', 'status', 'priority', 'checklistItems', 'comments.user',
            'activities.user:id,name,initials', 'transitionEvents.actor:id,name,initials',
        ]);

        return response()->json([
            'task' => $this->mapTask($updated),
            'job' => $this->mapJob($job, $this->canViewFinancials($request->user())),
        ]);
    }

    public function updateTaskDueDate(
        UpdateTaskDueDateRequest $request,
        FlowTask $task,
        TaskRepository $tasks,
        FlowAuthorizationService $authorization,
        UpdateTaskDueDateAction $action,
    ): JsonResponse {
        $visibleTask = $tasks->findVisible($request->user(), $task->id);
        abort_unless($authorization->canChangeTaskDueDate($request->user(), $visibleTask), 403);

        $updated = $action->execute(
            $visibleTask,
            $request->validated('due_date'),
            $request->user(),
            'exact_template_task_due_date',
        );
        $job = $this->loadJob($updated->job);

        return response()->json([
            'message' => 'Task due date updated.',
            'task' => $this->mapTask($updated),
            'job' => $this->mapJob($job, $this->canViewFinancials($request->user())),
        ]);
    }

}
