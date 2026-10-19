<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_records') || ! Schema::hasTable('workspaces')) return;

        $groups = [
            'invoice_type' => [
                ['code' => 'IVT-FINAL', 'name' => 'Final invoice', 'sort_order' => 10, 'metadata' => ['invoice_kind' => 'final']],
                ['code' => 'IVT-DEPOSIT', 'name' => 'Deposit invoice', 'sort_order' => 20, 'metadata' => ['invoice_kind' => 'deposit']],
                ['code' => 'IVT-PROGRESS', 'name' => 'Progress invoice', 'sort_order' => 30, 'metadata' => ['invoice_kind' => 'progress']],
            ],
            'payment_term' => [
                ['code' => 'PTR-DUE', 'name' => 'Due on receipt', 'sort_order' => 10, 'metadata' => ['days' => 0]],
                ['code' => 'PTR-007', 'name' => 'Net 7 days', 'sort_order' => 20, 'metadata' => ['days' => 7]],
                ['code' => 'PTR-015', 'name' => 'Net 15 days', 'sort_order' => 30, 'metadata' => ['days' => 15]],
                ['code' => 'PTR-030', 'name' => 'Net 30 days', 'sort_order' => 40, 'metadata' => ['days' => 30]],
                ['code' => 'PTR-045', 'name' => 'Net 45 days', 'sort_order' => 50, 'metadata' => ['days' => 45]],
                ['code' => 'PTR-060', 'name' => 'Net 60 days', 'sort_order' => 60, 'metadata' => ['days' => 60]],
            ],
            'payment_method' => [
                ['code' => 'PMT-BANK', 'name' => 'Bank transfer', 'sort_order' => 10],
                ['code' => 'PMT-CARD', 'name' => 'Credit card', 'sort_order' => 20],
                ['code' => 'PMT-CASH', 'name' => 'Cash', 'sort_order' => 30],
                ['code' => 'PMT-CHEQUE', 'name' => 'Cheque', 'sort_order' => 40],
                ['code' => 'PMT-OTHER', 'name' => 'Other', 'sort_order' => 50],
            ],
            'received_account' => [
                ['code' => 'RCA-USD', 'name' => 'USD Operating Account', 'sort_order' => 10],
                ['code' => 'RCA-EUR', 'name' => 'EUR Operating Account', 'sort_order' => 20],
                ['code' => 'RCA-GBP', 'name' => 'GBP Operating Account', 'sort_order' => 30],
                ['code' => 'RCA-CNY', 'name' => 'CNY Operating Account', 'sort_order' => 40],
                ['code' => 'RCA-HKD', 'name' => 'HKD Operating Account', 'sort_order' => 50],
            ],
        ];

        foreach (DB::table('workspaces')->pluck('id') as $workspaceId) {
            foreach ($groups as $type => $records) {
                foreach ($records as $record) {
                    DB::table('master_records')->updateOrInsert(
                        ['workspace_id' => $workspaceId, 'type' => $type, 'code' => $record['code']],
                        [
                            'parent_id' => null,
                            'name' => $record['name'],
                            'description' => null,
                            'metadata' => isset($record['metadata']) ? json_encode($record['metadata']) : null,
                            'status' => 'active',
                            'sort_order' => $record['sort_order'],
                            'created_at' => now(),
                            'updated_at' => now(),
                            'deleted_at' => null,
                        ]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('master_records')) return;

        DB::table('master_records')
            ->whereIn('type', ['invoice_type', 'payment_term', 'payment_method', 'received_account'])
            ->where(function ($query) {
                $query->whereIn('code', [
                    'IVT-FINAL','IVT-DEPOSIT','IVT-PROGRESS',
                    'PTR-DUE','PTR-007','PTR-015','PTR-030','PTR-045','PTR-060',
                    'PMT-BANK','PMT-CARD','PMT-CASH','PMT-CHEQUE','PMT-OTHER',
                    'RCA-USD','RCA-EUR','RCA-GBP','RCA-CNY','RCA-HKD',
                ]);
            })
            ->delete();
    }
};
