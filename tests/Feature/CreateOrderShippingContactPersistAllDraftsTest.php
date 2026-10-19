<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateOrderShippingContactPersistAllDraftsTest extends TestCase
{
    public function test_create_order_persists_every_completed_shipping_contact_draft(): void
    {
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));

        $this->assertStringContainsString('$this->rememberShippingContactDraft($this->shippingContactType);', $creation);
        $this->assertStringContainsString("foreach (['end_customer', 'middle_client', 'other_contact'] as $type)", $creation);
        $this->assertStringContainsString("if (\$name === '' || \$phone === '') continue;", $creation);
        $this->assertStringContainsString('app(SaveClientOrderContact::class)->execute(', $creation);
        $this->assertStringContainsString('app(SaveClientDeliveryContact::class)->execute(', $creation);
    }
}
