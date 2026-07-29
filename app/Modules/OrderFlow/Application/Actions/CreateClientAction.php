<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Data\Models\Client;

class CreateClientAction
{
    public function __construct(private readonly IdentifierGenerator $identifiers)
    {
    }

    public function execute(int $workspaceId, array $data): Client
    {
        // Client identifiers are owned by the system. Any incoming code value is
        // discarded so browser users and API callers cannot choose or alter it.
        unset($data['code']);

        return Client::create([
            ...$data,
            'workspace_id' => $workspaceId,
            'code' => $this->identifiers->client($workspaceId),
        ]);
    }
}
