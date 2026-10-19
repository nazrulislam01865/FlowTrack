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

trait ManagesClientDetail
{
    public function loadClientDetailSection(string $section): void
    {
        abort_unless($this->showDetail && $this->selectedClientId && $this->clientDetailTab === 'overview', 422);
        abort_unless($section === 'addresses', 422);
        $this->clientDetailSectionsReady['addresses'] = true;
    }

    private function resetClientDetailProgressiveSections(): void
    {
        $this->clientDetailSectionsReady = ['addresses' => false];
    }

    public function openClient(int $id): void
    {
        // Client rows now open the full client view directly. Keep this method
        // as a compatibility alias so stale Livewire payloads cannot reopen the
        // retired preview modal after a deployment.
        $this->viewClient($id);
    }
    public function closeClientPreview(): void
    {
        $this->showClientPreview = false;
        $this->selectedClientId = null;
        $this->actionMenuClientId = null;
    }
    public function toggleClientMenu(int $id): void
    {
        app(ClientService::class)->visibleQuery(auth()->user())->findOrFail($id);
        $this->actionMenuClientId = $this->actionMenuClientId === $id ? null : $id;
    }
    public function viewClient(int $id): void
    {
        app(ClientService::class)->visibleQuery(auth()->user())->findOrFail($id);
        $this->selectedClientId = $id;
        $this->showClientPreview = false;
        $this->showDetail = true;
        $this->resetClientDetailProgressiveSections();
        $this->showEdit = false;
        $this->clientDetailTab = 'overview';
        $this->clearClientOrderFilters();
        $this->actionMenuClientId = null;
    }
    public function backToClients(): void
    {
        $this->showDetail = false;
        $this->resetClientDetailProgressiveSections();
        $this->showEdit = false;
        $this->showClientPreview = false;
        $this->selectedClientId = null;
        $this->clientDetailTab = 'overview';
        $this->actionMenuClientId = null;
        $this->resetValidation();
    }
    private function canEditClient(Client $client): bool
    {
        $access = app(\App\Services\AccessControlService::class);
        if ($access->isAdministrator(auth()->user()) || $access->canEditAll(auth()->user(), 'clients')) return true;
        return $access->canEditOwn(auth()->user(), 'clients')
            && (int) ($client->account_manager_id ?? 0) === (int) auth()->id();
    }
}
