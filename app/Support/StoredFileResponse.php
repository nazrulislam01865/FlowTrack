<?php

namespace App\Support;

use App\Services\SecureDocumentStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StoredFileResponse
{
    private const FORCE_DOWNLOAD_EXTENSIONS = ['ai', 'eps', 'esp', 'ps', 'cdr'];

    public static function inline(string $path, string $originalName, ?string $mimeType = null): StreamedResponse
    {
        return self::make($path, $originalName, $mimeType, 'inline');
    }

    public static function download(string $path, string $originalName, ?string $mimeType = null): StreamedResponse
    {
        return self::make($path, $originalName, $mimeType, 'attachment');
    }

    private static function make(string $path, string $originalName, ?string $mimeType, string $disposition): StreamedResponse
    {
        $resolved = app(SecureDocumentStorage::class)->locate($path);
        abort_unless($resolved !== null, 404, 'The requested attachment could not be found.');

        $disk = Storage::disk($resolved['disk']);
        $resolvedPath = $resolved['path'];
        $filename = self::filename($originalName, $resolvedPath);
        $fallback = self::asciiFallback($filename);
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, self::FORCE_DOWNLOAD_EXTENSIONS, true)) {
            $disposition = 'attachment';
        }

        $type = self::mimeType($filename, $mimeType);
        if ($type === '') {
            try {
                $type = (string) $disk->mimeType($resolvedPath);
            } catch (\Throwable) {
                $type = '';
            }
        }
        if ($type === '') $type = 'application/octet-stream';

        // Never advertise PostScript-like active formats for inline rendering.
        if (in_array($extension, self::FORCE_DOWNLOAD_EXTENSIONS, true)) {
            $type = 'application/octet-stream';
        }

        $headers = [
            'Content-Type' => $type,
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        try {
            $size = $disk->size($resolvedPath);
            if (is_int($size) && $size >= 0) $headers['Content-Length'] = (string) $size;
        } catch (\Throwable) {
            // Remote/object disks may not expose size cheaply. Streaming still works.
        }

        return response()->stream(function () use ($disk, $resolvedPath): void {
            $stream = $disk->readStream($resolvedPath);
            if ($stream === false) return;
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) fclose($stream);
            }
        }, 200, $headers);
    }

    public static function mimeType(string $filename, ?string $storedMimeType = null): string
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $storedMimeType = strtolower(trim((string) $storedMimeType));

        // SecureDocumentStorage verifies raster signatures before persisting the
        // MIME type. Prefer that verified value so a harmless converted image
        // whose old filename extension was retained is served correctly under
        // X-Content-Type-Options: nosniff.
        if (in_array($storedMimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return $storedMimeType;
        }

        $known = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'zip' => 'application/zip',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'ai' => 'application/postscript',
            'eps' => 'application/postscript',
            'esp' => 'application/octet-stream',
            'ps' => 'application/postscript',
            'cdr' => 'application/octet-stream',
        ];

        if ($extension !== '' && isset($known[$extension])) return $known[$extension];
        return $storedMimeType;
    }

    public static function mustDownload(string $filename): bool
    {
        return in_array(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), self::FORCE_DOWNLOAD_EXTENSIONS, true);
    }

    private static function filename(string $originalName, string $path): string
    {
        $name = trim(str_replace('\\', '/', $originalName));
        $name = basename($name);
        return $name !== '' && $name !== '.' ? $name : basename($path);
    }

    private static function asciiFallback(string $filename): string
    {
        $fallback = Str::ascii($filename);
        $fallback = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $fallback) ?: 'attachment';
        $fallback = trim($fallback, " .\t\n\r\0\x0B");
        return $fallback !== '' ? $fallback : 'attachment';
    }
}
