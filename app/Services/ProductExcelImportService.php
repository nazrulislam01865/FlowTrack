<?php

namespace App\Services;

use App\Models\MasterRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

class ProductExcelImportService
{
    private const HEADER_ALIASES = [
        'main_category' => ['maincategory'],
        'category' => ['category'],
        'sub_category' => ['subcategory'],
        'reference_code' => ['productreferencecode', 'productreference', 'referencecode', 'productrefcode'],
        'product' => ['product', 'productname'],
        'pic' => ['picimage', 'pic', 'image', 'productimage'],
        'product_size' => ['productsizetext', 'productsize', 'size'],
        'certificate_report' => ['certificatetestreportdoc', 'certificatetestreport', 'certificateandtestreportdoc', 'certificateandtestreport'],
        'template' => ['templatedoc', 'template'],
    ];

    /**
     * @return array{
     *   rows_seen:int, products_created:int, products_updated:int,
     *   categories_created:int, images_imported:int, image_errors:int, skipped:int,
     *   warnings:array<int,string>, header_row:int, sheet_name:string
     * }
     */
    public function import(
        string $filePath,
        int $workspaceId = 1,
        int $sheetNumber = 1,
        bool $dryRun = false,
        int $startRow = 0,
        int $limit = 0,
        ?callable $progress = null,
    ): array {
        $filePath = $this->absolutePath($filePath);
        if (! is_file($filePath)) {
            throw new RuntimeException('Excel file not found: '.$filePath);
        }
        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('This product importer expects an .xlsx file so embedded product images can be extracted. Save older .xls files as .xlsx first.');
        }
        if (! class_exists(ZipArchive::class) || ! class_exists(XMLReader::class)) {
            throw new RuntimeException('PHP ZipArchive and XMLReader extensions are required. Enable zip and xml in XAMPP PHP.');
        }
        if (! Schema::hasTable('master_records')) {
            throw new RuntimeException('The master_records table does not exist. Run php artisan migrate first.');
        }
        if (! DB::table('workspaces')->where('id', $workspaceId)->exists()) {
            throw new RuntimeException('Workspace '.$workspaceId.' does not exist.');
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Could not open Excel file: '.$filePath);
        }

        try {
            $sheet = $this->worksheetInfo($zip, max(1, $sheetNumber));
            $sharedStrings = $this->readSharedStrings($zip);
            $imageAnchors = $this->readWorksheetImages($zip, $sheet['path']);

            $summary = [
                'rows_seen' => 0,
                'products_created' => 0,
                'products_updated' => 0,
                'categories_created' => 0,
                'images_imported' => 0,
                'image_errors' => 0,
                'skipped' => 0,
                'warnings' => [],
                'header_row' => 0,
                'sheet_name' => $sheet['name'],
            ];

            $headerMap = null;
            $picColumnIndex = null;
            $carry = [
                'main_category' => '',
                'category' => '',
                'sub_category' => '',
            ];
            $imported = 0;
            $excelDir = dirname($filePath);

            foreach ($this->streamRows($zip, $sheet['path'], $sharedStrings) as [$rowNumber, $cells]) {
                if ($headerMap === null) {
                    $candidate = $this->detectHeaderMap($cells);
                    if ($candidate !== null) {
                        $headerMap = $candidate;
                        $summary['header_row'] = $rowNumber;
                        $picColumnIndex = $headerMap['pic'] ?? null;
                    }
                    continue;
                }

                if ($startRow > 0 && $rowNumber < $startRow) {
                    continue;
                }

                $row = $this->mappedRow($cells, $headerMap);
                foreach (array_keys($carry) as $key) {
                    if ($row[$key] !== '') {
                        $carry[$key] = $row[$key];
                    } else {
                        $row[$key] = $carry[$key];
                    }
                }

                // Pictures or formatting can create physically non-empty rows. A product name is the import boundary.
                if ($row['product'] === '') {
                    continue;
                }

                $summary['rows_seen']++;
                if ($limit > 0 && $imported >= $limit) {
                    break;
                }
                $imported++;

                try {
                    $result = $this->importRow(
                        $zip,
                        $row,
                        $rowNumber,
                        $workspaceId,
                        $sheet['name'],
                        basename($filePath),
                        $excelDir,
                        $imageAnchors[$rowNumber] ?? [],
                        $picColumnIndex,
                        $dryRun,
                    );

                    $summary[$result['product_created'] ? 'products_created' : 'products_updated']++;
                    if ($result['category_created']) $summary['categories_created']++;
                    if ($result['image_imported']) $summary['images_imported']++;
                    if ($result['image_error'] !== null) {
                        $summary['image_errors']++;
                        $this->warn($summary, 'Row '.$rowNumber.' image: '.$result['image_error']);
                    }

                    if ($progress && $summary['rows_seen'] % 100 === 0) {
                        $progress($summary);
                    }
                } catch (\Throwable $exception) {
                    $summary['skipped']++;
                    $this->warn($summary, 'Row '.$rowNumber.': '.$exception->getMessage());
                }
            }

            if ($headerMap === null) {
                throw new RuntimeException('Could not find the product header row. Expected columns such as Product, Product reference Code, Category/Sub Category, and PIC (Image).');
            }

            return $summary;
        } finally {
            $zip->close();
        }
    }

    /** @return array{product_created:bool,category_created:bool,image_imported:bool,image_error:?string} */
    private function importRow(
        ZipArchive $zip,
        array $row,
        int $rowNumber,
        int $workspaceId,
        string $sheetName,
        string $workbookName,
        string $excelDir,
        array $rowImages,
        ?int $picColumnIndex,
        bool $dryRun,
    ): array {
        $productName = $this->clean($row['product']);
        $categoryName = $this->firstFilled($row['category'], $row['sub_category'], $row['main_category'], 'Uncategorized');
        $referenceCode = $this->cleanCode($row['reference_code']);
        $code = $referenceCode !== ''
            ? $referenceCode
            : $this->generatedProductCode($row);

        $existing = MasterRecord::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'product')
            ->where('code', $code)
            ->first();
        $productCreated = ! $existing;

        $category = MasterRecord::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'product_category')
            ->where('name', $categoryName)
            ->first();
        $categoryCreated = ! $category;

        if ($dryRun) {
            return [
                'product_created' => $productCreated,
                'category_created' => $categoryCreated,
                'image_imported' => $this->hasImportableImage($row['pic'], $excelDir, $rowImages, $picColumnIndex),
                'image_error' => null,
            ];
        }

        [$category, $product] = DB::transaction(function () use (
            $workspaceId,
            $category,
            $categoryName,
            $rowNumber,
            $existing,
            $code,
            $productName,
            $row,
            $sheetName,
            $workbookName,
        ) {
            $resolvedCategory = $category;
            if ($resolvedCategory) {
                if ($resolvedCategory->trashed()) $resolvedCategory->restore();
                if ($resolvedCategory->status !== 'active') $resolvedCategory->update(['status' => 'active']);
            }
            if (! $resolvedCategory) {
                $resolvedCategory = MasterRecord::create([
                    'workspace_id' => $workspaceId,
                    'parent_id' => null,
                    'type' => 'product_category',
                    'code' => $this->generatedCategoryCode($categoryName),
                    'name' => $categoryName,
                    'description' => null,
                    'metadata' => [
                        'excel_main_category' => $this->nullable($row['main_category']),
                        'excel_category' => $this->nullable($row['category']),
                        'excel_sub_category' => $this->nullable($row['sub_category']),
                        'source' => 'excel_product_import',
                    ],
                    'status' => 'active',
                    'sort_order' => max(0, $rowNumber),
                ]);
            }

            $product = $existing ?: new MasterRecord();
            if ($product->trashed()) {
                $product->restore();
            }

            $metadata = (array) ($product->metadata ?? []);
            unset($metadata['taxonomy_unassigned']);
            $metadata = array_merge($metadata, [
                'excel_main_category' => $this->nullable($row['main_category']),
                'excel_category' => $this->nullable($row['category']),
                'excel_sub_category' => $this->nullable($row['sub_category']),
                'product_size' => $this->nullable($row['product_size']),
                'certificate_test_report' => $this->nullable($row['certificate_report']),
                'template' => $this->nullable($row['template']),
                'excel_pic_cell' => $this->nullable($row['pic']),
                'excel_source' => [
                    'workbook' => $workbookName,
                    'sheet' => $sheetName,
                    'row' => $rowNumber,
                ],
            ]);

            $product->fill([
                'workspace_id' => $workspaceId,
                'parent_id' => $resolvedCategory->id,
                'type' => 'product',
                'code' => $code,
                'name' => $productName,
                'description' => $product->description,
                'metadata' => $metadata,
                'status' => 'active',
                'sort_order' => max(0, $rowNumber),
                'deleted_at' => null,
            ])->save();

            return [$resolvedCategory, $product];
        });

        $imageImported = false;
        $imageError = null;
        try {
            $imageImported = $this->importImage($zip, $product, $row['pic'], $excelDir, $rowImages, $picColumnIndex);
        } catch (\Throwable $exception) {
            $imageError = $exception->getMessage();
        }

        return [
            'product_created' => $productCreated,
            'category_created' => $categoryCreated,
            'image_imported' => $imageImported,
            'image_error' => $imageError,
        ];
    }

    private function importImage(
        ZipArchive $zip,
        MasterRecord $product,
        string $picCell,
        string $excelDir,
        array $rowImages,
        ?int $picColumnIndex,
    ): bool {
        $embedded = $this->chooseImage($rowImages, $picColumnIndex);
        if ($embedded) {
            $stream = $zip->getStream($embedded['path']);
            if (! is_resource($stream)) {
                throw new RuntimeException('Embedded image could not be opened: '.$embedded['path']);
            }
            try {
                app(ProductImageService::class)->replaceFromStream($product, $stream, basename($embedded['path']));
            } finally {
                fclose($stream);
            }
            return true;
        }

        $picCell = $this->clean($picCell);
        if ($picCell === '') return false;

        $candidate = $picCell;
        if (! $this->isAbsolutePath($candidate)) {
            $candidate = $excelDir.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        }
        if (! is_file($candidate)) return false;

        app(ProductImageService::class)->replaceFromLocalFile($product, $candidate);
        return true;
    }

    private function hasImportableImage(string $picCell, string $excelDir, array $rowImages, ?int $picColumnIndex): bool
    {
        if ($this->chooseImage($rowImages, $picColumnIndex)) return true;
        $picCell = $this->clean($picCell);
        if ($picCell === '') return false;
        $candidate = $this->isAbsolutePath($picCell)
            ? $picCell
            : $excelDir.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $picCell);
        return is_file($candidate);
    }

    private function chooseImage(array $rowImages, ?int $picColumnIndex): ?array
    {
        if ($rowImages === []) return null;
        if ($picColumnIndex === null) return $rowImages[0];

        usort($rowImages, fn (array $a, array $b) => abs($a['col'] - $picColumnIndex) <=> abs($b['col'] - $picColumnIndex));
        return $rowImages[0] ?? null;
    }

    /** @return array{name:string,path:string} */
    private function worksheetInfo(ZipArchive $zip, int $sheetNumber): array
    {
        $workbook = $this->xmlFromZip($zip, 'xl/workbook.xml', 'Excel workbook metadata is missing.');
        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = $workbook->xpath('//m:sheets/m:sheet') ?: [];
        $sheet = $sheets[$sheetNumber - 1] ?? null;
        if (! $sheet) throw new RuntimeException('Worksheet '.$sheetNumber.' does not exist.');

        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($attrs['id'] ?? '');
        $name = (string) ($sheet['name'] ?? ('Sheet '.$sheetNumber));

        $rels = $this->xmlFromZip($zip, 'xl/_rels/workbook.xml.rels', 'Excel workbook relationships are missing.');
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rels->xpath('//p:Relationship') ?: [] as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) continue;
            return [
                'name' => $name,
                'path' => $this->resolveZipPath('xl/workbook.xml', (string) $relationship['Target']),
            ];
        }

        throw new RuntimeException('Could not resolve worksheet '.$sheetNumber.'.');
    }

    /** @return array<int,string> */
    private function readSharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) return [];
        [$reader, $tempPath] = $this->xmlReaderForZipEntry($zip, 'xl/sharedStrings.xml');
        $strings = [];
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') continue;
                $outer = $reader->readOuterXML();
                if ($outer === '') {
                    $strings[] = '';
                    continue;
                }
                $item = @simplexml_load_string($outer);
                if (! $item) {
                    $strings[] = '';
                    continue;
                }
                $parts = [];
                $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                foreach ($item->xpath('.//m:t') ?: [] as $text) $parts[] = (string) $text;
                $strings[] = $this->clean(implode('', $parts));
            }
        } finally {
            $reader->close();
            @unlink($tempPath);
        }
        return $strings;
    }

    /** @return \Generator<int,array{0:int,1:array<int,string>}> */
    private function streamRows(ZipArchive $zip, string $sheetPath, array $sharedStrings): \Generator
    {
        [$reader, $tempPath] = $this->xmlReaderForZipEntry($zip, $sheetPath);
        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;

                $rowNumber = max(1, (int) ($reader->getAttribute('r') ?: 1));
                $outer = $reader->readOuterXML();
                if ($outer === '') continue;
                $rowXml = @simplexml_load_string($outer);
                if (! $rowXml) continue;
                $rowXml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

                $cells = [];
                foreach ($rowXml->xpath('./m:c') ?: [] as $cell) {
                    $reference = (string) ($cell['r'] ?? 'A'.$rowNumber);
                    $column = $this->columnIndex($reference);
                    $cells[$column] = $this->cellValue($cell, $sharedStrings);
                }
                yield [$rowNumber, $cells];
            }
        } finally {
            $reader->close();
            @unlink($tempPath);
        }
    }

    /** @return array<int,array<int,array{col:int,path:string}>> */
    private function readWorksheetImages(ZipArchive $zip, string $sheetPath): array
    {
        $relsPath = dirname($sheetPath).'/_rels/'.basename($sheetPath).'.rels';
        if ($zip->locateName($relsPath) === false) return [];

        $rels = $this->xmlFromZip($zip, $relsPath, '');
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $drawingPaths = [];
        foreach ($rels->xpath('//p:Relationship') ?: [] as $relationship) {
            if (! str_ends_with((string) $relationship['Type'], '/drawing')) continue;
            $drawingPaths[] = $this->resolveZipPath($sheetPath, (string) $relationship['Target']);
        }

        $images = [];
        foreach ($drawingPaths as $drawingPath) {
            if ($zip->locateName($drawingPath) === false) continue;
            $drawing = $this->xmlFromZip($zip, $drawingPath, '');
            $drawing->registerXPathNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
            $drawing->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $drawing->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

            $drawingRelsPath = dirname($drawingPath).'/_rels/'.basename($drawingPath).'.rels';
            if ($zip->locateName($drawingRelsPath) === false) continue;
            $drawingRels = $this->xmlFromZip($zip, $drawingRelsPath, '');
            $drawingRels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
            $relationshipTargets = [];
            foreach ($drawingRels->xpath('//p:Relationship') ?: [] as $relationship) {
                $relationshipTargets[(string) $relationship['Id']] = $this->resolveZipPath($drawingPath, (string) $relationship['Target']);
            }

            $anchors = array_merge(
                $drawing->xpath('//xdr:oneCellAnchor') ?: [],
                $drawing->xpath('//xdr:twoCellAnchor') ?: [],
            );
            foreach ($anchors as $anchor) {
                $anchor->registerXPathNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
                $anchor->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
                $anchor->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $rows = $anchor->xpath('./xdr:from/xdr:row') ?: [];
                $cols = $anchor->xpath('./xdr:from/xdr:col') ?: [];
                $blips = $anchor->xpath('.//a:blip') ?: [];
                if (! isset($rows[0], $cols[0], $blips[0])) continue;

                $blipAttrs = $blips[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $embedId = (string) ($blipAttrs['embed'] ?? '');
                $target = $relationshipTargets[$embedId] ?? null;
                if (! $target || $zip->locateName($target) === false) continue;

                $row = ((int) $rows[0]) + 1; // DrawingML anchors are zero-based.
                $images[$row][] = [
                    'col' => (int) $cols[0],
                    'path' => $target,
                ];
            }
        }
        return $images;
    }

    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        if ($type === 'inlineStr') {
            $parts = [];
            $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($cell->xpath('.//m:is//m:t') ?: [] as $text) $parts[] = (string) $text;
            return $this->clean(implode('', $parts));
        }

        $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $values = $cell->xpath('./m:v') ?: [];
        $raw = isset($values[0]) ? (string) $values[0] : '';
        if ($type === 's') return $sharedStrings[(int) $raw] ?? '';
        if ($type === 'b') return $raw === '1' ? 'TRUE' : 'FALSE';
        return $this->clean($raw);
    }

    private function detectHeaderMap(array $cells): ?array
    {
        $normalized = [];
        foreach ($cells as $index => $value) {
            $key = $this->normalizeHeader((string) $value);
            if ($key !== '') $normalized[$index] = $key;
        }

        $map = [];
        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            foreach ($normalized as $index => $value) {
                if (in_array($value, $aliases, true)) {
                    $map[$canonical] = $index;
                    break;
                }
            }
        }

        // Product is mandatory. At least two surrounding catalogue fields make accidental matches unlikely.
        if (! isset($map['product'])) return null;
        $supporting = count(array_intersect(array_keys($map), ['main_category', 'category', 'sub_category', 'reference_code', 'pic', 'product_size']));
        if ($supporting < 2) return null;

        return $map;
    }

    private function mappedRow(array $cells, array $headerMap): array
    {
        $row = [];
        foreach (array_keys(self::HEADER_ALIASES) as $key) {
            $index = $headerMap[$key] ?? null;
            $row[$key] = $index === null ? '' : $this->clean((string) ($cells[$index] ?? ''));
        }
        return $row;
    }

    private function generatedProductCode(array $row): string
    {
        $identity = implode('|', [
            $this->clean($row['main_category']),
            $this->clean($row['category']),
            $this->clean($row['sub_category']),
            $this->clean($row['product']),
            $this->clean($row['product_size']),
        ]);
        return 'IMP-'.strtoupper(substr(sha1(mb_strtolower($identity)), 0, 16));
    }

    private function generatedCategoryCode(string $name): string
    {
        $slug = strtoupper(trim(preg_replace('/[^A-Z0-9]+/i', '-', $name), '-'));
        $slug = substr($slug, 0, 27);
        $hash = strtoupper(substr(sha1(mb_strtolower($name)), 0, 6));
        return 'CAT-'.$slug.'-'.$hash;
    }

    private function cleanCode(string $value): string
    {
        $value = strtoupper($this->clean($value));
        if ($value === '') return '';
        if (mb_strlen($value) <= 40) return $value;

        return mb_substr($value, 0, 32).'-'.strtoupper(substr(sha1($value), 0, 7));
    }

    private function normalizeHeader(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $this->clean($value)));
    }

    private function columnIndex(string $reference): int
    {
        if (! preg_match('/^([A-Z]+)/i', $reference, $match)) return 0;
        $index = 0;
        foreach (str_split(strtoupper($match[1])) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }
        return max(0, $index - 1);
    }

    /** @return array{0:XMLReader,1:string} */
    private function xmlReaderForZipEntry(ZipArchive $zip, string $entry): array
    {
        $stream = $zip->getStream($entry);
        if (! is_resource($stream)) throw new RuntimeException('Excel entry could not be read: '.$entry);

        $tempPath = tempnam(sys_get_temp_dir(), 'flowtrack_xlsx_');
        if ($tempPath === false) {
            fclose($stream);
            throw new RuntimeException('Could not create a temporary file for Excel parsing.');
        }

        $out = fopen($tempPath, 'wb');
        if (! is_resource($out)) {
            fclose($stream);
            @unlink($tempPath);
            throw new RuntimeException('Could not open temporary Excel parsing file.');
        }
        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        $reader = new XMLReader();
        if (! $reader->open($tempPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
            @unlink($tempPath);
            throw new RuntimeException('Could not open temporary Excel XML.');
        }

        return [$reader, $tempPath];
    }

    private function xmlFromZip(ZipArchive $zip, string $entry, string $error): SimpleXMLElement
    {
        $contents = $zip->getFromName($entry);
        if ($contents === false) {
            throw new RuntimeException($error !== '' ? $error : 'Excel entry is missing: '.$entry);
        }
        $xml = @simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if (! $xml) throw new RuntimeException($error !== '' ? $error : 'Excel XML is invalid: '.$entry);
        return $xml;
    }

    private function resolveZipPath(string $from, string $target): string
    {
        $target = str_replace('\\', '/', $target);
        if (str_starts_with($target, '/')) return ltrim($target, '/');
        $path = dirname($from).'/'.$target;
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B\"'");
        if ($this->isAbsolutePath($path)) return $path;
        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function firstFilled(string ...$values): string
    {
        foreach ($values as $value) {
            $value = $this->clean($value);
            if ($value !== '') return $value;
        }
        return 'Uncategorized';
    }

    private function clean(string $value): string
    {
        $value = str_replace(["\xc2\xa0", "\r\n", "\r"], [' ', "\n", "\n"], $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function nullable(string $value): ?string
    {
        $value = $this->clean($value);
        return $value === '' ? null : $value;
    }

    private function warn(array &$summary, string $warning): void
    {
        if (count($summary['warnings']) < 100) {
            $summary['warnings'][] = $warning;
        }
    }
}
