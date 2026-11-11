<?php

namespace Tests\Feature;

use App\Models\MasterRecord;
use Tests\TestCase;

final class ProductPriceCurrencySelectionTest extends TestCase
{
    public function test_currency_defaults_to_legacy_usd_and_supports_independent_supplier_and_client_codes(): void
    {
        $legacy = new MasterRecord(['type' => 'product', 'metadata' => [
            'price_breakpoints' => [['quantity' => 100, 'price' => 8.50]],
        ]]);
        $this->assertSame('USD', $legacy->supplierPriceCurrency());
        $this->assertSame('USD', $legacy->supplierPriceCurrency(10));
        $this->assertSame('USD', $legacy->clientPriceCurrency());

        $record = new MasterRecord(['type' => 'product', 'metadata' => [
            'supplier_price_currency' => 'CNY',
            'supplier_price_currencies' => ['10' => 'USD'],
            'customer_price_currency' => 'GBP',
            'client_price_currencies' => ['20' => 'EUR'],
            'price_breakpoints' => [['quantity' => 100, 'price' => 8.50]],
        ]]);
        $this->assertSame('CNY', $record->supplierPriceCurrency());
        $this->assertSame('USD', $record->supplierPriceCurrency(10));
        $this->assertSame('CNY', $record->supplierPriceCurrency(11));
        $this->assertSame('GBP', $record->clientPriceCurrency());
        $this->assertSame('EUR', $record->clientPriceCurrency(20));
        $this->assertSame('GBP', $record->clientPriceCurrency(21));
        $this->assertSame(8.50, $record->metadata['price_breakpoints'][0]['price']);
    }

    public function test_product_editor_uses_financial_master_currency_options_and_does_not_modify_prices_for_currency_only_edits(): void
    {
        $renderer = file_get_contents(app_path('Livewire/MasterData/Concerns/BuildsMasterDataPageData.php'));
        $editor = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));

        $this->assertStringContainsString("$"."service->active('currency')", $renderer);
        $this->assertStringContainsString("$"."service->currencyValue($"."currency)", $renderer);
        $this->assertStringContainsString('wire:model="productSupplierPrices.', $form);
        $this->assertStringContainsString('wire:model="productClientPrices.', $form);
        $this->assertStringContainsString('wire:model="productCustomerPriceCurrency"', $form);
        $this->assertStringContainsString("$"."metadata['supplier_price_currencies']", $editor);
        $this->assertStringContainsString("$"."metadata['client_price_currencies']", $editor);
        $this->assertStringContainsString("$"."metadata['customer_price_currency']", $editor);
        $this->assertStringContainsString('if ($parsed === $pricing->normalize($original)) continue;', $editor);
    }
}
