<?php

namespace App\Livewire\Clients\Concerns;

use App\Models\Client;
use App\Models\ClientShippingAddress;
use App\Models\ClientContact;
use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\User;
use App\Services\ClientService;
use App\Services\DocumentService;
use App\Services\MasterDataService;
use App\Services\SetupContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ManagesClientContacts
{
    private function assertContactEmailsUnique(array $contacts, ?int $ignoreClientId = null): void
    {
        $emailByIndex = collect($contacts)
            ->mapWithKeys(function ($contact, int $index): array {
                $email = mb_strtolower(trim((string) ($contact['email'] ?? '')));
                return $email === '' ? [] : [$index => $email];
            });

        if ($emailByIndex->isEmpty()) return;

        // The validation rule above catches duplicates inside the current form.
        // This single indexed lookup catches an email already used by another
        // saved client contact without creating an N+1 query per contact row.
        $query = ClientContact::query()
            ->whereIn('email', $emailByIndex->unique()->values()->all());

        if ($ignoreClientId) {
            $query->where('client_id', '!=', $ignoreClientId);
        }

        $existing = $query->pluck('email')
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter()
            ->flip();

        if ($existing->isEmpty()) return;

        $messages = [];
        foreach ($emailByIndex as $index => $email) {
            if ($existing->has($email)) {
                $messages["contacts.{$index}.email"] = 'This email is already used by another client contact.';
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    public function addContact(): void
    {
        abort_if(count($this->contacts) >= 20, 422, 'A client can have up to 20 contacts.');
        $this->contacts[] = $this->blankContact();
        $this->resetValidation();
    }

    public function removeContact(int $index): void
    {
        abort_unless(isset($this->contacts[$index]), 404);
        array_splice($this->contacts, $index, 1);
        if ($this->contacts === []) $this->contacts = [$this->blankContact()];
        $this->contacts = array_values($this->contacts);
        $this->resetValidation();
    }

    private function blankContact(): array
    {
        return ['name' => '', 'job_title' => '', 'email' => '', 'phone' => ''];
    }

    private function normalizedContacts(array $contacts): array
    {
        return collect($contacts)
            ->map(fn ($contact) => [
                'name' => trim((string) ($contact['name'] ?? '')),
                'job_title' => trim((string) ($contact['job_title'] ?? '')),
                'email' => mb_strtolower(trim((string) ($contact['email'] ?? ''))),
                'phone' => trim((string) ($contact['phone'] ?? '')),
            ])
            ->filter(fn ($contact) => implode('', $contact) !== '')
            ->values()
            ->all();
    }

    private function persistContacts(Client $client, array $contacts): void
    {
        app(\App\Actions\Clients\ReplaceClientContactsAction::class)->execute(auth()->user(), $client, $this->normalizedContacts($contacts));
    }
}
