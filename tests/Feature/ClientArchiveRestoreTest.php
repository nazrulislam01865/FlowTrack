<?php

namespace Tests\Feature;

use App\Livewire\Clients\Index;
use App\Models\Client;
use App\Models\ClientShippingAddress;
use App\Models\Document;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientArchiveRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_be_archived_listed_and_restored_without_hard_delete(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create(['code' => 'ARCH-001', 'name' => 'Archive Test', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('deleteClient', $client->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'is_active' => false]);
        $this->assertFalse(app(ClientService::class)->filteredQuery($user)->whereKey($client->id)->exists());
        $this->assertTrue(app(ClientService::class)->filteredQuery($user, ['archived' => true])->whereKey($client->id)->exists());

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('showArchivedClients')
            ->assertSee('Archive Test')
            ->call('restoreClient', $client->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'is_active' => true]);
    }

    public function test_active_and_archived_rows_do_not_depend_on_a_blade_local_initials_variable(): void
    {
        $view = \Tests\Support\AdministrationPhase7Source::clientsView();

        $this->assertStringNotContainsString('$rowInitials', $view);
        $this->assertSame(2, substr_count($view, '<x-ui.client-logo :client="$clientRow"'));
    }

    public function test_archived_client_can_be_permanently_erased_without_deleting_linked_history(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create([
            'code' => 'PURGE-001',
            'name' => 'Permanent Delete Test',
            'email' => 'delete-me@example.test',
            'contact_name' => 'Old Contact',
            'created_by' => $user->id,
            'is_active' => false,
            'archived_at' => now()->subDay(),
            'archived_by' => $user->id,
        ]);

        ClientShippingAddress::create([
            'client_id' => $client->id,
            'label' => 'Main',
            'address_line1' => '123 Client Street',
            'city' => 'Dhaka',
            'state' => 'Dhaka',
            'zip' => '1200',
            'country' => 'Bangladesh',
            'is_default' => true,
            'sort_order' => 0,
        ]);

        $document = Document::create([
            'document_number' => 'DOC-PURGE-001',
            'client_id' => $client->id,
            'name' => 'Historical document.pdf',
            'path' => 'documents/history.pdf',
            'size' => 10,
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('showArchivedClients')
            ->call('openPermanentDeleteClient', $client->id)
            ->assertSee('Permanently delete client?')
            ->assertSee('Historical linked records must not be cascade-deleted.')
            ->set('deleteArchivedClientConfirmed', true)
            ->call('permanentlyDeleteClient')
            ->assertHasNoErrors();

        $client->refresh();
        $this->assertNotNull($client->purged_at);
        $this->assertFalse($client->is_active);
        $this->assertSame('Deleted client #'.$client->id, $client->name);
        $this->assertNull($client->email);
        $this->assertNull($client->contact_name);
        $this->assertDatabaseMissing('client_shipping_addresses', ['client_id' => $client->id]);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'client_id' => $client->id]);
        $this->assertFalse(app(ClientService::class)->filteredQuery($user, ['archived' => true])->whereKey($client->id)->exists());
    }
}
