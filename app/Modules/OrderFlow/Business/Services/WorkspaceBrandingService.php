<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Data\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class WorkspaceBrandingService
{
    /**
     * @return array<string, mixed>
     */
    public function forRequest(Request $request): array
    {
        if (! Schema::hasTable('workspaces') || ! Schema::hasColumn('workspaces', 'logo_path') || ! Schema::hasColumn('workspaces', 'favicon_path')) {
            return $this->defaults();
        }

        $workspace = $request->user()?->currentWorkspaceMembership()?->workspace;

        if (! $workspace) {
            $workspace = Workspace::query()
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNotNull('logo_path')->orWhereNotNull('favicon_path'))
                ->latest('updated_at')
                ->first()
                ?? Workspace::query()->where('is_active', true)->oldest('id')->first();
        }

        return $this->forWorkspace($workspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function forWorkspace(?Workspace $workspace): array
    {
        if (! $workspace) {
            return $this->defaults();
        }

        $version = $workspace->updated_at?->timestamp ?? time();
        $logoUrl = $this->publicUrl($workspace->logo_path, $version);
        $faviconUrl = $this->publicUrl($workspace->favicon_path, $version) ?: asset('favicon.ico');

        return [
            'workspaceId' => (int) $workspace->id,
            'workspaceName' => (string) $workspace->name,
            'logoUrl' => $logoUrl,
            'faviconUrl' => $faviconUrl,
            'hasCustomLogo' => filled($workspace->logo_path),
            'hasCustomFavicon' => filled($workspace->favicon_path),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'workspaceId' => null,
            'workspaceName' => 'FlowTrack',
            'logoUrl' => null,
            'faviconUrl' => asset('favicon.ico'),
            'hasCustomLogo' => false,
            'hasCustomFavicon' => false,
        ];
    }

    private function publicUrl(?string $path, int $version): ?string
    {
        if (! filled($path)) {
            return null;
        }

        $url = Storage::disk('public')->url($path);
        return $url.(str_contains($url, '?') ? '&' : '?').'v='.$version;
    }
}
