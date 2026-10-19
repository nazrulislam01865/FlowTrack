<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;

class ProfileImageController extends Controller
{
    public function __invoke(string $user, string $filename)
    {
        $path = 'profile-images/'.$user.'/'.$filename;

        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
