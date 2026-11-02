<?php

namespace Tests\Feature;

use Tests\TestCase;

class ArtworkHandoffAddressBookImplementationTest extends TestCase
{
    public function test_handoff_uses_email_only_and_address_book_is_a_separate_page(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $book = file_get_contents(resource_path('views/livewire/artwork-address-book/index.blade.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $list = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $capture = file_get_contents(app_path('Services/Orders/ArtworkHandoffContactCapture.php'));
        $migration = file_get_contents(database_path('migrations/2026_10_27_140000_create_artwork_handoff_contacts_table.php'));
        $delivery = file_get_contents(app_path('Services/Orders/OrderWorkflowEmailService.php'));
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString('@if($variant === \'artwork_email\')', $modal);
        $this->assertStringContainsString('orderWorkflowActionPayload.to_emails', $modal);
        $this->assertStringNotContainsString("route('artwork-address-book.index')", $modal);
        $this->assertStringContainsString('wire:click="addArtworkRecipient"', $modal);
        $this->assertStringContainsString('removeArtworkRecipient(', $modal);
        $this->assertStringContainsString('artworkRecipientEmails.', $modal);
        $this->assertStringContainsString('type="email"', $modal);
        $this->assertStringContainsString('ManagesArtworkHandoffRecipients', $workflow);
        $this->assertStringContainsString('ManagesArtworkHandoffRecipients', $list);
        $this->assertStringContainsString('validateArtworkRecipientFields();', $workflow);
        $this->assertStringContainsString('validateArtworkRecipientFields();', $list);
        $notice = file_get_contents(resource_path('views/components/jobs/order-detail/artwork-address-book-notice.blade.php'));
        $this->assertStringContainsString('Artwork email sent successfully.', $notice);
        $this->assertStringContainsString('You can edit contact information from the Address Book.', $notice);
        $this->assertStringContainsString('x-on:click="visible = false"', $notice);
        $this->assertStringNotContainsString('openArtworkAddressBook(', $modal);
        $this->assertStringNotContainsString('artworkAddressBookOpen', $modal);
        $this->assertStringContainsString('livewire:artwork-address-book.index', file_get_contents(resource_path('views/pages/artwork-address-book.blade.php')));
        $this->assertStringContainsString("Route::get('/address-book'", $routes);

        $this->assertStringContainsString('wire:click="create"', $book);
        $this->assertStringContainsString('wire:click="edit(', $book);
        $this->assertStringContainsString('wire:submit="save"', $book);
        $this->assertStringContainsString('form.email', $book);
        $this->assertStringContainsString('form.contact_type', $book);
        $this->assertStringContainsString('Rule::unique(', file_get_contents(app_path('Livewire/ArtworkAddressBook/Index.php')));
        $this->assertStringContainsString("\$table->unique(['workspace_id', 'email']", $migration);
        $this->assertStringContainsString('insertOrIgnore(', $capture);
        $this->assertStringContainsString('app(SetupContext::class)->workspaceId()', $capture);
        $this->assertStringContainsString("\$key === 'ART_SEND_ORDER_TEAM' && \$decision === 'confirm' && ! \$handoffWithoutRecipient", $workflow);
        $this->assertStringContainsString("\$key === 'ART_SEND_ORDER_TEAM' && \$decision === 'confirm' && ! \$handoffWithoutRecipient", $list);
        $this->assertStringContainsString('private function artworkRecipientSelection(', $delivery);
        $this->assertStringContainsString('Add no more than 10 To email addresses.', $delivery);
    }
}
