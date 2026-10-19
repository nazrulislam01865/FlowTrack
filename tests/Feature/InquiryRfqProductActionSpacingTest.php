<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryRfqProductActionSpacingTest extends TestCase
{
    public function test_rfq_product_supplier_actions_keep_a_safe_inset_from_the_table_edge(): void
    {
        $css = file_get_contents(
            resource_path('css/modules/application/24-inquiry-rfq-product-workspace.css')
        );

        $this->assertStringContainsString(
            '.ft-rfq-px-table th:last-child,',
            $css
        );

        $this->assertStringContainsString(
            'width: 156px;',
            $css
        );

        $this->assertStringContainsString(
            'padding-right: var(--ft-space-3);',
            $css
        );

        $this->assertStringContainsString(
            '.ft-rfq-px-action-col .ft-rfq-px-row-action',
            $css
        );

        $this->assertStringContainsString(
            'width: max-content;',
            $css
        );

        $this->assertStringContainsString(
            'width: fit-content;',
            $css
        );
    }
}
