<?php

namespace Tests\Feature;

use App\DTOs\Orders\OrderCreateData;
use Tests\TestCase;

class CreateOrderAutomaticTitleTest extends TestCase
{
    public function test_create_order_form_requires_client_reference_and_has_no_manual_title_input(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/create.blade.php'));
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $index = file_get_contents(app_path('Livewire/Jobs/Index.php'));
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));
        $dto = file_get_contents(app_path('DTOs/Orders/OrderCreateData.php'));

        $this->assertStringContainsString('Client Reference Number *', $view);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="referenceNumber" required', $view);
        $this->assertStringNotContainsString('<b>Order Title</b>', $view);
        $this->assertStringNotContainsString('value="{{ $orderTitlePreview }}" readonly', $view);
        $this->assertStringNotContainsString('wire:model="jobTitle"', $view);
        $this->assertStringContainsString("'referenceNumber' => ['required','string','max:255']", $creation);
        $this->assertStringContainsString("'referenceNumber.required' => 'Client Reference Number is required.'", $creation);
        $this->assertStringNotContainsString("'jobTitle' => ['required'", $creation);
        $this->assertStringNotContainsString('public string $jobTitle', $index);
        $this->assertStringContainsString('public static function generateTitle', $dto);
        $this->assertStringContainsString("'job_number' => 'ORDER-'.app(WorkspaceSettingsService::class)->localNow()->format('Y')", $service);
    }

    public function test_automatic_title_uses_reference_and_single_product_name(): void
    {
        $payload = OrderCreateData::fromLivewire($this->baseData([
            ['product' => 'Premium Cotton T-Shirt', 'category' => 'T-Shirts', 'quantity' => 100],
        ]), false)->toArray();

        $this->assertSame('FO-333119 – Premium Cotton T-Shirt', $payload['title']);
        $this->assertSame('FO-333119', $payload['order_number']);
    }

    public function test_automatic_title_uses_first_product_and_remaining_product_count(): void
    {
        $payload = OrderCreateData::fromLivewire($this->baseData([
            ['product' => 'Premium Cotton T-Shirt', 'category' => 'T-Shirts', 'quantity' => 100],
            ['product' => 'Classic Hoodie', 'category' => 'Hoodies', 'quantity' => 50],
            ['product' => 'Canvas Tote Bag', 'category' => 'Bags', 'quantity' => 25],
        ]), false)->toArray();

        $this->assertSame('FO-333119 – Premium Cotton T-Shirt + 2 more', $payload['title']);
    }

    private function baseData(array $items): array
    {
        return [
            'referenceNumber' => 'FO-333119',
            'isRepeatedOrder' => false,
            'repeatedOrderNumber' => '',
            'jobItems' => $items,
            'shipmentUrgencyIds' => [],
            'clientId' => 1,
            'workflowId' => 1,
            'workflowPhaseId' => 1,
            'ownerId' => 1,
            'coordinatorId' => null,
            'deliveryDate' => '',
            'estimatedDeliveryDate' => '',
            'description' => '',
            'shippingAddress' => 'Test address',
            'shippingPhoneCountryCode' => '',
            'shippingPhone' => '',
            'shippingPostalCode' => '1000',
            'shippingSourceAddressId' => null,
        ];
    }
}
