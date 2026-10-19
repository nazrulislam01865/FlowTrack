<?php

use App\Support\SupplierShortCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_records')) return;

        $suppliers = DB::table('master_records')
            ->where('type', 'supplier')
            ->whereNull('deleted_at')
            ->orderBy('workspace_id')
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'name', 'metadata']);

        $usedByWorkspace = [];
        foreach ($suppliers as $supplier) {
            $metadata = json_decode((string) ($supplier->metadata ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $stored = SupplierShortCode::normalize((string) ($metadata['short_code'] ?? ''));
            if ($stored === '') continue;
            $usedByWorkspace[(int) $supplier->workspace_id][strtolower($stored)] = true;
        }

        $updates = [];
        foreach ($suppliers as $supplier) {
            $metadata = json_decode((string) ($supplier->metadata ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            if (SupplierShortCode::normalize((string) ($metadata['short_code'] ?? '')) !== '') continue;

            $workspaceId = (int) $supplier->workspace_id;
            $base = SupplierShortCode::fromName((string) $supplier->name, (int) $supplier->id);
            $candidate = $base;
            $suffix = 2;

            while (isset($usedByWorkspace[$workspaceId][strtolower($candidate)])) {
                $candidate = substr($base, 0, max(1, 40 - strlen((string) $suffix))).$suffix;
                $suffix++;
            }

            $usedByWorkspace[$workspaceId][strtolower($candidate)] = true;
            $updates[] = [
                'id' => (int) $supplier->id,
                'short_code' => $candidate,
            ];
        }

        // This is an update-only backfill. Do not use upsert here: master_records
        // has required insert columns (workspace_id, type, code, name), and MySQL
        // validates those columns even when ON DUPLICATE KEY would update an
        // existing row. Updating in bounded batches also avoids runtime N+1
        // behavior while preserving every other metadata key in the database.
        foreach (array_chunk($updates, 500) as $chunk) {
            $caseSql = [];
            $bindings = [];
            $ids = [];

            foreach ($chunk as $update) {
                $caseSql[] = 'WHEN ? THEN ?';
                $bindings[] = $update['id'];
                $bindings[] = $update['short_code'];
                $ids[] = $update['id'];
            }

            if ($ids === []) continue;

            $idPlaceholders = implode(', ', array_fill(0, count($ids), '?'));
            $bindings[] = now();
            array_push($bindings, ...$ids);

            DB::update(
                'UPDATE `master_records`
                 SET `metadata` = JSON_SET(
                        IF(JSON_TYPE(`metadata`) = \'OBJECT\', `metadata`, JSON_OBJECT()),
                        \'$.short_code\',
                        CASE `id` '.implode(' ', $caseSql).' END,
                        \'$.short_code_source\',
                        \'generated\'
                     ),
                     `updated_at` = ?
                 WHERE `id` IN ('.$idPlaceholders.')
                   AND COALESCE(TRIM(JSON_UNQUOTE(JSON_EXTRACT(`metadata`, \'$.short_code\'))), \'\') = \'\'',
                $bindings,
            );
        }
    }

    public function down(): void
    {
        // Supplier short codes are business data. Do not erase them on rollback.
    }
};
