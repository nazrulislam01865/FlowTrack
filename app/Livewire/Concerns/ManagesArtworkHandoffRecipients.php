<?php

namespace App\Livewire\Concerns;

use App\Models\ArtworkHandoffContact;
use App\Services\SetupContext;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/** UI-only email rows for the artwork handoff; the existing to_emails payload remains canonical. */
trait ManagesArtworkHandoffRecipients
{
    public array $artworkRecipientEmails = [0 => ''];
    public int $nextArtworkRecipientKey = 1;

    /** Small, on-demand address-book matches keyed by recipient row. */
    public array $artworkSavedRecipientSuggestions = [];

    protected function initializeArtworkRecipients(): void
    {
        $raw = trim((string) (($this->orderWorkflowActionPayload['to_emails'] ?? '') ?: ($this->orderWorkflowActionPayload['to_email'] ?? '')));
        $emails = $raw === '' ? [] : array_slice(preg_split('/[\s,;]+/', $raw) ?: [], 0, 10);
        $this->artworkRecipientEmails = $emails !== [] ? array_values($emails) : [0 => ''];
        $this->nextArtworkRecipientKey = count($this->artworkRecipientEmails);
        $this->artworkSavedRecipientSuggestions = [];
    }

    protected function resetArtworkRecipients(): void
    {
        $this->artworkRecipientEmails = [0 => ''];
        $this->nextArtworkRecipientKey = 1;
        $this->artworkSavedRecipientSuggestions = [];
    }

    protected function artworkRecipientModalIsOpen(): bool
    {
        return $this->showOrderWorkflowActionModal
            && ($this->orderWorkflowActionModalPreview['automation_key'] ?? null) === 'ART_SEND_ORDER_TEAM';
    }

    public function addArtworkRecipient(): void
    {
        if (! $this->artworkRecipientModalIsOpen() || count($this->artworkRecipientEmails) >= 10) {
            return;
        }

        $this->artworkRecipientEmails[$this->nextArtworkRecipientKey++] = '';
    }

    public function removeArtworkRecipient(int $key): void
    {
        if (! $this->artworkRecipientModalIsOpen() || count($this->artworkRecipientEmails) <= 1 || ! array_key_exists($key, $this->artworkRecipientEmails)) {
            return;
        }

        unset($this->artworkRecipientEmails[$key], $this->artworkSavedRecipientSuggestions[$key]);
        $this->syncArtworkRecipientPayload();
        $this->loadOrderWorkflowActionEmailPreview();
        $this->resetValidation('orderWorkflowActionPayload.to_emails');
    }

    public function updatedArtworkRecipientEmails(mixed $value, mixed $key = null): void
    {
        if (! $this->artworkRecipientModalIsOpen()) {
            return;
        }

        // Keep the expensive artwork email preview out of every typing request.
        $this->syncArtworkRecipientPayload();

        // Search only the active workspace's saved contacts. A bounded lookup
        // starts after two characters; no address-book query runs on page load.
        $row = (int) $key;
        if (! array_key_exists($row, $this->artworkRecipientEmails)) {
            return;
        }
        $this->artworkSavedRecipientSuggestions[$row] = [];
        $term = mb_strtolower(trim((string) $value));
        if (mb_strlen($term) < 2 || mb_strlen($term) > 100 || filter_var($term, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $escaped = addcslashes($term, '%_\\');
        try {
            $this->artworkSavedRecipientSuggestions[$row] = ArtworkHandoffContact::query()
                ->inWorkspace(app(SetupContext::class)->workspaceId())
                ->where(function ($query) use ($escaped): void {
                    $query->where('email', 'like', '%'.$escaped.'%')
                        ->orWhere('name', 'like', '%'.$escaped.'%');
                })
                ->orderBy('email')
                ->limit(6)
                ->get(['id', 'name', 'email'])
                ->map(fn (ArtworkHandoffContact $contact) => [
                    'id' => (int) $contact->id,
                    'name' => (string) ($contact->name ?? ''),
                    'email' => (string) $contact->email,
                ])->all();
        } catch (QueryException $exception) {
            report($exception);
            // Missing/unavailable contacts must not interrupt email handoff.
        }
    }

    /** Refresh the full preview only after an address is finished/selected. */
    public function refreshArtworkRecipientEmailPreview(): void
    {
        if (! $this->artworkRecipientModalIsOpen()) {
            return;
        }

        $this->syncArtworkRecipientPayload();
        $this->loadOrderWorkflowActionEmailPreview();
    }

    /** Choose from the existing active-workspace directory, without new queries. */
    public function selectArtworkRecipient(int $key, int $userId): void
    {
        if (! $this->artworkRecipientModalIsOpen() || ! array_key_exists($key, $this->artworkRecipientEmails)) {
            return;
        }

        $options = (array) ($this->orderWorkflowActionModalPreview['email_handoff_preview']['recipient_options'] ?? []);
        foreach ($options as $option) {
            if (! is_array($option) || (int) ($option['id'] ?? 0) !== $userId) {
                continue;
            }

            $email = trim((string) ($option['email'] ?? ''));
            if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return;
            }

            $this->setArtworkRecipientEmail($key, $email);
            return;
        }
    }

    /** Select a workspace-owned saved contact without trusting a client-provided email. */
    public function selectSavedArtworkRecipient(int $key, int $contactId): void
    {
        if (! $this->artworkRecipientModalIsOpen() || ! array_key_exists($key, $this->artworkRecipientEmails)) {
            return;
        }

        $contact = ArtworkHandoffContact::query()
            ->inWorkspace(app(SetupContext::class)->workspaceId())
            ->find($contactId, ['id', 'email']);
        if ($contact) {
            $this->setArtworkRecipientEmail($key, (string) $contact->email);
        }
    }

    private function setArtworkRecipientEmail(int $key, string $email): void
    {
        $email = trim($email);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        foreach ($this->artworkRecipientEmails as $otherKey => $selected) {
            if ($otherKey !== $key && strcasecmp(trim((string) $selected), $email) === 0) {
                return;
            }
        }

        $this->artworkRecipientEmails[$key] = $email;
        unset($this->artworkSavedRecipientSuggestions[$key]);
        $this->syncArtworkRecipientPayload();
        $this->resetValidation('orderWorkflowActionPayload.to_emails');
        $this->loadOrderWorkflowActionEmailPreview();
    }

    protected function syncArtworkRecipientPayload(): void
    {
        $this->orderWorkflowActionPayload['to_emails'] = collect($this->artworkRecipientEmails)
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email) => trim($email))
            ->implode(', ');
    }

    protected function validateArtworkRecipientFields(): void
    {
        if (count($this->artworkRecipientEmails) > 10 || $this->artworkRecipientEmails === []) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.to_emails' => 'Add no more than 10 To email addresses.',
            ]);
        }

        foreach ($this->artworkRecipientEmails as $email) {
            if (! is_string($email)) {
                throw ValidationException::withMessages([
                    'orderWorkflowActionPayload.to_emails' => 'Enter one valid email address per field.',
                ]);
            }
            $email = trim($email);
            if ($email !== '' && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                throw ValidationException::withMessages([
                    'orderWorkflowActionPayload.to_emails' => 'Enter one valid email address per field. Use + to add another recipient.',
                ]);
            }
        }

        $this->syncArtworkRecipientPayload();
    }
}
