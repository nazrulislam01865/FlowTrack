<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_supplier_links')) {
            Schema::create('product_supplier_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('master_records')->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained('master_records')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['product_id', 'supplier_id'], 'product_supplier_links_product_supplier_uq');
                $table->index(['workspace_id', 'supplier_id'], 'product_supplier_links_workspace_supplier_idx');
                $table->index(['workspace_id', 'product_id'], 'product_supplier_links_workspace_product_idx');
            });
        }

        if (! Schema::hasTable('master_records')) {
            return;
        }

        $supplierIdsByWorkspace = DB::table('master_records')
            ->where('type', 'supplier')
            ->whereNull('deleted_at')
            ->get(['id', 'workspace_id', 'code', 'name', 'metadata'])
            ->groupBy('workspace_id');

        $productsByWorkspace = DB::table('master_records')
            ->where('type', 'product')
            ->whereNull('deleted_at')
            ->get(['id', 'workspace_id', 'code', 'name', 'metadata'])
            ->groupBy('workspace_id');

        foreach ($productsByWorkspace as $workspaceId => $products) {
            $suppliers = collect($supplierIdsByWorkspace->get($workspaceId, collect()));
            if ($suppliers->isEmpty()) {
                continue;
            }

            $validSupplierIds = $suppliers->pluck('id')->map(fn ($id) => (int) $id)->flip();
            $supplierById = $suppliers->keyBy(fn ($supplier) => (int) $supplier->id);
            $productById = collect($products)->keyBy(fn ($product) => (int) $product->id);
            $productByCode = collect($products)->flatMap(function ($product): array {
                $codes = array_filter([
                    strtoupper(trim((string) $product->code)),
                    'PRD-'.str_pad((string) $product->id, 6, '0', STR_PAD_LEFT),
                    'PRD-'.(int) $product->id,
                ]);
                return collect($codes)->mapWithKeys(fn ($code) => [$code => $product])->all();
            });

            $rows = [];
            foreach ($products as $product) {
                $metadata = json_decode((string) ($product->metadata ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $supplierIds = $this->normaliseIds($metadata['supplier_ids'] ?? []);
                $supplierIds[] = (int) ($metadata['supplier_id'] ?? 0);
                $supplierIds[] = (int) ($metadata['default_supplier_id'] ?? 0);

                foreach (array_unique(array_filter($supplierIds)) as $supplierId) {
                    if (! $validSupplierIds->has((int) $supplierId)) {
                        continue;
                    }
                    $rows[(int) $product->id.':'.(int) $supplierId] = [
                        'workspace_id' => (int) $workspaceId,
                        'product_id' => (int) $product->id,
                        'supplier_id' => (int) $supplierId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Compatibility with any older/manual reverse tags kept on Supplier metadata.
            foreach ($suppliers as $supplier) {
                $metadata = json_decode((string) ($supplier->metadata ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                $productIds = $this->normaliseIds($metadata['product_ids'] ?? ($metadata['assigned_product_ids'] ?? []));
                foreach ($productIds as $productId) {
                    if (! $productById->has((int) $productId)) continue;
                    $rows[(int) $productId.':'.(int) $supplier->id] = [
                        'workspace_id' => (int) $workspaceId,
                        'product_id' => (int) $productId,
                        'supplier_id' => (int) $supplier->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $rawCodes = $metadata['product_codes'] ?? ($metadata['assigned_product_codes'] ?? []);
                $codes = is_array($rawCodes)
                    ? $rawCodes
                    : (preg_split('/[\s,;|]+/', trim((string) $rawCodes), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                foreach ($codes as $code) {
                    $product = $productByCode->get(strtoupper(trim((string) $code)));
                    if (! $product) continue;
                    $rows[(int) $product->id.':'.(int) $supplier->id] = [
                        'workspace_id' => (int) $workspaceId,
                        'product_id' => (int) $product->id,
                        'supplier_id' => (int) $supplier->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                DB::table('product_supplier_links')->insertOrIgnore($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier_links');
    }

    /** @return array<int,int> */
    private function normaliseIds(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode(trim($raw), true);
            $raw = is_array($decoded)
                ? $decoded
                : (preg_split('/[\s,;|]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        } elseif (is_numeric($raw)) {
            $raw = [$raw];
        } elseif (! is_array($raw)) {
            $raw = [];
        }

        return collect($raw)
            ->flatten()
            ->map(fn ($value) => is_array($value) ? ($value['id'] ?? 0) : $value)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }
};
