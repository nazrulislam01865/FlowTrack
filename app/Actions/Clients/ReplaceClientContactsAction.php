<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use App\Services\AccessControlService;

final class ReplaceClientContactsAction
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    public function execute(User $actor, Client $client, array $contacts): void
    {
        $canEdit = $this->access->isAdministrator($actor)
            || $this->access->canEditAll($actor, 'clients')
            || ($this->access->canEditOwn($actor, 'clients') && (int) ($client->account_manager_id ?? 0) === (int) $actor->id);
        abort_unless($canEdit, 403);

        $client->contacts()->delete();
        foreach ($contacts as $index => $contact) {
            ClientContact::create([
                'client_id' => $client->id,
                'name' => ($contact['name'] ?? '') !== '' ? $contact['name'] : (($contact['email'] ?? '') !== '' ? $contact['email'] : 'Contact '.($index + 1)),
                'job_title' => ($contact['job_title'] ?? '') ?: null,
                'email' => ($contact['email'] ?? '') ?: null,
                'phone' => ($contact['phone'] ?? '') ?: null,
                'is_primary' => $index === 0,
                'sort_order' => $index,
            ]);
        }
    }
}
