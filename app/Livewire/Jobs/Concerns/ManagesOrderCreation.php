<?php

namespace App\Livewire\Jobs\Concerns;

use App\Actions\Clients\SaveClientDeliveryContact;
use App\Actions\Clients\SaveClientOrderContact;
use App\Actions\Orders\CreateOrder;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientDeliveryContact;
use App\Models\MasterRecord;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Services\ClientService;
use App\Services\MasterDataService;
use App\Services\OrderWorkflowSetupService;
use App\Support\AttachmentUpload;
use App\Support\CreateOrderShippingMethodPresenter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Renderless;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderCreation
{
    public function updatedWorkflowId(): void
    {
        if ($this->showCreate) $this->setDefaultStartPhase();
    }

    public function updatedIsRepeatedOrder(bool $value): void
    {
        if ($value) {
            $this->resetValidation('repeatedOrderNumber');
            return;
        }

        $this->repeatedOrderNumber = '';
        $this->resetValidation('repeatedOrderNumber');
    }

    #[Renderless]
    public function selectShippingContactType(string $type): array
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        abort_unless(in_array($type, ['end_customer', 'middle_client', 'other_contact'], true), 422, 'Choose a valid delivery contact type.');

        $client = $this->createShippingContactClient();
        abort_unless($client, 422, 'Select a client first.');

        if ($type !== $this->shippingContactType) {
            // Preserve the current tab as an in-progress draft before changing
            // the source. Switching itself is renderless so the very large
            // Create Order page is not rebuilt just to change this small panel.
            $this->rememberShippingContactDraft($this->shippingContactType);
            $this->shippingContactType = $type;

            if (!$this->restoreShippingContactDraft($type)) {
                $this->shippingSaveContact = true;

                if ($type === 'middle_client') {
                    $contact = $client->contacts
                        ->firstWhere('is_primary', true)
                        ?: $client->contacts->first();

                    if ($contact) {
                        $this->useMiddleClientContact($contact);
                    } else {
                        $this->clearShippingContactDraftFields();
                    }
                } else {
                    // End customer and Other contact are intentionally user-entered.
                    // Their separate drafts remain available when the user switches
                    // away and returns to either tab.
                    $this->clearShippingContactDraftFields();
                }
            }
        }

        $this->resetValidation([
            'shippingContactType',
            'shippingContactId',
            'shippingContactSelection',
            'shippingContactName',
            'shippingPhoneCountryCode',
            'shippingPhone',
            'shippingSaveContact',
        ]);

        return $this->shippingContactUiPayload($client);
    }

    private function rememberShippingContactDraft(?string $type = null): void
    {
        $type = $type ?: $this->shippingContactType;
        if (!in_array($type, ['end_customer', 'middle_client', 'other_contact'], true)) {
            return;
        }

        $this->shippingContactDrafts[$type] = [
            'contact_id' => $this->shippingContactId,
            'selection' => trim((string) $this->shippingContactSelection),
            'name' => trim((string) $this->shippingContactName),
            'country_code' => trim((string) $this->shippingPhoneCountryCode) ?: self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE,
            'phone' => trim((string) $this->shippingPhone),
            'save_contact' => (bool) $this->shippingSaveContact,
        ];
    }

    private function restoreShippingContactDraft(string $type): bool
    {
        $draft = $this->shippingContactDrafts[$type] ?? null;
        if (!is_array($draft)) {
            return false;
        }

        $this->shippingContactId = isset($draft['contact_id']) && $draft['contact_id'] !== null
            ? (int) $draft['contact_id']
            : null;
        $this->shippingContactSelection = trim((string) ($draft['selection'] ?? ''));
        $this->shippingContactName = trim((string) ($draft['name'] ?? ''));
        $this->shippingPhoneCountryCode = trim((string) ($draft['country_code'] ?? '')) ?: self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
        $this->shippingPhone = trim((string) ($draft['phone'] ?? ''));
        $this->shippingSaveContact = (bool) ($draft['save_contact'] ?? true);

        return true;
    }

    private function clearShippingContactDraftFields(): void
    {
        $this->shippingContactId = null;
        $this->shippingContactSelection = '';
        $this->shippingContactName = '';
        $this->shippingPhoneCountryCode = self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
        $this->shippingPhone = '';
    }

    public function selectShippingContact(mixed $contactId): void
    {
        $this->selectShippingContactOption('middle_client', $contactId);
    }

    #[Renderless]
    public function selectShippingContactOption(string $type, mixed $contactId): array
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        abort_unless(in_array($type, ['end_customer', 'middle_client', 'other_contact'], true), 422, 'Choose a valid delivery contact type.');
        abort_unless($type === $this->shippingContactType, 409, 'The delivery contact source changed. Choose the contact again.');
        abort_unless($this->clientId, 422, 'Select a client first.');

        $raw = trim((string) $contactId);
        abort_unless($raw !== '' && ctype_digit($raw), 422, 'Choose a valid saved contact.');

        $client = $this->createShippingContactClient();
        abort_unless($client, 422, 'That client is no longer available.');

        if ($type === 'middle_client') {
            $contact = $client->contacts->firstWhere('id', (int) $raw);
            abort_unless($contact, 422, 'That contact is no longer available for this client.');
            $this->useMiddleClientContact($contact);
        } else {
            $contact = ClientDeliveryContact::query()
                ->where('client_id', $client->id)
                ->where('contact_type', $type)
                ->find((int) $raw);
            abort_unless($contact, 422, 'That saved delivery contact is no longer available.');

            $this->shippingContactId = null;
            $this->shippingContactSelection = (string) $contact->id;
            $this->shippingContactName = trim((string) $contact->name);
            $this->shippingPhoneCountryCode = trim((string) ($contact->phone_country_code ?? '')) ?: self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
            $this->shippingPhone = trim((string) $contact->phone);
            $this->shippingSaveContact = true;
        }

        $this->resetValidation([
            'shippingContactId',
            'shippingContactName',
            'shippingPhoneCountryCode',
            'shippingPhone',
        ]);

        return $this->shippingContactUiPayload($client);
    }

    #[Renderless]
    public function useNewShippingContactPerson(string $type, mixed $name): array
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        abort_unless(in_array($type, ['end_customer', 'middle_client', 'other_contact'], true), 422, 'Choose a valid delivery contact type.');
        abort_unless($type === $this->shippingContactType, 409, 'The delivery contact source changed. Enter the contact again.');

        $name = trim((string) $name);
        abort_unless($name !== '' && mb_strlen($name) <= 255, 422, 'Enter a valid contact person.');

        $wasSavedSelection = $this->shippingContactSelection !== ''
            && !str_starts_with($this->shippingContactSelection, 'custom:');

        $this->shippingContactId = null;
        $this->shippingContactSelection = 'custom:'.$name;
        $this->shippingContactName = $name;
        $this->shippingSaveContact = true;

        if ($wasSavedSelection) {
            $this->shippingPhoneCountryCode = self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
            $this->shippingPhone = '';
        }

        $this->resetValidation(['shippingContactId', 'shippingContactName']);

        $client = $this->createShippingContactClient();
        abort_unless($client, 422, 'That client is no longer available.');

        return $this->shippingContactUiPayload($client);
    }

    #[Renderless]
    public function setCreateShippingPhoneCountryCode(string $property, mixed $value): array
    {
        abort_unless($property === 'shippingPhoneCountryCode', 422, 'Invalid country code target.');
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);

        $value = trim((string) $value);
        abort_unless($value !== '', 422, 'Choose a country code.');

        $exists = app(MasterDataService::class)
            ->active('phone_country_code')
            ->contains(fn ($record): bool => trim((string) $record->name) === $value);
        abort_unless($exists, 422, 'Choose a valid country code.');

        $this->shippingPhoneCountryCode = $value;
        $this->resetValidation('shippingPhoneCountryCode');
        $this->dispatch('flowtrack-search-select-sync', property: 'shippingPhoneCountryCode', value: $value, label: $value);

        return ['ok' => true, 'value' => $value, 'label' => $value];
    }

    public function updatedShippingContactName(mixed $value): void
    {
        if (!$this->showCreate) return;

        $name = trim((string) $value);
        if ($name === '') {
            $this->shippingContactId = null;
            $this->shippingContactSelection = '';
            return;
        }

        if ($this->shippingContactSelection !== '' && !str_starts_with($this->shippingContactSelection, 'custom:')) {
            $selectedName = $this->selectedShippingContactName();
            if ($selectedName !== null && mb_strtolower($selectedName) === mb_strtolower($name)) return;

            // Moving away from a saved option must never carry that person's
            // phone number into a newly typed contact by accident.
            $this->shippingPhoneCountryCode = self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
            $this->shippingPhone = '';
        }

        $this->shippingContactId = null;
        $this->shippingContactSelection = 'custom:'.$name;
        $this->shippingSaveContact = true;
        $this->resetValidation('shippingContactId');
    }

    /**
     * Small canonical payload used by the renderless Create Order contact panel.
     * Returning this payload lets Alpine update only the contact controls instead
     * of forcing Livewire to rebuild the full Create Order page and all selectors.
     *
     * @return array{type:string,contactId:?int,selection:string,name:string,countryCode:string,phone:string,saveContact:bool,items:array<int,array{id:string,label:string,meta:string}>}
     */
    private function shippingContactUiPayload(Client $client): array
    {
        if ($this->shippingContactType === 'middle_client') {
            $items = $client->contacts
                ->map(function (ClientContact $contact): array {
                    $meta = collect([$contact->job_title, $contact->phone])->filter()->implode(' · ');

                    return [
                        'id' => (string) $contact->id,
                        'label' => (string) $contact->name,
                        'meta' => $meta,
                    ];
                })
                ->values()
                ->all();
        } else {
            $items = ClientDeliveryContact::query()
                ->where('client_id', $client->id)
                ->where('contact_type', $this->shippingContactType)
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->get(['id', 'name', 'phone_country_code', 'phone'])
                ->map(function (ClientDeliveryContact $contact): array {
                    $phone = trim(collect([$contact->phone_country_code, $contact->phone])->filter()->implode(' '));

                    return [
                        'id' => (string) $contact->id,
                        'label' => (string) $contact->name,
                        'meta' => $phone,
                    ];
                })
                ->values()
                ->all();
        }

        return [
            'type' => (string) $this->shippingContactType,
            'contactId' => $this->shippingContactId,
            'selection' => (string) $this->shippingContactSelection,
            'name' => (string) $this->shippingContactName,
            'countryCode' => (string) $this->shippingPhoneCountryCode,
            'phone' => (string) $this->shippingPhone,
            'saveContact' => (bool) $this->shippingSaveContact,
            'items' => $items,
        ];
    }

    private function selectedShippingContactName(): ?string
    {
        $selection = trim((string) $this->shippingContactSelection);
        if ($selection === '' || !ctype_digit($selection) || !$this->clientId) return null;

        if ($this->shippingContactType === 'middle_client') {
            return ClientContact::query()
                ->where('client_id', $this->clientId)
                ->whereKey((int) $selection)
                ->value('name');
        }

        if (in_array($this->shippingContactType, ['end_customer', 'other_contact'], true)) {
            return ClientDeliveryContact::query()
                ->where('client_id', $this->clientId)
                ->where('contact_type', $this->shippingContactType)
                ->whereKey((int) $selection)
                ->value('name');
        }

        return null;
    }

    public function useSavedDeliveryContactByName(mixed $name): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);
        abort_unless($this->clientId, 422, 'Select a client first.');

        if (!in_array($this->shippingContactType, ['end_customer', 'other_contact'], true)) {
            return;
        }

        $name = trim((string) $name);
        if ($name === '') return;

        $clientId = app(ClientService::class)
            ->referenceQuery(auth()->user(), 'create-job')
            ->where('is_active', true)
            ->whereKey($this->clientId)
            ->value('id');
        abort_unless($clientId, 403);

        $contact = ClientDeliveryContact::query()
            ->where('client_id', $clientId)
            ->where('contact_type', $this->shippingContactType)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->first();

        if (!$contact) return;

        $this->shippingContactId = null;
        $this->shippingContactSelection = (string) $contact->id;
        $this->shippingContactName = trim((string) $contact->name);
        $this->shippingPhoneCountryCode = trim((string) ($contact->phone_country_code ?? '')) ?: self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
        $this->shippingPhone = trim((string) $contact->phone);
        $this->shippingSaveContact = true;
        $this->resetValidation([
            'shippingContactName',
            'shippingPhoneCountryCode',
            'shippingPhone',
        ]);
    }

    private function createShippingContactClient(): ?Client
    {
        if (!$this->clientId) return null;

        return app(ClientService::class)
            ->referenceQuery(auth()->user(), 'create-job')
            ->where('is_active', true)
            ->with(['contacts' => fn ($query) => $query
                ->select(['id', 'client_id', 'name', 'job_title', 'phone', 'is_primary', 'sort_order'])
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->find($this->clientId, ['id', 'name', 'contact_name', 'contact_job_title', 'phone']);
    }

    private function initializeCreateShippingContact(?int $clientId): void
    {
        $this->shippingContactType = 'end_customer';
        $this->shippingContactDrafts = [];
        $this->shippingContactId = null;
        $this->shippingContactSelection = '';
        $this->shippingContactName = '';
        $this->shippingSaveContact = true;
        $this->shippingPhoneCountryCode = self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
        $this->shippingPhone = '';

        if (!$clientId) return;

        $client = $this->createShippingContactClient();
        if (!$client) return;

        // The selected Order client is the Middle client. Prefer its primary
        // structured contact when available, while End customer remains a
        // separate user-entered source.
        $middleContact = $client->contacts->firstWhere('is_primary', true)
            ?: $client->contacts->first(fn (ClientContact $contact): bool => filled($contact->phone))
            ?: $client->contacts->first();

        if ($middleContact) {
            $this->shippingContactType = 'middle_client';
            $this->useMiddleClientContact($middleContact);
        }
    }

    private function useMiddleClientContact(ClientContact $contact): void
    {
        $this->shippingContactId = (int) $contact->id;
        $this->shippingContactSelection = (string) $contact->id;
        $this->shippingContactName = trim((string) $contact->name);
        $this->shippingSaveContact = true;
        $this->applyStoredShippingPhone((string) ($contact->phone ?? ''));
    }

    private function applyStoredShippingPhone(string $storedPhone): void
    {
        [$countryCode, $phone] = $this->splitStoredShippingPhone($storedPhone);
        $this->shippingPhoneCountryCode = $countryCode;
        $this->shippingPhone = $phone;
    }

    /** @return array{0:string,1:string} */
    private function splitStoredShippingPhone(string $storedPhone): array
    {
        $value = trim($storedPhone);
        if ($value === '') return [self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE, ''];

        // MasterDataService::active() is cached, so switching back to the
        // middle-client tab does not repeatedly hit master_records.
        $codes = app(MasterDataService::class)
            ->active('phone_country_code')
            ->pluck('name')
            ->map(fn ($code) => trim((string) $code))
            ->filter(fn (string $code): bool => $code !== '')
            ->sortByDesc(fn (string $code): int => strlen($code))
            ->values();

        foreach ($codes as $code) {
            if (!str_starts_with($value, $code)) continue;

            $phone = ltrim(trim(substr($value, strlen($code))), " \t-");
            return [$code, $phone];
        }

        return [self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE, $value];
    }

    private function normalizeShippingContactSelectionBeforeSave(): void
    {
        $name = trim((string) $this->shippingContactName);
        if ($name === '') return;

        $selection = trim((string) $this->shippingContactSelection);
        if ($selection !== '' && ctype_digit($selection)) {
            $selectedName = $this->selectedShippingContactName();
            if ($selectedName !== null && mb_strtolower($selectedName) === mb_strtolower($name)) return;
        } elseif (str_starts_with($selection, 'custom:')) {
            return;
        }

        $this->shippingContactId = null;
        $this->shippingContactSelection = 'custom:'.$name;
        $this->shippingSaveContact = true;
    }

    private function persistShippingContactSelection(): void
    {
        if (!$this->clientId) return;

        // The active tab has not necessarily been switched away from, so its
        // latest values may not yet exist in shippingContactDrafts. Capture it
        // first, then persist every complete contact draft the user supplied.
        // Empty or partially entered tabs are ignored.
        $this->rememberShippingContactDraft($this->shippingContactType);

        $client = app(ClientService::class)
            ->referenceQuery(auth()->user(), 'create-job')
            ->where('is_active', true)
            ->find($this->clientId, ['id']);
        if (!$client) return;

        foreach (['end_customer', 'middle_client', 'other_contact'] as $type) {
            $draft = $this->shippingContactDrafts[$type] ?? null;
            if (!is_array($draft)) continue;

            $name = trim((string) ($draft['name'] ?? ''));
            $phone = trim((string) ($draft['phone'] ?? ''));

            // A user may briefly visit a tab without completing it. Do not
            // create incomplete reusable contacts just because that tab has a
            // remembered draft.
            if ($name === '' || $phone === '') continue;

            $countryCode = trim((string) ($draft['country_code'] ?? ''))
                ?: self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
            $selection = trim((string) ($draft['selection'] ?? ''));
            $contactId = isset($draft['contact_id']) && $draft['contact_id'] !== null
                ? (int) $draft['contact_id']
                : null;
            $isNewContact = $selection === '' || str_starts_with($selection, 'custom:');
            $shouldSave = (bool) ($draft['save_contact'] ?? true) || $isNewContact;

            // Existing contacts are already reusable. Respect the save/update
            // checkbox for them, while newly typed contacts are always saved as
            // requested by the Create Order contact workflow.
            if (!$shouldSave) continue;

            if ($type === 'middle_client') {
                $existingId = $contactId;
                if (!$existingId && $selection !== '' && ctype_digit($selection)) {
                    $existingId = (int) $selection;
                }

                if ($existingId) {
                    $contact = ClientContact::query()
                        ->where('client_id', $client->id)
                        ->find($existingId);
                    if (!$contact) continue;

                    $fullPhone = trim(collect([$countryCode, $phone])
                        ->filter(fn ($part) => filled($part))
                        ->implode(' '));

                    if ($fullPhone !== '' && trim((string) $contact->phone) !== $fullPhone) {
                        $contact->update(['phone' => $fullPhone]);
                    }

                    continue;
                }

                app(SaveClientOrderContact::class)->execute(
                    $client,
                    $name,
                    $countryCode,
                    $phone,
                );
                continue;
            }

            app(SaveClientDeliveryContact::class)->execute(
                auth()->user(),
                $client,
                $type,
                $name,
                $countryCode,
                $phone,
            );
        }
    }

    public function setCreateSelector(string $property, mixed $value): void
    {
        abort_unless($this->showCreate && auth()->user()->canAccess('jobs.create'), 403);

        $user = auth()->user();
        $raw = trim((string) $value);
        $options = app(\App\Services\FilterOptionService::class);

        // Create Order supports multiple Inquiry links. Keep the control as a
        // reusable remote single-search picker, then append each validated choice
        // to ordered Livewire state. This avoids loading the Inquiry catalogue on
        // page render and keeps the first selected Inquiry as the legacy primary.
        if ($property === 'createInquiryId') {
            $access = app(\App\Services\AccessControlService::class);
            abort_unless(
                $access->can($user, 'jobs', 'link') && $access->can($user, 'inquiries', 'view'),
                403
            );

            if ($raw === '') {
                $this->createInquiryId = null;
                $this->createInquiryLabel = '';
                $this->createInquiryMeta = '';
                $this->resetValidation('createInquiryId');
                return;
            }

            abort_unless(ctype_digit($raw), 422, 'Please choose a valid Inquiry.');
            $id = (int) $raw;
            $selected = $options->selectedOptions(
                $user,
                'inquiries',
                'create-job',
                [(string) $id],
                ['client_id' => $this->clientId],
            )->first(fn ($item) => (string) ($item['id'] ?? '') === (string) $id);
            abort_unless($selected, 422, 'That Inquiry is no longer available to link.');

            $ids = collect($this->createInquiryIds)
                ->map(fn ($value) => (int) $value)
                ->filter(fn (int $value) => $value > 0)
                ->unique()
                ->values();
            $replaceId = (int) ($this->createInquiryReplaceId ?? 0);

            // Bound the selection to keep the create payload/UI predictable while
            // still allowing substantially more than normal business use requires.
            abort_if($replaceId <= 0 && !$ids->contains($id) && $ids->count() >= 100, 422, 'You can link up to 100 Inquiries to one Order.');

            if ($replaceId > 0 && $ids->contains($replaceId)) {
                abort_if($id !== $replaceId && $ids->contains($id), 422, 'That Inquiry is already selected for this Order.');
                $ids = $ids->map(fn (int $value) => $value === $replaceId ? $id : $value)->unique()->values();
                if ($replaceId !== $id) {
                    unset($this->createInquirySelections[$replaceId], $this->createInquirySelections[(string) $replaceId]);
                }
            } elseif (!$ids->contains($id)) {
                $ids->push($id);
            }

            $this->createInquiryIds = $ids->all();
            $this->createInquirySelections[$id] = [
                'id' => $id,
                'label' => (string) ($selected['label'] ?? ''),
                'meta' => (string) ($selected['meta'] ?? ''),
            ];

            // Reset/re-key the optimistic search-select after an add. The chosen
            // Inquiry remains in the summary list, while the picker is immediately
            // ready to add another one without carrying a stale Alpine value.
            $this->createInquiryId = null;
            $this->createInquiryLabel = '';
            $this->createInquiryMeta = '';
            $this->createInquiryReplaceId = null;
            $this->createInquirySelectorVersion++;
            $this->resetValidation('createInquiryId');
            $this->resetValidation('createInquiryIds');
            return;
        }

        // Create Order selects only complete Order workflows from the shared
        // Workflow Setup. Inquiry workflows never appear in this picker.
        if ($property === 'workflowId') {
            abort_unless($raw !== '' && ctype_digit($raw), 422, 'Please choose a valid Order workflow.');
            $id = (int) $raw;
            abort_unless(
                $this->createOrderWorkflowAvailableForClient($id, $this->clientId),
                422,
                'That Order workflow is no longer available.'
            );

            $this->workflowId = $id;
            $this->setDefaultStartPhase();
            $this->resetValidation('workflowId');
            $this->resetValidation('workflowPhaseId');
            return;
        }

        if (in_array($property, ['clientId', 'ownerId'], true)) {
            abort_unless($raw !== '' && ctype_digit($raw), 422, 'Please choose a valid option.');
            $id = (int) $raw;
            $type = $property === 'clientId' ? 'clients' : 'users';

            $valid = $options->options($user, $type, 'create-job', '', $id, 20)
                ->contains(fn ($item) => (string) ($item['id'] ?? '') === (string) $id);
            abort_unless($valid, 422, 'That option is no longer available.');

            $this->{$property} = $id;
            $this->resetValidation($property);

            if ($property === 'clientId') {
                // Re-resolve the preferred client-available Order workflow after
                // a Client change so Create Order never retains a stale id.
                $this->applyClientWorkflowDefault($id);
                $this->refreshCreateOrderClientPrices();
                $this->resetCreateShippingAddress();
                $this->initializeCreateShippingContact($id);
                $this->initializeCreateShipments();
            }

            return;
        }

        if (preg_match('/^jobItems\.(\d+)\.(category|product)$/', $property, $matches) !== 1) {
            abort(422, 'Unsupported Create Order selector.');
        }

        $this->authorizeCreateOrderProducts();
        $index = (int) $matches[1];
        $field = $matches[2];
        abort_unless(array_key_exists($index, $this->jobItems), 422, 'That product row is no longer available.');
        abort_unless($raw !== '', 422, 'Please choose a valid option.');

        $category = $field === 'product' ? trim((string) ($this->jobItems[$index]['category'] ?? '')) : '';
        $type = $field === 'category' ? 'product-categories' : 'products';
        $valid = $options->options(
            $user,
            $type,
            'create-job',
            '',
            $raw,
            20,
            $field === 'product' ? ['category' => $category] : [],
        )->contains(fn ($item) => (string) ($item['id'] ?? '') === $raw);
        abort_unless($valid, 422, 'That option is no longer available.');

        $this->jobItems[$index][$field] = $raw;
        $this->resetValidation("jobItems.$index.$field");

        if ($field === 'category') {
            // A Product is scoped to its category; changing the category must
            // invalidate the previous Product before the next render.
            $this->jobItems[$index]['product'] = '';
            $this->resetValidation("jobItems.$index.product");
        }
    }

    public function openCreateInquiryPicker(?int $replaceInquiryId = null): void
    {
        abort_unless($this->showCreate && auth()->user()->canAccess('jobs.create'), 403);

        $access = app(\App\Services\AccessControlService::class);
        abort_unless(
            $access->can(auth()->user(), 'jobs', 'link') && $access->can(auth()->user(), 'inquiries', 'view'),
            403
        );

        $selectedIds = collect($this->createInquiryIds)->map(fn ($id) => (int) $id)->filter()->unique();
        $this->createInquiryReplaceId = $replaceInquiryId && $selectedIds->contains($replaceInquiryId)
            ? (int) $replaceInquiryId
            : null;
        $this->createInquiryId = null;
        $this->createInquiryLabel = '';
        $this->createInquiryMeta = '';
        $this->createInquirySelectorVersion++;

        $this->dispatch('flowtrack-create-inquiry-picker-open');
    }

    public function cancelCreateInquiryPicker(): void
    {
        abort_unless($this->showCreate && auth()->user()->canAccess('jobs.create'), 403);
        $this->createInquiryReplaceId = null;
        $this->createInquiryId = null;
        $this->createInquiryLabel = '';
        $this->createInquiryMeta = '';
        $this->createInquirySelectorVersion++;
    }

    public function removeCreateInquiry(int $inquiryId): void
    {
        abort_unless($this->showCreate && auth()->user()->canAccess('jobs.create'), 403);

        $access = app(\App\Services\AccessControlService::class);
        abort_unless(
            $access->can(auth()->user(), 'jobs', 'link') && $access->can(auth()->user(), 'inquiries', 'view'),
            403
        );

        $this->createInquiryIds = collect($this->createInquiryIds)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0 && $value !== $inquiryId)
            ->unique()
            ->values()
            ->all();
        unset($this->createInquirySelections[$inquiryId], $this->createInquirySelections[(string) $inquiryId]);

        $this->createInquiryId = null;
        $this->createInquiryLabel = '';
        $this->createInquiryMeta = '';
        $this->createInquiryReplaceId = null;
        $this->createInquirySelectorVersion++;
        $this->resetValidation('createInquiryId');
        $this->resetValidation('createInquiryIds');
    }

    private function canUseCreateOrderProducts(User $user): bool
    {
        // Create-Order catalogue access is owned by the Products module. The
        // legacy Inquiry / Order Product Lines permission does not participate
        // in catalogue visibility or Product creation on this screen.
        return $user->canModule('jobs', 'create')
            && $user->canModule('catalog_products', 'view');
    }

    private function authorizeCreateOrderProducts(): void
    {
        abort_unless($this->showCreate && $this->canUseCreateOrderProducts(auth()->user()), 403);
    }

    public function openCreate(): void
    {
        abort_unless(auth()->user()->canModule('jobs', 'create'), 403);
        $this->selectedJobId = null;
        $this->selectedTaskId = null;
        $this->overviewPhaseId = null;
        $this->lastOverviewWorkflowPhaseId = null;
        $this->showCreate = true;
        $this->initializeCreateForm();
    }

    public function closeCreate(): void
    {
        $this->resetCreateForm();
        $this->redirectRoute('jobs.index', navigate: true);
    }

    public function removeCreatePurchaseOrder(): void
    {
        abort_unless(
            $this->showCreate
                && auth()->user()->canModule('jobs', 'create')
                && auth()->user()->canModule('documents', 'create'),
            403,
        );

        $this->purchaseOrderUpload = null;
        $this->resetValidation('purchaseOrderUpload');
    }

    public function removeCreateAttachment(int $index): void
    {
        abort_unless(
            $this->showCreate
                && auth()->user()->canModule('jobs', 'create')
                && auth()->user()->canModule('documents', 'create'),
            403,
        );
        abort_unless(array_key_exists($index, $this->jobAttachments), 422, 'That attachment is no longer selected.');

        unset($this->jobAttachments[$index]);
        $this->jobAttachments = array_values($this->jobAttachments);
        $this->resetValidation('jobAttachments');
        $this->resetValidation('jobAttachments.*');
    }

    public function loadCreateSection(string $section): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);

        if ($section === 'catalog') {
            $this->createCatalogReady = true;
            return;
        }

        if ($section === 'assignment') {
            $this->createCatalogReady = true;
            $this->ownerId ??= auth()->id();
            $this->coordinatorId ??= auth()->id();
            $this->createAssignmentReady = true;
            return;
        }

        if ($section === 'workflow') {
            $this->createCatalogReady = true;
            // Backward-compatible repair for the historical ORDER_PROCESS
            // workflow before the shared Workflow Setup picker calculates tasks.
            app(OrderWorkflowSetupService::class)->repairIfIncomplete();
            $this->createAssignmentReady = true;
            $this->ownerId ??= auth()->id();
            $this->coordinatorId ??= auth()->id();

            // The Client may have changed while this lazy section was still a
            // placeholder. Never hydrate the Workflow selector with an old or
            // no-longer-available Workflow from the previous Client.
            if (!$this->workflowId || !$this->createOrderWorkflowAvailableForClient($this->workflowId, $this->clientId)) {
                $this->applyClientWorkflowDefault($this->clientId);
            } else {
                $this->setDefaultStartPhase();
            }

            $this->createWorkflowReady = true;
            return;
        }

        abort(422, 'Unknown Create Order section.');
    }

    public function openSavedShippingAddressPicker(): void
    {
        $this->openSavedShippingAddressPickerForShipment(0);
    }

    public function closeSavedShippingAddressPicker(): void
    {
        $this->showSavedShippingAddressPicker = false;
        $this->savedShippingAddressShipmentIndex = null;
    }

    public function useSavedShippingAddress(int $addressId): void
    {
        $this->applySavedShippingAddressToCreateShipment($addressId);
    }

    private function resetCreateShippingAddress(): void
    {
        $this->shippingAddress = '';
        $this->shippingPhoneCountryCode = self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
        $this->shippingPhone = '';
        $this->shippingPostalCode = '';
        $this->shippingContactType = 'end_customer';
        $this->shippingContactDrafts = [];
        $this->shippingContactId = null;
        $this->shippingContactSelection = '';
        $this->shippingContactName = '';
        $this->shippingSaveContact = true;
        $this->shippingSourceAddressId = null;
        $this->showSavedShippingAddressPicker = false;
        $this->resetValidation([
            'shippingAddress',
            'shippingPhoneCountryCode',
            'shippingPhone',
            'shippingPostalCode',
            'shippingContactType',
            'shippingContactId',
            'shippingContactSelection',
            'shippingContactName',
            'shippingSaveContact',
            'shippingSourceAddressId',
        ]);
    }

    public function addProductRow(): void { $this->focusCreateProductSearch(); }

    public function removeProductRow(int $index): void
    {
        if (!array_key_exists($index, $this->jobItems)) return;

        $productId = (int) ($this->jobItems[$index]['product_id'] ?? 0);
        unset($this->jobItems[$index]);
        $this->jobItems = array_values($this->jobItems);

        if ($productId > 0) {
            $this->createOrderSupplierSkipProductIds = array_values(array_filter(
                $this->createOrderSupplierSkipProductIds,
                fn (int $id): bool => $id !== $productId
            ));
            unset($this->createOrderSupplierOverrides[$productId]);
        }

        $this->resetValidation('jobItems');
    }

    public function selectCreateProductionUrgency(int $urgencyId): void
    {
        $this->productionUrgencyIds = [$urgencyId];
        $this->resetErrorBag('productionUrgencyIds');
    }

    public function selectCreateShipmentUrgency(int $urgencyId): void
    {
        // Backward-compatible endpoint for a Create Order form left open across
        // this deployment. New renders use selectCreateShippingMethod(). Keep any
        // existing Order-level method aligned across all draft shipment rows.
        $this->shipmentUrgencyIds = [$urgencyId];
        $this->syncCreateShipmentMethodsFromGlobalSelection();
        $this->resetErrorBag('shipmentUrgencyIds');
    }

    public function selectCreateShippingMethod(int $methodId, ?int $urgencyId = null): void
    {
        abort_unless($this->showCreate && auth()->user()->canModule('jobs', 'create'), 403);

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $method = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('shipment_method')
            ->active()
            ->find($methodId);

        abort_unless($method, 422, 'That shipping method is no longer available.');

        $kind = CreateOrderShippingMethodPresenter::methodKind($method);
        $normalizedUrgencyId = null;

        if ($urgencyId !== null) {
            abort_unless($kind === 'express', 422, 'Shipping urgency is available only for Standard Express Shipping.');

            $urgency = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('shipment_urgency')
                ->active()
                ->find($urgencyId);

            abort_unless($urgency, 422, 'That shipping urgency is no longer available.');
            $normalizedUrgencyId = (int) $urgency->id;
        }

        // Create Order now chooses one shipping method in Schedule & owner.
        // Mirror that choice onto every draft shipment so every delivery address
        // enters the Shipment stage with the selected method already attached.
        $this->setCreateShippingSelectionForAllShipments(
            (int) $method->id,
            $kind === 'express' ? $normalizedUrgencyId : null,
        );
    }

    public function createJob(): void { $this->persistJob(false); }

    public function saveDraft(): void { $this->persistJob(true); }

    /**
     * Resolve each Create Order supplier safely before validation.
     *
     * Product Master supplies the default, while an explicit Order-only override is
     * preserved when it still references an active supplier. A missing Product supplier
     * still requires an explicit user choice. Products
     * explicitly stored in createOrderSupplierSkipProductIds are allowed to continue
     * with supplier_id = null. The skip state stays outside jobItems so
     * the persisted Order item payload remains identical to the normal create flow.
     *
     * @return bool False when at least one selected Product is missing a supplier
     *              and the user has not explicitly skipped it.
     */
    private function synchronizeCreateOrderProductSuppliersFromCatalog(): bool
    {
        if ($this->jobItems === []) return true;

        $catalog = app(\App\Services\ProductCatalogService::class);
        $products = $catalog->selectedProducts(collect($this->jobItems)->pluck('product_id'));
        // No extra linkage lookup for the normal default-supplier path.
        $hasOverrides = collect($this->createOrderSupplierOverrides)->contains(fn ($id): bool => (int) $id > 0);
        $linkedSuppliers = $hasOverrides ? $catalog->allSuppliersForProducts($products) : collect();
        $defaultSuppliers = $hasOverrides ? collect() : $catalog->suppliersForProducts($products);
        $missingSupplier = false;

        foreach ($this->jobItems as $index => $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $product = $products->get($productId);
            if (!$product) continue;

            $overrideId = (int) ($this->createOrderSupplierOverrides[$productId] ?? 0);
            $availableSuppliers = $linkedSuppliers->get($productId, collect());
            $overrideSupplier = $overrideId > 0
                ? $availableSuppliers->first(fn (MasterRecord $linked): bool => (int) $linked->id === $overrideId)
                : null;
            $defaultId = (int) ($product->productSupplierId() ?? 0);
            $defaultSupplier = $hasOverrides
                ? $availableSuppliers->first(fn (MasterRecord $linked): bool => (int) $linked->id === $defaultId)
                : $defaultSuppliers->get($productId);
            $supplier = $overrideSupplier ?: $defaultSupplier;
            $this->jobItems[$index]['supplier_id'] = $supplier?->id;

            if ($supplier) {
                $this->createOrderSupplierSkipProductIds = array_values(array_filter(
                    $this->createOrderSupplierSkipProductIds,
                    fn (int $id): bool => $id !== $productId
                ));
                $this->resetValidation("jobItems.$index.supplier_id");
                continue;
            }

            if (in_array($productId, $this->createOrderSupplierSkipProductIds, true)) {
                $this->resetValidation("jobItems.$index.supplier_id");
                continue;
            }

            $missingSupplier = true;
            $this->addError(
                "jobItems.$index.supplier_id",
                'Supplier is not linked. Link or create a supplier, or continue without one.'
            );

            if (!$this->showMissingProductSupplierModal) {
                $this->openMissingProductSupplierModalFor($product, $index);
            }
        }

        return !$missingSupplier;
    }

    private function persistJob(bool $draft): void
    {
        abort_unless($this->canUseCreateOrderProducts(auth()->user()), 403);

        if (!$this->createCatalogReady || !$this->createAssignmentReady || !$this->createWorkflowReady) {
            $this->addError('createLoading', 'Please wait for the remaining Create Order fields to finish loading.');
            return;
        }

        $this->normalizeCreateShipmentsBeforeSave();
        $this->normalizeShippingContactSelectionBeforeSave();

        // Re-resolve defaults and validate any explicit Order-only supplier override
        // immediately before validation. This prevents stale or tampered supplier IDs.
        if (!$this->synchronizeCreateOrderProductSuppliersFromCatalog()) {
            return;
        }

        $data = $this->validate([
            'referenceNumber' => ['required','string','max:255'],
            'isRepeatedOrder' => ['boolean'],
            'repeatedOrderNumber' => [Rule::requiredIf($this->isRepeatedOrder), 'nullable', 'string', 'max:255'],
            'createShipmentMode' => ['required', Rule::in([
                self::CREATE_SHIPMENT_MODE_MULTIPLE,
                self::CREATE_SHIPMENT_MODE_SAME_ADDRESS,
                self::CREATE_SHIPMENT_MODE_MULTIPLE_ADDRESS,
            ])],
            'createShipments' => ['required', 'array', 'min:1', 'max:20'],
            'createShipments.*.contact_name' => ['required', 'string', 'max:255'],
            'createShipments.*.phone_country_code' => [
                'required',
                'string',
                'max:12',
                'regex:/^\+[0-9]{1,4}$/',
                Rule::exists('master_records', 'name')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'phone_country_code')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'createShipments.*.phone' => ['required', 'string', 'max:60', 'regex:/^[0-9()\s.\-]{5,40}$/'],
            'createShipments.*.address' => ['required', 'string', 'max:2000'],
            'createShipments.*.city' => ['nullable', 'string', 'max:120'],
            'createShipments.*.state' => ['nullable', 'string', 'max:120'],
            'createShipments.*.postal_code' => ['required', 'string', 'max:30'],
            // Country/State are intentionally not shown in the approved compact
            // Shipment address prototype. Saved addresses may still populate
            // these optional metadata fields, but manual Create Order entry must
            // not fail validation for controls the user cannot see.
            'createShipments.*.country' => ['nullable', 'string', 'max:120'],
            'createShipments.*.shipping_source_address_id' => [
                'nullable',
                'integer',
                Rule::exists('client_shipping_addresses', 'id')->where(fn ($query) => $query->where('client_id', $this->clientId)),
            ],
            'createShipments.*.shipment_method_id' => [
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'shipment_method')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'createShipments.*.shipment_urgency_id' => [
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'shipment_urgency')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'createShipments.*.quantity' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'createShipments.*.package_reference' => ['nullable', 'string', 'max:255'],
            'shipmentMethodIds' => ['required', 'array', 'size:1'],
            'shipmentMethodIds.*' => [
                'integer',
                'distinct',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'shipment_method')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'shipmentUrgencyIds' => ['array', 'max:1'],
            'shipmentUrgencyIds.*' => [
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'shipment_urgency')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'clientId' => ['required','exists:clients,id'],
            'createInquiryId' => ['nullable','integer'],
            'createInquiryIds' => ['array','max:100'],
            'createInquiryIds.*' => ['integer','distinct'],
            'workflowId' => ['required','integer'],
            'workflowPhaseId' => ['required','integer'],
            'ownerId' => ['required','exists:users,id'],
            'coordinatorId' => ['nullable','exists:users,id'],
            // Hand Date is intentionally optional on Create Order.
            // The DTO already normalizes an empty value to null and the database column is nullable.
            'deliveryDate' => ['nullable','date'],
            'estimatedDeliveryDate' => ['nullable','date'],
            'description' => ['nullable','string'],
            'shippingAddress' => ['required','string','max:2000'],
            'shippingContactType' => ['required', Rule::in(['end_customer', 'middle_client', 'other_contact'])],
            'shippingContactId' => [
                'nullable',
                'integer',
                Rule::exists('client_contacts', 'id')->where(fn ($query) => $query->where('client_id', $this->clientId)),
            ],
            'shippingContactName' => ['required', 'string', 'max:255'],
            'shippingSaveContact' => ['boolean'],
            'shippingPhoneCountryCode' => [
                'required',
                'string',
                'max:12',
                'regex:/^\+[0-9]{1,4}$/',
                Rule::exists('master_records', 'name')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'phone_country_code')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'shippingPhone' => ['required','string','max:60','regex:/^[0-9()\s.\-]{5,40}$/'],
            'shippingPostalCode' => ['required','string','max:30'],
            'shippingSourceAddressId' => [
                'nullable',
                'integer',
                Rule::exists('client_shipping_addresses', 'id')->where(fn ($query) => $query->where('client_id', $this->clientId)),
            ],
            'jobItems' => ['required','array','min:1','max:25'],
            'jobItems.*.product_id' => ['required','integer'],
            'jobItems.*.category' => ['required','string','max:255'],
            'jobItems.*.product' => ['required','string','max:255'],
            'jobItems.*.supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', app(MasterDataService::class)->workspaceId())
                    ->where('type', 'supplier')
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'jobItems.*.quantity' => ['required','integer','min:1','max:999999999'],
            'jobItems.*.unit_price' => ['nullable','numeric','min:0','max:999999999999.99'],
            'jobItems.*.remote_area_surcharge' => ['nullable','numeric','min:0','max:999999999999.99'],
            'jobItems.*.notes' => ['nullable','string','max:2000'],
            // Optional PO uploaded during Create Order. It is attached to the
            // NEW_UPLOAD_PO workflow task after the Order and its tasks exist.
            'purchaseOrderUpload' => AttachmentUpload::nullableRules(AttachmentUpload::DOCUMENTS_WITH_AI, 20480),
            'jobAttachments.*' => AttachmentUpload::itemRules(AttachmentUpload::DOCUMENTS_WITH_AI, 20480),
        ], [
            'referenceNumber.required' => 'Client Reference Number is required.',
            'repeatedOrderNumber.required' => 'Enter the previous reference number for this repeated Order.',
            'jobItems.required' => 'Select at least one product for this Order.',
            'jobItems.min' => 'Select at least one product for this Order.',
            'createShipments.required' => 'Configure at least one shipment.',
            'createShipments.min' => 'Configure at least one shipment.',
            'createShipments.*.contact_name.required' => 'Contact person is required.',
            'createShipments.*.phone_country_code.required' => 'Country code is required.',
            'createShipments.*.phone_country_code.regex' => 'Choose a valid international phone code.',
            'createShipments.*.phone_country_code.exists' => 'Choose an active phone country code from Master Data.',
            'createShipments.*.phone.required' => 'Phone number is required.',
            'createShipments.*.phone.regex' => 'Enter a valid shipping contact phone number.',
            'createShipments.*.address.required' => 'Street address is required.',
            'createShipments.*.postal_code.required' => 'Postal code is required.',
            'shipmentMethodIds.required' => 'Select a shipping method.',
            'shipmentMethodIds.size' => 'Select one shipping method.',
            'createShipments.*.quantity.integer' => 'Quantity must be a whole number.',
            'createShipments.*.quantity.min' => 'Quantity must be at least 1 when provided.',
            'createShipments.*.quantity.max' => 'Quantity is too large.',
            'shippingAddress.required' => 'Shipping address is required.',
            'deliveryDate.date' => 'Hand Date must be a valid date.',
            'shippingPostalCode.required' => 'Postal code is required.',
            'shippingContactName.required' => 'Contact person is required.',
            'shippingPhoneCountryCode.required' => 'Country code is required.',
            'shippingPhone.required' => 'Phone number is required.',
            'shippingPhoneCountryCode.regex' => 'Choose a valid international phone code.',
            'shippingPhoneCountryCode.exists' => 'Choose an active phone country code from Master Data.',
            'shippingPhone.regex' => 'Enter a valid shipping contact phone number.',
            'purchaseOrderUpload.max' => 'The Purchase Order is too large. Maximum file size is 20 MB.',
        ]);

        if ($this->purchaseOrderUpload || count($this->jobAttachments) > 0) {
            abort_unless(auth()->user()->canModule('documents', 'create'), 403);
        }

        $clientAvailable = app(ClientService::class)
            ->referenceQuery(auth()->user(), 'create-job')
            ->where('is_active', true)
            ->whereKey((int) $data['clientId'])
            ->exists();
        if (!$clientAvailable) {
            $this->addError('clientId', 'That client is no longer available.');
            return;
        }

        $rawCreateInquiryIds = (array) ($data['createInquiryIds'] ?? []);
        if ($rawCreateInquiryIds === [] && !empty($data['createInquiryId'])) {
            // Support a stale pre-deployment browser snapshot that still sends
            // the previous single selector property.
            $rawCreateInquiryIds = [(int) $data['createInquiryId']];
        }
        $createInquiryIds = collect($rawCreateInquiryIds)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values();

        if ($createInquiryIds->isNotEmpty()) {
            $access = app(\App\Services\AccessControlService::class);
            abort_unless(
                $access->can(auth()->user(), 'jobs', 'link') && $access->can(auth()->user(), 'inquiries', 'view'),
                403
            );

            // Re-check every selected Inquiry immediately before creation. A
            // second user may have linked one since it was picked; if so, fail
            // the whole create cleanly instead of producing a partial relation.
            $availableIds = app(\App\Services\FilterOptionService::class)
                ->selectedOptions(
                    auth()->user(),
                    'inquiries',
                    'create-job',
                    $createInquiryIds->map(fn (int $id) => (string) $id)->all(),
                    ['client_id' => (int) $data['clientId']],
                )
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($createInquiryIds->diff($availableIds)->isNotEmpty()) {
                $this->addError('createInquiryIds', 'One or more selected Inquiries are already linked, closed, or no longer available. Remove them and try again.');
                return;
            }

            $data['createInquiryIds'] = $createInquiryIds->all();
        }

        $workflowAvailable = OrderWorkflowSetupService::orderWorkflowQuery()
            ->where('is_active', true)
            ->availableFor('orders', (int) $data['clientId'])
            ->whereKey((int) $data['workflowId'])
            ->exists()
            && app(OrderWorkflowSetupService::class)->isReadyForOrderCreation((int) $data['workflowId']);

        if (!$workflowAvailable) {
            $this->addError('workflowId', 'That Order workflow is not available. Choose a complete Order workflow from Workflow Setup.');
            return;
        }

        $firstOrderPhaseId = WorkflowPhase::query()
            ->where('workflow_template_id', (int) $data['workflowId'])
            ->where('is_active', true)
            ->orderBy('sequence')
            ->value('id');

        if (!$firstOrderPhaseId || (int) $data['workflowPhaseId'] !== (int) $firstOrderPhaseId) {
            $this->addError('workflowPhaseId', 'New Orders must start from Stage 1 of the selected Order workflow.');
            return;
        }

        $catalogInvalid = false;
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $clientId = (int) $data['clientId'];
        $products = MasterRecord::query()
            ->forWorkspace($workspaceId)->ofType('product')->active()
            ->with(['parent:id,name,status', 'clientPrices' => fn ($query) => $query->where('client_id', $clientId)])
            ->whereIn('id', collect($data['jobItems'])->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->all())
            ->get()->keyBy('id');
        foreach ($data['jobItems'] as $index => $row) {
            $product = $products->get((int) $row['product_id']);

            $valid = $product
                && $product->parent
                && $product->parent->status === 'active'
                && (string) $product->name === trim((string) $row['product'])
                && (string) $product->parent->name === trim((string) $row['category']);

            if (!$valid) {
                $catalogInvalid = true;
                $this->addError("jobItems.$index.product", 'That product is no longer available in the selected catalog.');
                continue;
            }

            // Product Master customer pricing is authoritative for Create Order. Resolve
            // the base/unit price again at save time so a stale browser value or
            // client-side tampering cannot override the quantity price table.
            $quantity = (int) ($row['quantity'] ?? 0);
            $basePrice = $product->productPriceForQuantity($quantity, (int) $data['clientId']);
            $data['jobItems'][$index]['unit_price'] = $basePrice !== null
                ? round($basePrice, 2)
                : null;
        }
        if ($catalogInvalid) return;

        // Resolve authoritative supplier-specific costs only into this server-side
        // save payload, including when the creator cannot view confidential costs.
        // No supplier cost is ever sent to an unauthorized Livewire snapshot.
        $costs = app(\App\Services\Orders\OrderProductPricing::class)
            ->supplierUnitPrices($data['jobItems'], $products);
        foreach ($data['jobItems'] as $index => $row) {
            $key = (int) $row['product_id'].':'.(int) ($row['supplier_id'] ?? 0);
            $data['jobItems'][$index]['supplier_unit_price'] = $costs[$key] ?? null;
            unset($data['jobItems'][$index]['supplier_price']);
        }

        $job = app(CreateOrder::class)->handle(
            $data,
            $this->purchaseOrderUpload,
            $this->jobAttachments,
            $draft,
            auth()->user(),
        );

        $this->persistShippingContactSelection();

        $this->showCreate = false;
        $this->resetCreateForm();
        $this->selectedJobId = $job->id;
        $this->detailTab = 'overview';
        $this->prepareSelectedJob($job->id);
        session()->flash('success', $draft ? 'Order draft saved.' : 'Order created and all configured Workflow Task Packs were loaded.');
    }

    private function preferredCreateOrderWorkflowId(?int $clientId): ?int
    {
        $ids = OrderWorkflowSetupService::orderWorkflowQuery()
            ->where('is_active', true)
            ->availableFor('orders', $clientId)
            // A workflow configured specifically for the selected Client is
            // more intentional than an all-client default. Keep the default
            // flag as the tie-breaker within the same availability scope.
            ->orderByRaw("CASE WHEN client_availability = 'specific' THEN 0 ELSE 1 END")
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            if (app(OrderWorkflowSetupService::class)->isReadyForOrderCreation((int) $id)) return (int) $id;
        }

        return null;
    }

    private function createOrderWorkflowAvailableForClient(int $workflowId, ?int $clientId): bool
    {
        return OrderWorkflowSetupService::orderWorkflowQuery()
            ->where('is_active', true)
            ->availableFor('orders', $clientId)
            ->whereKey($workflowId)
            ->exists()
            && app(OrderWorkflowSetupService::class)->isReadyForOrderCreation($workflowId);
    }

    private function applyClientWorkflowDefault(?int $clientId): void
    {
        // Clear first so both Livewire and Alpine cannot temporarily retain the
        // previous Client's selection while the new default is being resolved.
        $this->workflowId = null;
        $this->workflowPhaseId = null;

        // Resolve the preferred ready Order workflow for the selected client.
        // Client availability is configured in the shared Workflow Setup.
        $this->workflowId = $this->preferredCreateOrderWorkflowId($clientId);
        $this->setDefaultStartPhase();

        // Force the remote Workflow selector to get a fresh Alpine instance.
        // Its request params include client_id, so reusing the old instance can
        // otherwise leave the dropdown searching with the previous Client.
        $this->createWorkflowSelectorVersion++;
        $this->resetValidation('workflowId');
        $this->resetValidation('workflowPhaseId');
    }

    private function setDefaultStartPhase(): void
    {
        if (!$this->workflowId) {
            $this->workflowPhaseId = null;
            return;
        }

        // Every Order workflow keeps the same fixed seven-stage runtime. New
        // Orders always enter at Stage 1; users select the workflow, not a stage.
        $this->workflowPhaseId = WorkflowPhase::query()
            ->where('workflow_template_id', $this->workflowId)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->value('id');
    }

    private function initializeCreateForm(?int $requestedClientId = null): void
    {
        $this->resetCreateForm();
        $clientQuery = app(ClientService::class)
            ->referenceQuery(auth()->user(), 'create-job')
            ->where('is_active', true);

        $this->clientId = $requestedClientId && (clone $clientQuery)->whereKey($requestedClientId)->exists()
            ? $requestedClientId
            : $clientQuery->value('id');
        $this->applyClientWorkflowDefault($this->clientId);
        $this->initializeCreateShippingContact($this->clientId);
        $this->initializeCreateShipments();
        $this->jobItems = [];
    }

    private function resetCreateForm(): void
    {
        $this->resetValidation();
        $this->reset([
            'referenceNumber',
            'isRepeatedOrder',
            'repeatedOrderNumber',
            'priority',
            'productionUrgencyIds',
            'shipmentMethodIds',
            'shipmentUrgencyIds',
            'createShipmentMode',
            'createShipments',
            'savedShippingAddressShipmentIndex',
            'clientId',
            'createInquiryId',
            'createInquiryIds',
            'createInquirySelections',
            'createInquiryLabel',
            'createInquiryMeta',
            'createInquirySelectorVersion',
            'createInquiryReplaceId',
            'workflowId',
            'workflowPhaseId',
            'ownerId',
            'coordinatorId',
            'deliveryDate',
            'estimatedDeliveryDate',
            'description',
            'shippingAddress',
            'shippingPhoneCountryCode',
            'shippingPhone',
            'shippingPostalCode',
            'shippingContactType',
            'shippingContactId',
            'shippingContactSelection',
            'shippingContactName',
            'shippingSaveContact',
            'shippingContactDrafts',
            'shippingSourceAddressId',
            'showSavedShippingAddressPicker',
            'jobItems',
            'createProductSearch',
            'createProductCategoryFilter',
            'createProductShowAllResults',
            'showCreateOrderProductModal',
            'showMissingProductSupplierModal',
            'missingProductSupplierName',
            'missingProductSupplierChoice',
            'missingProductExistingSupplierId',
            'missingProductExistingSupplierLabel',
            'missingProductNewSupplierName',
            'missingProductNewSupplierEmail',
            'pendingMissingSupplierProductId',
            'pendingMissingSupplierRowIndex',
            'missingProductSupplierContext',
            'missingProductSupplierAllowSkip',
            'missingProductSupplierRecordLabel',
            'missingProductSupplierSubmitMode',
            'missingProductSupplierSelectorContext',
            'createOrderSupplierSkipProductIds',
            'createOrderSupplierOverrides',
            'newProductCode',
            'newProductCategoryId',
            'newProductCategorySearch',
            'newProductCategoryName',
            'newProductName',
            'newProductSupplierId',
            'newProductImage',
            'purchaseOrderUpload',
            'jobAttachments',
            'createCatalogReady',
            'createAssignmentReady',
            'createWorkflowReady',
            'createWorkflowSelectorVersion',
        ]);
    }

}
