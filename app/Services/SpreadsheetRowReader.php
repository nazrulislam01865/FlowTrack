<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class SpreadsheetRowReader
{
    /** @return array{headers: array<int,string>, rows: array<int,array<string,mixed>>, header_row:int} */
    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'csv' => $this->readCsv($file->getRealPath()),
            'xlsx' => $this->readXlsx($file->getRealPath()),
            'xls' => $this->readXmlSpreadsheet($file->getRealPath()),
            default => throw new RuntimeException('Unsupported file type. Upload an .xlsx, .xls or .csv file.'),
        };
    }

    /** @return array{headers: array<int,string>, rows: array<int,array<string,mixed>>, header_row:int} */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) throw new RuntimeException('The CSV file could not be opened.');

        $matrix = [];
        while (($row = fgetcsv($handle)) !== false) {
            $matrix[] = array_map(fn ($value) => is_string($value) ? $this->cleanText($value) : $value, $row);
            if (count($matrix) > 10020) break;
        }
        fclose($handle);

        return $this->matrixToRows($matrix);
    }

    /** @return array{headers: array<int,string>, rows: array<int,array<string,mixed>>, header_row:int} */
    private function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class) || !function_exists('simplexml_load_string')) {
            throw new RuntimeException('Excel import requires the PHP Zip and XML extensions on the server when browser-side Excel parsing is unavailable.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('The Excel file could not be opened.');

        try {
            $shared = $this->sharedStrings($zip);
            $sheetPath = $this->firstWorksheetPath($zip);
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) throw new RuntimeException('The first worksheet could not be read.');

            $sheet = @simplexml_load_string($xml);
            if (!$sheet) throw new RuntimeException('The first worksheet is invalid.');
            $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $matrix = [];
            foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $rowNode) {
                $rowNumber = (int) ($rowNode['r'] ?? (count($matrix) + 1));
                if ($rowNumber > 10030) break;
                $row = [];
                foreach ($rowNode->c as $cell) {
                    $ref = (string) ($cell['r'] ?? 'A'.$rowNumber);
                    $column = $this->columnIndex($ref);
                    $row[$column] = $this->xlsxCellValue($cell, $shared);
                }
                if ($row !== []) {
                    $max = max(array_keys($row));
                    $matrix[$rowNumber - 1] = array_map(fn ($i) => $row[$i] ?? '', range(0, $max));
                } else {
                    $matrix[$rowNumber - 1] = [];
                }
            }

            if ($matrix === []) throw new RuntimeException('The first worksheet is empty.');
            ksort($matrix);
            $last = array_key_last($matrix);
            $dense = [];
            for ($i = 0; $i <= $last; $i++) $dense[] = $matrix[$i] ?? [];

            return $this->matrixToRows($dense);
        } finally {
            $zip->close();
        }
    }

    /**
     * Supports SpreadsheetML 2003 XML files that are sometimes saved with an
     * .xls extension. Legacy binary BIFF .xls files are rejected explicitly so
     * users do not get corrupted or partially parsed imports.
     */
    private function readXmlSpreadsheet(string $path): array
    {
        if (!function_exists('simplexml_load_file')) {
            throw new RuntimeException('Legacy Excel XML import requires the PHP XML extension on the server. Save the file as .xlsx or .csv if that extension is unavailable.');
        }

        $prefix = file_get_contents($path, false, null, 0, 512) ?: '';
        if (!str_contains($prefix, '<?xml') && !str_contains($prefix, '<Workbook')) {
            throw new RuntimeException('This legacy binary .xls file cannot be read safely. Open it in Excel and save it as .xlsx or .csv, then upload it again.');
        }

        $xml = @simplexml_load_file($path);
        if (!$xml) throw new RuntimeException('The .xls XML file could not be read.');
        $namespaces = $xml->getNamespaces(true);
        $ss = $namespaces['ss'] ?? 'urn:schemas-microsoft-com:office:spreadsheet';
        $xml->registerXPathNamespace('ss', $ss);
        $worksheets = $xml->xpath('//ss:Worksheet');
        $worksheet = $worksheets[0] ?? null;
        if (!$worksheet) throw new RuntimeException('The spreadsheet does not contain a worksheet.');
        $worksheet->registerXPathNamespace('ss', $ss);
        $rows = $worksheet->xpath('.//ss:Table/ss:Row') ?: [];

        $matrix = [];
        foreach ($rows as $rowNode) {
            $rowNode->registerXPathNamespace('ss', $ss);
            $row = [];
            $column = 0;
            foreach ($rowNode->xpath('./ss:Cell') ?: [] as $cell) {
                $attrs = $cell->attributes($ss);
                if (isset($attrs['Index'])) $column = max(0, (int) $attrs['Index'] - 1);
                $cell->registerXPathNamespace('ss', $ss);
                $data = $cell->xpath('./ss:Data');
                $row[$column] = isset($data[0]) ? $this->cleanText((string) $data[0]) : '';
                $column++;
            }
            $matrix[] = $row === [] ? [] : array_map(fn ($i) => $row[$i] ?? '', range(0, max(array_keys($row))));
            if (count($matrix) > 10020) break;
        }

        return $this->matrixToRows($matrix);
    }

    /** @return array<int,string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $strings = @simplexml_load_string($xml);
        if (!$strings) return [];
        $strings->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $result = [];
        foreach ($strings->xpath('//m:si') ?: [] as $item) {
            $parts = [];
            $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($item->xpath('.//m:t') ?: [] as $text) $parts[] = (string) $text;
            $result[] = $this->cleanText(implode('', $parts));
        }
        return $result;
    }

    private function firstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) return 'xl/worksheets/sheet1.xml';

        $workbook = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if (!$workbook || !$rels) return 'xl/worksheets/sheet1.xml';

        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheet = ($workbook->xpath('//m:sheets/m:sheet') ?: [])[0] ?? null;
        if (!$sheet) return 'xl/worksheets/sheet1.xml';
        $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) ($attributes['id'] ?? '');
        if ($relationshipId === '') return 'xl/worksheets/sheet1.xml';

        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rels->xpath('//p:Relationship') ?: [] as $relationship) {
            if ((string) $relationship['Id'] !== $relationshipId) continue;
            $target = ltrim((string) $relationship['Target'], '/');
            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function xlsxCellValue(SimpleXMLElement $cell, array $shared): mixed
    {
        $type = (string) ($cell['t'] ?? '');
        if ($type === 'inlineStr') {
            $parts = [];
            foreach ($cell->is->t ?? [] as $text) $parts[] = (string) $text;
            if (isset($cell->is->r)) foreach ($cell->is->r as $run) $parts[] = (string) ($run->t ?? '');
            return $this->cleanText(implode('', $parts));
        }

        $raw = isset($cell->v) ? (string) $cell->v : '';
        if ($type === 's') return $shared[(int) $raw] ?? '';
        if ($type === 'b') return $raw === '1' ? 'TRUE' : 'FALSE';
        if ($type === 'str') return $this->cleanText($raw);
        return $this->cleanText($raw);
    }

    private function columnIndex(string $reference): int
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $match)) return 0;
        $index = 0;
        foreach (str_split(strtoupper($match[1])) as $letter) $index = ($index * 26) + (ord($letter) - 64);
        return max(0, $index - 1);
    }

    /** @return array{headers: array<int,string>, rows: array<int,array<string,mixed>>, header_row:int} */
    private function matrixToRows(array $matrix): array
    {
        $headerIndex = $this->findHeaderRow($matrix);
        if ($headerIndex === null) {
            throw new RuntimeException('Could not find the import header row. Required columns are Client ID, Client Reference Number, Shipping Address, Postal Code and Product ID. Use the current FlowTrack bulk order template or keep those column names intact.');
        }

        $headers = array_map(fn ($value) => trim((string) $value), $matrix[$headerIndex]);
        $rows = [];
        for ($i = $headerIndex + 1; $i < count($matrix); $i++) {
            $source = $matrix[$i] ?? [];
            $mapped = [];
            $hasValue = false;
            foreach ($headers as $column => $header) {
                if ($header === '') continue;
                $value = $source[$column] ?? '';
                if (is_string($value)) $value = $this->cleanText($value);
                if ($value !== '' && $value !== null) $hasValue = true;
                $mapped[$header] = $value;
            }
            if (!$hasValue) continue;
            $mapped['__source_row'] = $i + 1;
            $rows[] = $mapped;
            if (count($rows) > 10000) throw new RuntimeException('The file contains more than 10,000 data rows. Split it into smaller imports.');
        }

        return ['headers' => $headers, 'rows' => $rows, 'header_row' => $headerIndex + 1];
    }

    private function findHeaderRow(array $matrix): ?int
    {
        foreach (array_slice($matrix, 0, 30, true) as $index => $row) {
            $keys = array_map(fn ($value) => $this->normalizeHeader((string) $value), $row);

            // Current Bulk Order template mirrors Create Order: Client ID,
            // Client Reference Number, Shipping Address, Postal Code and
            // Product ID are required. The legacy Reference Order No. header is
            // still recognized so older files can be reviewed under the new
            // validation rules, but Order Title is no longer an input column.
            $hasClientReference = in_array('clientreferencenumber', $keys, true)
                || in_array('referenceorderno', $keys, true);

            if (in_array('clientid', $keys, true)
                && $hasClientReference
                && in_array('shippingaddress', $keys, true)
                && in_array('postalcode', $keys, true)
                && in_array('productid', $keys, true)) {
                return (int) $index;
            }

            // Do not fall back to the old 13-column layout: Shipping Address
            // and Postal Code are now required for every new/updated Order.
        }
        return null;
    }

    private function normalizeHeader(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', trim($value)) ?? '');
    }

    private function cleanText(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        return trim(str_replace("\0", '', $value));
    }
}
