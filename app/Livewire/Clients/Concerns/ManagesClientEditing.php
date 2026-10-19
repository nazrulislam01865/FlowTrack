<?php

namespace App\Livewire\Clients\Concerns;

use App\Models\Client;
use App\Models\ClientShippingAddress;
use App\Models\ClientContact;
use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\User;
use App\Services\ClientService;
use App\Services\DocumentService;
use App\Services\MasterDataService;
use App\Services\SetupContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ManagesClientEditing
{
    public function editClient(int $id): void
    {
        $client = app(ClientService::class)->visibleQuery(auth()->user())->with(['shippingAddresses','contacts'])->findOrFail($id);
        abort_unless($this->canEditClient($client), 403);
        $this->selectedClientId = $id;
        $this->showClientPreview = false;
        $this->showDetail = true;
        $this->showEdit = true;
        $this->clientDetailTab = 'overview';
        $this->actionMenuClientId = null;

        $hasStructuredOffice = collect([$client->office_address_line1, $client->office_suite, $client->office_city, $client->office_state, $client->office_zip])
            ->contains(fn ($value) => filled($value));

        $this->clientCode = $client->code ?: 'CL-'.str_pad((string) $client->id, 3, '0', STR_PAD_LEFT);
        $this->clientName = $client->name ?? '';
        $this->clientLogoUpload = null;
        $this->existingClientLogoUrl = $client->logoUrl() ?: '';
        $this->removeClientLogo = false;
        $this->legalBusinessName = $client->legal_business_name ?? '';
        $this->website = $client->website ?? '';
        $this->clientCountry = $client->country ?: $this->defaultClientCountry();
        $this->preferredCurrency = $client->preferred_currency ?: $this->defaultClientCurrency();
        $this->officeAddress = $client->office_address ?? '';
        $this->officeAddressLine1 = $client->office_address_line1 ?: ($hasStructuredOffice ? '' : ($client->office_address ?? ''));
        $this->officeSuite = $client->office_suite ?? '';
        $this->officeCity = $client->office_city ?? '';
        $this->officeState = $client->office_state ?? '';
        $this->officeZip = $client->office_zip ?? '';
        $legacyBillingFromOffice = (bool) $client->billing_same_as_office && blank($client->billing_address_line1);
        $this->billingSameAsOffice = false;
        $this->billingRecipient = $client->billing_recipient ?: ($client->contact_name ?? '');
        $this->billingAddressLine1 = $legacyBillingFromOffice ? ($client->office_address_line1 ?: $client->office_address ?: '') : ($client->billing_address_line1 ?? '');
        $this->billingSuite = $legacyBillingFromOffice ? ($client->office_suite ?? '') : ($client->billing_suite ?? '');
        $this->billingCity = $legacyBillingFromOffice ? ($client->office_city ?? '') : ($client->billing_city ?? '');
        $this->billingState = $legacyBillingFromOffice ? ($client->office_state ?? '') : ($client->billing_state ?? '');
        $this->billingZip = $legacyBillingFromOffice ? ($client->office_zip ?? '') : ($client->billing_zip ?? '');
        $this->billingCountry = $legacyBillingFromOffice ? $this->clientCountry : ($client->billing_country ?: $this->clientCountry);
        $this->contactName = $client->contact_name ?? '';
        $this->contactJobTitle = $client->contact_job_title ?? '';
        $this->email = $client->email ?? '';
        $this->phone = $client->phone ?? '';
        $this->contacts = $client->contacts->map(fn (ClientContact $contact) => [
            'name' => $contact->name ?? '',
            'job_title' => $contact->job_title ?? '',
            'email' => $contact->email ?? '',
            'phone' => $contact->phone ?? '',
        ])->values()->all();
        if ($this->contacts === []) {
            $this->contacts = [[
                'name' => $this->contactName,
                'job_title' => $this->contactJobTitle,
                'email' => $this->email,
                'phone' => $this->phone,
            ]];
        }
        $this->accountManagerId = $client->account_manager_id;
        $this->preferredLanguage = $client->preferred_language ?: 'English';
        $this->outstandingBalance = (string) ($client->outstanding_balance ?? 0);
        $this->einTaxId = $client->ein_tax_id ?? '';
        $this->salesTaxStatus = in_array($client->sales_tax_status, ['taxable','tax_exempt'], true) ? $client->sales_tax_status : 'taxable';
        $this->paymentTerms = $client->payment_terms ?? '';
        $this->poRequired = (bool) $client->po_required;
        $this->notes = $client->notes ?? '';
        $this->shippingAddresses = $client->shippingAddresses->values()->map(function (ClientShippingAddress $address, int $index) {
            return [
                'label' => $address->label ?? '',
                'recipient' => $address->recipient ?? '',
                'address_line1' => $address->address_line1 ?? '',
                'suite' => $address->suite ?? '',
                'city' => $address->city ?? '',
                'state' => $address->state ?? '',
                'zip' => $address->zip ?? '',
                'country' => $address->country ?: $this->clientCountry,
                'is_default' => (bool) $address->is_default,
                'expanded' => $index === 0,
            ];
        })->all();
        if (!$this->shippingAddresses) $this->shippingAddresses = [$this->blankShippingAddress(true)];

        $this->resetValidation();
    }
    public function cancelEditClient(): void
    {
        $this->showEdit = false;
        $this->clientLogoUpload = null;
        $this->removeClientLogo = false;
        $this->resetValidation();
    }
    public function markClientLogoForRemoval(): void
    {
        $this->clientLogoUpload = null;
        $this->removeClientLogo = true;
        $this->resetValidation('clientLogoUpload');
    }
    public function restoreClientLogo(): void
    {
        $this->removeClientLogo = false;
    }
    public function updatedClientLogoUpload(): void
    {
        $this->removeClientLogo = false;
        $this->validateOnly('clientLogoUpload', [
            'clientLogoUpload' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'],
        ]);
    }
    public function updateClient(): void
    {
        abort_unless($this->selectedClientId, 404);
        $client = app(ClientService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedClientId);
        abort_unless($this->canEditClient($client), 403);

        // Edit mode is deliberately tolerant of legacy Country/Currency/State
        // values already stored on older clients. The dropdowns still provide
        // current Master Data options, but stale historical values no longer make
        // the Save Client action appear to do nothing because validation failed.
        $data = $this->validate($this->clientProfileRules(false, false, false), $this->clientProfileValidationMessages());
        $this->assertContactEmailsUnique($data['contacts'] ?? [], $client->id);

        if ($data['accountManagerId'] && (int) $data['accountManagerId'] !== (int) $client->account_manager_id) {
            abort_unless(auth()->user()->canModule('clients','assign') || auth()->user()->canModule('clients','edit_all'), 403);
        }

        $client = app(\App\Actions\Clients\SaveClientProfileAction::class)->execute(
            auth()->user(),
            $data,
            false,
            $client,
            $this->clientLogoUpload,
            $this->removeClientLogo,
        );

        $this->clientLogoUpload = null;
        $this->removeClientLogo = false;
        $this->existingClientLogoUrl = $client->logoUrl() ?: '';
        $this->showEdit = false;
        session()->flash('success', 'Client updated successfully.');
        try {
            app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), 'Client updated', $client->name.' was updated.', 'update', null, null, auth()->user());
        } catch (\Throwable $exception) {
            // A notification/Reverb failure must never roll back or visually
            // block a client profile update that was already saved successfully.
            report($exception);
        }
    }
}
