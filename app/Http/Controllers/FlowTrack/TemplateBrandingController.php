<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Business\Services\WorkspaceBrandingService;
use App\Modules\OrderFlow\Data\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TemplateBrandingController extends Controller
{
    public function updateLogo(Request $request, WorkspaceBrandingService $branding): JsonResponse
    {
        $this->authorizeAdministrator($request);

        $data = $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $workspace = $this->workspace($request);
        $oldPath = $workspace->logo_path;
        $path = $data['logo']->store("branding/{$workspace->id}", 'public');

        $workspace->forceFill(['logo_path' => $path])->save();
        $this->deleteReplacedFile($oldPath, $path);

        return response()->json([
            'message' => 'System logo updated successfully.',
            'branding' => $branding->forWorkspace($workspace->fresh()),
        ]);
    }

    public function updateFavicon(Request $request, WorkspaceBrandingService $branding): JsonResponse
    {
        $this->authorizeAdministrator($request);

        $data = $request->validate([
            'favicon' => ['required', 'file', 'mimes:ico,png,jpg,jpeg,webp', 'max:1024'],
        ]);

        $workspace = $this->workspace($request);
        $oldPath = $workspace->favicon_path;
        $path = $data['favicon']->store("branding/{$workspace->id}", 'public');

        $workspace->forceFill(['favicon_path' => $path])->save();
        $this->deleteReplacedFile($oldPath, $path);

        return response()->json([
            'message' => 'Favicon updated successfully.',
            'branding' => $branding->forWorkspace($workspace->fresh()),
        ]);
    }

    public function destroyLogo(Request $request, WorkspaceBrandingService $branding): JsonResponse
    {
        $this->authorizeAdministrator($request);

        $workspace = $this->workspace($request);
        $oldPath = $workspace->logo_path;
        $workspace->forceFill(['logo_path' => null])->save();

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'System logo reset to the default FlowTrack mark.',
            'branding' => $branding->forWorkspace($workspace->fresh()),
        ]);
    }

    public function destroyFavicon(Request $request, WorkspaceBrandingService $branding): JsonResponse
    {
        $this->authorizeAdministrator($request);

        $workspace = $this->workspace($request);
        $oldPath = $workspace->favicon_path;
        $workspace->forceFill(['favicon_path' => null])->save();

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Favicon reset to the default FlowTrack icon.',
            'branding' => $branding->forWorkspace($workspace->fresh()),
        ]);
    }

    private function authorizeAdministrator(Request $request): void
    {
        abort_unless($request->user()?->isFlowAdministrator(), 403, 'Only Admin and Super Admin can manage system branding.');
    }

    private function workspace(Request $request): Workspace
    {
        $workspaceId = (int) $request->user()->currentWorkspaceId();
        abort_unless($workspaceId > 0, 403);

        return Workspace::query()->findOrFail($workspaceId);
    }

    private function deleteReplacedFile(?string $oldPath, string $newPath): void
    {
        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }
    }
}
