<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquiryRfqMissingEmailSelectableTest extends TestCase
{
    public function test_active_suppliers_without_email_remain_selectable_in_create_and_detail_rfq(): void
    {
        $service = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $choice = file_get_contents(resource_path('views/components/inquiries/rfq-supplier-choice.blade.php'));
        $createRfq = file_get_contents(app_path('Livewire/Inquiries/Concerns/ManagesInquiryCreateRfq.php'));
        $detailView = file_get_contents(resource_path('views/livewire/inquiries/sections/rfq.blade.php'));

        $this->assertStringContainsString('$invitable = $isActive;', $service);
        $this->assertStringContainsString("'email_ready' => \$validEmail", $service);
        $this->assertStringContainsString("'email_status' => \$emailReady ? 'Sending' : 'No email'", $service);
        $this->assertStringContainsString('if (! $emailReady)', $service);
        $this->assertStringContainsString('was added to the RFQ without sending email.', $service);

        $this->assertStringContainsString('@disabled(! $invitable)', $choice);
        $this->assertStringContainsString('No email configured', $choice);
        $this->assertStringNotContainsString('do not have a valid email address', $createRfq);
        $this->assertStringContainsString('selectableSuppliersByIds', $createRfq);

        $this->assertStringContainsString("\$candidateEmailReady ? 'Invite' : 'Add'", $detailView);
        $this->assertStringContainsString("\$defaultEmailReady ? 'Send' : 'Add'", $detailView);
        $this->assertStringContainsString('sendExistingRfqInvitation', $detailView);
    }

    public function test_unsent_no_email_participants_can_be_emailed_later_and_are_not_reminded_early(): void
    {
        $service = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $livewire = file_get_contents(app_path('Livewire/Inquiries/Concerns/ManagesInquiryRfq.php'));

        $this->assertStringContainsString('public function sendExistingInvitation', $service);
        $this->assertStringContainsString("->where('email_status', 'Delivered')", $service);
        $this->assertStringContainsString('public function sendExistingRfqInvitation', $livewire);
        $this->assertStringContainsString('added to the RFQ. No email was sent', $livewire);
    }
}
