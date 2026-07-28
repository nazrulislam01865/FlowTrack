<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreDocumentRequest;
use App\Modules\OrderFlow\Application\Actions\UploadDocumentAction;
use App\Modules\OrderFlow\Application\Queries\FlowTrackOptionsQuery;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\AccessResolver;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
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
    public function index(Request $request, JobRepository $jobs, FlowTrackOptionsQuery $options, FlowAuthorizationService $authorization): Response
    {
        abort_unless($authorization->allows($request->user(), 'documents', 'view'), 403);
        $workspaceId = $request->user()->currentWorkspaceId();
        $search = (string) $request->query('search', '');
        $category = $request->query('category');
        $client = $request->query('client');

        $documents = $authorization->applyDocumentVisibility(FlowDocument::query(), $request->user())
            ->with(['job.client', 'category', 'uploader', 'versions.uploader'])
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")->orWhereHas('job', fn (Builder $job) => $job->where('code', 'like', "%{$search}%"))))
            ->when($category, fn (Builder $query) => $query->where('document_category_id', $category))
            ->when($client, fn (Builder $query) => $query->whereHas('job', fn (Builder $job) => $job->where('client_id', $client)))
            ->latest()
            ->paginate(24)
            ->withQueryString();

        $documents->getCollection()->each(fn (FlowDocument $document) => $document->job?->makeHidden(['quoted_amount', 'currency_id']));

        return Inertia::render('flowtrack/Documents/Index', [
            'documents' => $documents,
            'jobs' => $jobs->visibleQuery($request->user())->with('client:id,name')->orderBy('code')->get(['id', 'client_id', 'code', 'title']),
            'options' => $options->forWorkspace($request->user()),
            'filters' => compact('search', 'category', 'client'),
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

        $action->execute(
            $job,
            $request->file('file'),
            isset($data['document_category_id']) ? (int) $data['document_category_id'] : null,
            (bool) ($data['is_final'] ?? false),
            $request->user(),
            isset($data['document_id']) ? (int) $data['document_id'] : null,
            $data['notes'] ?? null,
        );

        return back()->with('success', 'Document uploaded successfully.');
    }
    public function destroy(Request $request, FlowDocument $document, FlowAuthorizationService $authorization): RedirectResponse
    {
        abort_unless($document->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($authorization->allows($request->user(), 'documents', 'delete', $document), 403);
        $document->delete();

        return back()->with('success', 'Document deleted successfully.');
    }

}
