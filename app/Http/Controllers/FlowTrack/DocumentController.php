<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreDocumentRequest;
use App\Modules\OrderFlow\Application\Actions\DeleteDocumentAction;
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

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        return redirect('/flowtrack/view/documents');
    }


    public function download(Request $request, FlowDocumentVersion $version, JobRepository $jobs, FlowAuthorizationService $authorization): StreamedResponse
    {
        $version->loadMissing('document.job');
        abort_unless($version->document, 404);
        $job = $jobs->findVisible($request->user(), $version->document->flow_job_id);
        abort_unless($authorization->allows($request->user(), 'documents', 'export', $version->document), 403);
        abort_unless(Storage::disk($version->disk)->exists($version->path), 404);

        return Storage::disk($version->disk)->download($version->path, $version->original_name);
    }

    public function preview(Request $request, FlowDocumentVersion $version, JobRepository $jobs, FlowAuthorizationService $authorization): StreamedResponse
    {
        $version->loadMissing('document.job');
        abort_unless($version->document, 404);
        $jobs->findVisible($request->user(), $version->document->flow_job_id);
        abort_unless($authorization->allows($request->user(), 'documents', 'view', $version->document), 403);

        $disk = Storage::disk($version->disk);
        abort_unless($disk->exists($version->path), 404);

        $mimeType = strtolower((string) ($version->mime_type ?: $disk->mimeType($version->path)));
        $previewableMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/bmp',
            'image/x-ms-bmp',
        ];

        abort_unless(in_array($mimeType, $previewableMimeTypes, true), 415);

        return $disk->response(
            $version->path,
            $version->original_name,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
            ],
            'inline',
        );
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
            true,
            $request->user(),
            isset($data['document_id']) ? (int) $data['document_id'] : null,
            $data['notes'] ?? null,
            (int) $data['workflow_phase_id'],
            isset($data['task_pack_item_id']) ? (int) $data['task_pack_item_id'] : null,
            false,
        );

        $result = $action->workflowResult();
        $message = 'Document uploaded and linked to the required Task.';
        if ($result['moved'] ?? false) {
            $message = 'Document uploaded, the required Task was completed, and the Job moved to the next Workflow Phase.';
        }

        return back()->with('success', $message);
    }
    public function destroy(
        Request $request,
        FlowDocument $document,
        FlowAuthorizationService $authorization,
        WorkflowAutomationService $automation,
        DeleteDocumentAction $action,
    ): RedirectResponse {
        abort_unless($document->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($authorization->allows($request->user(), 'documents', 'delete', $document), 403);

        $job = $action->execute($document, $request->user());
        if ($job) {
            $automation->synchronize($job->fresh(), $request->user(), 'document_deletion', false);
        }

        return back()->with('success', 'Document and all stored versions deleted successfully.');
    }

}
