<?php
namespace App\Observers;

use App\Models\Workspace;
use App\Services\SupplierBridge\MarkBrandDirty;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Cache;

/** A workspace branding change is a supplier header change, not a per-page query. */
final class SupplierWorkspaceBrandObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(Workspace $workspace): void
    {
        if (! $workspace->wasChanged(['name', 'logo_path', 'favicon_path', 'supplier_portal_footer'])) return;
        // The cache key is the configured workspace ID, even when branding
        // falls back to another active workspace. Invalidate that same key.
        Cache::forget('flowtrack:branding:workspace:'.max(1, (int) config('flowtrack.workspace_id', 1)));
        if (! config('supplier_bridge.enabled')) return;
        // BrandingService::current() uses the configured workspace (falling
        // back to the first active one). Only that brand is shown by FlowTracker,
        // and it is shared by every supplier regardless of supplier workspace.
        $brandingWorkspaceId = (int) app(\App\Services\BrandingService::class)->current()['workspace_id'];
        if ($brandingWorkspaceId !== (int) $workspace->id) return;
        \App\Models\MasterRecord::query()->ofType('supplier')
            ->select(['id'])->chunkById(100, function ($suppliers): void {
                app(MarkBrandDirty::class)->supplierIds($suppliers->pluck('id')->all());
            });
    }
}
