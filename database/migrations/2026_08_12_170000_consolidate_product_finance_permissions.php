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

            $productActions = $this->mergedActions((int) $role->id, ['products', 'inquiry_products', 'order_products'], $allActions);
            if ($productActions === []) {
                $productActions = $this->mergedActions((int) $role->id, ['inquiries', 'jobs'], $allActions);
                $hadParentEdit = in_array('edit_own', $productActions, true) || in_array('edit_all', $productActions, true);
                if ($hadParentEdit) {
                    $productActions = array_values(array_unique(array_merge($productActions, ['view', 'create', 'delete'])));
                }
            }
            if ($isAdmin || in_array('manage', $productActions, true)) $productActions = $allActions;

            $financeActions = $this->mergedActions((int) $role->id, ['finance', 'inquiry_finance', 'order_finance'], $allActions);
            if ($financeActions === []) $financeActions = $isAdmin ? $allActions : ($hasLegacyFinanceAccess ? ['view'] : []);
            if ($isAdmin || in_array('manage', $financeActions, true)) $financeActions = $allActions;

            DB::table('role_module_access')->updateOrInsert(
                ['role_id' => $role->id, 'module_code' => 'products'],
                [
                    'record_scope' => $productActions ? 'all_records' : 'none',
                    'actions' => json_encode($productActions),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('role_module_access')->updateOrInsert(
                ['role_id' => $role->id, 'module_code' => 'finance'],
                [
                    'record_scope' => $financeActions ? 'all_records' : 'none',
                    'actions' => json_encode($financeActions),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        DB::table('role_module_access')
            ->whereIn('module_code', ['inquiry_products', 'inquiry_finance', 'order_products', 'order_finance'])
            ->delete();
    }

    public function down(): void
    {
        if (!Schema::hasTable('role_module_access')) return;

        foreach (DB::table('roles')->get() as $role) {
            foreach ([
                'inquiry_products' => 'products',
                'order_products' => 'products',
                'inquiry_finance' => 'finance',
                'order_finance' => 'finance',
            ] as $legacyModule => $sourceModule) {
                $source = DB::table('role_module_access')
                    ->where('role_id', $role->id)
                    ->where('module_code', $sourceModule)
                    ->first(['record_scope', 'actions']);
                if (!$source) continue;

                DB::table('role_module_access')->updateOrInsert(
                    ['role_id' => $role->id, 'module_code' => $legacyModule],
                    [
                        'record_scope' => $source->record_scope,
                        'actions' => $source->actions,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    private function mergedActions(int $roleId, array $modules, array $allowed): array
    {
        $merged = [];
        foreach (DB::table('role_module_access')->where('role_id', $roleId)->whereIn('module_code', $modules)->get(['actions']) as $row) {
            $actions = json_decode((string) ($row->actions ?? '[]'), true);
            if (is_array($actions)) $merged = array_merge($merged, $actions);
        }

        return array_values(array_unique(array_intersect($allowed, $merged)));
    }
};
