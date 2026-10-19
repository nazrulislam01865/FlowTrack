@extends('layouts.app')
@section('content')
<div
    id="ft-bulk-import-page"
    data-validate-url="{{ route('orders.bulk-import.validate') }}"
    data-revalidate-url="{{ route('orders.bulk-import.revalidate') }}"
    data-import-url="{{ route('orders.bulk-import.import') }}"
    data-template-url="{{ route('orders.bulk-import.template') }}"
    data-orders-url="{{ route('jobs.index') }}"
>
    <header class="ftbi-page-head">
        <div class="ftbi-heading-copy">
            <a class="ftbi-breadcrumb" href="{{ route('jobs.index') }}" wire:navigate>
                <span aria-hidden="true">‹</span> Orders
            </a>
            <h1>Import orders</h1>
            <p>Create many orders safely from an Excel or CSV file.</p>
        </div>
        <div class="ftbi-head-actions">
            <a class="btn" href="{{ route('jobs.index') }}" wire:navigate>Back to orders</a>
            <a class="btn ftbi-template-btn" id="downloadTemplate" href="{{ route('orders.bulk-import.template') }}">
                <span aria-hidden="true">⇩</span> Download template
            </a>
        </div>
    </header>

    <nav class="steps" aria-label="Bulk order import progress">
        <div class="step active" data-step="1">
            <span class="n">1</span>
            <div><strong>Upload file</strong><span>Select Excel or CSV</span></div>
        </div>
        <div class="step" data-step="2">
            <span class="n">2</span>
            <div><strong>Validate</strong><span>Check IDs &amp; rules</span></div>
        </div>
        <div class="step" data-step="3">
            <span class="n">3</span>
            <div><strong>Review</strong><span>Check validation</span></div>
        </div>
        <div class="step" data-step="4">
            <span class="n">4</span>
            <div><strong>Import</strong><span>Create orders</span></div>
        </div>
    </nav>

    <section class="card upload" id="uploadCard">
        <div class="ftbi-upload-grid">
            <div class="ftbi-file-panel">
                <div class="ftbi-section-heading">
                    <span class="ftbi-section-number">1</span>
                    <div>
                        <h2>Select your order file</h2>
                        <p>Use the FlowTrack template for the cleanest import.</p>
                    </div>
                </div>

                <div class="drop" id="drop" tabindex="0" role="button" aria-label="Choose or drop an order file">
                    <span class="ftbi-upload-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <h3>Drop your order file here</h3>
                    <p class="ftbi-file-limits">.xlsx, .xls or .csv <span>•</span> up to 10,000 rows <span>•</span> maximum 20 MB</p>
                    <label class="btn primary ftbi-choose-btn" for="bulkOrderFile">Choose file</label>
                    <input class="fileInput" id="bulkOrderFile" type="file" accept=".xlsx,.xls,.csv">
                    <div class="ftbi-safe-note"><span aria-hidden="true">✓</span> Nothing is created until you confirm the import.</div>
                </div>

                <div class="ftbi-template-note">
                    <div class="ftbi-note-icon" aria-hidden="true">i</div>
                    <div>
                        <strong>Need the correct column format?</strong>
                        <span>Download the supplied template, add your orders, then upload it here.</span>
                    </div>
                    <a href="{{ route('orders.bulk-import.template') }}">Download template</a>
                </div>
            </div>

            <aside class="ftbi-config-panel" aria-label="Import setup">
                <div class="ftbi-section-heading compact">
                    <span class="ftbi-section-number">2</span>
                    <div>
                        <h2>Template rules</h2>
                        <p>Client ID, Client Reference Number, Shipping Address, Postal Code and Product ID are mandatory.</p>
                    </div>
                </div>

                <div class="ftbi-config-fields">
                    <div class="ftbi-client-workflow-note">
                        <span class="ftbi-auto-icon" aria-hidden="true">✓</span>
                        <div><strong>Client-based workflow</strong><span>FlowTrack resolves the Order workflow from the mandatory Client ID. If that client has no client-specific Order workflow, you can choose an available Order workflow during Review.</span></div>
                    </div>
                    <div class="ftbi-client-workflow-note">
                        <span class="ftbi-auto-icon" aria-hidden="true">✓</span>
                        <div><strong>Required Product</strong><span>Product ID is required so FlowTrack can generate the Order Title automatically from the Client Reference Number and Product name. Product Quantity defaults to 1 when left blank.</span></div>
                    </div>
                    <div class="ftbi-client-workflow-note">
                        <span class="ftbi-auto-icon" aria-hidden="true">✓</span>
                        <div><strong>Required shipping details</strong><span>Shipping Address and Postal Code are required. Phone Number is optional; when supplied it must start with an active Phone Country Code from Master Data, for example +880 1712345678.</span></div>
                    </div>
                    <div class="ftbi-client-workflow-note">
                        <span class="ftbi-auto-icon" aria-hidden="true">✓</span>
                        <div><strong>Schedule &amp; urgency</strong><span>Order Hand Date is optional. Shipment Urgency accepts Normal, Urgent or Super Urgent; blank is treated as Normal.</span></div>
                    </div>
                </div>

                <details class="ftbi-source-help">
                    <summary>Which fields are required?</summary>
                    <p><b>Client ID *</b>, <b>Client Reference Number *</b>, <b>Shipping Address *</b>, <b>Postal Code *</b> and <b>Product ID *</b> are mandatory. Order Title is generated automatically. Order Hand Date and Phone Number are optional. Previous Reference Number becomes required only when Repeat Order? is Yes.</p>
                </details>

                <details class="ftbi-test-tools">
                    <summary>Test the importer with sample data</summary>
                    <div class="ftbi-test-actions">
                        <button class="btn" type="button" id="demo">Use sample data</button>
                        <button class="btn" type="button" id="errorDemo">Preview errors</button>
                    </div>
                </details>
            </aside>
        </div>
    </section>

    <section class="card content-card hidden" id="reviewCard">
        <div class="toolbar">
            <div class="ftbi-file-summary">
                <span class="ftbi-file-icon" aria-hidden="true">XLS</span>
                <div>
                    <h2 id="fileName">order-import.xlsx</h2>
                    <div class="hint" id="fileMeta">0 rows · source fields normalized · workflow resolved automatically</div>
                </div>
            </div>
            <div class="ftbi-review-actions">
                <button class="btn ftbi-compact-action" type="button" id="changeFile">Change file</button>
                <div class="pills" aria-label="Validation summary">
                    <span class="pill" id="totalPill">0 rows</span>
                    <span class="pill ok" id="validPill">0 ready</span>
                    <span class="pill warn" id="warnPill">0 warnings</span>
                    <span class="pill err" id="errorPill">0 errors</span>
                </div>
            </div>
        </div>

        <div class="ftbi-review-heading">
            <div>
                <h3>Review rows</h3>
                <p id="reviewStatusText">Check the validation result before importing.</p>
            </div>
            <button class="btn" type="button" id="exportErrors">⇩ Download issue rows</button>
        </div>

        <div class="tablewrap">
            <table>
                <thead><tr><th>Row</th><th>Order</th><th>Client</th><th>Repeat</th><th>Product / Qty</th><th>Order hand date</th><th>Shipment urgency</th><th>Workflow</th><th>Validation</th></tr></thead>
                <tbody id="rows"></tbody>
            </table>
        </div>

        <div class="footerbar">
            <div class="checks"><span>✓ Client &amp; Product IDs validated</span><span>✓ Client Reference Number validated</span><span>✓ Required shipping details validated</span><span>✓ Order hand date normalized</span><span>✓ Shipment urgency mapped from Master Data</span></div>
            <div class="ftbi-footer-actions">
                <a class="btn ftbi-compact-action ftbi-cancel-btn" href="{{ route('orders.bulk-import') }}">Cancel</a>
                <button class="btn primary ftbi-import-btn ftbi-compact-action" type="button" id="importBtn">Import ready orders</button>
            </div>
        </div>
    </section>

    <section class="card success" id="success">
        <div class="successIcon">✓</div>
        <h2>Order import completed</h2>
        <p class="sub">Valid orders were created. Rows needing attention were not changed.</p>
        <div class="summary">
            <div class="sum"><span>Created</span><b id="created">0</b></div>
            <div class="sum"><span>Updated</span><b id="updated">0</b></div>
            <div class="sum"><span>Skipped</span><b id="skipped">0</b></div>
            <div class="sum"><span>Failed</span><b id="failed">0</b></div>
        </div>
        <div class="ftbi-import-id">Import ID <b id="importId">—</b> <span>•</span> Audit log and source fingerprint saved.</div>
        <div class="ftbi-success-actions">
            <button class="btn" type="button" id="downloadResults">Download results</button>
            <a class="btn primary" id="viewImportedOrders" href="{{ route('jobs.index') }}">View imported orders</a>
        </div>
    </section>

    <div class="loading" id="loading" role="status" aria-live="polite">
        <div class="loaderCard">
            <div class="ftbi-loader-top">
                <span class="ftbi-loader-mark" aria-hidden="true"></span>
                <div>
                    <b id="loadTitle">Validating rows…</b>
                    <div class="sub" id="loadText">Checking clients, references, products, repeat-order rules, order hand dates, shipment urgency and workflows. No orders have been created yet.</div>
                </div>
            </div>
            <div class="bar"><i id="progress"></i></div>
        </div>
    </div>
</div>
@endsection
