@props(['job'])
@php
    // Generated tracking output is available only after Artwork confirmation.
    // The relation is eager-loaded by the Order detail shell; no query is made here.
    $trackingPdf = $job->relationLoaded('artworkTrackingPdf') ? $job->artworkTrackingPdf : null;
@endphp

@if($trackingPdf)
    @php
        $rawOrderNumber = $job->job_number ?: $job->order_number ?: ('FO-' . $job->id);
        $refNumber = $job->reference_number ?: $rawOrderNumber;
        $trackingUrl = $job->trackingUrl();
        $qrSvg = app(\App\Services\QrCodeService::class)->renderSvg($trackingUrl, 112);
    @endphp

    <section
        class="section-card ft-order-section-card ft-order-tracking-card"
        x-data="{
            copiedLink: false,
            copyFailed: false,
            async copyTrackingLink() {
                const text = @js($trackingUrl);
                let copied = false;

                if (navigator.clipboard?.writeText) {
                    try {
                        await navigator.clipboard.writeText(text);
                        copied = true;
                    } catch (_) {
                        // Clipboard permissions can be denied even on HTTPS.
                    }
                }

                if (!copied) {
                    // HTTP cloud hosts do not expose the modern Clipboard API.
                    const input = document.createElement('textarea');
                    input.value = text;
                    input.readOnly = true;
                    input.style.position = 'fixed';
                    input.style.left = '-9999px';
                    const previousFocus = document.activeElement;
                    document.body.appendChild(input);
                    input.focus();
                    input.select();
                    try {
                        copied = document.execCommand('copy');
                    } catch (_) {
                        copied = false;
                    } finally {
                        input.remove();
                        previousFocus?.focus?.();
                    }
                }

                this.copiedLink = copied;
                this.copyFailed = !copied;
                if (!copied) {
                    window.prompt('Copy tracking link:', text);
                }
                setTimeout(() => {
                    this.copiedLink = false;
                    this.copyFailed = false;
                }, 1800);
            }
        }"
    >
        <div class="section-head ft-order-section-head ft-order-tracking-head">
            <div class="ft-order-tracking-heading">
                <span class="ft-order-tracking-heading-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><path d="M14 14h3v3h-3zM19 14h2v2M19 19h2v2M14 19h2v2"></path></svg>
                </span>
                <div class="ft-order-tracking-heading-text">
                    <h2>Order tracking</h2>
                    <p>Live tracking QR and confirmed artwork</p>
                </div>
            </div>
            <span class="ft-tracking-ready-badge"><span aria-hidden="true">✓</span> Artwork confirmed</span>
        </div>

        <div class="ft-order-tracking-content">
            <div class="ft-order-tracking-qr-column">
                <div class="ft-qr-box" aria-label="Order tracking QR code">
                    {!! $qrSvg !!}
                </div>
                <span class="ft-order-tracking-scan-label">Scan for live status</span>
            </div>

            <div class="ft-tracking-copy">
                <h3>Tracking is ready</h3>
                <p>Scan the QR or open tracking to see the latest status for this order.</p>

                <dl class="ft-tracking-meta">
                    <div><dt>Order</dt><dd>{{ $rawOrderNumber }}</dd></div>
                    <div><dt>Reference</dt><dd>{{ $refNumber }}</dd></div>
                    <div><dt>Artwork PDF</dt><dd>V{{ max(1, (int) $trackingPdf->version) }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="ft-tracking-actions">
            <a href="{{ $trackingUrl }}" target="_blank" rel="noopener noreferrer" class="ft-btn-tracking-primary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3h7v7"></path><path d="M10 14 21 3"></path><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"></path></svg>
                <span>Open tracking</span>
            </a>

            <a href="{{ route('documents.open', $trackingPdf) }}" target="_blank" rel="noopener noreferrer" class="ft-btn-tracking-secondary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                <span>View PDF</span>
            </a>

            <a href="{{ route('orders.qr.download', $job->id) }}" class="ft-btn-tracking-secondary">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                <span>Download PDF</span>
            </a>

            <button type="button" class="ft-btn-tracking-link" x-on:click="copyTrackingLink()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                <span aria-live="polite" x-text="copiedLink ? 'Link copied' : (copyFailed ? 'Copy manually' : 'Copy tracking link')">Copy tracking link</span>
            </button>
        </div>
    </section>

    <style>
        .ft-order-tracking-card {
            background: #fff;
            border: 1px solid #dbe5ee;
            border-radius: 14px;
            overflow: hidden;
            container-type: inline-size;
        }
        .ft-order-tracking-card .ft-order-tracking-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) max-content;
            align-items: center;
            gap: 12px 18px;
            margin-bottom: 0;
            padding-bottom: 13px;
        }
        .ft-order-tracking-heading {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .ft-order-tracking-heading-text {
            min-width: 0;
            flex: 1 1 auto;
        }
        .ft-order-tracking-heading-icon {
            flex: 0 0 34px;
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #eaf8f5;
            color: #008a73;
        }
        .ft-order-tracking-heading h2 {
            margin: 0;
            line-height: 1.25;
        }
        .ft-order-tracking-heading p {
            margin: 2px 0 0;
            color: #718096;
            font-size: 11px;
            line-height: 1.35;
        }
        .ft-tracking-ready-badge {
            justify-self: end;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-height: 24px;
            padding: 3px 9px;
            border-radius: 999px;
            background: #e6f7f3;
            color: #007a64;
            font-size: 10.5px;
            font-weight: 750;
            white-space: nowrap;
        }
        .ft-order-tracking-content {
            display: grid;
            grid-template-columns: 112px minmax(0, 1fr);
            gap: 16px;
            align-items: center;
            padding: 16px 18px;
            border-top: 1px solid #edf2f7;
            border-bottom: 1px solid #edf2f7;
            background: #fbfdfd;
        }
        .ft-order-tracking-qr-column {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 7px;
        }
        .ft-qr-box {
            width: 112px;
            height: 112px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
            background: #fff;
            border: 1px solid #d7e2eb;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .05);
        }
        .ft-qr-box svg { display: block; width: 100%; height: 100%; }
        .ft-order-tracking-scan-label {
            color: #64748b;
            font-size: 10px;
            font-weight: 650;
            text-align: center;
        }
        .ft-tracking-copy { min-width: 0; }
        .ft-tracking-copy h3 {
            margin: 0 0 4px;
            color: #14213a;
            font-size: 14px;
            font-weight: 750;
        }
        .ft-tracking-copy > p {
            margin: 0 0 11px;
            color: #64748b;
            font-size: 11px;
            line-height: 1.45;
        }
        .ft-tracking-meta {
            display: grid;
            gap: 5px;
            margin: 0;
        }
        .ft-tracking-meta > div {
            min-width: 0;
            display: grid;
            grid-template-columns: 68px minmax(0, 1fr);
            gap: 8px;
            align-items: start;
            font-size: 11px;
        }
        .ft-tracking-meta dt { color: #718096; }
        .ft-tracking-meta dd {
            min-width: 0;
            margin: 0;
            color: #1e293b;
            font-weight: 700;
            text-align: right;
            overflow-wrap: anywhere;
        }
        .ft-tracking-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            padding: 14px 18px 16px;
        }
        .ft-btn-tracking-primary,
        .ft-btn-tracking-secondary,
        .ft-btn-tracking-link {
            min-width: 0;
            min-height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 10px;
            border-radius: 8px;
            font: inherit;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.2;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
        }
        .ft-btn-tracking-primary {
            border: 1px solid #008a73;
            background: #008a73;
            color: #fff !important;
        }
        .ft-btn-tracking-secondary {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155 !important;
        }
        .ft-btn-tracking-link {
            grid-column: 1 / -1;
            min-height: 28px;
            border: 0;
            background: transparent;
            color: #087f6c;
        }
        .ft-btn-tracking-primary:hover { background: #007a66; }
        .ft-btn-tracking-secondary:hover { border-color: #94a3b8; background: #f8fafc; }
        .ft-btn-tracking-link:hover { background: #f0fdfa; }
        @container (max-width: 700px) {
            .ft-order-tracking-card .ft-order-tracking-head {
                grid-template-columns: minmax(0, 1fr);
                gap: 10px;
            }
            .ft-order-tracking-card .ft-tracking-ready-badge {
                justify-self: start;
                margin-left: 46px;
            }
        }
        @container (max-width: 340px) {
            .ft-order-tracking-card .ft-tracking-ready-badge { margin-left: 0; }
        }
        @media (max-width: 520px) {
            .ft-order-tracking-content { grid-template-columns: 1fr; }
            .ft-qr-box { margin: 0 auto; }
            .ft-tracking-meta > div { grid-template-columns: 82px minmax(0, 1fr); }
            .ft-tracking-actions { grid-template-columns: 1fr; }
            .ft-btn-tracking-link { grid-column: auto; }
        }
    </style>
@endif
