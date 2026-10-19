<?php

namespace App\Livewire\Clients;

use App\Livewire\Clients\Concerns\ManagesClientList;
use App\Livewire\Clients\Concerns\ManagesClientProfile;
use App\Livewire\Clients\Concerns\ManagesClientAddresses;
use App\Livewire\Clients\Concerns\ManagesClientContacts;
use App\Livewire\Clients\Concerns\ManagesClientCreation;
use App\Livewire\Clients\Concerns\ManagesClientDetail;
use App\Livewire\Clients\Concerns\ManagesClientEditing;
use App\Livewire\Clients\Concerns\ManagesClientLifecycle;
use App\Livewire\Clients\Concerns\BuildsClientPageData;
use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;

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
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use ManagesClientList;
    use ManagesClientProfile;
    use ManagesClientAddresses;
    use ManagesClientContacts;
    use ManagesClientCreation;
    use ManagesClientDetail;
    use ManagesClientEditing;
    use ManagesClientLifecycle;
    use BuildsClientPageData;

    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    use WithFileUploads;
    use WithPagination;

    public string $search = '';
    public string $country = '';
    public string $manager = '';
    public string $outstanding = '';
    public string $quick = 'all';
    public bool $showArchived = false;
    public string $archivedDate = '';
    public string $createdBy = '';
    public int $perPage = 10;
    public ?int $selectedClientId = null;
    public bool $showClientPreview = false;
    public bool $showCreate = false;
    public bool $createAddressOptionsReady = false;
    public bool $showDetail = false;
    public array $clientDetailSectionsReady = [
        'addresses' => false,
    ];
    public bool $showEdit = false;
    public ?int $actionMenuClientId = null;
    public ?int $deleteArchivedClientId = null;
    public bool $deleteArchivedClientConfirmed = false;
    public string $clientDetailTab = 'overview';
    public string $clientOrderSearch = '';
    public string $clientOrderStatus = '';
    public string $clientOrderOwner = '';
    public string $clientOrderRange = '12m';
    public int $clientOrderPerPage = 8;

    public string $clientCode = '';
    public string $clientName = '';
    public $clientLogoUpload = null;
    public string $existingClientLogoUrl = '';
    public bool $removeClientLogo = false;
    public string $legalBusinessName = '';
    public string $website = '';
    public string $clientCountry = 'United States';
    public string $preferredCurrency = 'USD';
    public string $officeAddress = '';
    public string $officeAddressLine1 = '';
    public string $officeSuite = '';
    public string $officeCity = '';
    public string $officeState = '';
    public string $officeZip = '';
    public bool $billingSameAsOffice = false;
    public string $billingRecipient = '';
    public string $billingAddressLine1 = '';
    public string $billingSuite = '';
    public string $billingCity = '';
    public string $billingState = '';
    public string $billingZip = '';
    public string $billingCountry = 'United States';
    public string $contactName = '';
    public string $contactJobTitle = '';
    public string $email = '';
    public string $phone = '';
    public array $contacts = [];
    public ?int $accountManagerId = null;
    public string $preferredLanguage = 'English';
    public string $outstandingBalance = '0';
    public string $einTaxId = '';
    public string $salesTaxStatus = 'taxable';
    public string $paymentTerms = '';
    public bool $poRequired = false;
    public string $notes = '';
    public array $shippingAddresses = [];

    public function mount(): void
    {
        $this->showCreate = request()->boolean('create');
        if ($this->showCreate) $this->resetCreateForm();
    }


































































    public function render()
    {
        $user = auth()->user();

        if ($this->showCreate) {
            return view('livewire.clients.index', $this->createPageData($user));
        }

        if ($this->showDetail && $this->selectedClientId) {
            return view('livewire.clients.index', $this->detailPageData($user));
        }

        return view('livewire.clients.index', $this->clientsListData($user));
    }



}
