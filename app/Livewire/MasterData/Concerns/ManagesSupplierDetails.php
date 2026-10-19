<?php

namespace App\Livewire\MasterData\Concerns;

use App\Actions\MasterData\SaveMasterRecordAction;
use App\Models\MasterRecord;
use App\Services\MasterDataService;
use App\Support\SupplierShortCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;

trait ManagesSupplierDetails
{
    #[Url(as: 'supplier', history: true)]
    public ?int $supplierViewId = null;

    #[Url(as: 'edit_supplier', history: true)]
    public ?int $supplierEditId = null;

    public string $supplierEditContactPerson = '';
    public string $supplierEditEmail = '';
    public string $supplierEditPhone = '';
    public string $supplierEditShortCode = '';
    public string $supplierEditStatus = 'active';
    public string $portalUsername = '';
    public string $portalEmail = '';
    public string $portalPassword = '';
    public string $portalPasswordConfirmation = '';
    public bool $portalRequirePasswordChange = true;


    public function mountSupplierDetails(): void
    {
        if ($this->group !== 'supplier' || $this->supplierCreateMode) {
            return;
        }

        $requestedEditId = request()->integer('edit_supplier');
        $requestedViewId = request()->integer('supplier');
        if ($requestedEditId > 0) {
            $this->supplierEditId = $requestedEditId;
        } elseif ($requestedViewId > 0) {
            $this->supplierViewId = $requestedViewId;
        }

        if ($this->supplierEditId) {
            $this->authorizeGroupAction('edit', 'supplier');
            $this->supplierViewId = null;
            $this->recordsReady = false;
            $this->hydrateSupplierEditor($this->supplierEditId);
            return;
        }

        if ($this->supplierViewId) {
            $this->authorizeGroupAction('view', 'supplier');
            $this->supplierRecord($this->supplierViewId);
            $this->recordsReady = false;
        }
    }

    public function openSupplier(int $id): void
    {
        abort_unless($this->group === 'supplier', 404);
        $this->authorizeGroupAction('view', 'supplier');
        $this->supplierRecord($id);

        $this->supplierEditId = null;
        $this->supplierViewId = $id;
        $this->recordsReady = false;
        $this->resetValidation();
    }

    public function editSupplier(int $id): void
    {
        abort_unless($this->group === 'supplier', 404);
        $this->authorizeGroupAction('edit', 'supplier');

        $this->supplierViewId = null;
        $this->supplierEditId = $id;
        $this->recordsReady = false;
        $this->hydrateSupplierEditor($id);
    }

    public function cancelSupplierEdit(): void
    {
        $id = $this->supplierEditId;
        $this->resetValidation();

        $this->redirectRoute('master-data', array_filter([
            'group' => 'supplier',
            'supplier' => $id,
        ]), navigate: true);
    }

    public function saveSupplier(): void
    {
        abort_unless($this->group === 'supplier' && $this->supplierEditId, 404);
        $this->authorizeGroupAction('edit', 'supplier');

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $supplier = $this->supplierRecord($this->supplierEditId);

        $this->commitSupplierProductCodes();
        $data = $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('master_records', 'name')
                    ->ignore($supplier->id)
                    ->where(fn ($query) => $query
                        ->where('workspace_id', $workspaceId)
                        ->where('type', 'supplier')
                        ->whereNull('deleted_at')),
            ],
            'supplierEditContactPerson' => ['nullable', 'string', 'max:255'],
            'supplierEditEmail' => ['nullable', 'email:rfc', 'max:255'],
            'supplierEditPhone' => ['nullable', 'string', 'max:80'],
            'supplierEditShortCode' => ['nullable', 'string', 'max:40'],
            'supplierEditStatus' => ['required', Rule::in(['active', 'inactive'])],
            'supplierProductCodes' => ['array', 'max:250'],
            'supplierProductCodes.*' => ['string', 'max:80'],
        ], [
            'name.required' => 'Supplier name is required.',
            'name.unique' => 'A supplier with this name already exists.',
            'supplierEditEmail.email' => 'Enter a valid email address.',
        ]);

        abort_if($this->supplierProductCodes !== [] && ! auth()->user()?->canModule('catalog_products', 'edit'), 403);
        $codeRows = $this->supplierCreateCodeRows($workspaceId);
        $invalid = $codeRows->firstWhere('valid', false);
        if ($invalid) {
            $this->addError('supplierProductCodes', 'Correct or remove unknown product code: '.$invalid['code']);
            return;
        }

        $metadata = (array) ($supplier->metadata ?? []);
        foreach ([
            'contact_person' => trim((string) $data['supplierEditContactPerson']),
            'email' => trim((string) $data['supplierEditEmail']),
            'phone' => trim((string) $data['supplierEditPhone']),
        ] as $key => $value) {
            if ($value === '') {
                unset($metadata[$key]);
            } else {
                $metadata[$key] = $value;
            }
        }

        $metadata['short_code'] = SupplierShortCode::resolve(
            (string) $data['supplierEditShortCode'],
            (string) $data['name'],
            (int) $supplier->id,
        );
        unset($metadata['short_code_source']);

        $supplier = DB::transaction(function () use ($supplier, $data, $metadata, $workspaceId, $codeRows): MasterRecord {
            $updated = app(SaveMasterRecordAction::class)->execute('supplier', [
                'code' => trim((string) $supplier->code) !== ''
                    ? $supplier->code
                    : app(MasterDataService::class)->nextCode('supplier'),
                'name' => trim((string) $data['name']),
                'description' => $supplier->description,
                'color' => null,
                'parent_id' => null,
                'status' => (string) $data['supplierEditStatus'],
                'sort_order' => (int) $supplier->sort_order,
                'metadata' => $metadata,
            ], (int) $supplier->id);
            $this->linkProductsToSupplier($workspaceId, $updated, $codeRows->where('valid', true)
                ->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->all());
            return $updated;
        });

        app(\App\Services\SupplierBridge\MarkCatalogDirty::class)->supplierIds($workspaceId, [(int) $supplier->id]);
        session()->flash('success', $supplier->name.' updated successfully.');
        $this->redirectRoute('master-data', [
            'group' => 'supplier',
            'supplier' => $supplier->id,
        ], navigate: true);
    }

    /** Manage the linked account without replacing its password on ordinary detail edits. */
    public function provisionSupplierPortal(): void
    {
        abort_unless($this->group === 'supplier' && $this->supplierEditId, 404);
        abort_unless(auth()->user()?->canModule('suppliers', 'manage'), 403);
        $supplier = $this->supplierRecord($this->supplierEditId);
        if ($supplier->status !== 'active') {
            $this->addError('portalUsername', 'Activate the supplier before managing portal access.');
            return;
        }

        $linked = (bool) data_get($supplier->metadata, 'portal_linked_at');
        $values = $this->validate([
            'portalUsername' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]+$/', 'min:3', 'max:60'],
            'portalEmail' => ['required', 'email:rfc', 'max:254'],
            'portalPassword' => [
                $linked ? 'nullable' : 'required', 'string',
                \Illuminate\Validation\Rules\Password::min(8),
            ],
            'portalPasswordConfirmation' => ['required_with:portalPassword', 'same:portalPassword'],
            'portalRequirePasswordChange' => ['boolean'],
        ], [
            'portalPassword.required' => 'Set an initial password to create the portal login.',
            'portalPasswordConfirmation.required_with' => 'Confirm the new password.',
            'portalPasswordConfirmation.same' => 'The passwords do not match.',
        ]);

        $newPassword = $values['portalPassword'] ?? '';
        $emailChanged = strtolower(trim((string) data_get($supplier->metadata, 'portal_email'))) !== strtolower(trim($values['portalEmail']));
        $usernameChanged = strtolower(trim((string) data_get($supplier->metadata, 'portal_username'))) !== strtolower(trim($values['portalUsername']));
        try {
            app(\App\Services\SupplierBridge\SyncSupplier::class)->provision(
                (int) $supplier->id,
                strtolower(trim($values['portalUsername'])),
                strtolower(trim($values['portalEmail'])),
                $newPassword !== '' ? $newPassword : null,
                trim((string) data_get($supplier->metadata, 'contact_person')) ?: $supplier->name,
                $newPassword !== '' && (bool) $values['portalRequirePasswordChange'],
                ! $linked,
            );
            $metadata = (array) ($supplier->metadata ?? []);
            $metadata['portal_username'] = strtolower(trim($values['portalUsername']));
            $metadata['portal_email'] = strtolower(trim($values['portalEmail']));
            $metadata['portal_linked_at'] = now()->toIso8601String();
            $supplier->update(['metadata' => $metadata]);
            if (! $linked || $emailChanged || $usernameChanged || $newPassword !== '') {
                try {
                    app(\App\Services\Reminders\SupplierPortalAccessReminderService::class)->queue(
                        $supplier,
                        $values['portalEmail'],
                        $values['portalUsername'],
                        ! $linked || $emailChanged || $newPassword !== '',
                    );
                } catch (\Throwable $notificationError) {
                    report($notificationError);
                    session()->flash('warning', 'Portal access was saved, but the access email could not be queued.');
                }
            }
            $this->portalPassword = '';
            $this->portalPasswordConfirmation = '';
            session()->flash('success', 'Supplier portal access saved. Existing password retained unless changed.');
            $this->redirectRoute('master-data', ['group' => 'supplier', 'edit_supplier' => $supplier->id], navigate: true);
        } catch (\Throwable $exception) {
            // Discard secrets even when the remote portal is unavailable.
            $this->portalPassword = '';
            $this->portalPasswordConfirmation = '';
            $this->addError('portalAccess', $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : 'Supplier portal is unavailable. Check the API connection and retry.');
        }
    }

    /** The portal emails a one-use reset link; FlowTracker never receives the token. */
    public function sendSupplierPortalPasswordReset(): void
    {
        abort_unless($this->group === 'supplier' && $this->supplierEditId, 404);
        abort_unless(auth()->user()?->canModule('suppliers', 'manage'), 403);
        $supplier = $this->supplierRecord($this->supplierEditId);
        if (! data_get($supplier->metadata, 'portal_linked_at')) {
            $this->addError('portalAccess', 'Create the supplier portal account before requesting a reset.');
            return;
        }
        try {
            app(\App\Services\SupplierBridge\SyncSupplier::class)->sendPasswordReset((int) $supplier->id);
            session()->flash('success', 'Password reset instructions were sent to the linked portal email.');
        } catch (\Throwable $exception) {
            $this->addError('portalAccess', $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : 'Could not send the reset link. Verify portal email delivery and retry.');
        }
    }

    public function syncSupplierPortal(): void
    {
        abort_unless($this->group === 'supplier' && ($this->supplierEditId || $this->supplierViewId), 404);
        $this->authorizeGroupAction('edit', 'supplier');
        $supplier = $this->supplierRecord((int) ($this->supplierEditId ?: $this->supplierViewId));
        try {
            app(\App\Services\SupplierBridge\SendCatalogImmediately::class)->send((int) $supplier->id);
            session()->flash('success', 'Supplier and assigned products synchronized with the portal.');
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('portalSync', $exception instanceof \RuntimeException
                ? 'Supplier sync failed: '.$exception->getMessage()
                : 'Supplier sync failed. Check the FlowTracker log and Supplier Portal API.');
        }
    }

    protected function supplierRecord(int $id): MasterRecord
    {
        return MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('supplier')
            ->findOrFail($id);
    }

    protected function hydrateSupplierEditor(int $id): void
    {
        $supplier = $this->supplierRecord($id);

        $this->name = (string) $supplier->name;
        $this->supplierEditContactPerson = trim((string) data_get($supplier->metadata, 'contact_person'));
        $this->supplierEditEmail = trim((string) data_get($supplier->metadata, 'email'));
        $this->supplierEditPhone = trim((string) data_get($supplier->metadata, 'phone'));
        $this->supplierEditShortCode = $supplier->supplierShortCode();
        $this->supplierEditStatus = $supplier->status === 'inactive' ? 'inactive' : 'active';
        $this->supplierProductCodes = [];
        $this->supplierCodeDraft = '';
        $this->portalUsername = (string) data_get($supplier->metadata, 'portal_username', '');
        $this->portalEmail = (string) data_get($supplier->metadata, 'portal_email', '');
        $this->portalPassword = '';
        $this->portalPasswordConfirmation = '';
        $this->portalRequirePasswordChange = true;
        $this->resetValidation();
    }

    /**
     * Return products currently associated with one supplier using both the
     * normalized pivot and the legacy metadata formats still supported by FlowTrack.
     *
     * @return Collection<int,MasterRecord>
     */
    protected function supplierDetailProducts(int $workspaceId, MasterRecord $supplier): Collection
    {
        $supplierId = (int) $supplier->id;
        $reverseIds = collect($this->normaliseSupplierProductIds(
            data_get($supplier->metadata, 'product_ids', data_get($supplier->metadata, 'assigned_product_ids', []))
        ));
        $rawCodes = data_get($supplier->metadata, 'product_codes', data_get($supplier->metadata, 'assigned_product_codes', []));
        $reverseCodes = collect(is_array($rawCodes)
            ? $rawCodes
            : (preg_split('/[\s,;|]+/', trim((string) $rawCodes), -1, PREG_SPLIT_NO_EMPTY) ?: []))
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        $query = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->with('parent:id,name')
            ->where(function ($match) use ($supplierId, $workspaceId, $reverseIds, $reverseCodes): void {
                $match->where('metadata->supplier_id', $supplierId)
                    ->orWhere('metadata->default_supplier_id', $supplierId)
                    ->orWhereJsonContains('metadata->supplier_ids', $supplierId);

                if (Schema::hasTable('product_supplier_links')) {
                    $match->orWhereExists(function ($link) use ($supplierId, $workspaceId): void {
                        $link->selectRaw('1')
                            ->from('product_supplier_links')
                            ->whereColumn('product_supplier_links.product_id', 'master_records.id')
                            ->where('product_supplier_links.workspace_id', $workspaceId)
                            ->where('product_supplier_links.supplier_id', $supplierId);
                    });
                }

                if ($reverseIds->isNotEmpty()) {
                    $match->orWhereIn('id', $reverseIds->all());
                }

                if ($reverseCodes->isNotEmpty()) {
                    $displayIds = $reverseCodes
                        ->map(function (string $code): ?int {
                            return preg_match('/^PRD[-\s]*0*(\d+)$/i', $code, $matches)
                                ? (int) $matches[1]
                                : null;
                        })
                        ->filter()
                        ->values();

                    $match->orWhereIn('code', $reverseCodes->all());
                    if ($displayIds->isNotEmpty()) {
                        $match->orWhereIn('id', $displayIds->all());
                    }
                }
            });

        return $query
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'parent_id', 'metadata', 'status', 'updated_at'])
            ->unique('id')
            ->values();
    }
}
