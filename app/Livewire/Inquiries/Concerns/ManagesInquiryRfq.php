<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\MasterRecord;
use App\Queries\Inquiries\InquiryDetailQuery;
use App\Services\Inquiries\InquiryRfqService;
use App\Services\ProductCatalogService;
use Throwable;

trait ManagesInquiryRfq
{
    /** Supplier search inside the Add supplier modal. */
    public string $rfqSupplierSearch = '';

    /** Prototype RFQ management-table controls. */
    public string $rfqTableSearch = '';
    public string $rfqEmailStatusFilter = 'all';
    public array $rfqSelectedSupplierIds = [];
    public array $rfqSelectedProductSupplierKeys = [];
    public int $rfqTablePage = 1;
    public bool $showRfqSupplierPicker = false;
    public ?int $rfqSupplierProductId = null;

    public bool $showRfqSettings = false;
    public string $rfqSpecialNote = '';
    public string $rfqSupplierDetails = '';
    public string $rfqDefaultDueAt = '';
    public int $rfqLinkValidityValue = 30;
    public string $rfqLinkValidityUnit = 'days';
    public bool $rfqAutoReplyEnabled = true;
    public bool $rfqReminderEnabled = true;
    public int $rfqReminderHoursBeforeDue = 24;
    public bool $rfqAllowRevision = true;
    public bool $rfqAwardEmailEnabled = true;
    public bool $rfqNotSelectedEmailEnabled = true;

    public function resetInquiryRfqState(): void
    {
        $this->rfqSupplierSearch = '';
        $this->rfqTableSearch = '';
        $this->rfqEmailStatusFilter = 'all';
        $this->rfqSelectedSupplierIds = [];
        $this->rfqSelectedProductSupplierKeys = [];
        $this->rfqTablePage = 1;
        $this->showRfqSupplierPicker = false;
        $this->rfqSupplierProductId = null;
        $this->showRfqSettings = false;
        $this->rfqSpecialNote = '';
        $this->rfqSupplierDetails = '';
        $this->rfqDefaultDueAt = '';
        $this->rfqLinkValidityValue = 30;
        $this->rfqLinkValidityUnit = 'days';
        $this->rfqAutoReplyEnabled = true;
        $this->rfqReminderEnabled = true;
        $this->rfqReminderHoursBeforeDue = 24;
        $this->rfqAllowRevision = true;
        $this->rfqAwardEmailEnabled = true;
        $this->rfqNotSelectedEmailEnabled = true;
        $this->resetValidation();
    }

    public function updatedRfqTableSearch(): void
    {
        $this->rfqTablePage = 1;
    }

    public function updatedRfqEmailStatusFilter(): void
    {
        $this->rfqTablePage = 1;
    }

    public function setRfqTablePage(int $page): void
    {
        $this->rfqTablePage = max(1, $page);
    }

    /** @param array<int,int|string> $supplierIds */
    public function toggleVisibleRfqSelection(array $supplierIds): void
    {
        $visible = collect($supplierIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($visible->isEmpty()) {
            return;
        }

        $selected = collect($this->rfqSelectedSupplierIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique();

        $allVisibleSelected = $visible->every(fn (int $id): bool => $selected->contains($id));
        $selected = $allVisibleSelected
            ? $selected->reject(fn (int $id): bool => $visible->contains($id))
            : $selected->merge($visible)->unique();

        $this->rfqSelectedSupplierIds = $selected->values()->all();
    }

    /** @param array<int,string> $keys */
    public function toggleRfqProductSelection(array $keys): void
    {
        $keys = collect($keys)->map(fn ($key) => trim((string) $key))->filter()->unique()->values();
        if ($keys->isEmpty()) return;

        $selected = collect($this->rfqSelectedProductSupplierKeys)->map(fn ($key) => trim((string) $key))->filter()->unique();
        $allSelected = $keys->every(fn (string $key): bool => $selected->contains($key));
        $this->rfqSelectedProductSupplierKeys = ($allSelected
            ? $selected->reject(fn (string $key): bool => $keys->contains($key))
            : $selected->merge($keys)->unique()
        )->values()->all();
    }

    public function clearRfqSelection(): void
    {
        $this->rfqSelectedSupplierIds = [];
        $this->rfqSelectedProductSupplierKeys = [];
    }

    public function updatedRfqSupplierProductId(): void
    {
        $this->rfqSupplierSearch = '';
        $this->resetValidation('rfqSupplierSearch');
    }

    public function openRfqSupplierPicker(?int $productId = null): void
    {
        $inquiry = $this->authoriseRfqManagement();
        if ($productId) $this->rfqProductForInquiryOrFail($inquiry, $productId);
        $this->rfqSupplierProductId = $productId ?: null;
        $this->rfqSupplierSearch = '';
        $this->showRfqSupplierPicker = true;
        $this->resetValidation('rfqSupplierSearch');
    }

    public function closeRfqSupplierPicker(): void
    {
        $this->showRfqSupplierPicker = false;
        $this->rfqSupplierSearch = '';
        $this->rfqSupplierProductId = null;
        $this->resetValidation('rfqSupplierSearch');
    }

    /**
     * Add a supplier to the Inquiry RFQ without sending yet. This matches the
     * management-table workflow in the prototype: adding a participant and
     * sending an email are separate actions.
     */
    public function addRfqSupplier(int $supplierId): void
    {
        $inquiry = $this->authoriseRfqManagement();

        try {
            abort_unless($this->rfqSupplierProductId, 422, 'Choose a product before assigning a supplier.');
            $product = $this->rfqProductForInquiryOrFail($inquiry, (int) $this->rfqSupplierProductId);
            app(ProductCatalogService::class)->assignSupplierToProduct($product, $supplierId);

            $invitation = $this->rfqService()->managementInvitations($inquiry)
                ->first(fn ($row) => (int) $row->supplier_id === $supplierId);
            if (! $invitation) {
                $invitation = $this->rfqService()->invite($inquiry, $supplierId, auth()->user(), null, null, false);
            }

            $this->showRfqSupplierPicker = false;
            $this->rfqSupplierSearch = '';
            $this->rfqSupplierProductId = null;
            $this->rfqTablePage = 1;
            $this->resetPage('inquiryActivityPage');
            session()->flash('success', ($invitation->supplier?->name ?: 'Supplier').' assigned to '.$product->name.'.');
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('rfqSupplierSearch', $exception->getMessage() ?: 'The supplier could not be assigned to this product.');
        }
    }

    /**
     * Send, retry or explicitly resend one supplier invitation. The RFQ service
     * resolves whether the supplier is a default row or already has an invitation.
     */
    public function sendRfqSupplierEmail(int $supplierId): void
    {
        $inquiry = $this->authoriseRfqManagement();
        $this->resetValidation('rfqDelivery');

        try {
            $invitation = $this->rfqService()->sendSupplierInvitation($inquiry, $supplierId, auth()->user());
            $this->rfqSelectedSupplierIds = collect($this->rfqSelectedSupplierIds)
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id): bool => $id === $supplierId)
                ->values()
                ->all();
            $this->rfqSelectedProductSupplierKeys = collect($this->rfqSelectedProductSupplierKeys)
                ->reject(fn ($key): bool => (int) ((string) str((string) $key)->after(':')) === $supplierId)
                ->values()
                ->all();
            $this->resetPage('inquiryActivityPage');
            session()->flash('success', 'RFQ invitation sent to '.($invitation->supplier?->name ?: 'supplier').'.');
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('rfqDelivery', $exception->getMessage() ?: 'The RFQ invitation could not be sent.');
        }
    }

    public function sendSelectedRfqEmails(): void
    {
        $inquiry = $this->authoriseRfqManagement();
        $this->resetValidation('rfqDelivery');
        $supplierIds = collect($this->rfqSelectedProductSupplierKeys)
            ->map(fn ($key) => (int) ((string) str((string) $key)->after(':')))
            ->merge(collect($this->rfqSelectedSupplierIds)->map(fn ($id) => (int) $id))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($supplierIds->isEmpty()) {
            return;
        }

        $sent = 0;
        $failed = 0;
        $failedMessages = [];
        $rfq = $this->rfqService();
        $actor = auth()->user();

        foreach ($supplierIds as $supplierId) {
            try {
                $rfq->sendSupplierInvitation($inquiry, $supplierId, $actor);
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $message = trim((string) $exception->getMessage());
                if ($message !== '') {
                    $failedMessages[] = $message;
                }
            }
        }

        $this->rfqSelectedSupplierIds = [];
        $this->rfqSelectedProductSupplierKeys = [];
        $this->resetPage('inquiryActivityPage');

        if ($failed > 0) {
            $message = $failed.' '.str('email')->plural($failed).' failed to send.';
            if ($sent > 0) {
                $message .= ' '.$sent.' '.str('email')->plural($sent).' sent successfully.';
            }
            if ($failedMessages !== []) {
                $message .= ' '.collect($failedMessages)->unique()->first();
            }
            $this->addError('rfqDelivery', $message);
            return;
        }

        session()->flash('success', $sent.' '.str('RFQ email')->plural($sent).' sent successfully.');
    }

    /** @param array<int,int|string> $supplierIds */
    public function sendRfqProductEmails(array $supplierIds): void
    {
        $inquiry = $this->authoriseRfqManagement();
        $this->resetValidation('rfqDelivery');
        $ids = collect($supplierIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) return;

        $sent = 0;
        $failed = 0;
        foreach ($ids as $supplierId) {
            try {
                $this->rfqService()->sendSupplierInvitation($inquiry, $supplierId, auth()->user());
                $sent++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->addError('rfqDelivery', $exception->getMessage() ?: 'One or more RFQ invitations could not be sent.');
            }
        }

        $this->rfqSelectedProductSupplierKeys = collect($this->rfqSelectedProductSupplierKeys)
            ->reject(fn ($key): bool => $ids->contains((int) ((string) str((string) $key)->after(':'))))
            ->values()->all();
        $this->resetPage('inquiryActivityPage');
        if ($failed === 0) session()->flash('success', $sent.' '.str('RFQ invitation')->plural($sent).' sent successfully.');
    }

    /**
     * Backwards-compatible immediate-send action retained for any older RFQ UI
     * entry points. The redesigned RFQ workspace uses addRfqSupplier() instead.
     */
    public function inviteRfqSupplier(int $supplierId): void
    {
        $inquiry = $this->authoriseRfqManagement();

        try {
            $invitation = $this->rfqService()->invite($inquiry, $supplierId, auth()->user());
            $this->rfqSupplierSearch = '';
            $this->resetPage('inquiryActivityPage');
            if ($invitation->email_status === 'Delivered') {
                session()->flash('success', 'RFQ invitation sent to '.$invitation->supplier?->name.'.');
            } elseif ($invitation->email_status === 'Email disabled') {
                session()->flash('success', ($invitation->supplier?->name ?: 'Supplier').' added to the RFQ. Inquiry email service is disabled, so no email was sent.');
            } else {
                session()->flash('success', ($invitation->supplier?->name ?: 'Supplier').' added to the RFQ. No email was sent because the supplier has no configured email address.');
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('rfqSupplierSearch', $exception->getMessage() ?: 'The RFQ invitation could not be sent.');
        }
    }

    public function sendExistingRfqInvitation(int $invitationId): void
    {
        $inquiry = $this->authoriseRfqManagement();

        try {
            $invitation = $this->rfqService()->sendExistingInvitation($inquiry, $invitationId, auth()->user());
            $this->resetPage('inquiryActivityPage');
            session()->flash('success', 'RFQ invitation sent to '.$invitation->supplier?->name.'.');
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('rfqDelivery', $exception->getMessage() ?: 'The RFQ invitation could not be sent.');
        }
    }

    public function awardRfqSupplier(int $invitationId): void
    {
        $inquiry = $this->authoriseRfqManagement();

        try {
            $result = $this->rfqService()->award($inquiry, $invitationId, auth()->user());
            $name = $result['winner']->supplier?->name ?: 'Supplier';
            $failures = (int) ($result['email_failures'] ?? 0);
            $emailDisabled = (bool) ($result['email_service_disabled'] ?? false);
            $awardEmailEnabled = (bool) ($result['award_email_enabled'] ?? true);
            $notSelectedEmailEnabled = (bool) ($result['not_selected_email_enabled'] ?? true);
            $deliveryMessage = $emailDisabled
                ? ' Inquiry email service is disabled, so supplier notification emails were skipped.'
                : ($failures
                    ? ' '.$failures.' notification email(s) could not be delivered.'
                    : (($awardEmailEnabled && $notSelectedEmailEnabled)
                        ? ' Supplier emails were sent.'
                        : ' Supplier notification preferences were applied.'));
            session()->flash('success', $name.' was awarded the quotation.'.$deliveryMessage);
            $this->resetPage('inquiryActivityPage');
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('rfqAward', $exception->getMessage() ?: 'The quotation could not be awarded.');
        }
    }

    public function openRfqSettings(): void
    {
        $inquiry = $this->authoriseRfqManagement();
        $settings = $this->rfqService()->settings($inquiry);
        $hours = max(1, (int) ($settings->link_validity_hours ?: 720));

        $this->rfqSpecialNote = (string) ($settings->special_note ?? '');
        $this->rfqSupplierDetails = (string) ($settings->supplier_details ?? '');
        $this->rfqDefaultDueAt = ($settings->default_due_at ?: $this->rfqService()->defaultDueAt($inquiry))->format('Y-m-d\TH:i');
        $this->rfqLinkValidityUnit = $hours % 24 === 0 ? 'days' : 'hours';
        $this->rfqLinkValidityValue = $this->rfqLinkValidityUnit === 'days' ? max(1, intdiv($hours, 24)) : $hours;
        $this->rfqAutoReplyEnabled = (bool) ($settings->auto_reply_enabled ?? true);
        $this->rfqReminderEnabled = (bool) ($settings->reminder_enabled ?? true);
        $this->rfqReminderHoursBeforeDue = max(1, (int) ($settings->reminder_hours_before_due ?: 24));
        $this->rfqAllowRevision = (bool) ($settings->allow_revision ?? true);
        $this->rfqAwardEmailEnabled = (bool) ($settings->award_email_enabled ?? true);
        $this->rfqNotSelectedEmailEnabled = (bool) ($settings->not_selected_email_enabled ?? true);
        $this->showRfqSettings = true;
        $this->resetValidation();
    }

    public function closeRfqSettings(): void
    {
        $this->showRfqSettings = false;
        $this->resetValidation();
    }

    public function saveRfqSettings(): void
    {
        $inquiry = $this->authoriseRfqManagement();
        $validated = $this->validate([
            'rfqSpecialNote' => ['nullable', 'string', 'max:4000'],
            'rfqSupplierDetails' => ['nullable', 'string', 'max:8000'],
            'rfqDefaultDueAt' => ['required', 'date', 'after:now'],
            'rfqLinkValidityValue' => ['required', 'integer', 'min:1'],
            'rfqLinkValidityUnit' => ['required', 'in:hours,days'],
            'rfqAutoReplyEnabled' => ['boolean'],
            'rfqReminderEnabled' => ['boolean'],
            'rfqReminderHoursBeforeDue' => ['required', 'integer', 'min:1', 'max:336'],
            'rfqAllowRevision' => ['boolean'],
            'rfqAwardEmailEnabled' => ['boolean'],
            'rfqNotSelectedEmailEnabled' => ['boolean'],
        ]);

        $validityHours = (int) $validated['rfqLinkValidityValue'] * ($validated['rfqLinkValidityUnit'] === 'days' ? 24 : 1);
        if ($validityHours > 2160) {
            $this->addError('rfqLinkValidityValue', 'The secure link can be valid for up to 90 days.');
            return;
        }

        $this->rfqService()->saveSettings($inquiry, [
            'special_note' => $validated['rfqSpecialNote'] ?? '',
            'supplier_details' => $validated['rfqSupplierDetails'] ?? '',
            'default_due_at' => $validated['rfqDefaultDueAt'],
            'link_validity_hours' => $validityHours,
            'auto_reply_enabled' => (bool) $validated['rfqAutoReplyEnabled'],
            'reminder_enabled' => (bool) $validated['rfqReminderEnabled'],
            'reminder_hours_before_due' => (int) $validated['rfqReminderHoursBeforeDue'],
            'allow_revision' => (bool) $validated['rfqAllowRevision'],
            'award_email_enabled' => (bool) $validated['rfqAwardEmailEnabled'],
            'not_selected_email_enabled' => (bool) $validated['rfqNotSelectedEmailEnabled'],
        ], auth()->user());

        $this->showRfqSettings = false;
        $this->resetPage('inquiryActivityPage');
        session()->flash('success', 'RFQ supplier settings saved.');
    }

    private function authoriseRfqManagement(): \App\Models\Inquiry
    {
        $inquiry = $this->selectedInquiry();
        abort_unless(
            $this->inquiryDetailQuery()->canEditVisible(auth()->user(), $inquiry) && ! $inquiry->result,
            403
        );

        return $inquiry;
    }

    private function rfqProductForInquiryOrFail(\App\Models\Inquiry $inquiry, int $productId): MasterRecord
    {
        $product = MasterRecord::query()
            ->forWorkspace((int) $inquiry->workspace_id)
            ->ofType('product')
            ->active()
            ->with('parent:id,name')
            ->findOrFail($productId);

        $belongs = $inquiry->items()
            ->where('item_name', $product->name)
            ->when($product->parent?->name, fn ($query, $category) => $query->where(function ($match) use ($category): void {
                $match->whereNull('category')->orWhere('category', '')->orWhere('category', $category);
            }))
            ->exists();
        abort_unless($belongs, 404, 'That product is not part of this Inquiry.');

        return $product;
    }

    private function rfqService(): InquiryRfqService
    {
        return app(InquiryRfqService::class);
    }

    private function inquiryDetailQuery(): InquiryDetailQuery
    {
        return app(InquiryDetailQuery::class);
    }
}
