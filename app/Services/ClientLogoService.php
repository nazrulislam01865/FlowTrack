<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ClientLogoService
{
    public function replace(Client $client, UploadedFile $logo): Client
    {
        $disk = Storage::disk('public');
        $oldPath = (string) ($client->logo_path ?? '');
        $path = $logo->storePublicly('client-logos/'.$client->id, 'public');

        try {
            $client->update(['logo_path' => $path]);
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        if ($oldPath !== '' && $oldPath !== $path) {
            $disk->delete($oldPath);
        }

        return $client->refresh();
    }

    public function remove(Client $client): Client
    {
        $oldPath = (string) ($client->logo_path ?? '');
        $client->update(['logo_path' => null]);

        if ($oldPath !== '') {
            Storage::disk('public')->delete($oldPath);
        }

        return $client->refresh();
    }
}
