<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreDocumentRequest;
use App\Modules\OrderFlow\Application\Actions\UploadDocumentAction;
use App\Modules\OrderFlow\Application\Queries\FlowTrackOptionsQuery;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\AccessResolver;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\JobDocumentRequirementService;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowDocument;
use App\Modules\OrderFlow\Data\Models\FlowDocumentVersion;
use App\Modules\OrderFlow\Data\Repositories\JobRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class DocumentController extends Controller
{
    public function index(Request $request, JobRepository $jobs, FlowTrackOptionsQuery $options, FlowAuthorizationService $authorization, JobDocumentRequirementService $requirements): Response
    {
        abort_unless($authorization->allows($request->user(), 'documents', 'view'), 403);
        $workspaceId = $request->user()->currentWorkspaceId();
        $search = (string) $request->query('search', '');
        $category = $request->query('category');
        $client = $request->query('client');

        $documents = $authorization->applyDocumentVisibility(FlowDocument::query(), $request->user())
            ->with(['job.client', 'phase:id,name,short_name,sequence', 'taskPackItem:id,title', 'category', 'uploader', 'linker', 'versions.uploader'])
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhereHas('job', fn (Builder $job) => $job->where('code', 'like', "%{$search}%"))))
            ->when($category, fn (Builder $query) => $query->where('document_category_id', $category))
            ->when($client, fn (Builder $query) => $query->whereHas('job', fn (Builder $job) => $job->where('client_id', $client)))
            ->latest()
            ->paginate(24)
            ->withQueryString();

        $documents->getCollection()->each(fn (FlowDocument $document) => $document->job?->makeHidden(['quoted_amount', 'currency_id']));

        return Inertia::render('flowtrack/Documents/Index', [
            'documents' => $documents,
            'jobs' => $jobs->visibleQuery($request->user())
                ->with(['client:id,name', 'currentPhase.documentCategory', 'currentPhase.taskPack.items.documentCategory'])
                ->orderBy('code')
                ->get(['id', 'workspace_id', 'client_id', 'workflow_template_id', 'current_phase_id', 'code', 'title'])
                ->map(fn ($job): array => [
                    'id' => $job->id,
                    'client_id' => $job->client_id,
                    'code' => $job->code,
                    'title' => $job->title,
                    'client' => $job->client,
                    'current_phase_id' => $job->current_phase_id,
                    'current_phase_name' => $job->currentPhase?->name,
                    'document_requirements' => $requirements->requirements($job)->values()->all(),
                ])
                ->values(),
            'options' => $options->forWorkspace($request->user()),
            'filters' => compact('search', 'category', 'client'),
            'selectedJobId' => $request->integer('job') ?: null,
            'openUpload' => $request->boolean('upload'),
            'canLinkDocuments' => $authorization->allows($request->user(), 'documents', 'link'),
            'canUploadDocuments' => $authorization->allows($request->user(), 'documents', 'create')
                && $authorization->allows($request->user(), 'documents', 'link'),
            'documentType' => MasterDataType::DocumentCategory->value,
        ]);
    }

    public function download(Request $request, FlowDocumentVersion $version, JobRepository $jobs, FlowAuthorizationService $authorization): StreamedResponse
    {
        $version->loadMissing('document.job');
        $job = $jobs->findVisible($request->user(), $version->document->flow_job_id);
        abort_unless($authorization->allows($request->user(), 'documents', 'export', $version->document), 403);
        abort_unless(Storage::disk($version->disk)->exists($version->path), 404);

        return Storage::disk($version->disk)->download($version->path, $version->original_name);
    }

    public function store(StoreDocumentRequest $request, JobRepository $jobs, FlowAuthorizationService $authorization, UploadDocumentAction $action): RedirectResponse
    {
        $data = $request->validated();
        $job = $jobs->findVisible($request->user(), (int) $data['job_id']);
        abort_unless($authorization->allows($request->user(), 'documents', 'create', $job), 403);
        abort_unless($authorization->allows($request->user(), 'documents', 'link', $job), 403);

        $action->execute(
            $job,
            $request->file('file'),
            isset($data['document_category_id']) ? (int) $data['document_category_id'] : null,
            (bool) ($data['is_final'] ?? false),
            $request->user(),
            isset($data['document_id']) ? (int) $data['document_id'] : null,
            $data['notes'] ?? null,
            (int) $data['workflow_phase_id'],
            isset($data['task_pack_item_id']) ? (int) $data['task_pack_item_id'] : null,
            (bool) ($data['advance_phase'] ?? false),
        );

        $result = $action->workflowResult();
        $message = 'Document uploaded and linked to the current Workflow Phase.';
        if ($result['moved'] ?? false) {
            $message = 'Document uploaded, linked, and the Job moved to the next Workflow Phase.';
        } elseif ((bool) ($data['advance_phase'] ?? false) && ! ($result['ready'] ?? false) && ! empty($result['blockers'])) {
            $message = 'Document uploaded and linked. The Job remains in its current Phase: '.implode(' ', $result['blockers']);
        }

        return back()->with('success', $message);
    }
    public function destroy(Request $request, FlowDocument $document, FlowAuthorizationService $authorization, WorkflowAutomationService $automation): RedirectResponse
    {
        abort_unless($document->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($authorization->allows($request->user(), 'documents', 'delete', $document), 403);
        $job = $document->job;
        $document->delete();
        if ($job) {
            $automation->synchronize($job, $request->user(), 'document_deletion', false);
        }

        return back()->with('success', 'Document deleted successfully.');
    }

}
