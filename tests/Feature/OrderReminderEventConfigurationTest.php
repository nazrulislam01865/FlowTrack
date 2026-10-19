<?php

namespace Tests\Feature;

use App\Services\Reminders\OrderReminderEventCatalog;
use App\Services\Reminders\SupplierArtworkReminderService;
use Tests\TestCase;

final class OrderReminderEventConfigurationTest extends TestCase
{
    public function test_artwork_event_preserves_the_original_reminder_defaults(): void
    {
        $config = app(SupplierArtworkReminderService::class)->defaults();
        $this->assertSame(OrderReminderEventCatalog::ARTWORK_EVENT, $config['event_key']);
        $this->assertTrue($config['sections']['artwork']);
        $this->assertSame('skip', $config['missing_email']);
    }

    public function test_non_artwork_event_uses_generic_email_copy_without_artwork_blocks(): void
    {
        $key = OrderReminderEventCatalog::eventFor('PROD_FINISH');
        $config = app(SupplierArtworkReminderService::class)->defaults($key);
        $this->assertSame('order_task:PROD_FINISH:completed', $key);
        $this->assertSame($key, $config['event_key']);
        $this->assertFalse($config['sections']['artwork']);
        $this->assertFalse($config['sections']['confirmation']);
        $this->assertStringContainsString('{{event_name}}', $config['subject']);
    }

    public function test_supplier_portal_rule_uses_supplier_only_email_without_order_sections(): void
    {
        $key = OrderReminderEventCatalog::PORTAL_ACCESS_EVENT;
        $config = app(SupplierArtworkReminderService::class)->defaults($key);
        $this->assertSame('supplier_portal:access_updated', $key);
        $this->assertSame('supplier', $config['recipient_type']);
        $this->assertFalse($config['sections']['order_summary']);
        $this->assertFalse($config['sections']['artwork']);
        $this->assertTrue($config['sections']['action']);
        $this->assertStringContainsString('supplier portal', strtolower($config['subject']));
    }

    public function test_artwork_confirmation_keeps_its_distinct_confirm_action(): void
    {
        $this->assertSame(OrderReminderEventCatalog::ARTWORK_EVENT, OrderReminderEventCatalog::eventFor('ART_INTERNAL_REVIEW'));
    }
}
