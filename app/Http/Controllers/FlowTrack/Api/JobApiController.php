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


final class JobApiController extends BaseApiController
{
    public function destroyJob(Request $request, FlowJob $job, DeleteJobAction $action): JsonResponse
    {
        abort_unless($job->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('jobs', 'delete', $job), 403);

        $action->execute($job);

        return response()->json(['message' => 'Job, Tasks, documents and stored files were deleted successfully.']);
    }

    public function bulkJobs(Request $request, DeleteJobAction $deleteJob): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'action' => ['required', Rule::in(['deactivate', 'delete'])],
            'job_ids' => ['required', 'array', 'min:1', 'max:100'],
            'job_ids.*' => ['required', 'integer'],
        ]);

        $jobs = FlowJob::query()
            ->with(['tasks:id,flow_job_id', 'documents:id,flow_job_id'])
            ->where('workspace_id', $user->currentWorkspaceId())
            ->whereIn('id', collect($data['job_ids'])->map(fn ($id) => (int) $id)->unique()->values())
            ->get();

        abort_if($jobs->isEmpty(), 404, 'No matching Jobs were found.');

        if ($data['action'] === 'delete') {
            abort_unless(app(FlowAuthorizationService::class)->isAdministrator($user), 403);

            foreach ($jobs as $job) {
                abort_unless($user->hasFlowAction('jobs', 'delete', $job), 403);
            }

            $deleted = [];
            foreach ($jobs as $job) {
                $deleted[] = ['dbId' => (int) $job->id, 'id' => $job->code];
                $deleteJob->execute($job);
            }

            return response()->json([
                'message' => count($deleted).' Job'.(count($deleted) === 1 ? '' : 's').' deleted successfully.',
                'deleted' => $deleted,
            ]);
        }

        $authorization = app(FlowAuthorizationService::class);
        foreach ($jobs as $job) {
            abort_unless($authorization->canChangeJobWorkflowStatus($user, $job), 403);
        }

        $deactivated = [];

        DB::transaction(function () use ($jobs, $user, &$deactivated): void {
            foreach ($jobs as $job) {
                $oldStatus = $job->status;
                $job->forceFill([
                    'status' => 'inactive',
                    'health' => $job->health === 'completed' ? 'completed' : 'inactive',
                    'attention_required' => false,
                    'next_action' => 'Deactivated by bulk action',
                ])->save();

                FlowActivity::query()->create([
                    'workspace_id' => $job->workspace_id,
                    'user_id' => $user->id,
                    'flow_job_id' => $job->id,
                    'subject_type' => FlowJob::class,
                    'subject_id' => $job->id,
                    'event' => 'job.bulk_deactivated',
                    'description' => 'Job '.$job->code.' deactivated by bulk action',
                    'properties' => [
                        'from_status' => $oldStatus,
                        'to_status' => 'inactive',
                    ],
                ]);

                $deactivated[] = [
                    'dbId' => (int) $job->id,
                    'id' => $job->code,
                    'status' => 'Inactive',
                    'health' => $job->health === 'completed' ? 'Completed' : 'Inactive',
                    'active' => false,
                ];
            }
        });

        return response()->json([
            'message' => count($deactivated).' Job'.(count($deactivated) === 1 ? '' : 's').' deactivated successfully.',
            'deactivated' => $deactivated,
        ]);
    }

    public function storeJob(
        StoreJobRequest $request,
        CreateJobAction $action,
        UploadJobAttachmentAction $attachmentAction,
    ): JsonResponse
    {
        $data = $request->validated();
        $assignedIds = collect([$data['owner_id'] ?? null, $data['coordinator_id'] ?? null])
            ->merge($data['member_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique();
        if ($assignedIds->contains(fn (int $id) => $id !== (int) $request->user()->id)) {
            abort_unless($request->user()->hasFlowAction('jobs', 'assign'), 403);
        }

        $productItems = collect($data['products'] ?? [])->map(fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'product_category_id' => isset($item['product_category_id']) ? (int) $item['product_category_id'] : null,
            'quantity' => (int) $item['quantity'],
        ])->values();
        $primaryProduct = $productItems->first();
        $submittedQuantity = isset($data['quantity'])
            ? (int) $data['quantity']
            : max(1, (int) $productItems->sum('quantity'));

        $startPhase = WorkflowPhase::query()
            ->where('workflow_template_id', (int) $data['workflow_template_id'])
            ->where('is_active', true)
            ->where('allow_job_start', true)
            ->whereKey((int) $data['start_phase_id'])
            ->first();

        if (! $startPhase) {
            throw ValidationException::withMessages([
                'start_phase_id' => 'The selected starting phase is not available for this Workflow Template.',
            ]);
        }

        $attachmentFiles = collect($request->file('attachments', []))->filter();
        $existingAttachmentIds = collect($data['existing_attachment_document_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($attachmentFiles->count() + $existingAttachmentIds->count() > 10) {
            throw ValidationException::withMessages(['attachments' => 'You can attach up to 10 files to a Job.']);
        }
        $startReason = $data['start_reason'] ?? null;
        if ($startPhase->sequence > 1 && blank($startReason)) {
            $startReason = ($attachmentFiles->isNotEmpty() || $existingAttachmentIds->isNotEmpty())
                ? 'Job started directly at this Workflow Phase with supporting attachment(s).'
                : 'Job started directly at the selected Workflow Phase.';
        }

        $storedFiles = [];
        $attachmentUploadStarted = false;

        try {
            $job = DB::transaction(function () use (
                $request,
                $data,
                $productItems,
                $primaryProduct,
                $submittedQuantity,
                $startReason,
                $attachmentFiles,
                $existingAttachmentIds,
                $action,
                $attachmentAction,
                &$storedFiles,
                &$attachmentUploadStarted,
            ): FlowJob {
                $job = $action->execute(new CreateJobData(
                    workspaceId: $request->user()->currentWorkspaceId(),
                    clientId: (int) $data['client_id'],
                    workflowTemplateId: (int) $data['workflow_template_id'],
                    startPhaseId: (int) $data['start_phase_id'],
                    productId: $primaryProduct['product_id'] ?? (isset($data['product_id']) ? (int) $data['product_id'] : null),
                    productCategoryId: $primaryProduct['product_category_id'] ?? (isset($data['product_category_id']) ? (int) $data['product_category_id'] : null),
                    currencyId: isset($data['currency_id']) ? (int) $data['currency_id'] : null,
                    priorityId: isset($data['priority_id']) ? (int) $data['priority_id'] : null,
                    ownerId: isset($data['owner_id']) ? (int) $data['owner_id'] : null,
                    coordinatorId: isset($data['coordinator_id']) ? (int) $data['coordinator_id'] : null,
                    title: $data['title'],
                    requestDescription: $data['request_description'] ?? null,
                    quantity: $submittedQuantity,
                    quotedAmount: (float) ($data['quoted_amount'] ?? 0),
                    deliveryDate: $data['delivery_date'] ?? null,
                    startReason: $startReason,
                    memberIds: $data['member_ids'] ?? [],
                    draft: (bool) ($data['draft'] ?? false),
                    productItems: $productItems->all(),
                ), $request->user());

                $attachmentUploadStarted = $attachmentFiles->isNotEmpty() || $existingAttachmentIds->isNotEmpty();
                foreach ($attachmentFiles as $attachment) {
                    $document = $attachmentAction->execute($job, $attachment, $request->user());
                    $version = $document->versions->first();
                    if ($version) {
                        $storedFiles[] = ['disk' => $version->disk, 'path' => $version->path];
                    }
                }

                if ($existingAttachmentIds->isNotEmpty()) {
                    $authorization = app(FlowAuthorizationService::class);
                    $sources = FlowDocument::query()
                        ->with(['job', 'currentVersion'])
                        ->where('workspace_id', $request->user()->currentWorkspaceId())
                        ->whereIn('id', $existingAttachmentIds)
                        ->whereNull('deleted_at')
                        ->get()
                        ->keyBy('id');
                    foreach ($existingAttachmentIds as $sourceId) {
                        $source = $sources->get($sourceId);
                        if (! $source || ! $source->job || ! $authorization->allows($request->user(), 'documents', 'view', $source)) {
                            throw ValidationException::withMessages(['existing_attachment_document_ids' => 'One of the selected stored documents is not available to your account.']);
                        }
                        $document = $attachmentAction->copyExisting($job, $source, $request->user());
                        $version = $document->versions->first();
                        if ($version) {
                            $storedFiles[] = ['disk' => $version->disk, 'path' => $version->path];
                        }
                    }
                }

                return $job;
            });
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['job' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            foreach ($storedFiles as $storedFile) {
                Storage::disk($storedFile['disk'])->delete($storedFile['path']);
            }

            if (! $attachmentUploadStarted) {
                throw $exception;
            }

            report($exception);
            throw ValidationException::withMessages([
                'attachments' => 'The Job could not be created because one or more attachments could not be saved. Please try again.',
            ]);
        }

        $job = $this->loadJob($job);
        $tasks = $this->loadJobTasks($job, $request->user());

        return response()->json([
            'message' => ($attachmentFiles->isEmpty() && $existingAttachmentIds->isEmpty())
                ? "{$job->code} created successfully."
                : "{$job->code} created successfully with ".($attachmentFiles->count() + $existingAttachmentIds->count())." attachment(s).",
            'job' => $this->mapJob($job, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
    }

    public function updateJob(
        UpdateJobRequest $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UpdateJobAction $action,
        FlowNotificationService $notifications,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        $data = $request->validated();

        $assignmentChanged = (int) ($data['owner_id'] ?? 0) !== (int) ($visible->owner_id ?? 0)
            || (int) ($data['coordinator_id'] ?? 0) !== (int) ($visible->coordinator_id ?? 0);
        if ($assignmentChanged) {
            abort_unless($authorization->allows($request->user(), 'jobs', 'assign', $visible), 403);
        }

        $previousMemberIds = $visible->members()->pluck('user_id')->map(fn ($id) => (int) $id);
        $previousAssignedUserIds = collect([$visible->owner_id, $visible->coordinator_id])
            ->merge($previousMemberIds)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $productItems = collect($data['products'])->map(fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'product_category_id' => (int) $item['product_category_id'],
            'quantity' => (int) $item['quantity'],
        ])->values();

        try {
            $updated = $action->execute($visible, new UpdateJobData(
                clientId: (int) $data['client_id'],
                currencyId: isset($data['currency_id']) ? (int) $data['currency_id'] : null,
                priorityId: isset($data['priority_id']) ? (int) $data['priority_id'] : null,
                ownerId: isset($data['owner_id']) ? (int) $data['owner_id'] : null,
                coordinatorId: isset($data['coordinator_id']) ? (int) $data['coordinator_id'] : null,
                title: $data['title'],
                requestDescription: $data['request_description'] ?? null,
                quotedAmount: array_key_exists('quoted_amount', $data) && $data['quoted_amount'] !== null ? (float) $data['quoted_amount'] : null,
                deliveryDate: $data['delivery_date'] ?? null,
                productItems: $productItems->all(),
            ), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['job' => $exception->getMessage()]);
        }

        $newMemberIds = $updated->members()->pluck('user_id')->map(fn ($id) => (int) $id);
        $currentAssignedUserIds = collect([$updated->owner_id, $updated->coordinator_id])
            ->merge($newMemberIds)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $newlyAssigned = $currentAssignedUserIds->diff($previousAssignedUserIds)->values();
        if ($newlyAssigned->isNotEmpty()) {
            DB::afterCommit(fn () => $notifications->jobMembersChanged($updated->id, $request->user()->id, $newlyAssigned->all()));
        }

        $updated = $this->loadJob($updated);
        return response()->json([
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
        ]);
    }

    public function storeJobComment(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        ActivityLogger $activity,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'jobs', 'view', $visible), 403);

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
        ]);
        $comment = trim((string) $data['comment']);
        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'Enter a comment.']);
        }

        $activity->log(
            (int) $visible->workspace_id,
            $request->user(),
            $visible,
            'job.commented',
            $comment,
            [
                'source' => 'job details',
                'comment_added' => true,
                'comment' => $comment,
            ],
        );

        // The controller instance can retain an activity cache during this request.
        // Clear this Job so the response includes the newly-created comment.
        unset($this->jobActivityCache[(int) $visible->id]);
        $updated = $this->loadJob($visible);

        return response()->json([
            'message' => 'Comment added.',
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
        ], 201);
    }

    public function syncJobPhaseTasks(
        Request $request,
        FlowJob $job,
        WorkflowPhase $phase,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        TaskPackActivator $taskPackActivator,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless((int) $phase->workflow_template_id === (int) $visible->workflow_template_id && $phase->is_active, 404);
        abort_unless(
            $request->user()->isFlowAdministrator()
                || $authorization->allows($request->user(), 'tasks', 'create', $visible),
            403,
        );

        $taskPackActivator->activate($visible, $phase, $request->user(), 'job_details_phase_task_sync');
        $updated = $this->loadJob($visible->fresh());
        $tasks = $this->loadJobTasks($updated, $request->user());

        return response()->json([
            'message' => "{$phase->name} tasks synchronized from Task Setup.",
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ]);
    }

    public function jobTasks(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'tasks', 'view', $visible), 403);

        return response()->json([
            'job' => $this->mapJob($this->loadJob($visible), $this->canViewFinancials($request->user())),
            'tasks' => $this->loadJobTasks($visible, $request->user())->map(fn (FlowTask $task) => $this->mapTask($task)),
        ])->header('Cache-Control', 'private, no-cache, must-revalidate');
    }

    public function moveJob(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        AccessResolver $access,
        MoveJobPhaseAction $action,
    ): JsonResponse {
        $data = $request->validate([
            'phase_id' => [
                'required',
                'integer',
                Rule::exists('workflow_phases', 'id')->where(
                    fn ($query) => $query
                        ->where('workflow_template_id', $job->workflow_template_id)
                        ->where('is_active', true),
                ),
            ],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $visible = $jobs->findBoardVisible($request->user(), $job->id);
        abort_unless($access->canManageJob($request->user(), $visible), 403);

        try {
            $updated = $action->execute($visible, (int) $data['phase_id'], $request->user(), $data['reason'] ?? null, 'exact_template_job_phase_board');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['phase_id' => $exception->getMessage()]);
        }

        $updated = $this->loadJob($updated);
        $tasks = $this->loadJobTasks($updated, $request->user());

        return response()->json([
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ]);
    }

    public function updateJobPhaseControls(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        ActivityLogger $activity,
        WorkflowAutomationService $automation,
        FlowNotificationService $notifications,
    ): JsonResponse {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->canChangeJobWorkflowStatus($request->user(), $visible), 403);

        $workspaceId = $request->user()->currentWorkspaceId();
        $workspaceMember = Rule::exists('workspace_memberships', 'user_id')
            ->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'active'));
        $data = $request->validate([
            'phase_owner_id' => ['sometimes', 'nullable', 'integer', $workspaceMember],
            'target_date' => ['sometimes', 'nullable', 'date'],
            'health_override' => ['sometimes', 'nullable', Rule::in(['on_track', 'needs_attention', 'at_risk', 'delayed', 'blocked'])],
            'automation_mode' => ['sometimes', 'nullable', Rule::in(['manual', 'automatic'])],
        ]);

        if (filled($data['phase_owner_id'] ?? null)) {
            $activePhaseOwner = WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', (int) $data['phase_owner_id'])
                ->where('status', 'active')
                ->whereHas('user', fn ($query) => $query->where('status', 'active'))
                ->whereHas('role', fn ($query) => $query->where('is_active', true))
                ->whereHas('workspace', fn ($query) => $query->where('is_active', true))
                ->exists();

            if (! $activePhaseOwner) {
                throw ValidationException::withMessages([
                    'phase_owner_id' => 'Choose a user with active workspace and role access.',
                ]);
            }
        }

        if (array_key_exists('phase_owner_id', $data)) {
            abort_unless($authorization->allows($request->user(), 'jobs', 'assign', $visible), 403);
        }

        /** @var FlowJobPhaseHistory|null $history */
        $history = $visible->phaseHistory()
            ->where('workflow_phase_id', $visible->current_phase_id)
            ->first();
        if (! $history) {
            throw ValidationException::withMessages([
                'phase' => 'The current workflow phase history could not be loaded.',
            ]);
        }

        $before = [
            'phase_owner_id' => $history->phase_owner_id,
            'target_date' => $history->target_date?->toDateString(),
            'health_override' => $history->health_override,
            'automation_mode' => $history->automation_mode,
        ];
        $changes = [];
        foreach (['phase_owner_id', 'target_date', 'health_override', 'automation_mode'] as $field) {
            if (! array_key_exists($field, $data)) continue;
            $next = $data[$field];
            if ($field === 'target_date' && blank($next)) $next = null;
            if ($before[$field] === $next || (string) $before[$field] === (string) $next) continue;
            $changes[$field] = ['from' => $before[$field], 'to' => $next];
            $history->{$field} = $next;
        }
        $history->changed_by = $request->user()->id;
        $history->save();

        if (array_key_exists('phase_owner_id', $data) && $data['phase_owner_id']) {
            FlowJobMember::updateOrCreate(
                ['flow_job_id' => $visible->id, 'user_id' => (int) $data['phase_owner_id']],
                [
                    'access_level' => 'member',
                    'can_manage_tasks' => true,
                    'can_upload_documents' => true,
                    'can_view_financials' => false,
                ],
            );
        }

        if (array_key_exists('phase_owner_id', $changes) && filled($changes['phase_owner_id']['to'] ?? null)) {
            $newPhaseOwnerId = (int) $changes['phase_owner_id']['to'];
            DB::afterCommit(fn () => $notifications->jobMembersChanged(
                $visible->id,
                $request->user()->id,
                [$newPhaseOwnerId],
            ));
        }

        if ($changes !== []) {
            $activity->log(
                $visible->workspace_id,
                $request->user(),
                $visible,
                'job.phase_controls_updated',
                "{$visible->currentPhase?->name} phase controls updated",
                [
                    'phase_id' => (int) $visible->current_phase_id,
                    'phase_name' => $visible->currentPhase?->name,
                    'changes' => $changes,
                ],
            );
        }

        $updated = $automation->synchronize($visible->fresh(), $request->user(), 'phase_controls', false);
        $updated = $this->loadJob($updated);
        $this->primeJobActivityCache(collect([$updated]));

        return response()->json([
            'job' => $this->mapJob($updated, $this->canViewFinancials($request->user())),
            'message' => 'Phase controls updated.',
        ]);
    }

    public function destroyJobPhase(
        Request $request,
        FlowJob $job,
        WorkflowPhase $phase,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        RemoveJobPhaseAction $action,
    ): JsonResponse {
        $jobs->findVisible($request->user(), $job->id);

        return response()->json([
            'message' => 'Workflow phases cannot be removed from a Job. Update the phase in Workflow Setup instead.',
        ], 403);
    }

}
