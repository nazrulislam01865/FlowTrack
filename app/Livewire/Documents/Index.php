<?php

namespace App\Livewire\Documents;

use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\InquiryDocument;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\DocumentService;
use App\Services\FilterOptionService;
use App\Services\MasterDataService;
use App\Support\AttachmentUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    use WithFileUploads, WithPagination;

    public string $search = '';
    public string $client = '';
    public string $linkType = '';
    public string $uploader = '';
    public string $dateRange = '';
    public string $sort = 'updated_desc';
    public int $perPage = 25;

    public ?int $selectedDocumentId = null;
    public string $selectedDocumentSource = 'order';
    public bool $showDetails = false;

    public bool $showUpload = false;
    public array $documentUploads = [];
    public ?int $uploadJobId = null;
    public ?int $uploadTaskId = null;
    public string $uploadCategory = '';

    public bool $showRename = false;
    public ?int $renameDocumentId = null;
    public string $renameDocumentSource = 'order';
    public string $renameName = '';

    public bool $showVersionUpload = false;
    public ?int $versionDocumentId = null;
    public $versionUpload = null;

    public bool $showVersions = false;
    public ?int $versionsDocumentId = null;

    public function mount(): void
    {
        $this->client = request()->integer('client') ? (string) request()->integer('client') : '';
        $this->uploadJobId = request()->integer('job') ?: null;
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'client', 'linkType', 'uploader', 'dateRange', 'sort', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'client', 'linkType', 'uploader', 'dateRange']);
        $this->sort = 'updated_desc';
        $this->resetPage();
    }

    public function openDetails(string $source, int $id): void
    {
        $this->resolveArchiveDocument($source, $id);
        $this->selectedDocumentSource = $source;
        $this->selectedDocumentId = $id;
        $this->showDetails = true;
    }

    public function closeDetails(): void
    {
        $this->showDetails = false;
    }

    public function openRename(string $source, int $id): void
    {
        abort_unless(auth()->user()->canModule('document_archive', 'edit'), 403);
        $document = $this->resolveArchiveDocument($source, $id, true);

        $this->renameDocumentSource = $source;
        $this->renameDocumentId = $id;
        $this->renameName = (string) $document->name;
        $this->showRename = true;
        $this->resetValidation('renameName');
    }

    public function closeRename(): void
    {
        $this->showRename = false;
        $this->renameDocumentId = null;
        $this->renameName = '';
        $this->resetValidation('renameName');
    }

    public function renameDocument(): void
    {
        abort_unless($this->renameDocumentId, 422);
        abort_unless(auth()->user()->canModule('document_archive', 'edit'), 403);
        $this->validate(['renameName' => ['required', 'string', 'max:255']]);

        $name = trim($this->renameName);
        abort_if($name === '' || str_contains($name, '/') || str_contains($name, '\\'), 422, 'Enter a valid file name.');

        if ($this->renameDocumentSource === 'inquiry') {
            $document = $this->resolveInquiryDocument($this->renameDocumentId, true);
            $document->update(['name' => $name]);
            $document->inquiry?->activities()->create([
                'user_id' => auth()->id(),
                'event' => 'inquiry.document_renamed',
                'description' => 'Document renamed to '.$name.'.',
                'meta' => ['inquiry_document_id' => $document->id],
            ]);
        } else {
            $document = $this->resolveOrderDocument($this->renameDocumentId, true);
            app(DocumentService::class)->rename($document, $name, auth()->user(), 'document_archive');
        }

        $this->closeRename();
        session()->flash('success', 'Document renamed successfully.');
    }

    public function openVersionUpload(int $id): void
    {
        abort_unless(auth()->user()->canModule('document_archive', 'create'), 403);
        $this->resolveOrderDocument($id);
        $this->versionDocumentId = $id;
        $this->versionUpload = null;
        $this->showVersionUpload = true;
        $this->resetValidation('versionUpload');
    }

    public function closeVersionUpload(): void
    {
        $this->showVersionUpload = false;
        $this->versionDocumentId = null;
        $this->versionUpload = null;
        $this->resetValidation('versionUpload');
    }

    public function storeNewVersion(): void
    {
        abort_unless($this->versionDocumentId, 422);
        abort_unless(auth()->user()->canModule('document_archive', 'create'), 403);
        $this->validate([
            'versionUpload' => AttachmentUpload::requiredRules(AttachmentUpload::DOCUMENTS, 20480),
        ]);

        $base = $this->resolveOrderDocument($this->versionDocumentId);
        $created = app(DocumentService::class)->storeVersion($base, $this->versionUpload, auth()->user(), 'document_archive');
        $this->selectedDocumentSource = 'order';
        $this->selectedDocumentId = $created->id;
        $this->closeVersionUpload();
        session()->flash('success', 'New document version uploaded successfully.');
    }

    public function openVersions(int $id): void
    {
        $this->resolveOrderDocument($id);
        $this->versionsDocumentId = $id;
        $this->showVersions = true;
    }

    public function closeVersions(): void
    {
        $this->showVersions = false;
        $this->versionsDocumentId = null;
    }

    public function openUpload(): void
    {
        abort_unless(auth()->user()->canModule('document_archive', 'create'), 403);
        if ($this->uploadCategory === '') {
            $this->uploadCategory = app(MasterDataService::class)->active('document_category')->first()?->name ?: 'Other';
        }
        $this->showUpload = true;
    }

    public function closeUpload(): void
    {
        $this->showUpload = false;
        $this->documentUploads = [];
        $this->uploadTaskId = null;
    }

    public function updatedUploadJobId(): void
    {
        $this->uploadTaskId = null;
    }

    public function storeDocuments(): void
    {
        abort_unless(auth()->user()->canModule('document_archive', 'create'), 403);
        $this->validate([
            'documentUploads' => ['required', 'array', 'min:1'],
            'documentUploads.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS, 20480),
            'uploadJobId' => ['required', 'integer'],
            'uploadTaskId' => ['nullable', 'integer'],
            'uploadCategory' => ['required', 'string', 'max:100'],
        ]);

        $access = app(AccessControlService::class);
        $job = $access->applyDocumentArchiveJobScope(FlowJob::query(), auth()->user())
            ->findOrFail((int) $this->uploadJobId);
        $task = $this->uploadTaskId
            ? $access->applyDocumentArchiveTaskScope(Task::query(), auth()->user())
                ->where('flow_job_id', $job->id)
                ->findOrFail($this->uploadTaskId)
            : null;

        foreach ($this->documentUploads as $file) {
            $doc = app(DocumentService::class)->store($file, [
                'flow_job_id' => $job->id,
                'client_id' => $job->client_id,
                'task_id' => $task?->id,
                'category' => $this->uploadCategory,
            ], auth()->user(), 'document_archive');
            $this->selectedDocumentSource = 'order';
            $this->selectedDocumentId = $doc->id;
        }

        $this->closeUpload();
        session()->flash('success', 'Document(s) uploaded successfully.');
    }

    public function deleteArchiveDocument(string $source, int $id): void
    {
        abort_unless(auth()->user()->canModule('document_archive', 'delete'), 403);

        if ($source === 'inquiry') {
            $document = $this->resolveInquiryDocument($id);
            $path = (string) $document->path;
            $name = (string) $document->name;
            $inquiry = $document->inquiry;
            $document->delete();

            if ($path !== ''
                && ! Document::query()->where('path', $path)->exists()
                && ! InquiryDocument::query()->where('path', $path)->exists()) {
                app(\App\Services\SecureDocumentStorage::class)->delete($path);
            }

            $inquiry?->activities()->create([
                'user_id' => auth()->id(),
                'event' => 'inquiry.document_removed',
                'description' => $name.' removed from the Document Archive.',
                'meta' => ['inquiry_document_id' => $id, 'source' => 'document_archive'],
            ]);
        } else {
            $document = $this->resolveOrderDocument($id);
            app(DocumentService::class)->delete($document, auth()->user(), 'document_archive');
        }

        if ($this->selectedDocumentSource === $source && $this->selectedDocumentId === $id) {
            $this->selectedDocumentId = null;
            $this->showDetails = false;
        }

        session()->flash('success', 'Document deleted successfully.');
    }

    public function render()
    {
        return view('livewire.documents.index', $this->documentsPageData());
    }

    private function documentsPageData(): array
    {
        $user = auth()->user();
        $documents = $this->archivePaginator($user);
        $service = app(DocumentService::class);

        $orderIds = $documents->getCollection()->where('source_type', 'order')->pluck('source_id')->map(fn ($id) => (int) $id)->all();
        $inquiryIds = $documents->getCollection()->where('source_type', 'inquiry')->pluck('source_id')->map(fn ($id) => (int) $id)->all();

        $orderModels = Document::query()
            ->with(['job.client', 'client', 'task', 'uploader'])
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');
        $inquiryModels = InquiryDocument::query()
            ->with(['inquiry.client', 'task', 'uploader'])
            ->whereIn('id', $inquiryIds)
            ->get()
            ->keyBy('id');

        $rows = $documents->getCollection()->map(function ($archive) use ($orderModels, $inquiryModels, $user) {
            if ($archive->source_type === 'inquiry') {
                $document = $inquiryModels->get((int) $archive->source_id);
                if (!$document) return null;
                $inquiry = $document->inquiry;
                $task = $document->task;

                return [
                    'source' => 'inquiry',
                    'id' => (int) $document->id,
                    'name' => (string) $document->name,
                    'extension' => strtolower(pathinfo((string) $document->name, PATHINFO_EXTENSION)),
                    'size' => (int) $document->size,
                    'updated_at' => $document->updated_at,
                    'uploader' => $document->uploader,
                    'client' => $inquiry?->client,
                    'record_kind' => 'Inquiry',
                    'record_number' => $inquiry?->inquiry_number ?: 'Inquiry',
                    'record_url' => ($inquiry && $user->canModule('inquiries', 'view')) ? route('inquiries.index', ['open' => $inquiry->id]) : null,
                    'task_title' => $task?->title,
                    'task_number' => $task ? 'Task '.$task->sequence : null,
                    'task_url' => ($inquiry && $task && $user->canModule('inquiries', 'view')) ? route('inquiries.index', ['open' => $inquiry->id, 'task' => $task->id]) : null,
                    'open_url' => route('document-archive.inquiries.open', $document),
                    'download_url' => route('document-archive.inquiries.download', $document),
                    'is_unlinked' => false,
                    'is_client_only' => false,
                    'can_edit' => $this->canEditArchiveDocument($document, $user),
                    'can_delete' => $user->canModule('document_archive', 'delete'),
                    'supports_versions' => false,
                ];
            }

            $document = $orderModels->get((int) $archive->source_id);
            if (!$document) return null;
            $job = $document->job;
            $task = $document->task;
            $client = $document->client ?: $job?->client;
            $isUnlinked = !$document->flow_job_id && !$document->task_id && !$document->client_id;
            $isClientOnly = !$document->flow_job_id && !$document->task_id && (bool) $document->client_id;

            return [
                'source' => 'order',
                'id' => (int) $document->id,
                'name' => (string) $document->name,
                'extension' => strtolower(pathinfo((string) $document->name, PATHINFO_EXTENSION)),
                'size' => (int) $document->size,
                'updated_at' => $document->updated_at,
                'uploader' => $document->uploader,
                'client' => $client,
                'record_kind' => $isUnlinked ? 'Not linked' : ($isClientOnly ? 'Client only' : 'Order'),
                'record_number' => $job?->displayOrderNumber() ?: null,
                'record_url' => ($job && $user->canModule('jobs', 'view')) ? route('jobs.index', ['open' => $job->id]) : null,
                'task_title' => $task?->title,
                'task_number' => $task?->task_number,
                'task_url' => ($job && $task && $user->canModule('jobs', 'view')) ? route('jobs.index', ['open' => $job->id, 'task' => $task->id]) : null,
                'open_url' => route('document-archive.orders.open', $document),
                'download_url' => route('document-archive.orders.download', $document),
                'is_unlinked' => $isUnlinked,
                'is_client_only' => $isClientOnly,
                'can_edit' => $this->canEditArchiveDocument($document, $user),
                'can_delete' => $user->canModule('document_archive', 'delete'),
                'supports_versions' => true,
            ];
        })->filter()->values();
        $documents->setCollection($rows);

        [$allCount, $storageBytes] = $this->archiveTotals($user);
        $filterOptions = app(FilterOptionService::class);
        $clientOptions = $filterOptions->options($user, 'clients', 'documents', '', $this->client, FilterOptionService::COMPACT_PER_PAGE);
        $uploaderOptions = $filterOptions->options($user, 'users', 'documents', '', $this->uploader, FilterOptionService::COMPACT_PER_PAGE);

        $categories = $this->showUpload ? app(MasterDataService::class)->active('document_category') : collect();
        $access = app(AccessControlService::class);
        $jobs = $this->showUpload
            ? $filterOptions->options($user, 'jobs', 'documents', '', $this->uploadJobId, FilterOptionService::COMPACT_PER_PAGE)
            : collect();
        $uploadTasks = $this->showUpload && $this->uploadJobId
            ? $access->applyDocumentArchiveTaskScope(Task::query(), $user)
                ->where('flow_job_id', $this->uploadJobId)
                ->with('phase')
                ->orderBy('id')
                ->get()
            : collect();

        $selected = null;
        if ($this->showDetails && $this->selectedDocumentId) {
            $selected = $this->archiveDetailData($this->selectedDocumentSource, $this->selectedDocumentId);
        }

        $versions = collect();
        if ($this->showVersions && $this->versionsDocumentId) {
            $base = $this->resolveOrderDocument($this->versionsDocumentId);
            $versions = $service->versions($base, $user, 'document_archive');
        }

        return [
            'documents' => $documents,
            'documentCount' => $allCount,
            'storageBytes' => $storageBytes,
            'clientOptions' => $clientOptions,
            'uploaderOptions' => $uploaderOptions,
            'jobs' => $jobs,
            'categories' => $categories,
            'uploadTasks' => $uploadTasks,
            'selected' => $selected,
            'versions' => $versions,
        ];
    }

    private function archivePaginator(User $user): LengthAwarePaginator
    {
        $order = $this->orderArchiveQuery($user)
            ->selectRaw("'order' as source_type, documents.id as source_id, documents.updated_at as archive_updated_at, documents.name as archive_name, documents.size as archive_size");
        $inquiry = $this->inquiryArchiveQuery($user)
            ->selectRaw("'inquiry' as source_type, inquiry_documents.id as source_id, inquiry_documents.updated_at as archive_updated_at, inquiry_documents.name as archive_name, inquiry_documents.size as archive_size");

        if ($this->linkType === 'inquiry') {
            $union = $inquiry->toBase();
        } elseif (in_array($this->linkType, ['order', 'client', 'unlinked'], true)) {
            $union = $order->toBase();
        } else {
            $union = $order->toBase()->unionAll($inquiry->toBase());
        }

        $query = DB::query()->fromSub($union, 'document_archive');
        match ($this->sort) {
            'updated_asc' => $query->orderBy('archive_updated_at')->orderBy('source_id'),
            'name_asc' => $query->orderBy('archive_name')->orderByDesc('archive_updated_at'),
            'name_desc' => $query->orderByDesc('archive_name')->orderByDesc('archive_updated_at'),
            'size_desc' => $query->orderByDesc('archive_size')->orderByDesc('archive_updated_at'),
            default => $query->orderByDesc('archive_updated_at')->orderByDesc('source_id'),
        };

        return $query->paginate(max(10, min(100, $this->perPage)));
    }

    private function orderArchiveQuery(User $user): Builder
    {
        $query = app(DocumentService::class)->query($user, [
            'search' => trim($this->search),
            'client' => $this->client,
        ], 'document_archive');

        if ($this->linkType === 'order') {
            $query->whereNotNull('documents.flow_job_id');
        } elseif ($this->linkType === 'task') {
            $query->whereNotNull('documents.task_id');
        } elseif ($this->linkType === 'client') {
            $query->whereNull('documents.flow_job_id')->whereNull('documents.task_id')->whereNotNull('documents.client_id');
        } elseif ($this->linkType === 'unlinked') {
            $query->whereNull('documents.flow_job_id')->whereNull('documents.task_id')->whereNull('documents.client_id');
        }

        if ($this->uploader !== '') $query->where('documents.uploaded_by', (int) $this->uploader);
        $this->applyDateRange($query, 'documents.updated_at');

        return $query;
    }

    private function inquiryArchiveQuery(User $user): Builder
    {
        $query = app(AccessControlService::class)
            ->applyInquiryDocumentArchiveScope(InquiryDocument::query(), $user)
            ->join('inquiries', 'inquiries.id', '=', 'inquiry_documents.inquiry_id');

        $search = trim($this->search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $match) use ($like): void {
                $match->whereLike('inquiry_documents.name', $like)
                    ->orWhereLike('inquiries.inquiry_number', $like)
                    ->orWhereLike('inquiries.subject', $like)
                    ->orWhereHas('task', fn (Builder $task) => $task->whereLike('title', $like))
                    ->orWhereHas('uploader', fn (Builder $uploader) => $uploader->whereLike('name', $like));
            });
        }

        if ($this->client !== '') $query->where('inquiries.client_id', (int) $this->client);
        if ($this->uploader !== '') $query->where('inquiry_documents.uploaded_by', (int) $this->uploader);
        if ($this->linkType === 'task') $query->whereNotNull('inquiry_documents.inquiry_task_id');
        if (in_array($this->linkType, ['order', 'client', 'unlinked'], true)) $query->whereRaw('1 = 0');
        $this->applyDateRange($query, 'inquiry_documents.updated_at');

        return $query;
    }

    private function applyDateRange(Builder $query, string $column): void
    {
        match ($this->dateRange) {
            'today' => $query->where($column, '>=', now()->startOfDay()),
            '7_days' => $query->where($column, '>=', now()->subDays(7)),
            '30_days' => $query->where($column, '>=', now()->subDays(30)),
            default => null,
        };
    }

    private function archiveTotals(User $user): array
    {
        $order = app(DocumentService::class)->query($user, [], 'document_archive');
        $inquiry = app(AccessControlService::class)
            ->applyInquiryDocumentArchiveScope(InquiryDocument::query(), $user);

        return [
            (clone $order)->count() + (clone $inquiry)->count(),
            (int) (clone $order)->sum('size') + (int) (clone $inquiry)->sum('size'),
        ];
    }


    private function archiveDetailData(string $source, int $id): ?array
    {
        if ($source === 'inquiry') {
            $document = $this->resolveInquiryDocument($id);
            $document->loadMissing(['inquiry.client', 'task', 'uploader']);
            return [
                'source' => 'inquiry',
                'id' => $document->id,
                'name' => $document->name,
                'size' => $document->size,
                'mime_type' => $document->mime_type,
                'updated_at' => $document->updated_at,
                'created_at' => $document->created_at,
                'uploader' => $document->uploader,
                'client_name' => $document->inquiry?->client?->name,
                'record_label' => $document->inquiry?->inquiry_number,
                'task_label' => $document->task?->title,
                'open_url' => route('document-archive.inquiries.open', $document),
                'download_url' => route('document-archive.inquiries.download', $document),
                'version' => null,
            ];
        }

        $document = $this->resolveOrderDocument($id);
        $document->loadMissing(['job.client', 'client', 'task', 'uploader']);
        return [
            'source' => 'order',
            'id' => $document->id,
            'name' => $document->name,
            'size' => $document->size,
            'mime_type' => $document->mime_type,
            'updated_at' => $document->updated_at,
            'created_at' => $document->created_at,
            'uploader' => $document->uploader,
            'client_name' => $document->client?->name ?: $document->job?->client?->name,
            'record_label' => $document->job?->displayOrderNumber() ?: ($document->client ? 'Client only' : 'Not linked'),
            'task_label' => $document->task?->title,
            'open_url' => route('document-archive.orders.open', $document),
            'download_url' => route('document-archive.orders.download', $document),
            'version' => $document->version,
        ];
    }

    private function canEditArchiveDocument(Document|InquiryDocument $document, User $user): bool
    {
        $access = app(AccessControlService::class);
        if ($access->isAdministrator($user) || $access->canEditAll($user, 'document_archive')) return true;
        if (!$access->canEditOwn($user, 'document_archive')) return false;
        return (int) ($document->uploaded_by ?? 0) === (int) $user->id;
    }

    private function resolveArchiveDocument(string $source, int $id, bool $forEdit = false): Document|InquiryDocument
    {
        return $source === 'inquiry'
            ? $this->resolveInquiryDocument($id, $forEdit)
            : $this->resolveOrderDocument($id, $forEdit);
    }

    private function resolveOrderDocument(int $id, bool $forEdit = false): Document
    {
        $document = app(AccessControlService::class)
            ->applyDocumentScope(Document::query(), auth()->user(), 'document_archive')
            ->findOrFail($id);
        if ($forEdit) abort_unless($this->canEditArchiveDocument($document, auth()->user()), 403);
        return $document;
    }

    private function resolveInquiryDocument(int $id, bool $forEdit = false): InquiryDocument
    {
        abort_unless(auth()->user()->canModule('document_archive', 'view'), 403);
        $document = app(AccessControlService::class)
            ->applyInquiryDocumentArchiveScope(InquiryDocument::query()->with('inquiry'), auth()->user())
            ->findOrFail($id);
        if ($forEdit) abort_unless($this->canEditArchiveDocument($document, auth()->user()), 403);
        return $document;
    }
}
