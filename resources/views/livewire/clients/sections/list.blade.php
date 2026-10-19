@php
    $selected = $detail['client'] ?? null;
    $activeJobs = $detail['active'] ?? collect();
    $attentionTasks = $detail['tasks'] ?? collect();
    $clientListFieldFilterActive = collect([$search, $country, $manager, $outstanding, $archivedDate, $createdBy])
        ->contains(fn ($value) => trim((string) $value) !== '');
    $clientAnyFilterActive = $clientListFieldFilterActive || (!$showArchived && $quick !== 'all');
@endphp
<div class="ft-clients-reference">
    <div class="ft-clients-page-head">
        <div>
            <h1>{{ $showArchived ? 'Archived Clients' : 'Clients' }}</h1>
            <p>{{ $showArchived ? 'Review inactive clients and restore them when needed.' : 'Monitor client Orders, task delivery, account activity and outstanding balances.' }}</p>
        </div>
        @if(auth()->user()->canModule('clients','create'))
            <button class="ft-clients-new ft-dashboard-action-match" type="button" wire:click="openCreate"><span class="ft-dashboard-action-match-icon">+</span>New Client</button>
        @endif
    </div>

    @if(session('success'))<div class="flash success">{{ session('success') }}</div>@endif

    <div class="ft-client-list-modes" role="tablist" aria-label="Client status">
        <button type="button" wire:click="showActiveClients" class="{{ !$showArchived ? 'active' : '' }}">Active Clients <span>{{ $summary['clients'] }}</span></button>
        <button type="button" wire:click="showArchivedClients" class="{{ $showArchived ? 'active' : '' }}">Archived Clients <span>{{ $summary['archived'] }}</span></button>
    </div>

    <div class="ft-clients-layout ft-clients-layout-full">
        <section class="ft-clients-main">
            @if(!$showArchived)
            <div class="ft-clients-metrics ft-summary-card-grid ft-summary-card-grid-4 ft-summary-card-grid--4" aria-label="Client summary filters">
                <x-ui.summary-card label="Total clients" :value="$summary['clients'] ?? 0" icon="clients" tone="blue" caption="Active client records" :active="$quick === 'all' && ! $clientListFieldFilterActive" wire:click="setQuick('all')" aria-pressed="{{ $quick === 'all' && ! $clientListFieldFilterActive ? 'true' : 'false' }}" />
                <x-ui.summary-card label="Active Jobs" :value="$summary['active_jobs'] ?? 0" icon="orders" tone="green" caption="Open client work" :active="$quick === 'active_jobs'" wire:click="setQuick('active_jobs')" aria-pressed="{{ $quick === 'active_jobs' ? 'true' : 'false' }}" />
                <x-ui.summary-card label="Needs attention" :value="$summary['attention'] ?? 0" icon="attention" tone="red" caption="Client work requiring action" :active="$quick === 'attention'" wire:click="setQuick('attention')" aria-pressed="{{ $quick === 'attention' ? 'true' : 'false' }}" />
                <x-ui.summary-card label="Outstanding" :value="$summary['outstanding'] ?? 0" :display-value="'$'.number_format((float) ($summary['outstanding'] ?? 0), 0)" icon="money" tone="purple" caption="Total outstanding balance" :active="$quick === 'outstanding'" wire:click="setQuick('outstanding')" aria-pressed="{{ $quick === 'outstanding' ? 'true' : 'false' }}" />
            </div>
            @endif

            @if($showArchived)
                <div class="ft-list-filter-shell is-archived ft-archived-prototype-toolbar">
                    <div class="ft-list-filter-grid ft-client-filter-grid ft-archived-prototype-filter-grid">
                        <x-ui.search-input property="search" :value="$search" placeholder="Search archived clients" />
                        <x-ui.search-select
                            label="Archived date"
                            property="archivedDate"
                            :value="$archivedDate"
                            placeholder="All dates"
                            :options="collect([
                                ['id'=>'7d','label'=>'Last 7 days'],
                                ['id'=>'30d','label'=>'Last 30 days'],
                                ['id'=>'90d','label'=>'Last 90 days'],
                                ['id'=>'year','label'=>'This year'],
                            ])"
                        />
                        <x-ui.search-select label="Created by" property="createdBy" type="users" context="clients" :value="$createdBy" placeholder="Anyone" :initial-options="$createdByFilterOptions" />
                        <button type="button" class="ft-client-clear-filter" wire:click="clearFilters" @disabled(! $clientAnyFilterActive)>× Clear filter</button>
                    </div>
                    @php
                        $chips = collect();
                        if($search) $chips->push(['key'=>'search','label'=>'Search: '.$search]);
                        if($archivedDate) $chips->push(['key'=>'archivedDate','label'=>'Archived: '.(['7d'=>'Last 7 days','30d'=>'Last 30 days','90d'=>'Last 90 days','year'=>'This year'][$archivedDate] ?? $archivedDate)]);
                        if($createdBy) $chips->push(['key'=>'createdBy','label'=>'Created by: '.(collect($createdByFilterOptions)->firstWhere('id',(int)$createdBy)['label'] ?? 'Selected')]);
                    @endphp
                    @if($chips->isNotEmpty())
                        <div class="ft-list-active-row">
                            <div class="ft-list-filter-chips">@foreach($chips as $chip)<span class="ft-list-filter-chip">{{ $chip['label'] }}<button type="button" wire:click="clearFilter('{{ $chip['key'] }}')">×</button></span>@endforeach</div>
                        </div>
                    @endif
                </div>
            @else
                <div class="ft-list-filter-shell">
                    <div class="ft-list-filter-grid ft-client-filter-grid">
                        <x-ui.search-input property="search" :value="$search" placeholder="Client, Job ID, country or manager…" />
                        <x-ui.search-select label="Account manager" property="manager" type="users" context="clients" :value="$manager" placeholder="Anyone" :initial-options="$managerFilterOptions" />
                        <x-ui.search-select label="Country" property="country" type="countries" context="clients" :value="$country" placeholder="All countries" :initial-options="$countryFilterOptions" />
                        <x-ui.search-select label="Outstanding" property="outstanding" :value="$outstanding" placeholder="Any balance" :options="collect([['id'=>'positive','label'=>'Has balance'],['id'=>'high','label'=>'$10,000+'],['id'=>'zero','label'=>'No balance']])" />
                        <button type="button" class="ft-client-clear-filter" wire:click="clearFilters" @disabled(! $clientAnyFilterActive)>× Clear filter</button>
                    </div>
                    @php
                        $chips = collect();
                        if($search) $chips->push(['key'=>'search','label'=>'Search: '.$search]);
                        if($manager) $chips->push(['key'=>'manager','label'=>'Manager: '.(collect($managerFilterOptions)->firstWhere('id',(int)$manager)['label'] ?? 'Selected')]);
                        if($country) $chips->push(['key'=>'country','label'=>'Country: '.$country]);
                        if($outstanding) $chips->push(['key'=>'outstanding','label'=>'Outstanding: '.(['positive'=>'Has balance','high'=>'$10,000+','zero'=>'No balance'][$outstanding] ?? $outstanding)]);
                    @endphp
                    @if($chips->isNotEmpty())
                        <div class="ft-list-active-row">
                            <div class="ft-list-filter-chips">@foreach($chips as $chip)<span class="ft-list-filter-chip">{{ $chip['label'] }}<button type="button" wire:click="clearFilter('{{ $chip['key'] }}')">×</button></span>@endforeach</div>
                        </div>
                    @endif
                </div>
            @endif


            <div class="ft-client-list-card">
                <div class="ft-client-table-scroll ft-results-refreshable" wire:loading.class="is-refreshing" wire:target="search,manager,country,outstanding,quick,archivedDate,createdBy">
                    @if($showArchived)
                    <table class="ft-client-table ft-archived-client-table">
                        <thead><tr><th>Client</th><th>Contact</th><th>Status</th><th>Archived</th><th>Actions</th></tr></thead>
                        <tbody>
                        @forelse($clients as $clientRow)
                            <tr wire:key="archived-client-row-{{ $clientRow->id }}">
                                <td data-label="Client">
                                    <div class="ft-client-identity"><x-ui.client-logo :client="$clientRow" :name="$clientRow->name" :size="34" :archived="true" /><span><b>{{ $clientRow->name }}</b><small>{{ $clientRow->code }}</small></span></div>
                                </td>
                                <td data-label="Contact"><span class="ft-archived-contact">{{ $clientRow->email ?: ($clientRow->contact_name ?: '—') }}</span></td>
                                <td data-label="Status"><span class="ft-archived-status">Archived</span></td>
                                <td data-label="Archived"><span class="ft-archived-date">{{ ($clientRow->archived_at ?? $clientRow->updated_at)?->format('M j, Y') ?? '—' }}</span></td>
                                <td data-label="Actions">
                                    <div class="ft-archived-actions">
                                        @if(auth()->user()->canModule('clients','delete'))
                                            <button type="button" class="ft-archive-restore" wire:click="restoreClient({{ $clientRow->id }})" wire:confirm="Restore this client to the active client list?">Restore</button>
                                            <button type="button" class="ft-archive-delete" wire:click="openPermanentDeleteClient({{ $clientRow->id }})">Delete</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="ft-client-empty">No archived clients match the selected filters.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                    @else
                    <table class="ft-client-table">
                        <thead><tr><th>Client</th><th>Account manager</th><th>Jobs</th><th>Tasks</th><th>Next delivery</th><th>Outstanding</th><th>Updated</th><th>Actions</th></tr></thead>
                        <tbody>
                        @forelse($clients as $clientRow)
                            <tr
                                wire:key="client-row-{{ $clientRow->id }}"
                                class="{{ $showClientPreview && (int)$selectedClientId === (int)$clientRow->id ? 'selected' : '' }}"
                                wire:click="viewClient({{ $clientRow->id }})"
                                wire:keydown.enter="viewClient({{ $clientRow->id }})"
                                wire:keydown.space.prevent="viewClient({{ $clientRow->id }})"
                                tabindex="0"
                                aria-label="Open client {{ $clientRow->name }}"
                            >
                                <td data-label="Client"><div class="ft-client-identity"><x-ui.client-logo :client="$clientRow" :name="$clientRow->name" :size="34" /><span><b>{{ $clientRow->name }}</b><small>{{ $clientRow->country ?: '—' }}</small></span></div></td>
                                <td data-label="Account manager">@if($clientRow->accountManager)<div class="ft-client-person"><x-ui.avatar :user="$clientRow->accountManager" :name="$clientRow->accountManager->name" :size="26" /><span>{{ $clientRow->accountManager->name }}</span></div>@else<span class="muted">Unassigned</span>@endif</td>
                                <td data-label="Jobs"><b>{{ $clientRow->active_jobs_count }} / {{ $clientRow->total_jobs_count }}</b> active<div class="ft-mini-progress"><span style="width:{{ $clientRow->total_jobs_count ? min(100,round(($clientRow->active_jobs_count/$clientRow->total_jobs_count)*100)) : 0 }}%"></span></div></td>
                                <td data-label="Tasks">
                                    <b>{{ $clientRow->open_tasks_count }}</b> open
                                    @if ((int) $clientRow->overdue_tasks_count > 0)
                                        <small class="ft-text-red">{{ $clientRow->overdue_tasks_count }} overdue</small>
                                    @elseif ((int) $clientRow->blocked_tasks_count > 0)
                                        <small class="ft-text-purple">{{ $clientRow->blocked_tasks_count }} blocked</small>
                                    @else
                                        <small class="ft-text-green">0 overdue</small>
                                    @endif
                                </td>
                                <td data-label="Next delivery">{{ $clientRow->next_delivery_at ? \Carbon\Carbon::parse($clientRow->next_delivery_at)->format('M j') : '—' }}</td>
                                <td data-label="Outstanding"><b>${{ number_format($clientRow->outstanding_balance,0) }}</b></td>
                                <td data-label="Updated">{{ $clientRow->updated_at?->diffForHumans(short:true) }}</td>
                                <td
                                    data-label="Actions"
                                    class="ft-client-action-cell"
                                    x-data="window.FlowTrack.ui.floatingActionMenu()"
                                    x-on:resize.window="positionMenu()"
                                    x-on:scroll.window="positionMenu()"
                                >
                                    <button x-ref="trigger" type="button" class="ft-client-more" wire:click.stop="toggleClientMenu({{ $clientRow->id }})" aria-label="Client actions">⋮</button>
                                    @if($actionMenuClientId === (int)$clientRow->id)
                                        <div
                                            x-ref="menu"
                                            x-cloak
                                            x-show="menuStyle !== ''"
                                            x-init="$nextTick(() => positionMenu())"
                                            x-bind:style="menuStyle"
                                            class="ft-client-action-menu"
                                            x-on:click.stop
                                        >
                                            <button type="button" wire:click.stop="viewClient({{ $clientRow->id }})">View client</button>
                                            @php
                                                $access = app(\App\Services\AccessControlService::class);
                                                $rowCanEdit = $access->isAdministrator(auth()->user()) || $access->canEditAll(auth()->user(),'clients') || ($access->canEditOwn(auth()->user(),'clients') && (int)$clientRow->account_manager_id === (int)auth()->id());
                                            @endphp
                                            @if(!$showArchived && $rowCanEdit)<button type="button" wire:click.stop="editClient({{ $clientRow->id }})">Edit client</button>@endif
                                            @if(auth()->user()->canModule('clients','delete'))
                                                @if($showArchived)
                                                    <button type="button" wire:click.stop="restoreClient({{ $clientRow->id }})" wire:confirm="Restore this client to the active client list?">Restore client</button>
                                                @else
                                                    <button type="button" class="danger" wire:click.stop="deleteClient({{ $clientRow->id }})" wire:confirm="Archive this client? Existing history will be preserved and the client can be restored later.">Archive client</button>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="ft-client-empty">{{ $showArchived ? 'No archived clients match the selected filters.' : 'No clients match the selected filters.' }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                    @endif
                </div>
                <div class="ft-client-pagination">
                    <span>Showing {{ $clients->firstItem() ?? 0 }}–{{ $clients->lastItem() ?? 0 }} of {{ $clients->total() }} {{ $showArchived ? 'archived ' : '' }}clients</span>
                    <div><label>Rows per page:</label><select wire:model.live="perPage"><option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="40">40</option></select><button type="button" wire:click="previousPage" @disabled($clients->onFirstPage())>Previous</button><span>Page {{ $clients->currentPage() }} of {{ max(1,$clients->lastPage()) }}</span><button type="button" wire:click="nextPage" @disabled(!$clients->hasMorePages())>Next →</button></div>
                </div>
            </div>
        </section>

    </div>

    @if($deleteCandidate)
        <div
            class="ft-client-delete-layer"
            wire:key="delete-archived-client-dialog-{{ $deleteCandidate->id }}"
            x-data="{ acknowledged: false }"
            x-on:keydown.escape.window="$wire.closePermanentDeleteClient()"
        >
            <section class="ft-client-delete-dialog" role="alertdialog" aria-modal="true" aria-labelledby="ft-client-delete-title" aria-describedby="ft-client-delete-description">
                <header class="ft-client-delete-head">
                    <div class="ft-client-delete-title-wrap">
                        <span class="ft-client-delete-warning" aria-hidden="true">!</span>
                        <div>
                            <h2 id="ft-client-delete-title">Permanently delete client?</h2>
                            <p id="ft-client-delete-description">This action cannot be undone.</p>
                        </div>
                    </div>
                    <button type="button" class="ft-client-delete-close" wire:click="closePermanentDeleteClient" aria-label="Close">×</button>
                </header>

                <div class="ft-client-delete-summary">
                    <x-ui.client-logo :client="$deleteCandidate" :name="$deleteCandidate->name" :size="32" :archived="true" />
                    <span>
                        <strong>{{ $deleteCandidate->name }}</strong>
                        <small>{{ $deleteCandidate->code }} · Archived {{ ($deleteCandidate->archived_at ?? $deleteCandidate->updated_at)?->format('M j, Y') ?? '—' }}</small>
                    </span>
                </div>

                <div class="ft-client-delete-danger-note">
                    <strong>Permanent deletion</strong><br>
                    The client profile, contacts and stored client information will be permanently removed. Historical linked records must not be cascade-deleted.
                </div>

                <label class="ft-client-delete-check">
                    <input type="checkbox" x-model="acknowledged" wire:model="deleteArchivedClientConfirmed">
                    <span>I understand that this client cannot be recovered after deletion.</span>
                </label>
                @error('deleteArchivedClientConfirmed')<div class="ft-client-delete-error">{{ $message }}</div>@enderror

                <footer class="ft-client-delete-actions">
                    <button type="button" class="ft-client-delete-cancel" wire:click="closePermanentDeleteClient">Cancel</button>
                    <button
                        type="button"
                        class="ft-client-delete-confirm"
                        x-bind:disabled="!acknowledged"
                        wire:click="permanentlyDeleteClient"
                        wire:loading.attr="disabled"
                        wire:target="permanentlyDeleteClient"
                    >
                        <span wire:loading.remove wire:target="permanentlyDeleteClient">Permanently delete</span>
                        <span wire:loading wire:target="permanentlyDeleteClient">Deleting…</span>
                    </button>
                </footer>
            </section>
        </div>
    @endif


</div>
