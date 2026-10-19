<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Product catalogue and all Inquiry/Order Product-row capabilities are
        // controlled by catalog_products. Remove the obsolete duplicate module.
        DB::table('role_module_access')
            ->where('module_code', 'products')
            ->delete();
    }

    public function down(): void
    {
        // Intentionally not recreated: the legacy Product Lines module is no
        // longer part of the application permission model.
    }
};
