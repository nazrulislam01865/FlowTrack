<?php

namespace App\Services;

use App\Models\MasterRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductCatalogExportService
{
    /** Write the selected or filtered workspace products directly to the download stream. */
    public function write(Builder $query, int $workspaceId): void
    {
        $canViewSupplierPrices = app(\App\Services\Orders\SupplierCostVisibility::class)->allowed();
        $headers = [
            'Product code', 'Reference product code', 'Product name', 'Description',
            'Main category', 'Product category', 'Subcategory', 'Product size',
            'Tagged suppliers', 'Supplier short codes', 'Default supplier',
            'Client availability', 'Status', 'Supplier price by quantity', 'Customer price by quantity',
            'Remote surcharge by quantity', 'Product options', 'Shipping urgencies',
            'Test certificate number', 'Certificate & test report', 'Certificate URL',
            'Product template', 'Product template URL', 'Product image URL',
            'Created by', 'Created at (UTC)', 'Updated at (UTC)',
        ];

        $book = new Spreadsheet();
        $book->getProperties()->setCreator('FlowTracker')->setTitle('Product catalog details');
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Products');
        $sheet->setShowGridlines(false);
        $this->writeRow($sheet, 1, $headers);

        $row = 2;
        $hasLinksTable = Schema::hasTable('product_supplier_links');
        $lookup = app(ProductSupplierLookup::class);

        $query->select('master_records.*')
            ->with(['parent:id,name,metadata', 'creator:id,name'])
            ->reorder()
            ->chunkById(200, function ($products) use ($sheet, &$row, $lookup, $workspaceId, $hasLinksTable, $canViewSupplierPrices): void {
                $suppliersByProduct = $lookup->forProducts($products, $workspaceId, $hasLinksTable);
                foreach ($products as $product) {
                    $suppliers = $suppliersByProduct->get((int) $product->id, collect());
                    $default = $suppliers->firstWhere('id', $product->productSupplierId());
                    $documents = collect($product->productDocuments())->keyBy('kind');
                    $certificate = $documents->get('certificate');
                    $template = $documents->get('template');
                    $options = collect($product->productOptions())->map(fn (array $option) =>
                        $option['label'].' (extra '.$this->number($option['extra_charge']).')'
                        .($option['image_url'] ? ' ['.$this->absoluteUrl($option['image_url']).']' : '')
                    )->implode("\n");
                    $urgencies = collect($product->productShipmentUrgencyOptions())->map(fn (array $option) =>
                        ($option['shipment_urgency_name'] ?: $option['shipment_urgency_code'])
                        .' ('.$option['shipment_urgency_code'].'; extra '.$this->number($option['extra_charge']).')'
                    )->implode("\n");

                    $this->writeRow($sheet, $row++, [
                        $product->productDisplayCode(),
                        $product->productReferenceCode(),
                        $product->name,
                        trim(strip_tags((string) $product->description)),
                        $product->productMainCategory(),
                        $product->parent?->name,
                        trim((string) (data_get($product->metadata, 'sub_category') ?: data_get($product->metadata, 'excel_sub_category'))),
                        $product->productSize(),
                        $suppliers->pluck('name')->implode("\n"),
                        $suppliers->map(fn (MasterRecord $supplier) => $supplier->supplierShortCode())->implode(', '),
                        $default?->name,
                        implode(', ', $product->productAvailabilityLabels()),
                        ucfirst((string) $product->status),
                        $canViewSupplierPrices ? $this->priceRows($product->supplierPriceBreakpoints()) : 'Restricted',
                        $this->priceRows($product->customerPriceBreakpoints()),
                        $this->priceRows($product->productRemoteSurchargeBreakpoints()),
                        $options,
                        $urgencies,
                        (string) data_get($product->metadata, 'test_certificate_number', ''),
                        $certificate['label'] ?? '',
                        $this->absoluteUrl($certificate['url'] ?? null),
                        $template['label'] ?? '',
                        $this->absoluteUrl($template['url'] ?? null),
                        $this->absoluteUrl($product->productImageUrl()),
                        $product->creator?->name,
                        $product->created_at?->copy()->utc()->format('Y-m-d H:i:s'),
                        $product->updated_at?->copy()->utc()->format('Y-m-d H:i:s'),
                    ]);
                }
            }, 'master_records.id', 'id');

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->freezePane('D2');
        $sheet->setAutoFilter('A1:'.$lastColumn.max(1, $row - 1));
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF087F75');
        $sheet->getRowDimension(1)->setRowHeight(27);
        $sheet->getStyle('A1:'.$lastColumn.max(1, $row - 1))->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $width = match (true) {
                str_contains(strtolower($header), 'url') => 40,
                preg_match('/description|suppliers|price by|surcharge by|options|urgencies/i', $header) === 1 => 38,
                preg_match('/name|category|availability|certificate|template/i', $header) === 1 => 25,
                default => 20,
            };
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save('php://output');
        $book->disconnectWorksheets();
    }

    private function writeRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $index => $value) {
            // Explicit text keeps product codes and user-supplied names intact and
            // prevents spreadsheet formulas in imported product content executing.
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($index + 1).$row,
                mb_substr((string) ($value ?? ''), 0, 32767),
                DataType::TYPE_STRING
            );
        }
    }

    private function priceRows(array $rows): string
    {
        return collect($rows)->map(fn (array $price) =>
            $price['quantity'].' pcs: '.$this->number($price['price'])
        )->implode("\n");
    }

    private function number(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
    }

    private function absoluteUrl(?string $url): string
    {
        if (! $url) return '';
        return str_starts_with($url, '/') ? url($url) : $url;
    }
}
