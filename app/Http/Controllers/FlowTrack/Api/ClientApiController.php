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


final class ClientApiController extends BaseApiController
{
    public function storeClient(StoreClientRequest $request, CreateClientAction $action): JsonResponse
    {
        $data = $request->validated();
        $this->authorizeClientSensitiveData($request->user(), $data);
        if (! empty($data['account_manager_id']) && (int) $data['account_manager_id'] !== (int) $request->user()->id) {
            abort_unless($request->user()->hasFlowAction('clients', 'assign'), 403);
        }
        $client = $action->execute($request->user()->currentWorkspaceId(), $data);
        $client->load('accountManager:id,name,initials')->loadCount([
            'jobs as active_jobs_count' => fn (Builder $query) => $query->where('status', '!=', 'completed'),
            'jobs as attention_jobs_count' => fn (Builder $query) => $query->where('attention_required', true),
        ]);

        return response()->json(['client' => $this->mapClient($client, $this->canViewFinancials($request->user()), app(FlowAuthorizationService::class)->canViewSensitiveField($request->user(), 'client_contact_details'))], 201);
    }

    public function updateClient(StoreClientRequest $request, Client $client, UpdateClientAction $action): JsonResponse
    {
        abort_unless($client->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $data = $request->validated();
        $this->authorizeClientSensitiveData($request->user(), $data, $client);
        if (array_key_exists('account_manager_id', $data) && (int) ($data['account_manager_id'] ?? 0) !== (int) ($client->account_manager_id ?? 0)) {
            abort_unless($request->user()->hasFlowAction('clients', 'assign', $client), 403);
        }
        $client = $action->execute($client, $data);
        $client->load('accountManager:id,name,initials')->loadCount([
            'jobs as active_jobs_count' => fn (Builder $query) => $query->where('status', '!=', 'completed'),
            'jobs as attention_jobs_count' => fn (Builder $query) => $query->where('attention_required', true),
        ]);

        return response()->json(['client' => $this->mapClient($client, $this->canViewFinancials($request->user()), app(FlowAuthorizationService::class)->canViewSensitiveField($request->user(), 'client_contact_details'))]);
    }

    public function destroyClient(Request $request, Client $client, DeleteClientAction $action): JsonResponse
    {
        abort_unless($client->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($request->user()->hasFlowAction('clients', 'delete', $client), 403);

        $action->execute($client);

        return response()->json(['message' => 'Client, related Jobs, Tasks, documents and stored files were deleted successfully.']);
    }

}
