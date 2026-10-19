@extends('layouts.app', ['title' => 'UI component reference'])

@section('content')
<div x-data="{ modalOpen: false }">
    <x-ui.page-header title="FlowTrack UI component reference" subtitle="Developer-only Phase 2 reference. Components shown here use the centralized token and component contracts.">
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('dashboard') }}">Back to dashboard</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card>
        <x-slot:header><x-ui.section-header title="Buttons" description="Semantic variants, sizes, disabled and loading states." /></x-slot:header>
        <div class="ft-page-header__actions">
            <x-ui.button>Primary</x-ui.button>
            <x-ui.button variant="secondary">Secondary</x-ui.button>
            <x-ui.button variant="tertiary">Tertiary</x-ui.button>
            <x-ui.button variant="danger">Destructive</x-ui.button>
            <x-ui.button size="sm">Small</x-ui.button>
            <x-ui.button disabled>Disabled</x-ui.button>
            <x-ui.button loading loading-label="Saving…">Save</x-ui.button>
            <x-ui.icon-button label="Add item">+</x-ui.icon-button>
        </div>
    </x-ui.card>

    <div class="ft-type-body">&nbsp;</div>

    <x-ui.card>
        <x-slot:header><x-ui.section-header title="Badges and runtime colors" description="Static variants use semantic tokens; Master Data colors use validated custom properties." /></x-slot:header>
        <div class="ft-page-header__actions">
            <x-ui.badge label="Neutral" variant="neutral" />
            <x-ui.badge label="Information" variant="info" dot />
            <x-ui.badge label="Success" variant="success" dot />
            <x-ui.badge label="Warning" variant="warning" dot />
            <x-ui.badge label="Danger" variant="danger" dot />
            <x-ui.badge label="Purple" variant="purple" />
            <x-ui.status-badge label="Runtime priority" :color="\App\Support\MasterColor::defaultFor('priority', 'High')" dot />
        </div>
    </x-ui.card>

    <div class="ft-type-body">&nbsp;</div>

    <x-ui.card>
        <x-slot:header><x-ui.section-header title="Form controls" description="Labels, helper text, validation, disabled and native selection states." /></x-slot:header>
        <x-ui.input name="reference_name" label="Name" placeholder="Enter a name" help="Shared helper text treatment." required />
        <div class="ft-type-body">&nbsp;</div>
        <x-ui.input name="reference_error" label="Validation example" value="Invalid value" error="Please correct this value." />
        <div class="ft-type-body">&nbsp;</div>
        <x-ui.select name="reference_status" label="Status" :options="['Open' => 'Open', 'In Progress' => 'In Progress', 'Completed' => 'Completed']" value="In Progress" />
        <div class="ft-type-body">&nbsp;</div>
        <x-ui.date-input name="reference_date" label="Due date" optional />
        <div class="ft-type-body">&nbsp;</div>
        <x-ui.textarea name="reference_notes" label="Notes" placeholder="Add notes" optional />
    </x-ui.card>

    <div class="ft-type-body">&nbsp;</div>

    <x-ui.card>
        <x-slot:header><x-ui.section-header title="Table and tabs" description="Composable structure with accessible roles and stable class contracts." /></x-slot:header>
        <x-ui.tabs label="Reference sections">
            <x-ui.tab selected>Overview</x-ui.tab>
            <x-ui.tab>Activity</x-ui.tab>
            <x-ui.tab>Documents</x-ui.tab>
        </x-ui.tabs>
        <div class="ft-type-body">&nbsp;</div>
        <x-ui.table caption="Example component table">
            <x-slot:head>
                <tr><th>Item</th><th>Status</th><th>Owner</th></tr>
            </x-slot:head>
            <tr><td>Order review</td><td><x-ui.badge label="In Progress" variant="info" /></td><td>Operations</td></tr>
            <tr><td>Artwork approval</td><td><x-ui.badge label="Completed" variant="success" /></td><td>Design</td></tr>
        </x-ui.table>
    </x-ui.card>

    <div class="ft-type-body">&nbsp;</div>

    <x-ui.card>
        <x-slot:header><x-ui.section-header title="Feedback states" description="Consistent loading, validation, empty and pagination presentation." /></x-slot:header>
        <x-ui.loading label="Loading records…" />
        <div class="ft-type-body">&nbsp;</div>
        <x-ui.validation-message message="A validation message uses the shared danger treatment." />
        <x-ui.empty-state title="No matching records" description="Adjust the filters or create a new record when the workflow allows it.">
            <x-slot:icon>○</x-slot:icon>
            <x-slot:action><x-ui.button size="sm">Create record</x-ui.button></x-slot:action>
        </x-ui.empty-state>
        <x-ui.pagination :page="2" :last-page="5" label="Page 2 of 5" />
    </x-ui.card>

    <div class="ft-type-body">&nbsp;</div>

    <x-ui.card>
        <x-slot:header><x-ui.section-header title="Tooltip and modal" description="Keyboard-focusable tooltip and dialog semantics are part of the component contract." /></x-slot:header>
        <div class="ft-page-header__actions">
            <x-ui.tooltip id="reference-tooltip" text="Shared tooltip content"><x-ui.button variant="secondary" size="sm">Focus or hover</x-ui.button></x-ui.tooltip>
            <x-ui.button x-on:click="modalOpen = true">Open modal</x-ui.button>
        </div>
    </x-ui.card>

    <x-ui.modal id="reference-modal" title="Shared modal" x-show="modalOpen" x-cloak>
        <x-slot:close><x-ui.icon-button label="Close modal" x-on:click="modalOpen = false">×</x-ui.icon-button></x-slot:close>
        <p class="ft-type-body">The modal owns dialog structure and styling; the caller owns workflow state.</p>
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="modalOpen = false">Cancel</x-ui.button>
            <x-ui.button x-on:click="modalOpen = false">Confirm</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
@endsection
