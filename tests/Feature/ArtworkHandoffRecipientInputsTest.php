<?php

namespace Tests\Feature;

use App\Livewire\Concerns\ManagesArtworkHandoffRecipients;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ArtworkHandoffRecipientInputsTest extends TestCase
{
    private function recipientForm(): object
    {
        return new class {
            use ManagesArtworkHandoffRecipients;

            public bool $showOrderWorkflowActionModal = true;
            public array $orderWorkflowActionModalPreview = ['automation_key' => 'ART_SEND_ORDER_TEAM'];
            public array $orderWorkflowActionPayload = [];
            public function loadOrderWorkflowActionEmailPreview(): void {}
            public function resetValidation(string $name): void {}
            public function openRows(): void { $this->initializeArtworkRecipients(); }
            public function validateRows(): void { $this->validateArtworkRecipientFields(); }
            public function resetRows(): void { $this->resetArtworkRecipients(); }
        };
    }

    public function test_add_remove_and_payload_sync_use_one_email_per_row(): void
    {
        $form = $this->recipientForm();
        $form->openRows();
        $form->artworkRecipientEmails[0] = 'first@example.com';
        $form->addArtworkRecipient();
        $form->artworkRecipientEmails[1] = 'second@example.com';
        $form->updatedArtworkRecipientEmails();

        $this->assertSame('first@example.com, second@example.com', $form->orderWorkflowActionPayload['to_emails']);
        $form->removeArtworkRecipient(0);
        $this->assertSame('second@example.com', $form->orderWorkflowActionPayload['to_emails']);
        $this->assertSame([1 => 'second@example.com'], $form->artworkRecipientEmails);
    }

    public function test_rows_are_limited_to_ten_and_ignored_when_modal_closed(): void
    {
        $form = $this->recipientForm();
        for ($i = 0; $i < 20; $i++) {
            $form->addArtworkRecipient();
        }
        $this->assertCount(10, $form->artworkRecipientEmails);

        $form->showOrderWorkflowActionModal = false;
        $form->removeArtworkRecipient(0);
        $this->assertCount(10, $form->artworkRecipientEmails);
        $form->addArtworkRecipient();
        $this->assertCount(10, $form->artworkRecipientEmails);
    }

    public function test_comma_separated_addresses_in_one_field_are_rejected(): void
    {
        $form = $this->recipientForm();
        $form->artworkRecipientEmails[0] = 'first@example.com, second@example.com';
        $this->expectException(ValidationException::class);
        $form->validateRows();
    }

    public function test_initial_payload_and_reset_are_safe(): void
    {
        $form = $this->recipientForm();
        $form->orderWorkflowActionPayload['to_emails'] = 'one@example.com, two@example.com';
        $form->openRows();
        $this->assertSame(['one@example.com', 'two@example.com'], $form->artworkRecipientEmails);
        $form->resetRows();
        $this->assertSame([0 => ''], $form->artworkRecipientEmails);
    }
}
