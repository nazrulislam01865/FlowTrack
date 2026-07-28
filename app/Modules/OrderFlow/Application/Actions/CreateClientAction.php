<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Modules\OrderFlow\Data\Models\Client;

class CreateClientAction
{
    public function execute(int $workspaceId, array $data): Client
    {
        $next = Client::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('code', 'like', 'CL-%')
            ->pluck('code')
            ->map(fn (string $code): int => (int) substr($code, 3))
            ->max() + 1;

        return Client::create([
            ...$data,
            'workspace_id' => $workspaceId,
            'code' => filled($data['code'] ?? null)
                ? $data['code']
                : 'CL-'.str_pad((string) max(1, $next), 3, '0', STR_PAD_LEFT),
        ]);
    }
}
