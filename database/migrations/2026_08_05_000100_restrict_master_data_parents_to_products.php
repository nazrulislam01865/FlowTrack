<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('master_records') || !Schema::hasColumn('master_records', 'parent_id')) return;

        DB::table('master_records')
            ->where('type', '!=', 'product')
            ->whereNotNull('parent_id')
            ->update(['parent_id' => null, 'updated_at' => now()]);

        $products = DB::table('master_records')
            ->where('type', 'product')
            ->whereNull('deleted_at')
            ->whereNotNull('parent_id')
            ->get(['id', 'workspace_id', 'parent_id']);

        foreach ($products as $product) {
            $validParent = DB::table('master_records')
                ->where('id', $product->parent_id)
                ->where('workspace_id', $product->workspace_id)
                ->where('type', 'product_category')
                ->whereNull('deleted_at')
                ->exists();

            if (!$validParent) {
                DB::table('master_records')->where('id', $product->id)->update([
                    'parent_id' => null,
                    'updated_at' => now(),
                ]);
            }
        }

        if (!Schema::hasTable('master_values')) return;

        $workspaces = DB::table('master_records')->whereNull('deleted_at')->distinct()->pluck('workspace_id');
        $legacyProducts = DB::table('master_values')
            ->where('group_key', 'products')
            ->whereNotNull('parent_id')
            ->get(['code', 'parent_id']);

        foreach ($workspaces as $workspaceId) {
            foreach ($legacyProducts as $legacyProduct) {
                $categoryCode = DB::table('master_values')
                    ->where('id', $legacyProduct->parent_id)
                    ->where('group_key', 'product_categories')
                    ->value('code');

                if (!$categoryCode) continue;

                $categoryId = DB::table('master_records')
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'product_category')
                    ->where('code', $categoryCode)
                    ->whereNull('deleted_at')
                    ->value('id');

                if (!$categoryId) continue;

                DB::table('master_records')
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'product')
                    ->where('code', $legacyProduct->code)
                    ->whereNull('deleted_at')
                    ->whereNull('parent_id')
                    ->update([
                        'parent_id' => $categoryId,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Parent links removed from non-product records were invalid by design
        // and are intentionally not recreated.
    }
};
