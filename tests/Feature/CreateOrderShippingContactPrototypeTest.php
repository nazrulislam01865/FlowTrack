<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateOrderShippingContactPrototypeTest extends TestCase
{
    public function test_shipping_section_keeps_the_reusable_contact_component_and_aligned_field_structure(): void
    {
        $createView = file_get_contents(resource_path('views/components/jobs/create.blade.php'));
        $contactView = file_get_contents(resource_path('views/components/jobs/create/shipping-contact.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/create.css'));

        $this->assertStringContainsString('<x-jobs.create.shipping-contact', $createView);
        $this->assertStringContainsString(':saved-delivery-contacts="$savedDeliveryContacts"', $createView);
        $this->assertStringContainsString('Delivery contact phone', $contactView);
        $this->assertStringContainsString('End customer', $contactView);
        $this->assertStringContainsString('Middle client', $contactView);
        $this->assertStringContainsString('Other contact', $contactView);
        $this->assertStringContainsString("x-on:click=\"switchContactType('end_customer')\"", $contactView);
        $this->assertStringContainsString("x-on:click=\"switchContactType('middle_client')\"", $contactView);
        $this->assertStringContainsString("x-on:click=\"switchContactType('other_contact')\"", $contactView);
        $this->assertStringContainsString("\$wire.call('selectShippingContactType', type)", $contactView);
        $this->assertStringContainsString('<x-jobs.create.contact-person-combobox', $contactView);
        $this->assertStringContainsString(':shipping-contact-selection="$shippingContactSelection"', $createView);
        $this->assertStringContainsString('New contact will be saved', $contactView);
        $this->assertStringContainsString('<label class="ft-create-field ft-order-postal-row"', $contactView);
        $this->assertStringContainsString('<b>Postal Code *</b>', $contactView);
        $this->assertStringContainsString('<div class="ft-create-field ft-order-country-code-field">', $contactView);
        $this->assertStringContainsString('<b>Country code *</b>', $contactView);
        $this->assertStringContainsString(':hide-label="true"', $contactView);

        $this->assertStringContainsString('grid-template-columns:minmax(0,1.12fr) minmax(150px,.55fr) minmax(0,1.45fr)', $css);
        $this->assertStringContainsString('.ft-form-standard--order .ft-order-postal-row{', $css);
        $this->assertStringContainsString('display:block;', $css);
        $this->assertStringContainsString('var(--ft-form-label-size)', $css);
        $this->assertStringContainsString('var(--ft-form-control-size)', $css);
        $this->assertStringContainsString('var(--ft-form-control-height)', $css);
    }

    public function test_contact_sources_have_distinct_data_semantics_and_reusable_saved_contacts(): void
    {
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $index = file_get_contents(app_path('Livewire/Jobs/Index.php'));
        $pageData = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $action = file_get_contents(app_path('Actions/Clients/SaveClientDeliveryContact.php'));
        $dto = file_get_contents(app_path('DTOs/Orders/OrderCreateData.php'));

        $this->assertStringContainsString('#[Renderless]', $creation);
        $this->assertStringContainsString('public function selectShippingContactType(string $type): array', $creation);
        $this->assertStringContainsString('public function selectShippingContactOption(string $type, mixed $contactId): array', $creation);
        $this->assertStringContainsString('public function useNewShippingContactPerson(string $type, mixed $name): array', $creation);
        $this->assertStringContainsString('shippingContactUiPayload', $creation);
        $this->assertStringContainsString('setCreateShippingPhoneCountryCode', $creation);
        $this->assertStringContainsString('public function updatedShippingContactName(mixed $value): void', $creation);
        $this->assertStringContainsString('End customer and Other contact are intentionally user-entered.', $creation);
        $this->assertStringContainsString("\$this->shippingContactType = 'middle_client';", $creation);
        $this->assertStringContainsString('client->contacts', $creation);
        $this->assertStringContainsString('app(SaveClientDeliveryContact::class)->execute(', $creation);
        $this->assertStringContainsString('persistShippingContactSelection();', $creation);
        $this->assertStringContainsString("Rule::in(['end_customer', 'middle_client', 'other_contact'])", $creation);
        $this->assertStringNotContainsString("Rule::requiredIf(\$this->shippingContactType === 'middle_client')", $creation);
        $this->assertStringContainsString('app(SaveClientOrderContact::class)->execute(', $creation);
        $this->assertStringContainsString("public string \$shippingContactSelection = '';", $index);
        $this->assertStringContainsString("public string \$shippingContactType = 'end_customer';", $index);
        $this->assertStringContainsString("'savedDeliveryContacts' => \$savedDeliveryContacts", $pageData);
        $this->assertStringContainsString("->whereIn('contact_type', ['end_customer', 'other_contact'])", $pageData);
        $this->assertStringContainsString('ClientDeliveryContact::query()->create', $action);
        $this->assertStringContainsString("'shipping_contact_type'", $dto);
        $this->assertStringContainsString("'shipping_contact_name'", $dto);
    }

    public function test_saved_delivery_contacts_have_a_dedicated_database_model(): void
    {
        $model = file_get_contents(app_path('Models/ClientDeliveryContact.php'));
        $client = file_get_contents(app_path('Models/Client.php'));
        $migration = file_get_contents(database_path('migrations/2026_08_31_051500_create_client_delivery_contacts_and_order_contact_fields.php'));

        $this->assertStringContainsString("Schema::create('client_delivery_contacts'", $migration);
        $this->assertStringContainsString("\$table->string('contact_type', 30)", $migration);
        $this->assertStringContainsString("\$table->string('shipping_contact_type', 30)", $migration);
        $this->assertStringContainsString("\$table->string('shipping_contact_name')", $migration);
        $this->assertStringContainsString("'contact_type'", $model);
        $this->assertStringContainsString('public function deliveryContacts(): HasMany', $client);
    }
}
