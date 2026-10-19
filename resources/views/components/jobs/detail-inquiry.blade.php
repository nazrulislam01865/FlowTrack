@props([
    'job',
    'results'=>collect(),
    'search'=>'',
    'selectedInquiry'=>null,
    'showLinkConfirm'=>false,
    'showUnlinkConfirm'=>false,
    'unlinkInquiryId'=>null,
    'canManage'=>false,
    'linkedInquiryCanOpen'=>false,
    'canViewLinkedInquiryDocuments'=>false,
    'canExportLinkedInquiryDocuments'=>false,
])
@php
    $linkedInquiries = $job->relationLoaded('linkedInquiries') ? $job->linkedInquiries : collect();
    $linkedCount = $linkedInquiries->count();
    $linkedFileCount = $canViewLinkedInquiryDocuments
        ? $linkedInquiries->sum(fn ($inquiry) => $inquiry->relationLoaded('documents') ? $inquiry->documents->count() : 0)
        : null;
    $unlinkInquiry = $unlinkInquiryId ? $linkedInquiries->firstWhere('id', (int) $unlinkInquiryId) : null;
    $phaseName = $job->phase?->name ?? $job->status;
    $phaseSequence = (int) ($job->phase?->sequence ?? 1);
    $progress = max(0, min(100, (int) ($job->progress ?? 0)));
    $nextAction = trim((string) ($job->next_action ?? '')) ?: 'No action currently required';
    $selectedClientMatch = $selectedInquiry && (int) $selectedInquiry->client_id === (int) $job->client_id;
    $searchLength = mb_strlen(trim((string) $search));
    $inquiryService = app(\App\Services\InquiryService::class);
@endphp

<div class="ft-order-inquiry-link" wire:key="order-inquiry-link-{{ $job->id }}">
    <section class="ft-oil-summary" aria-label="Order summary">
        <div class="ft-oil-summary-card">
            <span class="ft-oil-summary-icon">▣</span>
            <span><small>Current phase</small><strong>{{ $phaseName }} · Phase {{ max(1, $phaseSequence) }}</strong></span>
        </div>
        <div class="ft-oil-summary-card">
            <span class="ft-oil-summary-icon">↗</span>
            <span><small>Overall progress</small><strong>{{ $progress }}%</strong><span class="ft-oil-progress"><i style="width:{{ $progress }}%"></i></span></span>
        </div>
        <div class="ft-oil-summary-card">
            <span class="ft-oil-summary-icon">⌘</span>
            <span><small>Next required action</small><strong>{{ $nextAction }}</strong></span>
        </div>
    </section>

    <section class="ft-oil-panel">
        <header class="ft-oil-panel-head">
            <div>
                <h2>Linked inquiries</h2>
                <p>Keep every Inquiry related to this Order together, with its files available in one place.</p>
            </div>
            <div class="ft-oil-security-note"><i>⌑</i><span>Existing Inquiry permissions still apply</span></div>
        </header>

        <div class="ft-oil-panel-body">
            <div class="ft-oil-callout">
                <span class="ft-oil-callout-icon">i</span>
                <span>
                    <strong>Multiple Inquiries can be linked to one Order</strong>
                    <span>Each Inquiry can belong to only one Order. Linking keeps traceability and shows the Inquiry files here without copying them.</span>
                </span>
            </div>

            @error('inquiryLink')
                <div class="ft-oil-error" role="alert">{{ $message }}</div>
            @enderror

            @if(!$linkedInquiryCanOpen)
                <div class="ft-oil-files-locked ft-oil-inquiry-access-note">
                    <span>⌑</span>
                    <span>Linked Inquiry details are hidden because your role does not have <b>Inquiry → View</b> access.</span>
                </div>
            @else
                <section class="ft-oil-linked-section" aria-label="Linked inquiries">
                    <div class="ft-oil-linked-section-head">
                        <div>
                            <h3>{{ $linkedCount ? 'Linked to this order' : 'No inquiries linked yet' }}</h3>
                            <p>{{ $linkedCount ? 'Open any Inquiry, review its files, or unlink only the relationship you no longer need.' : 'Use the search below to connect the first Inquiry to this Order.' }}</p>
                        </div>
                        <div class="ft-oil-linked-stats" aria-label="Linked inquiry summary">
                            <span><b>{{ $linkedCount }}</b> {{ \Illuminate\Support\Str::plural('Inquiry', $linkedCount) }}</span>
                            @if($canViewLinkedInquiryDocuments)
                                <span><b>{{ (int) $linkedFileCount }}</b> {{ \Illuminate\Support\Str::plural('File', (int) $linkedFileCount) }}</span>
                            @endif
                        </div>
                    </div>

                    @if($linkedCount)
                        <div class="ft-oil-inquiry-stack">
                            @foreach($linkedInquiries as $linked)
                                @php
                                    $isPrimaryInquiry = (int) ($job->source_inquiry_id ?? 0) === (int) $linked->id;
                                    $linkedInquiryStatusColor = $inquiryService->inquiryStatusColor((string) $linked->status);
                                    $linkedInquiryDocuments = $canViewLinkedInquiryDocuments && $linked->relationLoaded('documents')
                                        ? $linked->documents
                                        : collect();
                                @endphp
                                <article class="ft-oil-inquiry-card" wire:key="order-linked-inquiry-{{ $linked->id }}">
                                    <div class="ft-oil-inquiry-card-head">
                                        <div class="ft-oil-inquiry-identity">
                                            <span class="ft-oil-linked-icon">✓</span>
                                            <div class="ft-oil-inquiry-title-block">
                                                <div class="ft-oil-inquiry-kicker">
                                                    @if($isPrimaryInquiry)<span class="ft-oil-primary-source-badge">Primary source</span>@endif
                                                    <span class="ft-oil-inquiry-file-chip">{{ $canViewLinkedInquiryDocuments ? $linkedInquiryDocuments->count().' '.\Illuminate\Support\Str::plural('file', $linkedInquiryDocuments->count()) : 'Files permission-based' }}</span>
                                                </div>
                                                @if($linkedInquiryCanOpen)
                                                    <a class="ft-oil-linked-id" href="{{ route('inquiries.index', ['open'=>$linked->id]) }}" wire:navigate>{{ $linked->inquiry_number }}</a>
                                                @else
                                                    <span class="ft-oil-linked-id">{{ $linked->inquiry_number }}</span>
                                                @endif
                                                <span class="ft-oil-linked-title" title="{{ $linked->subject }}">{{ $linked->subject }}</span>
                                                <span class="ft-oil-linked-meta">
                                                    <span>{{ $linked->client?->name ?: 'No client' }}</span>
                                                    <span class="ft-master-color-inline-label" style="{{ \App\Support\MasterColor::style($linkedInquiryStatusColor) }}">{{ $linked->status }}</span>
                                                    <span>Owner: {{ $linked->owner?->name ?: 'Unassigned' }}</span>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="ft-oil-linked-actions">
                                            @if($linkedInquiryCanOpen)
                                                <a class="ft-oil-secondary" href="{{ route('inquiries.index', ['open'=>$linked->id]) }}" wire:navigate>Open inquiry ↗</a>
                                            @endif
                                            @if($canManage)
                                                <button class="ft-oil-danger" type="button" wire:click="openInquiryUnlinkConfirm({{ $linked->id }})">Unlink</button>
                                            @endif
                                        </div>
                                    </div>

                                    @if($canViewLinkedInquiryDocuments)
                                        <details class="ft-oil-files ft-oil-files-collapsible" open>
                                            <summary class="ft-oil-files-head">
                                                <span class="ft-oil-files-head-label"><i>▱</i> Inquiry files</span>
                                                <span class="ft-oil-files-head-right"><small>Click to collapse</small><b>{{ $linkedInquiryDocuments->count() }}</b><i class="ft-oil-files-chevron">⌄</i></span>
                                            </summary>

                                            @if($linkedInquiryDocuments->isEmpty())
                                                <div class="ft-oil-files-empty">No files have been uploaded to this Inquiry yet.</div>
                                            @else
                                                <div class="ft-oil-files-list">
                                                    @foreach($linkedInquiryDocuments as $document)
                                                        @php
                                                            $fileExtension = strtoupper(pathinfo((string) $document->name, PATHINFO_EXTENSION) ?: 'FILE');
                                                            $fileSize = (int) ($document->size ?? 0);
                                                            $fileSizeLabel = $fileSize >= 1048576
                                                                ? number_format($fileSize / 1048576, 1).' MB'
                                                                : ($fileSize > 0 ? max(1, (int) ceil($fileSize / 1024)).' KB' : 'Size unavailable');
                                                            $fileSource = $document->task?->title
                                                                ? 'Task: '.$document->task->title
                                                                : 'Inquiry attachment';
                                                        @endphp
                                                        <article class="ft-oil-file-row" wire:key="order-linked-inquiry-document-{{ $linked->id }}-{{ $document->id }}">
                                                            <span class="ft-oil-file-type">{{ $fileExtension }}</span>
                                                            <span class="ft-oil-file-copy">
                                                                <strong title="{{ $document->name }}">{{ $document->name }}</strong>
                                                                <small>
                                                                    {{ $fileSizeLabel }} · {{ $fileSource }}
                                                                    @if($document->uploader?->name) · Uploaded by {{ $document->uploader->name }} @endif
                                                                    @if($document->created_at) · {{ \App\Support\UserLocalTime::format($document->created_at, 'M j, Y, g:i A') }} @endif
                                                                </small>
                                                            </span>
                                                            <span class="ft-oil-file-actions">
                                                                <a href="{{ route('inquiries.documents.open', $document) }}" target="_blank" rel="noopener">Open</a>
                                                                @if($canExportLinkedInquiryDocuments)
                                                                    <a href="{{ route('inquiries.documents.download', $document) }}">Download</a>
                                                                @endif
                                                            </span>
                                                        </article>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </details>
                                    @else
                                        <div class="ft-oil-files-locked"><span>⌑</span><span>Files are hidden because your role does not have <b>Documents → View</b> access.</span></div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            <section class="ft-oil-add-section" aria-label="Link another inquiry">
                <div class="ft-oil-add-section-head">
                    <span class="ft-oil-add-icon">＋</span>
                    <div>
                        <h3>{{ $linkedCount ? 'Link another inquiry' : 'Link an inquiry' }}</h3>
                        <p>Search by Inquiry number, client, subject, product, or offer text.</p>
                    </div>
                </div>

                <div class="ft-oil-link-layout">
                    <section class="ft-oil-search-section" aria-busy="false">
                        <div class="ft-oil-section-head"><h3>Find an eligible inquiry</h3><p>Enter at least 2 characters. Inquiries already linked to any Order are shown as unavailable.</p></div>
                        <div class="ft-oil-search-wrap">
                            <span class="ft-oil-search-icon">⌕</span>
                            <input
                                class="ft-oil-search"
                                type="search"
                                autocomplete="off"
                                placeholder="Inquiry number, client, subject, product or offer text"
                                aria-label="Search inquiries"
                                wire:model.live.debounce.400ms="inquirySearch"
                                @disabled(!$canManage)
                            >
                            @if($search !== '')
                                <button class="ft-oil-clear" type="button" wire:click="clearInquirySearch">Clear</button>
                            @endif
                        </div>
                        <div class="ft-oil-search-meta">
                            <span>
                                @if(!$canManage)
                                    You do not have permission to link Inquiries to this Order
                                @elseif($searchLength < 2)
                                    {{ $searchLength ? 'Enter at least 2 characters' : 'Start with an Inquiry number or client name' }}
                                @else
                                    Results for “{{ trim($search) }}”
                                @endif
                            </span>
                            <span class="ft-oil-status-wrap">
                                <i class="ft-oil-spinner" wire:loading wire:target="inquirySearch" aria-hidden="true"></i>
                                @if($searchLength >= 2 && $canManage)<span>{{ $results->count() }} found</span>@endif
                            </span>
                        </div>

                        <div class="ft-oil-results" wire:loading.class="is-updating" wire:target="inquirySearch">
                            @if($searchLength < 2 || !$canManage)
                                <div class="ft-oil-empty"><span><strong>No search yet</strong>Try an Inquiry number, client name, subject, or product.</span></div>
                            @elseif($results->isEmpty())
                                <div class="ft-oil-empty"><span><strong>No matching inquiry</strong>Check the number or search with a client, product, or offer term.</span></div>
                            @else
                                @foreach($results as $inquiry)
                                    @php
                                        $pivotLinkedOrder = $inquiry->relationLoaded('linkedOrders') ? $inquiry->linkedOrders->first() : null;
                                        $linkedOrder = $pivotLinkedOrder ?: $inquiry->sourceOrder ?: $inquiry->convertedJob;
                                        $linkedToThisOrder = $linkedOrder && (int) $linkedOrder->id === (int) $job->id;
                                        $eligible = !$linkedOrder && (string) $inquiry->result !== 'dead';
                                        $isSelected = $selectedInquiry && (int) $selectedInquiry->id === (int) $inquiry->id;
                                        $clientMatch = (int) $inquiry->client_id === (int) $job->client_id;
                                        $product = $inquiry->items->pluck('item_name')->filter()->take(2)->join(', ');
                                        $updated = \App\Support\UserLocalTime::format($inquiry->updated_at, 'M j, Y');
                                    @endphp
                                    <button
                                        type="button"
                                        class="ft-oil-result {{ $isSelected ? 'selected' : '' }} {{ !$eligible ? 'disabled' : '' }}"
                                        wire:click="selectInquiryForLink({{ $inquiry->id }})"
                                        wire:key="order-inquiry-result-{{ $inquiry->id }}"
                                        @disabled(!$eligible)
                                    >
                                        <span class="ft-oil-radio"></span>
                                        <span>
                                            <span class="ft-oil-result-title">{{ $inquiry->inquiry_number }}</span>
                                            <span class="ft-oil-result-subject">{{ $inquiry->subject }}</span>
                                            <span class="ft-oil-result-meta">{{ $product ?: ($inquiry->reference_number ?: 'Inquiry') }} · Updated {{ $updated }}</span>
                                        </span>
                                        <span class="ft-oil-result-client"><strong>{{ $inquiry->client?->name ?: 'No client' }}</strong><small>{{ $clientMatch ? 'Client match' : 'Different client' }}</small></span>
                                        @php($resultInquiryStatusColor = $inquiryService->inquiryStatusColor((string) $inquiry->status))
                                        <span class="ft-oil-result-owner">
                                            <strong class="ft-master-color-inline-label" style="{{ \App\Support\MasterColor::style($resultInquiryStatusColor) }}">{{ $inquiry->status }}</strong>
                                            <small>
                                                @if($eligible)
                                                    Owner: {{ $inquiry->owner?->name ?: 'Unassigned' }}
                                                @elseif($linkedToThisOrder)
                                                    Already linked here
                                                @elseif($linkedOrder)
                                                    Linked to {{ $linkedOrder->displayOrderNumber() }}
                                                @else
                                                    Not eligible
                                                @endif
                                            </small>
                                        </span>
                                    </button>
                                @endforeach
                            @endif
                        </div>
                    </section>

                    <aside class="ft-oil-selection" aria-label="Selected inquiry">
                        <div class="ft-oil-section-head"><h3>Review before linking</h3><p>Verify the client and current availability.</p></div>
                        <div class="ft-oil-selection-body">
                            @if(!$selectedInquiry)
                                <div class="ft-oil-selection-empty"><span><i>↗</i>Select one eligible Inquiry from the results.</span></div>
                            @else
                                <div class="ft-oil-selected-card">
                                    <a class="ft-oil-selected-id" href="{{ route('inquiries.index', ['open'=>$selectedInquiry->id]) }}" wire:navigate>{{ $selectedInquiry->inquiry_number }}</a>
                                    <h4>{{ $selectedInquiry->subject }}</h4>
                                    <div class="ft-oil-checks">
                                        <div class="ft-oil-check"><span>Client</span><b class="{{ $selectedClientMatch ? '' : 'warn' }}">{{ $selectedClientMatch ? 'Match' : 'Different client' }}</b></div>
                                        <div class="ft-oil-check"><span>Inquiry availability</span><b>Eligible</b></div>
                                        <div class="ft-oil-check"><span>Files &amp; permissions</span><b>Read-through</b></div>
                                    </div>
                                    <button class="ft-oil-primary" type="button" wire:click="openInquiryLinkConfirm">Link to this order</button>
                                    <p class="ft-oil-helper">The Inquiry stays intact. Its files remain stored in the Inquiry and are shown here according to document permissions.</p>
                                </div>
                            @endif
                        </div>
                    </aside>
                </div>
            </section>

            <div class="ft-oil-permission"><span>⌑</span><span><b>Access remains permission-based.</b> Linked files are read directly from each Inquiry; nothing is copied into the Order.</span></div>
        </div>
    </section>

    @if($showLinkConfirm && $selectedInquiry)
        <div class="ft-oil-modal show" role="dialog" aria-modal="true" aria-labelledby="ft-inquiry-link-confirm-title" wire:key="inquiry-link-confirm-{{ $selectedInquiry->id }}">
            <div class="ft-oil-modal-card">
                <header class="ft-oil-modal-head"><h3 id="ft-inquiry-link-confirm-title">Link this Inquiry to the Order?</h3><button class="ft-oil-close" type="button" wire:click="closeInquiryLinkConfirm" aria-label="Close">×</button></header>
                <div class="ft-oil-modal-body">
                    <div class="ft-oil-pair">
                        <div class="ft-oil-pair-card"><small>Inquiry</small><strong>{{ $selectedInquiry->inquiry_number }}</strong></div>
                        <div class="ft-oil-pair-arrow">→</div>
                        <div class="ft-oil-pair-card"><small>Order</small><strong>{{ $job->displayOrderNumber() }}</strong></div>
                    </div>
                    <div class="ft-oil-modal-note">{{ $selectedClientMatch ? 'This adds another traceable Inquiry relationship. No files are copied and existing access permissions remain unchanged.' : 'The Inquiry belongs to a different client. Confirm the relationship is intentional before linking; this event will be recorded.' }}</div>
                </div>
                <footer class="ft-oil-modal-actions"><button class="ft-oil-cancel" type="button" wire:click="closeInquiryLinkConfirm">Cancel</button><button class="ft-oil-confirm" type="button" wire:click="confirmInquiryLink" wire:loading.attr="disabled" wire:target="confirmInquiryLink">Confirm link</button></footer>
            </div>
        </div>
    @endif

    @if($showUnlinkConfirm && $unlinkInquiry)
        <div class="ft-oil-modal show" role="dialog" aria-modal="true" aria-labelledby="ft-inquiry-unlink-title" wire:key="inquiry-unlink-confirm-{{ $unlinkInquiry->id }}">
            <div class="ft-oil-modal-card">
                <header class="ft-oil-modal-head"><h3 id="ft-inquiry-unlink-title">Unlink {{ $unlinkInquiry->inquiry_number }}?</h3><button class="ft-oil-close" type="button" wire:click="closeInquiryUnlinkConfirm" aria-label="Close">×</button></header>
                <div class="ft-oil-modal-body"><div class="ft-oil-modal-note">Only this Inquiry relationship will be removed. The Inquiry, its files, and the Order remain unchanged. The action is recorded in Order activity.</div></div>
                <footer class="ft-oil-modal-actions"><button class="ft-oil-cancel" type="button" wire:click="closeInquiryUnlinkConfirm">Cancel</button><button class="ft-oil-danger" type="button" wire:click="confirmInquiryUnlink" wire:loading.attr="disabled" wire:target="confirmInquiryUnlink">Unlink inquiry</button></footer>
            </div>
        </div>
    @endif
</div>
