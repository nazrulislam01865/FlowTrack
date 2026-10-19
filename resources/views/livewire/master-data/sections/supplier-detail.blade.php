@php
    $supplier = $supplierDetail;
    $products = collect($supplierDetailProducts);
    $supplierName = trim((string) $supplier?->name);
    $initials = collect(preg_split('/\s+/u', $supplierName, -1, PREG_SPLIT_NO_EMPTY))
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $contactPerson = trim((string) data_get($supplier?->metadata, 'contact_person'));
    $email = trim((string) data_get($supplier?->metadata, 'email'));
    $phone = trim((string) data_get($supplier?->metadata, 'phone'));
    $shortCode = $supplier?->supplierShortCode() ?? '';
    $createdAt = $supplier?->created_at?->copy()->timezone($displayTimezone);
    $updatedAt = $supplier?->updated_at?->copy()->timezone($displayTimezone);
@endphp

<div class="ft-supplier-detail-page">
    <div class="ft-supplier-detail-breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('master-data', ['group' => 'supplier']) }}" wire:navigate>Suppliers</a>
        <span>/</span>
        <strong>{{ $supplier->name }}</strong>
    </div>

    @if(session('success'))
        <div class="flash success ft-master-flash">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="flash error ft-master-flash" role="alert">{{ session('warning') }}</div>
    @endif

    <header class="ft-supplier-detail-head">
        <div class="ft-supplier-detail-identity">
            <span class="ft-supplier-detail-avatar" aria-hidden="true">{{ $initials ?: 'S' }}</span>
            <div>
                <div class="ft-supplier-detail-title-row">
                    <h1>{{ $supplier->name }}</h1>
                    <x-suppliers.status-badge :status="$supplier->status" />
                </div>
                <p>Supplier reference {{ $supplier->code ?: '—' }}</p>
            </div>
        </div>

        <div class="ft-supplier-detail-actions">
            <a href="{{ route('master-data', ['group' => 'supplier']) }}" wire:navigate class="ft-supplier-list-button is-secondary">
                Back to suppliers
            </a>
            @if($canEditMaster)
                <a href="{{ route('master-data', ['group' => 'supplier', 'edit_supplier' => $supplier->id]) }}" wire:navigate class="ft-supplier-list-button is-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>
                    <span>Edit supplier</span>
                </a>
            @endif
        </div>
    </header>

    <section class="ft-supplier-detail-card" style="margin-bottom: 16px;">
        <div class="ft-supplier-detail-card-head"><div>
            <h2>Supplier portal connection</h2>
            <p>FlowTracker supplier ID {{ $supplier->id }} · Short code {{ $shortCode }}</p>
        </div></div>
        <div class="ft-supplier-detail-fields">
            <div class="ft-supplier-detail-field"><span>Portal account</span><strong>{{ data_get($supplier->metadata, 'portal_linked_at') ? 'Linked' : 'Not created from FlowTracker' }}</strong></div>
            @if(auth()->user()->canModule('suppliers', 'manage'))
                <div class="ft-supplier-detail-field"><span>Username</span><strong>{{ data_get($supplier->metadata, 'portal_username', '—') }}</strong></div>
                <div class="ft-supplier-detail-field"><span>Login email</span><strong>{{ data_get($supplier->metadata, 'portal_email', '—') }}</strong></div>
            @endif
            <div class="ft-supplier-detail-field"><span>Assigned products</span><strong>{{ $products->count() }}</strong></div>
        </div>
        @if($canEditMaster)
            <button type="button" class="ft-supplier-list-button is-secondary" wire:click="syncSupplierPortal" wire:loading.attr="disabled" wire:target="syncSupplierPortal">Sync supplier &amp; products</button>
            @error('portalSync') <div role="alert" class="flash error">{{ $message }}</div> @enderror
        @endif
    </section>

    <div class="ft-supplier-detail-summary">
        <x-suppliers.stat-card label="Assigned products" :value="number_format($products->count())" icon="product" />
        <x-suppliers.stat-card label="Supplier status" :value="$supplier->status === 'active' ? 'Active' : 'Inactive'" icon="supplier" />
        <x-suppliers.stat-card label="Last updated" :value="$updatedAt?->format('M j, Y') ?? '—'" icon="clock" />
    </div>

    <div class="ft-supplier-detail-grid">
        <section class="ft-supplier-detail-card">
            <div class="ft-supplier-detail-card-head">
                <div>
                    <h2>Supplier information</h2>
                    <p>Primary contact and supplier record information.</p>
                </div>
            </div>

            <div class="ft-supplier-detail-fields">
                <div class="ft-supplier-detail-field">
                    <span>Supplier name</span>
                    <strong>{{ $supplier->name }}</strong>
                </div>
                <div class="ft-supplier-detail-field">
                    <span>Reference code</span>
                    <strong>{{ $supplier->code ?: '—' }}</strong>
                </div>
                <div class="ft-supplier-detail-field">
                    <span>Short code</span>
                    <strong>{{ $shortCode !== '' ? $shortCode : '—' }}</strong>
                </div>
                <div class="ft-supplier-detail-field">
                    <span>Contact person</span>
                    <strong>{{ $contactPerson !== '' ? $contactPerson : '—' }}</strong>
                </div>
                <div class="ft-supplier-detail-field">
                    <span>Email</span>
                    @if($email !== '')
                        <a href="mailto:{{ $email }}">{{ $email }}</a>
                    @else
                        <strong>—</strong>
                    @endif
                </div>
                <div class="ft-supplier-detail-field">
                    <span>Phone</span>
                    @if($phone !== '')
                        <a href="tel:{{ preg_replace('/[^+0-9]/', '', $phone) }}">{{ $phone }}</a>
                    @else
                        <strong>—</strong>
                    @endif
                </div>
                <div class="ft-supplier-detail-field">
                    <span>Status</span>
                    <x-suppliers.status-badge :status="$supplier->status" />
                </div>
            </div>
        </section>

        <aside class="ft-supplier-detail-card ft-supplier-detail-record-card">
            <div class="ft-supplier-detail-card-head">
                <div>
                    <h2>Record details</h2>
                    <p>Audit information for this supplier.</p>
                </div>
            </div>
            <div class="ft-supplier-detail-record-list">
                <div><span>Created</span><strong>{{ $createdAt?->format('M j, Y · g:i A') ?? '—' }}</strong></div>
                <div><span>Last updated</span><strong>{{ $updatedAt?->format('M j, Y · g:i A') ?? '—' }}</strong></div>
                <div><span>Created by</span><strong>{{ $supplier->creator?->name ?: '—' }}</strong></div>
                <div><span>Products linked</span><strong>{{ number_format($products->count()) }}</strong></div>
            </div>
        </aside>
    </div>

    <section class="ft-supplier-detail-card ft-supplier-detail-products-card">
        <div class="ft-supplier-detail-card-head is-with-action">
            <div>
                <h2>Assigned products</h2>
                <p>Products currently linked to this supplier.</p>
            </div>
            @if(auth()->user()->canModule('catalog_products', 'view'))
                <a href="{{ route('master-data', ['group' => 'product', 'supplier_id' => $supplier->id]) }}" wire:navigate class="ft-supplier-list-button is-secondary">
                    View all products
                </a>
            @endif
        </div>

        @if($products->isEmpty())
            <div class="ft-supplier-detail-empty">
                <strong>No products assigned</strong>
                <span>Link products from the Product catalogue when this supplier is ready to be used.</span>
                @if(auth()->user()->canModule('catalog_products', 'view'))
                    <a href="{{ route('master-data', ['group' => 'product', 'supplier_id' => $supplier->id]) }}" wire:navigate>Open product catalogue</a>
                @endif
            </div>
        @else
            <div class="ft-supplier-detail-product-table-wrap">
                <table class="ft-supplier-detail-product-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Code</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th aria-label="Actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($products->take(12) as $product)
                            <tr>
                                <td><strong>{{ $product->name }}</strong></td>
                                <td>{{ $product->productDisplayCode() }}</td>
                                <td>{{ $product->productClassificationPath() ?: ($product->parent?->name ?: '—') }}</td>
                                <td><x-suppliers.status-badge :status="$product->status" /></td>
                                <td>
                                    @if(auth()->user()->canModule('catalog_products', 'view'))
                                        <a href="{{ route('master-data', ['group' => 'product', 'open' => $product->id]) }}" wire:navigate>View</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($products->count() > 12)
                <div class="ft-supplier-detail-product-footer">
                    Showing 12 of {{ number_format($products->count()) }} linked products.
                    @if(auth()->user()->canModule('catalog_products', 'view'))
                        <a href="{{ route('master-data', ['group' => 'product', 'supplier_id' => $supplier->id]) }}" wire:navigate>View all products</a>
                    @endif
                </div>
            @endif
        @endif
    </section>
</div>
