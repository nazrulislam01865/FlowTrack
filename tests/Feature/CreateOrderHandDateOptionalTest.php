<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateOrderHandDateOptionalTest extends TestCase
{
    public function test_create_order_hand_date_remains_optional_end_to_end(): void
    {
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $dto = file_get_contents(app_path('DTOs/Orders/OrderCreateData.php'));
        $migration = file_get_contents(database_path('migrations/2026_08_04_000100_create_flowtrack_core_tables.php'));

        $this->assertStringContainsString("'deliveryDate' => ['nullable','date']", $creation);
        $this->assertStringNotContainsString("'deliveryDate' => ['required','date']", $creation);
        $this->assertStringNotContainsString("'deliveryDate.required'", $creation);
        $this->assertStringContainsString("'delivery_date' => \$data['deliveryDate'] ?: null", $dto);
        $this->assertStringContainsString("\$table->date('delivery_date')->nullable();", $migration);
    }
}
