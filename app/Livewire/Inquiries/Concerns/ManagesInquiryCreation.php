<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use App\Models\MasterRecord;
use App\Models\Client;
use App\Models\WorkflowTemplate;
use App\Services\AccessControlService;
use App\Services\MasterDataService;
use App\Services\WorkspaceSettingsService;
use App\Support\AttachmentUpload;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use Throwable;

trait ManagesInquiryCreation
{
    public function openCreate(): void
    {
        abort_unless(auth()->user()->canModule('inquiries', 'create'), 403);
        $this->showCreate = true;
        $this->selectedInquiryId = null;
        $this->userOptions = [];
        $this->selectedTaskId = null;
        $this->createOwnerId ??= (int) auth()->id();
        if ($this->createReceivedDate === '') {
            $this->createReceivedDate = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        }
        $this->initialiseCreateRfqState();
        $priorityOptions = app(MasterDataService::class)->active('priority');
        if (! $priorityOptions->contains(fn ($priority) => (string) $priority->name === (string) $this->createPriority)) {
            $preferred = $priorityOptions->first(fn ($priority) => strcasecmp((string) $priority->name, 'Medium') === 0)
                ?? $priorityOptions->first();
            $this->createPriority = (string) ($preferred?->name ?? '');
        }
        $this->loadCreateOptions();
    }

    public function cancelCreate(): void
    {
        $this->showCreate = false;
        $this->resetCreateForm();
    }

    public function loadCreateSection(string $section): void
    {
        abort_unless($this->showCreate, 422);

        if ($section === 'catalog') {
            abort_unless($this->canUseCreateInquiryProducts(auth()->user()), 403);
            $this->createCatalogReady = true;
            return;
        }

        if ($section === 'workflow') {
            if (! $this->createWorkflowReady) {
                $this->createWorkflowReady = true;
                $this->refreshCreateWorkflowOptions();
            }
            return;
        }

        abort(422, 'Unknown Create Inquiry section.');
    }

    public function openCreateClientModal(): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('clients', 'create'), 403);
        $this->resetCreateClientModal();
        $this->showCreateClientModal = true;
    }

    public function closeCreateClientModal(): void
    {
        $this->showCreateClientModal = false;
        $this->showCreateContactModal = false;
        $this->resetCreateClientModal();
        $this->resetValidation([
            'newClientName', 'newClientContactName', 'newClientEmail',
            'newClientPhone', 'newClientCountry',
        ]);
    }

    public function createClientAndSelect(): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('clients', 'create'), 403);

        $data = $this->validate([
            'newClientName' => ['required', 'string', 'max:255'],
            'newClientContactName' => ['nullable', 'string', 'max:255'],
            'newClientEmail' => ['nullable', 'email', 'max:255'],
            'newClientPhone' => ['nullable', 'string', 'max:60'],
            'newClientCountry' => ['nullable', 'string', 'max:120'],
            'useNewClientContactForInquiry' => ['boolean'],
        ]);

        $client = app(\App\Actions\Inquiries\CreateInquiryClient::class)->handle([
            'name' => $data['newClientName'],
            'country' => $data['newClientCountry'],
            'contact_name' => $data['newClientContactName'],
            'email' => $data['newClientEmail'],
            'phone' => $data['newClientPhone'],
        ], auth()->user());

        $this->clientId = (int) $client->id;
        $this->refreshCreateInquiryClientPrices();
        $this->selectedClientLabel = (string) $client->name;
        $this->clientContact = $this->useNewClientContactForInquiry
            ? (string) ($client->contact_name ?: '')
            : '';
        $this->clientFilterOptions = app(\App\Services\FilterOptionService::class)
            ->options(auth()->user(), 'clients', 'create-inquiry', '', $client->id, 6)
            ->all();
        if ($this->createWorkflowReady) {
            $this->refreshCreateWorkflowOptions();
        } else {
            $this->createWorkflowId = null;
            $this->selectedWorkflowLabel = '';
            $this->resetCreateCollections();
        }

        $this->showCreateClientModal = false;
        $this->resetCreateClientModal();
        $this->resetValidation('clientId');

    }

    public function openCreateContactModal(): void
    {
        abort_unless($this->showCreate && $this->clientId, 422, 'Select a client first.');
        $client = app(\App\Services\ClientService::class)->visibleQuery(auth()->user())->findOrFail((int) $this->clientId);
        abort_unless($this->canEditClientRecord($client), 403);
        $this->newContactName = '';
        $this->newContactEmail = '';
        $this->newContactPhone = '';
        $this->showCreateContactModal = true;
    }

    public function closeCreateContactModal(): void
    {
        $this->showCreateContactModal = false;
        $this->newContactName = '';
        $this->newContactEmail = '';
        $this->newContactPhone = '';
        $this->resetValidation(['newContactName', 'newContactEmail', 'newContactPhone']);
    }

    public function saveCreateContact(): void
    {
        abort_unless($this->showCreate && $this->clientId, 422, 'Select a client first.');
        $data = $this->validate([
            'newContactName' => ['required', 'string', 'max:255'],
            'newContactEmail' => ['nullable', 'email', 'max:255'],
            'newContactPhone' => ['nullable', 'string', 'max:60'],
        ]);

        $client = app(\App\Services\ClientService::class)
            ->visibleQuery(auth()->user())
            ->findOrFail((int) $this->clientId);
        abort_unless($this->canEditClientRecord($client), 403);
        $client = app(\App\Actions\Inquiries\UpdateInquiryClientContact::class)->handle($client, [
            'name' => $data['newContactName'],
            'email' => $data['newContactEmail'],
            'phone' => $data['newContactPhone'],
        ], auth()->user());

        $this->clientContact = (string) $client->contact_name;
        $this->showCreateContactModal = false;
        $this->newContactName = '';
        $this->newContactEmail = '';
        $this->newContactPhone = '';
    }

    private function loadClientContactOptions(?Client $client): void
    {
        if (! $client) {
            $this->clientContactOptions = [];
            $this->clientContact = '';
            return;
        }

        $contacts = collect();
        if (Schema::hasTable('client_contacts')) {
            $contacts = $client->contacts()->get(['name', 'email', 'job_title', 'is_primary', 'sort_order']);
        }

        if ($contacts->isEmpty() && trim((string) $client->contact_name) !== '') {
            $contacts = collect([(object) [
                'name' => $client->contact_name,
                'email' => $client->email,
                'job_title' => $client->contact_job_title,
                'is_primary' => true,
            ]]);
        }

        $this->clientContactOptions = $contacts->map(function ($contact): array {
            $name = trim((string) ($contact->name ?? ''));
            $email = trim((string) ($contact->email ?? ''));
            $jobTitle = trim((string) ($contact->job_title ?? ''));
            $meta = collect([$jobTitle, $email])->filter()->implode(' · ');
            return [
                'value' => $name,
                'label' => $name,
                'meta' => $meta,
                'primary' => (bool) ($contact->is_primary ?? false),
            ];
        })->filter(fn ($contact) => $contact['value'] !== '')->values()->all();

        $values = collect($this->clientContactOptions)->pluck('value');
        if (! $values->contains($this->clientContact)) {
            $this->clientContact = (string) ($values->first() ?? '');
        }
    }

    public function updatedClientId($value): void
    {
        $this->refreshCreateInquiryClientPrices();
        $this->resetValidation(['clientId', 'clientContact']);
        if (!$this->showCreate || !$value) {
            $this->clientContact = '';
            $this->clientContactOptions = [];
            return;
        }
        $client = app(\App\Services\ClientService::class)->referenceQuery(auth()->user(), 'create-inquiry')->where('is_active', true)->find((int) $value);
        $this->loadClientContactOptions($client);
    }

    public function setCreateSelector(string $property, mixed $value): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('inquiries', 'create'), 403);

        $user = auth()->user();
        $raw = trim((string) $value);
        $options = app(\App\Services\FilterOptionService::class);

        if (preg_match('/^createProductRows\.(\d+)\.(category|product)$/', $property, $matches) === 1) {
            $this->authorizeCreateInquiryProducts();
            $index = (int) $matches[1];
            $field = $matches[2];
            abort_unless(array_key_exists($index, $this->createProductRows), 422, 'That product row is no longer available.');
            abort_unless($raw !== '', 422, 'Please choose a valid option.');

            $category = $field === 'product'
                ? trim((string) ($this->createProductRows[$index]['category'] ?? ''))
                : '';
            $type = $field === 'category' ? 'product-categories' : 'products';
            $valid = $options->options(
                $user,
                $type,
                'create-inquiry',
                '',
                $raw,
                20,
                $field === 'product' ? ['category' => $category] : [],
            )->contains(fn ($item) => (string) ($item['id'] ?? '') === $raw);
            abort_unless($valid, 422, 'That option is no longer available.');

            $this->createProductRows[$index][$field] = $raw;
            $this->resetValidation("createProductRows.$index.$field");

            if ($field === 'category') {
                $this->createProductRows[$index]['product'] = '';
                $this->resetValidation("createProductRows.$index.product");
            }
            return;
        }

        if ($property === 'clientId') {
            abort_unless($raw !== '' && ctype_digit($raw), 422, 'Please choose a valid option.');
            $id = (int) $raw;
            $selected = $options->options($user, 'clients', 'create-inquiry', '', $id, 20)
                ->first(fn ($item) => (string) ($item['id'] ?? '') === (string) $id);
            abort_unless($selected, 422, 'That option is no longer available.');

            $this->clientId = $id;
            $this->refreshCreateInquiryClientPrices();
            $this->selectedClientLabel = (string) ($selected['label'] ?? '');
            $this->resetValidation(['clientId', 'clientContact']);
            $client = app(\App\Services\ClientService::class)->referenceQuery($user, 'create-inquiry')
                ->where('is_active', true)
                ->find($id);
            $this->loadClientContactOptions($client);
            if ($this->createWorkflowReady) {
                $this->refreshCreateWorkflowOptions();
            } else {
                $this->createWorkflowId = null;
                $this->selectedWorkflowLabel = '';
                $this->resetCreateCollections();
            }
            return;
        }

        if ($property === 'createOwnerId') {
            abort_unless($raw !== '' && ctype_digit($raw), 422, 'Please choose a valid assignee.');
            $id = (int) $raw;
            $selected = $options->options($user, 'users', 'create-inquiry', '', $id, 20)
                ->first(fn ($item) => (string) ($item['id'] ?? '') === (string) $id);
            abort_unless($selected, 422, 'That assignee is no longer available.');

            $this->createOwnerId = $id;
            $name = (string) ($selected['label'] ?? '');
            $this->selectedOwnerLabel = $id === (int) $user->id ? 'Me · '.$name : $name;
            $this->resetValidation('createOwnerId');
            return;
        }

        if ($property === 'createWorkflowId') {
            abort_unless($raw !== '' && ctype_digit($raw), 422, 'Please choose a valid Workflow.');
            $id = (int) $raw;
            $selected = $options->options($user, 'workflows', 'create-inquiry', '', $id, 20, ['client_id' => $this->clientId])
                ->first(fn ($item) => (string) ($item['id'] ?? '') === (string) $id);
            abort_unless($selected, 422, 'That Workflow is no longer available.');

            $this->createWorkflowId = $id;
            $this->selectedWorkflowLabel = (string) ($selected['label'] ?? '');
            $summary = app(\App\Queries\Inquiries\InquiryWorkflowQuery::class)->summary($id);
            $this->createWorkflowTaskCount = (int) ($summary['tasks'] ?? 0);
            $this->createWorkflowPhaseCount = (int) ($summary['phases'] ?? 0);
            $this->resetValidation('createWorkflowId');
            return;
        }

        abort(422, 'Unsupported Create Inquiry selector.');
    }

    public function saveDraft(): void { $this->persistInquiry(true); }

    public function createInquiry(): void { $this->persistInquiry(false); }

    private function persistInquiry(bool $draft): void
    {
        $user = auth()->user();

        // The workflow selector is below the fold and normally hydrates when it
        // approaches the viewport. Keyboard submission can happen first, so
        // resolve the current client's workflow just-in-time instead of showing
        // a false validation error or forcing the whole form to load up front.
        if (! $this->createWorkflowReady) {
            $this->createWorkflowReady = true;
            $this->refreshCreateWorkflowOptions();
        }
        $canUseInquiryProducts = $this->canUseCreateInquiryProducts($user);
        if (!$canUseInquiryProducts && collect($this->createProductRows)->contains(fn (array $row): bool =>
            (int) ($row['product_id'] ?? 0) > 0
            || trim((string) ($row['category'] ?? '')) !== ''
            || trim((string) ($row['product'] ?? '')) !== ''
        )) {
            abort(403, 'Your role is not allowed to add Products to an Inquiry.');
        }
        if (!$canUseInquiryProducts) $this->createProductRows = [];

        // Products remain optional for Inquiry creation. Rows selected from the
        // canonical Product catalog are normalized here before validation.
        $this->createProductRows = collect($this->createProductRows)
            ->map(fn (array $row): array => [
                'row_key' => trim((string) ($row['row_key'] ?? '')),
                'product_id' => (int) ($row['product_id'] ?? 0),
                'category' => trim((string) ($row['category'] ?? '')),
                'product' => trim((string) ($row['product'] ?? '')),
                'quantity' => $row['quantity'] ?? 1,
                'unit' => trim((string) ($row['unit'] ?? 'units')) ?: 'units',
                'unit_price' => $row['unit_price'] ?? '',
                'notes' => trim((string) ($row['notes'] ?? '')),
            ])
            ->filter(fn (array $row): bool => $row['product_id'] > 0 || $row['category'] !== '' || $row['product'] !== '')
            ->values()
            ->all();

        $data = $this->validate([
            'clientId' => ['required', 'exists:clients,id'],
            'referenceNumber' => ['nullable', 'string', 'max:255'],
            'clientContact' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'requirementNotes' => ['nullable', 'string', 'max:60000'],
            'requestSource' => ['required', Rule::in(['Email', 'Phone', 'Other'])],
            'createPriority' => [
                'required',
                'string',
                'max:100',
                Rule::exists('master_records', 'name')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'priority')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'createReceivedDate' => ['required', 'date_format:Y-m-d'],
            'createOwnerId' => ['required', 'exists:users,id'],
            'createWorkflowId' => ['required', 'exists:workflow_templates,id'],
            'createProductRows' => ['array', 'max:25'],
            'createProductRows.*.product_id' => ['required', 'integer'],
            'createProductRows.*.category' => ['required', 'string', 'max:255'],
            'createProductRows.*.product' => ['required', 'string', 'max:255'],
            'createProductRows.*.quantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'createProductRows.*.unit' => ['required', 'string', 'max:32'],
            'createProductRows.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'createProductRows.*.notes' => ['nullable', 'string', 'max:2000'],
            'createAttachments.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, 20480),
            'createRfqSupplierIds' => ['array', 'max:25'],
            'createRfqSupplierIds.*' => ['integer', 'distinct'],
            'createRfqDueDate' => ['nullable', 'date_format:Y-m-d'],
            'createRfqMessage' => ['nullable', 'string', 'max:2000'],
        ]);

        // Client is shared reference data for Inquiry creation. Fetch the
        // authorized active Client once and reuse it for contact validation.
        $selectedClient = app(\App\Services\ClientService::class)
            ->referenceQuery(auth()->user(), 'create-inquiry')
            ->where('is_active', true)
            ->find((int) $data['clientId']);
        if (! $selectedClient) {
            $this->addError('clientId', 'That client is no longer available.');
            return;
        }

        // Client contact is mandatory and must belong to the selected Client.
        // Multiple contacts are supported; the Inquiry stores the selected name
        // as its historical snapshot so later Client edits do not rewrite history.
        $allowedContacts = collect();
        if (Schema::hasTable('client_contacts')) {
            $allowedContacts = $selectedClient->contacts()->pluck('name')->map(fn ($name) => trim((string) $name))->filter();
        }
        if ($allowedContacts->isEmpty() && trim((string) ($selectedClient->contact_name ?? '')) !== '') {
            $allowedContacts = collect([trim((string) $selectedClient->contact_name)]);
        }
        $requestedContact = trim((string) $data['clientContact']);
        if ($requestedContact === '' || ! $allowedContacts->containsStrict($requestedContact)) {
            $this->addError('clientContact', 'Select a valid contact for this client.');
            return;
        }
        $data['clientContact'] = $requestedContact;

        // Assigned-to uses the same remote option source as the UI. Re-check it
        // on save so a stale/inactive user cannot be submitted by changing the
        // Livewire payload manually.
        $ownerAvailable = app(\App\Services\FilterOptionService::class)
            ->options(auth()->user(), 'users', 'create-inquiry', '', (int) $data['createOwnerId'], 20)
            ->contains(fn ($item) => (int) ($item['id'] ?? 0) === (int) $data['createOwnerId']);
        if (! $ownerAvailable) {
            $this->addError('createOwnerId', 'That assignee is no longer available.');
            return;
        }

        $workflowAvailable = WorkflowTemplate::query()
            ->where('workspace_id', app(\App\Services\WorkflowService::class)->workspaceId())
            ->where('is_active', true)
            ->availableFor('inquiries', (int) $data['clientId'])
            ->whereKey((int) $data['createWorkflowId'])
            ->exists();

        if (!$workflowAvailable) {
            $this->addError('createWorkflowId', 'That Workflow is not available for the selected client.');
            return;
        }

        $catalogInvalid = false;
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $clientId = (int) $data['clientId'];
        $products = MasterRecord::query()
            ->forWorkspace($workspaceId)->ofType('product')->active()
            ->with(['parent:id,name,status,type', 'clientPrices' => fn ($query) => $query->where('client_id', $clientId)])
            ->whereIn('id', collect($data['createProductRows'])->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->all())
            ->get()->keyBy('id');
        foreach ($data['createProductRows'] as $index => $row) {
            $product = $products->get((int) $row['product_id']);

            $valid = $product
                && $product->parent
                && $product->parent->type === 'product_category'
                && $product->parent->status === 'active'
                && (string) $product->name === trim((string) $row['product'])
                && (string) $product->parent->name === trim((string) $row['category']);

            if (!$valid) {
                $catalogInvalid = true;
                $this->addError("createProductRows.$index.product", 'That product is no longer available in the product catalog.');
                continue;
            }

            // Product Master pricing is authoritative for Create Inquiry.
            // Re-resolve it at save time so the stored base price always follows
            // the Product quantity price table, even if browser state is stale.
            $quantity = (int) ($row['quantity'] ?? 0);
            $basePrice = $product->productPriceForQuantity($quantity, (int) $data['clientId']);
            $data['createProductRows'][$index]['unit_price'] = $basePrice !== null
                ? round($basePrice, 2)
                : null;
        }
        if ($catalogInvalid) return;

        $createRfqPlan = $this->validatedCreateProductRfqPlan();
        if ($createRfqPlan === null) return;

        $workflowQuery = app(\App\Queries\Inquiries\InquiryWorkflowQuery::class);
        $canonicalRows = $workflowQuery->rows(
            (int) $data['createWorkflowId'],
            $data['createReceivedDate'],
        );
        if ($canonicalRows === []) {
            $this->addError('createWorkflowId', 'The selected Workflow has no active Task Pack tasks. Add Task Packs in Workflow Setup first.');
            return;
        }

        // Workflow Setup / Task Pack Setup remain the source of truth.
        // The create screen shows only the workflow summary; tasks are rebuilt canonically on save.
        $tasks = $canonicalRows;

        $inquiry = app(\App\Actions\Inquiries\CreateInquiry::class)->handle([
            'client_id' => $data['clientId'],
            'reference_number' => $data['referenceNumber'],
            'client_contact' => $data['clientContact'],
            'received_date' => $data['createReceivedDate'],
            'request_source' => $data['requestSource'],
            'subject' => $data['subject'],
            'requirement_notes' => $data['requirementNotes'],
            'target_price' => null,
            'currency' => 'USD',
            'required_delivery_date' => null,
            'priority' => $data['createPriority'],
            'owner_id' => (int) $data['createOwnerId'],
            'initial_follow_up_date' => null,
            'items' => array_map(fn (array $row): array => [
                'category' => trim((string) $row['category']),
                'name' => trim((string) $row['product']),
                'quantity' => (int) $row['quantity'],
                'unit_price' => filled($row['unit_price'] ?? null) ? round((float) $row['unit_price'], 2) : null,
                'unit' => trim((string) ($row['unit'] ?? 'units')) ?: 'units',
                'notes' => trim((string) ($row['notes'] ?? '')),
            ], $data['createProductRows']),
            'tasks' => $tasks,
            'source_task_pack_id' => null,
            'source_workflow_template_id' => (int) $data['createWorkflowId'],
        ], auth()->user(), $draft);

        foreach ($this->createAttachments as $upload) app(\App\Actions\Inquiries\UploadInquiryDocument::class)->handle($inquiry, $upload, auth()->user());

        $rfqDelivery = ['sent' => 0, 'drafted' => 0, 'added_without_email' => 0, 'failed' => 0];
        if (! $draft && $createRfqPlan !== []) {
            $rfqDelivery = $this->sendCreateProductRfqPlan($inquiry, $user, $createRfqPlan);
        }

        $this->showCreate = false;
        $this->selectedInquiryId = $inquiry->id;
        $this->detailTab = 'overview';
        $this->refreshInquiryListMetrics();
        $this->resetCreateForm();
        if ($draft) {
            session()->flash('success', 'Inquiry draft saved.');
        } else {
            $message = $inquiry->inquiry_number.' created with its taskflow tasks.';
            if ($rfqDelivery['sent'] > 0) {
                $message .= ' '.$rfqDelivery['sent'].' RFQ '.\Illuminate\Support\Str::plural('invitation', $rfqDelivery['sent']).' sent.';
            }
            if (($rfqDelivery['drafted'] ?? 0) > 0) {
                $count = (int) $rfqDelivery['drafted'];
                $message .= ' '.$count.' RFQ '.\Illuminate\Support\Str::plural('invitation', $count).' saved as draft.';
            }
            if (($rfqDelivery['added_without_email'] ?? 0) > 0) {
                $count = (int) $rfqDelivery['added_without_email'];
                $message .= ' '.$count.' '.\Illuminate\Support\Str::plural('supplier', $count).' added to the RFQ without email delivery.';
            }
            if ($rfqDelivery['failed'] > 0) {
                $message .= ' '.$rfqDelivery['failed'].' RFQ '.\Illuminate\Support\Str::plural('email', $rfqDelivery['failed']).' could not be delivered; the Inquiry is still available from the RFQ tab.';
            }
            session()->flash('success', $message);
        }
    }

    private function loadCreateOptions(): void
    {
        $user = auth()->user();
        $options = app(\App\Services\FilterOptionService::class);

        // Keep create rendering bounded: only a handful of initial selector
        // options are hydrated; searching is handled by the same remote
        // endpoint used by Create Order.
        $this->clientFilterOptions = $options->options($user, 'clients', 'create-inquiry', '', $this->clientId, 6)->all();

        $this->ownerFilterOptions = $options->options($user, 'users', 'create-inquiry', '', $this->createOwnerId, 6)->all();
        // Product/category permissions must never block opening Create Inquiry.
        // The create page loads its visible catalogue controls in createPageData();
        // this legacy preload was unused and could turn a hidden optional control
        // into a full-page 403.
        $this->createProductCategoryOptions = [];
        $selectedOwner = collect($this->ownerFilterOptions)
            ->first(fn ($item) => (string) ($item['id'] ?? '') === (string) $this->createOwnerId);
        if ($selectedOwner) {
            $name = (string) ($selectedOwner['label'] ?? '');
            $this->selectedOwnerLabel = (int) $this->createOwnerId === (int) $user->id ? 'Me · '.$name : $name;
        }

    }

    private function refreshCreateWorkflowOptions(): void
    {
        if (!$this->showCreate) return;

        $user = auth()->user();
        $options = app(\App\Services\FilterOptionService::class);
        $constraints = ['client_id' => $this->clientId];

        $available = $options->options($user, 'workflows', 'create-inquiry', '', $this->createWorkflowId, 20, $constraints);
        $selected = $this->createWorkflowId
            ? $available->first(fn ($item) => (int) ($item['id'] ?? 0) === (int) $this->createWorkflowId)
            : null;

        if (!$selected) {
            // Workflow preference must come from setup configuration, not from
            // a hard-coded Workflow name. This keeps client-specific defaults
            // working after a Workflow is renamed (for example NEP).
            $preferredId = WorkflowTemplate::query()
                ->where('workspace_id', app(\App\Services\WorkflowService::class)->workspaceId())
                ->where('is_active', true)
                ->availableFor('inquiries', $this->clientId)
                ->orderByRaw("CASE WHEN client_availability = 'specific' THEN 0 ELSE 1 END")
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->value('id');

            $this->createWorkflowId = $preferredId ? (int) $preferredId : null;
            $selected = $this->createWorkflowId
                ? $options->options($user, 'workflows', 'create-inquiry', '', $this->createWorkflowId, 20, $constraints)
                    ->first(fn ($item) => (int) ($item['id'] ?? 0) === (int) $this->createWorkflowId)
                : null;
        }

        $this->workflowFilterOptions = $options
            ->options($user, 'workflows', 'create-inquiry', '', $this->createWorkflowId, 6, $constraints)
            ->all();

        if ($this->createWorkflowId) {
            $selected = collect($this->workflowFilterOptions)
                ->first(fn ($item) => (int) ($item['id'] ?? 0) === (int) $this->createWorkflowId)
                ?? $selected;
            $this->selectedWorkflowLabel = (string) ($selected['label'] ?? '');
            $summary = app(\App\Queries\Inquiries\InquiryWorkflowQuery::class)->summary($this->createWorkflowId);
            $this->createWorkflowTaskCount = (int) ($summary['tasks'] ?? 0);
            $this->createWorkflowPhaseCount = (int) ($summary['phases'] ?? 0);
        } else {
            $this->selectedWorkflowLabel = '';
            $this->createWorkflowTaskCount = 0;
            $this->createWorkflowPhaseCount = 0;
        }
    }

    private function resetCreateCollections(): void
    {
        $this->createWorkflowTaskCount = 0;
        $this->createWorkflowPhaseCount = 0;
    }

    private function resetCreateForm(): void
    {
        $this->clientId = null;
        $this->clientContact = '';
        $this->clientContactOptions = [];
        $this->selectedClientLabel = '';
        $this->referenceNumber = '';
        $this->subject = '';
        $this->requirementNotes = '';
        $this->requestSource = 'Email';
        $priorityOptions = app(MasterDataService::class)->active('priority');
        $preferredPriority = $priorityOptions->first(fn ($priority) => strcasecmp((string) $priority->name, 'Medium') === 0)
            ?? $priorityOptions->first();
        $this->createPriority = (string) ($preferredPriority?->name ?? '');
        $this->createReceivedDate = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $this->createOwnerId = (int) auth()->id();
        $this->selectedOwnerLabel = 'Me · '.(string) auth()->user()->name;
        $this->ownerFilterOptions = [];
        $this->showCreateClientModal = false;
        $this->showCreateContactModal = false;
        $this->newContactName = '';
        $this->newContactEmail = '';
        $this->newContactPhone = '';
        $this->resetCreateClientModal();
        $this->createAttachments = [];
        $this->resetCreateRfqState();
        $this->createProductRows = [];
        $this->createProductRfqRows = [];
        $this->createProductCategoryOptions = [];
        $this->createProductSearch = '';
        $this->createProductCategoryFilter = '';
        $this->createProductShowAllResults = false;
        $this->createCatalogReady = false;
        $this->createWorkflowReady = false;
        $this->showCreateOrderProductModal = false;
        $this->resetCreateOrderProductModal();
        $this->closeMissingProductSupplierModal();
        $this->createWorkflowId = null;
        $this->selectedWorkflowLabel = '';
        $this->resetCreateCollections();
    }

    private function canEditClientRecord(Client $client): bool
    {
        $access = app(AccessControlService::class);
        if ($access->isAdministrator(auth()->user()) || $access->canEditAll(auth()->user(), 'clients')) {
            return true;
        }

        return $access->canEditOwn(auth()->user(), 'clients')
            && (int) ($client->account_manager_id ?? 0) === (int) auth()->id();
    }

    private function resetCreateClientModal(): void
    {
        $this->newClientName = '';
        $this->newClientContactName = '';
        $this->newClientEmail = '';
        $this->newClientPhone = '';
        $this->newClientCountry = '';
        $this->useNewClientContactForInquiry = true;
    }

    private function nextClientCode(): string
    {
        $next = (int) Client::max('id') + 1;
        do {
            $code = 'CL-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $next++;
        } while (Client::where('code', $code)->exists());

        return $code;
    }
}
