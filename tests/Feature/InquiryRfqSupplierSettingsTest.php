<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryRfqSupplierSettingsTest extends TestCase
{
    public function test_rfq_workspace_uses_settings_modal_instead_of_email_preview_gallery(): void
    {
        $workspace = file_get_contents(resource_path('views/components/inquiries/rfq-product-workspace.blade.php'));
        $detail = file_get_contents(resource_path('views/livewire/inquiries/sections/detail.blade.php'));
        $modal = file_get_contents(resource_path('views/components/inquiries-rfq-settings.blade.php'));
        $manager = file_get_contents(app_path('Livewire/Inquiries/Concerns/ManagesInquiryRfq.php'));

        $this->assertStringContainsString('wire:click="openRfqSettings"', $workspace);
        $this->assertStringContainsString('<span>RFQ settings</span>', $workspace);
        $this->assertStringContainsString('<x-inquiries-rfq-settings', $detail);
        $this->assertStringContainsString('Supplier quotation settings', $modal);
        $this->assertStringContainsString('Special note', $modal);
        $this->assertStringContainsString('Inquiry / product details for supplier', $modal);
        $this->assertStringContainsString('Secure link valid for', $modal);
        $this->assertStringContainsString('Send submission confirmation automatically', $modal);
        $this->assertStringContainsString('Send quotation deadline reminder', $modal);
        $this->assertStringContainsString('Allow supplier to revise after submission', $modal);
        $this->assertStringContainsString('Email the awarded supplier automatically', $modal);
        $this->assertStringContainsString('Email non-selected suppliers automatically', $modal);
        $this->assertStringContainsString('public function saveRfqSettings(): void', $manager);
        $this->assertStringNotContainsString('openRfqEmailPreview', $workspace);
    }

    public function test_supplier_settings_drive_secure_link_and_supplier_automation(): void
    {
        $service = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $portal = file_get_contents(app_path('Services/Inquiries/PublicRfqPortalService.php'));
        $email = file_get_contents(resource_path('views/emails/rfq/invitation.blade.php'));
        $product = file_get_contents(resource_path('views/components/rfq/public/product-to-quote.blade.php'));
        $migration = file_get_contents(database_path('migrations/2026_08_31_193500_add_inquiry_rfq_supplier_settings.php'));

        $this->assertStringContainsString("'link_expires_at' => now()->addHours", $service);
        $this->assertStringContainsString("auto_reply_enabled", $service);
        $this->assertStringContainsString("reminder_hours_before_due", $service);
        $this->assertStringContainsString("allow_revision", $service);
        $this->assertStringContainsString('linkExpired($invitation)', $portal);
        $this->assertStringContainsString('Special note from buyer', $email);
        $this->assertStringContainsString('Inquiry &amp; product details', $email);
        $this->assertStringContainsString('$invitation->supplier_details', $product);
        $this->assertStringContainsString("Schema::create('inquiry_rfq_settings'", $migration);
    }
}
