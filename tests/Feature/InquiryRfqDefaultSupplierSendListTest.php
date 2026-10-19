<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryRfqDefaultSupplierSendListTest extends TestCase
{
    public function test_default_product_supplier_is_rendered_in_send_list_before_email_delivery(): void
    {
        $service = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $pageData = file_get_contents(app_path('Livewire/Inquiries/Concerns/BuildsInquiryPageData.php'));
        $view = file_get_contents(resource_path('views/livewire/inquiries/sections/rfq.blade.php'));

        $this->assertStringContainsString('public function defaultSuppliersAwaitingSend', $service);
        $this->assertStringContainsString('defaultSupplierProductNames($inquiry)', $service);
        $this->assertStringContainsString('rfqDefaultSuppliers', $pageData);
        $this->assertStringContainsString('Default supplier', $view);
        $this->assertStringContainsString('Ready to send', $view);
        $this->assertStringContainsString('>Send</span>', $view);
        $this->assertStringContainsString('wire:click="inviteRfqSupplier({{ $defaultSupplier[\'id\'] }})"', $view);
    }

    public function test_default_supplier_is_not_duplicated_in_manual_invite_candidates(): void
    {
        $service = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));

        $this->assertStringContainsString('$defaultSupplierIds = $this->defaultSupplierProductNames($inquiry)', $service);
        $this->assertStringContainsString('->merge($defaultSupplierIds)', $service);
        $this->assertStringContainsString('$excludeSupplierIds', $service);
    }
}
