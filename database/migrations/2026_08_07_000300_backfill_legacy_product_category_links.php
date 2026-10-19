<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('master_records') || !Schema::hasColumn('master_records', 'parent_id')) return;

        $products = DB::table('master_records')
            ->where('type', 'product')
            ->whereNull('deleted_at')
            ->whereNull('parent_id')
            ->whereNotNull('description')
            ->get(['id', 'workspace_id', 'description']);

        foreach ($products as $product) {
            $description = trim((string) $product->description);
            if ($description === '') continue;

            $categoryName = trim(explode(' ·', $description, 2)[0]);
            if ($categoryName === '') continue;

            $categoryId = DB::table('master_records')
                ->where('workspace_id', $product->workspace_id)
                ->where('type', 'product_category')
                ->where('name', $categoryName)
                ->where('status', 'active')
                ->whereNull('deleted_at')
                ->value('id');

            if (!$categoryId) continue;

            DB::table('master_records')
                ->where('id', $product->id)
                ->whereNull('parent_id')
                ->update([
                    'parent_id' => $categoryId,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // This migration repairs missing Product -> Product Category links.
        // The links are valid application data and should not be removed.
    }
};
