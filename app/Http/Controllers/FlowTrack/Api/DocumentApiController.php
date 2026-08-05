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


final class DocumentApiController extends BaseApiController
{
    public function storeDocument(
        StoreDocumentRequest $request,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UploadDocumentAction $action,
    ): JsonResponse {
        $data = $request->validated();
        $job = $jobs->findVisible($request->user(), (int) $data['job_id']);
        abort_unless($authorization->allows($request->user(), 'documents', 'create', $job), 403);
        abort_unless($authorization->allows($request->user(), 'documents', 'link', $job), 403);

        $document = $action->execute(
            $job,
            $request->file('file'),
            isset($data['document_category_id']) ? (int) $data['document_category_id'] : null,
            true,
            $request->user(),
            isset($data['document_id']) ? (int) $data['document_id'] : null,
            $data['notes'] ?? null,
            (int) $data['workflow_phase_id'],
            isset($data['task_pack_item_id']) ? (int) $data['task_pack_item_id'] : null,
            false,
            isset($data['task_id']) ? (int) $data['task_id'] : null,
        );

        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'task', 'uploader', 'currentVersion', 'versions.uploader']);
        $updatedJob = $this->loadJob($job);
        $tasks = $this->loadJobTasks($updatedJob, $request->user());

        $workflowResult = $action->workflowResult();
        $message = 'Document uploaded and linked to the required Task.';
        if ($workflowResult['moved'] ?? false) {
            $message = 'Document uploaded, the required Task was completed, and the Job moved to the next Workflow Phase.';
        }

        return response()->json([
            'message' => $message,
            'document' => $this->mapDocument($document),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ]);
    }

    public function linkExistingDocument(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UploadDocumentAction $action,
    ): JsonResponse {
        $targetJob = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'documents', 'create', $targetJob), 403);
        abort_unless($authorization->allows($request->user(), 'documents', 'link', $targetJob), 403);

        $data = $request->validate([
            'source_document_id' => ['required', 'integer', Rule::exists('flow_documents', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $request->user()->currentWorkspaceId())
                    ->whereNull('deleted_at'))],
            'workflow_phase_id' => ['required', 'integer', Rule::exists('workflow_phases', 'id')
                ->where(fn ($query) => $query->where('workflow_template_id', $targetJob->workflow_template_id))],
            'task_pack_item_id' => ['nullable', 'integer', Rule::exists('task_pack_items', 'id')
                ->where(fn ($query) => $query->whereIn('task_pack_id', function ($subquery) use ($request): void {
                    $subquery->select('id')->from('task_packs')->where('workspace_id', $request->user()->currentWorkspaceId());
                }))],
            'document_category_id' => ['required', 'integer', Rule::exists('master_records', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $request->user()->currentWorkspaceId())
                    ->where('type', MasterDataType::DocumentCategory->value)
                    ->where('status', 'active')
                    ->whereNull('deleted_at'))],
            'task_id' => ['nullable', 'integer', Rule::exists('flow_tasks', 'id')
                ->where(fn ($query) => $query
                    ->where('flow_job_id', $targetJob->id)
                    ->whereNull('deleted_at'))],
        ]);

        $source = FlowDocument::query()
            ->with(['job', 'currentVersion'])
            ->where('workspace_id', $request->user()->currentWorkspaceId())
            ->whereNull('deleted_at')
            ->findOrFail((int) $data['source_document_id']);
        abort_unless($source->job && $authorization->allows($request->user(), 'documents', 'view', $source), 403);

        $version = $source->currentVersion;
        if (! $version || ! Storage::disk($version->disk)->exists($version->path)) {
            throw ValidationException::withMessages([
                'source_document_id' => 'The selected stored document file is no longer available.',
            ]);
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'flowtrack-doc-');
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            throw ValidationException::withMessages([
                'source_document_id' => 'A temporary copy of the selected document could not be created.',
            ]);
        }

        $sourceStream = null;
        $targetStream = null;
        try {
            $sourceStream = Storage::disk($version->disk)->readStream($version->path);
            $targetStream = fopen($temporaryPath, 'w+b');
            if (! is_resource($sourceStream) || ! is_resource($targetStream)) {
                throw ValidationException::withMessages([
                    'source_document_id' => 'The selected stored document could not be read.',
                ]);
            }
            stream_copy_to_stream($sourceStream, $targetStream);
            fclose($sourceStream);
            fclose($targetStream);
            $sourceStream = null;
            $targetStream = null;

            $uploadedFile = new UploadedFile(
                $temporaryPath,
                $version->original_name ?: ($source->name ?: 'document'),
                $version->mime_type ?: null,
                null,
                true,
            );

            $document = $action->execute(
                $targetJob,
                $uploadedFile,
                (int) $data['document_category_id'],
                true,
                $request->user(),
                null,
                'Linked from stored document '.$source->code,
                (int) $data['workflow_phase_id'],
                isset($data['task_pack_item_id']) ? (int) $data['task_pack_item_id'] : null,
                false,
                isset($data['task_id']) ? (int) $data['task_id'] : null,
            );
        } finally {
            if (is_resource($sourceStream)) fclose($sourceStream);
            if (is_resource($targetStream)) fclose($targetStream);
            @unlink($temporaryPath);
        }

        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'task', 'uploader', 'currentVersion', 'versions.uploader']);
        $updatedJob = $this->loadJob($targetJob);
        $tasks = $this->loadJobTasks($updatedJob, $request->user());

        return response()->json([
            'message' => 'Stored document linked to the Job.',
            'document' => $this->mapDocument($document),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
    }

    public function storeJobAttachment(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UploadJobAttachmentAction $attachmentAction,
    ): JsonResponse {
        $targetJob = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'documents', 'create', $targetJob), 403);
        abort_unless($authorization->allows($request->user(), 'documents', 'link', $targetJob), 403);

        $data = $request->validate([
            'task_id' => ['nullable', 'integer', Rule::exists('flow_tasks', 'id')
                ->where(fn ($query) => $query->where('flow_job_id', $targetJob->id)->whereNull('deleted_at'))],
            'file' => ['bail', 'required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,webp,zip,txt,csv'],
        ], [
            'file.required' => 'Choose a document before uploading.',
            'file.uploaded' => 'The server rejected the upload before FlowTrack received it. Confirm the PHP and Nginx upload limits from the deployment guide.',
            'file.max' => 'The document must be 20 MB or smaller.',
            'file.mimes' => 'Use a PDF, Office, image, ZIP, TXT, or CSV document.',
        ]);

        $task = isset($data['task_id']) ? FlowTask::query()->where('flow_job_id', $targetJob->id)->findOrFail((int) $data['task_id']) : null;
        $document = $attachmentAction->execute($targetJob, $request->file('file'), $request->user(), $task);
        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'task', 'uploader', 'currentVersion', 'versions.uploader']);
        $updatedJob = $this->loadJob($targetJob);
        $tasks = $this->loadJobTasks($updatedJob, $request->user());

        return response()->json([
            'message' => 'Attachment uploaded successfully.',
            'document' => $this->mapDocument($document),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
    }

    public function linkExistingJobAttachment(
        Request $request,
        FlowJob $job,
        JobRepository $jobs,
        FlowAuthorizationService $authorization,
        UploadJobAttachmentAction $attachmentAction,
    ): JsonResponse {
        $targetJob = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'documents', 'create', $targetJob), 403);
        abort_unless($authorization->allows($request->user(), 'documents', 'link', $targetJob), 403);

        $data = $request->validate([
            'source_document_id' => ['required', 'integer', Rule::exists('flow_documents', 'id')
                ->where(fn ($query) => $query
                    ->where('workspace_id', $request->user()->currentWorkspaceId())
                    ->whereNull('deleted_at'))],
            'task_id' => ['nullable', 'integer', Rule::exists('flow_tasks', 'id')
                ->where(fn ($query) => $query->where('flow_job_id', $targetJob->id)->whereNull('deleted_at'))],
        ]);

        $source = FlowDocument::query()
            ->with(['job', 'currentVersion'])
            ->where('workspace_id', $request->user()->currentWorkspaceId())
            ->whereNull('deleted_at')
            ->findOrFail((int) $data['source_document_id']);
        abort_unless($source->job && $authorization->allows($request->user(), 'documents', 'view', $source), 403);

        $task = isset($data['task_id']) ? FlowTask::query()->where('flow_job_id', $targetJob->id)->findOrFail((int) $data['task_id']) : null;
        $document = $attachmentAction->copyExisting($targetJob, $source, $request->user(), $task);
        $document->loadMissing(['job.client', 'category', 'phase', 'taskPackItem', 'task', 'uploader', 'currentVersion', 'versions.uploader']);
        $updatedJob = $this->loadJob($targetJob);
        $tasks = $this->loadJobTasks($updatedJob, $request->user());

        return response()->json([
            'message' => 'Stored document attached to the Job.',
            'document' => $this->mapDocument($document),
            'job' => $this->mapJob($updatedJob, $this->canViewFinancials($request->user())),
            'tasks' => $tasks->map(fn (FlowTask $task) => $this->mapTask($task)),
        ], 201);
    }

    public function destroyDocument(
        Request $request,
        FlowDocument $document,
        WorkflowAutomationService $automation,
        TaskPackActivator $taskPackActivator,
        ProgressCalculator $progress,
        ActivityLogger $activities,
        FlowNotificationService $notifications,
        DeleteDocumentAction $action,
    ): JsonResponse {
        abort_unless($document->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('documents', 'delete', $document), 403);

        $deletedPhaseId = (int) ($document->workflow_phase_id ?? 0);
        $deletedTaskId = (int) ($document->flow_task_id ?? 0);
        $job = $action->execute($document, $request->user());
        if ($job) {
            $job = $job->fresh(['currentPhase']);

            // Required-document Tasks must never remain Completed after their
            // evidence has been removed. Reconcile the current Task Pack first;
            // TaskPackActivator reopens the matching document Task when the
            // exact required file is no longer present.
            if ($job->currentPhase && (int) $job->current_phase_id === $deletedPhaseId) {
                $taskPackActivator->activate($job, $job->currentPhase, $request->user(), 'document_deletion');
                $job = $job->fresh(['currentPhase']);
            }

            // Generic mandatory Task attachments are not always Task Pack
            // document items. If the exact Task-linked evidence is removed,
            // reopen that Task so it cannot remain completed without the file.
            if ($deletedTaskId) {
                $this->reopenTaskWhenRequiredDocumentIsMissing(
                    $job,
                    $deletedTaskId,
                    $request->user(),
                    $progress,
                    $activities,
                    $notifications,
                );
            }

            $automation->synchronize($job, $request->user(), 'document_deletion', false);
        }

        $payload = ['message' => 'Document and all stored versions deleted successfully.'];
        if ($job) {
            $updatedJob = $this->loadJob($job->fresh());
            $tasks = $this->loadJobTasks($updatedJob, $request->user());
            $payload['job'] = $this->mapJob($updatedJob, $this->canViewFinancials($request->user()));
            $payload['tasks'] = $tasks->map(fn (FlowTask $task) => $this->mapTask($task));
            $payload['deletedTaskId'] = $deletedTaskId ?: null;
        }

        return response()->json($payload);
    }

    private function reopenTaskWhenRequiredDocumentIsMissing(
        FlowJob $job,
        int $taskId,
        User $actor,
        ProgressCalculator $progress,
        ActivityLogger $activities,
        FlowNotificationService $notifications,
    ): void {
        $task = FlowTask::query()
            ->with(['status', 'packItem.documentCategory', 'documentCategory'])
            ->where('flow_job_id', $job->id)
            ->whereNull('deleted_at')
            ->find($taskId);

        if (! $task) {
            return;
        }

        $categoryId = $task->document_category_id ?: $task->packItem?->document_category_id;
        if (! $categoryId) {
            return;
        }

        $hasEvidence = FlowDocument::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $task->workflow_phase_id)
            ->where('document_category_id', $categoryId)
            ->where('flow_task_id', $task->id)
            ->exists();

        if ($hasEvidence) {
            return;
        }

        $statusCode = strtoupper(trim((string) ($task->status?->code ?? '')));
        $statusName = strtoupper(trim((string) ($task->status?->name ?? '')));
        $isCompleted = (bool) $task->completed_at
            || in_array($statusCode, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'], true)
            || in_array($statusName, ['COMPLETED', 'COMPLETE', 'DONE'], true);

        if (! $isCompleted) {
            return;
        }

        $statusQuery = MasterRecord::query()
            ->where('workspace_id', $job->workspace_id)
            ->where('type', MasterDataType::TaskStatus->value)
            ->where('status', 'active');
        $initialStatus = (clone $statusQuery)
            ->where(function ($query): void {
                $query->whereIn('code', ['READY', 'NS', 'NEW'])
                    ->orWhereIn('name', ['Ready', 'Not Started', 'New']);
            })
            ->orderByRaw("case when upper(code) = 'READY' or upper(name) = 'READY' then 1 when upper(code) = 'NS' or upper(name) = 'NOT STARTED' then 2 else 3 end")
            ->first()
            ?: (clone $statusQuery)->orderBy('sort_order')->orderBy('id')->first();

        if (! $initialStatus) {
            return;
        }

        $previousStatus = $task->status?->name ?: 'Completed';
        $previousProgress = (int) $task->progress;
        $task->forceFill([
            'task_status_id' => $initialStatus->id,
            'progress' => $progress->forTaskStatus($initialStatus),
            'completed_at' => null,
            'attention_required' => false,
            'blocker_reason' => null,
        ])->save();

        $activities->log(
            (int) $job->workspace_id,
            $actor,
            $task,
            'task.document_removed',
            "Task {$task->code} reopened because its required document was deleted",
            [
                'changes' => [
                    ['field' => 'Status', 'from' => $previousStatus, 'to' => $initialStatus->name],
                    ['field' => 'Progress', 'from' => $previousProgress.'%', 'to' => $task->progress.'%'],
                ],
                'source' => 'document_deletion',
            ],
        );

        $notifications->taskChanged($task->id, $actor->id, $task->assignee_id, $previousStatus);
    }

}
