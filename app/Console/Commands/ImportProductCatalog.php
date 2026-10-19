<?php

namespace App\Console\Commands;

use App\Models\MasterRecord;
use App\Models\MasterValue;
use App\Services\MasterDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\BaseDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class ImportProductCatalog extends Command
{
    protected $signature = 'flowtrack:import-products
        {path : An .xlsx file or a directory containing .xlsx files}
        {--workspace=1 : FlowTrack workspace id}
        {--sheet= : Optional worksheet name; defaults to the first sheet}
        {--dry-run : Validate and report without writing to the database or storage}
        {--no-images : Import product data but skip embedded images}';

    protected $description = 'Import the FlowTrack product catalogue from Excel, including embedded product images.';

    /** @var array<string,array{file:string,row:int}> */
    private array $seenProductCodes = [];

    private int $created = 0;
    private int $updated = 0;
    private int $images = 0;
    private int $skipped = 0;
    private int $errors = 0;

    public function handle(): int
    {
        $path = $this->absolutePath((string) $this->argument('path'));
        $workspaceId = max(1, (int) $this->option('workspace'));
        $dryRun = (bool) $this->option('dry-run');
        $skipImages = (bool) $this->option('no-images');

        if (! file_exists($path)) {
            $this->error('Path does not exist: '.$path);
            return self::FAILURE;
        }

        $files = $this->xlsxFiles($path);
        if ($files === []) {
            $this->error('No .xlsx files were found at: '.$path);
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY RUN: no database or image files will be changed.');
        }

        foreach ($files as $file) {
            try {
                $this->importFile($file, $workspaceId, $dryRun, $skipImages);
            } catch (Throwable $e) {
                $this->errors++;
                $this->newLine();
                $this->error(basename($file).': '.$e->getMessage());
            }
        }

        if (! $dryRun) {
            Cache::forget("flowtrack:master:active:{$workspaceId}:product");
            Cache::forget("flowtrack:master:active:{$workspaceId}:product_category");
            Cache::forget("flowtrack:master:legacy-sync:{$workspaceId}");
        }

        $this->newLine();
        $this->table(
            ['Created', 'Updated', 'Images', 'Skipped', 'Errors'],
            [[$this->created, $this->updated, $this->images, $this->skipped, $this->errors]],
        );

        return $this->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function importFile(string $file, int $workspaceId, bool $dryRun, bool $skipImages): void
    {
        $this->newLine();
        $this->info('Reading '.basename($file));

        $spreadsheet = IOFactory::load($file);

        try {
            $sheetName = trim((string) $this->option('sheet'));
            $sheet = $sheetName !== ''
                ? $spreadsheet->getSheetByName($sheetName)
                : $spreadsheet->getSheet(0);

            if (! $sheet instanceof Worksheet) {
                throw new RuntimeException($sheetName !== ''
                    ? "Worksheet '{$sheetName}' was not found."
                    : 'The workbook does not contain a worksheet.');
            }

            [$headerRow, $columns] = $this->findHeaderRow($sheet);
            $drawings = $skipImages ? [] : $this->drawingsByRow($sheet, $columns['image'] ?? null);
            $highestRow = $sheet->getHighestDataRow();

            $this->line("Sheet: {$sheet->getTitle()} | header row: {$headerRow} | last data row: {$highestRow} | embedded image rows: ".count($drawings));

            for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
                $code = strtoupper($this->cell($sheet, $columns['code'], $row));
                $name = $this->cell($sheet, $columns['product'], $row);

                if ($code === '' && $name === '') {
                    continue;
                }

                if ($code === '' || $name === '') {
                    $this->errors++;
                    $this->warn("Row {$row}: skipped because Product reference Code or Product is blank.");
                    continue;
                }

                if (isset($this->seenProductCodes[$code])) {
                    $first = $this->seenProductCodes[$code];
                    $this->errors++;
                    $this->warn("Row {$row}: duplicate product code {$code}; first seen in {$first['file']} row {$first['row']}. Skipped.");
                    continue;
                }
                $this->seenProductCodes[$code] = ['file' => basename($file), 'row' => $row];

                $mainCategory = $this->cellOptional($sheet, $columns, 'main_category', $row);
                $categoryName = $this->cellOptional($sheet, $columns, 'category', $row);
                $subCategory = $this->cellOptional($sheet, $columns, 'sub_category', $row);
                $productSize = $this->cellOptional($sheet, $columns, 'product_size', $row);
                $certificate = $this->cellOptional($sheet, $columns, 'certificate', $row);
                $template = $this->cellOptional($sheet, $columns, 'template', $row);
                $certificateUrl = $this->hyperlinkOptional($sheet, $columns, 'certificate', $row);
                $templateUrl = $this->hyperlinkOptional($sheet, $columns, 'template', $row);

                $existing = MasterRecord::withTrashed()
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'product')
                    ->where('code', $code)
                    ->first();

                if ($dryRun) {
                    $existing ? $this->updated++ : $this->created++;
                    if (isset($drawings[$row][0])) {
                        $this->images++;
                    }
                    $this->line("[dry-run] row {$row}: {$code} - {$name}".($existing ? ' (update)' : ' (create)'));
                    continue;
                }

                try {
                    DB::transaction(function () use (
                        $workspaceId,
                        $file,
                        $sheet,
                        $row,
                        $code,
                        $name,
                        $mainCategory,
                        $categoryName,
                        $subCategory,
                        $productSize,
                        $certificate,
                        $template,
                        $certificateUrl,
                        $templateUrl,
                        $drawings,
                        $existing
                    ): void {
                        $category = $this->ensureProductCategory($workspaceId, $categoryName);

                        $product = $existing ?: new MasterRecord();
                        $wasExisting = $product->exists;
                        if ($product->trashed()) {
                            $product->restore();
                        }

                        $metadata = (array) ($product->metadata ?? []);
                        $metadata = array_merge($metadata, array_filter([
                            'main_category' => $mainCategory,
                            'category' => $categoryName,
                            'sub_category' => $subCategory,
                            'product_size' => $productSize,
                            'certificate_test_report' => $certificate,
                            'certificate_test_report_url' => $certificateUrl,
                            'template_doc' => $template,
                            'template_doc_url' => $templateUrl,
                            'catalog_source_file' => basename($file),
                            'catalog_source_sheet' => $sheet->getTitle(),
                            'catalog_source_row' => $row,
                        ], static fn ($value) => $value !== null && $value !== ''));

                        $product->fill([
                            'workspace_id' => $workspaceId,
                            'parent_id' => $category?->id,
                            'type' => 'product',
                            'code' => $code,
                            'name' => $name,
                            'description' => $subCategory !== '' ? $subCategory : ($product->description ?: null),
                            'metadata' => $metadata ?: null,
                            'status' => 'active',
                            'sort_order' => $product->sort_order ?: $row,
                        ]);
                        $product->save();

                        if (isset($drawings[$row][0])) {
                            if (count($drawings[$row]) > 1) {
                                $this->warn("Row {$row}: multiple images found in PIC column; only the first image is used because FlowTrack currently stores one product image.");
                            }

                            $imagePath = $this->storeDrawing($drawings[$row][0], $workspaceId, (int) $product->id, $code);
                            if ($imagePath !== null) {
                                $metadata = (array) ($product->metadata ?? []);
                                $oldPath = trim((string) ($metadata['product_image_path'] ?? ''));
                                $metadata['product_image_path'] = $imagePath;
                                $product->update(['metadata' => $metadata]);

                                if ($oldPath !== '' && $oldPath !== $imagePath) {
                                    Storage::disk('public')->delete($oldPath);
                                }
                                $this->images++;
                            }
                        }

                        $this->mirrorLegacy($category, $product);
                        $wasExisting ? $this->updated++ : $this->created++;
                    });

                    $this->line("Row {$row}: imported {$code} - {$name}");
                } catch (Throwable $e) {
                    $this->errors++;
                    $this->warn("Row {$row} ({$code}): {$e->getMessage()}");
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /** @return array{0:int,1:array<string,int>} */
    private function findHeaderRow(Worksheet $sheet): array
    {
        $aliases = [
            'main category' => 'main_category',
            'category' => 'category',
            'sub category' => 'sub_category',
            'product reference code' => 'code',
            'product reference' => 'code',
            'product ref code' => 'code',
            'product' => 'product',
            'pic image' => 'image',
            'pic' => 'image',
            'product size text' => 'product_size',
            'product size' => 'product_size',
            'certificate test report doc' => 'certificate',
            'certificate test report' => 'certificate',
            'template doc' => 'template',
            'template' => 'template',
        ];

        $maxRow = min(30, $sheet->getHighestDataRow());
        $maxColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row <= $maxRow; $row++) {
            $columns = [];
            for ($column = 1; $column <= $maxColumn; $column++) {
                $value = $this->normalizeHeader($this->cell($sheet, $column, $row));
                if ($value !== '' && isset($aliases[$value])) {
                    $columns[$aliases[$value]] = $column;
                }
            }

            if (isset($columns['code'], $columns['product'])) {
                return [$row, $columns];
            }
        }

        throw new RuntimeException('Could not find the Excel header row. Required headers: Product reference Code and Product.');
    }

    /** @return array<int,array<int,BaseDrawing>> */
    private function drawingsByRow(Worksheet $sheet, ?int $imageColumn): array
    {
        if ($imageColumn === null) {
            return [];
        }

        $wantedColumn = Coordinate::stringFromColumnIndex($imageColumn);
        $result = [];

        foreach ($sheet->getDrawingCollection() as $drawing) {
            $coordinate = strtoupper((string) $drawing->getCoordinates());
            if ($coordinate === '') {
                continue;
            }

            [$column, $row] = Coordinate::coordinateFromString($coordinate);
            if (strtoupper($column) !== strtoupper($wantedColumn)) {
                continue;
            }

            $result[(int) $row][] = $drawing;
        }

        return $result;
    }

    private function ensureProductCategory(int $workspaceId, string $name): ?MasterRecord
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $category = MasterRecord::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'product_category')
            ->where('name', $name)
            ->orderBy('id')
            ->first();

        if ($category) {
            if ($category->trashed()) {
                $category->restore();
            }
            if ($category->status !== 'active') {
                $category->status = 'active';
                $category->save();
            }
            return $category;
        }

        return MasterRecord::create([
            'workspace_id' => $workspaceId,
            'created_by' => null,
            'parent_id' => null,
            'type' => 'product_category',
            'code' => app(MasterDataService::class)->nextCode('product_category'),
            'name' => $name,
            'description' => null,
            'metadata' => null,
            'status' => 'active',
            'sort_order' => 0,
        ]);
    }

    private function storeDrawing(BaseDrawing $drawing, int $workspaceId, int $productId, string $code): ?string
    {
        $bytes = null;
        $extension = 'png';

        if ($drawing instanceof Drawing) {
            $path = $drawing->getPath();
            if ($path === '') {
                return null;
            }
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('Could not read embedded image data from the workbook.');
            }
            $candidate = strtolower((string) $drawing->getExtension());
            if ($candidate !== '') {
                $extension = $candidate;
            }
        } elseif ($drawing instanceof MemoryDrawing) {
            $resource = $drawing->getImageResource();
            $renderer = $drawing->getRenderingFunction();
            if (! $resource || ! is_callable($renderer)) {
                throw new RuntimeException('Unsupported in-memory Excel image.');
            }

            ob_start();
            call_user_func($renderer, $resource);
            $bytes = ob_get_clean();
            if (! is_string($bytes) || $bytes === '') {
                throw new RuntimeException('Could not render the embedded Excel image.');
            }

            $extension = match (strtolower((string) $drawing->getMimeType())) {
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'png',
            };
        } else {
            throw new RuntimeException('Unsupported Excel drawing type: '.get_class($drawing));
        }

        $safeCode = preg_replace('/[^A-Za-z0-9._-]+/', '-', $code) ?: 'product';
        $path = "product-images/{$workspaceId}/{$productId}/{$safeCode}.{$extension}";

        if (! Storage::disk('public')->put($path, $bytes)) {
            throw new RuntimeException('Failed to save the product image to the public storage disk.');
        }

        return $path;
    }

    private function mirrorLegacy(?MasterRecord $category, MasterRecord $product): void
    {
        if (! Schema::hasTable('master_values')) {
            return;
        }

        $legacyCategoryId = null;
        if ($category) {
            $legacyCategory = MasterValue::query()->updateOrCreate(
                ['group_key' => 'product_categories', 'code' => $category->code],
                [
                    'name' => $category->name,
                    'description' => $category->description,
                    'parent_id' => null,
                    'is_active' => true,
                    'meta' => $category->metadata,
                ]
            );
            $legacyCategoryId = $legacyCategory->id;
        }

        MasterValue::query()->updateOrCreate(
            ['group_key' => 'products', 'code' => $product->code],
            [
                'name' => $product->name,
                'description' => $product->description,
                'parent_id' => $legacyCategoryId,
                'is_active' => true,
                'meta' => $product->metadata,
            ]
        );
    }

    private function cell(Worksheet $sheet, int $column, int $row): string
    {
        $address = Coordinate::stringFromColumnIndex($column).$row;
        $value = $sheet->getCell($address)->getFormattedValue();
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function cellOptional(Worksheet $sheet, array $columns, string $key, int $row): string
    {
        return isset($columns[$key]) ? $this->cell($sheet, $columns[$key], $row) : '';
    }

    private function hyperlinkOptional(Worksheet $sheet, array $columns, string $key, int $row): string
    {
        if (! isset($columns[$key])) {
            return '';
        }
        $address = Coordinate::stringFromColumnIndex($columns[$key]).$row;
        return trim((string) $sheet->getCell($address)->getHyperlink()->getUrl());
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /** @return array<int,string> */
    private function xlsxFiles(string $path): array
    {
        if (is_file($path)) {
            return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx' ? [$path] : [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }
            if (strtolower($fileInfo->getExtension()) !== 'xlsx') {
                continue;
            }
            if (str_starts_with($fileInfo->getFilename(), '~$')) {
                continue;
            }
            $files[] = $fileInfo->getPathname();
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $path;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return base_path($path);
    }
}
