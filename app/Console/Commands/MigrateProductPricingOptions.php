<?php

namespace App\Console\Commands;

use App\Models\MasterRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class MigrateProductPricingOptions extends Command
{
    protected $signature = 'flowtrack:migrate-product-pricing-options
        {path : Path to the USA pricing/options .xlsx workbook}
        {--workspace=1 : FlowTrack workspace id}
        {--dry-run : Report matches without changing database or files}
        {--replace-options : Replace the product option list with the Excel option list instead of merging by label}';

    protected $description = 'Import product base pricing, remote-area surcharge, and product options/images by metadata reference_code; urgent/super-urgent shipping charges are intentionally ignored.';

    private const PRODUCT_PRICE_START = 11; // K
    private const PRODUCT_PRICE_END = 22;   // V
    private const REMOTE_SURCHARGE_START = 23; // W
    private const REMOTE_SURCHARGE_END = 34;   // AH
    private const SHIPPING_COST_START = 35;    // AI
    private const SHIPPING_COST_END = 46;      // AT
    private const OPTION_PRICE_START = 47;  // AU
    private const OPTION_PRICE_END = 57;    // BE
    private const OPTION_IMAGE_START = 58;  // BF
    private const OPTION_IMAGE_END = 68;    // BP

    public function handle(): int
    {
        $path = $this->absolutePath((string) $this->argument('path'));
        $workspaceId = max(1, (int) $this->option('workspace'));
        $dryRun = (bool) $this->option('dry-run');
        $replaceOptions = (bool) $this->option('replace-options');

        if (! is_file($path)) {
            $this->error('Workbook not found: '.$path);
            return self::FAILURE;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            $this->error('The importer requires an .xlsx workbook.');
            return self::FAILURE;
        }
        if (! class_exists(ZipArchive::class)) {
            $this->error('PHP ZipArchive extension is required.');
            return self::FAILURE;
        }
        if (! Schema::hasTable('master_records')) {
            $this->error('master_records does not exist.');
            return self::FAILURE;
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $this->error('Could not open workbook: '.$path);
            return self::FAILURE;
        }

        $matched = 0;
        $updated = 0;
        $skippedNoDbMatch = 0;
        $skippedInvalid = 0;
        $imagesStored = 0;
        $seenReferenceCodes = [];

        try {
            $sheet = $this->firstWorksheet($zip);
            $sharedStrings = $this->sharedStrings($zip);
            $rows = $this->worksheetRows($zip, $sheet['path'], $sharedStrings);
            $cellImages = $this->cellImageMap($zip);

            $headerRow = $this->findHeaderRow($rows);
            $this->validateWorkbookLayout($rows, $headerRow);

            $quantityHeaders = [];
            for ($column = self::PRODUCT_PRICE_START; $column <= self::PRODUCT_PRICE_END; $column++) {
                $quantity = $this->numeric($rows[$headerRow][$column]['value'] ?? '');
                if ($quantity === null || $quantity <= 0) {
                    throw new RuntimeException('Invalid quantity header at column '.$this->columnName($column).$headerRow.'.');
                }
                $quantityHeaders[$column] = (int) $quantity;
            }

            $optionLabels = [];
            for ($column = self::OPTION_PRICE_START; $column <= self::OPTION_PRICE_END; $column++) {
                $label = trim((string) ($rows[$headerRow][$column]['value'] ?? ''));
                if ($label === '') {
                    throw new RuntimeException('Missing option label at column '.$this->columnName($column).$headerRow.'.');
                }
                $optionLabels[$column] = $label;
            }

            if ($dryRun) {
                $this->warn('DRY RUN: no database rows or image files will be changed.');
            }
            $this->line('Worksheet: '.$sheet['name'].' | header row: '.$headerRow);
            $this->line('Remote Area Surcharge columns W:AH are imported with pricing.');
            $this->line('Urgent/Super Urgent Shipping columns AI:AT are intentionally ignored.');

            foreach ($rows as $rowNumber => $cells) {
                if ($rowNumber <= $headerRow) {
                    continue;
                }

                $referenceCode = strtoupper(trim((string) ($cells[4]['value'] ?? ''))); // D
                if ($referenceCode === '') {
                    continue;
                }

                if (isset($seenReferenceCodes[$referenceCode])) {
                    $skippedInvalid++;
                    $this->warn("Row {$rowNumber}: duplicate reference code {$referenceCode}; first seen on row {$seenReferenceCodes[$referenceCode]}. Skipped.");
                    continue;
                }
                $seenReferenceCodes[$referenceCode] = $rowNumber;

                $products = MasterRecord::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('type', 'product')
                    ->whereNull('deleted_at')
                    ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.reference_code'))) = ?", [$referenceCode])
                    ->get();

                if ($products->count() === 0) {
                    $skippedNoDbMatch++;
                    $this->line("SKIP row {$rowNumber}: {$referenceCode} - no existing product with this reference code.");
                    continue;
                }
                if ($products->count() > 1) {
                    $skippedInvalid++;
                    $this->warn("Row {$rowNumber}: {$referenceCode} matches multiple products. Skipped for safety.");
                    continue;
                }

                /** @var MasterRecord $product */
                $product = $products->first();
                $matched++;

                $priceBreakpoints = [];
                foreach ($quantityHeaders as $column => $quantity) {
                    $value = trim((string) ($cells[$column]['value'] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $price = $this->numeric($value);
                    if ($price === null || $price < 0) {
                        $skippedInvalid++;
                        $this->warn("Row {$rowNumber}: invalid product price in {$this->columnName($column)}{$rowNumber}. Product skipped.");
                        continue 2;
                    }
                    $priceBreakpoints[] = ['quantity' => $quantity, 'price' => $price];
                }

                if ($priceBreakpoints === []) {
                    $skippedInvalid++;
                    $this->warn("Row {$rowNumber}: {$referenceCode} has no base pricing. Product skipped.");
                    continue;
                }

                // Remote Area Surcharge is part of the product pricing table in FlowTrack.
                // Excel stores it in W:AH using the same quantity breakpoints as K:V.
                $remoteSurchargeBreakpoints = [];
                foreach ($quantityHeaders as $productPriceColumn => $quantity) {
                    $remoteColumn = self::REMOTE_SURCHARGE_START + ($productPriceColumn - self::PRODUCT_PRICE_START);
                    $value = trim((string) ($cells[$remoteColumn]['value'] ?? ''));
                    if ($value === '') {
                        continue;
                    }
                    $price = $this->numeric($value);
                    if ($price === null || $price < 0) {
                        $skippedInvalid++;
                        $this->warn("Row {$rowNumber}: invalid remote-area surcharge in {$this->columnName($remoteColumn)}{$rowNumber}. Product skipped.");
                        continue 2;
                    }
                    $remoteSurchargeBreakpoints[] = ['quantity' => $quantity, 'price' => $price];
                }

                $excelOptions = [];
                for ($optionPriceColumn = self::OPTION_PRICE_START; $optionPriceColumn <= self::OPTION_PRICE_END; $optionPriceColumn++) {
                    $offset = $optionPriceColumn - self::OPTION_PRICE_START;
                    $imageColumn = self::OPTION_IMAGE_START + $offset;
                    $label = $optionLabels[$optionPriceColumn];
                    $rawCharge = trim((string) ($cells[$optionPriceColumn]['value'] ?? ''));
                    $imageFormula = (string) (($cells[$imageColumn]['formula'] ?? '') ?: ($cells[$imageColumn]['value'] ?? ''));
                    $imageId = $this->dispImageId($imageFormula);
                    $mediaPath = $imageId !== null ? ($cellImages[$imageId] ?? null) : null;

                    if ($rawCharge === '' && $imageId === null) {
                        continue;
                    }

                    $charge = $rawCharge === '' ? 0.0 : $this->numeric($rawCharge);
                    if ($charge === null || $charge < 0) {
                        $skippedInvalid++;
                        $this->warn("Row {$rowNumber}: invalid option charge for {$label}. Product skipped.");
                        continue 2;
                    }
                    if ($imageId !== null && $mediaPath === null) {
                        $skippedInvalid++;
                        $this->warn("Row {$rowNumber}: option image {$imageId} for {$label} cannot be resolved. Product skipped.");
                        continue 2;
                    }

                    $excelOptions[] = [
                        'label' => $label,
                        'extra_charge' => $charge,
                        'media_path' => $mediaPath,
                    ];
                }

                $metadata = (array) ($product->metadata ?? []);
                $existingOptions = collect((array) ($metadata['product_options'] ?? []))
                    ->filter(fn ($option) => is_array($option))
                    ->values();
                $existingByLabel = $existingOptions->keyBy(fn ($option) => mb_strtolower(trim((string) ($option['label'] ?? ''))));

                $nextOptions = [];
                $excelLabels = [];
                $pendingImages = [];

                foreach ($excelOptions as $excelOption) {
                    $normalizedLabel = mb_strtolower(trim($excelOption['label']));
                    $excelLabels[] = $normalizedLabel;
                    $existing = $existingByLabel->get($normalizedLabel);
                    $key = trim((string) data_get($existing, 'key'));
                    if ($key === '' || ! preg_match('/^[A-Za-z0-9-]{8,80}$/', $key)) {
                        $key = (string) Str::uuid();
                    }

                    $row = [
                        'key' => $key,
                        'label' => $excelOption['label'],
                        'extra_charge' => $excelOption['extra_charge'],
                    ];

                    $existingImagePath = trim((string) data_get($existing, 'image_path'));
                    if ($excelOption['media_path']) {
                        $pendingImages[] = [
                            'key' => $key,
                            'media_path' => $excelOption['media_path'],
                            'target_index' => count($nextOptions),
                        ];
                    } elseif ($existingImagePath !== '') {
                        $row['image_path'] = $existingImagePath;
                    }

                    $nextOptions[] = $row;
                }

                if (! $replaceOptions) {
                    foreach ($existingOptions as $existing) {
                        $normalizedLabel = mb_strtolower(trim((string) ($existing['label'] ?? '')));
                        if ($normalizedLabel === '' || in_array($normalizedLabel, $excelLabels, true)) {
                            continue;
                        }
                        $nextOptions[] = $existing;
                    }
                }

                if ($dryRun) {
                    $this->info("MATCH row {$rowNumber}: {$referenceCode} -> product #{$product->id}; ".count($priceBreakpoints).' base prices, '.count($remoteSurchargeBreakpoints).' remote surcharges, '.count($excelOptions).' Excel options, '.count($pendingImages).' option images.');
                    continue;
                }

                foreach ($pendingImages as $pendingImage) {
                    $bytes = $zip->getFromName($pendingImage['media_path']);
                    if ($bytes === false) {
                        throw new RuntimeException('Could not read '.$pendingImage['media_path'].' from the workbook.');
                    }
                    $extension = strtolower(pathinfo($pendingImage['media_path'], PATHINFO_EXTENSION));
                    $extension = preg_match('/^[a-z0-9]{2,5}$/', $extension) ? $extension : 'png';
                    $hash = substr(hash('sha256', $bytes), 0, 24);
                    $storagePath = 'product-option-images/'.$workspaceId.'/'.$product->id.'/'.$pendingImage['key'].'/excel-'.$hash.'.'.$extension;
                    Storage::disk('public')->put($storagePath, $bytes);
                    $nextOptions[$pendingImage['target_index']]['image_path'] = $storagePath;
                    $imagesStored++;
                }

                $metadata['price_breakpoints'] = $priceBreakpoints;
                if ($remoteSurchargeBreakpoints === []) {
                    unset($metadata['remote_surcharge_breakpoints']);
                } else {
                    $metadata['remote_surcharge_breakpoints'] = $remoteSurchargeBreakpoints;
                }
                $metadata['price_table_raw'] = $this->priceTableRaw($priceBreakpoints, $remoteSurchargeBreakpoints);
                if ($nextOptions === []) {
                    unset($metadata['product_options']);
                } else {
                    $metadata['product_options'] = array_values($nextOptions);
                }
                $metadata['pricing_options_import'] = [
                    'source_file' => basename($path),
                    'sheet' => $sheet['name'],
                    'row' => $rowNumber,
                    'reference_code' => $referenceCode,
                    'imported_at' => now()->toIso8601String(),
                    'remote_area_surcharge_imported' => $remoteSurchargeBreakpoints !== [],
                    'urgent_shipping_costs_imported' => false,
                    'shipment_costs_imported' => false,
                ];

                DB::transaction(function () use ($product, $metadata): void {
                    $product->update(['metadata' => $metadata]);

                    if (Schema::hasTable('master_values')) {
                        DB::table('master_values')
                            ->where('group_key', 'products')
                            ->where('code', $product->code)
                            ->update([
                                'meta' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'updated_at' => now(),
                            ]);
                    }
                });

                $updated++;
                $this->info("UPDATED row {$rowNumber}: {$referenceCode} -> product #{$product->id}");
            }
        } finally {
            $zip->close();
        }

        if (! $dryRun) {
            Cache::forget("flowtrack:master:active:{$workspaceId}:product");
            Cache::forget("flowtrack:master:legacy-sync:{$workspaceId}");
        }

        $this->newLine();
        $this->table(
            ['Matched', 'Updated', 'No DB match', 'Invalid/duplicate', 'Images stored'],
            [[$matched, $updated, $skippedNoDbMatch, $skippedInvalid, $imagesStored]],
        );

        return $skippedInvalid > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }
        return base_path($path);
    }

    /** @return array{name:string,path:string} */
    private function firstWorksheet(ZipArchive $zip): array
    {
        $workbook = $this->xml($zip, 'xl/workbook.xml');
        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = $workbook->xpath('//m:sheets/m:sheet') ?: [];
        if (! isset($sheets[0])) throw new RuntimeException('Workbook contains no worksheets.');

        $sheet = $sheets[0];
        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($attrs['id'] ?? '');
        $name = (string) ($sheet['name'] ?? 'Sheet1');

        $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rels->xpath('//p:Relationship') ?: [] as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) continue;
            $target = ltrim((string) $relationship['Target'], '/');
            return ['name' => $name, 'path' => str_starts_with($target, 'xl/') ? $target : 'xl/'.$target];
        }

        throw new RuntimeException('Could not resolve first worksheet.');
    }

    /** @return array<int,string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) return [];
        $xml = $this->xml($zip, 'xl/sharedStrings.xml');
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];
        foreach ($xml->xpath('//m:si') ?: [] as $item) {
            $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = [];
            foreach ($item->xpath('.//m:t') ?: [] as $text) $parts[] = (string) $text;
            $strings[] = implode('', $parts);
        }
        return $strings;
    }

    /** @return array<int,array<int,array{value:string,formula:string}>> */
    private function worksheetRows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
    {
        $xml = $this->xml($zip, $sheetPath);
        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];
        foreach ($xml->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $rowNumber = (int) ($row['r'] ?? 0);
            if ($rowNumber <= 0) continue;
            $row->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($row->xpath('./m:c') ?: [] as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $column = $this->columnIndex($reference);
                if ($column <= 0) continue;

                $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $values = $cell->xpath('./m:v') ?: [];
                $formulas = $cell->xpath('./m:f') ?: [];
                $raw = isset($values[0]) ? (string) $values[0] : '';
                $type = (string) ($cell['t'] ?? '');

                if ($type === 's' && $raw !== '') {
                    $value = $sharedStrings[(int) $raw] ?? '';
                } elseif ($type === 'inlineStr') {
                    $parts = [];
                    foreach ($cell->xpath('.//m:is//m:t') ?: [] as $text) $parts[] = (string) $text;
                    $value = implode('', $parts);
                } else {
                    $value = $raw;
                }

                $rows[$rowNumber][$column] = [
                    'value' => trim($value),
                    'formula' => isset($formulas[0]) ? trim((string) $formulas[0]) : '',
                ];
            }
        }
        ksort($rows);
        return $rows;
    }

    /** @return array<string,string> image-id => xl/media/... */
    private function cellImageMap(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/cellimages.xml') === false || $zip->locateName('xl/_rels/cellimages.xml.rels') === false) {
            return [];
        }

        $rels = $this->xml($zip, 'xl/_rels/cellimages.xml.rels');
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $targets = [];
        foreach ($rels->xpath('//p:Relationship') ?: [] as $relationship) {
            $id = (string) ($relationship['Id'] ?? '');
            $target = ltrim((string) ($relationship['Target'] ?? ''), '/');
            if ($id !== '' && $target !== '') {
                $targets[$id] = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        $xml = $this->xml($zip, 'xl/cellimages.xml');
        $xml->registerXPathNamespace('etc', 'http://www.wps.cn/officeDocument/2017/etCustomData');
        $map = [];
        foreach ($xml->xpath('//etc:cellImage') ?: [] as $cellImage) {
            $cellImage->registerXPathNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
            $cellImage->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $properties = $cellImage->xpath('.//xdr:cNvPr') ?: [];
            $blips = $cellImage->xpath('.//a:blip') ?: [];
            if (! isset($properties[0], $blips[0])) continue;

            $imageId = trim((string) ($properties[0]['name'] ?? ''));
            $blipAttrs = $blips[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationshipId = (string) ($blipAttrs['embed'] ?? '');
            if ($imageId !== '' && isset($targets[$relationshipId])) {
                $map[$imageId] = $targets[$relationshipId];
            }
        }
        return $map;
    }

    private function findHeaderRow(array $rows): int
    {
        foreach ($rows as $rowNumber => $cells) {
            if ($rowNumber > 20) break;
            $d = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($cells[4]['value'] ?? '')));
            if ($d === 'productreferencecode') return $rowNumber;
        }
        throw new RuntimeException('Could not find Product reference Code header in column D.');
    }

    private function validateWorkbookLayout(array $rows, int $headerRow): void
    {
        $checks = [
            [self::PRODUCT_PRICE_START, '50'],
            [self::PRODUCT_PRICE_END, '5000'],
            [self::REMOTE_SURCHARGE_START, '50'],
            [self::REMOTE_SURCHARGE_END, '5000'],
            [self::SHIPPING_COST_START, '50'],
            [self::SHIPPING_COST_END, '5000'],
            [self::OPTION_PRICE_START, 'J-hook'],
            [self::OPTION_IMAGE_START, 'J-hook'],
        ];
        foreach ($checks as [$column, $expected]) {
            $actual = trim((string) ($rows[$headerRow][$column]['value'] ?? ''));
            if (mb_strtolower($actual) !== mb_strtolower($expected)) {
                throw new RuntimeException('Unexpected workbook layout at '.$this->columnName($column).$headerRow.": expected '{$expected}', found '{$actual}'.");
            }
        }
    }

    private function numeric(string $value): ?float
    {
        $value = trim(str_replace([',', '$'], '', $value));
        if ($value === '' || ! is_numeric($value)) return null;
        return round((float) $value, 6);
    }

    private function dispImageId(string $value): ?string
    {
        if (preg_match('/DISPIMG\("([^"]+)"/i', $value, $match)) {
            return trim($match[1]);
        }
        return null;
    }

    private function priceTableRaw(array $breakpoints, array $remoteSurchargeBreakpoints = []): string
    {
        $format = static function (float $value): string {
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        };

        $quantities = array_map(static fn ($row) => (string) ((int) $row['quantity']), $breakpoints);
        $prices = array_map(static fn ($row) => $format((float) $row['price']), $breakpoints);

        $lines = [
            "Quantity\t".implode("\t", $quantities),
            "Product price\t".implode("\t", $prices),
        ];

        if ($remoteSurchargeBreakpoints !== []) {
            $remoteByQuantity = [];
            foreach ($remoteSurchargeBreakpoints as $row) {
                $remoteByQuantity[(int) $row['quantity']] = (float) $row['price'];
            }

            $remotePrices = [];
            foreach ($breakpoints as $row) {
                $quantity = (int) $row['quantity'];
                $remotePrices[] = array_key_exists($quantity, $remoteByQuantity)
                    ? $format($remoteByQuantity[$quantity])
                    : '';
            }
            $lines[] = "Remote Area charge\t".implode("\t", $remotePrices);
        }

        return implode("\n", $lines);
    }

    private function columnIndex(string $reference): int
    {
        if (! preg_match('/^([A-Z]+)/i', $reference, $match)) return 0;
        $index = 0;
        foreach (str_split(strtoupper($match[1])) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }
        return $index;
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }
        return $name;
    }

    private function xml(ZipArchive $zip, string $path): SimpleXMLElement
    {
        $contents = $zip->getFromName($path);
        if ($contents === false) {
            throw new RuntimeException('Missing workbook entry: '.$path);
        }

        // WPS cellimages.xml uses a prefixed root namespace (etc:cellImages).
        // A valid SimpleXMLElement can evaluate to false in a loose boolean check
        // when its visible/default-namespace child set is empty, so always compare
        // the parser result strictly with false.
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);

            if ($xml === false) {
                $details = collect(libxml_get_errors())
                    ->map(static function (\LibXMLError $error): string {
                        return trim($error->message).' (line '.$error->line.', column '.$error->column.')';
                    })
                    ->filter()
                    ->take(3)
                    ->implode('; ');

                throw new RuntimeException(
                    'Invalid XML in workbook entry: '.$path.($details !== '' ? ' - '.$details : '')
                );
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
