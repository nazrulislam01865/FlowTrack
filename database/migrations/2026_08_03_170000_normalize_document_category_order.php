<?php

use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $workspaceIds = DB::table('master_records')
            ->where('type', MasterDataType::DocumentCategory->value)
            ->whereNull('deleted_at')
            ->distinct()
            ->orderBy('workspace_id')
            ->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            $ids = DB::table('master_records')
                ->where('workspace_id', $workspaceId)
                ->where('type', MasterDataType::DocumentCategory->value)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids as $index => $id) {
                DB::table('master_records')->where('id', $id)->update(['sort_order' => $index + 1]);
            }
        }
    }

    public function down(): void
    {
        // The previous values cannot be reconstructed safely. Keeping the
        // normalized order is harmless if this migration is rolled back.
    }
};
