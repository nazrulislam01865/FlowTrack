<?php

namespace App\Livewire\ArtworkAddressBook;

use App\Models\ArtworkHandoffContact;
use App\Services\SetupContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/** Dedicated workspace-scoped address book for artwork handoff recipients. */
final class Index extends Component
{
    private const PER_PAGE = 20;

    public string $search = '';
    public int $page = 1;
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $notice = '';

    public array $form = [
        'email' => '', 'name' => '', 'phone' => '', 'contact_type' => '', 'address' => '',
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccess('jobs.view') || auth()->user()?->canAccess('tasks.view'), 403);
    }

    private function workspaceId(): int
    {
        abort_unless(auth()->user()?->canAccess('jobs.view') || auth()->user()?->canAccess('tasks.view'), 403);

        return app(SetupContext::class)->workspaceId();
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function nextPage(): void
    {
        $this->page = min(1000000, $this->page + 1);
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['email' => '', 'name' => '', 'phone' => '', 'contact_type' => '', 'address' => ''];
        $this->notice = '';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $contact = ArtworkHandoffContact::query()->inWorkspace($this->workspaceId())->findOrFail($id);
        $this->editingId = (int) $contact->id;
        $this->form = [
            'email' => (string) $contact->email,
            'name' => (string) ($contact->name ?? ''),
            'phone' => (string) ($contact->phone ?? ''),
            'contact_type' => (string) ($contact->contact_type ?? ''),
            'address' => (string) ($contact->address ?? ''),
        ];
        $this->notice = '';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->notice = '';
        $this->resetValidation();
    }

    public function save(): void
    {
        if (! $this->showForm) {
            return;
        }

        // Resolve edits within this workspace before calling ignore(id) in validation.
        $contact = $this->editingId
            ? ArtworkHandoffContact::query()->inWorkspace($this->workspaceId())->findOrFail($this->editingId)
            : new ArtworkHandoffContact();

        $this->form['email'] = mb_strtolower(trim((string) ($this->form['email'] ?? '')));
        foreach (['name', 'phone', 'contact_type', 'address'] as $field) {
            $this->form[$field] = trim((string) ($this->form[$field] ?? ''));
        }

        $data = $this->validate([
            'form.email' => [
                'required', 'email:rfc', 'max:254',
                Rule::unique('artwork_handoff_contacts', 'email')
                    ->where('workspace_id', $this->workspaceId())
                    ->ignore($contact->id),
            ],
            'form.name' => ['nullable', 'string', 'max:150'],
            'form.phone' => ['nullable', 'string', 'max:40'],
            'form.contact_type' => ['nullable', Rule::in(array_merge([''], array_keys(ArtworkHandoffContact::TYPES)))],
            'form.address' => ['nullable', 'string', 'max:1000'],
        ]);

        $fields = $data['form'];
        // Avoid creating or changing a saved contact to an internal user.
        // Allow editing details on any legacy contact without erasing it.
        if ((! $contact->exists || strcasecmp((string) $contact->email, $fields['email']) !== 0)
            && DB::table('users')->where('email', $fields['email'])->exists()) {
            throw ValidationException::withMessages([
                'form.email' => 'This email belongs to a FlowTracker user and does not need an Address Book entry.',
            ]);
        }
        $contact->fill([
            'workspace_id' => $this->workspaceId(),
            'created_by' => $contact->exists ? $contact->created_by : auth()->id(),
            'email' => $fields['email'],
            'name' => $fields['name'] ?: null,
            'phone' => $fields['phone'] ?: null,
            'contact_type' => $fields['contact_type'] ?: null,
            'address' => $fields['address'] ?: null,
        ]);

        try {
            $contact->save();
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [19, 1062], true)
                || (string) $exception->getCode() === '23505') {
                throw ValidationException::withMessages(['form.email' => 'This email address is already in the Address Book.']);
            }
            report($exception);
            $this->addError('save', 'Contact could not be saved. Try again.');
            return;
        }

        $this->notice = $this->editingId ? 'Contact updated.' : 'Contact added.';
        $this->showForm = false;
        $this->editingId = null;
        $this->page = 1;
        $this->resetValidation();
    }

    public function render(): View
    {
        $contacts = collect();
        $hasMore = false;
        $loadError = false;

        try {
            $search = trim(mb_substr($this->search, 0, 100));
            $escaped = addcslashes($search, '%_\\');
            $typeSearch = str_replace(' ', '_', $escaped);
            $rows = ArtworkHandoffContact::query()
                ->inWorkspace($this->workspaceId())
                ->when($search !== '', function ($query) use ($escaped, $typeSearch): void {
                    $query->where(function ($q) use ($escaped, $typeSearch): void {
                        $q->where('email', 'like', '%'.$escaped.'%')
                          ->orWhere('name', 'like', '%'.$escaped.'%')
                          ->orWhere('phone', 'like', '%'.$escaped.'%')
                          ->orWhere('contact_type', 'like', '%'.$typeSearch.'%');
                    });
                })
                ->orderByDesc('id')
                ->offset((max(1, $this->page) - 1) * self::PER_PAGE)
                ->limit(self::PER_PAGE + 1)
                ->get(['id', 'email', 'name', 'phone', 'contact_type', 'address']);
            $hasMore = $rows->count() > self::PER_PAGE;
            $contacts = $rows->take(self::PER_PAGE);
        } catch (QueryException $exception) {
            report($exception);
            $loadError = true;
        }

        return view('livewire.artwork-address-book.index', compact('contacts', 'hasMore', 'loadError'));
    }
}
