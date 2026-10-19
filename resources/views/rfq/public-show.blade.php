<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="light">
    <title>{{ $invitation->inquiry->inquiry_number }} · Supplier quotation</title>
    @if($brand['favicon_url'] ?? null)<link rel="icon" href="{{ $brand['favicon_url'] }}">@endif
    <script
        src="{{ asset('js/flowtrack-image-fallback.js') }}?v={{ \App\Support\FrontendBuildVersion::current() }}"
        data-fallback-src="{{ asset('images/flowtrack-image-fallback.svg') }}"
    ></script>
    @vite(['resources/theme/flowtrack/core.css', 'resources/css/app.css'])
</head>
<body class="ft-rfq-portal-page ft-rfq-step-{{ $step }}">
    <x-rfq.public.header :brand="$brand" :supplier="$invitation->supplier" :buyer-email="$buyerEmail" />

    <main class="ft-rfq-portal-shell">
        <section class="ft-rfq-portal-main">
            <div class="ft-rfq-portal-title-row">
                <div>
                    <a class="ft-rfq-portal-backlink" href="{{ route('rfq.public.show', ['token' => $token, 'step' => 'details']) }}">
                        <x-rfq.public.icon name="arrow-left" /> Quotation request
                    </a>
                    <h1>Submit your quotation</h1>
                    <p>Provide your best commercial offer for the product below.</p>
                </div>
                <div class="ft-rfq-portal-save-state" aria-live="polite">
                    <span class="ft-rfq-portal-draft-pill">{{ $submitted ? 'Submitted' : 'Draft' }}</span>
                    @if($savedAt)
                        <small><x-rfq.public.icon name="clock" /> Saved {{ $savedAt->diffForHumans() }}</small>
                    @else
                        <small><x-rfq.public.icon name="clock" /> Not saved yet</small>
                    @endif
                </div>
            </div>

            <div class="ft-rfq-portal-private-note">
                <span class="ft-rfq-portal-lock-icon"><x-rfq.public.icon name="lock" /></span>
                <span>This quotation is private and can only be accessed through your invitation link.</span>
            </div>

            @php($portalSuccess = session('success'))
            @if($portalSuccess && $step !== 'pricing' && $portalSuccess !== 'Draft saved.')
                <div class="ft-rfq-portal-feedback is-success" role="status">
                    <x-rfq.public.icon name="check" />{{ $portalSuccess }}
                </div>
            @endif
            @if($errors->any())
                <div class="ft-rfq-portal-feedback is-error" role="alert">
                    <x-rfq.public.icon name="alert" />{{ $errors->first() }}
                </div>
            @endif

            <x-rfq.public.stepper :steps="$steps" :token="$token" :locked="$locked" />

            @switch($step)
                @case('pricing')
                    <x-rfq.public.pricing
                        :invitation="$invitation"
                        :token="$token"
                        :quote="$quote"
                        :products="$products"
                        :currency="$currency"
                        :contact="$contact"
                        :rfq-reference="$rfqReference"
                        :client-name="$clientName"
                        :documents="$documents"
                        :locked="$locked"
                    />
                    @break
                @case('documents')
                    <x-rfq.public.documents
                        :invitation="$invitation"
                        :token="$token"
                        :quote="$quote"
                        :products="$products"
                        :documents="$documents"
                        :document-types="$documentTypes"
                        :required-document-types="$requiredDocumentTypes"
                        :required-document-count="$requiredDocumentCount"
                        :required-document-total="$requiredDocumentTotal"
                        :supporting-information-options="$supportingInformationOptions"
                        :supporting-information="$supportingInformation"
                        :locked="$locked"
                    />
                    @break
                @case('review')
                    <x-rfq.public.review
                        :invitation="$invitation"
                        :token="$token"
                        :quote="$quote"
                        :products="$products"
                        :documents="$documents"
                        :document-types="$documentTypes"
                        :contact="$contact"
                        :rfq-reference="$rfqReference"
                        :currency="$currency"
                        :product-subtotal="$productSubtotal"
                        :sample-cost="$sampleCost"
                        :other-costs="$otherCosts"
                        :total-quoted-value="$totalQuotedValue"
                        :client-name="$clientName"
                        :ready-to-submit="$readyToSubmit"
                        :locked="$locked"
                        :submitted="$submitted"
                        :can-revise="$canRevise"
                    />
                    @break
                @default
                    <x-rfq.public.details
                        :invitation="$invitation"
                        :token="$token"
                        :quote="$quote"
                        :products="$products"
                        :contact="$contact"
                        :rfq-reference="$rfqReference"
                        :locked="$locked"
                    />
            @endswitch
        </section>

        <aside class="ft-rfq-portal-aside">
            <x-rfq.public.summary
                :invitation="$invitation"
                :token="$token"
                :step="$step"
                :quote="$quote"
                :first-product="$firstProduct"
                :currency="$currency"
                :total-quantity="$totalQuantity"
                :product-subtotal="$productSubtotal"
                :sample-cost="$sampleCost"
                :other-costs="$otherCosts"
                :total-quoted-value="$totalQuotedValue"
                :details-complete="$detailsComplete"
                :pricing-complete="$pricingComplete"
                :documents="$documents"
                :documents-complete="$documentsComplete"
                :ready-to-submit="$readyToSubmit"
                :locked="$locked"
                :submitted="$submitted"
                :can-revise="$canRevise"
            />
        </aside>
    </main>

    <x-rfq.public.footer :brand="$brand" :buyer-email="$buyerEmail" />

    <script>
    (() => {
        const upload = document.querySelector('[data-rfq-document-input]');
        if (upload) {
            upload.addEventListener('change', () => {
                if (upload.files && upload.files.length && upload.form) {
                    upload.form.action = upload.dataset.uploadUrl || upload.form.action;
                    upload.form.submit();
                }
            });
        }

        // Every quotation upload surface supports native browse plus drag-and-drop.
        // The Documents step auto-uploads after a drop; the Pricing step keeps the
        // dropped files in its form so unsaved commercial fields are not discarded.
        document.querySelectorAll('[data-rfq-dropzone]').forEach(dropZone => {
            const fileInput = dropZone.querySelector('input[type="file"]');
            if (!fileInput || fileInput.disabled) return;

            const stopDragDefaults = event => {
                event.preventDefault();
                event.stopPropagation();
            };

            ['dragenter', 'dragover'].forEach(name => dropZone.addEventListener(name, event => {
                stopDragDefaults(event);
                dropZone.classList.add('is-dragging');
            }));

            ['dragleave', 'dragend'].forEach(name => dropZone.addEventListener(name, event => {
                stopDragDefaults(event);
                dropZone.classList.remove('is-dragging');
            }));

            dropZone.addEventListener('drop', event => {
                stopDragDefaults(event);
                dropZone.classList.remove('is-dragging');

                const droppedFiles = Array.from(event.dataTransfer?.files || []);
                if (!droppedFiles.length) return;

                const transfer = new DataTransfer();
                droppedFiles.forEach(file => transfer.items.add(file));
                fileInput.files = transfer.files;
                fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        const pricing = document.getElementById('rfq-pricing-form');
        if (pricing) {
            const currency = pricing.querySelector('[data-rfq-currency]');
            const totalNodes = document.querySelectorAll('[data-rfq-live-total]');
            const recalculate = () => {
                let subtotal = 0;
                pricing.querySelectorAll('[data-rfq-price]').forEach(input => {
                    const row = input.closest('[data-rfq-price-row]');
                    const lineSubtotal = Number(row?.dataset.quantity || 0) * Number(input.value || 0);
                    subtotal += lineSubtotal;
                    const lineNode = row?.querySelector('[data-rfq-line-subtotal]');
                    if (lineNode) {
                        const code = currency?.value || 'USD';
                        lineNode.textContent = `${code} ${lineSubtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                    }
                });
                const money = name => Number(pricing.querySelector(`[name="${name}"]`)?.value || 0);
                const sampleCost = money('sample_cost');
                const otherCosts = money('tooling_cost') + money('freight') - money('discount');
                const total = subtotal + sampleCost + otherCosts;
                const code = currency?.value || 'USD';
                totalNodes.forEach(node => node.textContent = `${code} ${total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`);
                const productSubtotalNode = document.querySelector('[data-rfq-summary-product-subtotal]');
                const sampleCostNode = document.querySelector('[data-rfq-summary-sample-cost]');
                const otherCostsNode = document.querySelector('[data-rfq-summary-other-costs]');
                if (productSubtotalNode) productSubtotalNode.textContent = `${code} ${subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                if (sampleCostNode) sampleCostNode.textContent = `${code} ${sampleCost.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                if (otherCostsNode) otherCostsNode.textContent = `${code} ${otherCosts.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                pricing.querySelectorAll('[data-rfq-currency-label]').forEach(node => { node.textContent = code; });
            };
            pricing.addEventListener('input', recalculate);
            pricing.addEventListener('change', recalculate);
            recalculate();

            const editContact = pricing.querySelector('[data-rfq-edit-contact]');
            editContact?.addEventListener('click', () => pricing.querySelector('[name="supplier_contact_name"]')?.focus());

            const addPriceBreak = pricing.querySelector('[data-rfq-add-price-break]');
            addPriceBreak?.addEventListener('click', () => {
                pricing.querySelector('[name^="moqs["]')?.focus();
            });

            const pricingFiles = pricing.querySelector('[data-rfq-pricing-file-input]');
            pricingFiles?.addEventListener('change', () => {
                const list = pricing.querySelector('.ft-rfq-prototype-uploaded-files');
                if (!list) return;
                list.querySelectorAll('[data-rfq-pending-file]').forEach(node => node.remove());
                const selectedFiles = Array.from(pricingFiles.files || []);
                const emptyState = list.querySelector('.ft-rfq-prototype-empty-file');
                if (emptyState) emptyState.hidden = selectedFiles.length > 0;
                const summaryDocument = document.querySelector('[data-rfq-summary-document]');
                if (summaryDocument) {
                    const hasDocument = Number(summaryDocument.dataset.existingDocuments || 0) > 0 || selectedFiles.length > 0;
                    summaryDocument.classList.toggle('is-complete', hasDocument);
                    const marker = summaryDocument.querySelector('span');
                    if (marker) marker.textContent = hasDocument ? '✓' : '○';
                }
                selectedFiles.forEach(file => {
                    const row = document.createElement('div');
                    row.className = 'ft-rfq-prototype-file-row is-pending-upload';
                    row.dataset.rfqPendingFile = '1';
                    const size = file.size >= 1048576
                        ? `${(file.size / 1048576).toFixed(1)} MB`
                        : `${Math.max(1, Math.ceil(file.size / 1024))} KB`;
                    row.innerHTML = `<span class="ft-rfq-file-icon">FILE</span><span class="ft-rfq-prototype-file-name"></span><span class="ft-rfq-prototype-file-size">·&nbsp; ${size}</span><span class="ft-rfq-prototype-file-ready">✓</span><span class="ft-rfq-prototype-pending-label">Ready to save</span>`;
                    row.querySelector('.ft-rfq-prototype-file-name').textContent = file.name;
                    list.appendChild(row);
                });
            });

            const confirmation = pricing.querySelector('[data-rfq-pricing-confirmation]');
            const confirmationSummary = document.querySelector('[data-rfq-summary-confirmation]');
            const syncConfirmation = () => {
                if (!confirmation || !confirmationSummary) return;
                confirmationSummary.classList.toggle('is-complete', confirmation.checked);
                confirmationSummary.classList.toggle('is-pending', !confirmation.checked);
                const marker = confirmationSummary.querySelector('span');
                if (marker) marker.textContent = confirmation.checked ? '✓' : '○';
                confirmationSummary.lastChild.textContent = confirmation.checked ? ' Confirmation completed' : ' Confirmation required';
            };
            confirmation?.addEventListener('change', syncConfirmation);
            syncConfirmation();
        }

        document.querySelectorAll('[data-rfq-toggle-requirements]').forEach(link => {
            link.addEventListener('click', event => {
                const panel = link.closest('.ft-rfq-buyer-requirements');
                if (!panel) return;

                event.preventDefault();
                const expanded = panel.classList.toggle('is-expanded');
                link.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            });
        });

        document.querySelectorAll('[data-rfq-decline]').forEach(button => button.addEventListener('click', event => {
            if (!window.confirm('Decline this request for quotation?')) event.preventDefault();
        }));
    })();
    </script>
</body>
</html>
