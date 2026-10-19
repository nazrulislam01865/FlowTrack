<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\SimplePdfDocument;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    private const LAYOUT_VERSION = 3;

    public function generate(Invoice $invoice): Invoice
    {
        $invoice->loadMissing([
            'items',
            'creator:id,name',
            'job.client',
            'job.owner:id,name',
        ]);

        $pdf = $this->render($invoice);
        $filename = $this->filename($invoice);
        $path = 'invoices/'.$invoice->flow_job_id.'/generated/'.$filename;
        $disk = Storage::disk('local');
        $oldPath = (string) ($invoice->pdf_path ?? '');

        $stored = $disk->put($path, $pdf);
        throw_if(!$stored, \RuntimeException::class, 'The generated invoice PDF could not be stored. Please try again.');

        try {
            $invoice->update([
                'pdf_path' => $path,
                'pdf_name' => $filename,
                'pdf_generated_at' => now(),
                'pdf_layout_version' => self::LAYOUT_VERSION,
            ]);
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        if ($oldPath !== '' && $oldPath !== $path) {
            $disk->delete($oldPath);
        }

        return $invoice->refresh();
    }

    public function ensure(Invoice $invoice): Invoice
    {
        $invoice->loadMissing('job.client');
        $updates = [];

        if (!is_array($invoice->company_snapshot) || blank($invoice->company_snapshot['legal_name'] ?? null)) {
            $updates['company_snapshot'] = app(CompanyProfileService::class)->invoiceSnapshot();
        }

        if (!is_array($invoice->client_snapshot) || blank($invoice->client_snapshot['name'] ?? null)) {
            $updates['client_snapshot'] = app(ClientInvoiceProfileService::class)->invoiceSnapshot($invoice->job?->client);
        }

        if ($updates !== []) {
            $invoice->update($updates);
            $invoice = $invoice->refresh();
        }

        $path = (string) ($invoice->pdf_path ?? '');
        $needsCurrentLayout = (int) ($invoice->pdf_layout_version ?? 0) < self::LAYOUT_VERSION;

        if (!$needsCurrentLayout && $path !== '' && Storage::disk('local')->exists($path)) {
            return $invoice;
        }

        return $this->generate($invoice);
    }

    public function filename(Invoice $invoice): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->invoice_number) ?: 'invoice-'.$invoice->id;
        return trim($base, '-').'.pdf';
    }

    private function render(Invoice $invoice): string
    {
        $job = $invoice->job;
        $client = $job?->client;
        $branding = app(BrandingService::class)->current();
        $workspaceName = trim((string) ($branding['name'] ?? 'FlowTrack')) ?: 'FlowTrack';

        $companyService = app(CompanyProfileService::class);
        $company = is_array($invoice->company_snapshot) && filled($invoice->company_snapshot['legal_name'] ?? null)
            ? $invoice->company_snapshot
            : $companyService->current();
        $companyName = trim((string) ($company['trading_name'] ?? ''))
            ?: trim((string) ($company['legal_name'] ?? ''))
            ?: $workspaceName;
        $legalName = trim((string) ($company['legal_name'] ?? '')) ?: $companyName;

        $clientService = app(ClientInvoiceProfileService::class);
        $clientProfile = is_array($invoice->client_snapshot) && filled($invoice->client_snapshot['name'] ?? null)
            ? $invoice->client_snapshot
            : $clientService->invoiceSnapshot($client);
        $clientName = trim((string) ($clientProfile['name'] ?? '')) ?: trim((string) ($client?->name ?? '')) ?: 'Client';
        $clientLegalName = trim((string) ($clientProfile['legal_name'] ?? '')) ?: $clientName;

        $currency = strtoupper((string) ($invoice->currency ?: 'USD'));
        $money = fn (float|int|string|null $amount): string => $this->money((float) ($amount ?? 0), $currency);
        $doc = new SimplePdfDocument();
        $logoPath = $this->brandingLogoPath($branding);

        $navy = [0.055, 0.145, 0.285];
        $blue = [0.08, 0.38, 0.82];
        $text = [0.08, 0.14, 0.24];
        $muted = [0.38, 0.44, 0.54];
        $soft = [0.97, 0.98, 0.995];
        $border = [0.84, 0.88, 0.93];

        $pageNumber = 0;
        $y = 0.0;
        $startPage = function (bool $continued = false) use (
            $doc,
            $logoPath,
            $companyName,
            $legalName,
            $invoice,
            $navy,
            $blue,
            $text,
            $muted,
            &$pageNumber,
            &$y
        ): void {
            if ($pageNumber > 0) {
                $doc->newPage();
            }
            $pageNumber++;

            if ($continued) {
                $logoDrawn = $this->drawLogo($doc, $logoPath, 42, 786, 92, 30);
                if (!$logoDrawn) {
                    $doc->text(42, 805, $this->plain($companyName), 12, true, $navy);
                }
                $doc->textRight(553, 806, 'INVOICE '.$this->plain((string) $invoice->invoice_number), 10, true, $text);
                $doc->textRight(553, 791, 'Continued', 8, false, $muted);
                $doc->fillRect(42, 775, 511, 2.5, $blue);
                $y = 756;
                return;
            }

            $logoDrawn = $this->drawLogo($doc, $logoPath, 42, 765, 130, 50);
            if (!$logoDrawn) {
                $doc->text(42, 801, $this->plain($companyName), 17, true, $navy);
                if ($legalName !== $companyName) {
                    $doc->text(42, 783, $this->plain($legalName), 8, false, $muted);
                }
            }

            $doc->textRight(553, 803, 'INVOICE', 27, true, $navy);
            $doc->textRight(553, 780, $this->plain((string) $invoice->invoice_number), 11, true, $text);
            $status = strtoupper($this->plain((string) ($invoice->status ?: 'sent')));
            $doc->textRight(553, 764, $status, 8, true, $blue);
            $doc->fillRect(42, 748, 511, 3, $blue);
            $y = 728;
        };

        $startPage(false);

        // Issuer and invoice metadata.
        $fromTop = $y;
        $leftWidth = 302.0;
        $rightX = 362.0;
        $rightWidth = 191.0;
        $boxHeight = 112.0;

        $doc->text(42, $fromTop, 'FROM', 8, true, $blue);
        $doc->text(42, $fromTop - 19, $this->plain($companyName), 12, true, $text);
        $companyY = $fromTop - 36;
        if ($legalName !== $companyName) {
            $doc->text(42, $companyY, $this->plain($legalName), 8, false, $muted);
            $companyY -= 12;
        }
        foreach (array_slice($companyService->addressLines($company), 0, 4) as $line) {
            $doc->text(42, $companyY, $this->plain($line), 8, false, $muted);
            $companyY -= 11;
        }
        $companyContact = [];
        if (filled($company['billing_email'] ?? null)) $companyContact[] = trim((string) $company['billing_email']);
        if (filled($company['phone'] ?? null)) $companyContact[] = trim((string) $company['phone']);
        if ($companyContact !== [] && $companyY > $fromTop - $boxHeight + 8) {
            $doc->text(42, $companyY, $this->plain(implode('  |  ', $companyContact)), 8, false, $muted);
        }

        $doc->fillRect($rightX, $fromTop - $boxHeight + 4, $rightWidth, $boxHeight - 4, $soft);
        $doc->rect($rightX, $fromTop - $boxHeight + 4, $rightWidth, $boxHeight - 4, 0.6, $border);
        $metaY = $fromTop - 18;
        $this->metaRow($doc, $rightX + 12, $rightX + $rightWidth - 12, $metaY, 'Issue date', $invoice->issue_date?->format('M j, Y') ?: '-', $muted, $text);
        $metaY -= 21;
        $this->metaRow($doc, $rightX + 12, $rightX + $rightWidth - 12, $metaY, 'Due date', $invoice->due_date?->format('M j, Y') ?: '-', $muted, $text);
        $metaY -= 21;
        $this->metaRow($doc, $rightX + 12, $rightX + $rightWidth - 12, $metaY, 'Currency', $currency, $muted, $text);
        $metaY -= 21;
        $type = trim(str_ireplace(' invoice', '', (string) $invoice->type));
        $this->metaRow($doc, $rightX + 12, $rightX + $rightWidth - 12, $metaY, 'Type', $type ?: '-', $muted, $text);

        $y = $fromTop - $boxHeight - 12;
        $doc->line(42, $y + 2, 553, $y + 2, 0.7, $border);
        $y -= 14;

        // Bill-to and order information.
        $billTop = $y;
        $doc->text(42, $billTop, 'BILL TO', 8, true, $blue);
        $doc->text(42, $billTop - 19, $this->plain($clientName), 12, true, $text);
        $billY = $billTop - 36;
        if ($clientLegalName !== $clientName) {
            $doc->text(42, $billY, $this->plain($clientLegalName), 8, false, $muted);
            $billY -= 12;
        }
        foreach (array_slice($clientService->addressLines($clientProfile), 0, 4) as $line) {
            $doc->text(42, $billY, $this->plain($line), 8, false, $muted);
            $billY -= 11;
        }
        if ($invoice->billing_contact_name && $billY > $billTop - 103) {
            $doc->text(42, $billY, 'Attn: '.$this->plain((string) $invoice->billing_contact_name), 8, false, $text);
            $billY -= 11;
        }
        $clientEmail = trim((string) ($invoice->billing_contact_email ?: ($clientProfile['email'] ?? '')));
        if ($clientEmail !== '' && $billY > $billTop - 112) {
            $doc->text(42, $billY, $this->plain($clientEmail), 8, false, $muted);
            $billY -= 11;
        }
        $clientPhone = trim((string) ($clientProfile['phone'] ?? ''));
        if ($clientPhone !== '' && $billY > $billTop - 123) {
            $doc->text(42, $billY, $this->plain($clientPhone), 8, false, $muted);
            $billY -= 11;
        }
        $clientWebsite = trim((string) ($clientProfile['website'] ?? ''));
        if ($clientWebsite !== '' && $billY > $billTop - 134) {
            $doc->text(42, $billY, $this->plain($clientWebsite), 8, false, $muted);
            $billY -= 11;
        }

        $orderX = 322.0;
        $doc->text($orderX, $billTop, 'ORDER DETAILS', 8, true, $blue);
        $detailY = $billTop - 19;
        $this->detailPair($doc, $orderX, 553, $detailY, 'Order no.', $job?->displayOrderNumber() ?: '-', $muted, $text);
        $detailY -= 20;
        if ($invoice->purchase_order_reference) {
            $this->detailPair($doc, $orderX, 553, $detailY, 'PO reference', (string) $invoice->purchase_order_reference, $muted, $text);
            $detailY -= 20;
        }
        if (filled($clientProfile['code'] ?? null)) {
            $this->detailPair($doc, $orderX, 553, $detailY, 'Client code', (string) $clientProfile['code'], $muted, $text);
            $detailY -= 20;
        }
        if (filled($clientProfile['tax_number'] ?? null)) {
            $this->detailPair($doc, $orderX, 553, $detailY, 'Client tax ID', (string) $clientProfile['tax_number'], $muted, $text);
            $detailY -= 20;
        }
        if (filled($company['registration_number'] ?? null)) {
            $this->detailPair($doc, $orderX, 553, $detailY, 'Company reg.', (string) $company['registration_number'], $muted, $text);
            $detailY -= 20;
        }
        if (filled($company['tax_number'] ?? null)) {
            $this->detailPair($doc, $orderX, 553, $detailY, 'Company tax ID', (string) $company['tax_number'], $muted, $text);
        }

        $y = min($billY, $detailY) - 20;
        if ($y > $billTop - 128) {
            $y = $billTop - 128;
        }

        // Product table. Values are the immutable invoice items created from the order products.
        $drawItemsHeader = function () use ($doc, &$y, $navy): void {
            $doc->fillRect(42, $y - 24, 511, 24, $navy);
            $doc->text(50, $y - 16, '#', 8, true, [1, 1, 1]);
            $doc->text(76, $y - 16, 'PRODUCT', 8, true, [1, 1, 1]);
            $doc->textCentered(349, $y - 16, 'QTY', 8, true, [1, 1, 1]);
            $doc->textRight(461, $y - 16, 'UNIT PRICE', 8, true, [1, 1, 1]);
            $doc->textRight(545, $y - 16, 'TOTAL', 8, true, [1, 1, 1]);
            $y -= 24;
        };

        $drawItemsHeader();
        foreach ($invoice->items->values() as $index => $item) {
            $description = $this->plain((string) $item->description);
            $descriptionLines = $this->wrapPlain($description, 43);
            $rowHeight = max(34, 14 + count($descriptionLines) * 11);
            if ($y - $rowHeight < 148) {
                $startPage(true);
                $drawItemsHeader();
            }

            if ($index % 2 === 1) {
                $doc->fillRect(42, $y - $rowHeight, 511, $rowHeight, [0.985, 0.989, 0.996]);
            }
            $doc->line(42, $y - $rowHeight, 553, $y - $rowHeight, 0.55, [0.89, 0.91, 0.94]);
            $doc->text(50, $y - 21, (string) ($index + 1), 8, false, $muted);
            $lineY = $y - 20;
            foreach ($descriptionLines as $line) {
                $doc->text(76, $lineY, $line, 9, false, $text);
                $lineY -= 11;
            }
            $doc->textCentered(349, $y - 21, $this->quantity((float) $item->quantity), 9, false, $text);
            $doc->textRight(461, $y - 21, $money($item->unit_price), 9, false, $text);
            $doc->textRight(545, $y - 21, $money($item->amount), 9, true, $text);
            $y -= $rowHeight;
        }

        if ($y < 315) {
            $startPage(true);
        } else {
            $y -= 18;
        }

        // Notes/payment instructions on the left and totals on the right.
        $sectionTop = $y;
        $leftX = 42.0;
        $leftWidth = 270.0;
        $leftY = $sectionTop;

        if ($invoice->notes) {
            $doc->text($leftX, $leftY, 'NOTES', 8, true, $blue);
            $note = $doc->wrappedText($leftX, $leftY - 17, $this->plain((string) $invoice->notes), $leftWidth, 8.5, 11, false, $muted, 5);
            $leftY = $note['bottom'] - 8;
        }

        $paymentLines = [];
        if (filled($company['bank_name'] ?? null)) $paymentLines[] = ['Bank', trim((string) $company['bank_name'])];
        if (filled($company['bank_account_name'] ?? null)) $paymentLines[] = ['Account name', trim((string) $company['bank_account_name'])];
        if (filled($company['bank_account_number'] ?? null)) $paymentLines[] = ['Account no.', trim((string) $company['bank_account_number'])];
        if (filled($company['bank_iban'] ?? null)) $paymentLines[] = ['IBAN', trim((string) $company['bank_iban'])];
        if (filled($company['bank_swift'] ?? null)) $paymentLines[] = ['SWIFT / BIC', trim((string) $company['bank_swift'])];

        if ($paymentLines !== []) {
            $doc->text($leftX, $leftY, 'PAYMENT DETAILS', 8, true, $blue);
            $leftY -= 17;
            foreach ($paymentLines as [$label, $value]) {
                $doc->text($leftX, $leftY, $label.':', 8, true, $text);
                $doc->wrappedText($leftX + 76, $leftY, $this->plain($value), $leftWidth - 76, 8, 10, false, $muted, 2);
                $leftY -= 13;
            }
        }
        if (filled($company['payment_instructions'] ?? null) && $leftY > 74) {
            $leftY -= 3;
            $doc->wrappedText($leftX, $leftY, $this->plain((string) $company['payment_instructions']), $leftWidth, 8, 10, false, $muted, 3);
        }

        $totalsX = 337.0;
        $totalsWidth = 216.0;
        $totalsHeight = 112.0;
        $doc->fillRect($totalsX, $sectionTop - $totalsHeight, $totalsWidth, $totalsHeight, $soft);
        $doc->rect($totalsX, $sectionTop - $totalsHeight, $totalsWidth, $totalsHeight, 0.6, $border);
        $labelX = $totalsX + 14;
        $valueRight = $totalsX + $totalsWidth - 14;
        $totalY = $sectionTop - 22;
        $doc->text($labelX, $totalY, 'Subtotal', 9, false, $muted);
        $doc->textRight($valueRight, $totalY, $money($invoice->subtotal), 9, true, $text);
        $totalY -= 23;
        $taxLabel = 'Tax '.rtrim(rtrim(number_format((float) $invoice->tax_rate, 2), '0'), '.').'%';
        $doc->text($labelX, $totalY, $taxLabel, 9, false, $muted);
        $doc->textRight($valueRight, $totalY, $money($invoice->tax_amount), 9, true, $text);
        $totalY -= 23;
        $doc->line($labelX, $totalY + 8, $valueRight, $totalY + 8, 0.7, $border);
        $doc->text($labelX, $totalY - 10, 'TOTAL DUE', 10, true, $navy);
        $doc->textRight($valueRight, $totalY - 11, $money($invoice->total), 13, true, $blue);

        // Footer always reflects the snapshotted company details.
        $footerY = 44.0;
        $doc->line(42, $footerY + 15, 553, $footerY + 15, 0.6, $border);
        $footer = trim((string) ($company['invoice_footer'] ?? ''));
        if ($footer !== '') {
            $doc->wrappedText(42, $footerY, $this->plain($footer), 340, 7, 9, false, [0.48, 0.53, 0.61], 2);
        } else {
            $doc->text(42, $footerY, 'Issued by '.$this->plain($legalName), 7, false, [0.48, 0.53, 0.61]);
        }
        $doc->textRight(553, $footerY, 'Invoice '.$this->plain((string) $invoice->invoice_number), 7, false, [0.48, 0.53, 0.61]);

        return $doc->output();
    }

    private function metaRow(SimplePdfDocument $doc, float $labelX, float $rightX, float $y, string $label, string $value, array $muted, array $text): void
    {
        $doc->text($labelX, $y, $label, 8, false, $muted);
        $doc->textRight($rightX, $y, $this->plain($value), 8.5, true, $text);
    }

    private function detailPair(SimplePdfDocument $doc, float $x, float $rightX, float $y, string $label, string $value, array $muted, array $text): void
    {
        $doc->text($x, $y, $label, 8, false, $muted);
        $doc->textRight($rightX, $y, $this->plain($value), 8.5, true, $text);
    }

    private function brandingLogoPath(array $branding): ?string
    {
        $path = trim((string) ($branding['logo_path'] ?? ''));
        if ($path === '') return null;

        try {
            $disk = Storage::disk('public');
            if (!$disk->exists($path)) return null;
            return $disk->path($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function drawLogo(SimplePdfDocument $doc, ?string $path, float $x, float $y, float $maxWidth, float $maxHeight): bool
    {
        if (!$path || !is_file($path)) return false;
        $info = @getimagesize($path);
        if (!$info || empty($info[0]) || empty($info[1])) return false;

        $scale = min($maxWidth / (float) $info[0], $maxHeight / (float) $info[1], 1.0);
        $width = max(1.0, (float) $info[0] * $scale);
        $height = max(1.0, (float) $info[1] * $scale);
        return $doc->image($path, $x, $y, $width, $height);
    }

    private function money(float $amount, string $currency): string
    {
        $prefix = match ($currency) {
            'USD' => '$',
            'EUR' => 'EUR ',
            'GBP' => 'GBP ',
            'CNY', 'RMB' => 'CNY ',
            default => $currency.' ',
        };
        return $prefix.number_format($amount, 2);
    }

    private function quantity(float $quantity): string
    {
        if (abs($quantity - round($quantity)) < 0.00001) {
            return number_format((int) round($quantity));
        }
        return rtrim(rtrim(number_format($quantity, 2), '0'), '.');
    }

    private function plain(string $value): string
    {
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** @return list<string> */
    private function wrapPlain(string $text, int $maxChars): array
    {
        $text = trim($text);
        if ($text === '') return [''];
        $words = preg_split('/\s+/', $text) ?: [$text];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if (strlen($candidate) > $maxChars && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: [''];
    }
}
