<?php

namespace Tests\Feature;

use App\Jobs\SendSupplierPortalAccessInvitation;
use App\Models\MasterRecord;
use App\Services\Reminders\SupplierPortalAccessReminderService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SupplierPortalAccessReminderTest extends TestCase
{
    public function test_queues_one_access_email_after_successful_provision(): void
    {
        Bus::fake();
        Cache::shouldReceive('remember')->once()->andReturn([
            'supplier_portal:access_updated' => [27 => [
                'event_key' => 'supplier_portal:access_updated',
                'subject' => 'Your supplier portal access is ready', 'message' => 'Welcome',
                'delay_minutes' => 0,
            ]],
        ]);
        $supplier = new MasterRecord();
        $supplier->forceFill(['id' => 42, 'workspace_id' => 5]);

        app(SupplierPortalAccessReminderService::class)->queue($supplier, 'SUPPLIER@example.com', 'Test.User', true);

        Bus::assertDispatched(SendSupplierPortalAccessInvitation::class, function ($job) {
            return $job->workspaceId === 5 && $job->supplierId === 42
                && $job->recipient === 'supplier@example.com' && $job->username === 'test.user'
                && $job->sendSetupLink && $job->templateId === 27;
        });
    }

    public function test_disabled_notification_does_not_queue(): void
    {
        Bus::fake();
        Cache::shouldReceive('remember')->once()->andReturn([]);
        $supplier = new MasterRecord();
        $supplier->forceFill(['id' => 42, 'workspace_id' => 5]);

        app(SupplierPortalAccessReminderService::class)->queue($supplier, 'supplier@example.com', 'test.user', true);

        Bus::assertNotDispatched(SendSupplierPortalAccessInvitation::class);
    }

    public function test_email_escapes_supplier_supplied_values_and_has_no_password(): void
    {
        $html = app(SupplierPortalAccessReminderService::class)->emailHtml(
            '<img src=x onerror=alert(1)>', 'supplier@example.com', '<script>alert(1)</script>',
            ['message' => '<b>Injected</b>'], 'https://supplier.example.com/login',
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Injected</b>', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('supplier@example.com', $html);
        $this->assertStringContainsString('https://supplier.example.com/login', $html);
        $this->assertStringContainsString('Supplier Portal Access', $html);
    }

    public function test_password_is_never_embedded_in_queued_notification(): void
    {
        $source = file_get_contents(app_path('Jobs/SendSupplierPortalAccessInvitation.php'));
        $create = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesSupplierCreation.php'));
        $edit = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesSupplierDetails.php'));
        $this->assertStringNotContainsString('portalPassword,', $source);
        $this->assertStringContainsString('->queue($supplier, $this->portalEmail, $this->portalUsername, true)', $create);
        $this->assertStringContainsString('if (! $linked || $emailChanged || $usernameChanged || $newPassword !==', $edit);
    }
}
