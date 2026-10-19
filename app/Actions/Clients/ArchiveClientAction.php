<?php
namespace App\Actions\Clients;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientService;
class ArchiveClientAction { public function __construct(private readonly ClientService $clients) {} public function execute(User $actor, int $clientId): Client { return $this->clients->archive($actor, $clientId); } }
