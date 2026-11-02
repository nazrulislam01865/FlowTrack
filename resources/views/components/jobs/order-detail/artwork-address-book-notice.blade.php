@props(['status'])
@if(in_array($status, ['saved', 'internal', 'failed'], true))
    {{-- Internal FlowTracker confirmation only; never part of the outgoing email. --}}
    <div class="ft-address-book-handoff-notice" role="status" aria-live="polite" x-data="{ visible: true }" x-show="visible" x-transition>
        <span>
            @if($status === 'saved')
                Artwork email sent successfully. External addresses are saved in the Address Book; internal FlowTracker users are not. You can edit contact information from the Address Book.
            @elseif($status === 'internal')
                Artwork email sent successfully. Internal FlowTracker users are not added to the Address Book.
            @else
                Artwork email sent successfully, but saving the addresses failed. Please add them from the Address Book.
            @endif
        </span>
        @if($status !== 'internal')
            <x-ui.button variant="secondary" size="sm" href="{{ route('artwork-address-book.index') }}" wire:navigate>Open Address Book</x-ui.button>
        @endif
        <x-ui.button variant="tertiary" size="sm" x-on:click="visible = false" aria-label="Dismiss notification">×</x-ui.button>
    </div>
@endif
