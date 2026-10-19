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

trait ManagesClientCreation
{
    public function openCreate(): void
    {
        abort_unless(auth()->user()->canModule('clients','create'), 403);
        $this->showCreate = true;
        $this->resetCreateForm();
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
        $this->createAddressOptionsReady = false;
        $this->resetValidation();
    }

    public function loadCreateSection(string $section): void
    {
        abort_unless($this->showCreate, 422);

        if ($section === 'addresses') {
            $this->createAddressOptionsReady = true;
            return;
        }

        abort(422, 'Unknown Create Client section.');
    }

    public function createClient(): void
    {
        $this->persistNewClient(false);
    }

    public function saveClientDraft(): void
    {
        $this->persistNewClient(true);
    }

    private function persistNewClient(bool $draft): void
    {
        abort_unless(auth()->user()->canModule('clients','create'), 403);

        $data = $this->validate($this->clientProfileRules($draft, !$draft), $this->clientProfileValidationMessages());
        $this->assertContactEmailsUnique($data['contacts'] ?? []);

        if ($data['accountManagerId'] && (int) $data['accountManagerId'] !== (int) auth()->id()) {
            abort_unless(auth()->user()->canModule('clients','assign') || auth()->user()->canModule('clients','edit_all'), 403);
        }

        $client = app(\App\Actions\Clients\SaveClientProfileAction::class)->execute(
            auth()->user(),
            $data,
            $draft,
            null,
            $this->clientLogoUpload,
            false,
        );

        $this->clientLogoUpload = null;
        $this->existingClientLogoUrl = $client->logoUrl() ?: '';
        $this->removeClientLogo = false;
        $this->showCreate = false;
        $this->selectedClientId = $client->id;
        $this->showClientPreview = true;
        session()->flash('success', $draft ? 'Client draft saved successfully.' : 'Client created successfully.');
        app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), $draft ? 'Client draft saved' : 'Client created', $client->name.($draft ? ' was saved as a draft.' : ' was created.'), 'update', null, null, auth()->user());
    }

    private function resetCreateForm(): void
    {
        $defaultCountry = $this->defaultClientCountry();
        $defaultCurrency = $this->defaultClientCurrency();
        $this->clientCode = $this->nextClientCode(); $this->clientName = ''; $this->clientLogoUpload = null; $this->existingClientLogoUrl = ''; $this->removeClientLogo = false; $this->legalBusinessName = ''; $this->website = '';
        $this->clientCountry = $defaultCountry; $this->preferredCurrency = $defaultCurrency; $this->officeAddress = ''; $this->officeAddressLine1 = '';
        $this->officeSuite = ''; $this->officeCity = ''; $this->officeState = ''; $this->officeZip = ''; $this->billingSameAsOffice = false;
        $this->billingRecipient = ''; $this->billingAddressLine1 = ''; $this->billingSuite = ''; $this->billingCity = ''; $this->billingState = ''; $this->billingZip = '';
        $this->billingCountry = $defaultCountry; $this->contactName = ''; $this->contactJobTitle = ''; $this->email = ''; $this->phone = ''; $this->contacts = [$this->blankContact()];
        $this->accountManagerId = auth()->id(); $this->preferredLanguage = 'English'; $this->outstandingBalance = '0'; $this->einTaxId = '';
        $this->salesTaxStatus = 'taxable'; $this->paymentTerms = ''; $this->poRequired = false; $this->notes = '';
        $this->shippingAddresses = [$this->blankShippingAddress(false)];
        $this->createAddressOptionsReady = false;
        $this->resetValidation();
    }

    private function nextClientCode(): string
    {
        $next = (int) Client::max('id') + 1;
        do { $code = 'CL-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT); $next++; } while (Client::where('code', $code)->exists());
        return $code;
    }
}
