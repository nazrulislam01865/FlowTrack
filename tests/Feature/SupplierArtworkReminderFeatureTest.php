<?php

namespace Tests\Feature;

use App\Services\Reminders\SupplierArtworkReminderService;
use Tests\TestCase;

final class SupplierArtworkReminderFeatureTest extends TestCase
{
    public function test_supplier_reminder_is_unpublished_by_default(): void
    {
        $defaults = (new SupplierArtworkReminderService())->defaults();
        $this->assertSame('skip', $defaults['missing_email']);
        $this->assertFalse($defaults['sections']['internal']);
        $this->assertTrue($defaults['sections']['artwork']);
    }

    public function test_only_known_supplier_reminder_tokens_are_substituted(): void
    {
        $service = new SupplierArtworkReminderService();
        $result = $service->interpolate(
            'Order {{order_number}} for {{supplier_name}} / {{not_an_allowed_token}}',
            $service->sample(),
        );
        $this->assertSame('Order FT-10245 for Supplier A / ', $result);
    }

    public function test_guest_cannot_access_supplier_reminder_settings(): void
    {
        $this->get(route('supplier-reminder.index'))->assertRedirect(route('login'));
    }
}
