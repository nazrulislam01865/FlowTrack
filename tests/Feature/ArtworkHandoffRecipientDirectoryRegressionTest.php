<?php

namespace Tests\Feature;

use Tests\TestCase;

class ArtworkHandoffRecipientDirectoryRegressionTest extends TestCase
{
    public function test_internal_addresses_are_excluded_from_capture_without_changing_delivery(): void
    {
        $capture = file_get_contents(app_path('Services/Orders/ArtworkHandoffContactCapture.php'));
        $order = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderWorkflow.php'));
        $notice = file_get_contents(resource_path('views/components/jobs/order-detail/artwork-address-book-notice.blade.php'));

        $this->assertStringContainsString("DB::table('users')->whereIn('email', \$emails->all())", $capture);
        $this->assertStringContainsString('->reject(fn ($email) => in_array($email, $internalEmails, true))', $capture);
        $this->assertStringContainsString('insertOrIgnore(', $capture);
        $this->assertStringContainsString('return $emails->count();', $capture);
        $this->assertStringContainsString("$".'savedExternalCount > 0 ? \'saved\' : \'internal\'', $order);
        $this->assertStringContainsString("$".'savedExternalCount > 0 ? \'saved\' : \'internal\'', $workflow);
        $this->assertStringContainsString('Internal FlowTracker users are not added to the Address Book.', $notice);
        $this->assertStringContainsString("$"."status !== 'internal'", $notice);
    }

    public function test_saved_contacts_are_suggested_in_both_modal_hosts_without_per_render_queries(): void
    {
        $trait = file_get_contents(app_path('Livewire/Concerns/ManagesArtworkHandoffRecipients.php'));
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $list = file_get_contents(resource_path('views/livewire/orders/index.blade.php'));
        $detail = file_get_contents(resource_path('views/livewire/jobs/order-workflow-section.blade.php'));
        $book = file_get_contents(app_path('Livewire/ArtworkAddressBook/Index.php'));

        $this->assertStringContainsString('updatedArtworkRecipientEmails(mixed $value, mixed $key = null)', $trait);
        $this->assertStringContainsString('->inWorkspace(app(SetupContext::class)->workspaceId())', $trait);
        $this->assertStringContainsString('->limit(6)', $trait);
        $this->assertStringContainsString('selectSavedArtworkRecipient(int $key, int $contactId)', $trait);
        $this->assertStringContainsString('setArtworkRecipientEmail($key, (string) $contact->email)', $trait);
        $this->assertStringContainsString('wire:click="selectSavedArtworkRecipient(', $modal);
        $this->assertStringContainsString('Address Book ·', $modal);
        $this->assertStringContainsString('Internal user ·', $modal);
        $this->assertStringContainsString(':artwork-saved-recipient-suggestions="$artworkSavedRecipientSuggestions"', $list);
        $this->assertStringContainsString(':artwork-saved-recipient-suggestions="$artworkSavedRecipientSuggestions"', $detail);
        $this->assertStringContainsString("DB::table('users')->where('email', \$fields['email'])->exists()", $book);
        $this->assertStringContainsString('This email belongs to a FlowTracker user', $book);
    }
}
