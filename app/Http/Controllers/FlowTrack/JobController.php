<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreJobRequest;
use App\Modules\OrderFlow\Application\Actions\CreateJobAction;
use App\Modules\OrderFlow\Application\DTOs\CreateJobData;
use App\Modules\OrderFlow\Application\Queries\FlowTrackOptionsQuery;
use App\Modules\OrderFlow\Business\Services\AccessResolver;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowJobMember;
use App\Modules\OrderFlow\Data\Repositories\JobRepository;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends Controller
{
    public function index(Request $request, JobRepository $jobs, FlowTrackOptionsQuery $options, AccessResolver $access): Response
    {
        return $this->render($request, $jobs, $options, $access);
    }

    public function show(Request $request, FlowJob $job, JobRepository $jobs, FlowTrackOptionsQuery $options, AccessResolver $access): Response
    {
        return $this->render($request, $jobs, $options, $access, $job->id);
    }

    public function store(StoreJobRequest $request, CreateJobAction $action, FlowAuthorizationService $authorization): RedirectResponse
    {
        $data = $request->validated();
        $assignedIds = collect([$data['owner_id'] ?? null, $data['coordinator_id'] ?? null])
            ->merge($data['member_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique();
        if ($assignedIds->contains(fn (int $id) => $id !== (int) $request->user()->id)) {
            abort_unless($authorization->allows($request->user(), 'jobs', 'assign'), 403);
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

        try {
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
                quantity: $submittedQuantity,
                quotedAmount: (float) ($data['quoted_amount'] ?? 0),
                deliveryDate: $data['delivery_date'] ?? null,
                startReason: $data['start_reason'] ?? null,
                memberIds: $data['member_ids'] ?? [],
                draft: (bool) ($data['draft'] ?? false),
                productItems: $productItems->all(),
            ), $request->user());
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['job' => $exception->getMessage()]);
        }

        return to_route('flowtrack.jobs.show', $job)->with('success', "{$job->code} created successfully.");
    }

    public function updateMembers(Request $request, FlowJob $job, JobRepository $jobs, FlowAuthorizationService $authorization, ActivityLogger $activityLogger): RedirectResponse
    {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'jobs', 'assign', $visible), 403);
        $workspaceId = $request->user()->currentWorkspaceId();
        $data = $request->validate([
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', Rule::exists('workspace_memberships', 'user_id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'active'))],
        ]);

        DB::transaction(function () use ($visible, $data, $request, $activityLogger) {
            $memberIds = collect($data['member_ids'] ?? [])
                ->push($visible->owner_id)
                ->push($visible->coordinator_id)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $visible->members()->whereNotIn('user_id', $memberIds)->delete();

            foreach ($memberIds as $memberId) {
                $isManager = in_array($memberId, [$visible->owner_id, $visible->coordinator_id], true);
                FlowJobMember::updateOrCreate(
                    ['flow_job_id' => $visible->id, 'user_id' => $memberId],
                    [
                        'access_level' => $isManager ? 'manager' : 'member',
                        'can_manage_tasks' => $isManager,
                        'can_upload_documents' => true,
                        'can_view_financials' => $memberId === $visible->owner_id,
                    ],
                );
            }

            $activityLogger->log($visible->workspace_id, $request->user(), $visible, 'job.members_updated', "Assigned team updated for {$visible->code}", ['member_ids' => $memberIds->all()]);
        });

        return back()->with('success', 'Assigned project team updated.');
    }

    public function destroy(Request $request, FlowJob $job, JobRepository $jobs, FlowAuthorizationService $authorization): RedirectResponse
    {
        $visible = $jobs->findVisible($request->user(), $job->id);
        abort_unless($authorization->allows($request->user(), 'jobs', 'delete', $visible), 403);

        DB::transaction(function () use ($visible): void {
            $visible->tasks()->delete();
            $visible->documents()->delete();
            $visible->delete();
        });

        return to_route('flowtrack.jobs.index')->with('success', 'Job deleted successfully.');
    }

    private function render(Request $request, JobRepository $jobs, FlowTrackOptionsQuery $options, AccessResolver $access, ?int $selectedId = null): Response
    {
        abort_unless($request->user()->hasFlowAction('jobs', 'view'), 403);
        $filters = $request->only(['search', 'phase', 'health', 'client']);
        $selected = $selectedId ? $jobs->findVisible($request->user(), $selectedId) : null;
        if ($selected) {
            $canViewFinancials = $access->canViewFinancials($request->user(), $selected);
            $selected->setAttribute('can_view_financials', $canViewFinancials);
            if (! $canViewFinancials) {
                $selected->setAttribute('quoted_amount', null);
            }
        }

        return Inertia::render('flowtrack/Jobs/Index', [
            'jobs' => $jobs->paginateVisible($request->user(), $filters),
            'filters' => $filters,
            'options' => $options->forWorkspace($request->user()),
            'selectedJob' => $selected,
        ]);
    }
}
