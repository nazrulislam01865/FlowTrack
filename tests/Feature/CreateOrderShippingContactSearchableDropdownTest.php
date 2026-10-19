<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateOrderShippingContactSearchableDropdownTest extends TestCase
{
    public function test_all_three_contact_sources_use_the_reusable_searchable_combobox(): void
    {
        $shipping = file_get_contents(resource_path('views/components/jobs/create/shipping-contact.blade.php'));
        $combobox = file_get_contents(resource_path('views/components/jobs/create/contact-person-combobox.blade.php'));

        $this->assertStringContainsString('<x-jobs.create.contact-person-combobox', $shipping);
        $this->assertStringContainsString("\$shippingContactType === 'middle_client'", $shipping);
        $this->assertStringContainsString('wire:model="shippingContactName"', $combobox);
        $this->assertStringContainsString("x-on:focus=\"openMenu()\"", $combobox);
        $this->assertStringContainsString('filteredItems', $combobox);
        $this->assertStringContainsString('selectShippingContactOption', $combobox);
        $this->assertStringContainsString('useNewShippingContactPerson', $combobox);
        $this->assertStringContainsString('New contact', $combobox);
    }

    public function test_manual_and_middle_contacts_persist_to_their_existing_database_models(): void
    {
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $manualAction = file_get_contents(app_path('Actions/Clients/SaveClientDeliveryContact.php'));
        $middleAction = file_get_contents(app_path('Actions/Clients/SaveClientOrderContact.php'));
        $pageData = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));

        $this->assertStringContainsString('ClientDeliveryContact::query()', $manualAction);
        $this->assertStringContainsString('ClientContact::query()', $middleAction);
        $this->assertStringContainsString("str_starts_with((string) \$this->shippingContactSelection, 'custom:')", $creation);
        $this->assertStringContainsString('app(SaveClientOrderContact::class)->execute(', $creation);
        $this->assertStringContainsString('app(SaveClientDeliveryContact::class)->execute(', $creation);
        $this->assertStringNotContainsString('->limit(40)', $pageData);
    }
    public function test_switching_contact_sources_preserves_each_tabs_in_progress_draft(): void
    {
        $creation = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderCreation.php'));
        $index = file_get_contents(app_path('Livewire/Jobs/Index.php'));

        $this->assertStringContainsString('public array $shippingContactDrafts = [];', $index);
        $this->assertStringContainsString('rememberShippingContactDraft($this->shippingContactType);', $creation);
        $this->assertStringContainsString('restoreShippingContactDraft($type)', $creation);
        $this->assertStringContainsString('private function clearShippingContactDraftFields(): void', $creation);
        $this->assertStringContainsString("'shippingContactDrafts',", $creation);
    }

}
