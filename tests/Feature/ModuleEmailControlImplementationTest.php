<?php

namespace Tests\Feature;

use Tests\TestCase;

class ModuleEmailControlImplementationTest extends TestCase
{
    public function test_admin_has_independent_workspace_scoped_inquiry_and_order_email_switches(): void
    {
        $control = file_get_contents(app_path('Services/Email/ModuleEmailControlService.php'));
        $admin = file_get_contents(app_path('Services/AdminService.php'));
        $livewire = file_get_contents(app_path('Livewire/Administration/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/administration/index.blade.php'));

        $this->assertStringContainsString("'INQUIRY_EMAIL_ENABLED'", $control);
        $this->assertStringContainsString("'ORDER_EMAIL_ENABLED'", $control);
        $this->assertStringContainsString("return true;", $control);
        $this->assertStringContainsString('AccessControlService::class)->isAdministrator($actor)', $control);
        $this->assertStringContainsString("'workspace_id' => \$workspaceId", $control);
        $this->assertStringContainsString("'access.email_service_changed'", $control);

        $this->assertStringContainsString('emailServiceSettings()', $admin);
        $this->assertStringContainsString('setEmailService(string $module, bool $enabled, User $actor)', $admin);
        $this->assertStringContainsString('setEmailService(string $module, bool $enabled)', $livewire);
        $this->assertStringContainsString("'settings' => ['emailServiceSettings' => \$service->emailServiceSettings()]", $livewire);
        $this->assertStringContainsString('Email service controls', $view);
        $this->assertStringContainsString('ft-email-service-segmented', $view);
        $this->assertStringContainsString('wire:click="setEmailService(', $view);
        $this->assertStringContainsString('requestDisableEmailService(string $module)', $livewire);
        $this->assertStringContainsString('confirmDisableEmailService()', $livewire);
        $this->assertStringContainsString('wire:click="requestDisableEmailService(', $view);
        $this->assertStringContainsString('ft-email-service-confirm-modal', $view);
        $this->assertStringContainsString('Turn off email service', $view);
        $this->assertStringNotContainsString('wire:confirm="Turn off', $view);
        $this->assertStringContainsString('Only Admin and Super Admin can change these switches.', $view);
    }

    public function test_delivery_and_workflow_paths_respect_module_switches_without_blocking_business_logic(): void
    {
        $central = file_get_contents(app_path('Services/Email/EmailService.php'));
        $inquiry = file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $orderWorkflow = file_get_contents(app_path('Services/Orders/OrderWorkflowEmailService.php'));
        $invoice = file_get_contents(app_path('Actions/Orders/EmailOrderInvoice.php'));
        $finance = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderFinance.php'));

        $this->assertStringContainsString('moduleForContext($message->context)', $central);
        $this->assertStringContainsString("'flowtrack.email.suppressed'", $central);
        $this->assertStringContainsString("'module_email_service_disabled'", $central);

        $this->assertStringContainsString('$emailEnabled = $this->emailControl->inquiryEnabled();', $inquiry);
        $this->assertStringContainsString("['email_status' => 'Email disabled']", $inquiry);
        $this->assertStringContainsString("Inquiry email service is currently disabled by an administrator.", $inquiry);
        $this->assertStringContainsString("'email_service_disabled' => true", $inquiry);
        $this->assertStringContainsString("if (! \$this->emailControl->inquiryEnabled()) return ['sent' => 0, 'failed' => 0];", $inquiry);

        $this->assertStringContainsString('if (! $this->emailControl->orderEnabled())', $orderWorkflow);
        $this->assertStringContainsString("'job.workflow_email_skipped'", $orderWorkflow);
        $this->assertStringContainsString("'email_service_disabled' => true", $orderWorkflow);
        $this->assertStringContainsString('return $trackingId;', $orderWorkflow);

        $this->assertStringContainsString('! $this->emailControl->orderEnabled()', $invoice);
        $this->assertStringContainsString('ModuleEmailControlService::class)->orderEnabled()', $finance);
    }
}
