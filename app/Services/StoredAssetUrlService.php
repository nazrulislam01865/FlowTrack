<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class StoredAssetUrlService
{
    /** @var array<string, bool> */
    private array $exists = [];

    /** @var array<string, bool> */
    private array $secureExists = [];

    public function profileImageUrl(int $userId, ?string $path): ?string
    {
        $path = trim((string) $path);
        if ($userId < 1 || $path === '') return null;

        // ProfileImageController resolves by user id + stored basename. Mirror
        // that exact lookup here so legacy rows keep working when their stored
        // path format differs but the protected asset itself is present.
        $filename = basename($path);
        $servedPath = 'profile-images/'.$userId.'/'.$filename;
        if (! $this->publicFileExists($servedPath)) return null;

        return route('profile-images.show', [
            'user' => $userId,
            'filename' => $filename,
        ], false);
    }

    public function clientLogoUrl(int $clientId, ?string $path): ?string
    {
        $path = trim((string) $path);
        if ($clientId < 1 || $path === '') return null;

        $expectedPrefix = 'client-logos/'.$clientId.'/';
        if (! str_starts_with($path, $expectedPrefix) || ! $this->publicFileExists($path)) return null;

        return route('client-logos.show', [
            'client' => $clientId,
            'filename' => basename($path),
        ], false);
    }

    public function productImageUrl(int $productId, int $workspaceId, ?string $path): ?string
    {
        $path = trim((string) $path);
        if ($productId < 1 || $workspaceId < 1 || $path === '') return null;

        $expectedPrefix = 'product-images/'.$workspaceId.'/'.$productId.'/';
        if (! str_starts_with($path, $expectedPrefix) || ! $this->publicFileExists($path)) return null;

        return route('master-data.product-image', [
            'product' => $productId,
            'filename' => basename($path),
        ], false);
    }

    public function brandingAssetUrl(int $workspaceId, string $type, ?string $path): ?string
    {
        $path = trim((string) $path);
        if ($workspaceId < 1 || ! in_array($type, ['logo', 'favicon'], true) || $path === '') return null;

        $expectedPrefix = 'branding/'.$workspaceId.'/'.$type.'/';
        if (! str_starts_with($path, $expectedPrefix) || ! $this->publicFileExists($path)) return null;

        return '/branding-assets/'.$type.'/'.rawurlencode(basename($path)).'/workspace/'.$workspaceId;
    }

    public function richTextImageUrl(string $filename): ?string
    {
        $filename = trim($filename);
        if (preg_match('/^[A-Za-z0-9-]+\.(?:png|jpe?g|webp|gif)$/i', $filename) !== 1) return null;

        $path = 'rich-text-images/'.$filename;
        if (! $this->secureFileExists($path)) return null;

        return route('rich-text-images.show', ['filename' => $filename], false);
    }

    public function richTextImageDownloadUrl(string $filename): ?string
    {
        $filename = trim($filename);
        if (preg_match('/^[A-Za-z0-9-]+\.(?:png|jpe?g|webp|gif)$/i', $filename) !== 1) return null;

        $path = 'rich-text-images/'.$filename;
        if (! $this->secureFileExists($path)) return null;

        return route('rich-text-images.download', ['filename' => $filename], false);
    }

    public function publicFileExists(?string $path): bool
    {
        $path = trim((string) $path);
        if ($path === '') return false;

        if (array_key_exists($path, $this->exists)) {
            return $this->exists[$path];
        }

        return $this->exists[$path] = Storage::disk('public')->exists($path);
    }

    private function secureFileExists(string $path): bool
    {
        $path = ltrim(trim($path), '/');
        if ($path === '' || str_contains($path, '../')) return false;

        if (array_key_exists($path, $this->secureExists)) {
            return $this->secureExists[$path];
        }

        return $this->secureExists[$path] = app(SecureDocumentStorage::class)->locate($path) !== null;
    }
}
