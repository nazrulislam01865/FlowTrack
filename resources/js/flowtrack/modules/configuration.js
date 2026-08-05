function masterSection(category = selectedMasterCategory) {
        return masterCatalog.find((section) => section.key === category) || null;
    }

    function currentMasterType() {
        return masterSection()?.type || null;
    }

    function currentMasterLabel(category = selectedMasterCategory) {
        return masterSection(category)?.label || masterLabels[category] || 'Master Data';
    }

    const masterPaginatedSections = new Set(['productCategories', 'products', 'documentCategories', 'departments']);
    let masterListPage = 1;
    let masterListPageSize = 10;
    let masterListQuery = '';

    function masterSectionUsesPagination(category = selectedMasterCategory) {
        return masterPaginatedSections.has(category);
    }

    function filteredMasterRows(rows, query = masterListQuery) {
        const normalized = String(query || '').trim().toLocaleLowerCase();
        if (!normalized) return rows.slice();
        return rows.filter((record) => `${record.code || ''} ${record.name || ''} ${masterParentName(record)} ${record.detail || record.description || ''}`.toLocaleLowerCase().includes(normalized));
    }

    function masterPaginationState(rows) {
        if (!masterSectionUsesPagination()) {
            return { items: rows, total: rows.length, page: 1, pageCount: 1, start: 0, end: rows.length };
        }
        const total = rows.length;
        const pageCount = Math.max(1, Math.ceil(total / masterListPageSize));
        masterListPage = Math.max(1, Math.min(masterListPage, pageCount));
        const start = (masterListPage - 1) * masterListPageSize;
        const end = Math.min(total, start + masterListPageSize);
        return { items: rows.slice(start, end), total, page: masterListPage, pageCount, start, end };
    }

    function masterPaginationHtml(pagination) {
        if (!masterSectionUsesPagination()) return '';
        const showingStart = pagination.total ? pagination.start + 1 : 0;
        return `<div class="jobs-v2-pagination master-data-pagination" id="masterPagination">
            <div class="jobs-v2-page-count">Showing <b>${showingStart}</b>–<b>${pagination.end}</b> of <b>${pagination.total}</b> Records</div>
            <label class="jobs-v2-page-size">Show <select id="masterPageSize" aria-label="Master Data records per page"><option value="10" ${masterListPageSize === 10 ? 'selected' : ''}>10</option><option value="20" ${masterListPageSize === 20 ? 'selected' : ''}>20</option><option value="30" ${masterListPageSize === 30 ? 'selected' : ''}>30</option><option value="50" ${masterListPageSize === 50 ? 'selected' : ''}>50</option></select> per page</label>
            <div class="jobs-v2-page-buttons"><button type="button" id="masterPrevPage" ${pagination.page <= 1 ? 'disabled' : ''}>Previous</button><span>Page ${pagination.page} of ${pagination.pageCount}</span><button type="button" id="masterNextPage" ${pagination.page >= pagination.pageCount ? 'disabled' : ''}>Next</button></div>
        </div>`;
    }

    function renderMasterRowsPage(rows = masterData[selectedMasterCategory] || []) {
        const filtered = filteredMasterRows(rows);
        const pagination = masterPaginationState(filtered);
        const body = $('#masterRows');
        if (body) body.innerHTML = masterRowsHtmlDynamic(pagination.items);
        const paginationHost = $('#masterPaginationHost');
        if (paginationHost) paginationHost.innerHTML = masterPaginationHtml(pagination);

        bindDynamicMasterRows();
        applyActionPermissions();

        $('#masterPageSize')?.addEventListener('change', (event) => {
            masterListPageSize = Math.max(10, Number(event.target.value) || 10);
            masterListPage = 1;
            renderMasterRowsPage(rows);
        });
        $('#masterPrevPage')?.addEventListener('click', () => {
            if (masterListPage <= 1) return;
            masterListPage -= 1;
            renderMasterRowsPage(rows);
        });
        $('#masterNextPage')?.addEventListener('click', () => {
            if (masterListPage >= pagination.pageCount) return;
            masterListPage += 1;
            renderMasterRowsPage(rows);
        });
    }

    function masterParentName(record, category = selectedMasterCategory) {
        const section = masterSection(category);
        if (!section?.parentKey) return '';
        if (record.parent_name || record.parentName) return record.parent_name || record.parentName;
        const parent = (masterData[section.parentKey] || []).find((item) => Number(item.id) === Number(record.parent_id));
        return parent?.name || '';
    }

    function masterRecordPayload() {
        const section = masterSection();
        return {
            type: section?.type,
            parent_id: section?.parentKey && $('#masterParent')?.value ? Number($('#masterParent').value) : null,
            name: $('#masterName').value.trim().replace(/\s+/g, ' '),
            description: $('#masterDetail').value.trim() || null,
            status: $('#masterStatus').value.toLowerCase(),
            sort_order: Number($('#masterSortOrder').value || 0),
            color: $('#masterColorCode')?.value ? flowtrackNormalizeHexColor($('#masterColorCode').value) : null,
        };
    }

    function nextMasterSortOrder(category = selectedMasterCategory) {
        const rows = masterData[category] || [];
        const maximum = rows.reduce((value, record) => Math.max(value, Number(record.sort_order || 0)), 0);
        return Math.max(1, maximum + 1);
    }

    function masterRowsHtmlDynamic(rows) {
        const section = masterSection();
        const hasParent = Boolean(section?.parentKey);
        const hasDocumentOrder = section?.type === 'document_category';
        const hasColorCode = ['priority', 'task_status'].includes(section?.type);
        const colspan = 5 + (hasParent ? 1 : 0) + (hasDocumentOrder ? 1 : 0) + (hasColorCode ? 1 : 0);
        return rows.map((record) => {
            const parentCell = hasParent ? `<td data-label="Parent">${html(masterParentName(record) || '—')}</td>` : '';
            const orderCell = hasDocumentOrder ? `<td data-label="Sort Order"><b>${Number(record.sort_order || 0)}</b></td>` : '';
            const resolvedColor = hasColorCode
                ? (section.type === 'task_status' ? flowtrackTaskStatusColor(record) : flowtrackPriorityColor(record))
                : '';
            const colorCell = hasColorCode ? `<td data-label="Color"><span style="display:inline-flex;align-items:center;gap:8px;font-weight:650"><i aria-hidden="true" style="display:inline-block;width:16px;height:16px;border-radius:50%;background:${html(resolvedColor)};box-shadow:0 0 0 1px rgba(15,23,42,.12)"></i><code style="font:inherit;font-size:11px;color:#52637b">${html(resolvedColor)}</code></span></td>` : '';
            return `<tr><td data-label="Code"><b>${html(record.code)}</b></td><td data-label="Name">${html(record.name)}</td>${colorCell}${parentCell}<td data-label="Description / Use">${html(record.detail || record.description || '')}</td>${orderCell}<td data-label="Status"><span class="badge ${String(record.status).toLowerCase() === 'active' ? 'b-green' : 'b-gray'}">${html(record.status)}</span></td><td data-label="Actions"><div class="row-actions"><button class="mini-btn" data-master-edit="${record.id}">Edit</button><button class="mini-btn" data-master-toggle="${record.id}">${String(record.status).toLowerCase() === 'active' ? 'Deactivate' : 'Activate'}</button><button class="mini-btn danger-btn" data-master-delete="${record.id}">Delete</button></div></td></tr>`;
        }).join('') || `<tr><td colspan="${colspan}"><div class="empty-state">No records found.</div></td></tr>`;
    }

    function bindDynamicMasterRows() {
        $$('[data-master-edit]').forEach((button) => button.addEventListener('click', () => {
            const record = (masterData[selectedMasterCategory] || []).find((item) => Number(item.id) === Number(button.dataset.masterEdit));
            if (record) openMasterModal(selectedMasterCategory, record);
        }));
        $$('[data-master-toggle]').forEach((button) => button.addEventListener('click', async () => {
            const record = (masterData[selectedMasterCategory] || []).find((item) => Number(item.id) === Number(button.dataset.masterToggle));
            if (!record) return;
            button.disabled = true;
            try {
                const result = await api(`/flowtrack/template-api/master-data/${record.id}/toggle`, { method: 'PATCH' });
                const index = masterData[selectedMasterCategory].findIndex((item) => Number(item.id) === Number(record.id));
                if (index >= 0) masterData[selectedMasterCategory].splice(index, 1, result.record);
                renderMasterData();
                showToast(`${result.record.name} is now ${result.record.status}.`);
            } catch (error) {
                button.disabled = false;
                showToast(error.message);
            }
        }));
        $$('[data-master-delete]').forEach((button) => button.addEventListener('click', async () => {
            const record = (masterData[selectedMasterCategory] || []).find((item) => Number(item.id) === Number(button.dataset.masterDelete));
            if (!record) return;
            if (!window.confirm(`Delete ${record.code} · ${record.name}? Existing Jobs, tasks, users, workflows and documents will be preserved, but this master-data reference will be removed.`)) return;
            button.disabled = true;
            try {
                const result = await api(`/flowtrack/template-api/master-data/${record.id}`, { method: 'DELETE' });
                await loadBootstrap();
                renderMasterData();
                showToast(result.message || 'Master-data record deleted.');
            } catch (error) {
                button.disabled = false;
                showToast(error.message);
            }
        }));
    }

    renderMasterData = function () {
        if (!masterCatalog.length) {
            $('#page-masterdata').innerHTML = '<div class="card empty-state">No Master Data sections are available for this workspace.</div>';
            return;
        }
        if (!masterCatalog.some((section) => section.key === selectedMasterCategory)) {
            selectedMasterCategory = masterCatalog[0].key;
        }
        const rows = masterData[selectedMasterCategory] || [];
        const label = currentMasterLabel();
        const section = masterSection();
        const parentHeader = section?.parentKey ? '<th>Parent</th>' : '';
        const orderHeader = section?.type === 'document_category' ? '<th>Sort Order</th>' : '';
        const colorHeader = ['priority', 'task_status'].includes(section?.type) ? '<th>Color</th>' : '';
        const allRows = masterCatalog.flatMap((item) => masterData[item.key] || []);
        const initialFilteredRows = filteredMasterRows(rows);
        const initialPagination = masterPaginationState(initialFilteredRows);
        $('#page-masterdata').innerHTML = `<div class="page-head"><div><h1>Master Data</h1><p>Maintain the values used in Jobs, tasks, documents, production and shipment forms</p></div><div class="actions"><button class="ghost" id="masterImport">Import</button><button class="primary" id="addMasterRecord">＋ Add Record</button></div></div>
        <div class="config-summary"><div class="card config-stat"><span>Master Categories</span><b>${masterCatalog.length}</b></div><div class="card config-stat"><span>Total Records</span><b>${allRows.length}</b></div><div class="card config-stat"><span>Active Records</span><b>${allRows.filter((item) => String(item.status).toLowerCase() === 'active').length}</b></div><div class="card config-stat"><span>Selected Category</span><b style="font-size:15px">${html(label)}</b></div></div>
        <div class="master-layout"><div class="card master-categories">${masterCatalog.map((item) => `<button class="${item.key === selectedMasterCategory ? 'active' : ''}" data-master-category="${html(item.key)}"><b>${html(item.label)}</b><small>${(masterData[item.key] || []).length} records</small></button>`).join('')}</div><div><div class="card section-card"><div class="master-head"><div><h3 style="margin:0 0 4px">${html(label)}</h3><div class="small muted">All records in this section are loaded from the database. Codes are generated automatically and cannot be changed.</div></div><input id="masterSearch" value="${html(masterListQuery)}" style="max-width:260px;border:1px solid var(--line);border-radius:8px;padding:9px" placeholder="Search records…"></div><div class="table-wrap"><table class="master-table"><thead><tr><th>Code</th><th>Name</th>${colorHeader}${parentHeader}<th>Description / Use</th>${orderHeader}<th>Status</th><th>Actions</th></tr></thead><tbody id="masterRows">${masterRowsHtmlDynamic(initialPagination.items)}</tbody></table></div><div id="masterPaginationHost">${masterPaginationHtml(initialPagination)}</div></div></div></div>`;

        $$('[data-master-category]').forEach((button) => button.addEventListener('click', () => {
            selectedMasterCategory = button.dataset.masterCategory;
            masterListPage = 1;
            masterListQuery = '';
            renderMasterData();
        }));
        $('#addMasterRecord')?.addEventListener('click', () => openMasterModal(selectedMasterCategory));
        $('#masterImport')?.addEventListener('click', () => showToast('Excel/CSV import will use the database section and automatic code sequence.'));
        $('#masterSearch')?.addEventListener('input', (event) => {
            masterListQuery = event.target.value;
            masterListPage = 1;
            renderMasterRowsPage(rows);
        });
        renderMasterRowsPage(rows);
    };

    openMasterModal = async function (category, existing = null) {
        const section = masterSection(category);
        const type = section?.type;
        if (!section || !type) { showToast('This Master Data section is not available.'); return; }
        let code = existing?.code || 'Generating…';
        if (!existing) {
            try {
                const preview = await api(`/flowtrack/template-api/master-data/next-code?type=${encodeURIComponent(type)}`);
                code = preview.code;
            } catch (error) {
                showToast(error.message);
                return;
            }
        }

        const parentRecords = section.parentKey ? (masterData[section.parentKey] || []).filter((record) => String(record.status).toLowerCase() === 'active') : [];
        const isDocumentCategory = section.type === 'document_category';
        const hasColorCode = ['priority', 'task_status'].includes(section.type);
        const defaultColor = section.type === 'task_status'
            ? flowtrackTaskStatusColor(existing || (existing?.name || 'Ready'))
            : flowtrackPriorityColor(existing || (existing?.name || 'Medium'));
        const colorValue = flowtrackNormalizeHexColor(existing?.color, defaultColor);
        const sortOrderValue = existing ? Number(existing.sort_order || 0) : nextMasterSortOrder(category);
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${existing ? 'Edit' : 'Add'} ${html(section.label)} Record</h2><div class="small muted" style="margin-top:5px">The code follows this section's automatic sequence and is permanently locked.</div></div><button class="close-btn" data-close>×</button></div>
        <div class="modal-body"><div class="form-grid">
            <div class="field"><label>Code</label><input id="masterCode" value="${html(code)}" readonly aria-readonly="true"><div class="inline-help">Automatically generated. This field cannot be edited.</div></div>
            <div class="field"><label>Status</label><select id="masterStatus"><option value="active" ${String(existing?.status || 'Active').toLowerCase() === 'active' ? 'selected' : ''}>Active</option><option value="inactive" ${String(existing?.status || '').toLowerCase() === 'inactive' ? 'selected' : ''}>Inactive</option></select></div>
            <div class="field full"><label>Name *</label><input id="masterName" value="${html(existing?.name)}" placeholder="Enter the record name"></div>
            ${hasColorCode ? `<div class="field full"><label>Color code *</label><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><input id="masterColorPicker" type="color" value="${html(colorValue)}" aria-label="Choose color" style="width:52px;height:42px;padding:4px;cursor:pointer"><input id="masterColorCode" value="${html(colorValue)}" maxlength="7" placeholder="#2563EB" spellcheck="false" style="min-width:160px;flex:1 1 180px"><span class="inline-help" style="margin:0;flex:1 0 100%">This color is used for this ${section.type === 'task_status' ? 'Task Status' : 'Priority'} across the system.</span></div></div>` : ''}
            ${section.parentKey ? `<div class="field full"><label>${html(masterLabels[section.parentKey] || 'Parent')}</label><select id="masterParent" data-searchable data-search-placeholder="Search parent…"><option value="">No parent</option>${parentRecords.map((parent) => `<option value="${parent.id}" ${Number(existing?.parent_id) === Number(parent.id) ? 'selected' : ''}>${html(parent.code)} · ${html(parent.name)}</option>`).join('')}</select></div>` : ''}
            <div class="field full"><label>Description / Use</label><textarea id="masterDetail" rows="3">${html(existing?.detail || existing?.description)}</textarea></div>
            <div class="field"><label>${isDocumentCategory ? 'Order *' : 'Display order'}</label><input id="masterSortOrder" type="number" min="${isDocumentCategory ? 1 : 0}" value="${sortOrderValue}">${isDocumentCategory ? '<div class="inline-help">Controls the document-category sequence across document and workflow selectors. Each order must be unique.</div>' : ''}</div>
        </div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveMasterRecord">Save Record</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        if (hasColorCode) {
            const picker = $('#masterColorPicker');
            const codeInput = $('#masterColorCode');
            picker?.addEventListener('input', () => { if (codeInput) codeInput.value = String(picker.value || '').toUpperCase(); });
            codeInput?.addEventListener('input', () => {
                const value = String(codeInput.value || '').trim().toUpperCase();
                if (/^#[0-9A-F]{6}$/.test(value) && picker) picker.value = value;
            });
            codeInput?.addEventListener('blur', () => {
                const value = flowtrackNormalizeHexColor(codeInput.value, colorValue);
                codeInput.value = value;
                if (picker) picker.value = value;
            });
        }
        $('#saveMasterRecord').addEventListener('click', async () => {
            const payload = masterRecordPayload();
            if (!payload.name) {
                showToast('Name is required.');
                $('#masterName').focus();
                return;
            }
            const duplicateName = (masterData[category] || []).some((record) => Number(record.id) !== Number(existing?.id) && String(record.name || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase() === payload.name.toLocaleLowerCase());
            if (duplicateName) {
                showToast('This name already exists in this Master Data section. Duplicate entries are not allowed.');
                $('#masterName').focus();
                return;
            }
            if (hasColorCode && !/^#[0-9A-F]{6}$/.test(String(payload.color || '').toUpperCase())) {
                showToast('Use a valid 6-digit HEX color code, for example #2563EB.');
                $('#masterColorCode')?.focus();
                return;
            }
            if (isDocumentCategory) {
                if (!Number.isInteger(payload.sort_order) || payload.sort_order < 1) {
                    showToast('Document order must start from 1.');
                    $('#masterSortOrder').focus();
                    return;
                }
                const duplicateOrder = (masterData[category] || []).some((record) => Number(record.id) !== Number(existing?.id) && Number(record.sort_order) === Number(payload.sort_order));
                if (duplicateOrder) {
                    showToast('This document order is already in use. Choose a different order.');
                    $('#masterSortOrder').focus();
                    return;
                }
            }
            const button = $('#saveMasterRecord');
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const result = await api(existing ? `/flowtrack/template-api/master-data/${existing.id}` : '/flowtrack/template-api/master-data', {
                    method: existing ? 'PUT' : 'POST',
                    body: payload,
                });
                const records = masterData[category] || (masterData[category] = []);
                const index = records.findIndex((record) => Number(record.id) === Number(result.record.id));
                if (index >= 0) records.splice(index, 1, result.record);
                else records.push(result.record);
                records.sort((left, right) => Number(left.sort_order || 0) - Number(right.sort_order || 0) || left.name.localeCompare(right.name));
                closeAll();
                renderMasterData();
                showToast(existing ? 'Master-data record updated. The code remains unchanged.' : `${result.record.code} created automatically.`);
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Save Record';
            }
        });
    };

    function rebuildTaskPackDerivedState() {
        for (const key of Object.keys(taskTemplates)) delete taskTemplates[key];
        taskPacks.forEach((pack) => {
            taskTemplates[pack.name] = flowtrackToArray(pack.items).map((item) => item.title);
        });

        workflowTemplates.forEach((workflow) => {
            flowtrackToArray(workflow.phases).forEach((phase) => {
                if (!phase?.taskPackId) return;
                const pack = taskPacks.find((item) => Number(item.id) === Number(phase.taskPackId));
                if (!pack) return;
                phase.taskPack = pack.name;
                phase.taskCount = flowtrackToArray(pack.items).length;
            });
        });
    }

    function upsertTaskPack(taskPack) {
        if (!taskPack || typeof taskPack !== 'object' || !taskPack.id) return null;

        const normalized = {
            ...taskPack,
            active: Boolean(taskPack.active ?? taskPack.is_active ?? true),
            is_active: Boolean(taskPack.is_active ?? taskPack.active ?? true),
            items: flowtrackToArray(taskPack.items).filter((item) => item && typeof item === 'object'),
        };
        const index = taskPacks.findIndex((item) => Number(item.id) === Number(normalized.id));
        if (index >= 0) taskPacks.splice(index, 1, normalized);
        else taskPacks.push(normalized);
        taskPacks.sort((left, right) => String(left.name || '').localeCompare(String(right.name || '')));
        rebuildTaskPackDerivedState();
        return normalized;
    }

    async function flowtrackFetchTaskPack(taskPackId) {
        const id = Number(taskPackId);
        if (!id) return null;
        const result = await api(`/flowtrack/template-api/task-packs/${id}`);
        const pack = upsertTaskPack(result?.taskPack);
        if (!pack) throw new Error('The requested Task Pack could not be loaded.');
        return pack;
    }
    window.flowtrackFetchTaskPack = flowtrackFetchTaskPack;

    function blankTaskPackItem() {
        return {
            id: null,
            title: '',
            description: '',
            default_assignee_id: null,
            default_department_id: null,
            priority_id: null,
            document_category_id: null,
            due_offset_days: 0,
            is_required: true,
        };
    }

    function taskPackItemEditorHtml(items) {
        const departments = activeMaster('departments');
        const priorities = activeMaster('priorities');
        const documentCategories = activeMaster('documentCategories');
        return items.map((item, index) => `<div class="card task-pack-editor-item" data-pack-item-index="${index}">
            <div class="section-head"><div><h3 style="font-size:14px">Task ${index + 1}</h3><div class="small muted">Sequence ${index + 1} in this Task Pack</div></div><div class="row-actions"><button type="button" class="mini-btn" data-pack-up="${index}" ${index === 0 ? 'disabled' : ''}>↑</button><button type="button" class="mini-btn" data-pack-down="${index}" ${index === items.length - 1 ? 'disabled' : ''}>↓</button><button type="button" class="mini-btn" data-pack-remove="${index}">Remove</button></div></div>
            <div class="form-grid">
                <div class="field full"><label>Task title *</label><input data-pack-field="title" data-pack-index="${index}" value="${html(item.title)}" placeholder="Task title"></div>
                <div class="field full"><label>Description</label><textarea data-pack-field="description" data-pack-index="${index}" rows="2">${html(item.description)}</textarea></div>
                <div class="field"><label>Default assignee</label><select data-pack-field="default_assignee_id" data-pack-index="${index}"><option value="">Job coordinator</option>${users.map((user) => `<option value="${user.id}" ${Number(item.default_assignee_id) === Number(user.id) ? 'selected' : ''}>${html(user.name)}</option>`).join('')}</select></div>
                <div class="field"><label>Default department</label><select data-pack-field="default_department_id" data-pack-index="${index}"><option value="">No department default</option>${departments.map((department) => `<option value="${department.id}" ${Number(item.default_department_id) === Number(department.id) ? 'selected' : ''}>${html(department.name)}</option>`).join('')}</select></div>
                <div class="field"><label>Priority</label><select data-pack-field="priority_id" data-pack-index="${index}"><option value="">Use Job priority</option>${priorities.map((priority) => `<option value="${priority.id}" ${Number(item.priority_id) === Number(priority.id) ? 'selected' : ''}>${html(priority.name)}</option>`).join('')}</select></div>
                <div class="field"><label>Required document</label><select data-pack-field="document_category_id" data-pack-index="${index}"><option value="">No task-specific file</option>${documentCategories.map((category) => `<option value="${category.id}" ${Number(item.document_category_id) === Number(category.id) ? 'selected' : ''}>${html(category.name)}</option>`).join('')}</select><div class="inline-help">The required file must be uploaded or linked before this task status can be changed.</div></div>
            </div>
            <label class="check-row"><input data-pack-field="is_required" data-pack-index="${index}" type="checkbox" ${item.is_required ? 'checked' : ''}> <span>Required task</span></label>
        </div>`).join('');
    }

    function openTaskPackModal(existing = null, options = {}) {
        const mount = options?.mount instanceof Element ? options.mount : $('#jobModal');
        const standalone = Boolean(options?.standalone);
        const onReady = typeof options?.onReady === 'function' ? options.onReady : null;
        const query = (selector) => mount?.querySelector(selector) || null;
        const queryAll = (selector) => Array.from(mount?.querySelectorAll(selector) || []);
        const items = (existing?.items?.length ? existing.items : [blankTaskPackItem()]).map((item) => ({ ...blankTaskPackItem(), ...item }));

        if (!mount) {
            showToast('The Task Pack form could not be opened.');
            return null;
        }

        mount.removeAttribute('data-task-pack-form-ready');

        const renderItems = () => {
            const editor = query('#taskPackItemsEditor');
            if (!editor) return;
            editor.innerHTML = taskPackItemEditorHtml(items);
            queryAll('[data-pack-field]').forEach((control) => {
                const eventName = control.type === 'checkbox' || control.tagName === 'SELECT' ? 'change' : 'input';
                control.addEventListener(eventName, () => {
                    const index = Number(control.dataset.packIndex);
                    const field = control.dataset.packField;
                    let value = control.type === 'checkbox' ? control.checked : control.value;
                    if (['default_assignee_id', 'default_department_id', 'priority_id', 'document_category_id'].includes(field)) value = value ? Number(value) : null;
                    items[index][field] = value;
                });
            });
            queryAll('[data-pack-up]').forEach((button) => button.addEventListener('click', () => {
                const index = Number(button.dataset.packUp);
                [items[index - 1], items[index]] = [items[index], items[index - 1]];
                renderItems();
            }));
            queryAll('[data-pack-down]').forEach((button) => button.addEventListener('click', () => {
                const index = Number(button.dataset.packDown);
                [items[index + 1], items[index]] = [items[index], items[index + 1]];
                renderItems();
            }));
            queryAll('[data-pack-remove]').forEach((button) => button.addEventListener('click', () => {
                if (items.length === 1) {
                    showToast('A Task Pack must contain at least one task.');
                    return;
                }
                items.splice(Number(button.dataset.packRemove), 1);
                renderItems();
            }));
            window.FlowtrackSearchableDropdown?.enhance(mount);
        };

        const showForm = (code) => {
            mount.innerHTML = `<div class="modal-head"><div><h2>${existing ? 'Edit Task Pack' : 'Create Task Pack'}</h2><div class="small muted" style="margin-top:5px">Build the complete reusable task sequence activated by workflow phases.</div></div><button class="close-btn" data-close>×</button></div>
            <div class="modal-body"><div class="form-grid">
                <div class="field"><label>Task Pack code</label><input id="taskPackCode" value="${html(code)}" readonly aria-readonly="true"><div class="inline-help">Automatically generated and permanently locked.</div></div>
                <div class="field"><label>Status</label><select id="taskPackStatus"><option value="active" ${(existing?.active ?? existing?.is_active ?? true) ? 'selected' : ''}>Active</option><option value="inactive" ${(existing?.active ?? existing?.is_active ?? true) ? '' : 'selected'}>Inactive</option></select></div>
                <div class="field full"><label>Task Pack name *</label><input id="taskPackName" value="${html(existing?.name)}" placeholder="e.g. Quality Inspection"></div>
                <div class="field full"><label>Description</label><textarea id="taskPackDescription" rows="3" placeholder="Explain when this Task Pack is used…">${html(existing?.description)}</textarea></div>
            </div>
            <div class="section-head" style="margin-top:8px"><div><h3>Task sequence</h3><div class="small muted">Tasks are created in this order when the phase becomes active.</div></div><button type="button" class="secondary" id="addTaskPackItem">＋ Add Task</button></div>
            <div id="taskPackItemsEditor" class="task-pack-items-editor"></div></div>
            <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveTaskPack">${existing ? 'Save Task Pack' : 'Create Task Pack'}</button></div>`;

            if (!standalone) {
                openOverlay();
                mount.classList.add('open');
            } else {
                mount.classList.remove('open', 'document-job-chooser');
                $('#overlay')?.classList.remove('open');
            }

            queryAll('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
            query('#addTaskPackItem')?.addEventListener('click', () => {
                items.push(blankTaskPackItem());
                renderItems();
                if (standalone) {
                    window.setTimeout(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' }), 30);
                } else {
                    mount.scrollTo({ top: mount.scrollHeight, behavior: 'smooth' });
                }
            });
            renderItems();

            query('#saveTaskPack')?.addEventListener('click', async () => {
                const nameInput = query('#taskPackName');
                const name = nameInput?.value.trim() || '';
                if (!name) {
                    showToast('Task Pack name is required.');
                    nameInput?.focus();
                    return;
                }
                const missingTitleIndex = items.findIndex((item) => !String(item.title || '').trim());
                if (missingTitleIndex >= 0) {
                    showToast(`Task ${missingTitleIndex + 1} needs a title.`);
                    query(`[data-pack-field="title"][data-pack-index="${missingTitleIndex}"]`)?.focus();
                    return;
                }

                const button = query('#saveTaskPack');
                button.disabled = true;
                const originalText = button.textContent;
                try {
                    const result = await api(existing ? `/flowtrack/template-api/task-packs/${existing.id}` : '/flowtrack/template-api/task-packs', {
                        method: existing ? 'PUT' : 'POST',
                        body: {
                            name,
                            description: query('#taskPackDescription')?.value.trim() || null,
                            is_active: query('#taskPackStatus')?.value === 'active',
                            items: items.map((item) => ({
                                id: item.id || null,
                                title: String(item.title).trim(),
                                description: String(item.description || '').trim() || null,
                                default_assignee_id: item.default_assignee_id || null,
                                default_department_id: item.default_department_id || null,
                                priority_id: item.priority_id || null,
                                document_category_id: item.document_category_id || null,
                                due_offset_days: Number(item.due_offset_days || 0),
                                is_required: Boolean(item.is_required),
                            })),
                        },
                    });
                    const savedTaskPack = upsertTaskPack(result?.taskPack);
                    if (!savedTaskPack) throw new Error('The Task Pack was saved, but the server returned an invalid response.');

                    closeAll();
                    renderTaskPackSetup();
                    showToast(existing ? 'Task Pack updated successfully.' : 'Task Pack and its task sequence created successfully.');

                    void loadBootstrap()
                        .then(() => {
                            upsertTaskPack(savedTaskPack);
                            if (currentPage === 'taskpacksetup') renderTaskPackSetup();
                        })
                        .catch(() => {});
                } catch (error) {
                    showToast(error.message);
                } finally {
                    button.disabled = false;
                    button.textContent = originalText || (existing ? 'Save Task Pack' : 'Create Task Pack');
                }
            });

            mount.dataset.taskPackFormReady = '1';
            onReady?.(mount);
        };

        if (existing) {
            showForm(existing.code);
            return mount;
        }

        if (standalone) {
            mount.innerHTML = flowtrackInlineProgress('Preparing Task Pack form…');
        }
        api('/flowtrack/template-api/task-packs/next-code')
            .then((result) => showForm(result.code))
            .catch((error) => {
                mount.innerHTML = `<div class="empty-state">${html(error.message || 'The Task Pack form could not be loaded.')}</div>`;
                showToast(error.message);
            });
        return mount;
    }

    renderTaskPackSetup = function () {
        const page = $('#page-taskpacksetup');
        if (!page) return;
        const configuredTasks = taskPacks.reduce((sum, pack) => sum + (pack.items || []).length, 0);
        const mappedPhases = workflowTemplates.reduce((sum, workflow) => sum + (workflow.phases || []).filter((phase) => phase.taskPackId).length, 0);
        page.innerHTML = `<div class="page-head"><div><h1>Task Pack Setup</h1><p>Create reusable task sequences that activate when a Job enters a workflow phase</p></div><div class="actions"><button class="primary" id="addTaskPack">＋ Add Task Pack</button></div></div>
        <div class="config-summary"><div class="card config-stat"><span>Total Task Packs</span><b>${taskPacks.length}</b></div><div class="card config-stat"><span>Active Task Packs</span><b>${taskPacks.filter((pack) => pack.active).length}</b></div><div class="card config-stat"><span>Configured Tasks</span><b>${configuredTasks}</b></div><div class="card config-stat"><span>Mapped Phases</span><b>${mappedPhases}</b></div></div>
        <div id="taskPackSetupContent"><div class="pack-list">${taskPacks.map((pack) => `<div class="card pack"><div class="section-head"><div><h4>${html(pack.name)} Task Pack</h4><div class="small muted">${html(pack.code)} · ${(pack.items || []).length} predefined tasks · ${pack.active ? 'Active' : 'Inactive'}</div></div><button class="mini-btn" data-edit-pack-id="${pack.id}">Edit</button></div><div class="small muted">${html(pack.description || 'No description')}</div><ul class="pack-tasks">${(pack.items || []).map((item, index) => `<li><div><span>${index + 1}. ${html(item.title)}</span><div class="small muted" style="margin-top:3px">${html(item.default_assignee_name || item.default_department_name || 'Job coordinator')} · Due set from Task details${item.priority_name ? ` · <span style="color:${html(flowtrackPriorityColor(item.priority_name))};font-weight:700">${html(item.priority_name)}</span>` : ''}${item.document_category_name ? ` · Required file: ${html(item.document_category_name)}` : ''}</div></div><span class="badge ${item.is_required ? 'b-blue' : 'b-gray'}">${item.is_required ? 'Mandatory' : 'Optional'}</span></li>`).join('') || '<li><span class="muted">No tasks configured</span></li>'}</ul></div>`).join('') || '<div class="card empty-state">No Task Packs have been created.</div>'}</div></div>`;
        $('#addTaskPack')?.addEventListener('click', () => openTaskPackModal());
        $$('[data-edit-pack-id]').forEach((button) => button.addEventListener('click', async () => {
            const id = Number(button.dataset.editPackId);
            if (!id) return;
            try {
                const pack = await flowtrackFetchTaskPack(id);
                if (pack) openTaskPackModal(pack);
            } catch (error) {
                showToast(error.message || 'The Task Pack could not be loaded.');
            }
        }));
        queueMicrotask(applyActionPermissions);
    };

    function productByName(name) {
        return (masterData.products || []).find((product) => product.name.toLowerCase() === String(name || '').trim().toLowerCase());
    }

    function jobPriorityOptions(name, selectedId) {
        const priorities = activeMaster('priorities');
        return `<div class="job-priority-options">${priorities.map((item) => `<label class="job-priority-option"><input type="radio" name="${name}" value="${item.id}" ${Number(item.id) === Number(selectedId) ? 'checked' : ''}><span class="job-priority-check">✓</span><i aria-hidden="true" style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${html(flowtrackPriorityColor(item))}"></i><span>${html(item.name)}</span></label>`).join('')}</div>`;
    }

    function canEditJobRecord() {
        return canFlow('jobs', 'edit_all') || canFlow('jobs', 'edit_own');
    }

    function flowtrackMemberInitials(name) {
        return String(name || 'FT')
            .trim()
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part.charAt(0).toUpperCase())
            .join('') || 'FT';
    }

    function flowtrackWorkspaceMembers() {
        const byId = new Map();
        const push = (source) => {
            if (!source || typeof source !== 'object') return;
            const status = String(source.status || 'active').toLowerCase();
            if (status && !['active', 'enabled'].includes(status)) return;
            const id = Number(source.id ?? source.userId ?? source.user_id ?? 0);
            const name = String(source.name || source.full_name || '').trim();
            if (!id || !name) return;
            byId.set(id, {
                id,
                name,
                initials: source.initials || flowtrackMemberInitials(name),
                email: source.email || null,
                role: source.role || source.roleName || source.role_name || 'Member',
                dept: source.dept || source.department || source.departmentName || source.department_name || 'Unassigned',
                status: 'active',
            });
        };

        flowtrackToArray(users).forEach(push);
        flowtrackToArray(accessMembers).forEach(push);
        if (currentUser?.id && currentUser?.name) {
            push({
                id: currentUser.id,
                name: currentUser.name,
                initials: currentUser.initials,
                email: currentUser.email,
                role: currentUser.role,
                dept: currentUser.department,
                status: 'active',
            });
        }

        return Array.from(byId.values()).sort((a, b) => {
            if (Number(a.id) === Number(currentUser?.id)) return -1;
            if (Number(b.id) === Number(currentUser?.id)) return 1;
            return String(a.name).localeCompare(String(b.name));
        });
    }

    function flowtrackJobAssigneeUsers() {
        const members = flowtrackWorkspaceMembers();
        if (canFlow('jobs', 'assign')) return members;
        const ownUser = members.filter((user) => Number(user.id) === Number(currentUser?.id));
        return ownUser.length ? ownUser : members.slice(0, 1);
    }

    function flowtrackAssigneeOptionsHtml(selectedId = null, fallbackName = '') {
        const selected = selectedId ? Number(selectedId) : Number(currentUser?.id || 0);
        const members = flowtrackJobAssigneeUsers();
        if (!members.length) {
            const fallbackId = Number(currentUser?.id || 0);
            const fallbackText = String(fallbackName || currentUser?.name || 'Current user').trim();
            return fallbackId
                ? `<option value="${html(fallbackId)}" selected>${html(fallbackText)}</option>`
                : '<option value="">No active workspace members found</option>';
        }
        const hasSelected = selected && members.some((user) => Number(user.id) === selected);
        const options = members.map((user, index) => {
            const isSelected = hasSelected ? Number(user.id) === selected : index === 0;
            const labelParts = [user.name, user.dept && user.dept !== 'Unassigned' ? user.dept : '', user.role && user.role !== 'Member' ? user.role : ''].filter(Boolean);
            return `<option value="${html(user.id)}" ${isSelected ? 'selected' : ''}>${html(labelParts.join(' · '))}</option>`;
        });
        return options.join('');
    }

    function flowtrackRefreshAssigneeSelect(host, selector, selectedId = null, fallbackName = '') {
        const scope = host || document;
        const select = scope.querySelector?.(selector) || document.querySelector(selector);
        if (!select) return;
        select.innerHTML = flowtrackAssigneeOptionsHtml(selectedId || select.value, fallbackName);
        const hasAssignableUsers = flowtrackJobAssigneeUsers().length > 0 || Number(currentUser?.id || 0) > 0;
        select.disabled = !hasAssignableUsers || (!canFlow('jobs', 'assign') && String(select.value) !== String(currentUser?.id || ''));
        const field = select.closest('.field');
        if (field) field.classList.remove('hidden');
        window.FlowtrackSearchableDropdown?.refresh(select);
        window.FlowtrackSearchableDropdown?.enhance(scope);
    }

    openJobModal = function (prefillClient) {
        const categories = activeMaster('productCategories');
        const defaultClient = clients.find((client) => Number(client.id) === Number(prefillClient)) || clients[0];
        const defaultPriority = (masterData.priorities || []).find((item) => item.code === 'MED') || activeMaster('priorities')[0];
        const defaultCurrency = (masterData.currencies || []).find((item) => item.code === 'USD') || activeMaster('currencies')[0];
        const ownerUsers = flowtrackJobAssigneeUsers();

        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Create New Job</h2><div class="small muted" style="margin-top:5px">Enter the Job details in order and add every requested product.</div></div><button class="close-btn" data-close>×</button></div>
        <div class="modal-body"><div class="form-grid job-form-stack">
        <div class="field full"><label>Client *</label><select id="newJobClient" data-searchable data-search-placeholder="Search clients…">${clients.map((client) => `<option value="${client.id}" ${Number(defaultClient?.id) === Number(client.id) ? 'selected' : ''}>${html(client.name)}</option>`).join('')}</select></div>
        <div class="field full"><label>Client contact</label><input id="newJobContact" value="${html(defaultClient?.contact)}" readonly></div>
        <div class="field full"><label>Job title *</label><input id="newJobTitle" placeholder="e.g. Conference merchandise order"></div>
        <div class="field full"><label>Request description</label><textarea id="newJobDesc" rows="3" placeholder="Specifications, target price, printing or customization requirements…"></textarea></div>
        <div class="field full job-products-field">
            <div class="job-products-title"><div><label>Products & quantities *</label><div class="inline-help">Choose the Product Category first, then its Product and Quantity on the same row.</div></div></div>
            <div class="job-product-labels"><span>Product category</span><span>Product</span><span>Quantity</span><span aria-hidden="true"></span></div>
            <div id="newJobProducts" class="job-product-list"></div>
            <button type="button" class="secondary job-add-product" id="addJobProduct">＋ Add another product</button>
        </div>
        <div class="field full"><label>Required delivery date *</label><input id="newJobDelivery" type="date"></div>
        <div class="field full"><label>Priority</label>${jobPriorityOptions('newJobPriority', defaultPriority?.id)}</div>
        <div class="field full"><label>Job assignee *</label><select id="newJobOwner" data-searchable data-search-placeholder="Search assignees…" ${canFlow('jobs', 'assign') ? '' : 'disabled'}>${flowtrackAssigneeOptionsHtml(currentUser?.id)}</select><div class="inline-help">Shows active workspace members. Admin/Super Admin can choose any assignee; otherwise your own user is selected automatically.</div></div>
        <div class="field full"><label>Workflow template *</label><select id="newJobWorkflow" data-searchable data-search-placeholder="Search workflow templates…">${workflowTemplates.filter((workflow) => workflow.active).map((workflow) => `<option value="${workflow.id}">${html(workflow.name)}</option>`).join('')}</select><div class="inline-help">The selected workflow controls available starting stages and Task Packs.</div></div>
        <div class="field full"><label>Start Job from stage *</label><select id="newJobStartPhase" data-searchable data-search-placeholder="Search starting stages…"></select><div class="inline-help">Choose the first Workflow stage that should be activated for this Job.</div></div>
        <div class="field full"><label>Attachments</label><div class="job-create-task-attachment-shell"><div id="newJobAttachmentDrop" class="ft-task-dropzone job-create-task-dropzone" tabindex="0"><input id="newJobAttachments" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.webp,.zip,.txt,.csv"><span class="ft-task-paperclip" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M21.44 11.05 12.25 20.24a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66L9.41 17.41a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg></span><div><b>Drop files here or <button type="button" data-new-job-browse>browse</button></b><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Max 20 MB</small></div><button type="button" class="ft-task-btn ft-task-btn-secondary" data-new-job-choose-documents>Choose from Documents</button></div><div id="newJobAttachmentList" class="ft-task-file-list job-create-task-file-list"></div></div></div>
        </div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="secondary" id="saveDraftJob">Save Draft</button><button class="primary" id="createJob">Create</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));

        let productRows = [{ product_category_id: '', product_id: '', quantity: 1000 }];
        let attachmentFiles = [];
        let existingAttachmentDocuments = [];
        const attachmentLimit = 10;
        const maxAttachmentBytes = 20 * 1024 * 1024;
        const supportedAttachmentExtensions = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','webp','zip','txt','csv'];
        const productsForCategory = (categoryId) => activeMaster('products').filter((product) => Number(product.parent_id) === Number(categoryId));
        const categoryOptions = (selectedId) => `<option value="">Select category</option>${categories.map((item) => `<option value="${item.id}" ${Number(item.id) === Number(selectedId) ? 'selected' : ''}>${html(item.name)}</option>`).join('')}`;

        const renderProductRows = () => {
            $('#newJobProducts').innerHTML = productRows.map((row, index) => {
                const products = productsForCategory(row.product_category_id);
                return `<div class="job-product-row" data-product-row="${index}">
                    <div class="job-product-stack-field"><label>Product category</label><select class="new-job-category" data-searchable data-search-placeholder="Search categories…" aria-label="Product category ${index + 1}">${categoryOptions(row.product_category_id)}</select></div>
                    <div class="job-product-stack-field"><label>Product</label><select class="new-job-product" data-searchable data-search-placeholder="Search products…" aria-label="Product ${index + 1}" ${row.product_category_id ? '' : 'disabled'}>
                        <option value="">${row.product_category_id ? (products.length ? 'Select product' : 'No products in this category') : 'Select category first'}</option>
                        ${products.map((product) => `<option value="${product.id}" ${Number(row.product_id) === Number(product.id) ? 'selected' : ''}>${html(product.code)} · ${html(product.name)}</option>`).join('')}
                    </select></div>
                    <div class="job-product-stack-field"><label>Quantity</label><input class="new-job-quantity" type="number" min="1" max="1000000000" value="${Math.max(1, Number(row.quantity) || 1)}" aria-label="Quantity for product ${index + 1}"></div>
                    <div class="job-product-stack-action"><button type="button" class="job-remove-product" data-remove-product="${index}" title="Remove product" aria-label="Remove product ${index + 1}" ${productRows.length === 1 ? 'disabled' : ''}></button></div>
                </div>`;
            }).join('');
            window.FlowtrackSearchableDropdown?.enhance($('#newJobProducts'));
        };

        const syncProductRows = () => {
            productRows = $$('#newJobProducts .job-product-row').map((row) => ({
                product_category_id: row.querySelector('.new-job-category').value,
                product_id: row.querySelector('.new-job-product').value,
                quantity: Number(row.querySelector('.new-job-quantity').value || 1),
            }));
        };

        $('#addJobProduct').addEventListener('click', () => {
            syncProductRows();
            productRows.push({ product_category_id: '', product_id: '', quantity: 1 });
            renderProductRows();
            $('#newJobProducts .job-product-row:last-child .new-job-category')?.focus();
        });

        $('#newJobProducts').addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-product]');
            if (!button || productRows.length === 1) return;
            syncProductRows();
            productRows.splice(Number(button.dataset.removeProduct), 1);
            renderProductRows();
        });
        $('#newJobProducts').addEventListener('change', (event) => {
            if (!event.target.matches('.new-job-category')) return;
            const row = event.target.closest('.job-product-row');
            syncProductRows();
            const index = Number(row.dataset.productRow);
            productRows[index].product_id = '';
            renderProductRows();
        });

        const refreshStages = () => {
            const workflow = getWorkflow(Number($('#newJobWorkflow').value));
            const select = $('#newJobStartPhase');
            select.innerHTML = (workflow?.phases || []).filter((phase) => phase.allowStart && phase.active !== false).map((phase) => `<option value="${phase.id}">${phase.sequence || workflow.phases.indexOf(phase) + 1}. ${html(phase.name)}</option>`).join('');
            window.FlowtrackSearchableDropdown?.refresh(select);
        };

        const formatFileSize = (bytes) => {
            if (bytes < 1024) return `${bytes} B`;
            if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
            return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        };
        const renderAttachments = () => {
            const localRows = attachmentFiles.map((file, index) => {
                const extension = String(file.name || '').split('.').pop().toUpperCase().slice(0, 4) || 'FILE';
                return `<div class="ft-task-file-row job-create-task-file-row"><span class="ft-task-file-icon">${html(extension)}</span><div><b>${html(file.name)}</b><small>${formatFileSize(file.size)} · Ready to attach</small></div><button type="button" class="job-create-task-file-remove" data-remove-attachment="${index}">Remove</button></div>`;
            });
            const storedRows = existingAttachmentDocuments.map((documentRecord) => `<div class="ft-task-file-row job-create-task-file-row"><span class="ft-task-file-icon">${html(flowtrackDocumentFileBadge(documentRecord))}</span><div><b>${html(documentRecord.name || documentRecord.id)}</b><small>Stored document · ${html(documentRecord.job || 'FlowTrack')} · Ready to attach</small></div><button type="button" class="job-create-task-file-remove" data-remove-existing-attachment="${html(documentRecord.dbId)}">Remove</button></div>`);
            $('#newJobAttachmentList').innerHTML = [...localRows, ...storedRows].join('');
        };
        const addAttachments = (incomingFiles) => {
            const next = flowtrackToArray(attachmentFiles);
            for (const file of Array.from(incomingFiles || [])) {
                const extension = String(file.name || '').split('.').pop().toLowerCase();
                if (!supportedAttachmentExtensions.includes(extension)) {
                    showToast(`${file.name} is not a supported attachment type.`);
                    continue;
                }
                if (file.size > maxAttachmentBytes) {
                    showToast(`${file.name} is larger than 20 MB.`);
                    continue;
                }
                if (next.some((item) => item.name === file.name && item.size === file.size && item.lastModified === file.lastModified)) continue;
                if (next.length + existingAttachmentDocuments.length >= attachmentLimit) {
                    showToast('You can attach up to 10 files.');
                    break;
                }
                next.push(file);
            }
            attachmentFiles = next;
            renderAttachments();
        };
        const attachmentDrop = $('#newJobAttachmentDrop');
        const attachmentInput = $('#newJobAttachments');
        $('[data-new-job-browse]')?.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); attachmentInput.click(); });
        $('[data-new-job-choose-documents]')?.addEventListener('click', async (event) => {
            event.preventDefault(); event.stopPropagation();
            if (!canFlow('documents', 'view')) return showToast('Your role cannot view stored documents.');
            let available;
            try {
                available = await flowtrackEnsureStoredDocumentsForChooser();
            } catch (error) {
                return showToast(error.message || 'Stored documents could not be loaded.');
            }
            if (!available.length) return showToast('There are no stored documents available.');
            let chooser = $('#newJobStoredDocumentChooser');
            if (chooser) { chooser.remove(); return; }
            const shell = $('#newJobAttachmentDrop')?.parentElement;
            if (!shell) return;
            shell.insertAdjacentHTML('beforeend', `<div id="newJobStoredDocumentChooser" class="job-create-stored-chooser"><div class="job-create-stored-head"><b>Choose from Documents</b><button type="button" data-new-job-existing-close>×</button></div><input type="search" placeholder="Search stored documents…" data-new-job-existing-search><div class="job-v6-existing-list">${available.map((item) => `<div class="job-v6-existing-row" data-new-job-existing-row data-search="${html(normalizedStatusValue(`${item.name || ''} ${item.type || ''} ${item.job || ''} ${item.uploadedBy || ''}`))}"><span class="job-v6-file-icon ${html(flowtrackDocumentFileBadge(item).toLowerCase())}">${html(flowtrackDocumentFileBadge(item))}</span><div><b>${html(item.name || 'Document')}</b><small>${html(item.job || 'Stored document')} · ${html(item.type || 'Document')}</small></div><button type="button" class="${existingAttachmentDocuments.some((doc) => Number(doc.dbId) === Number(item.dbId)) ? 'secondary' : 'primary'}" data-new-job-existing-add="${html(item.dbId)}">${existingAttachmentDocuments.some((doc) => Number(doc.dbId) === Number(item.dbId)) ? 'Selected' : 'Add'}</button></div>`).join('')}</div></div>`);
            chooser = $('#newJobStoredDocumentChooser');
            chooser.querySelector('[data-new-job-existing-close]')?.addEventListener('click', () => chooser.remove());
            chooser.querySelector('[data-new-job-existing-search]')?.addEventListener('input', (searchEvent) => { const term = normalizedStatusValue(searchEvent.target.value || ''); chooser.querySelectorAll('[data-new-job-existing-row]').forEach((row) => row.classList.toggle('is-hidden', Boolean(term) && !String(row.dataset.search || '').includes(term))); });
            chooser.querySelectorAll('[data-new-job-existing-add]').forEach((button) => button.addEventListener('click', () => {
                const doc = available.find((item) => Number(item.dbId) === Number(button.dataset.newJobExistingAdd));
                if (!doc) return;
                const selectedIndex = existingAttachmentDocuments.findIndex((item) => Number(item.dbId) === Number(doc.dbId));
                if (selectedIndex >= 0) { existingAttachmentDocuments.splice(selectedIndex, 1); button.textContent = 'Add'; button.className = 'primary'; }
                else {
                    if (attachmentFiles.length + existingAttachmentDocuments.length >= attachmentLimit) return showToast('You can attach up to 10 files.');
                    existingAttachmentDocuments.push(doc); button.textContent = 'Selected'; button.className = 'secondary';
                }
                renderAttachments();
            }));
        });
        attachmentDrop.addEventListener('click', (event) => { if (event.target.closest('button')) return; attachmentInput.click(); });
        attachmentDrop.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                attachmentInput.click();
            }
        });
        attachmentInput.addEventListener('change', (event) => {
            addAttachments(event.target.files);
            event.target.value = '';
        });
        ['dragenter', 'dragover'].forEach((name) => attachmentDrop.addEventListener(name, (event) => {
            event.preventDefault();
            attachmentDrop.classList.add('dragging', 'is-dragging');
        }));
        ['dragleave', 'drop'].forEach((name) => attachmentDrop.addEventListener(name, (event) => {
            event.preventDefault();
            attachmentDrop.classList.remove('dragging', 'is-dragging');
        }));
        attachmentDrop.addEventListener('drop', (event) => addAttachments(event.dataTransfer?.files));
        $('#newJobAttachmentList').addEventListener('click', (event) => {
            const localButton = event.target.closest('[data-remove-attachment]');
            if (localButton) {
                attachmentFiles.splice(Number(localButton.dataset.removeAttachment), 1);
                renderAttachments();
                return;
            }
            const storedButton = event.target.closest('[data-remove-existing-attachment]');
            if (storedButton) {
                existingAttachmentDocuments = existingAttachmentDocuments.filter((item) => Number(item.dbId) !== Number(storedButton.dataset.removeExistingAttachment));
                renderAttachments();
            }
        });

        $('#newJobClient').addEventListener('change', (event) => {
            const client = clients.find((item) => Number(item.id) === Number(event.target.value));
            $('#newJobContact').value = client?.contact || '';
        });
        $('#newJobWorkflow').addEventListener('change', refreshStages);
        renderProductRows();
        refreshStages();
        window.FlowtrackSearchableDropdown?.enhance($('#jobModal'));

        const submit = async (draft) => {
            const title = $('#newJobTitle').value.trim();
            const phaseId = Number($('#newJobStartPhase').value);
            syncProductRows();

            const normalizedProducts = productRows.map((row) => ({
                product_id: Number(row.product_id || 0),
                product_category_id: Number(row.product_category_id || 0),
                quantity: Number(row.quantity || 0),
            }));
            const productIds = normalizedProducts.map((item) => item.product_id);

            if (!title) return showToast('Please enter the Job title.');
            if (normalizedProducts.some((item) => !item.product_category_id || !item.product_id || item.quantity < 1)) return showToast('Select a category, product and valid quantity in every product row.');
            if (new Set(productIds).size !== productIds.length) return showToast('The same product cannot be added more than once.');
            if (!$('#newJobDelivery').value) return showToast('Please select the required delivery date.');

            const button = draft ? $('#saveDraftJob') : $('#createJob');
            button.disabled = true;
            const initialText = button.textContent;
            button.textContent = draft ? 'Saving…' : 'Creating…';
            try {
                const formData = new FormData();
                formData.append('client_id', String(Number($('#newJobClient').value)));
                formData.append('workflow_template_id', String(Number($('#newJobWorkflow').value)));
                formData.append('start_phase_id', String(phaseId));
                const selectedWorkflowForStart = getWorkflow(Number($('#newJobWorkflow').value));
                const selectedStartPhase = flowtrackToArray(selectedWorkflowForStart?.phases).find((phase) => Number(phase.id) === Number(phaseId));
                if (Number(selectedStartPhase?.sequence || 1) > 1) {
                    formData.append('start_reason', `Job created from ${selectedStartPhase?.name || 'the selected workflow phase'} through the Create Job page.`);
                }
                normalizedProducts.forEach((item, index) => {
                    formData.append(`products[${index}][product_id]`, String(item.product_id));
                    formData.append(`products[${index}][product_category_id]`, String(item.product_category_id));
                    formData.append(`products[${index}][quantity]`, String(item.quantity));
                });
                if (defaultCurrency?.id) formData.append('currency_id', String(defaultCurrency.id));
                const priorityId = Number(document.querySelector('input[name="newJobPriority"]:checked')?.value || 0);
                if (priorityId) formData.append('priority_id', String(priorityId));
                const ownerId = Number($('#newJobOwner').value) || Number(currentUser?.id || 0);
                if (ownerId) formData.append('owner_id', String(ownerId));
                if (currentUser?.id) formData.append('coordinator_id', String(currentUser.id));
                formData.append('title', title);
                const requestDescription = $('#newJobDesc').value.trim();
                if (requestDescription) formData.append('request_description', requestDescription);
                formData.append('quoted_amount', '0');
                formData.append('delivery_date', $('#newJobDelivery').value || '');
                formData.append('draft', draft ? '1' : '0');
                attachmentFiles.forEach((file) => formData.append('attachments[]', file));
                existingAttachmentDocuments.forEach((documentRecord) => formData.append('existing_attachment_document_ids[]', String(documentRecord.dbId)));
                const result = await api('/flowtrack/template-api/jobs', {
                    method: 'POST',
                    body: formData,
                });
                for (let index = jobs.length - 1; index >= 0; index -= 1) {
                    if (Number(jobs[index].dbId) === Number(result.job.dbId) || String(jobs[index].id) === String(result.job.id)) jobs.splice(index, 1);
                }
                jobs.unshift(result.job);
                replaceTasksForJob(result.job.id, result.tasks);
                closeAll();
                navigate('jobs');
                updateBadges();
                const totalAttachments = attachmentFiles.length + existingAttachmentDocuments.length;
                const attachmentText = totalAttachments ? ` and ${totalAttachments} attachment${totalAttachments === 1 ? '' : 's'}` : '';
                showToast(draft ? 'Draft Job saved. Opening details…' : `${result.job.id} created with ${result.job.productCount || normalizedProducts.length} product line${(result.job.productCount || normalizedProducts.length) === 1 ? '' : 's'}${attachmentText}. Opening details…`);
                setTimeout(() => {
                    openJob(result.job.id);
                    setTimeout(() => {
                        const page = document.querySelector(`#page-record-job-${flowtrackRecordSlug(result.job.id)} .standalone-record-content`);
                        if (page) {
                            activateScopedDetailTab(page, 2);
                            flowtrackOpenPhaseHistory(result.job.id, result.job.phaseId, page);
                        }
                    }, 50);
                }, 40);
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = initialText;
            }
        };

        $('#createJob').addEventListener('click', () => submit(false));
        $('#saveDraftJob').addEventListener('click', () => submit(true));
    };

    function openEditJobModal(job) {
        if (!job || !canEditJobRecord()) return;
        const categories = activeMaster('productCategories');
        const defaultCurrency = (masterData.currencies || []).find((item) => Number(item.id) === Number(job.currencyId)) || activeMaster('currencies')[0];
        const assignableUsers = canFlow('jobs', 'assign') ? flowtrackWorkspaceMembers() : flowtrackWorkspaceMembers().filter((user) => Number(user.id) === Number(job.ownerId) || Number(user.id) === Number(currentUser?.id));
        let productRows = (job.products || []).map((item) => ({ product_category_id: item.categoryId || '', product_id: item.productId || '', quantity: item.qty || 1 }));
        if (!productRows.length) productRows = [{ product_category_id: job.categoryId || '', product_id: job.productId || '', quantity: job.qty || 1 }];

        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Edit Job</h2><div class="small muted" style="margin-top:5px">Update the Job details without changing its active Workflow or current Phase.</div></div><button class="close-btn" data-edit-job-close>×</button></div>
        <div class="modal-body"><div class="form-grid job-form-stack">
            <div class="field full"><label>Job code</label><input value="${html(job.id)}" readonly></div>
            <div class="field full"><label>Client *</label><select id="editJobClient" data-searchable data-search-placeholder="Search clients…">${clients.map((client) => `<option value="${client.id}" ${Number(client.id) === Number(job.client) ? 'selected' : ''}>${html(client.name)}</option>`).join('')}</select></div>
            <div class="field full"><label>Job title *</label><input id="editJobTitle" value="${html(job.title)}"></div>
            <div class="field full"><label>Request description</label><textarea id="editJobDesc" rows="3" placeholder="Specifications, target price, printing or customization requirements…">${html(job.requestDescription || '')}</textarea></div>
            <div class="field full job-products-field"><div class="job-products-title"><div><label>Products & quantities *</label><div class="inline-help">Category, Product and Quantity stay linked on each row.</div></div></div><div class="job-product-labels"><span>Product category</span><span>Product</span><span>Quantity</span><span></span></div><div id="editJobProducts" class="job-product-list"></div><button type="button" class="secondary job-add-product" id="addEditJobProduct">＋ Add another product</button></div>
            <div class="field full"><label>Required delivery date *</label><input id="editJobDelivery" type="date" value="${html(job.delivery || '')}"></div>
            <div class="field full"><label>Priority</label>${jobPriorityOptions('editJobPriority', job.priorityId)}</div>
            <div class="field full"><label>Job assignee</label><select id="editJobOwner" data-searchable data-search-placeholder="Search assignees…" ${canFlow('jobs', 'assign') ? '' : 'disabled'}>${assignableUsers.map((user) => `<option value="${user.id}" ${Number(user.id) === Number(job.ownerId) ? 'selected' : ''}>${html(user.name)}</option>`).join('')}</select></div>
            <div class="field full"><label>Coordinator</label><select id="editJobCoordinator" data-searchable data-search-placeholder="Search coordinators…" ${canFlow('jobs', 'assign') ? '' : 'disabled'}>${assignableUsers.map((user) => `<option value="${user.id}" ${Number(user.id) === Number(job.coordinatorId) ? 'selected' : ''}>${html(user.name)}</option>`).join('')}</select></div>
            <div class="field full"><label>Workflow</label><input value="${html(getWorkflow(job.workflow)?.name || '')}" readonly><div class="inline-help">Use the Workflow tab to move the Job between configured Phases.</div></div>
        </div></div>
        <div class="modal-foot"><button class="ghost" data-edit-job-close>Cancel</button><button class="primary" id="saveEditJob">Save Job Changes</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
        $$('[data-edit-job-close]').forEach((button) => button.addEventListener('click', () => { closeAll(); setTimeout(() => openJob(job.id), 20); }));

        const productsForCategory = (categoryId) => activeMaster('products').filter((product) => Number(product.parent_id) === Number(categoryId));
        const renderRows = () => {
            $('#editJobProducts').innerHTML = productRows.map((row, index) => {
                const products = productsForCategory(row.product_category_id);
                return `<div class="job-product-row" data-product-row="${index}"><select class="edit-job-category" data-searchable data-search-placeholder="Search categories…"><option value="">Select category</option>${categories.map((item) => `<option value="${item.id}" ${Number(item.id) === Number(row.product_category_id) ? 'selected' : ''}>${html(item.name)}</option>`).join('')}</select><select class="edit-job-product" data-searchable data-search-placeholder="Search products…" ${row.product_category_id ? '' : 'disabled'}><option value="">${row.product_category_id ? 'Select product' : 'Select category first'}</option>${products.map((product) => `<option value="${product.id}" ${Number(product.id) === Number(row.product_id) ? 'selected' : ''}>${html(product.code)} · ${html(product.name)}</option>`).join('')}</select><input class="edit-job-quantity" type="number" min="1" max="1000000000" value="${Math.max(1, Number(row.quantity) || 1)}"><button type="button" class="job-remove-product" data-remove-edit-product="${index}" ${productRows.length === 1 ? 'disabled' : ''}>×</button></div>`;
            }).join('');
            window.FlowtrackSearchableDropdown?.enhance($('#editJobProducts'));
        };
        const syncRows = () => {
            productRows = $$('#editJobProducts .job-product-row').map((row) => ({ product_category_id: row.querySelector('.edit-job-category').value, product_id: row.querySelector('.edit-job-product').value, quantity: Number(row.querySelector('.edit-job-quantity').value || 1) }));
        };
        $('#addEditJobProduct').addEventListener('click', () => { syncRows(); productRows.push({ product_category_id: '', product_id: '', quantity: 1 }); renderRows(); });
        $('#editJobProducts').addEventListener('click', (event) => { const button = event.target.closest('[data-remove-edit-product]'); if (!button || productRows.length === 1) return; syncRows(); productRows.splice(Number(button.dataset.removeEditProduct), 1); renderRows(); });
        $('#editJobProducts').addEventListener('change', (event) => { if (!event.target.matches('.edit-job-category')) return; const row = event.target.closest('.job-product-row'); syncRows(); productRows[Number(row.dataset.productRow)].product_id = ''; renderRows(); });
        renderRows();
        window.FlowtrackSearchableDropdown?.enhance($('#jobModal'));

        $('#saveEditJob').addEventListener('click', async () => {
            syncRows();
            const products = productRows.map((row) => ({ product_category_id: Number(row.product_category_id || 0), product_id: Number(row.product_id || 0), quantity: Number(row.quantity || 0) }));
            if (!$('#editJobTitle').value.trim()) return showToast('Please enter the Job title.');
            if (products.some((item) => !item.product_category_id || !item.product_id || item.quantity < 1)) return showToast('Select a category, product and valid quantity in every product row.');
            if (new Set(products.map((item) => item.product_id)).size !== products.length) return showToast('The same product cannot be added more than once.');
            if (!$('#editJobDelivery').value) return showToast('Please select the required delivery date.');
            const button = $('#saveEditJob'); button.disabled = true; button.textContent = 'Saving…';
            try {
                const updateBody = {
                    client_id: Number($('#editJobClient').value),
                    products,
                    priority_id: Number(document.querySelector('input[name="editJobPriority"]:checked')?.value || 0) || null,
                    owner_id: Number($('#editJobOwner').value) || null,
                    coordinator_id: Number($('#editJobCoordinator').value) || null,
                    title: $('#editJobTitle').value.trim(),
                    request_description: $('#editJobDesc').value.trim() || null,
                    delivery_date: $('#editJobDelivery').value,
                };
                if (job.price !== null && job.price !== undefined) {
                    updateBody.quoted_amount = Number(job.price || 0);
                    updateBody.currency_id = defaultCurrency?.id || null;
                }
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}`, { method: 'PUT', body: updateBody });
                const index = jobs.findIndex((item) => Number(item.dbId) === Number(job.dbId));
                if (index >= 0) jobs.splice(index, 1, result.job);
                if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                else if (result.task) flowtrackUpsertRecord(tasks, result.task);
                closeAll();
                renderPage(currentPage);
                setTimeout(() => openJob(result.job.id), 20);
                showToast('Job details updated successfully.');
            } catch (error) {
                showToast(error.message);
                button.disabled = false;
                button.textContent = 'Save Job Changes';
            }
        });
    }

    const originalQuickCreate = $('#quickCreateBtn');
    if (originalQuickCreate) {
        const dynamicQuickCreate = originalQuickCreate.cloneNode(true);
        originalQuickCreate.replaceWith(dynamicQuickCreate);
        dynamicQuickCreate.addEventListener('click', () => openJobModal());
    }


    function syncWorkflow(workflow) {
        if (!workflow) return;
        const index = workflowTemplates.findIndex((item) => Number(item.id) === Number(workflow.id));
        if (index >= 0) workflowTemplates.splice(index, 1, workflow);
        else workflowTemplates.push(workflow);
        workflowTemplates.sort((a, b) => {
            if (Boolean(a.isDefault) !== Boolean(b.isDefault)) return a.isDefault ? -1 : 1;
            return String(a.name).localeCompare(String(b.name));
        });
        selectedWorkflowId = workflow.id;
    }

    function workflowCode(name) {
        const base = String(name || '')
            .normalize('NFKD')
            .replace(/[^A-Za-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .toUpperCase()
            .slice(0, 32) || 'WORKFLOW';
        let candidate = base;
        let suffix = 2;
        while (workflowTemplates.some((item) => String(item.code).toUpperCase() === candidate)) {
            candidate = `${base}_${suffix++}`.slice(0, 40);
        }
        return candidate;
    }

    function openWorkflowModal(existing = null, duplicate = false) {
        const source = duplicate ? existing : null;
        const startingName = duplicate ? `${existing.name} Copy` : (existing?.name || '');
        const startingCode = duplicate ? workflowCode(`${existing.code}_COPY`) : (existing?.code || '');
        const startingDescription = existing?.description || '';

        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${existing && !duplicate ? 'Edit Workflow Details' : duplicate ? 'Duplicate Workflow' : 'Create New Workflow'}</h2><div class="small muted" style="margin-top:5px">Configure the workflow identity without changing the template layout.</div></div><button class="close-btn" data-close>×</button></div>
        <div class="modal-body"><div class="form-grid">
            <div class="field"><label>Workflow name *</label><input id="workflowName" value="${html(startingName)}" placeholder="e.g. Fast Track Order"></div>
            <div class="field"><label>Workflow code *</label><input id="workflowCode" value="${html(startingCode)}" placeholder="e.g. FAST_TRACK"></div>
            <div class="field full"><label>Description</label><textarea id="workflowDescription" rows="3" placeholder="Describe when this workflow should be used…">${html(startingDescription)}</textarea></div>
            ${existing && !duplicate ? `<label class="check-row full"><input id="workflowActive" type="checkbox" ${existing.active ? 'checked' : ''}> <span>Workflow active and available for new Jobs</span></label>` : ''}
            ${!existing || duplicate ? `<div class="field full"><label>Start from</label><select id="workflowBase"><option value="">Blank workflow</option>${workflowTemplates.map((item) => `<option value="${item.id}" ${Number(source?.id) === Number(item.id) ? 'selected' : ''}>Duplicate ${html(item.name)}</option>`).join('')}</select><div class="inline-help">Duplicating copies the phase sequence and configuration, but not Job history.</div></div>` : ''}
        </div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveWorkflowBtn">${existing && !duplicate ? 'Save Changes' : duplicate ? 'Duplicate Workflow' : 'Create Workflow'}</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));

        const nameInput = $('#workflowName');
        const codeInput = $('#workflowCode');
        let codeTouched = Boolean(startingCode);
        codeInput.addEventListener('input', () => { codeTouched = true; codeInput.value = codeInput.value.toUpperCase().replace(/[^A-Z0-9_-]/g, ''); });
        nameInput.addEventListener('input', () => {
            if (!codeTouched || !codeInput.value) codeInput.value = workflowCode(nameInput.value);
        });

        $('#saveWorkflowBtn').addEventListener('click', async () => {
            const button = $('#saveWorkflowBtn');
            const payload = {
                name: nameInput.value.trim(),
                code: codeInput.value.trim().toUpperCase(),
                description: $('#workflowDescription').value.trim() || null,
                is_active: existing && !duplicate ? $('#workflowActive').checked : true,
                base_workflow_id: (!existing || duplicate) && $('#workflowBase').value ? Number($('#workflowBase').value) : null,
            };
            if (!payload.name || !payload.code) {
                showToast('Workflow name and code are required.');
                return;
            }

            button.disabled = true;
            const label = button.textContent;
            button.textContent = 'Saving…';
            try {
                const result = await api(existing && !duplicate ? `/flowtrack/template-api/workflows/${existing.id}` : '/flowtrack/template-api/workflows', {
                    method: existing && !duplicate ? 'PUT' : 'POST',
                    body: payload,
                });
                syncWorkflow(result.workflow);
                closeAll();
                renderWorkflowSetup();
                showToast(result.message || 'Workflow saved successfully.');
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = label;
            }
        });
    }

    function phasePayload(phase, overrides = {}) {
        return {
            name: phase.name,
            short_name: phase.shortName || phase.short || phase.name,
            task_pack_id: phase.taskPackId || null,
            document_category_id: phase.documentCategoryId || null,
            allow_job_start: Boolean(phase.allowStart),
            is_skippable: Boolean(phase.skippable),
            auto_advance_on_ready: Boolean(phase.autoAdvance),
            is_active: phase.active !== false,
            entry_condition: phase.entry || null,
            exit_condition: phase.exit || null,
            ...overrides,
        };
    }

    async function savePhaseConfiguration(workflow, phase, overrides, successMessage) {
        const result = await api(`/flowtrack/template-api/workflow-phases/${phase.id}`, {
            method: 'PUT',
            body: phasePayload(phase, overrides),
        });
        syncWorkflow(result.workflow);
        renderWorkflowSetup();
        showToast(successMessage || result.message || 'Workflow phase updated.');
    }

    renderWorkflowSetup = function () {
        if (!workflowTemplates.length) {
            $('#page-workflowsetup').innerHTML = `<div class="page-head"><div><h1>Workflow Setup</h1><p>Define phase sequence, allowed starting stages, Task Packs, documents and skip rules</p></div><div class="actions"><button class="primary" id="addWorkflow">＋ New Workflow</button></div></div><div class="card empty-state">No workflow templates have been configured.</div>`;
            $('#addWorkflow').addEventListener('click', () => openWorkflowModal());
            return;
        }

        let selected = getWorkflow(Number(selectedWorkflowId));
        if (!selected || !workflowTemplates.some((item) => Number(item.id) === Number(selected.id))) {
            selected = workflowTemplates[0];
            selectedWorkflowId = selected.id;
        }
        const phases = selected.phases || [];

        $('#page-workflowsetup').innerHTML = `<div class="page-head"><div><h1>Workflow Setup</h1><p>Define phase sequence, allowed starting stages, Task Packs, documents and skip rules</p></div><div class="actions"><button class="ghost" id="cloneWorkflow">Duplicate</button><button class="primary" id="addWorkflow">＋ New Workflow</button></div></div>
        <div class="config-summary"><div class="card config-stat"><span>Active Templates</span><b>${workflowTemplates.filter((workflow) => workflow.active).length}</b></div><div class="card config-stat"><span>Phases in Selected Workflow</span><b>${phases.length}</b></div><div class="card config-stat"><span>Allowed Starting Stages</span><b>${phases.filter((phase) => phase.allowStart && phase.active !== false).length}</b></div><div class="card config-stat"><span>Automatic Transitions</span><b>${phases.filter((phase) => phase.autoAdvance && phase.active !== false).length}</b></div></div>
        <div class="workflow-layout"><div class="card workflow-list"><div class="sidebar-section" style="padding-top:8px">Workflow Templates</div>${workflowTemplates.map((workflow) => `<button class="${Number(workflow.id) === Number(selectedWorkflowId) ? 'active' : ''}" data-workflow-id="${workflow.id}"><b>${html(workflow.name)}</b><small>${workflow.phases.length} phases · ${workflow.active ? 'Active' : 'Inactive'}</small></button>`).join('')}</div>
        <div class="workflow-editor"><div class="card section-card"><div class="section-head"><div><h3>${html(selected.name)}</h3><div class="small muted">${html(selected.description || '')}</div></div><div class="workflow-toolbar"><button class="ghost" id="editWorkflowInfo">Edit Details</button><button class="primary" id="addWorkflowPhase">＋ Add Phase</button></div></div>
        <div class="source-note"><b>Starting-stage rule</b><div class="small muted" style="margin-top:4px">Only phases marked “Allow Job Start” appear in the New Job form. Earlier phases are recorded as skipped, completed outside the system, or migrated.</div></div>
        <div class="table-wrap" style="margin-top:14px"><table class="phase-config-table"><thead><tr><th>#</th><th>Phase</th><th>Task Pack</th><th>Allow Job Start</th><th>Can Skip</th><th>Auto Move</th><th>Required Document</th><th>Entry / Exit</th><th>Actions</th></tr></thead><tbody>${phases.map((phase, index) => `<tr style="${phase.active === false ? 'opacity:.58' : ''}"><td><div class="sequence-controls"><button data-phase-up="${index}" ${index === 0 ? 'disabled' : ''}>↑</button><button data-phase-down="${index}" ${index === phases.length - 1 ? 'disabled' : ''}>↓</button></div></td><td><b>${html(phase.name)}</b><div class="small muted">Stage ${index + 1}${phase.active === false ? ' · Disabled' : ''}</div></td><td>${html(phase.taskPack || 'No Task Pack')}</td><td><label class="toggle-chip"><input type="checkbox" data-toggle-start="${index}" ${phase.allowStart ? 'checked' : ''} ${phase.active === false ? 'disabled' : ''}> ${phase.allowStart ? 'Allowed' : 'Not allowed'}</label></td><td><label class="toggle-chip"><input type="checkbox" data-toggle-skip="${index}" ${phase.skippable ? 'checked' : ''} ${phase.active === false ? 'disabled' : ''}> ${phase.skippable ? 'Yes' : 'No'}</label></td><td>${phase.autoAdvance ? '<span class="badge b-blue">Automatic</span>' : '<span class="badge b-gray">Manual</span>'}</td><td>${html(phase.document || '—')}</td><td><div><b>In:</b> ${html(phase.entry || '—')}</div><div class="small muted"><b>Out:</b> ${html(phase.exit || '—')}</div></td><td><div class="row-actions"><button class="mini-btn" data-edit-phase="${index}">Edit</button><button class="mini-btn" data-delete-phase="${index}">Remove</button></div></td></tr>`).join('') || '<tr><td colspan="10"><div class="empty-state">No phases have been configured for this workflow.</div></td></tr>'}</tbody></table></div></div></div></div>`;

        $$('[data-workflow-id]').forEach((button) => button.addEventListener('click', () => {
            selectedWorkflowId = Number(button.dataset.workflowId);
            renderWorkflowSetup();
        }));
        $$('[data-toggle-start]').forEach((checkbox) => checkbox.addEventListener('change', async () => {
            const phase = phases[Number(checkbox.dataset.toggleStart)];
            checkbox.disabled = true;
            try {
                await savePhaseConfiguration(selected, phase, { allow_job_start: checkbox.checked }, 'Allowed starting stages updated.');
            } catch (error) {
                checkbox.checked = !checkbox.checked;
                checkbox.disabled = false;
                showToast(error.message);
            }
        }));
        $$('[data-toggle-skip]').forEach((checkbox) => checkbox.addEventListener('change', async () => {
            const phase = phases[Number(checkbox.dataset.toggleSkip)];
            checkbox.disabled = true;
            try {
                await savePhaseConfiguration(selected, phase, { is_skippable: checkbox.checked }, 'Phase skip rule updated.');
            } catch (error) {
                checkbox.checked = !checkbox.checked;
                checkbox.disabled = false;
                showToast(error.message);
            }
        }));
        const reorder = async (index, direction) => {
            const reordered = flowtrackToArray(phases);
            const target = index + direction;
            if (target < 0 || target >= reordered.length) return;
            [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
            try {
                const result = await api(`/flowtrack/template-api/workflows/${selected.id}/reorder`, {
                    method: 'PATCH',
                    body: { phase_ids: reordered.map((phase) => phase.id) },
                });
                syncWorkflow(result.workflow);
                renderWorkflowSetup();
                showToast(result.message || 'Workflow sequence updated.');
            } catch (error) {
                showToast(error.message);
            }
        };
        $$('[data-phase-up]').forEach((button) => button.addEventListener('click', () => reorder(Number(button.dataset.phaseUp), -1)));
        $$('[data-phase-down]').forEach((button) => button.addEventListener('click', () => reorder(Number(button.dataset.phaseDown), 1)));
        $$('[data-edit-phase]').forEach((button) => button.addEventListener('click', () => openPhaseModal(selected, Number(button.dataset.editPhase))));
        $$('[data-delete-phase]').forEach((button) => button.addEventListener('click', async () => {
            const phase = phases[Number(button.dataset.deletePhase)];
            if (!window.confirm(`Remove “${phase.name}” from this workflow?`)) return;
            button.disabled = true;
            try {
                const result = await api(`/flowtrack/template-api/workflow-phases/${phase.id}`, { method: 'DELETE' });
                syncWorkflow(result.workflow);
                renderWorkflowSetup();
                showToast(result.message || 'Workflow phase removed.');
            } catch (error) {
                button.disabled = false;
                showToast(error.message);
            }
        }));
        $('#addWorkflowPhase').addEventListener('click', () => openPhaseModal(selected, -1));
        $('#editWorkflowInfo').addEventListener('click', () => openWorkflowModal(selected));
        $('#addWorkflow').addEventListener('click', () => openWorkflowModal());
        $('#cloneWorkflow').addEventListener('click', () => openWorkflowModal(selected, true));
    };

    openPhaseModal = function (workflow, index) {
        const existing = index >= 0 ? workflow.phases[index] : {
            name: 'New Phase', short: 'New', taskPackId: null, documentCategoryId: null,
            allowStart: true, skippable: true, autoAdvance: false, active: true,
            entry: 'Previous phase complete', exit: 'Required work complete', document: '',
        };
        const activePacks = taskPacks.filter((pack) => pack.active !== false);

        $('#jobModal').innerHTML = `<div class="modal-head"><h2>${index >= 0 ? 'Edit' : 'Add'} Workflow Phase</h2><button class="close-btn" data-close>×</button></div><div class="modal-body"><div class="form-grid">
        <div class="field"><label>Phase name *</label><input id="phaseName" value="${html(existing.name)}"></div>
        <div class="field"><label>Short label *</label><input id="phaseShortName" value="${html(existing.shortName || existing.short || '')}"></div>
        <div class="field"><label>Task Pack</label><select id="phaseTaskPack"><option value="">No Task Pack</option>${activePacks.map((pack) => `<option value="${pack.id}" ${Number(pack.id) === Number(existing.taskPackId) ? 'selected' : ''}>${html(pack.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Required document</label><select id="phaseDocument"><option value="">None</option>${activeMaster('documentCategories').map((document) => `<option value="${document.id}" ${Number(document.id) === Number(existing.documentCategoryId) ? 'selected' : ''}>${html(document.name)}</option>`).join('')}</select></div>
        <div class="field full"><label>Entry rule</label><input id="phaseEntry" value="${html(existing.entry || '')}"></div>
        <div class="field full"><label>Exit control</label><input id="phaseExit" value="${html(existing.exit || '')}"></div></div>
        <label class="check-row"><input id="phaseAllowStart" type="checkbox" ${existing.allowStart ? 'checked' : ''}> <span>Allow users to create a Job starting from this phase</span></label>
        <label class="check-row"><input id="phaseCanSkip" type="checkbox" ${existing.skippable ? 'checked' : ''}> <span>Allow this phase to be skipped during normal progression</span></label>
        <label class="check-row"><input id="phaseAutoAdvance" type="checkbox" ${existing.autoAdvance ? 'checked' : ''}> <span>Automatically move the Job when all task, document and blocker gates are ready</span></label>
        <label class="check-row"><input id="phaseActive" type="checkbox" ${existing.active !== false ? 'checked' : ''}> <span>Phase active</span></label></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="savePhase">Save Phase</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        $('#savePhase').addEventListener('click', async () => {
            const button = $('#savePhase');
            const payload = {
                name: $('#phaseName').value.trim(),
                short_name: $('#phaseShortName').value.trim(),
                task_pack_id: $('#phaseTaskPack').value ? Number($('#phaseTaskPack').value) : null,
                document_category_id: $('#phaseDocument').value ? Number($('#phaseDocument').value) : null,
                allow_job_start: $('#phaseAllowStart').checked,
                is_skippable: $('#phaseCanSkip').checked,
                auto_advance_on_ready: $('#phaseAutoAdvance').checked,
                is_active: $('#phaseActive').checked,
                entry_condition: $('#phaseEntry').value.trim() || null,
                exit_condition: $('#phaseExit').value.trim() || null,
            };
            if (!payload.name || !payload.short_name) {
                showToast('Phase name and short label are required.');
                return;
            }

            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const result = await api(index >= 0 ? `/flowtrack/template-api/workflow-phases/${existing.id}` : `/flowtrack/template-api/workflows/${workflow.id}/phases`, {
                    method: index >= 0 ? 'PUT' : 'POST',
                    body: payload,
                });
                syncWorkflow(result.workflow);
                closeAll();
                renderWorkflowSetup();
                showToast(result.message || 'Workflow phase saved.');
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Save Phase';
            }
        });
    };


    function injectAccessMatrixStyles() {
        if (document.getElementById('flowtrack-access-matrix-styles')) return;
        
    /* CSS externalized: style. */
    }

    function scopeLabel(code) {
        return accessCatalog.scopes.find((scope) => scope.code === code)?.label || code || 'None';
    }

    function accessRoleById(id) {
        return accessRoles.find((role) => Number(role.id) === Number(id));
    }

    function upsertAccessRole(role) {
        const index = accessRoles.findIndex((item) => Number(item.id) === Number(role.id));
        if (index >= 0) accessRoles.splice(index, 1, role);
        else accessRoles.push(role);
        accessRoles.sort((a, b) => Number(b.system) - Number(a.system) || String(a.name).localeCompare(String(b.name)));
        selectedAccessRoleId = role.id;
    }

    function roleInitials(name) {
        return String(name || 'R').split(/\s+/).filter(Boolean).map((part) => part[0]).slice(0, 2).join('').toUpperCase();
    }

    function roleCardsHtml(list) {
        return list.map((role) => {
            const protectedRole = String(role.code).toUpperCase() === 'SUPER_ADMIN';
            const canDelete = (isFlowAdmin || isFlowSuperAdmin) && !protectedRole && Number(role.members || 0) === 0;
            return `<div class="card access-role-card ${Number(role.id) === Number(selectedAccessRoleId) ? 'selected' : ''}" data-access-role-card="${role.id}">
                <div class="access-role-top"><div style="display:flex;gap:10px"><div class="access-role-icon">${html(roleInitials(role.name))}</div><div><div class="access-role-name">${html(role.name)}</div><div class="small muted">${html(role.code)}</div></div></div><span class="access-status ${role.active ? 'green' : 'gray'}">${protectedRole ? 'Super Admin' : (role.active ? (role.system ? 'System' : 'Active') : 'Inactive')}</span></div>
                <div class="access-role-desc">${html(role.description || 'Reusable workspace access policy.')}</div>
                <div class="access-role-meta"><span class="access-tag">${Number(role.members || 0)} users</span><span class="access-tag">${html(scopeLabel(role.defaultScope))}</span>${isAdministratorRole(role) ? '<span class="access-tag">Unrestricted</span>' : ''}</div>
                <div class="access-role-actions"><button class="ghost" data-edit-access-role="${role.id}">Details</button><button class="secondary" data-open-access-matrix="${role.id}">Permissions</button>${canDelete ? `<button class="danger-btn" data-delete-access-role="${role.id}">Delete</button>` : ''}</div>
            </div>`;
        }).join('');
    }

    function renderAccessRoles() {
        injectAccessMatrixStyles();
        const element = $('#setupContent');
        element.innerHTML = `<div class="section-head"><div><h3>Roles & Access Policies</h3><div class="small muted">Create reusable roles and define their default record scope.</div></div>${canManageAccess ? '<button class="primary" id="newAccessRole">＋ New Role</button>' : ''}</div>
        <div class="access-toolbar"><div class="field grow"><label>Search roles</label><input id="accessRoleSearch" placeholder="Role name or code"></div><div class="field"><label>Scope</label><select id="accessRoleScope"><option value="">All scopes</option>${accessCatalog.scopes.filter((scope) => scope.code !== 'none').map((scope) => `<option value="${scope.code}">${html(scope.label)}</option>`).join('')}</select></div></div>
        <div id="accessRoleGrid" class="access-role-grid">${roleCardsHtml(accessRoles)}</div>
        <div style="height:16px"></div><div class="card section-card"><div class="section-head"><h3>Permission model</h3></div><div class="grid-3" style="margin-bottom:0"><div class="card" style="padding:15px"><b>Role permission</b><div class="small muted" style="margin-top:6px;line-height:1.5">Controls module actions such as View, Edit, Export and Manage.</div></div><div class="card" style="padding:15px"><b>Record scope</b><div class="small muted" style="margin-top:6px;line-height:1.5">Limits records to own work, assigned Jobs, selected clients, department or all records.</div></div><div class="card" style="padding:15px"><b>Administrator control</b><div class="small muted" style="margin-top:6px;line-height:1.5">Admin and Super Admin roles always retain every action, every record and every sensitive field.</div></div></div></div>`;

        const filter = () => {
            const query = $('#accessRoleSearch').value.trim().toLowerCase();
            const scope = $('#accessRoleScope').value;
            const list = accessRoles.filter((role) => (!query || `${role.name} ${role.code}`.toLowerCase().includes(query)) && (!scope || role.defaultScope === scope));
            $('#accessRoleGrid').innerHTML = roleCardsHtml(list) || '<div class="card access-empty">No matching roles.</div>';
            bindAccessRoleCards();
        };
        $('#accessRoleSearch').addEventListener('input', filter);
        $('#accessRoleScope').addEventListener('change', filter);
        $('#newAccessRole')?.addEventListener('click', () => openAccessRoleModal());
        bindAccessRoleCards();
    }

    function bindAccessRoleCards() {
        $$('[data-access-role-card]').forEach((card) => card.addEventListener('click', (event) => {
            if (event.target.closest('button')) return;
            selectedAccessRoleId = Number(card.dataset.accessRoleCard);
            renderAccessRoles();
        }));
        $$('[data-edit-access-role]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            openAccessRoleModal(accessRoleById(button.dataset.editAccessRole));
        }));
        $$('[data-open-access-matrix]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            selectedAccessRoleId = Number(button.dataset.openAccessMatrix);
            renderSetupContent('permissions');
            $$('.setup-menu [data-setup]').forEach((item) => item.classList.toggle('active', item.dataset.setup === 'permissions'));
        }));
        $$('[data-delete-access-role]').forEach((button) => button.addEventListener('click', async (event) => {
            event.stopPropagation();
            const role = accessRoleById(button.dataset.deleteAccessRole);
            if (!role || !confirm(`Delete role “${role.name}”?`)) return;
            try {
                await api(`/flowtrack/template-api/access/roles/${role.id}`, { method: 'DELETE' });
                accessRoles = accessRoles.filter((item) => Number(item.id) !== Number(role.id));
                selectedAccessRoleId = accessRoles[0]?.id || null;
                renderAccessRoles();
                showToast('Role deleted successfully.');
            } catch (error) {
                showToast(error.message);
            }
        }));
    }

    function suggestedRoleCode(name) {
        return String(name || '').normalize('NFKD').replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '').toUpperCase().slice(0, 80);
    }

    function openAccessRoleModal(existing = null) {
        if (!canManageAccess) return showToast('You do not have permission to manage roles.');
        const role = existing || { name: '', code: '', description: '', defaultScope: 'assigned_jobs', active: true, system: false };
        const administratorRole = isAdministratorRole(role);
        const superAdminRole = String(role.code).toUpperCase() === 'SUPER_ADMIN';
        if (superAdminRole && !isFlowSuperAdmin) return showToast('Only the Super Admin can edit this role.');
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${existing ? 'Edit Access Role' : 'Create Access Role'}</h2><div class="small muted" style="margin-top:5px">Role details and default scope follow the supplied access-control design.</div></div><button class="close-btn" data-close>×</button></div>
        <div class="modal-body"><div class="form-grid access-form-stack"><div class="field"><label>Role name *</label><input id="accessRoleName" value="${html(role.name)}" placeholder="e.g. Quality Inspector"></div><div class="field"><label>Role code *</label><input class="access-code-input" id="accessRoleCode" value="${html(role.code)}" placeholder="QUALITY_INSPECTOR" ${role.system ? 'readonly' : ''}></div><div class="field full"><label>Description</label><textarea id="accessRoleDescription" rows="3" placeholder="What this role is responsible for">${html(role.description)}</textarea></div><div class="field"><label>Default record scope</label><select id="accessRoleDefaultScope" ${administratorRole || !canManageAccess ? 'disabled' : ''}>${accessCatalog.scopes.filter((scope) => scope.code !== 'none').map((scope) => `<option value="${scope.code}" ${(administratorRole ? scope.code === 'all_records' : scope.code === role.defaultScope) ? 'selected' : ''}>${html(scope.label)}</option>`).join('')}</select></div><div class="field"><label>Status</label><select id="accessRoleActive" ${administratorRole || !canManageAccess ? 'disabled' : ''}><option value="1" ${role.active ? 'selected' : ''}>Active</option><option value="0" ${!role.active ? 'selected' : ''}>Inactive</option></select></div>${existing ? '' : `<div class="field full"><label>Copy permissions from</label><select id="accessRoleCopy"><option value="">Start empty</option>${accessRoles.map((item) => `<option value="${item.id}">${html(item.name)}</option>`).join('')}</select></div>`}</div>${administratorRole ? '<div class="access-info"><b>Unrestricted administrator role</b><div class="small" style="margin-top:4px">All modules, actions, records and sensitive fields remain enabled automatically.</div></div>' : (role.system ? '<div class="access-form-note">This is a system role. Its code remains fixed so backend authorization mappings stay stable.</div>' : '')}</div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveAccessRole">${existing ? 'Save Role' : 'Create Role'}</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        let codeEdited = Boolean(existing);
        $('#accessRoleCode')?.addEventListener('input', () => { codeEdited = true; $('#accessRoleCode').value = suggestedRoleCode($('#accessRoleCode').value); });
        $('#accessRoleName').addEventListener('input', () => {
            if (!codeEdited && !role.system) $('#accessRoleCode').value = suggestedRoleCode($('#accessRoleName').value);
        });
        $('#saveAccessRole').addEventListener('click', async () => {
            const payload = {
                name: $('#accessRoleName').value.trim(),
                code: suggestedRoleCode($('#accessRoleCode').value),
                description: $('#accessRoleDescription').value.trim() || null,
                default_scope: administratorRole ? 'all_records' : $('#accessRoleDefaultScope').value,
                is_active: administratorRole || $('#accessRoleActive').value === '1',
                ...(existing ? {} : { copy_from_role_id: $('#accessRoleCopy').value ? Number($('#accessRoleCopy').value) : null }),
            };
            if (!payload.name || !payload.code) return showToast('Role name and role code are required.');
            const button = $('#saveAccessRole');
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const result = await api(existing ? `/flowtrack/template-api/access/roles/${existing.id}` : '/flowtrack/template-api/access/roles', {
                    method: existing ? 'PUT' : 'POST', body: payload,
                });
                upsertAccessRole(result.role);
                closeAll();
                renderAccessRoles();
                showToast(existing ? 'Role updated successfully.' : 'Role created successfully.');
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = existing ? 'Save Role' : 'Create Role';
            }
        });
    }

    function matrixRowsHtml(role) {
        const administratorRole = isAdministratorRole(role);
        const disabled = administratorRole || !canManageAccess;
        return accessCatalog.modules.map((module) => {
            const row = administratorRole ? { actions: accessCatalog.actions.map((action) => action.code), scope: 'all_records' } : (role.matrix?.[module.code] || { actions: [], scope: role.defaultScope || 'assigned_jobs' });
            return `<tr data-access-matrix-row data-group="${html(module.group)}" data-name="${html(module.name.toLowerCase())}"><td><div class="access-module-title">${html(module.name)}</div><div class="access-module-sub">${html(module.group)}</div></td>${accessCatalog.actions.map((action) => `<td><input class="access-perm-check" type="checkbox" data-access-module="${module.code}" data-access-action="${action.code}" ${(row.actions || []).includes(action.code) ? 'checked' : ''} ${disabled ? 'disabled' : ''}></td>`).join('')}<td><select class="access-scope-select" data-access-scope="${module.code}" ${disabled ? 'disabled' : ''}>${accessCatalog.scopes.map((scope) => `<option value="${scope.code}" ${scope.code === row.scope ? 'selected' : ''}>${html(scope.label)}</option>`).join('')}</select></td></tr>`;
        }).join('');
    }

    function renderPermissionMatrix() {
        injectAccessMatrixStyles();
        const element = $('#setupContent');
        const role = accessRoleById(selectedAccessRoleId) || accessRoles[0];
        if (!role) {
            element.innerHTML = '<div class="card access-empty">Create a role before configuring permissions.</div>';
            return;
        }
        selectedAccessRoleId = role.id;
        const administratorRole = isAdministratorRole(role);
        const locked = administratorRole || !canManageAccess;
        const groups = flowtrackToArray(new Set(flowtrackToArray(accessCatalog.modules).map((module) => module.group)));
        element.innerHTML = `<div class="section-head"><div><h3>Permission Matrix</h3><div class="small muted">Set module actions, record scope and sensitive-field visibility for each role.</div></div><div class="actions"><button class="ghost" id="resetAccessMatrix">Reset Unsaved</button>${canManageAccess ? '<button class="primary" id="saveAccessMatrix">Save Permissions</button>' : ''}</div></div>
        <div class="access-toolbar"><div class="field"><label>Role</label><select id="accessMatrixRole">${accessRoles.filter((item) => item.active || Number(item.id) === Number(role.id)).map((item) => `<option value="${item.id}" ${Number(item.id) === Number(role.id) ? 'selected' : ''}>${html(item.name)}</option>`).join('')}</select></div><div class="field"><label>Module group</label><select id="accessModuleGroup"><option value="">All groups</option>${groups.map((group) => `<option>${html(group)}</option>`).join('')}</select></div><div class="field grow"><label>Find module</label><input id="accessMatrixSearch" placeholder="Search module"></div>${!locked ? '<div><label class="small muted">Bulk action</label><div class="access-segmented"><button id="accessGrantView">Grant view</button><button id="accessClearVisible">Clear</button></div></div>' : ''}</div>
        <div class="access-info"><b>Editing: ${html(role.name)}</b><div class="small" style="margin-top:4px">${administratorRole ? 'Administrator roles are permanently unrestricted and cannot lose access.' : (!canManageAccess ? 'This permission matrix is read only for your account.' : `${html(role.description || 'Reusable workspace role.')} Default scope: <strong>${html(scopeLabel(role.defaultScope))}</strong>.`)}</div></div>
        <div class="access-matrix-wrap"><table class="access-matrix"><thead><tr><th>Module</th>${accessCatalog.actions.map((action) => `<th>${html(action.label)}</th>`).join('')}<th>Record scope</th></tr></thead><tbody>${matrixRowsHtml(role)}</tbody></table></div>
        <div style="height:16px"></div><div class="card section-card"><div class="section-head"><h3>Sensitive field visibility</h3><span class="small muted">Role-specific field controls</span></div><div class="access-sensitive-grid">${accessCatalog.sensitiveFields.map((field) => `<div class="access-sensitive-item"><div><strong>${html(field.name)}</strong><div class="small muted">${html(field.group)}</div></div><label class="access-switch"><input class="access-sensitive-toggle" type="checkbox" value="${field.code}" ${administratorRole || (role.sensitiveFields || []).includes(field.code) ? 'checked' : ''} ${locked ? 'disabled' : ''}><span class="access-slider"></span></label></div>`).join('')}</div></div>`;

        const filterRows = () => {
            const group = $('#accessModuleGroup').value;
            const query = $('#accessMatrixSearch').value.trim().toLowerCase();
            $$('[data-access-matrix-row]').forEach((row) => {
                row.style.display = (!group || row.dataset.group === group) && (!query || row.dataset.name.includes(query)) ? '' : 'none';
            });
        };
        $('#accessMatrixRole').addEventListener('change', () => { selectedAccessRoleId = Number($('#accessMatrixRole').value); renderPermissionMatrix(); });
        $('#accessModuleGroup').addEventListener('change', filterRows);
        $('#accessMatrixSearch').addEventListener('input', filterRows);
        $('#accessGrantView')?.addEventListener('click', () => {
            $$('[data-access-matrix-row]').filter((row) => row.style.display !== 'none').forEach((row) => row.querySelector('[data-access-action="view"]').checked = true);
        });
        $('#accessClearVisible')?.addEventListener('click', () => {
            $$('[data-access-matrix-row]').filter((row) => row.style.display !== 'none').forEach((row) => row.querySelectorAll('.access-perm-check').forEach((checkbox) => checkbox.checked = false));
        });
        $('#resetAccessMatrix').addEventListener('click', renderPermissionMatrix);
        $('#saveAccessMatrix')?.addEventListener('click', async () => {
            const modules = accessCatalog.modules.map((module) => ({
                module_code: module.code,
                scope: document.querySelector(`[data-access-scope="${module.code}"]`).value,
                actions: flowtrackToArray(document.querySelectorAll(`[data-access-module="${module.code}"]:checked`)).map((checkbox) => checkbox.dataset.accessAction),
            }));
            const sensitive_fields = $$('.access-sensitive-toggle:checked').map((checkbox) => checkbox.value);
            const button = $('#saveAccessMatrix');
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const result = await api(`/flowtrack/template-api/access/roles/${role.id}/matrix`, { method: 'PUT', body: { modules, sensitive_fields } });
                upsertAccessRole(result.role);
                await loadBootstrap();
                renderPermissionMatrix();
                applyActionPermissions();
                showToast('Permission matrix saved successfully.');
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Save Permissions';
            }
        });
    }

    function renderRoleAssignments() {
        injectAccessMatrixStyles();
        const element = $('#setupContent');
        element.innerHTML = `<div class="section-head"><div><h3>Users & Role Assignments</h3><div class="small muted">Only Admin and Super Admin can create or delete users. Permission-based managers can update allowed assignments.</div></div>${canCreateUsers ? '<button class="primary" id="addAccessUser">＋ Add User</button>' : ''}</div><div class="card table-wrap"><table class="data-table access-member-table"><thead><tr><th>User</th><th>Department</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody>${accessMembers.map((member) => {
            const targetIsSuper = member.isSuperAdmin || String(member.roleCode).toUpperCase() === 'SUPER_ADMIN';
            const actorCanEdit = !targetIsSuper || isFlowSuperAdmin;
            const roleOptions = availableRolesForActor(member.roleId);
            return `<tr><td data-label="User"><div class="person"><span class="avatar">${html(member.initials || roleInitials(member.name))}</span><div><b>${html(member.name)}</b><div class="small muted">${html(member.email)}</div>${member.jobTitle ? `<div class="small muted">${html(member.jobTitle)}</div>` : ''}</div></div></td><td data-label="Department">${html(member.department)}</td><td data-label="Role"><select data-member-role="${member.id}" ${actorCanEdit ? '' : 'disabled'}>${roleOptions.map((role) => `<option value="${role.id}" ${Number(role.id) === Number(member.roleId) ? 'selected' : ''}>${html(role.name)}</option>`).join('')}</select></td><td data-label="Status"><select data-member-status="${member.id}" ${actorCanEdit ? '' : 'disabled'}><option value="active" ${member.status === 'active' ? 'selected' : ''}>Active</option><option value="pending" ${member.status === 'pending' ? 'selected' : ''}>Pending</option><option value="inactive" ${member.status === 'inactive' ? 'selected' : ''}>Inactive</option></select></td><td data-label="Actions"><div class="row-actions">${actorCanEdit && canManageAccess ? `<button class="mini-btn" data-save-member-role="${member.id}">Save</button>${(isFlowAdmin || isFlowSuperAdmin) ? `<button class="mini-btn" data-edit-access-user="${member.id}">Edit</button>` : ''}` : ''}${actorCanEdit && canDeleteUsers && Number(member.userId) !== Number(currentUser?.id) ? `<button class="danger-btn" data-delete-access-user="${member.id}">Delete</button>` : ''}</div></td></tr>`;
        }).join('') || '<tr><td colspan="5"><div class="access-empty">No workspace members found.</div></td></tr>'}</tbody></table></div>`;
        $('#addAccessUser')?.addEventListener('click', () => openAccessUserModal());
        $$('[data-edit-access-user]').forEach((button) => button.addEventListener('click', () => openAccessUserModal(accessMembers.find((member) => Number(member.id) === Number(button.dataset.editAccessUser)))));
        $$('[data-save-member-role]').forEach((button) => button.addEventListener('click', async () => {
            const membershipId = Number(button.dataset.saveMemberRole);
            const member = accessMembers.find((item) => Number(item.id) === membershipId);
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                await api(`/flowtrack/template-api/access/members/${membershipId}`, {
                    method: 'PUT', body: {
                        role_id: Number(document.querySelector(`[data-member-role="${membershipId}"]`).value),
                        department_id: member?.departmentId || null,
                        job_title: member?.jobTitle || null,
                        status: document.querySelector(`[data-member-status="${membershipId}"]`).value,
                    },
                });
                await loadBootstrap();
                renderRoleAssignments();
                showToast('User role assignment updated.');
            } catch (error) {
                showToast(error.message);
                button.disabled = false;
                button.textContent = 'Save';
            }
        }));
        $$('[data-delete-access-user]').forEach((button) => button.addEventListener('click', async () => {
            const membershipId = Number(button.dataset.deleteAccessUser);
            const member = accessMembers.find((item) => Number(item.id) === membershipId);
            if (!member || !confirm(`Delete user “${member.name}”? This removes their workspace account.`)) return;
            try {
                await api(`/flowtrack/template-api/access/members/${membershipId}`, { method: 'DELETE' });
                await loadBootstrap();
                renderRoleAssignments();
                showToast('User deleted successfully.');
            } catch (error) {
                showToast(error.message);
            }
        }));
    }

    function openAccessUserModal(existing = null) {
        if (!canCreateUsers && !existing) return showToast('Only Admin and Super Admin can add users.');
        if (existing && !isFlowAdmin && !isFlowSuperAdmin) return showToast('Only Admin and Super Admin can edit user login details.');
        if (existing?.isSuperAdmin && !isFlowSuperAdmin) return showToast('Only the Super Admin can edit this account.');
        const departments = activeMaster('departments');
        const roles = availableRolesForActor(existing?.roleId);
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${existing ? 'Edit User' : 'Add User'}</h2><div class="small muted" style="margin-top:5px">Create the login and assign its workspace role.</div></div><button class="close-btn" data-close>×</button></div>
        <div class="modal-body"><form id="accessUserForm" autocomplete="off" onsubmit="return false"><div class="form-grid access-form-stack"><div class="field"><label>Full name *</label><input id="accessUserName" autocomplete="off" value="${html(existing?.name || '')}"></div><div class="field"><label>Email *</label><input id="accessUserEmail" type="email" autocomplete="off" autocapitalize="none" spellcheck="false" value="${html(existing?.email || '')}"></div><div class="field"><label>Role *</label><select id="accessUserRole">${roles.map((role) => `<option value="${role.id}" ${Number(role.id) === Number(existing?.roleId) ? 'selected' : ''}>${html(role.name)}</option>`).join('')}</select></div><div class="field"><label>Department</label><select id="accessUserDepartment"><option value="">Unassigned</option>${departments.map((department) => `<option value="${department.id}" ${Number(department.id) === Number(existing?.departmentId) ? 'selected' : ''}>${html(department.name)}</option>`).join('')}</select></div><div class="field full"><label>Job title</label><input id="accessUserJobTitle" autocomplete="off" value="${html(existing?.jobTitle || '')}"></div><div class="field"><label>Status</label><select id="accessUserStatus"><option value="active" ${!existing || existing.status === 'active' ? 'selected' : ''}>Active</option><option value="pending" ${existing?.status === 'pending' ? 'selected' : ''}>Pending</option><option value="inactive" ${existing?.status === 'inactive' ? 'selected' : ''}>Inactive</option></select></div><div class="field"><label>Language</label><select id="accessUserLocale"><option value="en" ${!existing || existing.locale !== 'zh' ? 'selected' : ''}>English</option><option value="zh" ${existing?.locale === 'zh' ? 'selected' : ''}>中文</option></select></div><div class="field"><label>${existing ? 'New password' : 'Password *'}</label><input id="accessUserPassword" type="password" autocomplete="new-password"></div><div class="field"><label>${existing ? 'Confirm new password' : 'Confirm password *'}</label><input id="accessUserPasswordConfirmation" type="password" autocomplete="new-password"></div></div></form><div class="access-form-note">Use a strong password that meets the server policy. Password values are cleared immediately after submission and are never returned by the API.</div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveAccessUser">${existing ? 'Save User' : 'Create User'}</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        $('#saveAccessUser').addEventListener('click', async () => {
            const payload = {
                name: $('#accessUserName').value.trim(),
                email: $('#accessUserEmail').value.trim(),
                role_id: Number($('#accessUserRole').value),
                department_id: $('#accessUserDepartment').value ? Number($('#accessUserDepartment').value) : null,
                job_title: $('#accessUserJobTitle').value.trim() || null,
                status: $('#accessUserStatus').value,
                locale: $('#accessUserLocale').value,
                password: $('#accessUserPassword').value || null,
                password_confirmation: $('#accessUserPasswordConfirmation').value || null,
            };
            if (!payload.name || !payload.email || !payload.role_id) return showToast('Name, email and role are required.');
            if (!existing && !payload.password) return showToast('A password is required for a new user.');
            if (payload.password !== payload.password_confirmation) return showToast('Password confirmation does not match.');
            if (existing && !payload.password) {
                delete payload.password;
                delete payload.password_confirmation;
            }
            const button = $('#saveAccessUser');
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                await api(existing ? `/flowtrack/template-api/access/members/${existing.id}` : '/flowtrack/template-api/access/members', {
                    method: existing ? 'PUT' : 'POST', body: payload,
                });
                await loadBootstrap();
                closeAll();
                renderRoleAssignments();
                showToast(existing ? 'User updated successfully.' : 'User created successfully.');
            } catch (error) {
                showToast(error.message);
            } finally {
                payload.password = null;
                payload.password_confirmation = null;
                const passwordInput = $('#accessUserPassword');
                const confirmationInput = $('#accessUserPasswordConfirmation');
                if (passwordInput) passwordInput.value = '';
                if (confirmationInput) confirmationInput.value = '';
                button.disabled = false;
                button.textContent = existing ? 'Save User' : 'Create User';
            }
        });
    }

    const profileNotificationFields = [
        ['task_assignments', 'Task assignments'],
        ['due_date_reminders', 'Due date reminders'],
        ['mentions_comments', 'Mentions and comments'],
        ['workflow_updates', 'Workflow updates'],
        ['shipment_alerts', 'Shipment alerts'],
        ['payment_reminders', 'Payment reminders'],
    ];

    renderProfile = function () {
        const preferences = currentUser?.notificationPreferences || {};
        $('#page-profile').innerHTML = `<div class="page-head"><div><h1>User Profile</h1><p>Your real account information, workspace preferences and security settings</p></div></div>
        <div class="grid-2"><div class="card section-card"><div class="client-head" style="margin-bottom:20px"><div id="profileAvatar" class="avatar" style="width:62px;height:62px;font-size:18px">${html(currentUser?.initials || 'FT')}</div><div><h3 style="margin:0 0 4px">${html(currentUser?.name || '')}</h3><div class="muted">${html(currentUser?.role || 'Workspace member')}${currentUser?.department ? ` · ${html(currentUser.department)}` : ''}</div><div class="actions" style="margin-top:8px"><button class="link-btn" id="changeProfilePhoto" type="button">Change photo</button>${currentUser?.avatarUrl ? '<button class="link-btn" id="removeProfilePhoto" type="button" style="color:var(--red)">Remove photo</button>' : ''}</div><input id="profileAvatarInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden"></div></div>
        <div class="form-grid"><div class="field"><label>Full name *</label><input id="profileName" value="${html(currentUser?.name || '')}" autocomplete="name"></div><div class="field"><label>Phone</label><input id="profilePhone" value="${html(currentUser?.phone || '')}" autocomplete="tel"></div><div class="field"><label>Email *</label><input id="profileEmail" type="email" value="${html(currentUser?.email || '')}" autocomplete="email"></div><div class="field"><label>Preferred language</label><select id="profileLocale"><option value="en" ${currentUser?.locale === 'en' ? 'selected' : ''}>English</option><option value="zh" ${currentUser?.locale === 'zh' ? 'selected' : ''}>Simplified Chinese</option></select></div><div class="field"><label>Default landing page</label><select id="profileLanding"><option value="dashboard" ${currentUser?.defaultLandingPage === 'dashboard' ? 'selected' : ''}>Management Dashboard</option><option value="mywork" ${currentUser?.defaultLandingPage === 'mywork' ? 'selected' : ''}>My Work</option><option value="jobs" ${currentUser?.defaultLandingPage === 'jobs' ? 'selected' : ''}>Jobs</option><option value="clients" ${currentUser?.defaultLandingPage === 'clients' ? 'selected' : ''}>Clients</option><option value="board" ${currentUser?.defaultLandingPage === 'board' ? 'selected' : ''}>Workflow Board</option><option value="documents" ${currentUser?.defaultLandingPage === 'documents' ? 'selected' : ''}>Documents</option><option value="reports" ${currentUser?.defaultLandingPage === 'reports' ? 'selected' : ''}>Reports</option></select></div><div class="field"><label>Default task view</label><select id="profileTaskView"><option value="list" ${currentUser?.defaultTaskView === 'list' ? 'selected' : ''}>List</option><option value="board" ${currentUser?.defaultTaskView === 'board' ? 'selected' : ''}>Board</option></select></div></div><button class="primary" id="saveProfile" type="button">Save Profile</button></div>
        <div><div class="card section-card" style="margin-bottom:14px"><div class="section-head"><h3>Change Password</h3></div><div class="field"><label>Current password *</label><input id="profileCurrentPassword" type="password" autocomplete="current-password"></div><div class="field"><label>New password *</label><input id="profileNewPassword" type="password" autocomplete="new-password"></div><div class="field"><label>Confirm new password *</label><input id="profilePasswordConfirmation" type="password" autocomplete="new-password"></div><button class="secondary" id="changePass" type="button">Update Password</button></div><div class="card section-card"><div class="section-head"><h3>Notifications</h3></div>${profileNotificationFields.map(([key, label]) => `<label class="check-row"><input type="checkbox" data-profile-notification="${key}" ${preferences[key] ? 'checked' : ''}> <span>${label}</span></label>`).join('')}</div></div></div>`;

        applyCurrentUserIdentity();

        $('#saveProfile')?.addEventListener('click', async () => {
            const button = $('#saveProfile');
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const notificationPreferences = {};
                $$('[data-profile-notification]').forEach((input) => { notificationPreferences[input.dataset.profileNotification] = input.checked; });
                const payload = await api('/flowtrack/template-api/profile', {
                    method: 'PATCH',
                    body: {
                        name: $('#profileName').value.trim(),
                        phone: $('#profilePhone').value.trim() || null,
                        email: $('#profileEmail').value.trim(),
                        locale: $('#profileLocale').value,
                        default_landing_page: $('#profileLanding').value,
                        default_task_view: $('#profileTaskView').value,
                        notification_preferences: notificationPreferences,
                    },
                });
                acceptCurrentUser(payload.currentUser);
                showToast(payload.message || 'Profile updated successfully.');
                renderProfile();
            } catch (error) {
                showToast(error.message);
            } finally {
                if (document.body.contains(button)) {
                    button.disabled = false;
                    button.textContent = 'Save Profile';
                }
            }
        });

        $('#changePass')?.addEventListener('click', async () => {
            const button = $('#changePass');
            button.disabled = true;
            button.textContent = 'Updating…';
            try {
                const payload = await api('/flowtrack/template-api/profile/password', {
                    method: 'PUT',
                    body: {
                        current_password: $('#profileCurrentPassword').value,
                        password: $('#profileNewPassword').value,
                        password_confirmation: $('#profilePasswordConfirmation').value,
                    },
                });
                $('#profileCurrentPassword').value = '';
                $('#profileNewPassword').value = '';
                $('#profilePasswordConfirmation').value = '';
                showToast(payload.message || 'Password updated successfully.');
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Update Password';
            }
        });

        $('#changeProfilePhoto')?.addEventListener('click', () => $('#profileAvatarInput')?.click());
        $('#profileAvatarInput')?.addEventListener('change', async (event) => {
            const file = event.target.files?.[0];
            if (!file) return;
            const formData = new FormData();
            formData.append('avatar', file);
            try {
                const payload = await api('/flowtrack/template-api/profile/avatar', { method: 'POST', body: formData });
                acceptCurrentUser(payload.currentUser);
                showToast(payload.message || 'Profile photo updated successfully.');
                renderProfile();
            } catch (error) {
                showToast(error.message);
                event.target.value = '';
            }
        });

        $('#removeProfilePhoto')?.addEventListener('click', async () => {
            if (!window.confirm('Remove your profile photo?')) return;
            try {
                const payload = await api('/flowtrack/template-api/profile/avatar', { method: 'DELETE' });
                acceptCurrentUser(payload.currentUser);
                showToast(payload.message || 'Profile photo removed successfully.');
                renderProfile();
            } catch (error) {
                showToast(error.message);
            }
        });
    };

    function renderBrandingSettings() {
        const element = $('#setupContent');
        if (!element) return;
        if (!(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin)) {
            element.innerHTML = '<div class="card access-empty">Only Admin and Super Admin can manage system branding.</div>';
            return;
        }

        const logoUrl = String(flowtrackBranding.logoUrl || '').trim();
        const faviconUrl = String(flowtrackBranding.faviconUrl || '/favicon.ico').trim();
        const logoPreview = logoUrl && flowtrackSafeUrl(logoUrl)
            ? `<img src="${html(logoUrl)}" alt="Current system logo">`
            : '<span>FT</span>';
        const faviconPreview = faviconUrl && flowtrackSafeUrl(faviconUrl)
            ? `<img src="${html(faviconUrl)}" alt="Current favicon">`
            : '<span>FT</span>';

        element.innerHTML = `<div class="section-head"><div><h3>Logo &amp; Favicon</h3><div class="small muted">Upload the workspace branding used on the login page, sidebar and browser tab.</div></div></div>
            <div class="branding-grid">
                <div class="card branding-card">
                    <b>System logo</b>
                    <div class="branding-help">PNG, JPG or WebP · up to 4 MB. A square or compact transparent logo works best.</div>
                    <div class="branding-preview"><div class="branding-preview-logo">${logoPreview}</div></div>
                    <div class="branding-actions">
                        <button class="primary" id="uploadSystemLogo" type="button">Upload Logo</button>
                        ${flowtrackBranding.hasCustomLogo ? '<button class="ghost" id="resetSystemLogo" type="button">Reset Logo</button>' : ''}
                        <input id="systemLogoInput" class="hidden" type="file" accept="image/png,image/jpeg,image/webp">
                    </div>
                </div>
                <div class="card branding-card">
                    <b>Favicon</b>
                    <div class="branding-help">ICO, PNG, JPG or WebP · up to 1 MB. Recommended size: 32×32 or 64×64.</div>
                    <div class="branding-preview"><div class="branding-preview-favicon">${faviconPreview}</div></div>
                    <div class="branding-actions">
                        <button class="primary" id="uploadSystemFavicon" type="button">Upload Favicon</button>
                        ${flowtrackBranding.hasCustomFavicon ? '<button class="ghost" id="resetSystemFavicon" type="button">Reset Favicon</button>' : ''}
                        <input id="systemFaviconInput" class="hidden" type="file" accept=".ico,image/x-icon,image/png,image/jpeg,image/webp">
                    </div>
                </div>
            </div>`;

        const uploadBrandAsset = async (input, field, endpoint, button) => {
            const file = input?.files?.[0];
            if (!file) return;
            const form = new FormData();
            form.append(field, file);
            const originalText = button?.textContent || 'Upload';
            if (button) {
                button.disabled = true;
                button.textContent = 'Uploading…';
            }
            try {
                const payload = await api(endpoint, { method: 'POST', body: form });
                applyFlowtrackBranding(payload.branding || {});
                showToast(payload.message || 'Branding updated successfully.');
                renderBrandingSettings();
            } catch (error) {
                showToast(error.message || 'Unable to upload this branding file.');
                if (input) input.value = '';
                if (button) {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            }
        };

        $('#uploadSystemLogo')?.addEventListener('click', () => $('#systemLogoInput')?.click());
        $('#systemLogoInput')?.addEventListener('change', () => uploadBrandAsset($('#systemLogoInput'), 'logo', '/flowtrack/template-api/branding/logo', $('#uploadSystemLogo')));
        $('#uploadSystemFavicon')?.addEventListener('click', () => $('#systemFaviconInput')?.click());
        $('#systemFaviconInput')?.addEventListener('change', () => uploadBrandAsset($('#systemFaviconInput'), 'favicon', '/flowtrack/template-api/branding/favicon', $('#uploadSystemFavicon')));

        $('#resetSystemLogo')?.addEventListener('click', async () => {
            if (!window.confirm('Reset the system logo to the default FlowTrack mark?')) return;
            try {
                const payload = await api('/flowtrack/template-api/branding/logo', { method: 'DELETE' });
                applyFlowtrackBranding(payload.branding || {});
                showToast(payload.message || 'System logo reset.');
                renderBrandingSettings();
            } catch (error) {
                showToast(error.message || 'Unable to reset the system logo.');
            }
        });

        $('#resetSystemFavicon')?.addEventListener('click', async () => {
            if (!window.confirm('Reset the favicon to the default FlowTrack icon?')) return;
            try {
                const payload = await api('/flowtrack/template-api/branding/favicon', { method: 'DELETE' });
                applyFlowtrackBranding(payload.branding || {});
                showToast(payload.message || 'Favicon reset.');
                renderBrandingSettings();
            } catch (error) {
                showToast(error.message || 'Unable to reset the favicon.');
            }
        });
    }

    const setupContentBase = renderSetupContent;
    renderSetupContent = function (type) {
        if (['roles', 'permissions', 'users'].includes(type) && !canViewAccess) {
            $('#setupContent').innerHTML = '<div class="card access-empty">You do not have permission to view roles and access.</div>';
            return;
        }
        if (type === 'roles') return renderAccessRoles();
        if (type === 'permissions') return renderPermissionMatrix();
        if (type === 'users') return renderRoleAssignments();
        if (type === 'branding') return renderBrandingSettings();
        if (type === 'notification') {
            const rules = notificationRules || [];
            $('#setupContent').innerHTML = `<div class="card section-card"><div class="section-head"><div><h3>Notification Rules</h3><div class="small muted">Configured reminder and escalation rules.</div></div></div><table class="mini-table"><thead><tr><th>Name</th><th>Trigger</th><th>Recipients</th><th>Status</th></tr></thead><tbody>${rules.map((rule) => `<tr><td data-label="Name"><b>${html(rule.name)}</b></td><td data-label="Trigger">${html(rule.trigger)}</td><td data-label="Recipients">${html(rule.recipients)}</td><td data-label="Status"><span class="badge ${rule.active === false ? 'b-gray' : 'b-green'}">${rule.active === false ? 'Inactive' : 'Active'}</span></td></tr>`).join('') || '<tr><td colspan="4"><div class="empty-state">No notification rules are configured.</div></td></tr>'}</tbody></table></div>`;
            return;
        }
        return setupContentBase(type);
    };

    renderSetup = function () {
        originalRenderSetup();
        injectAccessMatrixStyles();
        const metrics = summaryMetrics.administration || {};
        const activeUsers = accessMembers.length ? accessMembers.filter((member) => String(member.status).toLowerCase() === 'active').length : users.length;
        const activeRoles = accessRoles.length ? accessRoles.filter((role) => role.active !== false).length : new Set(users.map((user) => user.role).filter(Boolean)).size;
        const departments = (masterData.departments || []).filter((department) => String(department.status).toLowerCase() === 'active').length;
        const summary = $('#page-setup .config-summary');
        if (summary) {
            summary.innerHTML = `<div class="card config-stat"><span>Active Users</span><b>${Number(metrics.activeUsers ?? activeUsers)}</b></div><div class="card config-stat"><span>Operational Roles</span><b>${Number(metrics.operationalRoles ?? activeRoles)}</b></div><div class="card config-stat"><span>Departments</span><b>${Number(metrics.departments ?? departments)}</b></div><div class="card config-stat"><span>Notification Rules</span><b>${Number(metrics.notificationRules ?? notificationRules.length)}</b></div>`;
        }
        const roleButton = $('.setup-menu [data-setup="roles"]');
        const userButton = $('.setup-menu [data-setup="users"]');
        if (!canViewAccess) {
            roleButton?.remove();
            userButton?.remove();
            return;
        }
        if (roleButton) roleButton.textContent = 'Roles & Policies';
        if ((isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin) && !$('.setup-menu [data-setup="branding"]')) {
            const brandingButton = document.createElement('button');
            brandingButton.dataset.setup = 'branding';
            brandingButton.textContent = 'Logo & Favicon';
            const notificationButton = $('.setup-menu [data-setup="notification"]');
            notificationButton?.insertAdjacentElement('beforebegin', brandingButton);
            brandingButton.addEventListener('click', () => {
                $$('.setup-menu [data-setup]').forEach((button) => button.classList.remove('active'));
                brandingButton.classList.add('active');
                renderSetupContent('branding');
            });
        }
        if (!$('.setup-menu [data-setup="permissions"]')) {
            const permissionButton = document.createElement('button');
            permissionButton.dataset.setup = 'permissions';
            permissionButton.textContent = 'Permission Matrix';
            roleButton?.insertAdjacentElement('afterend', permissionButton);
            permissionButton.addEventListener('click', () => {
                $$('.setup-menu [data-setup]').forEach((button) => button.classList.remove('active'));
                permissionButton.classList.add('active');
                renderSetupContent('permissions');
            });
        }
        applyActionPermissions();
    };

    


/* FlowTrack mobile/tablet alignment hardening for Master Data + Administration Setup. */
(() => {
    if (document.getElementById('flowtrack-master-setup-responsive-fix')) return;
    
    /* CSS externalized: style. */
})();
