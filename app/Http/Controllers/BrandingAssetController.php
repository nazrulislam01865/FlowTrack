<?php

namespace App\Http\Controllers;

use App\Services\BrandingService;
use Illuminate\Support\Facades\Storage;

class BrandingAssetController extends Controller
{
    public function __invoke(string $type, string $filename, ?string $workspace = null)
    {
        abort_unless(in_array($type, ['logo', 'favicon'], true), 404);
        abort_unless($filename === basename($filename), 404);

        // Legacy URLs still use the configured workspace. New supplier
        // branding URLs specify the correct workspace explicitly.
        $workspaceId = $workspace !== null ? (int) $workspace
            : (int) app(BrandingService::class)->current()['workspace_id'];
        abort_unless($workspaceId > 0, 404);
        $path = 'branding/'.$workspaceId.'/'.$type.'/'.$filename;

        abort_unless(Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
