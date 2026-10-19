<?php

namespace App\Actions\Inquiries;

use App\Models\Client;
use App\Models\User;
use App\Services\AccessControlService;

final class UpdateInquiryClientContact
{
    public function handle(Client $client, array $data, User $actor): Client
    {
        $access = app(AccessControlService::class);
        $canEdit = $access->isAdministrator($actor)
            || $access->canEditAll($actor, 'clients')
            || ($access->canEditOwn($actor, 'clients') && (int) ($client->account_manager_id ?? 0) === (int) $actor->id);
        abort_unless($canEdit, 403);

        $client->update([
            'contact_name' => trim((string) $data['name']),
            'email' => trim((string) ($data['email'] ?? '')) ?: $client->email,
            'phone' => trim((string) ($data['phone'] ?? '')) ?: $client->phone,
        ]);

        return $client->refresh();
    }
}
