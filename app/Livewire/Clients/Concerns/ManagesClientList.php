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

trait ManagesClientList
{
    public function updatedSearch(): void
    {
        $this->activateSingleListFilter('search');
    }
    public function updatedCountry(): void
    {
        $this->activateSingleListFilter('country');
    }
    public function updatedManager(): void
    {
        $this->activateSingleListFilter('manager');
    }
    public function updatedOutstanding(): void
    {
        $this->activateSingleListFilter('outstanding');
    }
    public function updatedArchivedDate(): void
    {
        $this->activateSingleListFilter('archivedDate');
    }
    public function updatedCreatedBy(): void
    {
        $this->activateSingleListFilter('createdBy');
    }
    public function updatedPerPage(): void { $this->resetPage(); }
    public function updatedClientOrderSearch(): void { $this->resetPage('clientOrdersPage'); }
    public function updatedClientOrderStatus(): void { $this->resetPage('clientOrdersPage'); }
    public function updatedClientOrderOwner(): void { $this->resetPage('clientOrdersPage'); }
    public function updatedClientOrderRange(): void { $this->resetPage('clientOrdersPage'); }
    public function updatedClientOrderPerPage(): void { $this->resetPage('clientOrdersPage'); }
    public function setClientDetailTab(string $tab): void
    {
        abort_unless(in_array($tab, ['overview','contacts','orders','documents','activity'], true), 422);
        $this->clientDetailTab = $tab;
    }
    public function clearClientOrderFilters(): void
    {
        $this->clientOrderSearch = '';
        $this->clientOrderStatus = '';
        $this->clientOrderOwner = '';
        $this->clientOrderRange = '12m';
        $this->resetPage('clientOrdersPage');
    }
    public function updatedClientCountry(string $country): void
    {
        $this->officeState = '';
        if ($this->billingSameAsOffice) {
            $this->billingCountry = $country;
            $this->billingState = '';
        }
    }
    public function updatedBillingCountry(): void
    {
        $this->billingState = '';
    }
    public function updatedShippingAddresses(mixed $value, string $key): void
    {
        if (!str_ends_with($key, '.country')) return;
        $index = (int) explode('.', $key, 2)[0];
        if (isset($this->shippingAddresses[$index])) $this->shippingAddresses[$index]['state'] = '';
    }
    public function setQuick(string $quick): void
    {
        abort_unless(in_array($quick, ['all','active_jobs','attention','outstanding'], true), 422);

        // Summary cards and list filters are mutually exclusive. Selecting any
        // card clears the filter bar first; Total clients therefore acts as a
        // clean "show all" state.
        $this->clearClientListFilterValues();
        $this->quick = $quick;
        $this->resetPage();
    }
    public function showActiveClients(): void
    {
        $this->showArchived = false;
        $this->clearClientListFilterValues();
        $this->quick = 'all';
        $this->actionMenuClientId = null;
        $this->closePermanentDeleteClient();
        $this->resetPage();
    }
    public function showArchivedClients(): void
    {
        $this->showArchived = true;
        $this->clearClientListFilterValues();
        $this->quick = 'all';
        $this->actionMenuClientId = null;
        $this->closePermanentDeleteClient();
        $this->resetPage();
    }
    public function clearFilters(): void
    {
        $this->clearClientListFilterValues();
        $this->quick = 'all';
        $this->resetPage();
    }
    private function activateSingleListFilter(string $activeFilter): void
    {
        $allowed = ['search','country','manager','outstanding','archivedDate','createdBy'];
        abort_unless(in_array($activeFilter, $allowed, true), 422);

        $value = $this->{$activeFilter};
        $hasValue = is_string($value) ? trim($value) !== '' : !empty($value);

        if ($hasValue) {
            $this->clearClientListFilterValues($activeFilter);
            $this->quick = 'all';
        }

        $this->resetPage();
    }
    private function clearClientListFilterValues(?string $except = null): void
    {
        foreach (['search','country','manager','outstanding','archivedDate','createdBy'] as $filter) {
            if ($filter === $except) continue;
            $this->{$filter} = '';
        }
    }
    public function clearFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['search','country','manager','outstanding','archivedDate','createdBy'], true), 422);
        $this->{$filter} = '';
        $this->resetPage();
    }
}
