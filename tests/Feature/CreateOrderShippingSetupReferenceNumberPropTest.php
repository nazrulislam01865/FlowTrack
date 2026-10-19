<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateOrderShippingSetupReferenceNumberPropTest extends TestCase
{
    public function test_reference_number_is_forwarded_through_the_create_order_component_boundary(): void
    {
        $indexView = file_get_contents(resource_path('views/livewire/jobs/index.blade.php'));
        $createView = file_get_contents(resource_path('views/components/jobs/create.blade.php'));

        $this->assertStringContainsString(':reference-number="$referenceNumber"', $indexView);
        $this->assertStringContainsString("'referenceNumber'=>'',", $createView);
        $this->assertStringContainsString(':reference-number="$referenceNumber"', $createView);
    }
}
