<?php

namespace Tests\Unit;

use App\Services\Reminders\ReminderOverview;
use App\Services\Reminders\OrderReminderEventCatalog;
use PHPUnit\Framework\TestCase;

final class ReminderOverviewTest extends TestCase
{
    public function test_summary_uses_all_reminders_instead_of_one_active_recipient(): void
    {
        $templates = [
            ['active' => true, 'published' => true, 'config' => ['recipient_type' => 'supplier', 'event_key' => 'order_task:ART_INTERNAL_REVIEW:confirm']],
            ['active' => true, 'published' => true, 'config' => ['recipient_type' => 'owner', 'event_key' => 'order_task:OTHER:completed']],
            ['active' => true, 'published' => true, 'config' => ['recipient_type' => 'supplier', 'event_key' => OrderReminderEventCatalog::PORTAL_ACCESS_EVENT]],
            ['active' => false, 'published' => true, 'config' => ['recipient_type' => 'client']],
            ['active' => false, 'published' => false, 'config' => ['recipient_type' => 'coordinator']],
        ];
        $result = ReminderOverview::summarize($templates, [['key' => 'a'], ['key' => 'b']], false);
        self::assertSame(2, $result['eventCount']);
        self::assertSame('3 active', $result['status']);
        self::assertStringContainsString('1 paused · 1 draft', $result['statusHelp']);
        self::assertStringContainsString('2 order email rule(s) disabled globally', $result['statusHelp']);
        self::assertSame('Supplier, Order owner, Supplier portal login', $result['recipients']);
        self::assertSame('3 recipient type(s) across active reminders', $result['recipientHelp']);
    }

    public function test_empty_summary_has_no_fictitious_supplier_or_published_state(): void
    {
        $result = ReminderOverview::summarize([], [], true);
        self::assertSame('0 active', $result['status']);
        self::assertSame('None yet', $result['recipients']);
        self::assertSame(0, $result['eventCount']);
    }
}
