<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Services\AccessControlService;

final class ClientPolicy
{
    public function __construct(private readonly AccessControlService $access)
    {
    }

    public function viewAny(User $user): bool { return $this->access->can($user, 'clients', 'view'); }
    public function view(User $user, Client $client): bool { return $this->access->applyClientScope(Client::query()->whereKey($client->id), $user)->exists(); }
    public function create(User $user): bool { return $this->access->can($user, 'clients', 'create'); }
    public function update(User $user, Client $client): bool { return $this->access->can($user, 'clients', 'edit') && $this->view($user, $client); }
    public function delete(User $user, Client $client): bool { return $this->access->can($user, 'clients', 'delete') && $this->view($user, $client); }
}
