@props([
    'detail',
    'users',
    'editing' => false,
    'tab' => 'overview',
    'orders' => null,
    'documents' => null,
    'activities' => null,
    'orderStatusOptions' => null,
    'orderOwnerOptions' => null,
    'clientOrderSearch' => '',
    'clientOrderStatus' => '',
    'clientOrderOwner' => '',
    'clientOrderRange' => '3m',
    'documentCount' => 0,
    'orderMetrics' => [],
    'orderCount' => 0,
    'detailSectionsReady' => [],
    'clientCode' => '',
    'clientCountries' => [],
    'clientCountryFlags' => [],
    'clientStatesByCountry' => [],
    'clientLanguages' => [],
    'clientCurrencies' => [],
    'paymentTermOptions' => [],
    'accountManagerId' => null,
    'preferredCurrency' => '',
    'clientCountry' => '',
    'officeState' => '',
    'billingState' => '',
    'billingCountry' => '',
    'billingRecipient' => '',
    'billingAddressLine1' => '',
    'billingSuite' => '',
    'billingCity' => '',
    'billingZip' => '',
    'billingSameAsOffice' => true,
    'salesTaxStatus' => 'taxable',
    'editShippingAddresses' => [],
    'contacts' => [],
    'clientLogoUpload' => null,
    'existingClientLogoUrl' => '',
    'removeClientLogo' => false,
])
@php
    $client = $detail['client'];
    $jobs = $detail['jobs'] ?? collect();
    $addressesReady = (bool) ($detailSectionsReady['addresses'] ?? false);
    $initials = collect(preg_split('/\s+/', trim((string) $client->name)))
        ->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: 'CL';
    $access = app(\App\Services\AccessControlService::class);
    $canEdit = $access->isAdministrator(auth()->user())
        || $access->canEditAll(auth()->user(), 'clients')
        || ($access->canEditOwn(auth()->user(), 'clients') && (int) $client->account_manager_id === (int) auth()->id());
    $canDelete = auth()->user()->canModule('clients', 'delete');
    $canCreateOrder = auth()->user()->canModule('jobs', 'create');

    $location = collect([$client->office_city, $client->office_state])->filter()->implode(', ');
    if ($location === '') $location = $client->country ?: '—';
    $clientSince = $client->created_at?->format('M Y') ?: '—';
    $currencyCode = $client->preferred_currency ?: 'USD';
    $currencyNames = ['USD'=>'US Dollar','CNY'=>'Chinese Yuan','BDT'=>'Bangladeshi Taka','EUR'=>'Euro','GBP'=>'British Pound','CAD'=>'Canadian Dollar','AUD'=>'Australian Dollar','AED'=>'UAE Dirham'];
    $currencyText = $currencyCode.(isset($currencyNames[$currencyCode]) ? ' · '.$currencyNames[$currencyCode] : '');
    $primaryInitials = collect(preg_split('/\s+/', trim((string) ($client->contact_name ?: 'Primary Contact'))))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p,0,1)))->implode('') ?: 'PC';
    $managerInitials = collect(preg_split('/\s+/', trim((string) ($client->accountManager?->name ?: 'Unassigned'))))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p,0,1)))->implode('') ?: 'AM';
    $contactCount = (int) ($client->contacts_count ?? ($client->relationLoaded('contacts') ? $client->contacts->count() : 0));
    if ($contactCount === 0 && (filled($client->contact_name) || filled($client->email) || filled($client->phone))) $contactCount = 1;

    $formatAddress = function (?string $line1, ?string $suite, ?string $city, ?string $state, ?string $zip, ?string $country): array {
        $first = collect([$line1, $suite])->filter(fn ($v) => filled($v))->implode(', ');
        $second = collect([$city, $state, $zip])->filter(fn ($v) => filled($v))->implode(', ');
        return array_values(array_filter([$first, $second, $country], fn ($v) => filled($v)));
    };

    $officeLines = $formatAddress($client->office_address_line1 ?: $client->office_address, $client->office_suite, $client->office_city, $client->office_state, $client->office_zip, $client->country);
    $billingLines = $formatAddress($client->billing_address_line1, $client->billing_suite, $client->billing_city, $client->billing_state, $client->billing_zip, $client->billing_country ?: $client->country);
    $shippingAddresses = $addressesReady && $client->relationLoaded('shippingAddresses') ? $client->shippingAddresses : collect();
    $addressCount = 1 + ($client->billing_same_as_office ? 0 : ($billingLines ? 1 : 0)) + $shippingAddresses->count();

    $statusLabel = function ($job) {
        if ($job->completed_at) return 'Completed';
        $status = trim((string) $job->status);
        if ($status === '' || in_array(mb_strtolower($status), ['new','active','open','in progress'], true)) {
            return $job->phase?->name ?: ($status ?: 'Not started');
        }
        return $status;
    };
    $statusClass = function (string $status): string {
        $s = mb_strtolower($status);
        if (str_contains($s, 'complete')) return 'is-green';
        if (str_contains($s, 'await') || str_contains($s, 'approval') || str_contains($s, 'attention')) return 'is-amber';
        if (str_contains($s, 'hold') || str_contains($s, 'block') || str_contains($s, 'cancel')) return 'is-red';
        if (str_contains($s, 'quote') || str_contains($s, 'sample')) return 'is-purple';
        if (str_contains($s, 'not started') || str_contains($s, 'new')) return 'is-gray';
        return 'is-blue';
    };
@endphp

<div class="ft-client-prototype-page" data-client-detail-tab="{{ $tab }}">
    @if(session('success'))<div class="flash success">{{ session('success') }}</div>@endif

    <div class="ft-client-proto-breadcrumb">
        <button type="button" wire:click="backToClients">Clients</button>
        <span>/</span>
        <b>{{ $client->code ?: 'CL-'.str_pad((string) $client->id, 3, '0', STR_PAD_LEFT) }}</b>
    </div>

    <header class="ft-client-proto-header">
        <div class="ft-client-proto-identity">
            <x-ui.client-logo :client="$client" :name="$client->name" :size="60" shape="circle" class="ft-client-proto-logo" />
            <div class="ft-client-proto-title-block">
                <div class="ft-client-proto-title-line">
                    <h1>{{ $client->name }}</h1>
                    <span class="ft-client-status-pill {{ $client->is_active ? 'is-active' : 'is-archived' }}">{{ $client->is_active ? 'Active' : 'Archived' }}</span>
                </div>
                <div class="ft-client-proto-subline">
                    <span>{{ $client->legal_business_name ?: $client->name }}</span><i></i>
                    <span>{{ $location }}</span><i></i>
                    <span>Client since {{ $clientSince }}</span>
                </div>
            </div>
        </div>
        <div class="ft-client-proto-actions">
            @if($canEdit)
                <button class="ft-client-proto-secondary" type="button" wire:click="editClient({{ $client->id }})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>
                    Edit client
                </button>
            @endif
            @if($canCreateOrder)
                <a class="ft-client-proto-primary" href="{{ route('jobs.index', ['create'=>1,'client'=>$client->id]) }}" wire:navigate>
                    <span>+</span> Create order
                </a>
            @endif
            <details class="ft-client-proto-more">
                <summary aria-label="More client actions">⋮</summary>
                <div>
                    @if($canEdit)<button type="button" wire:click="editClient({{ $client->id }})">Edit client</button>@endif
                    @if($canDelete)<button class="danger" type="button" wire:click="deleteClient({{ $client->id }})" wire:confirm="Archive this client? Existing order history will remain available.">Archive client</button>@endif
                </div>
            </details>
        </div>
    </header>

    <nav class="ft-client-proto-tabs" aria-label="Client detail sections">
        <button type="button" wire:click="setClientDetailTab('overview')" class="{{ $tab === 'overview' ? 'active' : '' }}">Overview</button>
        <button type="button" wire:click="setClientDetailTab('contacts')" class="{{ $tab === 'contacts' ? 'active' : '' }}">Contacts <span>{{ $contactCount }}</span></button>
        <button type="button" wire:click="setClientDetailTab('orders')" class="{{ $tab === 'orders' ? 'active' : '' }}">Orders <span>{{ $orderCount }}</span></button>
        <button type="button" wire:click="setClientDetailTab('documents')" class="{{ $tab === 'documents' ? 'active' : '' }}">Documents <span>{{ $documentCount }}</span></button>
        <button type="button" wire:click="setClientDetailTab('activity')" class="{{ $tab === 'activity' ? 'active' : '' }}">Activity</button>
    </nav>

    @if($editing)
        <x-clients.create
            mode="edit"
            :users="$users"
            :client-code="$clientCode"
            :client-name="$client->name ?? ''"
            :client-countries="$clientCountries"
            :client-country-flags="$clientCountryFlags"
            :client-states-by-country="$clientStatesByCountry"
            :client-languages="$clientLanguages"
            :client-currencies="$clientCurrencies"
            :payment-term-options="$paymentTermOptions"
            :account-manager-id="$accountManagerId"
            :preferred-currency="$preferredCurrency"
            :client-country="$clientCountry"
            :office-state="$officeState"
            :billing-state="$billingState"
            :billing-country="$billingCountry"
            :billing-recipient="$billingRecipient"
            :billing-address-line1="$billingAddressLine1"
            :billing-suite="$billingSuite"
            :billing-city="$billingCity"
            :billing-zip="$billingZip"
            :billing-same-as-office="$billingSameAsOffice"
            :sales-tax-status="$salesTaxStatus"
            :shipping-addresses="$editShippingAddresses"
            :contacts="$contacts"
            :client-logo-upload="$clientLogoUpload"
            :existing-client-logo-url="$existingClientLogoUrl"
            :remove-client-logo="$removeClientLogo"
        />
    @elseif($tab === 'overview')
        <div class="ft-client-summary-grid">
            <article class="ft-client-summary-card">
                <span class="ft-client-summary-icon is-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg></span>
                <div><small>Primary contact</small><b>{{ $client->contact_name ?: 'Not set' }}</b><span>{{ $client->email ?: 'No email recorded' }}</span></div>
            </article>
            <article class="ft-client-summary-card">
                <span class="ft-client-summary-icon is-purple">{{ $managerInitials }}</span>
                <div><small>Account manager</small><b>{{ $client->accountManager?->name ?? 'Unassigned' }}</b></div>
            </article>
            <article class="ft-client-summary-card">
                <span class="ft-client-summary-icon is-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
                <div><small>Office location</small><b>{{ $location }}</b><span>{{ $client->country ?: '—' }}</span></div>
            </article>
            <article class="ft-client-summary-card">
                <span class="ft-client-summary-icon is-purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
                <div><small>Client since</small><b>{{ $clientSince }}</b><span class="ft-client-active-dot"><i></i>{{ $client->is_active ? 'Active' : 'Archived' }}</span></div>
            </article>
        </div>

        <div class="ft-client-overview-main-grid is-single">
            <section class="ft-client-proto-card ft-client-info-card">
                <div class="ft-client-card-head"><h2>Client information</h2>@if($canEdit)<button type="button" wire:click="editClient({{ $client->id }})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg> Edit details</button>@endif</div>
                <dl class="ft-client-info-list">
                    <div><dt>Client code</dt><dd>{{ $client->code ?: 'CL-'.str_pad((string)$client->id,3,'0',STR_PAD_LEFT) }}</dd></div>
                    <div><dt>Legal business name</dt><dd>{{ $client->legal_business_name ?: '—' }}</dd></div>
                    <div><dt>Website</dt><dd>@if($client->website)<a href="{{ str_starts_with($client->website, 'http') ? $client->website : 'https://'.$client->website }}" target="_blank" rel="noopener">{{ $client->website }}</a>@else—@endif</dd></div>
                    <div><dt>Preferred language</dt><dd>{{ $client->preferred_language ?: 'English' }}</dd></div>
                    <div><dt>Preferred currency</dt><dd>{{ $currencyText }}</dd></div>
                    <div><dt>Country</dt><dd>{{ $client->country ?: '—' }}</dd></div>
                    <div><dt>Created by</dt><dd>—</dd></div>
                    <div><dt>Last updated</dt><dd>{{ $client->updated_at?->format('M j, Y · g:i A') ?: '—' }}</dd></div>
                </dl>
            </section>

        </div>

        @if($addressesReady)
        <section class="ft-client-proto-card ft-client-addresses-card">
            <div class="ft-client-card-head ft-client-address-card-head">
                <div><h2>Addresses</h2><p>Office, billing and delivery locations.</p></div>
                <div><span>{{ $addressCount }} {{ \Illuminate\Support\Str::plural('address', $addressCount) }}</span>@if($canEdit)<button type="button" wire:click="editClient({{ $client->id }})">Manage addresses</button>@endif</div>
            </div>
            <div class="ft-client-address-grid">
                <article class="ft-client-address-item">
                    <span class="ft-client-address-icon is-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 2v4M15 2v4M8 10h8M8 14h8"/></svg></span>
                    <div><small>{{ $client->billing_same_as_office ? 'Office & billing' : 'Office' }}</small><b>{{ $client->office_city ? $client->office_city.' Office' : 'Office address' }}</b>@forelse($officeLines as $line)<span>{{ $line }}</span>@empty<span>Address not set</span>@endforelse<div class="ft-client-address-tags"><em>Office</em>@if($client->billing_same_as_office)<em class="purple">Billing</em>@endif</div></div>
                    <span class="ft-client-address-more">⋮</span>
                </article>
                @if(!$client->billing_same_as_office && $billingLines)
                    <article class="ft-client-address-item">
                        <span class="ft-client-address-icon is-purple"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 2v4M15 2v4M8 10h8M8 14h8"/></svg></span>
                        <div><small>Billing</small><b>Billing address</b>@foreach($billingLines as $line)<span>{{ $line }}</span>@endforeach<div class="ft-client-address-tags"><em class="purple">Billing</em></div></div>
                        <span class="ft-client-address-more">⋮</span>
                    </article>
                @endif
                @foreach($shippingAddresses as $address)
                    @php $shippingLines = $formatAddress($address->address_line1, $address->suite, $address->city, $address->state, $address->zip, $address->country); @endphp
                    <article class="ft-client-address-item">
                        <span class="ft-client-address-icon {{ $address->is_default ? 'is-green' : 'is-purple' }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">@if($address->is_default)<path d="M3 7h11v10H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>@else<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>@endif</svg></span>
                        <div><small>{{ $address->is_default ? 'Default shipping' : 'Shipping' }}</small><b>{{ $address->label }}</b>@foreach($shippingLines as $line)<span>{{ $line }}</span>@endforeach<div class="ft-client-address-tags"><em class="{{ $address->is_default ? 'green' : 'purple' }}">{{ $address->is_default ? 'Default shipping' : 'Shipping' }}</em></div></div>
                        <span class="ft-client-address-more">⋮</span>
                    </article>
                @endforeach
            </div>
        </section>
        @else
            <x-ui.progressive-section-loader section="addresses" method="loadClientDetailSection" key-prefix="client-detail" :rows="4" message="Loading client addresses when needed…" root-margin="340px 0px" />
        @endif

        <div class="ft-client-overview-bottom-grid">
            <section class="ft-client-proto-card ft-client-commercial-card">
                <div class="ft-client-card-head"><h2>Commercial settings</h2>@if($canEdit)<button type="button" wire:click="editClient({{ $client->id }})" aria-label="Edit commercial settings">✎</button>@endif</div>
                <div class="ft-client-commercial-grid">
                    <dl><div><dt>Payment terms</dt><dd>{{ $client->payment_terms ?: '—' }}</dd></div><div><dt>Sales tax status</dt><dd>{{ $client->sales_tax_status === 'tax_exempt' ? 'Tax exempt' : 'Taxable' }}</dd></div><div><dt>EIN / Tax ID</dt><dd>{{ $client->ein_tax_id ?: '—' }}</dd></div></dl>
                    <dl><div><dt>PO required</dt><dd>{{ $client->po_required ? 'Yes' : 'No' }}</dd></div><div><dt>Credit status</dt><dd class="ft-credit-good"><i></i>{{ (float)$client->outstanding_balance > 0 ? 'Balance outstanding' : 'In good standing' }}</dd></div><div><dt>Currency</dt><dd>{{ $currencyCode }}</dd></div></dl>
                </div>
                <p class="ft-client-commercial-note"><span>i</span> Outstanding balance is calculated from transactions and shown in reporting.</p>
            </section>
            <section class="ft-client-proto-card ft-client-notes-card">
                <div class="ft-client-card-head"><h2>Internal notes</h2>@if($canEdit)<button type="button" wire:click="editClient({{ $client->id }})" aria-label="Edit notes">✎</button>@endif</div>
                <p>{{ $client->notes ?: 'No internal notes have been added for this client.' }}</p>
                <small>Last edited {{ $client->updated_at?->format('M j, Y') ?: '—' }}</small>
                <div class="ft-client-info-banner"><span>i</span> Orders, documents and activity are available in their respective tabs.</div>
            </section>
        </div>
    @elseif($tab === 'contacts')
        <section class="ft-client-proto-card ft-client-contacts-tab-card">
            <div class="ft-client-card-head ft-client-contacts-tab-head">
                <div>
                    <h2>Client contacts</h2>
                    <p>People available for inquiries, orders and documents for {{ $client->name }}.</p>
                </div>
                <div class="ft-client-contacts-tab-actions">
                    <span>{{ $contactCount }} {{ \Illuminate\Support\Str::plural('contact', $contactCount) }}</span>
                    @if($canEdit)<button class="outlined" type="button" wire:click="editClient({{ $client->id }})">＋ Manage contacts</button>@endif
                </div>
            </div>

            <div class="ft-client-contact-directory">
                <div class="ft-client-contact-directory-head" aria-hidden="true">
                    <span>Contact</span><span>Job title</span><span>Email</span><span>Phone</span><span>Action</span>
                </div>
                @forelse($client->contacts as $contactIndex => $contact)
                    @php
                        $contactInitials = collect(preg_split('/\s+/', trim((string) $contact->name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') ?: 'C';
                    @endphp
                    <article class="ft-client-contact-directory-row {{ $contactIndex === 0 ? 'is-primary' : '' }}">
                        <div class="ft-client-contact-person" data-label="Contact">
                            <span class="ft-client-contact-avatar {{ $contactIndex % 2 === 0 ? 'is-blue' : 'is-purple' }}">{{ $contactInitials }}</span>
                            <div><b>{{ $contact->name }}</b>@if($contactIndex === 0)<em>Primary</em>@endif</div>
                        </div>
                        <div class="ft-client-contact-field" data-label="Job title"><span>{{ $contact->job_title ?: '—' }}</span></div>
                        <div class="ft-client-contact-field" data-label="Email">@if($contact->email)<a href="mailto:{{ $contact->email }}">{{ $contact->email }}</a>@else<span>—</span>@endif</div>
                        <div class="ft-client-contact-field" data-label="Phone">@if($contact->phone)<a href="tel:{{ preg_replace('/[^+0-9]/', '', $contact->phone) }}">{{ $contact->phone }}</a>@else<span>—</span>@endif</div>
                        <div class="ft-client-contact-row-action" data-label="Action">
                            @if($canEdit)<button type="button" wire:click="editClient({{ $client->id }})" aria-label="Edit {{ $contact->name }}">Edit</button>@else<span>—</span>@endif
                        </div>
                    </article>
                @empty
                    @if(filled($client->contact_name) || filled($client->email) || filled($client->phone))
                        <article class="ft-client-contact-directory-row is-primary">
                            <div class="ft-client-contact-person" data-label="Contact"><span class="ft-client-contact-avatar is-blue">{{ $primaryInitials }}</span><div><b>{{ $client->contact_name ?: 'Primary contact' }}</b><em>Primary</em></div></div>
                            <div class="ft-client-contact-field" data-label="Job title"><span>{{ $client->contact_job_title ?: '—' }}</span></div>
                            <div class="ft-client-contact-field" data-label="Email">@if($client->email)<a href="mailto:{{ $client->email }}">{{ $client->email }}</a>@else<span>—</span>@endif</div>
                            <div class="ft-client-contact-field" data-label="Phone">@if($client->phone)<a href="tel:{{ preg_replace('/[^+0-9]/', '', $client->phone) }}">{{ $client->phone }}</a>@else<span>—</span>@endif</div>
                            <div class="ft-client-contact-row-action" data-label="Action">@if($canEdit)<button type="button" wire:click="editClient({{ $client->id }})">Edit</button>@else<span>—</span>@endif</div>
                        </article>
                    @else
                        <div class="ft-client-contacts-empty">
                            <span class="ft-client-contacts-empty-icon">+</span>
                            <div><b>No contacts added yet</b><p>Add a contact so the team can select the right person on inquiries and orders.</p></div>
                            @if($canEdit)<button type="button" wire:click="editClient({{ $client->id }})">Add contact</button>@endif
                        </div>
                    @endif
                @endforelse
            </div>
        </section>
    @elseif($tab === 'orders')
        <div class="ft-client-order-metrics">
            <article><span class="is-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/></svg></span><div><small>Open orders</small><b>{{ number_format($orderMetrics['open'] ?? 0) }}</b><em>Across active phases</em></div></article>
            <article><span class="is-amber"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 2h12M6 22h12M7 2c0 5 2 6 5 10-3 4-5 5-5 10M17 2c0 5-2 6-5 10 3 4 5 5 5 10"/></svg></span><div><small>Awaiting action</small><b>{{ number_format($orderMetrics['attention'] ?? 0) }}</b><em>Needs your response</em></div></article>
            <article><span class="is-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></span><div><small>Completed orders</small><b>{{ number_format($orderMetrics['completed'] ?? 0) }}</b><em>All time</em></div></article>
            <article><span class="is-purple">$</span><div><small>Total order value</small><b>${{ number_format($orderMetrics['value'] ?? 0, 0) }}</b><em>All time · {{ $currencyCode }}</em></div></article>
        </div>

        <section class="ft-client-proto-card ft-client-orders-card">
            <div class="ft-client-orders-head">
                <div><h2>Client orders</h2><p>All orders for {{ $client->name }}.</p></div>
                <div><a href="{{ route('jobs.index', ['client'=>$client->id]) }}" wire:navigate><span>↗</span> Open in Orders</a><small>Opens the Orders workspace with<br>{{ $client->name }} filter applied.</small></div>
            </div>
            <x-ui.filter-bar class="ft-client-order-filters" label="Client order filters">
                <x-ui.search-input
                    class="ft-client-order-search ft-client-order-search-shared"
                    property="clientOrderSearch"
                    :value="$clientOrderSearch"
                    label="Search client orders"
                    placeholder="Search order number or description..."
                    :debounce="300"
                    :hide-label="true"
                />
                <x-ui.search-select
                    class="ft-client-order-selector"
                    label="Status"
                    property="clientOrderStatus"
                    :value="$clientOrderStatus"
                    placeholder="All statuses"
                    :options="collect($orderStatusOptions ?? [])->map(fn ($status) => ['id' => $status, 'label' => $status])"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="220"
                />
                <x-ui.search-select
                    class="ft-client-order-selector"
                    label="Owner"
                    property="clientOrderOwner"
                    type="users"
                    context="client-orders"
                    :value="$clientOrderOwner"
                    placeholder="All owners"
                    :initial-options="$orderOwnerOptions ?? collect()"
                    :params="['client_id' => $client->id]"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="260"
                />
                <select class="ft-client-order-range" wire:model.live="clientOrderRange" aria-label="Client order date range"><option value="3m">Last 3 months</option><option value="6m">Last 6 months</option><option value="12m">Last 12 months</option><option value="all">All time</option></select>
                <x-ui.filter-reset action="clearClientOrderFilters" label="Clear filters" />
            </x-ui.filter-bar>
            <div class="ft-client-order-filter-meta"><span>Client: {{ $client->name }} <b>×</b></span><em>{{ number_format($orders?->total() ?? 0) }} matching orders</em></div>
            <div class="ft-client-orders-table-wrap">
                <table class="ft-client-orders-table">
                    <thead><tr><th><span class="ft-fake-checkbox"></span></th><th>ORDER</th><th>CREATED</th><th>DESCRIPTION</th><th>STATUS</th><th>OWNER</th><th>DUE DATE</th><th>VALUE</th><th>UPDATED</th><th>ACTION</th></tr></thead>
                    <tbody>
                    @forelse($orders ?? [] as $job)
                        @php
                            $label = $statusLabel($job);
                            $jobStatus = mb_strtolower(trim((string) $job->status));
                            $showPhaseStatus = !$job->completed_at
                                && $job->phase
                                && ($jobStatus === '' || in_array($jobStatus, ['new', 'active', 'open', 'in progress'], true));
                            $ownerName = $job->owner?->name ?: 'Unassigned';
                            $overdue = $job->delivery_date && !$job->completed_at && $job->delivery_date->isPast();
                        @endphp
                        <tr>
                            <td><span class="ft-fake-checkbox"></span></td>
                            <td><a href="{{ route('jobs.index', ['open'=>$job->id]) }}" wire:navigate>{{ $job->displayOrderNumber() }}</a></td>
                            <td>{{ $job->created_at?->format('M j, Y') }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($job->title ?: $job->product ?: '—', 42) }}</td>
                            <td>
                                @if($showPhaseStatus)
                                    <x-ui.phase-label :phase="$job->phase" short class="ft-client-order-status" />
                                @else
                                    <span class="ft-client-order-status {{ $statusClass($label) }}">{{ $label }}</span>
                                @endif
                            </td>
                            <td><div class="ft-client-order-owner"><x-ui.avatar :user="$job->owner" :name="$ownerName" :size="23" /><span>{{ $ownerName }}</span></div></td>
                            <td class="{{ $overdue ? 'is-overdue' : '' }}">{{ $job->delivery_date?->format('M j, Y') ?? '—' }}@if($overdue)<span>△</span>@endif</td>
                            <td>{{ $job->commercial_value > 0 ? '$'.number_format((float)$job->commercial_value, 0) : '—' }}</td>
                            <td>{{ $job->updated_at?->diffForHumans(['short'=>true]) ?? '—' }}</td>
                            <td><a class="ft-client-order-view" href="{{ route('jobs.index', ['open'=>$job->id]) }}" wire:navigate>View</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="ft-client-table-empty">No matching orders found for this client.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($orders)
                <div class="ft-client-orders-pagination">
                    <div>Showing {{ $orders->firstItem() ?? 0 }}–{{ $orders->lastItem() ?? 0 }} of {{ $orders->total() }} orders <select wire:model.live="clientOrderPerPage"><option value="8">8 per page</option><option value="16">16 per page</option><option value="24">24 per page</option></select></div>
                    <div class="ft-client-page-buttons">
                        <button type="button" wire:click="previousPage('clientOrdersPage')" @disabled($orders->onFirstPage())>Previous</button>
                        @php
                            $pageStart = max(1, min($orders->currentPage() - 1, max(1, $orders->lastPage() - 2)));
                            $pageEnd = min($orders->lastPage(), $pageStart + 2);
                        @endphp
                        @for($page = $pageStart; $page <= $pageEnd; $page++)<button type="button" wire:click="gotoPage({{ $page }}, 'clientOrdersPage')" class="{{ $orders->currentPage() === $page ? 'active' : '' }}">{{ $page }}</button>@endfor
                        <button type="button" wire:click="nextPage('clientOrdersPage')" @disabled(!$orders->hasMorePages())>Next</button>
                    </div>
                </div>
            @endif
        </section>
        <div class="ft-client-info-banner ft-client-order-info"><span>i</span> Order totals and statuses update automatically as tasks progress.</div>
    @elseif($tab === 'documents')
        <section class="ft-client-proto-card ft-client-generic-tab-card">
            <div class="ft-client-card-head"><div><h2>Client documents</h2><p>Documents linked to {{ $client->name }}.</p></div>@if(auth()->user()->canModule('document_archive', 'view'))<a href="{{ route('documents.index', ['client'=>$client->id]) }}" wire:navigate>Open Document Archive</a>@endif</div>
            <div class="ft-client-generic-list">
                @forelse($documents ?? [] as $document)
                    <div><span class="ft-client-doc-icon">▤</span><div><b>{{ $document->name }}</b><small>{{ $document->category ?: 'Document' }} · {{ $document->document_number }}</small></div><span>{{ $document->updated_at?->format('M j, Y') }}</span></div>
                @empty<div class="ft-client-generic-empty">No documents are linked to this client yet.</div>@endforelse
            </div>
        </section>
    @elseif($tab === 'activity')
        <section class="ft-client-proto-card ft-client-generic-tab-card">
            <div class="ft-client-card-head"><div><h2>Client activity</h2><p>Recent activity from this client’s orders.</p></div></div>
            <div class="ft-client-activity-list">
                @forelse($activities ?? [] as $activity)
                    <div><x-ui.avatar :user="$activity->user" :name="$activity->user?->name ?: 'System'" :size="30" /><div><b>{{ $activity->user?->name ?: 'System' }}</b><p>{{ $activity->description }}</p><small>{{ $activity->created_at?->diffForHumans() }}</small></div></div>
                @empty<div class="ft-client-generic-empty">No activity is available for this client yet.</div>@endforelse
            </div>
        </section>
    @endif
</div>
