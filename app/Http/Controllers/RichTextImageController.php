<?php

namespace App\Http\Controllers;

use App\Services\SecureDocumentStorage;
use App\Support\StoredFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RichTextImageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'max:10240', 'mimetypes:image/png,image/jpeg,image/webp,image/gif'],
        ]);

        $file = $data['image'];
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };

        // Preserve the existing route filename contract while storing the
        // physical object privately after quarantine/inspection.
        $filename = Str::uuid().'.'.$extension;
        $stored = app(SecureDocumentStorage::class)->store($file, 'rich-text-images');
        $storedPath = $stored['path'];
        if (basename($storedPath) !== $filename) {
            // The secure storage service intentionally owns randomized physical
            // names, so return that generated name rather than exposing input.
            $filename = basename($storedPath);
        }

        return response()->json([
            'url' => route('rich-text-images.show', ['filename' => $filename], false),
        ]);
    }

    public function show(string $filename): StreamedResponse
    {
        $path = $this->resolvedImagePath($filename);
        return StoredFileResponse::inline($path, $filename);
    }

    public function download(string $filename): StreamedResponse
    {
        $path = $this->resolvedImagePath($filename);
        return StoredFileResponse::download($path, $filename);
    }

    private function resolvedImagePath(string $filename): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9-]+\.(?:png|jpe?g|webp|gif)$/i', $filename) === 1, 404);
        $path = 'rich-text-images/'.$filename;
        abort_unless(app(SecureDocumentStorage::class)->locate($path) !== null, 404);
        return $path;
    }
}
