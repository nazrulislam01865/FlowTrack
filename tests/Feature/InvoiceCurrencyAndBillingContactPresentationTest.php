<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class InvoiceCurrencyAndBillingContactPresentationTest extends TestCase
{
    public function test_invoice_uses_business_currency_value_instead_of_master_record_code(): void
    {
        $master = file_get_contents(app_path('Services/MasterDataService.php'));
        $jobs = OrderPhase5Source::livewire();
        $modal = file_get_contents(resource_path('views/components/jobs/finance/create-invoice-modal.blade.php'));

        $this->assertStringContainsString('public function currencyValue(MasterRecord $currency): string', $master);
        $this->assertStringContainsString('$currency->name,', $master);
        $this->assertStringContainsString('$master->currencyValue($currency)', $jobs);
        $this->assertStringContainsString("->unique('value')", $modal);
        $this->assertStringContainsString("'USD' => '\$'", $modal);
        $this->assertStringNotContainsString('{{ $currencyCode }}', $modal);
        $this->assertStringNotContainsString('$option->code ?: $option->name', $modal);
    }

    public function test_invoice_billing_contact_dropdown_has_one_option_per_contact(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/finance/create-invoice-modal.blade.php'));

        $this->assertStringContainsString('$billingContacts = collect($contacts)', $modal);
        $this->assertStringContainsString('->unique(function ($contact)', $modal);
        $this->assertStringContainsString('@foreach($billingContacts as $contact)', $modal);
        $this->assertStringContainsString('Select billing contact', $modal);
        $this->assertStringNotContainsString('@foreach($contacts as $contact)', $modal);
    }
}
