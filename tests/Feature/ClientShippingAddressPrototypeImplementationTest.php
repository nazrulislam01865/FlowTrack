<?php

namespace Tests\Feature;

use Tests\TestCase;

class ClientShippingAddressPrototypeImplementationTest extends TestCase
{
    public function test_client_form_uses_reusable_prototype_shipping_components(): void
    {
        $clientForm = file_get_contents(resource_path('views/components/clients/create.blade.php'));
        $address = file_get_contents(resource_path('views/components/ui/shipping-address-editor.blade.php'));

        $this->assertStringContainsString('ft-reusable-form-theme', $clientForm);
        $this->assertStringContainsString('ft-client-shipping-aligned-section', $clientForm);
        $this->assertStringContainsString('<h3>Shipping address</h3>', $clientForm);
        $this->assertStringContainsString('<x-ui.shipping-address-editor', $clientForm);
        $this->assertStringContainsString('Use saved address', $clientForm);

        $this->assertStringContainsString('Recipient name', $address);
        $this->assertStringContainsString('Country / region', $address);
        $this->assertStringContainsString('Address line 1', $address);
        $this->assertStringContainsString('ZIP / postal code', $address);
        $this->assertStringContainsString('Add address line 2', $address);
        $this->assertStringContainsString('Save this address for this client', $address);
    }

    public function test_shipping_address_no_longer_requires_a_visible_location_label(): void
    {
        $component = \Tests\Support\AdministrationPhase7Source::clients();

        $this->assertStringContainsString("\$requiredShippingFields = ['address_line1','city','zip'];", $component);
        $this->assertStringContainsString("\$requiredShippingFields[] = 'recipient';", $component);
        $this->assertStringContainsString("'label' => trim((string) (\$address['label'] ?? '')) ?: 'Shipping address '.(\$index + 1)", $component);
        $this->assertStringContainsString('public function useSavedAddressForShipping(): void', $component);
    }
}
