<?php

namespace App\Livewire\MasterData\Concerns;

use App\Models\Client;
use App\Models\MasterRecord;
use App\Support\Filters\ProductClientOptions;
use App\Services\MasterDataService;
use App\Services\ProductImageService;
use App\Services\ProductOptionImageService;
use App\Services\ProductPriceTableParser;
use App\Services\ProductCategoryDeletionService;
use App\Support\MasterColor;
use App\Support\AttachmentUpload;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ManagesProductCategorySelection
{
    private function normalizeCategorySelectionKey(string $key): ?array
    {
        if (! preg_match('/^(main|product|sub):(\d+)$/', trim($key), $matches)) return null;
        return [$matches[1], (int) $matches[2]];
    }

    public function toggleCategorySelection(string $level, int $id): void
    {
        abort_unless($this->group === 'product_category', 404);
        $type = $this->categoryTypeForLevel($level);
        MasterRecord::query()->forWorkspace(app(MasterDataService::class)->workspaceId())->ofType($type)->findOrFail($id);

        $key = $level.':'.$id;
        $selected = collect($this->selectedCategoryKeys)->map(fn ($value) => (string) $value);
        $this->selectedCategoryKeys = $selected->contains($key)
            ? $selected->reject(fn ($value) => $value === $key)->values()->all()
            : $selected->push($key)->unique()->values()->all();
    }

    public function toggleCategoryPageSelection(array $keys, bool $checked): void
    {
        abort_unless($this->group === 'product_category', 404);
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $parsedKeys = collect($keys)
            ->map(fn ($key) => $this->normalizeCategorySelectionKey((string) $key))
            ->filter()
            ->groupBy(fn ($pair) => $pair[0]);
        $validKeys = collect();
        foreach (['main', 'product', 'sub'] as $level) {
            $ids = collect($parsedKeys->get($level, collect()))->pluck(1)->map(fn ($id) => (int) $id)->unique()->values();
            if ($ids->isEmpty()) continue;
            MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType($this->categoryTypeForLevel($level))
                ->whereIn('id', $ids->all())
                ->pluck('id')
                ->each(fn ($id) => $validKeys->push($level.':'.(int) $id));
        }
        $validKeys = $validKeys->unique()->values();

        if ($validKeys->isEmpty()) return;
        $selected = collect($this->selectedCategoryKeys)->map(fn ($value) => (string) $value);
        $this->selectedCategoryKeys = $checked
            ? $selected->concat($validKeys)->unique()->values()->all()
            : $selected->reject(fn ($value) => $validKeys->contains($value))->values()->all();
    }

    public function clearCategorySelection(): void
    {
        $this->selectedCategoryKeys = [];
    }

    private function selectedCategoryRecords(): \Illuminate\Support\Collection
    {
        if ($this->group !== 'product_category') return collect();
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $grouped = collect($this->selectedCategoryKeys)
            ->map(fn ($key) => $this->normalizeCategorySelectionKey((string) $key))
            ->filter()
            ->groupBy(fn ($pair) => $pair[0]);

        return collect(['main', 'product', 'sub'])->flatMap(function (string $level) use ($grouped, $workspaceId) {
            $ids = collect($grouped->get($level, collect()))->pluck(1)->map(fn ($id) => (int) $id)->unique()->values();
            if ($ids->isEmpty()) return collect();
            return MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType($this->categoryTypeForLevel($level))
                ->whereIn('id', $ids->all())
                ->get()
                ->map(fn (MasterRecord $record) => ['level' => $level, 'record' => $record]);
        })->values();
    }

    public function bulkSetCategoryStatus(string $status): void
    {
        abort_unless($this->group === 'product_category', 404);
        $this->authorizeGroupAction('edit');
        abort_unless(in_array($status, ['active', 'inactive'], true), 422);
        $selected = $this->selectedCategoryRecords();
        if ($selected->isEmpty()) return;

        foreach ($selected as $item) {
            $record = $item['record'];
            if ($record->status === $status) continue;
            $record->status = $status;
            $record->save();
        }

        $count = $selected->count();
        $this->clearCategorySelection();
        $this->recordsReady = true;
        session()->flash('success', number_format($count).' '.strtolower(\Illuminate\Support\Str::plural('category', $count)).' set to '.$status.'.');
    }

    public function exportSelectedCategories()
    {
        abort_unless($this->group === 'product_category', 404);
        $this->authorizeGroupAction('view');
        $selected = $this->selectedCategoryRecords();
        if ($selected->isEmpty()) return null;
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $filename = 'flowtrack-product-categories-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($selected, $workspaceId): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Category', 'Code', 'Level', 'Parent', 'Status', 'Updated']);
            foreach ($selected as $item) {
                $record = $item['record'];
                $level = $item['level'];
                $parent = '—';
                if ($level === 'product') {
                    $mainId = (int) data_get($record->metadata, 'main_category_id', 0);
                    $parent = $mainId > 0
                        ? (string) (MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_main_category')->whereKey($mainId)->value('name') ?: '—')
                        : (string) (data_get($record->metadata, 'main_category') ?: data_get($record->metadata, 'excel_main_category') ?: '—');
                } elseif ($level === 'sub') {
                    $parent = (string) (MasterRecord::query()->forWorkspace($workspaceId)->ofType('product_category')->whereKey($record->parent_id)->value('name') ?: '—');
                }
                fputcsv($out, [
                    $record->name,
                    $record->code,
                    match ($level) { 'main' => 'Main category', 'product' => 'Product category', default => 'Subcategory' },
                    $parent,
                    ucfirst((string) $record->status),
                    optional($record->updated_at)->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function openCategoryDeleteConfirmation(?string $level = null, ?int $id = null): void
    {
        abort_unless($this->group === 'product_category', 404);
        $this->authorizeGroupAction('delete');

        $workspaceId = app(MasterDataService::class)->workspaceId();
        app(\App\Services\ProductTaxonomyService::class)->synchronizeLegacyTaxonomy();

        if ($level !== null && $id !== null) {
            $type = $this->categoryTypeForLevel($level);
            MasterRecord::query()->forWorkspace($workspaceId)->ofType($type)->findOrFail($id);
            $keys = [$level.':'.$id];
        } else {
            $keys = collect($this->selectedCategoryKeys)->map(fn ($key) => (string) $key)->unique()->values()->all();
        }

        if ($keys === []) return;

        $preview = app(ProductCategoryDeletionService::class)->preview($workspaceId, $keys);
        if (($preview['total_categories'] ?? 0) < 1) {
            $this->addError('record', 'The selected categories no longer exist. Refresh the page and try again.');
            return;
        }

        $this->categoryDeleteTargetKeys = $keys;
        $this->categoryDeletePreview = $preview;
        $this->showCategoryDeleteConfirm = true;
    }

    public function closeCategoryDeleteConfirmation(): void
    {
        $this->showCategoryDeleteConfirm = false;
        $this->categoryDeletePreview = [];
        $this->categoryDeleteTargetKeys = [];
    }

    public function bulkDeleteCategories(): void
    {
        $this->openCategoryDeleteConfirmation();
    }

    public function confirmCategoryHardDelete(): void
    {
        abort_unless($this->group === 'product_category', 404);
        $this->authorizeGroupAction('delete');
        if ($this->categoryDeleteTargetKeys === []) return;

        $workspaceId = app(MasterDataService::class)->workspaceId();
        $result = app(\App\Actions\MasterData\DeleteProductCategoriesAction::class)->execute($this->categoryDeleteTargetKeys);

        $deletedCategories = (int) ($result['total_categories'] ?? 0);
        $unassignedProducts = (int) ($result['products'] ?? 0);

        $this->closeCategoryDeleteConfirmation();
        $this->clearCategorySelection();
        $this->resetCategoryLazyLoading();
        $this->recordsReady = true;
        $this->resetPage('masterPage');

        session()->flash(
            'success',
            number_format($deletedCategories).' '.strtolower(\Illuminate\Support\Str::plural('category', $deletedCategories)).' permanently deleted'
            .($unassignedProducts > 0 ? ' and '.number_format($unassignedProducts).' '.strtolower(\Illuminate\Support\Str::plural('product', $unassignedProducts)).' unassigned.' : '.')
        );
    }
}
