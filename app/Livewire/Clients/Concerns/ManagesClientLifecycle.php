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

trait ManagesClientLifecycle
{
    public function deleteClient(int $id): void
    {
        abort_unless(auth()->user()->canModule('clients','delete'), 403);
        $client = app(\App\Actions\Clients\ArchiveClientAction::class)->execute(auth()->user(), $id);
        session()->flash('success', 'Client archived. It is available from Archived Clients and can be restored.');
        try {
            app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), 'Client archived', $client->name.' was archived.', 'update', null, null, auth()->user());
        } catch (\Throwable $exception) {
            report($exception);
        }

        if ($this->selectedClientId === $id) $this->selectedClientId = null;
        $this->showClientPreview = false;
        $this->showDetail = false;
        $this->showEdit = false;
        $this->actionMenuClientId = null;
        $this->resetPage();
    }
    public function restoreClient(int $id): void
    {
        abort_unless(auth()->user()->canModule('clients','delete'), 403);
        $client = app(\App\Actions\Clients\RestoreClientAction::class)->execute(auth()->user(), $id);
        session()->flash('success', $client->name.' was restored to Active Clients.');
        try {
            app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), 'Client restored', $client->name.' was restored.', 'update', null, null, auth()->user());
        } catch (\Throwable $exception) {
            report($exception);
        }
        $this->actionMenuClientId = null;
        $this->resetPage();
    }
    public function openPermanentDeleteClient(int $id): void
    {
        abort_unless(auth()->user()->canModule('clients','delete'), 403);

        app(ClientService::class)
            ->visibleQuery(auth()->user())
            ->where('is_active', false)
            ->findOrFail($id);

        $this->deleteArchivedClientId = $id;
        $this->deleteArchivedClientConfirmed = false;
        $this->actionMenuClientId = null;
    }
    public function closePermanentDeleteClient(): void
    {
        $this->deleteArchivedClientId = null;
        $this->deleteArchivedClientConfirmed = false;
    }
    public function permanentlyDeleteClient(): void
    {
        abort_unless(auth()->user()->canModule('clients','delete'), 403);
        abort_unless($this->showArchived && $this->deleteArchivedClientId, 422);

        $this->validate([
            'deleteArchivedClientConfirmed' => ['accepted'],
        ], [
            'deleteArchivedClientConfirmed.accepted' => 'Confirm that you understand this client cannot be recovered.',
        ]);

        $clientId = (int) $this->deleteArchivedClientId;
        $name = app(\App\Actions\Clients\PermanentlyDeleteClientAction::class)->execute(auth()->user(), $clientId);

        $this->closePermanentDeleteClient();
        if ($this->selectedClientId === $clientId) $this->selectedClientId = null;
        $this->showClientPreview = false;
        $this->showDetail = false;
        $this->showEdit = false;
        $this->resetPage();

        session()->flash('success', $name.' was permanently deleted. Historical linked records were preserved.');

        try {
            app(\App\Services\NotificationService::class)->notifyUser(
                auth()->user(),
                'Archived client deleted',
                'An archived client was permanently deleted. Historical linked records were preserved.',
                'update',
                null,
                null,
                auth()->user()
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
