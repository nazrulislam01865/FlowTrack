import * as XLSX from 'xlsx';

export const bootBulkOrderImport = () => {

    const root = document.getElementById('ft-bulk-import-page');
    if (!root || root.dataset.initialized === '1') return;
    root.dataset.initialized = '1';

    const $ = (selector) => root.querySelector(selector);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const state = { token: null, review: null, results: null, loadingTimer: null, manualWorkflows: {} };

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);

    const setStep = (step) => {
        root.querySelectorAll('.step').forEach((node, index) => {
            node.classList.toggle('active', index === step - 1);
            node.classList.toggle('done', index < step - 1);
        });
    };

    const config = () => ({
        duplicate_policy: $('#duplicate')?.value || 'skip',
        manual_workflows: { ...state.manualWorkflows },
    });

    const startLoading = (title, text) => {
        const overlay = $('#loading');
        $('#loadTitle').textContent = title;
        $('#loadText').textContent = text;
        $('#progress').style.width = '9%';
        overlay.classList.add('show');
        let percent = 9;
        clearInterval(state.loadingTimer);
        state.loadingTimer = setInterval(() => {
            percent = Math.min(92, percent + Math.max(2, Math.ceil(Math.random() * 8)));
            $('#progress').style.width = percent + '%';
        }, 220);
    };

    const stopLoading = () => {
        clearInterval(state.loadingTimer);
        $('#progress').style.width = '100%';
        window.setTimeout(() => {
            $('#loading').classList.remove('show');
            $('#progress').style.width = '0%';
        }, 180);
    };

    const showError = (message) => window.alert(message || 'Something went wrong. Please try again.');

    const responseMessage = async (response) => {
        let data = {};
        try { data = await response.json(); } catch (_) {}
        if (response.status === 419) {
            const recover = document.querySelector('meta[name="flowtrack-session-recover-url"]')?.content || '/session/recover';
            window.location.replace(recover);
            throw new Error('Your session expired.');
        }
        if (!response.ok) {
            const firstValidation = data?.errors ? Object.values(data.errors).flat()[0] : null;
            throw new Error(firstValidation || data?.message || 'The request could not be completed.');
        }
        return data;
    };

    const sha256 = async (buffer) => {
        if (!window.crypto?.subtle) return null;
        const digest = await window.crypto.subtle.digest('SHA-256', buffer);
        return Array.from(new Uint8Array(digest)).map((byte) => byte.toString(16).padStart(2, '0')).join('');
    };

    const browserNormalizeExcel = async (file) => {
        const extension = file.name.split('.').pop()?.toLowerCase();
        if (!['xlsx', 'xls'].includes(extension)) return { uploadFile: file, displayFilename: null, fingerprint: null };

        const buffer = await file.arrayBuffer();
        const workbook = XLSX.read(buffer, { type: 'array', cellDates: true, cellText: false });
        if (!workbook.SheetNames?.length) throw new Error('The spreadsheet does not contain a worksheet.');
        const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
        const csv = XLSX.utils.sheet_to_csv(firstSheet, { blankrows: true, dateNF: 'yyyy-mm-dd' });
        return {
            uploadFile: new File([csv], file.name.replace(/\.(xlsx|xls)$/i, '') + '.csv', { type: 'text/csv' }),
            displayFilename: file.name,
            fingerprint: await sha256(buffer),
        };
    };

    const uploadAndValidate = async (file) => {
        if (!file) return;
        if (file.size > 20 * 1024 * 1024) return showError('File exceeds the 20 MB limit.');

        setStep(2);
        startLoading(`Preparing ${file.name}…`, 'Reading the file and validating client, reference, product, repeat-order, order hand date, shipment urgency and workflow values. Nothing has been created yet.');
        try {
            const normalized = await browserNormalizeExcel(file);
            const form = new FormData();
            form.append('file', normalized.uploadFile);
            if (normalized.displayFilename) form.append('display_filename', normalized.displayFilename);
            if (normalized.fingerprint) form.append('source_fingerprint', normalized.fingerprint);
            const current = config();
            Object.entries(current).forEach(([key, value]) => {
                if (value === null) return;
                if (key === 'manual_workflows') {
                    Object.entries(value).forEach(([row, workflowId]) => {
                        if (workflowId) form.append(`manual_workflows[${row}]`, workflowId);
                    });
                    return;
                }
                form.append(key, value);
            });

            const response = await fetch(root.dataset.validateUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: form,
                credentials: 'same-origin',
            });
            const review = await responseMessage(response);
            state.token = review.token;
            renderReview(review);
        } catch (error) {
            setStep(1);
            showError(error.message);
        } finally {
            stopLoading();
        }
    };

    const revalidate = async () => {
        if (!state.token) return;
        const current = config();
        startLoading('Revalidating rows…', 'Applying the workflow selection. No orders have been changed yet.');
        try {
            const response = await fetch(root.dataset.revalidateUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ token: state.token, ...current }),
                credentials: 'same-origin',
            });
            renderReview(await responseMessage(response));
        } catch (error) {
            showError(error.message);
        } finally {
            stopLoading();
        }
    };

    const workflowCell = (row) => {
        if (row.workflow_selection_source === 'client') {
            return `<div class="ftbi-workflow-auto"><span class="status" style="background:var(--ftbi-blue2);color:var(--ftbi-blue)">${escapeHtml(row.workflow_resolved_label || '—')}</span><small>Client workflow</small></div>`;
        }

        if (row.workflow_selection_source === 'manual' && !row.workflow_requires_selection) {
            return `<div class="ftbi-workflow-manual"><select class="ftbi-row-workflow" data-row="${escapeHtml(row.row)}" aria-label="Workflow for row ${escapeHtml(row.row)}">${workflowOptions(row, row.workflow_resolved_id)}</select><small>Selected manually</small></div>`;
        }

        if (Array.isArray(row.workflow_options) && row.workflow_options.length) {
            return `<div class="ftbi-workflow-manual"><select class="ftbi-row-workflow needs-selection" data-row="${escapeHtml(row.row)}" aria-label="Select workflow for row ${escapeHtml(row.row)}"><option value="">Select Order workflow…</option>${workflowOptions(row, row.workflow_manual_selected_id)}</select><small>Required because this client has no client-specific Order workflow</small></div>`;
        }

        return `<span class="status" style="background:var(--ftbi-redbg);color:var(--ftbi-red)">${escapeHtml(row.workflow_resolved_label || 'Not available')}</span>`;
    };

    const workflowOptions = (row, selectedId = null) => (row.workflow_options || []).map((workflow) => {
        const selected = Number(selectedId || 0) === Number(workflow.id) ? ' selected' : '';
        const suffix = workflow.client_specific ? ' · Client' : (workflow.is_default ? ' · Default' : '');
        return `<option value="${escapeHtml(workflow.id)}"${selected}>${escapeHtml(workflow.name + suffix)}</option>`;
    }).join('');

    const renderReview = (review) => {
        state.review = review;
        $('#uploadCard').classList.add('hidden');
        $('#success').classList.remove('show');
        $('#reviewCard').classList.remove('hidden');
        const manualCount = Number(review.counts.workflow_selection_required || 0);
        $('#fileName').textContent = review.filename;
        $('#fileMeta').textContent = manualCount
            ? `${review.counts.total} rows · client-specific Order workflows applied · ${manualCount} manual ${manualCount === 1 ? 'selection' : 'selections'} needed`
            : `${review.counts.total} rows · clients validated · Order workflows resolved`; 
        $('#totalPill').textContent = `${review.counts.total} rows`;
        $('#validPill').textContent = `${review.counts.ready} ready`;
        $('#warnPill').textContent = `${review.counts.warnings} warnings`;
        $('#errorPill').textContent = `${review.counts.errors} errors`;
        const issueCount = review.rows.filter((row) => row.errors.length || row.warnings.length).length;
        const nonErrorCount = review.rows.filter((row) => !row.errors.length).length;
        $('#importBtn').disabled = review.counts.total === 0 || review.counts.importable === 0 && review.counts.skippable === 0;
        $('#importBtn').textContent = nonErrorCount > 0 ? `Import ${nonErrorCount} ready ${nonErrorCount === 1 ? 'order' : 'orders'}` : 'No orders ready';
        $('#exportErrors').disabled = issueCount === 0;
        $('#reviewStatusText').textContent = issueCount
            ? `${issueCount} ${issueCount === 1 ? 'row needs' : 'rows need'} attention. Error rows will not be imported.`
            : `All ${review.counts.total} ${review.counts.total === 1 ? 'row is' : 'rows are'} ready to import.`;
        setStep(3);

        $('#rows').innerHTML = review.rows.map((row) => {
            const rowClass = row.errors.length ? 'errorRow' : row.warnings.length ? 'warnRow' : '';
            const issue = row.errors.length
                ? `<div class="rowmsg">${escapeHtml(row.errors.join(' · '))}</div>`
                : row.warnings.length
                    ? `<div class="rowmsg warn">${escapeHtml(row.warnings.join(' · '))}</div>`
                    : '';
            const status = row.errors.length
                ? '<span class="status" style="background:var(--ftbi-redbg);color:var(--ftbi-red)">Error</span>'
                : row.warnings.length
                    ? '<span class="status" style="background:var(--ftbi-amberbg);color:var(--ftbi-amber)">Review</span>'
                    : '<span class="status" style="background:var(--ftbi-greenbg);color:var(--ftbi-green)">Ready</span>';
            const repeat = row.is_repeat === 'Yes'
                ? `Yes${row.repeat_order_no ? ` · ${escapeHtml(row.repeat_order_no)}` : ''}`
                : 'No';
            const product = row.product_resolved_name
                ? `<b>${escapeHtml(row.product_resolved_name)}</b><br><span class="sub">${escapeHtml(row.product_id || '')} · Qty ${escapeHtml(row.product_quantity_resolved || 1)}</span>`
                : '<span class="sub">No product</span>';
            const delivery = `<b>${escapeHtml(row.customer_delivery_normalized || '—')}</b>`;
            const urgency = `<b>${escapeHtml(row.shipment_urgency_resolved || 'Normal')}</b>`;
            return `<tr class="${rowClass}">
                <td>${escapeHtml(row.row)}</td>
                <td><b>${escapeHtml(row.title || '—')}</b><br><span class="sub">Client ref: ${escapeHtml(row.ref || '—')}</span></td>
                <td>${escapeHtml(row.client_resolved_label || 'Unresolved')}</td>
                <td>${repeat}</td>
                <td>${product}</td>
                <td>${delivery}</td>
                <td>${urgency}</td>
                <td>${workflowCell(row)}</td>
                <td>${status}${issue}</td>
            </tr>`;
        }).join('');
    };

    const importOrders = async () => {
        if (!state.token || !state.review) return;
        const nonError = state.review.rows.filter((row) => !row.errors.length).length;
        if (!nonError) return showError('No valid orders are ready to import.');

        setStep(4);
        startLoading(`Importing ${nonError} ready orders…`, 'Creating valid orders with each client-specific or manually selected Order workflow. Rows with validation errors are left unchanged.');
        try {
            const response = await fetch(root.dataset.importUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ token: state.token, ...config() }),
                credentials: 'same-origin',
            });
            const result = await responseMessage(response);
            state.results = result;
            $('#reviewCard').classList.add('hidden');
            $('#success').classList.add('show');
            $('#created').textContent = result.counts.created;
            $('#updated').textContent = result.counts.updated;
            $('#skipped').textContent = result.counts.skipped;
            $('#failed').textContent = result.counts.failed;
            $('#importId').textContent = result.import_number;
            const importedOrdersLink = $('#viewImportedOrders');
            if (importedOrdersLink && result.view_orders_url) {
                importedOrdersLink.href = result.view_orders_url;
            }
            setStep(4);
        } catch (error) {
            setStep(3);
            showError(error.message);
        } finally {
            stopLoading();
        }
    };

    const resetImport = () => {
        clearInterval(state.loadingTimer);
        state.token = null;
        state.review = null;
        state.results = null;
        state.manualWorkflows = {};
        $('#reviewCard').classList.add('hidden');
        $('#success').classList.remove('show');
        $('#uploadCard').classList.remove('hidden');
        $('#rows').innerHTML = '';
        $('#bulkOrderFile').value = '';
        $('#exportErrors').disabled = false;
        $('#importBtn').disabled = false;
        $('#importBtn').textContent = 'Import ready orders';
        setStep(1);
        root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const csvCell = (value) => {
        let text = String(value ?? '');
        if (/^[=+\-@]/.test(text)) text = "'" + text;
        return `"${text.replaceAll('"', '""')}"`;
    };
    const download = (name, text, type = 'text/csv;charset=utf-8') => {
        const anchor = document.createElement('a');
        anchor.href = URL.createObjectURL(new Blob(['\ufeff', text], { type }));
        anchor.download = name;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(anchor.href);
    };

    const exportIssues = () => {
        if (!state.review) return;
        const rows = state.review.rows.filter((row) => row.errors.length || row.warnings.length);
        const csv = [
            ['Row', 'Client Reference Number', 'Issue'],
            ...rows.map((row) => [row.row, row.ref, [...row.errors, ...row.warnings].join('; ')]),
        ].map((row) => row.map(csvCell).join(',')).join('\r\n');
        download('FlowTrack_import_issues.csv', csv);
    };

    const exportResults = () => {
        if (!state.results) return;
        const csv = [
            ['Import ID', 'Row', 'Client Reference Number', 'Status', 'Message'],
            ...state.results.results.map((row) => [state.results.import_number, row.row, row.reference, row.status, row.message]),
        ].map((row) => row.map(csvCell).join(',')).join('\r\n');
        download(`${state.results.import_number}_results.csv`, csv);
    };

    const demoRows = (withError = false) => {
        const rows = [
            ['CL-011', 'FO-333119', 'No', '', 'Conference merchandise for August event.', '123 Main Street, Los Angeles, CA, USA', '+1 213 555 0198', '90012', 'PRD-000123', '250', '2026-08-25', 'Urgent', 'Confirm packing before dispatch.'],
            ['CL-014', 'FO-333120', 'Yes', 'FO-332940', 'Repeat the previous approved design.', '88 Market Road, Toronto, ON, Canada', '', 'M5V 2T6', 'PRD-000124', '100', '', 'Normal', 'Keep colors identical to prior order.'],
        ];
        if (withError) rows.push(['', '', 'Yes', '', '', '', '+999 12345', '', '', 'abc', '2026-08-40', 'Extreme', 'This row intentionally demonstrates validation.']);
        const header = ['Client ID *', 'Client Reference Number *', 'Repeat Order? Yes / No', 'Previous Reference Number', 'Request Description', 'Shipping Address *', 'Phone Number (with country code)', 'Postal Code *', 'Product ID *', 'Product Quantity', 'Order Hand Date', 'Shipment Urgency', 'Notes'];
        const csv = [header, ...rows].map((row) => row.map(csvCell).join(',')).join('\r\n');
        return new File([csv], withError ? 'Order-import-with-errors.csv' : 'FlowTrack-bulk-order-sample.csv', { type: 'text/csv' });
    };

    $('#rows').addEventListener('change', (event) => {
        const select = event.target.closest('.ftbi-row-workflow');
        if (!select) return;
        const row = String(select.dataset.row || '');
        if (!row) return;
        if (select.value) state.manualWorkflows[row] = Number(select.value);
        else delete state.manualWorkflows[row];
        revalidate();
    });

    $('#bulkOrderFile').addEventListener('change', (event) => uploadAndValidate(event.target.files?.[0]));
    $('#drop').addEventListener('click', (event) => {
        if (event.target.closest('label')) return;
        $('#bulkOrderFile').click();
    });
    $('#drop').addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        $('#bulkOrderFile').click();
    });
    ['dragenter', 'dragover'].forEach((eventName) => $('#drop').addEventListener(eventName, (event) => {
        event.preventDefault();
        $('#drop').classList.add('drag');
    }));
    ['dragleave', 'drop'].forEach((eventName) => $('#drop').addEventListener(eventName, (event) => {
        event.preventDefault();
        $('#drop').classList.remove('drag');
    }));
    $('#drop').addEventListener('drop', (event) => uploadAndValidate(event.dataTransfer?.files?.[0]));
    $('#changeFile').addEventListener('click', resetImport);
    $('#demo').addEventListener('click', () => uploadAndValidate(demoRows(false)));
    $('#errorDemo').addEventListener('click', () => uploadAndValidate(demoRows(true)));
    $('#importBtn').addEventListener('click', importOrders);
    $('#exportErrors').addEventListener('click', exportIssues);
    $('#downloadResults').addEventListener('click', exportResults);
};
