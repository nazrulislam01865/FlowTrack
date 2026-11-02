<?php

namespace Tests\Feature;

use App\Livewire\Concerns\ManagesArtworkHandoffRecipients;
use Tests\TestCase;

class ArtworkHandoffRecipientSuggestionUxTest extends TestCase
{
    private function form(): object
    {
        return new class {
            use ManagesArtworkHandoffRecipients;

            public bool $showOrderWorkflowActionModal = true;
            public array $orderWorkflowActionModalPreview = [
                'automation_key' => 'ART_SEND_ORDER_TEAM',
                'email_handoff_preview' => [
                    'recipient_options' => [
                        ['id' => 4, 'name' => 'StepPromo User', 'email' => 'team@example.com'],
                        ['id' => 5, 'name' => 'Designer', 'email' => 'design@example.com'],
                    ],
                ],
            ];
            public array $orderWorkflowActionPayload = [];
            public int $previewCalls = 0;
            public function loadOrderWorkflowActionEmailPreview(): void { $this->previewCalls++; }
            public function resetValidation(string $name): void {}
        };
    }

    public function test_typing_only_updates_email_payload_without_rebuilding_preview(): void
    {
        $form = $this->form();
        $form->artworkRecipientEmails = [0 => 'step'];
        $form->updatedArtworkRecipientEmails();

        $this->assertSame('step', $form->orderWorkflowActionPayload['to_emails']);
        $this->assertSame(0, $form->previewCalls);
        $form->refreshArtworkRecipientEmailPreview();
        $this->assertSame(1, $form->previewCalls);
    }

    public function test_selection_only_accepts_a_listed_internal_user_and_avoids_duplicates(): void
    {
        $form = $this->form();
        $form->artworkRecipientEmails = [0 => 'step', 1 => ''];
        $form->selectArtworkRecipient(0, 4);
        $this->assertSame('team@example.com', $form->artworkRecipientEmails[0]);
        $this->assertSame(1, $form->previewCalls);

        $form->selectArtworkRecipient(1, 4);
        $this->assertSame('', $form->artworkRecipientEmails[1]);
        $form->selectArtworkRecipient(1, 99);
        $this->assertSame('', $form->artworkRecipientEmails[1]);
        $this->assertSame(1, $form->previewCalls);

        $form->selectArtworkRecipient(1, 5);
        $this->assertSame('design@example.com', $form->artworkRecipientEmails[1]);
        $this->assertSame(2, $form->previewCalls);
    }

    public function test_closed_handoff_cannot_select_user(): void
    {
        $form = $this->form();
        $form->showOrderWorkflowActionModal = false;
        $form->selectArtworkRecipient(0, 4);
        $this->assertSame([0 => ''], $form->artworkRecipientEmails);
        $this->assertSame(0, $form->previewCalls);
    }

    public function test_blade_suggestions_and_contact_buttons_use_shared_controls(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $book = file_get_contents(resource_path('views/livewire/artwork-address-book/index.blade.php'));
        $this->assertStringContainsString("$".'artworkRecipientSuggestions', $modal);
        $this->assertStringContainsString('wire:click="selectArtworkRecipient(', $modal);
        $this->assertStringContainsString('wire:blur="refreshArtworkRecipientEmailPreview"', $modal);
        $this->assertStringContainsString('Add recipient</x-ui.button>', $modal);
        $this->assertStringNotContainsString('ft-artwork-recipient-plus', $modal);
        $this->assertStringContainsString('ft-address-book-page__primary-action', $book);
        $this->assertStringContainsString('ft-address-book-page__edit-action', $book);
    }
}
