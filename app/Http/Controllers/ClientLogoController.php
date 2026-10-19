<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientService;
use Illuminate\Support\Facades\Storage;

class ClientLogoController extends Controller
{
    public function __invoke(Client $client, string $filename)
    {
        app(ClientService::class)->visibleQuery(auth()->user())->whereKey($client->id)->firstOrFail();

        $path = (string) ($client->logo_path ?? '');
        abort_unless($path !== '' && basename($path) === $filename, 404);
        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
