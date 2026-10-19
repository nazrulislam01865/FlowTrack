<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateOrderShippingSetupUxTest extends TestCase
{
    public function test_shipment_address_section_keeps_the_approved_two_mode_structure(): void
    {
        $setup = file_get_contents(resource_path('views/components/jobs/create/shipping-setup.blade.php'));

        $this->assertStringContainsString('<h2>Shipment address</h2>', $setup);
        $this->assertStringContainsString('<strong>Address</strong>', $setup);
        $this->assertStringContainsString('<strong>Multiple address</strong>', $setup);
        $this->assertSame(2, substr_count($setup, 'class="ft-create-shipping-mode '));
        $this->assertStringNotContainsString('Multiple address multiple shipment', $setup);
        $this->assertStringNotContainsString('Allow multiple shipments', $setup);
    }

    public function test_multiple_address_is_the_only_create_mode_that_can_add_more_shipments(): void
    {
        $setup = file_get_contents(resource_path('views/components/jobs/create/shipping-setup.blade.php'));
        $manager = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesCreateOrderShipments.php'));

        $this->assertStringContainsString('value="multiple_address"', $setup);
        $this->assertStringContainsString("wire:click=\"setCreateShipmentMode('multiple_address')\"", $setup);
        $this->assertStringContainsString('@if($isMultipleAddressMode)', $setup);
        $this->assertStringContainsString('wire:click="addCreateShipment"', $setup);
        $this->assertStringContainsString('$this->createShipmentMode === self::CREATE_SHIPMENT_MODE_MULTIPLE_ADDRESS', $manager);
        $this->assertStringContainsString('Select Multiple address before adding another shipment.', $manager);

        // Returning to the single Address option cannot leave hidden extra rows
        // that would later be persisted accidentally.
        $this->assertStringContainsString('$this->createShipments = [array_values($this->createShipments)[0]];', $manager);
    }

    public function test_shipping_setup_uses_reusable_card_rows_instead_of_a_dense_table(): void
    {
        $setup = file_get_contents(resource_path('views/components/jobs/create/shipping-setup.blade.php'));
        $row = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));

        $this->assertStringContainsString('ft-create-shipment-workspace', $setup);
        $this->assertStringContainsString('<x-jobs.create.shipping-row', $setup);
        $this->assertStringNotContainsString('ft-create-shipment-table-head', $setup);
        $this->assertStringContainsString('data-ft-ui-component="create-order-shipment-card"', $row);
        $this->assertStringContainsString('Shipment {{ $shipmentNumber }}', $row);
        $this->assertStringContainsString('ft-create-shipment-primary-badge', $row);
        $this->assertStringContainsString('Primary', $row);
    }

    public function test_editable_shipment_contains_only_the_fields_visible_in_the_prototype(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));
        $create = file_get_contents(resource_path('views/components/jobs/create.blade.php'));

        $contact = strpos($row, 'Contact person <span class="ft-order-required-star"');
        $phone = strpos($row, 'Phone <span class="ft-order-required-star"');
        $address = strpos($row, 'Shipping address <span class="ft-order-required-star"');
        $postal = strpos($row, 'Postal code <span class="ft-order-required-star"');

        foreach ([$contact, $phone, $address, $postal] as $position) {
            $this->assertNotFalse($position);
        }

        $this->assertLessThan($phone, $contact);
        $this->assertLessThan($address, $phone);
        $this->assertLessThan($postal, $address);

        $this->assertStringNotContainsString('Country <b class="ft-order-required-star"', $row);
        $this->assertStringNotContainsString('State @if', $row);
        $this->assertStringNotContainsString('City <em>Optional</em>', $row);
        $this->assertStringNotContainsString('<span>Shipment no.</span>', $row);
        $this->assertStringNotContainsString('Package / reference <em>Optional</em>', $row);
        $this->assertStringNotContainsString('<span>Shipping method</span>', $row);

        $this->assertStringContainsString('<h2>Schedule & owner</h2>', $create);
        $this->assertStringContainsString('<b>Hand Date</b>', $create);
        $this->assertStringContainsString('<x-jobs.create.shipping-method-picker', $create);
        $this->assertStringContainsString('Applied automatically to every shipment address.', $create);
    }

    public function test_shipping_address_label_is_rendered_only_once_per_editable_row(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));

        $this->assertSame(1, substr_count($row, '>Shipping address <span class="ft-order-required-star"'));
        $this->assertStringNotContainsString('<span class="sr-only">Shipping address</span>', $row);
        $this->assertStringContainsString('aria-label="Shipping address for shipment {{ $shipmentNumber }}"', $row);
    }

    public function test_shipment_fields_use_the_centralized_create_order_form_contract(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/create-shipping-setup.css'));
        $theme = file_get_contents(resource_path('theme/flowtrack/forms/order.css'));

        $this->assertStringContainsString('class="ft-create-field ft-create-shipment-field"', $row);
        $this->assertStringContainsString('ft-create-shipment-address-field', $row);
        $this->assertStringContainsString('styling come from the centralized .ft-form-standard--order / .ft-create-field', $css);
        $this->assertStringContainsString('font-size:var(--ft-form-button-size);', $css);
        $this->assertStringContainsString('.ft-form-standard--order .ft-create-field input', $theme);
        $this->assertStringContainsString('min-height: var(--ft-form-control-height);', $theme);

        // Shipment-specific CSS must not recreate a separate input typography/height system.
        $this->assertStringNotContainsString('.ft-create-shipment-field input{', $css);
        $this->assertStringNotContainsString('min-height:42px', $css);
        $this->assertStringNotContainsString('font-size:.75rem', $css);
    }

    public function test_multiple_create_addresses_are_persisted_and_rendered_in_the_shipment_stage(): void
    {
        $dto = file_get_contents(app_path('DTOs/Orders/OrderCreateData.php'));
        $service = file_get_contents(app_path('Services/OrderShipmentService.php'));
        $plan = file_get_contents(resource_path('views/components/jobs/order-detail/shipment/plan-table.blade.php'));

        $this->assertStringContainsString("'initial_shipments' => \$initialShipments", $dto);
        $this->assertStringContainsString("'shipment_address_mode' => $createShipmentMode === 'multiple_address'", $dto);
        $this->assertStringContainsString('foreach (array_values($rows) as $offset => $payload)', $service);
        $this->assertStringContainsString("'sequence' => $offset + 1", $service);
        $this->assertStringContainsString('$this->syncPlanFromShipments($lockedJob);', $service);
        $this->assertStringContainsString('$shipments = collect($presentation[\'shipments\'] ?? []);', $plan);
        $this->assertStringContainsString('@forelse($shipments as $shipment)', $plan);
    }

    public function test_saved_address_and_delete_actions_match_the_prototype(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));

        $this->assertStringContainsString('Use saved address', $row);
        $this->assertStringContainsString('Primary shipment cannot be removed', $row);
        $this->assertStringContainsString('Remove shipment', $row);
        $this->assertStringContainsString('ft-create-shipment-remove', $row);
        $this->assertStringNotContainsString('ft-create-shipment-actions-menu', $row);
    }

    public function test_phone_validation_is_scoped_to_the_phone_input_column(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));

        $phoneNumberWrapper = strpos($row, 'ft-create-shipment-phone-number');
        $phoneError = strpos($row, '@error("createShipments.$index.phone")');
        $phoneRowEnd = strpos($row, '</div>\n                    </div>', $phoneError);

        $this->assertNotFalse($phoneNumberWrapper);
        $this->assertNotFalse($phoneError);
        $this->assertNotFalse($phoneRowEnd);
        $this->assertLessThan($phoneError, $phoneNumberWrapper);
        $this->assertLessThan($phoneRowEnd, $phoneError);
    }

    public function test_layout_is_responsive_to_available_component_width_without_horizontal_scrolling(): void
    {
        $css = file_get_contents(resource_path('css/modules/orders/create-shipping-setup.css'));

        $this->assertStringContainsString('container-name:create-shipping-setup;', $css);
        $this->assertStringContainsString('container-name:create-shipment-card;', $css);
        $this->assertStringContainsString('@container create-shipment-card (max-width: 620px)', $css);
        $this->assertStringContainsString('@container create-shipment-card (max-width: 460px)', $css);
        $this->assertStringContainsString('max-width:100%;', $css);
        $this->assertStringNotContainsString('min-width:1060px', $css);
    }
    public function test_phone_country_code_uses_shared_searchable_dropdown(): void
    {
        $row = file_get_contents(resource_path('views/components/jobs/create/shipping-row.blade.php'));
        $pageData = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $css = file_get_contents(resource_path('css/modules/orders/create-shipping-setup.css'));

        $this->assertStringContainsString('<x-ui.search-select', $row);
        $this->assertStringContainsString('property="createShipments.{{ $index }}.phone_country_code"', $row);
        $this->assertStringContainsString('search-placeholder="Search code or country…"', $row);
        $this->assertStringContainsString(':clearable="false"', $row);
        $this->assertStringContainsString(':fixed-menu="true"', $row);
        $this->assertStringNotContainsString('<select\n                                wire:model.live="createShipments.{{ $index }}.phone_country_code"', $row);

        $this->assertStringContainsString("'meta' => trim((string) \$record->description)", $pageData);
        $this->assertStringContainsString('.ft-create-shipment-phone-code-select .ft-search-select__trigger', $css);
        $this->assertStringContainsString('min-height:var(--ft-form-control-height);', $css);
        $this->assertStringContainsString('font-size:var(--ft-form-control-size);', $css);
    }

}
