<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryComparisonStatementLayoutTest extends TestCase
{
    public function test_inquiry_comparison_statement_matches_supplier_column_matrix_layout(): void
    {
        $view = file_get_contents(resource_path('views/livewire/inquiries/sections/comparison.blade.php'));
        $css = file_get_contents(resource_path('css/modules/application/20-inquiry-rfq.css'));

        $this->assertStringContainsString('Supplier comparison statement', $view);
        $this->assertStringContainsString('Comparison criteria', $view);
        $this->assertStringContainsString('Select supplier', $view);
        $this->assertStringContainsString('Landed total', $view);
        $this->assertStringContainsString('Payment terms', $view);
        $this->assertStringContainsString('Supplier note', $view);
        $this->assertStringContainsString('Award selected supplier', $view);
        $this->assertStringContainsString('selectedSupplierId', $view);
        $this->assertStringContainsString('.ft-rfq-comparison-matrix', $css);
        $this->assertStringContainsString('.ft-rfq-comparison-award-bar', $css);
        $this->assertStringNotContainsString('ft-rfq-price-matrix-wrap', $view);
    }
}
