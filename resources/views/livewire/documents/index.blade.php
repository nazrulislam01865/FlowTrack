@php
    $formatBytes = static function (int $bytes): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);
        $precision = $power >= 3 ? 1 : ($value >= 10 ? 0 : 1);
        return number_format($value, $precision).' '.$units[$power];
    };
    $formatUpdated = static function ($value): string {
        $local = \App\Support\UserLocalTime::localize($value);
        if (!$local) return '—';
        $today = app(\App\Services\WorkspaceSettingsService::class)->localToday();
        if ($local->toDateString() === $today->toDateString()) {
            $minutes = max(0, (int) $local->diffInMinutes(now(), false));
            if ($minutes >= 0 && $minutes < 60) return $minutes <= 1 ? 'Just now' : $minutes.' minutes ago';
            $hours = max(1, (int) floor($minutes / 60));
            if ($hours <= 3) return $hours.' '.\Illuminate\Support\Str::plural('hour', $hours).' ago';
            return 'Today, '.$local->format('g:i A');
        }
        if ($local->toDateString() === $today->copy()->subDay()->toDateString()) return 'Yesterday';
        return $local->format('M j, Y');
    };
    $fileType = static function (string $extension): string {
        return match (strtolower($extension)) {
            'pdf' => 'PDF',
            'doc', 'docx' => 'DOCX',
            'xls', 'xlsx', 'csv' => 'XLSX',
            'jpg', 'jpeg' => 'JPG',
            'png' => 'PNG',
            'zip' => 'ZIP',
            'txt' => 'TXT',
            default => strtoupper($extension ?: 'FILE'),
        };
    };
    $lastPage = max(1, (int) $documents->lastPage());
    $currentPage = max(1, (int) $documents->currentPage());
    $pageNumbers = collect([1, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage])
        ->filter(fn ($page) => $page >= 1 && $page <= $lastPage)
        ->unique()->sort()->values();
@endphp

<div id="document-archive-app" class="ft-da-page">
    <header class="ft-da-page-header">
        <div class="ft-da-title-block">
            <div class="ft-da-eyebrow">Documents</div>
            <h1>Document archive</h1>
            <p>Find every file linked to clients, inquiries, orders and tasks.</p>
            <div class="ft-da-summary-line">
                <strong>{{ number_format($documentCount) }} documents</strong>
                <span aria-hidden="true">•</span>
                <strong>{{ $formatBytes((int) $storageBytes) }} used</strong>
            </div>
        </div>

    </header>

    @if(session('success'))
        <div class="ft-da-flash">{{ session('success') }}</div>
    @endif

    <section class="ft-da-archive-card" aria-label="Document archive">
        <div class="ft-da-search-area" x-data x-on:keydown.window.meta.k.prevent="$refs.archiveSearch.focus()" x-on:keydown.window.ctrl.k.prevent="$refs.archiveSearch.focus()">
            <div class="ft-da-search-box">
                <svg class="ft-da-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input
                    x-ref="archiveSearch"
                    type="search"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search filename, client, order, inquiry, task or document text..."
                    autocomplete="off"
                    aria-label="Search documents"
                >
                <span class="ft-da-shortcut"><kbd>⌘</kbd><kbd>K</kbd></span>
            </div>
            <p>Search includes file names, linked records and extracted text from supported documents.</p>
        </div>

        <x-ui.filter-bar class="ft-da-filter-row" label="Document filters">
            <div class="ft-da-filter-group">
                <x-ui.search-select
                    class="ft-da-select-wrap ft-da-search-select"
                    label="Client"
                    property="client"
                    type="clients"
                    context="documents"
                    :value="$client"
                    placeholder="All clients"
                    :initial-options="$clientOptions"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="280"
                    wire:key="documents-client-filter-{{ $client ?: 'all' }}"
                />

                <label class="ft-da-select-wrap">
                    <span class="sr-only">Linked record</span>
                    <select wire:model.live="linkType">
                        <option value="">Order or inquiry</option>
                        <option value="order">Orders</option>
                        <option value="inquiry">Inquiries</option>
                        <option value="task">Tasks</option>
                        <option value="client">Client only</option>
                        <option value="unlinked">Not linked</option>
                    </select>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg>
                </label>

                <x-ui.search-select
                    class="ft-da-select-wrap ft-da-search-select"
                    label="Uploaded by"
                    property="uploader"
                    type="users"
                    context="documents"
                    :value="$uploader"
                    placeholder="Uploaded by"
                    :initial-options="$uploaderOptions"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="280"
                    wire:key="documents-uploader-filter-{{ $uploader ?: 'all' }}"
                />

                <label class="ft-da-select-wrap ft-da-date-select">
                    <svg class="ft-da-calendar-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>
                    <span class="sr-only">Updated date</span>
                    <select wire:model.live="dateRange">
                        <option value="">Any time</option>
                        <option value="today">Today</option>
                        <option value="7_days">Last 7 days</option>
                        <option value="30_days">Last 30 days</option>
                    </select>
                    <svg class="ft-da-select-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg>
                </label>

                <button type="button" class="ft-da-clear-filters" wire:click="clearFilters">Clear filters</button>
            </div>

            <label class="ft-da-select-wrap ft-da-sort-select">
                <span class="sr-only">Sort documents</span>
                <select wire:model.live="sort">
                    <option value="updated_desc">Updated: newest</option>
                    <option value="updated_asc">Updated: oldest</option>
                    <option value="name_asc">Name: A–Z</option>
                    <option value="name_desc">Name: Z–A</option>
                    <option value="size_desc">Size: largest</option>
                </select>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg>
            </label>
        </x-ui.filter-bar>

        <div class="ft-da-result-count">{{ number_format($documents->total()) }} documents</div>

        <div class="ft-da-table-scroll ft-results-refreshable" wire:loading.class="is-refreshing" wire:target="search,client,linkType,uploader,dateRange,sort,perPage,gotoPage,previousPage,nextPage">
            <table class="ft-da-table">
                <colgroup>
                    <col class="ft-da-col-name">
                    <col class="ft-da-col-record">
                    <col class="ft-da-col-task">
                    <col class="ft-da-col-client">
                    <col class="ft-da-col-uploader">
                    <col class="ft-da-col-updated">
                    <col class="ft-da-col-size">
                    <col class="ft-da-col-actions">
                </colgroup>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Order / inquiry</th>
                        <th>Task</th>
                        <th>Client</th>
                        <th>Uploaded by</th>
                        <th>Updated</th>
                        <th>Size</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($documents as $row)
                        @php
                            $extension = $row['extension'];
                            $type = $fileType($extension);
                            $fileClass = in_array($extension, ['jpg', 'jpeg', 'png'], true) ? 'image' : ($extension ?: 'file');
                        @endphp
                        <tr class="{{ $row['is_unlinked'] ? 'ft-da-unlinked-row' : '' }}" wire:key="document-archive-row-{{ $row['source'] }}-{{ $row['id'] }}">
                            <td>
                                <div class="ft-da-file-cell">
                                    <span class="ft-da-file-icon ft-da-file-{{ $fileClass }}" aria-hidden="true">
                                        @if(in_array($extension, ['jpg', 'jpeg', 'png'], true))
                                            <svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><circle cx="9" cy="10" r="2"/><path d="m5 18 5-5 3 3 2-2 4 4"/></svg>
                                        @else
                                            <span>{{ mb_substr($type, 0, 4) }}</span>
                                        @endif
                                    </span>
                                    <span class="ft-da-file-copy">
                                        <button type="button" class="ft-da-file-name" wire:click="openDetails('{{ $row['source'] }}', {{ $row['id'] }})">{{ $row['name'] }}</button>
                                        <small>{{ $type }}</small>
                                    </span>
                                </div>
                            </td>
                            <td>
                                @if($row['is_unlinked'])
                                    <span class="ft-da-record-badge ft-da-record-unlinked">Not linked</span>
                                @elseif($row['is_client_only'])
                                    <span class="ft-da-client-only">Client only</span>
                                @else
                                    <div class="ft-da-record-cell">
                                        <span class="ft-da-record-badge">{{ $row['record_kind'] }}</span>
                                        @if($row['record_url'])
                                            <a href="{{ $row['record_url'] }}" wire:navigate>{{ $row['record_number'] }}</a>
                                        @else
                                            <span>{{ $row['record_number'] ?: '—' }}</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td>
                                @if($row['task_title'])
                                    <a class="ft-da-task-link" href="{{ $row['task_url'] }}" wire:navigate>
                                        {{ $row['task_title'] }}@if($row['task_number']) · {{ $row['task_number'] }}@endif
                                    </a>
                                @else
                                    <span class="ft-da-dash">—</span>
                                @endif
                            </td>
                            <td><span class="ft-da-client-name">{{ $row['client']?->name ?? '—' }}</span></td>
                            <td>
                                <div class="ft-da-uploader">
                                    <x-ui.avatar :user="$row['uploader']" :name="$row['uploader']?->name ?? 'FlowTrack'" :size="27" />
                                    <span>{{ $row['uploader']?->name ?? 'FlowTrack Super Admin' }}</span>
                                </div>
                            </td>
                            <td><time>{{ $formatUpdated($row['updated_at']) }}</time></td>
                            <td><span class="ft-da-size">{{ $formatBytes((int) $row['size']) }}</span></td>
                            <td>
                                <div class="ft-da-actions" x-data="{ open: false }">
                                    <a class="ft-da-icon-button" href="{{ $row['open_url'] }}" target="_blank" rel="noopener" aria-label="Preview {{ $row['name'] }}" title="Preview document">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                                    </a>
                                    @if(auth()->user()->canModule('document_archive', 'export'))
                                        <a class="ft-da-download-button" href="{{ $row['download_url'] }}">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"/></svg>
                                            <span>Download</span>
                                        </a>
                                    @endif
                                    <button
                                        type="button"
                                        class="ft-da-more-button"
                                        :aria-expanded="open ? 'true' : 'false'"
                                        aria-haspopup="menu"
                                        aria-controls="document-actions-{{ $row['source'] }}-{{ $row['id'] }}"
                                        aria-label="More actions for {{ $row['name'] }}"
                                        x-on:click.stop="
                                            const menu = $refs.menu;
                                            if (menu.matches(':popover-open')) { menu.hidePopover(); return; }
                                            const rect = $el.getBoundingClientRect();
                                            const menuWidth = 220;
                                            const menuHeight = 286;
                                            const edge = 12;
                                            const gap = 6;
                                            const left = Math.min(window.innerWidth - menuWidth - edge, Math.max(edge, rect.right - menuWidth));
                                            const openAbove = (window.innerHeight - rect.bottom) < (menuHeight + gap + edge) && rect.top > (menuHeight + gap + edge);
                                            const top = openAbove ? rect.top - menuHeight - gap : rect.bottom + gap;
                                            menu.style.left = `${left}px`;
                                            menu.style.top = `${Math.max(edge, top)}px`;
                                            menu.showPopover();
                                        "
                                    >⋮</button>
                                    <div
                                        id="document-actions-{{ $row['source'] }}-{{ $row['id'] }}"
                                        class="ft-da-action-menu"
                                        x-ref="menu"
                                        popover="auto"
                                        role="menu"
                                        x-on:toggle="open = $event.newState === 'open'"
                                        x-on:click.capture="
                                            const item = $event.target.closest('[role=menuitem]');
                                            if (item && $el.matches(':popover-open')) {
                                                $el.hidePopover();
                                            }
                                        "
                                    >
                                        <button type="button" role="menuitem" wire:click="openDetails('{{ $row['source'] }}', {{ $row['id'] }})">
                                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/></svg>
                                            <span>View document details</span>
                                        </button>
                                        @if($row['task_url'])
                                            <a role="menuitem" href="{{ $row['task_url'] }}" wire:navigate>
                                                <svg viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9"/><path d="M19 13v6H5V5h6"/></svg>
                                                <span>Open linked task</span>
                                            </a>
                                        @endif
                                        <div class="ft-da-menu-divider"></div>
                                        @if($row['can_edit'])
                                            <button type="button" role="menuitem" wire:click="openRename('{{ $row['source'] }}', {{ $row['id'] }})">
                                                <svg viewBox="0 0 24 24"><path d="m4 16-.5 4.5L8 20l10.5-10.5-4-4L4 16Z"/><path d="m13 7 4 4"/></svg>
                                                <span>Rename document</span>
                                            </button>
                                        @endif
                                        @if($row['supports_versions'] && auth()->user()->canModule('document_archive', 'create'))
                                            <button type="button" role="menuitem" wire:click="openVersionUpload({{ $row['id'] }})">
                                                <svg viewBox="0 0 24 24"><path d="M12 16V4m0 0-4 4m4-4 4 4"/><path d="M5 15v5h14v-5"/></svg>
                                                <span>Upload new version</span>
                                            </button>
                                            <button type="button" role="menuitem" wire:click="openVersions({{ $row['id'] }})">
                                                <svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.3-5.7L4 8"/><path d="M4 4v4h4M12 8v5l3 2"/></svg>
                                                <span>Version history</span>
                                            </button>
                                        @endif
                                        <button type="button" role="menuitem" x-on:click="navigator.clipboard?.writeText(@js($row['open_url'])); $refs.menu.hidePopover()">
                                            <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/></svg>
                                            <span>Copy link</span>
                                        </button>
                                        @if($row['can_delete'])
                                            <div class="ft-da-menu-divider"></div>
                                            <button type="button" class="ft-da-delete-menu-item" role="menuitem" wire:click="deleteArchiveDocument('{{ $row['source'] }}', {{ $row['id'] }})" wire:confirm="Delete {{ addslashes($row['name']) }}? This action cannot be undone.">
                                                <svg viewBox="0 0 24 24"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
                                                <span>Delete document</span>
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="ft-da-empty">No documents match the current filters.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="ft-da-table-footer">
            <div class="ft-da-footer-left">
                <span>
                    @if($documents->total())
                        Showing {{ $documents->firstItem() }}–{{ $documents->lastItem() }} of {{ number_format($documents->total()) }} documents
                    @else
                        Showing 0 documents
                    @endif
                </span>
                <label class="ft-da-select-wrap ft-da-per-page">
                    <span class="sr-only">Documents per page</span>
                    <select wire:model.live="perPage">
                        <option value="10">10 per page</option>
                        <option value="25">25 per page</option>
                        <option value="50">50 per page</option>
                        <option value="100">100 per page</option>
                    </select>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg>
                </label>
            </div>

            @if($lastPage > 1)
                <nav class="ft-da-pagination" aria-label="Documents pagination">
                    <button type="button" wire:click="previousPage" @disabled($documents->onFirstPage())>Previous</button>
                    @php $previousRenderedPage = null; @endphp
                    @foreach($pageNumbers as $pageNumber)
                        @if($previousRenderedPage !== null && $pageNumber - $previousRenderedPage > 1)
                            <span class="ft-da-page-ellipsis">…</span>
                        @endif
                        <button type="button" class="{{ $pageNumber === $currentPage ? 'active' : '' }}" wire:click="gotoPage({{ $pageNumber }})" @if($pageNumber === $currentPage) aria-current="page" @endif>{{ $pageNumber }}</button>
                        @php $previousRenderedPage = $pageNumber; @endphp
                    @endforeach
                    <button type="button" wire:click="nextPage" @disabled(!$documents->hasMorePages())>Next</button>
                </nav>
            @endif
        </footer>
    </section>

    <div class="ft-da-info-bar">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/></svg>
        <span>Documents stay connected to the task, inquiry, order or client where they were uploaded in FlowTrack.</span>
    </div>

    @if($showUpload)
        <div class="overlay livewire-overlay" wire:click.self="closeUpload"></div>
        <div class="modal livewire-modal ft-doc-upload-modal" data-ft-feedback-scope="form" wire:key="document-upload-modal">
            <div class="modal-head">
                <div><h2>Upload document</h2><div class="small muted">Link the file to a visible Order and optionally to one of your assigned tasks.</div></div>
                <button type="button" class="close-btn" wire:click="closeUpload">×</button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field">
                        <x-ui.search-select
                            label="Order *"
                            property="uploadJobId"
                            type="jobs"
                            context="documents"
                            :value="$uploadJobId"
                            placeholder="Select Order"
                            :initial-options="$jobs"
                            :clearable="false"
                            :menu-width="420"
                            :fixed-menu="true"
                            wire:key="document-upload-order-{{ $uploadJobId ?: 'none' }}"
                        />
                        @error('uploadJobId')<x-ui.validation-message :message="$message" />@enderror
                    </div>
                    <div class="field">
                        <label>Task</label>
                        <select wire:model="uploadTaskId"><option value="">Order-level document</option>@foreach($uploadTasks as $task)<option value="{{ $task->id }}">{{ $task->phase?->short_name }} · {{ $task->title }}</option>@endforeach</select>
                    </div>
                    <div class="field full">
                        <label>Document type *</label>
                        <select wire:model="uploadCategory">@foreach($categories as $cat)<option>{{ $cat->name }}</option>@endforeach</select>
                        @error('uploadCategory')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field full">
                        <label class="upload-zone ft-livewire-upload-zone" data-file-dropzone for="document-page-upload-input">
                            <input id="document-page-upload-input" type="file" wire:model="documentUploads" multiple accept="{{ \App\Support\AttachmentUpload::accept() }}">
                            <b>Drop files here or browse</b>
                            <div class="small muted" data-drop-status>{{ \App\Support\AttachmentUpload::helperText(20) }}</div>
                        </label>
                        <div class="ft-file-upload-progress" wire:loading wire:target="documentUploads">Preparing selected files…</div>
                        @if(count($documentUploads))<div class="ft-upload-ready-list">@foreach($documentUploads as $file)<span>{{ $file->getClientOriginalName() }}</span>@endforeach</div>@endif
                    </div>
                </div>
                @error('documentUploads')<div class="validation-error">{{ $message }}</div>@enderror
                @error('documentUploads.*')<div class="validation-error">{{ $message }}</div>@enderror
            </div>
            <div class="modal-foot">
                <button type="button" class="ghost" wire:click="closeUpload">Cancel</button>
                <button type="button" class="primary" wire:click="storeDocuments" wire:loading.attr="disabled" wire:target="documentUploads,storeDocuments" @disabled(count($documentUploads) === 0)>
                    <span wire:loading.remove wire:target="storeDocuments">Upload document</span><span wire:loading wire:target="storeDocuments">Uploading…</span>
                </button>
            </div>
        </div>
    @endif

    @if($showDetails && $selected)
        <div class="overlay livewire-overlay" wire:click.self="closeDetails"></div>
        <div class="modal livewire-modal ft-da-details-modal" wire:key="document-details-modal">
            <div class="modal-head"><div><h2>Document details</h2><div class="small muted">File information and linked record.</div></div><button type="button" class="close-btn" wire:click="closeDetails">×</button></div>
            <div class="modal-body">
                <div class="ft-da-detail-file">
                    <span class="ft-da-file-icon ft-da-file-{{ strtolower(pathinfo($selected['name'], PATHINFO_EXTENSION) ?: 'file') }}"><span>{{ mb_substr(strtoupper(pathinfo($selected['name'], PATHINFO_EXTENSION) ?: 'FILE'), 0, 4) }}</span></span>
                    <div><h3>{{ $selected['name'] }}</h3><p>{{ $selected['mime_type'] ?: 'File' }} · {{ $formatBytes((int) $selected['size']) }}@if($selected['version']) · v{{ $selected['version'] }}@endif</p></div>
                </div>
                <dl class="ft-da-detail-grid">
                    <div><dt>Linked record</dt><dd>{{ $selected['record_label'] ?: '—' }}</dd></div>
                    <div><dt>Task</dt><dd>{{ $selected['task_label'] ?: '—' }}</dd></div>
                    <div><dt>Client</dt><dd>{{ $selected['client_name'] ?: '—' }}</dd></div>
                    <div><dt>Uploaded by</dt><dd>{{ $selected['uploader']?->name ?? 'FlowTrack' }}</dd></div>
                    <div><dt>Uploaded</dt><dd>{{ \App\Support\UserLocalTime::format($selected['created_at'], 'M j, Y g:i A') }}</dd></div>
                    <div><dt>Updated</dt><dd>{{ \App\Support\UserLocalTime::format($selected['updated_at'], 'M j, Y g:i A') }}</dd></div>
                </dl>
            </div>
            <div class="modal-foot"><button type="button" class="ghost" wire:click="closeDetails">Close</button><a class="primary" href="{{ $selected['open_url'] }}" target="_blank" rel="noopener">Open document</a>@if(auth()->user()->canModule('document_archive', 'export'))<a class="ghost" href="{{ $selected['download_url'] }}">Download</a>@endif</div>
        </div>
    @endif

    @if($showRename)
        <div class="overlay livewire-overlay" wire:click.self="closeRename"></div>
        <div class="modal livewire-modal ft-da-small-modal" data-ft-feedback-scope="form" wire:key="document-rename-modal">
            <div class="modal-head"><div><h2>Rename document</h2><div class="small muted">Change the display name without moving the stored file.</div></div><button type="button" class="close-btn" wire:click="closeRename">×</button></div>
            <div class="modal-body"><div class="field"><label>File name *</label><input type="text" wire:model="renameName" maxlength="255">@error('renameName')<div class="validation-error">{{ $message }}</div>@enderror</div></div>
            <div class="modal-foot"><button type="button" class="ghost" wire:click="closeRename">Cancel</button><button type="button" class="primary" wire:click="renameDocument">Rename</button></div>
        </div>
    @endif

    @if($showVersionUpload)
        <div class="overlay livewire-overlay" wire:click.self="closeVersionUpload"></div>
        <div class="modal livewire-modal ft-da-small-modal" data-ft-feedback-scope="form" wire:key="document-version-upload-modal">
            <div class="modal-head"><div><h2>Upload new version</h2><div class="small muted">The new file is added to the same document history.</div></div><button type="button" class="close-btn" wire:click="closeVersionUpload">×</button></div>
            <div class="modal-body">
                <label class="upload-zone ft-livewire-upload-zone" data-file-dropzone for="document-version-upload-input">
                    <input id="document-version-upload-input" type="file" wire:model="versionUpload" accept="{{ \App\Support\AttachmentUpload::accept() }}">
                    <b>Drop a file here or browse</b><div class="small muted">{{ \App\Support\AttachmentUpload::helperText(20) }}</div>
                </label>
                @error('versionUpload')<div class="validation-error">{{ $message }}</div>@enderror
            </div>
            <div class="modal-foot"><button type="button" class="ghost" wire:click="closeVersionUpload">Cancel</button><button type="button" class="primary" wire:click="storeNewVersion" wire:loading.attr="disabled" wire:target="versionUpload,storeNewVersion" @disabled(!$versionUpload)>Upload version</button></div>
        </div>
    @endif

    @if($showVersions)
        <div class="overlay livewire-overlay" wire:click.self="closeVersions"></div>
        <div class="modal livewire-modal ft-da-versions-modal" wire:key="document-version-history-modal">
            <div class="modal-head"><div><h2>Version history</h2><div class="small muted">Previous versions of this document.</div></div><button type="button" class="close-btn" wire:click="closeVersions">×</button></div>
            <div class="modal-body">
                <div class="ft-da-version-list">
                    @forelse($versions as $version)
                        <div class="ft-da-version-row">
                            <strong>{{ $version->name }}@unless(\App\Support\ArtworkDocumentName::hasVersion((string) $version->name)) · Version {{ max(1, (int) $version->version) }}@endunless</strong>
                            <em class="ft-da-version-state {{ $loop->first ? 'is-latest' : 'is-archived' }}">{{ $loop->first ? 'Latest' : 'Archived' }}</em>
                            <span>{{ $version->uploader?->name ?? 'FlowTrack' }}</span>
                            <time>{{ \App\Support\UserLocalTime::format($version->created_at, 'M j, Y g:i A') }}</time>
                            <span>{{ $formatBytes((int) $version->size) }}</span>
                            <a href="{{ route('documents.open', $version) }}" target="_blank" rel="noopener">Open</a>
                        </div>
                    @empty
                        <div class="ft-da-empty">No versions found.</div>
                    @endforelse
                </div>
            </div>
            <div class="modal-foot"><button type="button" class="ghost" wire:click="closeVersions">Close</button></div>
        </div>
    @endif
</div>
