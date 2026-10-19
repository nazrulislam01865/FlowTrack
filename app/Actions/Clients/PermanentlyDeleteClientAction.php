<?php
namespace App\Actions\Clients;
use App\Models\User;
use App\Services\ClientService;
class PermanentlyDeleteClientAction { public function __construct(private readonly ClientService $clients) {} public function execute(User $actor, int $clientId): string { return $this->clients->permanentlyDeleteArchived($actor, $clientId); } }
