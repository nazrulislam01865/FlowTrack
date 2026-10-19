<?php

namespace App\Console\Commands;

use App\Models\MasterRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class ImportSupplierShortCodes extends Command
{
    protected $signature = 'flowtrack:import-supplier-short-codes
        {path : Supplier .xls or .xlsx file}
        {--workspace=1 : FlowTrack workspace id}
        {--sheet= : Optional worksheet name; defaults to the first sheet}
        {--dry-run : Match and report without changing supplier records}';

    protected $description = 'Import Supplier new Code values into existing FlowTrack supplier metadata.';

    public function handle(): int
    {
        $path = $this->absolutePath((string) $this->argument('path'));
        $workspaceId = max(1, (int) $this->option('workspace'));
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path)) {
            $this->error('Supplier spreadsheet does not exist: '.$path);
            return self::FAILURE;
        }

        if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['xls', 'xlsx'], true)) {
            $this->error('Supplier spreadsheet must be an .xls or .xlsx file.');
            return self::FAILURE;
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $exception) {
            $this->error('Could not read supplier spreadsheet: '.$exception->getMessage());
            return self::FAILURE;
        }

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
            $entries = $this->readEntries($sheet, $headerRow, $columns);

            if ($entries === []) {
                $this->warn('No rows containing a Supplier new Code were found.');
                return self::SUCCESS;
            }

            $byName = $this->uniqueIndex($entries, 'name_key');
            $byOldCode = $this->uniqueIndex($entries, 'old_code_key');
            $byLatinName = $this->uniqueIndex($entries, 'latin_name_key');

            // One bounded supplier read; matching happens in memory so the import
            // never turns into a per-row/N+1 lookup against production data.
            $suppliers = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('supplier')
                ->orderBy('id')
                ->get(['id', 'name', 'code', 'metadata']);

            $updates = [];
            $matched = 0;
            $unchanged = 0;
            $unmatched = [];

            foreach ($suppliers as $supplier) {
                $entry = $byName[$this->key((string) $supplier->name)]
                    ?? $byOldCode[$this->key((string) $supplier->code)]
                    ?? $byLatinName[$this->latinKey((string) $supplier->name)]
                    ?? null;

                if (! $entry) {
                    $unmatched[] = (string) $supplier->name;
                    continue;
                }

                $matched++;
                $shortCode = $entry['short_code'];
                $metadata = (array) ($supplier->metadata ?? []);
                $current = trim((string) ($metadata['short_code'] ?? ''));

                if ($current === $shortCode) {
                    $unchanged++;
                    continue;
                }

                $metadata['short_code'] = $shortCode;
                unset($metadata['short_code_source']);
                $updates[] = [
                    'id' => (int) $supplier->id,
                    'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ];
            }

            if (! $dryRun && $updates !== []) {
                DB::transaction(function () use ($updates): void {
                    foreach (array_chunk($updates, 500) as $chunk) {
                        DB::table('master_records')->upsert(
                            $chunk,
                            ['id'],
                            ['metadata', 'updated_at'],
                        );
                    }
                });

                Cache::forget("flowtrack:master:active:{$workspaceId}:supplier");
                Cache::forget("flowtrack:master:legacy-sync:{$workspaceId}");
            }

            $this->table(
                ['Spreadsheet rows', 'Matched suppliers', $dryRun ? 'Would update' : 'Updated', 'Already current', 'Unmatched suppliers'],
                [[count($entries), $matched, count($updates), $unchanged, count($unmatched)]],
            );

            if ($dryRun) {
                $this->warn('DRY RUN: no supplier records were changed.');
            }

            if ($unmatched !== []) {
                $this->newLine();
                $this->warn('Unmatched FlowTrack suppliers (first 20):');
                foreach (array_slice($unmatched, 0, 20) as $name) {
                    $this->line(' - '.$name);
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /** @return array{0:int,1:array{name?:int,old_code?:int,short_code:int}} */
    private function findHeaderRow(Worksheet $sheet): array
    {
        $aliases = [
            'supplier name' => 'name',
            'supplier old code' => 'old_code',
            'old supplier code' => 'old_code',
            'supplier new code' => 'short_code',
            'supplier short code' => 'short_code',
            'short code' => 'short_code',
        ];

        $maxRow = min(30, $sheet->getHighestDataRow());
        $maxColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row <= $maxRow; $row++) {
            $columns = [];
            for ($column = 1; $column <= $maxColumn; $column++) {
                $header = $this->normalizeHeader($this->cell($sheet, $column, $row));
                if ($header !== '' && isset($aliases[$header])) {
                    $columns[$aliases[$header]] = $column;
                }
            }

            if (isset($columns['short_code']) && (isset($columns['name']) || isset($columns['old_code']))) {
                return [$row, $columns];
            }
        }

        throw new RuntimeException('Could not find Supplier name/Supplier old Code and Supplier new Code headers.');
    }

    /** @return array<int,array{name:string,name_key:string,latin_name_key:string,old_code:string,old_code_key:string,short_code:string}> */
    private function readEntries(Worksheet $sheet, int $headerRow, array $columns): array
    {
        $entries = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $name = isset($columns['name']) ? $this->cell($sheet, $columns['name'], $row) : '';
            $oldCode = isset($columns['old_code']) ? $this->cell($sheet, $columns['old_code'], $row) : '';
            $shortCode = $this->cell($sheet, $columns['short_code'], $row);

            if ($shortCode === '' || ($name === '' && $oldCode === '')) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'name_key' => $this->key($name),
                'latin_name_key' => $this->latinKey($name),
                'old_code' => $oldCode,
                'old_code_key' => $this->key($oldCode),
                'short_code' => mb_strtoupper(trim($shortCode)),
            ];
        }

        return $entries;
    }

    /**
     * Keep only unambiguous keys. Repeated spreadsheet rows are accepted when
     * they resolve to the same short code; conflicting duplicates are ignored.
     *
     * @param array<int,array<string,string>> $entries
     * @return array<string,array<string,string>>
     */
    private function uniqueIndex(array $entries, string $field): array
    {
        $buckets = [];
        foreach ($entries as $entry) {
            $key = trim((string) ($entry[$field] ?? ''));
            if ($key === '') continue;
            $buckets[$key][] = $entry;
        }

        $index = [];
        foreach ($buckets as $key => $rows) {
            $codes = array_values(array_unique(array_column($rows, 'short_code')));
            if (count($codes) === 1) {
                $index[$key] = $rows[0];
            }
        }

        return $index;
    }

    private function cell(Worksheet $sheet, int $column, int $row): string
    {
        $address = Coordinate::stringFromColumnIndex($column).$row;
        $value = $sheet->getCell($address)->getFormattedValue();
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function key(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function latinKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{Latin}\pN]+/u', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_starts_with($path, '/')) return $path;
        return base_path($path);
    }
}
