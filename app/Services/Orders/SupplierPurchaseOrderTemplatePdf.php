<?php

namespace App\Services\Orders;

use App\Support\SimplePdfDocument;

/**
 * Prints live order values over the unmodified, A4, bilingual purchase-order
 * sheet supplied by the business. The PNG is a high-resolution print of that
 * exact spreadsheet: no layout recreation, HTML renderer, shell command,
 * font-package dependency or external conversion is required on the server.
 *
 * The official source XLSX is retained beside the printed background.
 */
final class SupplierPurchaseOrderTemplatePdf
{
    private const SHEET_WIDTH = 992.0;
    private const SHEET_HEIGHT = 1404.0;
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    /** @param list<array{name:string,quantity:int,supplier_unit_cost:?float,sku?:string}> $items */
    public function render(
        string $poNumber,
        string $supplierName,
        string $currency,
        array $items,
        ?string $deliveryDate = null,
        string $buyerName = 'Step Promo & Accessories Co Ltd.',
        array $details = [],
    ): string {
        $template = resource_path('templates/purchase-orders/official-purchase-order.png');
        if (!is_file($template)) {
            throw new \RuntimeException('The official Purchase Order template image is missing.');
        }
        $pdf = new SimplePdfDocument();
        $pages = array_chunk($items, 6);
        if (!$pages) $pages = [[]];
        $totalQty = array_sum(array_map(fn (array $item): int => (int) $item['quantity'], $items));
        $allHaveCost = count($items) > 0;
        $totalCost = 0.0;
        foreach ($items as $item) {
            if ($item['supplier_unit_cost'] === null) $allHaveCost = false;
            if ($item['supplier_unit_cost'] !== null) {
                $totalCost += ((float) $item['supplier_unit_cost']) * ((int) $item['quantity']);
            }
        }

        foreach ($pages as $pageIndex => $lines) {
            if ($pageIndex > 0) $pdf->newPage();
            if (!$pdf->image($template, 0, 0, self::PAGE_WIDTH, self::PAGE_HEIGHT)) {
                throw new \RuntimeException('Unable to render the official Purchase Order template.');
            }
            // These cells are intentionally left empty in the supplied sheet.
            $this->write($pdf, 232, 244, $poNumber, 7.2, 192);
            $this->write($pdf, 559, 244, date('d M Y'), 7.2, 337);
            $this->write($pdf, 232, 265, $buyerName, 5.7, 192);
            $this->write($pdf, 232, 288, $supplierName, 6.8, 192);
            if ($deliveryDate) $this->write($pdf, 559, 309, $deliveryDate, 7.0, 337);
            // Production lines and packing summary occupy the original six
            // printed template rows. Additional items use an identical page.
            $pageQty = 0;
            $pageCost = 0.0;
            $pageAllHaveCost = count($lines) > 0;
            foreach ($lines as $i => $item) {
                $qty = (int) $item['quantity'];
                $pageQty += $qty;
                $top = 449.5 + $i * 15.5;
                $sku = trim((string) ($item['sku'] ?? ''));
                $name = trim((string) ($item['name'] ?? ''));
                $this->write($pdf, 165, $top, $sku, 5.8, 59);
                $this->write($pdf, 232, $top, $name, 5.8, 82);
                $this->writeRight($pdf, 549, $top, number_format($qty), 6.0);
                $this->write($pdf, 563, $top, 'PCS', 5.8, 27);
                // The Remarks column retains the full product name when it
                // cannot fit inside the narrow original Description column.
                if (strlen($name) > 23) {
                    $this->write($pdf, 713, $top, $name, 5.0, 194);
                }
                if ($item['supplier_unit_cost'] === null) $pageAllHaveCost = false;
                if ($item['supplier_unit_cost'] !== null) {
                    $cost = (float) $item['supplier_unit_cost'];
                    $amount = $cost * $qty;
                    $pageCost += $amount;
                    $this->writeRight($pdf, 639, $top, number_format($cost, 2), 5.8);
                    $this->writeRight($pdf, 700, $top, number_format($amount, 2), 5.8);
                }
                $packTop = 707.0 + $i * 15.5;
                $this->write($pdf, 47, $packTop, $sku, 5.7, 110);
                $this->writeRight($pdf, 548, $packTop, number_format($qty), 5.8);
            }
            // The printed blank template contains zero-value Excel formulas.
            // Clear only those formula characters (not the original table
            // borders) and replace with the actual page figures.
            $this->clearValue($pdf, 516, 535, 36, 11);
            $this->writeRight($pdf, 547, 542, number_format($pageQty), 6.2);
            $this->clearValue($pdf, 664, 535, 37, 11);
            if ($pageAllHaveCost) $this->writeRight($pdf, 699, 542, number_format($pageCost, 2), 6.2);
            $this->write($pdf, 112, 578, $currency, 6.2, 52);
            $this->write($pdf, 302, 578, number_format($totalQty), 6.1, 56);
            if ($allHaveCost) $this->write($pdf, 516, 578, number_format($totalCost, 2), 6.1, 80);
            // Matching packing QTY and TOTAL cells are formula-backed as well.
            $this->clearValue($pdf, 513, 792, 39, 13);
            $this->writeRight($pdf, 547, 805, number_format($pageQty), 6.0);
            // The supplied spreadsheet prints zero formulas for carton count,
            // net weight and gross weight. Those packing measures are unknown
            // until explicitly provided and must never appear as fabricated 0s.
            // Clear just the printed formula glyphs, leaving the original grid.
            $this->clearValue($pdf, 557, 792, 33, 13);
            $this->clearValue($pdf, 596, 792, 46, 13);
            $this->clearValue($pdf, 650, 792, 54, 13);
            $this->renderCartonAndPayment($pdf, $details, $currency);
        }
        return $pdf->output();
    }

    /** Overlay only the blank fields and checkboxes of sections 4 and 5. */
    private function renderCartonAndPayment(SimplePdfDocument $pdf, array $d, string $currency): void
    {
        $value = fn (string $key): string => trim((string) ($d[$key] ?? ''));
        // Section 4 - the bilingual labels, border lines and printed options
        // remain part of the supplied template image, entirely unchanged.
        $this->write($pdf, 281, 890, $value('carton_buyer'), 4.5, 25);
        $this->write($pdf, 368, 890, $value('carton_style'), 4.5, 30);
        $this->write($pdf, 457, 890, $value('carton_colour'), 4.5, 29);
        $this->write($pdf, 546, 890, $value('carton_size'), 4.5, 28);
        // Carton-mark quantity is not the same as total ordered units.
        // Only print it when explicitly supplied; do not infer packing details.
        $this->write($pdf, 94, 909, $value('carton_qty'), 5.0, 36);
        $this->write($pdf, 203, 909, $value('carton_gross_weight'), 5.0, 29);
        $this->write($pdf, 292, 909, $value('carton_net_weight'), 5.0, 30);
        $this->write($pdf, 466, 909, $value('carton_measurement'), 5.0, 39);
        $this->write($pdf, 601, 909, $value('carton_other'), 4.8, 120);

        $this->mark($pdf, $value('carton_mark_type'), [
            'side' => [237, 926], 'two' => [327, 926], 'four' => [405, 926],
            'barcode' => [495, 926], 'fragile' => [583, 926],
        ]);
        $this->mark($pdf, $value('carton_strength'), [
            '5ply' => [237, 943], '3ply' => [309, 943], 'export' => [372, 943],
        ]);
        $this->mark($pdf, $value('inner_packing'), [
            'polybag' => [237, 959], 'tissue' => [502, 959], 'desiccant' => [581, 959],
        ]);
        $this->write($pdf, 346, 959, $value('pieces_per_inner'), 5.0, 48);
        $this->write($pdf, 731, 943, $value('carton_strength_other'), 4.8, 170);
        $this->write($pdf, 784, 959, $value('inner_packing_other'), 4.8, 120);

        // Section 5 - leave financial commitments and bank details blank
        // unless provided explicitly. Currency comes from the Order.
        $this->mark($pdf, $value('payment_method'), [
            'tt' => [166, 1031], 'lc' => [549, 1031], 'dp' => [665, 1031], 'other' => [747, 1031],
        ]);
        if ($value('payment_method') === 'tt') {
            $this->write($pdf, 229, 1032, $value('deposit_percent'), 5.5, 35);
            $this->write($pdf, 408, 1032, $value('balance_percent'), 5.5, 35);
        }
        if ($value('payment_method') === 'other') $this->write($pdf, 835, 1032, $value('payment_other'), 5.5, 88);
        $cur = strtoupper($currency);
        $this->mark($pdf, $cur, ['USD' => [166, 1047], 'EUR' => [209, 1047], 'RMB' => [263, 1047], 'HKD' => [307, 1047]]);
        if (!in_array($cur, ['USD','EUR','RMB','HKD'], true)) {
            $this->mark($pdf, 'other', ['other' => [365, 1047]]);
            $this->write($pdf, 451, 1047, $cur, 5.7, 85);
        }
        $this->mark($pdf, $value('payment_term'), [
            '30_70' => [166, 1062], '50_50' => [550, 1062],
            'net30' => [618, 1062], 'net60' => [743, 1062],
        ]);
        foreach (['beneficiary' => 1078, 'bank_name' => 1094, 'bank_address' => 1110, 'account_number' => 1126, 'swift_code' => 1142] as $field => $top) {
            $this->write($pdf, 172, $top, $value($field), 6.0, 735);
        }
    }

    /** Original template uses square checkbox outlines; a small X marks a chosen option. */
    private function mark(SimplePdfDocument $pdf, string $chosen, array $points): void
    {
        if ($chosen !== '' && isset($points[$chosen])) {
            [$x, $y] = $points[$chosen];
            $this->write($pdf, $x, $y, 'X', 5.2, 8);
        }
    }

    private function write(SimplePdfDocument $pdf, float $x, float $top, string $value, float $size, float $maxPixels): void
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($value === '') return;
        // The embedded business sheet supplies all Chinese labels. Dynamic
        // values are drawn with the shared lightweight PDF Helvetica font.
        $value = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value) ?: '';
        $maxChars = max(1, (int) floor(($maxPixels * self::PAGE_WIDTH / self::SHEET_WIDTH) / ($size * .53)));
        if (strlen($value) > $maxChars) $value = substr($value, 0, max(1, $maxChars - 3)).'...';
        $pdf->text($x * self::PAGE_WIDTH / self::SHEET_WIDTH, (self::SHEET_HEIGHT - $top) * self::PAGE_HEIGHT / self::SHEET_HEIGHT, $value, $size, false, [0.12, 0.16, 0.23]);
    }

    private function writeRight(SimplePdfDocument $pdf, float $right, float $top, string $value, float $size): void
    {
        $pdf->textRight($right * self::PAGE_WIDTH / self::SHEET_WIDTH, (self::SHEET_HEIGHT - $top) * self::PAGE_HEIGHT / self::SHEET_HEIGHT, $value, $size, false, [0.12, 0.16, 0.23]);
    }

    private function clearValue(SimplePdfDocument $pdf, float $x, float $top, float $width, float $height): void
    {
        $pdf->fillRect($x * self::PAGE_WIDTH / self::SHEET_WIDTH, (self::SHEET_HEIGHT - $top - $height) * self::PAGE_HEIGHT / self::SHEET_HEIGHT,
            $width * self::PAGE_WIDTH / self::SHEET_WIDTH, $height * self::PAGE_HEIGHT / self::SHEET_HEIGHT, [0.94, 0.94, 0.94]);
    }
}
