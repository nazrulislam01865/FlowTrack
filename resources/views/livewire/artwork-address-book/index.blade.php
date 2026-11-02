<section class="ft-address-book-page" aria-label="Artwork Address Book">
    <x-ui.page-header title="Address Book" subtitle="Manage artwork handoff email contacts in one place.">
        <x-slot:actions>
            <x-ui.button variant="primary" size="sm" class="ft-address-book-page__primary-action" wire:click="create" :disabled="$showForm && $editingId === null"><span aria-hidden="true">+</span> Add Contact</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if($notice !== '')
        <p class="ft-address-book-page__notice" role="status">{{ $notice }}</p>
    @endif

    <div class="ft-address-book-page__layout {{ $showForm ? 'ft-address-book-page__layout--editing' : '' }}">
        <section class="ft-address-book-page__card ft-address-book-page__listing" aria-label="Saved contacts">
            <header class="ft-address-book-page__toolbar">
                <div>
                    <h2>Saved contacts</h2>
                    <p>External recipients are saved automatically after an artwork handoff email is sent.</p>
                </div>
                <div class="ft-address-book-page__search">
                    <x-ui.input id="book-search" type="search" placeholder="Search contacts..." aria-label="Search contacts" wire:model.live.debounce.350ms="search" />
                </div>
            </header>

            @if($loadError)
                <p class="ft-address-book-page__empty" role="alert">Unable to load contacts. Please try again.</p>
            @elseif($contacts->isEmpty())
                <div class="ft-address-book-page__empty">
                    <strong>{{ trim($search) !== '' ? 'No matching contacts' : 'No contacts yet' }}</strong>
                    <p>{{ trim($search) !== '' ? 'Try another name, email or contact type.' : 'Send an artwork email or use Add Contact to create your first entry.' }}</p>
                </div>
            @else
                <div class="ft-address-book-page__table-wrap">
                    <table class="ft-address-book-page__table">
                        <thead><tr><th scope="col">Contact</th><th scope="col">Contact type</th><th scope="col">Phone</th><th scope="col" class="ft-address-book-page__right">Action</th></tr></thead>
                        <tbody>
                            @foreach($contacts as $contact)
                                <tr wire:key="book-contact-{{ $contact->id }}" class="{{ $showForm && $editingId === (int) $contact->id ? 'is-selected' : '' }}">
                                    <td><strong>{{ $contact->name ?: 'Name not added' }}</strong><span class="ft-address-book-page__email">{{ $contact->email }}</span></td>
                                    <td><span class="ft-address-book-page__type">{{ \App\Models\ArtworkHandoffContact::TYPES[$contact->contact_type] ?? 'Not assigned' }}</span></td>
                                    <td>{{ $contact->phone ?: '—' }}</td>
                                    <td class="ft-address-book-page__right"><x-ui.button variant="secondary" size="sm" class="ft-address-book-page__edit-action" wire:click="edit({{ $contact->id }})" aria-label="Edit {{ $contact->name ?: $contact->email }}">Edit</x-ui.button></td>
                                </tr>
                                @if(filled($contact->address))
                                    <tr wire:key="book-contact-address-{{ $contact->id }}" class="ft-address-book-page__address-row">
                                        <td colspan="4"><span>Address</span> {{ $contact->address }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <footer class="ft-address-book-page__pagination">
                    <span>Page {{ $page }}</span>
                    <div>
                        <x-ui.button variant="secondary" size="sm" wire:click="previousPage" :disabled="$page <= 1">Previous</x-ui.button>
                        <x-ui.button variant="secondary" size="sm" wire:click="nextPage" :disabled="!$hasMore">Next</x-ui.button>
                    </div>
                </footer>
            @endif
        </section>

        @if($showForm)
            <aside class="ft-address-book-page__card ft-address-book-page__editor" aria-label="Contact editor" wire:key="book-editor-{{ $editingId ?? 'new' }}">
                <header class="ft-address-book-page__section-head">
                    <div>
                        <h2>{{ $editingId ? 'Edit contact' : 'Add contact' }}</h2>
                        <p>Email is required. All other details are optional.</p>
                    </div>
                </header>
                <form wire:submit="save">
                    <div class="ft-address-book-page__fields">
                        <x-ui.input id="book-email" label="Email address" name="form.email" type="email" maxlength="254" wire:model="form.email" placeholder="person@example.com" required />
                        <x-ui.input id="book-name" label="Contact name" name="form.name" maxlength="150" wire:model="form.name" optional placeholder="Full name" />
                        <div class="ft-address-book-page__field-pair">
                            <x-ui.input id="book-phone" label="Phone number" name="form.phone" type="tel" maxlength="40" wire:model="form.phone" optional placeholder="Phone number" />
                            <x-ui.select id="book-type" label="Contact type" name="form.contact_type" wire:model="form.contact_type" optional :options="\App\Models\ArtworkHandoffContact::TYPES" placeholder="Select contact type" />
                        </div>
                        <x-ui.textarea id="book-address" label="Address" name="form.address" maxlength="1000" wire:model="form.address" optional rows="3" placeholder="Contact or mailing address" />
                    </div>
                    @error('save')<p class="validation-error" role="alert">{{ $message }}</p>@enderror
                    <div class="ft-address-book-page__actions">
                        <x-ui.button variant="secondary" size="sm" class="ft-address-book-page__cancel-action" wire:click="cancel">Cancel</x-ui.button>
                        <x-ui.button variant="primary" size="sm" class="ft-address-book-page__primary-action" type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save Changes' : 'Save Contact' }}</x-ui.button>
                    </div>
                </form>
            </aside>
        @endif
    </div>
</section>
