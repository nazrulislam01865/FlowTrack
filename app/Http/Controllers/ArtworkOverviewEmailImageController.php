<?php

namespace App\Http\Controllers;

use App\Services\SecureDocumentStorage;
use App\Support\StoredFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** View-only access to a single pasted image from an emailed order overview. */
final class ArtworkOverviewEmailImageController extends Controller
{
    public function __invoke(string $filename): StreamedResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9-]+\.(?:png|jpe?g|webp|gif)$/i', $filename) === 1, 404);
        $path = 'rich-text-images/'.$filename;
        abort_unless(app(SecureDocumentStorage::class)->locate($path) !== null, 404);

        return StoredFileResponse::inline($path, $filename);
    }
}
