<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Modules\OrderFlow\Data\Models\Client;
use Illuminate\Support\Facades\DB;

class UpdateClientAction
{
    public function execute(Client $client, array $data): Client
    {
        return DB::transaction(function () use ($client, $data): Client {
            $client->update($data);

            return $client->refresh();
        });
    }
}
