<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles') || !Schema::hasTable('role_module_access')) return;

        $allActions = ['view','create','edit_own','edit_all','delete','assign','link','export','manage'];
        $financialFields = [
            'supplier_cost', 'gross_margin', 'client_target_price', 'confirmed_selling_price',
            'invoice_amount', 'payment_history', 'supplier_banking_details',
        ];

        foreach (DB::table('roles')->get() as $role) {
            $isAdmin = in_array((string) $role->slug, ['super-admin', 'admin', 'administrator'], true);
            $sensitiveFields = json_decode((string) ($role->sensitive_fields ?? '[]'), true);
            $sensitiveFields = is_array($sensitiveFields) ? $sensitiveFields : [];
            $hasLegacyFinanceAccess = count(array_intersect($financialFields, $sensitiveFields)) > 0;

            if (!DB::table('role_module_access')->where('role_id', $role->id)->where('module_code', 'products')->exists()) {
                $parentActions = [];
                foreach (['inquiries', 'jobs'] as $parentModule) {
                    $parent = DB::table('role_module_access')
                        ->where('role_id', $role->id)
                        ->where('module_code', $parentModule)
                        ->first(['actions']);
                    $actions = $parent ? json_decode((string) ($parent->actions ?? '[]'), true) : [];
                    if (is_array($actions)) $parentActions = array_merge($parentActions, $actions);
                }
                $parentActions = array_values(array_unique(array_intersect($allActions, $parentActions)));

                if ($isAdmin || in_array('manage', $parentActions, true)) {
                    $productActions = $allActions;
                } else {
                    $productActions = $parentActions;
                    $hadParentEdit = in_array('edit_own', $parentActions, true) || in_array('edit_all', $parentActions, true);
                    if ($hadParentEdit) {
                        $productActions = array_values(array_unique(array_merge($productActions, ['view', 'create', 'delete'])));
                    }
                }

                DB::table('role_module_access')->insert([
                    'role_id' => $role->id,
                    'module_code' => 'products',
                    'record_scope' => $productActions ? 'all_records' : 'none',
                    'actions' => json_encode($productActions),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (!DB::table('role_module_access')->where('role_id', $role->id)->where('module_code', 'finance')->exists()) {
                // Finance stays conservative by default. Administrators keep full
                // access, while a legacy sensitive-finance grant retains View.
                $financeActions = $isAdmin ? $allActions : ($hasLegacyFinanceAccess ? ['view'] : []);

                DB::table('role_module_access')->insert([
                    'role_id' => $role->id,
                    'module_code' => 'finance',
                    'record_scope' => $financeActions ? 'all_records' : 'none',
                    'actions' => json_encode($financeActions),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('role_module_access')) return;

        DB::table('role_module_access')
            ->whereIn('module_code', ['products', 'finance'])
            ->delete();
    }
};
