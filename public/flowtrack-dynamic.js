(() => {
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let currentUser = null;
    let taskPacks = [];
    let masterCatalog = [];
    let accessRoles = [];
    let accessMembers = [];
    let accessCatalog = { modules: [], actions: [], scopes: [], sensitiveFields: [] };
    let selectedAccessRoleId = null;
    let canViewAccess = false;
    let canManageAccess = false;
    let canCreateUsers = false;
    let canDeleteUsers = false;
    let isFlowAdmin = false;
    let isFlowSuperAdmin = false;
    let capabilities = {};
    let currentSensitiveFields = [];

    const originalRenderClients = renderClients;
    const originalRenderSetupContent = renderSetupContent;
    const originalRenderSetup = renderSetup;
    const originalNavigate = navigate;
    const originalOpenJob = openJob;
    const originalOpenDoc = openDoc;

    function replaceArray(target, values) {
        target.splice(0, target.length, ...(values || []));
    }

    function html(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function firstValidationMessage(payload, fallback = 'Something went wrong. Please try again.') {
        if (payload?.message) return payload.message;
        const errors = payload?.errors || {};
        const first = Object.values(errors).flat()[0];
        return first || fallback;
    }

    async function api(url, options = {}) {
        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers || {}),
        };

        let body = options.body;
        if (body && !(body instanceof FormData) && typeof body !== 'string') {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(body);
        }

        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers,
            body,
        });

        const contentType = response.headers.get('content-type') || '';
        const payload = contentType.includes('application/json') ? await response.json() : null;

        if (!response.ok) {
            const error = new Error(firstValidationMessage(payload, `Request failed (${response.status}).`));
            error.payload = payload;
            error.status = response.status;
            throw error;
        }

        return payload;
    }

    function canFlow(module, action = 'view') {
        if (isFlowAdmin || isFlowSuperAdmin) return true;
        return (capabilities?.[module]?.actions || []).includes(action) || (capabilities?.[module]?.actions || []).includes('manage');
    }

    function canSensitive(field) {
        return isFlowAdmin || isFlowSuperAdmin || currentSensitiveFields.includes(field);
    }

    function isAdministratorRole(role) {
        return ['SUPER_ADMIN', 'ADMIN', 'SYS_ADMIN', 'ADMINISTRATOR', 'SYSTEM_ADMINISTRATOR'].includes(String(role?.code || '').toUpperCase());
    }

    function availableRolesForActor(currentRoleId = null) {
        return accessRoles.filter((role) => {
            if (!role.active && Number(role.id) !== Number(currentRoleId)) return false;
            const code = String(role.code || '').toUpperCase();
            if (code === 'SUPER_ADMIN' && !isFlowSuperAdmin) return false;
            if (isAdministratorRole(role) && !isFlowAdmin && !isFlowSuperAdmin) return false;
            return true;
        });
    }

    function applyCurrentUserIdentity() {
        const initialsText = currentUser?.initials || 'FT';
        document.querySelectorAll('.sidebar-footer .avatar, [data-page-jump="profile"] .avatar, #profileAvatar').forEach((element) => {
            if (currentUser?.avatarUrl) {
                element.classList.add('has-photo');
                element.style.backgroundImage = `url("${String(currentUser.avatarUrl).replaceAll('"', '%22')}")`;
                element.textContent = initialsText;
            } else {
                element.classList.remove('has-photo');
                element.style.removeProperty('background-image');
                element.textContent = initialsText;
            }
        });
        const footerName = document.querySelector('.sidebar-footer .user-mini > div:last-child > div:first-child');
        if (footerName) footerName.textContent = currentUser?.name || 'FlowTrack User';
        const footerRole = document.querySelector('.sidebar-footer .current-user-role');
        if (footerRole) footerRole.textContent = currentUser?.role || (isFlowSuperAdmin ? 'Super Administrator' : isFlowAdmin ? 'Administrator' : 'Workspace member');
    }

    function acceptCurrentUser(user) {
        currentUser = user || null;
        currentSensitiveFields = currentUser?.sensitiveFields || [];
        isFlowAdmin = Boolean(currentUser?.isAdmin || isFlowAdmin);
        isFlowSuperAdmin = Boolean(currentUser?.isSuperAdmin || isFlowSuperAdmin);
        applyCurrentUserIdentity();
    }

    function applyBootstrap(data) {
        currentUser = data.currentUser || null;
        replaceArray(users, data.users);
        replaceArray(clients, data.clients);
        replaceArray(jobs, data.jobs);
        replaceArray(tasks, data.tasks);
        replaceArray(docs, data.documents);
        replaceArray(notifications, data.notifications);
        replaceArray(workflowTemplates, data.workflows);
        taskPacks = data.taskPacks || [];
        capabilities = data.capabilities || {};
        currentSensitiveFields = data.currentUser?.sensitiveFields || [];
        canViewAccess = Boolean(data.accessControl);
        canManageAccess = Boolean(data.accessControl?.canManage);
        canCreateUsers = Boolean(data.accessControl?.canCreateUsers);
        canDeleteUsers = Boolean(data.accessControl?.canDeleteUsers);
        isFlowAdmin = Boolean(data.accessControl?.isAdmin || data.currentUser?.isAdmin);
        isFlowSuperAdmin = Boolean(data.accessControl?.isSuperAdmin || data.currentUser?.isSuperAdmin);
        accessRoles = data.accessControl?.roles || [];
        accessMembers = data.accessControl?.members || [];
        accessCatalog = data.accessControl?.catalog || accessCatalog;
        if (!selectedAccessRoleId || !accessRoles.some((role) => Number(role.id) === Number(selectedAccessRoleId))) {
            selectedAccessRoleId = accessRoles[0]?.id || null;
        }

        masterCatalog = Array.isArray(data.masterDataCatalog) ? data.masterDataCatalog : [];
        for (const key of Object.keys(masterLabels)) delete masterLabels[key];
        for (const key of Object.keys(masterData)) delete masterData[key];
        masterCatalog.forEach((section) => {
            masterLabels[section.key] = section.label;
            masterData[section.key] = Array.isArray(data.masterData?.[section.key]) ? data.masterData[section.key] : [];
        });
        if (!masterCatalog.some((section) => section.key === selectedMasterCategory)) {
            selectedMasterCategory = masterCatalog[0]?.key || '';
        }
        for (const key of Object.keys(taskTemplates)) delete taskTemplates[key];
        taskPacks.forEach((pack) => { taskTemplates[pack.name] = (pack.items || []).map((item) => item.title); });
        selectedWorkflowId = workflowTemplates[0]?.id ?? selectedWorkflowId;

        applyCurrentUserIdentity();
    }

    async function loadBootstrap() {
        const data = await api('/flowtrack/template-api/bootstrap');
        applyBootstrap(data);
        return data;
    }

    const pagePermissions = {
        dashboard: ['dashboard', 'view'],
        mywork: ['tasks', 'view'],
        jobs: ['jobs', 'view'],
        clients: ['clients', 'view'],
        board: ['jobs', 'view'],
        documents: ['documents', 'view'],
        reports: ['reports', 'view'],
        workflowsetup: ['workflow', 'view'],
        masterdata: ['masterdata', 'view'],
        setup: ['users', 'view'],
    };

    function canOpenPage(page) {
        if (['notifications', 'profile'].includes(page)) return true;
        if (page === 'board') return canFlow('jobs', 'view') || canFlow('tasks', 'view');
        if (page === 'setup') return canFlow('users', 'view') || canFlow('workflow', 'view') || canFlow('masterdata', 'view');
        const requirement = pagePermissions[page];
        return !requirement || canFlow(requirement[0], requirement[1]);
    }

    function applyNavigationPermissions() {
        document.querySelectorAll('[data-page]').forEach((button) => {
            const allowed = canOpenPage(button.dataset.page);
            button.classList.toggle('hidden', !allowed);
            button.disabled = !allowed;
        });
    }

    function setVisible(selector, visible) {
        document.querySelectorAll(selector).forEach((element) => element.classList.toggle('hidden', !visible));
    }

    function applyActionPermissions() {
        applyNavigationPermissions();
        setVisible('#quickCreateBtn,#jobCreate,#boardCreate,[data-dashboard-action="create"]', canFlow('jobs', 'create'));
        setVisible('#newClient', canFlow('clients', 'create'));
        setVisible('#editClientBtn', canFlow('clients', 'edit_own') || canFlow('clients', 'edit_all'));
        setVisible('[data-new-client-job]', canFlow('jobs', 'create'));
        setVisible('[data-next-phase]', canFlow('jobs', 'edit_own') || canFlow('jobs', 'edit_all') || canFlow('jobs', 'override'));
        setVisible('#uploadBtn,[data-upload-job]', canFlow('documents', 'create'));
        setVisible('#addWorkflow,#cloneWorkflow,#addWorkflowPhase', canFlow('workflow', 'create'));
        setVisible('#editWorkflowInfo,[data-edit-phase],[data-phase-up],[data-phase-down],[data-edit-pack-id]', canFlow('workflow', 'edit_all') || canFlow('workflow', 'manage'));
        setVisible('[data-delete-phase]', canFlow('workflow', 'delete'));
        setVisible('#addTaskPack', canFlow('workflow', 'create'));
        setVisible('#addMasterRecord,#masterImport', canFlow('masterdata', 'create'));
        setVisible('[data-master-edit],[data-master-toggle]', canFlow('masterdata', 'edit_all') || canFlow('masterdata', 'manage'));
        setVisible('[data-master-delete]', canFlow('masterdata', 'delete'));
        setVisible('[data-add-task]', canFlow('tasks', 'create'));
        setVisible('#saveTaskUpdate', canFlow('tasks', 'edit_own') || canFlow('tasks', 'edit_all'));
        setVisible('#jobExport,#reportPdf,#reportExcel', canFlow('reports', 'export') || canFlow('jobs', 'export'));
        setVisible('[data-delete-client]', canFlow('clients', 'delete'));
        setVisible('[data-delete-job]', canFlow('jobs', 'delete'));
        setVisible('[data-delete-task]', canFlow('tasks', 'delete'));
        setVisible('[data-delete-document]', canFlow('documents', 'delete'));
        setVisible('.client-sensitive-contact', canSensitive('client_contact_details'));
        setVisible('.client-sensitive-financial', canSensitive('invoice_amount'));
    }

    navigate = function (page) {
        if (!canOpenPage(page)) {
            showToast('You do not have permission to open this section.');
            return;
        }
        originalNavigate(page);
        queueMicrotask(applyActionPermissions);
    };

    function showApplication() {
        $('#loginScreen')?.classList.add('hidden');
        $('#authLoadingScreen')?.classList.add('hidden');
        $('#appScreen')?.classList.remove('hidden');
        updateBadges();
        applyActionPermissions();

        const preferredPage = currentUser?.defaultLandingPage || 'dashboard';
        navigate(canOpenPage(preferredPage) ? preferredPage : 'dashboard');
    }

    async function login() {
        const button = $('#loginBtn');
        const email = $('#loginEmail').value.trim();
        const password = $('#loginPassword').value;
        const originalText = button.textContent;

        button.disabled = true;
        button.textContent = 'Signing in…';

        try {
            await api('/login', {
                method: 'POST',
                body: { email, password, remember: Boolean($('#loginRemember')?.checked) },
            });
            await loadBootstrap();
            window.history.replaceState({}, '', '/dashboard');
            showApplication();
        } catch (error) {
            showToast(error.message);
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    }

    async function logout() {
        const button = $('#logoutBtn');
        const originalText = button?.querySelector('span')?.textContent || 'Log out';
        if (button) {
            button.disabled = true;
            const label = button.querySelector('span');
            if (label) label.textContent = 'Signing out…';
        }

        try {
            await api('/flowtrack/logout', { method: 'POST' });
            window.FLOWTRACK_AUTHENTICATED = false;
            window.location.replace('/login');
        } catch (error) {
            if (button) {
                button.disabled = false;
                const label = button.querySelector('span');
                if (label) label.textContent = originalText;
            }
            showToast(error.message);
        }
    }

    const loginButton = $('#loginBtn');
    loginButton?.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopImmediatePropagation();
        login();
    }, true);

    $('#loginPassword')?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        login();
    }, true);

    function clientPayload(existing = null) {
        const managerValue = $('#clientManager').value;
        const payload = {
            name: $('#clientName').value.trim(),
            code: $('#clientCode').value.trim() || null,
            country: $('#clientCountryInput').value.trim() || null,
            account_manager_id: managerValue ? Number(managerValue) : null,
            preferred_language: $('#clientLanguage').value,
            ...(existing ? { code: $('#clientCode').value.trim() } : {}),
        };
        if (canSensitive('client_contact_details')) {
            payload.contact_name = $('#clientContact').value.trim() || null;
            payload.email = $('#clientEmail').value.trim() || null;
            payload.phone = $('#clientPhone').value.trim() || null;
            payload.notes = $('#clientNotes').value.trim() || null;
        }
        if (canSensitive('invoice_amount')) {
            payload.outstanding_balance = Number($('#clientOutstanding').value || 0);
        }
        return payload;
    }

    function openClientModal(existing = null) {
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${existing ? 'Edit Client' : 'Create New Client'}</h2><div class="small muted" style="margin-top:5px">Client details are shared across Jobs, documents and reporting.</div></div><button class="close-btn" data-close>×</button></div>
        <div class="modal-body"><div class="form-grid">
            <div class="field full"><label>Client name *</label><input id="clientName" value="${html(existing?.name)}" placeholder="Company or organisation name"></div>
            <div class="field"><label>Client code</label><input id="clientCode" value="${html(existing?.code)}" placeholder="Auto-generated when blank" ${existing ? 'required' : ''}></div>
            <div class="field"><label>Country</label><input id="clientCountryInput" value="${html(existing?.country)}" placeholder="e.g. United States"></div>
            <div class="field client-sensitive-contact"><label>Primary contact</label><input id="clientContact" value="${html(existing?.contact || existing?.contact_name)}" placeholder="Contact person"></div>
            <div class="field client-sensitive-contact"><label>Email</label><input id="clientEmail" type="email" value="${html(existing?.email)}" placeholder="contact@company.com"></div>
            <div class="field client-sensitive-contact"><label>Phone</label><input id="clientPhone" value="${html(existing?.phone)}" placeholder="Phone number"></div>
            <div class="field"><label>Account manager</label><select id="clientManager" ${canFlow('clients', 'assign') ? '' : 'disabled'}><option value="">Unassigned</option>${users.map((user) => `<option value="${user.id}" ${Number(existing?.manager_id || existing?.account_manager_id) === Number(user.id) ? 'selected' : ''}>${html(user.name)}</option>`).join('')}</select></div>
            <div class="field"><label>Preferred language</label><select id="clientLanguage"><option value="en" ${(existing?.preferred_language || 'en') === 'en' ? 'selected' : ''}>English</option><option value="zh" ${existing?.preferred_language === 'zh' ? 'selected' : ''}>Simplified Chinese</option></select></div>
            <div class="field client-sensitive-financial"><label>Outstanding balance</label><input id="clientOutstanding" type="number" min="0" step="0.01" value="${Number(existing?.outstanding ?? existing?.outstanding_balance ?? 0)}"></div>
            <div class="field full client-sensitive-contact"><label>Notes</label><textarea id="clientNotes" rows="3" placeholder="Commercial notes, preferences or account instructions…">${html(existing?.notes)}</textarea></div>
        </div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveClientBtn">${existing ? 'Save Changes' : 'Create Client'}</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        applyActionPermissions();

        $('#saveClientBtn').addEventListener('click', async () => {
            const button = $('#saveClientBtn');
            const payload = clientPayload(existing);
            if (!payload.name) {
                showToast('Client name is required.');
                $('#clientName').focus();
                return;
            }
            if (existing && !payload.code) {
                showToast('Client code is required when editing a client.');
                $('#clientCode').focus();
                return;
            }

            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const result = await api(existing ? `/flowtrack/template-api/clients/${existing.id}` : '/flowtrack/template-api/clients', {
                    method: existing ? 'PUT' : 'POST',
                    body: payload,
                });
                const index = clients.findIndex((client) => Number(client.id) === Number(result.client.id));
                if (index >= 0) clients.splice(index, 1, result.client);
                else clients.push(result.client);
                clients.sort((a, b) => a.name.localeCompare(b.name));
                closeAll();
                renderClients();
                showToast(existing ? 'Client updated successfully.' : 'Client created successfully.');
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = existing ? 'Save Changes' : 'Create Client';
            }
        });
    }

    renderClients = function () {
        originalRenderClients();
        const existingButton = $('#newClient');
        if (existingButton) {
            const cleanButton = existingButton.cloneNode(true);
            existingButton.replaceWith(cleanButton);
            cleanButton.addEventListener('click', () => openClientModal());
        }
    };

    openClient = function (id) {
        const client = clients.find((item) => Number(item.id) === Number(id));
        if (!client) return;
        const clientJobs = jobs.filter((job) => Number(job.client) === Number(client.id));

        $('#detailDrawer').innerHTML = `<div class="drawer-head"><div class="client-head"><div class="company-logo">${html(client.code)}</div><div><div class="small muted">Client</div><h2>${html(client.name)}</h2><div class="muted">${html(client.country || 'No country')}</div></div></div><button class="close-btn" data-close>×</button></div>
        <div class="drawer-body"><div class="summary-grid"><div class="summary-box"><span>Primary Contact</span><b>${html(client.contact || 'Not provided')}</b></div><div class="summary-box"><span>Account Manager</span><b>${html(client.manager || 'Unassigned')}</b></div><div class="summary-box"><span>Outstanding</span><b>${canSensitive('invoice_amount') ? currency(client.outstanding || 0) : 'Restricted'}</b></div></div>
        <div class="section-head" style="margin-top:20px"><h3>Contact Information</h3><div class="actions"><button class="danger-btn" data-delete-client="${client.id}">Delete Client</button><button class="secondary" id="editClientBtn">Edit Client</button></div></div>
        <div class="card" style="padding:14px"><b>${html(client.contact || 'No contact')}</b><div class="muted" style="margin-top:4px">${html(client.email || 'No email')}<br>${html(client.phone || 'No phone')}<br>Preferred language: ${client.preferred_language === 'zh' ? 'Simplified Chinese' : 'English'}</div>${client.notes ? `<div class="small" style="margin-top:10px">${html(client.notes)}</div>` : ''}</div>
        <div class="section-head" style="margin-top:20px"><h3>Active Jobs</h3><button class="primary" data-new-client-job="${client.id}">＋ New Job</button></div>
        ${clientJobs.map((job) => `<div class="attention-item" data-job="${job.id}"><span class="signal ${job.attention ? 'red' : 'amber'}"></span><div><div class="item-title">${html(job.title)}</div><div class="item-meta">${job.id} · ${phaseShortFor(job)} · ${job.progress}%</div></div>${healthBadge(job.health)}</div>`).join('') || '<div class="empty-state">No Jobs have been created for this client.</div>'}</div>`;

        openOverlay();
        $('#detailDrawer').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        $('#editClientBtn').addEventListener('click', () => {
            closeAll();
            setTimeout(() => openClientModal(client), 30);
        });
        $('[data-delete-client]')?.addEventListener('click', async () => {
            if (!confirm(`Delete ${client.name} and all related Jobs, tasks and documents?`)) return;
            try {
                await api(`/flowtrack/template-api/clients/${client.id}`, { method: 'DELETE' });
                const deletedJobCodes = new Set(jobs.filter((job) => Number(job.client) === Number(client.id)).map((job) => job.id));
                for (let i = jobs.length - 1; i >= 0; i -= 1) if (Number(jobs[i].client) === Number(client.id)) jobs.splice(i, 1);
                for (let i = tasks.length - 1; i >= 0; i -= 1) if (deletedJobCodes.has(tasks[i].job)) tasks.splice(i, 1);
                for (let i = docs.length - 1; i >= 0; i -= 1) if (Number(docs[i].client) === Number(client.id)) docs.splice(i, 1);
                const index = clients.findIndex((item) => Number(item.id) === Number(client.id));
                if (index >= 0) clients.splice(index, 1);
                closeAll(); renderClients(); showToast('Client deleted successfully.');
            } catch (error) { showToast(error.message); }
        });
        bindCommon();
        applyActionPermissions();
    };

    openJob = function (id) {
        const job = jobs.find((item) => item.id === id);
        originalOpenJob(id);
        if (!job || !$('#detailDrawer')?.classList.contains('open')) return;
        const head = $('#detailDrawer .drawer-head');
        const close = head?.querySelector('.close-btn');
        if (head && close && !head.querySelector('[data-delete-job]')) {
            const button = document.createElement('button');
            button.className = 'danger-btn';
            button.dataset.deleteJob = String(job.dbId);
            button.textContent = 'Delete Job';
            head.insertBefore(button, close);
            button.addEventListener('click', async () => {
                if (!confirm(`Delete ${job.id} and all related tasks and documents?`)) return;
                try {
                    await api(`/flowtrack/template-api/jobs/${job.dbId}`, { method: 'DELETE' });
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1);
                    for (let i = tasks.length - 1; i >= 0; i -= 1) if (tasks[i].job === job.id) tasks.splice(i, 1);
                    for (let i = docs.length - 1; i >= 0; i -= 1) if (docs[i].job === job.id) docs.splice(i, 1);
                    closeAll(); renderPage(currentPage); showToast('Job deleted successfully.');
                } catch (error) { showToast(error.message); }
            });
        }
        applyActionPermissions();
    };

    openDoc = function (id) {
        const documentRecord = docs.find((item) => item.id === id);
        originalOpenDoc(id);
        if (!documentRecord || !$('#detailDrawer')?.classList.contains('open')) return;
        const head = $('#detailDrawer .drawer-head');
        const close = head?.querySelector('.close-btn');
        if (head && close && !head.querySelector('[data-delete-document]')) {
            const button = document.createElement('button');
            button.className = 'danger-btn';
            button.dataset.deleteDocument = String(documentRecord.dbId);
            button.textContent = 'Delete Document';
            head.insertBefore(button, close);
            button.addEventListener('click', async () => {
                if (!confirm(`Delete ${documentRecord.name}?`)) return;
                try {
                    await api(`/flowtrack/template-api/documents/${documentRecord.dbId}`, { method: 'DELETE' });
                    const index = docs.findIndex((item) => Number(item.dbId) === Number(documentRecord.dbId));
                    if (index >= 0) docs.splice(index, 1);
                    closeAll(); renderDocuments(); showToast('Document deleted successfully.');
                } catch (error) { showToast(error.message); }
            });
        }
        applyActionPermissions();
    };

    function taskStatusRecord(value) {
        return (masterData.taskStatuses || []).find((status) => Number(status.id) === Number(value) || status.name === value || status.code === value);
    }

    function taskProgressForStatus(status) {
        const code = String(status?.code || status?.name || status || '').toUpperCase();
        if (['CMP', 'COMPLETED'].includes(code)) return 100;
        if (['WC', 'WAITING FOR CLIENT', 'WS', 'WAITING FOR SUPPLIER', 'WIA', 'WAITING FOR INTERNAL APPROVAL'].includes(code)) return 65;
        if (['IP', 'IN PROGRESS'].includes(code)) return 50;
        if (['BLK', 'BLOCKED', 'HOLD', 'ON HOLD'].includes(code)) return 50;
        if (['REV', 'REVISION REQUIRED'].includes(code)) return 35;
        if (['READY'].includes(code)) return 10;
        return 0;
    }

    function updateTaskAndJob(result) {
        if (result.task) {
            const taskIndex = tasks.findIndex((task) => Number(task.dbId) === Number(result.task.dbId));
            if (taskIndex >= 0) tasks.splice(taskIndex, 1, result.task);
        }
        if (result.job) {
            const jobIndex = jobs.findIndex((job) => Number(job.dbId) === Number(result.job.dbId));
            if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
        }
    }

    function replaceTasksForJob(jobCode, newTasks) {
        for (let index = tasks.length - 1; index >= 0; index -= 1) {
            if (tasks[index].job === jobCode) tasks.splice(index, 1);
        }
        tasks.push(...(newTasks || []));
    }

    async function persistTask(task, status, extras = {}) {
        const statusRecord = taskStatusRecord(status);
        if (!statusRecord) throw new Error('The selected task status is not configured.');
        const payload = {
            task_status_id: statusRecord.id,
            assignee_id: extras.assignee_id ?? task.assigneeId ?? null,
            progress: taskProgressForStatus(statusRecord),
            attention_required: extras.attention_required ?? task.attention ?? false,
            blocker_reason: extras.blocker_reason ?? task.blockerReason ?? null,
            completed_checklist_ids: extras.completed_checklist_ids ?? (task.checklist || []).filter((item) => item.done).map((item) => item.id),
            comment: extras.comment ?? '',
        };
        const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'PUT', body: payload });
        updateTaskAndJob(result);
        return result;
    }

    openTask = function (id) {
        const task = tasks.find((item) => item.id === id);
        if (!task) return;
        const job = jobs.find((item) => item.id === task.job);
        const statuses = masterData.taskStatuses || [];
        const selectedStatus = taskStatusRecord(task.statusId || task.status);

        $('#detailDrawer').innerHTML = `<div class="drawer-head"><div><div class="small muted">${task.id} · ${task.job}</div><h2>${html(task.title)}</h2><div>${statusBadge(task.status)} <span class="badge ${task.priority === 'Critical' ? 'b-red' : task.priority === 'High' ? 'b-amber' : 'b-gray'}">${html(task.priority)}</span></div></div><button class="close-btn" data-close>×</button></div>
        <div class="drawer-body"><div class="summary-grid"><div class="summary-box"><span>Job</span><b>${html(job?.title || task.job)}</b></div><div class="summary-box"><span>Assignee</span><b>${html(task.assignee)}</b></div><div class="summary-box"><span>Due Date</span><b class="${dueClass(task.due)}">${formatDate(task.due)}</b></div></div>
        <div class="form-grid" style="margin-top:18px"><div class="field"><label>Status</label><select id="taskUpdateStatus">${statuses.map((status) => `<option value="${status.id}" ${Number(status.id) === Number(selectedStatus?.id) ? 'selected' : ''}>${html(status.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Assignee</label><select id="taskUpdateAssignee" ${canFlow('tasks', 'assign') ? '' : 'disabled'}><option value="">Unassigned</option>${users.map((user) => `<option value="${user.id}" ${Number(user.id) === Number(task.assigneeId) ? 'selected' : ''}>${html(user.name)}</option>`).join('')}</select></div>
        <div class="field full"><label>Progress: <b id="progressValue">${task.progress}%</b></label><input id="taskProgress" type="range" min="0" max="100" value="${task.progress}" disabled><div class="inline-help">Progress is calculated automatically from the selected status.</div></div></div>
        <div class="section-head"><h3>Checklist</h3><span class="small muted">${task.checklist.filter((item) => item.done).length}/${task.checklist.length} complete</span></div>
        ${task.checklist.map((item, index) => `<label class="check-row"><input class="task-check" data-check="${index}" type="checkbox" ${item.done ? 'checked' : ''}> <span>${html(item.text)}</span></label>`).join('')}
        <div class="field" style="margin-top:18px"><label>Update comment</label><textarea id="taskComment" rows="3" placeholder="Add progress, decision, blocker or next action…"></textarea></div>
        <label class="check-row"><input id="taskAttention" type="checkbox" ${task.attention ? 'checked' : ''}> <span>Mark as attention needed</span></label>
        <div class="section-head" style="margin-top:18px"><h3>Activity & Comments</h3></div>
        ${(task.comments || []).map((comment) => `<div class="comment"><span class="avatar">${userInitials(comment.user)}</span><div><b>${html(comment.user)}</b><div style="margin-top:4px">${html(comment.text)}</div><div class="small muted" style="margin-top:4px">${html(comment.time)}</div></div></div>`).join('')}
        <div class="actions" style="margin-top:18px"><button class="danger-btn" data-delete-task="${task.dbId}">Delete Task</button><button class="primary" id="saveTaskUpdate" style="flex:1">Save Task Update</button></div></div>`;

        openOverlay();
        $('#detailDrawer').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));

        const syncProgressPreview = () => {
            const status = taskStatusRecord($('#taskUpdateStatus').value);
            const progress = taskProgressForStatus(status);
            $('#taskProgress').value = String(progress);
            $('#progressValue').textContent = `${progress}%`;
        };
        $('#taskUpdateStatus').addEventListener('change', syncProgressPreview);
        syncProgressPreview();
        $('[data-delete-task]')?.addEventListener('click', async () => {
            if (!confirm(`Delete task ${task.id}?`)) return;
            try {
                const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'DELETE' });
                const index = tasks.findIndex((item) => Number(item.dbId) === Number(task.dbId));
                if (index >= 0) tasks.splice(index, 1);
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                }
                closeAll(); renderPage(currentPage); updateBadges(); showToast('Task deleted successfully.');
            } catch (error) { showToast(error.message); }
        });
        applyActionPermissions();

        $('#saveTaskUpdate').addEventListener('click', async () => {
            const status = taskStatusRecord($('#taskUpdateStatus').value);
            const comment = $('#taskComment').value.trim();
            const attention = $('#taskAttention').checked;
            const isBlocked = ['BLK', 'BLOCKED'].includes(String(status?.code || status?.name || '').toUpperCase());
            if ((isBlocked || attention) && !comment && !task.blockerReason) {
                showToast('Please add a short reason for the blocker or attention flag.');
                $('#taskComment').focus();
                return;
            }

            const button = $('#saveTaskUpdate');
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const completed = $$('.task-check').filter((checkbox) => checkbox.checked).map((checkbox) => task.checklist[Number(checkbox.dataset.check)]?.id).filter(Boolean);
                await persistTask(task, status, {
                    assignee_id: $('#taskUpdateAssignee').value ? Number($('#taskUpdateAssignee').value) : null,
                    attention_required: attention,
                    blocker_reason: (isBlocked || attention) ? (comment || task.blockerReason) : null,
                    completed_checklist_ids: completed,
                    comment,
                });
                closeAll();
                renderPage(currentPage);
                updateBadges();
                showToast('Task update saved. Progress recalculated automatically.');
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Save Task Update';
            }
        });
    };

    async function moveJobToPhase(job, phase, reason = null) {
        const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/phase`, {
            method: 'PATCH',
            body: { phase_id: phase.id, reason },
        });
        const index = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
        if (index >= 0) jobs.splice(index, 1, result.job);
        replaceTasksForJob(result.job.id, result.tasks);
        return result;
    }

    renderKanban = function () {
        const area = $('#kanbanArea');
        if (boardMode === 'tasks') {
            const cols = ['Ready', 'In Progress', 'Waiting for Client', 'Revision Required', 'Blocked', 'Completed'];
            area.innerHTML = `<div class="kanban">${cols.map((column) => `<div class="kanban-col"><div class="kanban-head"><span>${column}</span><span class="count">${tasks.filter((task) => task.status === column).length}</span></div><div class="kanban-list" data-drop-status="${column}">${tasks.filter((task) => task.status === column).slice(0, 12).map((task) => `<article class="kanban-card" draggable="true" data-task="${task.id}"><span class="badge ${task.priority === 'Critical' ? 'b-red' : task.priority === 'High' ? 'b-amber' : 'b-gray'}">${task.priority}</span><div class="kanban-card-title">${html(task.title)}</div><div class="kanban-job">${task.job} · ${html(task.phase)}</div><div class="kanban-meta"><span class="avatar" style="width:25px;height:25px;font-size:9px">${userInitials(task.assignee)}</span><span class="due ${dueClass(task.due)} small">${formatDate(task.due)}</span></div><div class="progress" style="margin-top:9px"><span style="width:${task.progress}%"></span></div></article>`).join('')}</div></div>`).join('')}</div>`;
        } else {
            const cols = [0, 1, 3, 5, 6, 7, 8, 9];
            area.innerHTML = `<div class="kanban" style="grid-template-columns:repeat(8,minmax(230px,1fr))">${cols.map((phaseIndex) => `<div class="kanban-col"><div class="kanban-head"><span>${phaseShort[phaseIndex]}</span><span class="count">${jobs.filter((job) => job.phase === phaseIndex).length}</span></div><div class="kanban-list" data-drop-phase="${phaseIndex}">${jobs.filter((job) => job.phase === phaseIndex).map((job) => `<article class="kanban-card" draggable="true" data-job="${job.id}">${healthBadge(job.health)}<div class="kanban-card-title">${html(job.title)}</div><div class="kanban-job">${job.id} · ${clientName(job.client)}</div><div class="kanban-meta"><span class="avatar" style="width:25px;height:25px;font-size:9px">${userInitials(job.coordinator)}</span><span class="small">${job.progress}%</span></div><div class="progress" style="margin-top:9px"><span style="width:${job.progress}%"></span></div></article>`).join('')}</div></div>`).join('')}</div>`;
        }

        let dragged = null;
        $$('.kanban-card').forEach((card) => {
            card.addEventListener('dragstart', () => { dragged = card; card.classList.add('dragging'); });
            card.addEventListener('dragend', () => card.classList.remove('dragging'));
        });
        $$('.kanban-list').forEach((list) => {
            list.addEventListener('dragover', (event) => event.preventDefault());
            list.addEventListener('drop', async () => {
                if (!dragged) return;
                try {
                    if (boardMode === 'tasks') {
                        const task = tasks.find((item) => item.id === dragged.dataset.task);
                        const targetStatus = list.dataset.dropStatus;
                        if (targetStatus === 'Blocked') {
                            showToast('Open the task and add a blocker reason before marking it blocked.');
                            openTask(task.id);
                            return;
                        }
                        await persistTask(task, targetStatus);
                    } else {
                        const job = jobs.find((item) => item.id === dragged.dataset.job);
                        const phaseIndex = Number(list.dataset.dropPhase);
                        if (phaseIndex > job.phase + 1) {
                            showToast('Complete required phase controls before skipping ahead.');
                            return;
                        }
                        const workflow = getWorkflow(job.workflow);
                        const phase = workflow.phases[phaseIndex];
                        if (!phase) throw new Error('The selected workflow phase is not available.');
                        await moveJobToPhase(job, phase);
                    }
                    renderKanban();
                    updateBadges();
                    showToast('Board updated. Progress recalculated automatically.');
                } catch (error) {
                    showToast(error.message);
                    renderKanban();
                }
            });
        });
        bindCommon();
    };

    bindCommon = function () {
        const newClientJob = $('[data-new-client-job]');
        if (newClientJob) newClientJob.addEventListener('click', () => {
            const id = newClientJob.dataset.newClientJob;
            closeAll();
            setTimeout(() => openJobModal(id), 50);
        });
        $$('[data-go]').forEach((button) => button.addEventListener('click', () => navigate(button.dataset.go)));
        $$('[data-job]').forEach((element) => element.addEventListener('click', (event) => {
            event.stopPropagation();
            openJob(element.dataset.job);
        }));
        $$('[data-task]').forEach((element) => element.addEventListener('click', (event) => {
            if (element.classList.contains('dragging')) return;
            event.stopPropagation();
            openTask(element.dataset.task);
        }));
        $$('[data-client]').forEach((element) => element.addEventListener('click', () => openClient(element.dataset.client)));
        $$('[data-doc]').forEach((element) => element.addEventListener('click', () => openDoc(element.dataset.doc)));
        $$('[data-metric]').forEach((metricElement) => metricElement.addEventListener('click', () => {
            const filter = metricElement.dataset.metric;
            navigate(filter === 'done' ? 'reports' : filter === 'approval' || filter === 'overdue' ? 'mywork' : 'jobs');
            if (filter === 'risk') { jobFilter.health = 'At Risk'; renderJobs(); }
            if (filter === 'shipment') { jobFilter.phase = '8'; renderJobs(); }
            if (filter === 'payment') { jobFilter.phase = '9'; renderJobs(); }
        }));

        const next = $('[data-next-phase]');
        if (next) next.addEventListener('click', async () => {
            const job = jobs.find((item) => item.id === next.dataset.nextPhase);
            const workflow = getWorkflow(job.workflow);
            const target = workflow.phases[job.phase + 1];
            if (!target) return;
            next.disabled = true;
            try {
                const result = await moveJobToPhase(job, target);
                showToast(`${phaseNameFor(result.job)} activated. Progress recalculated automatically.`);
                openJob(result.job.id);
            } catch (error) {
                showToast(error.message);
            } finally {
                next.disabled = false;
            }
        });
        const viewTasks = $('[data-open-job-tasks]');
        if (viewTasks) viewTasks.addEventListener('click', () => $('[data-detailtab="2"]')?.click());
    };

    function masterSection(category = selectedMasterCategory) {
        return masterCatalog.find((section) => section.key === category) || null;
    }

    function currentMasterType() {
        return masterSection()?.type || null;
    }

    function currentMasterLabel(category = selectedMasterCategory) {
        return masterSection(category)?.label || masterLabels[category] || 'Master Data';
    }

    function masterRecordPayload() {
        const section = masterSection();
        return {
            type: section?.type,
            parent_id: section?.parentKey && $('#masterParent')?.value ? Number($('#masterParent').value) : null,
            name: $('#masterName').value.trim(),
            description: $('#masterDetail').value.trim() || null,
            status: $('#masterStatus').value.toLowerCase(),
            sort_order: Number($('#masterSortOrder').value || 0),
        };
    }

    function masterRowsHtmlDynamic(rows) {
        return rows.map((record) => `<tr><td><b>${html(record.code)}</b></td><td>${html(record.name)}</td><td>${html(record.detail || record.description || '')}</td><td><span class="badge ${String(record.status).toLowerCase() === 'active' ? 'b-green' : 'b-gray'}">${html(record.status)}</span></td><td><div class="row-actions"><button class="mini-btn" data-master-edit="${record.id}">Edit</button><button class="mini-btn" data-master-toggle="${record.id}">${String(record.status).toLowerCase() === 'active' ? 'Deactivate' : 'Activate'}</button><button class="mini-btn danger-btn" data-master-delete="${record.id}">Delete</button></div></td></tr>`).join('') || '<tr><td colspan="5"><div class="empty-state">No records found.</div></td></tr>';
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
        const allRows = masterCatalog.flatMap((section) => masterData[section.key] || []);
        $('#page-masterdata').innerHTML = `<div class="page-head"><div><h1>Master Data</h1><p>Maintain the values used in Jobs, tasks, documents, production and shipment forms</p></div><div class="actions"><button class="ghost" id="masterImport">Import</button><button class="primary" id="addMasterRecord">＋ Add Record</button></div></div>
        <div class="config-summary"><div class="card config-stat"><span>Master Categories</span><b>${masterCatalog.length}</b></div><div class="card config-stat"><span>Total Records</span><b>${allRows.length}</b></div><div class="card config-stat"><span>Active Records</span><b>${allRows.filter((item) => String(item.status).toLowerCase() === 'active').length}</b></div><div class="card config-stat"><span>Selected Category</span><b style="font-size:15px">${html(label)}</b></div></div>
        <div class="master-layout"><div class="card master-categories">${masterCatalog.map((section) => `<button class="${section.key === selectedMasterCategory ? 'active' : ''}" data-master-category="${html(section.key)}"><b>${html(section.label)}</b><small>${(masterData[section.key] || []).length} records</small></button>`).join('')}</div><div><div class="card section-card"><div class="master-head"><div><h3 style="margin:0 0 4px">${html(label)}</h3><div class="small muted">All records in this section are loaded from the database. Codes are generated automatically and cannot be changed.</div></div><input id="masterSearch" style="max-width:260px;border:1px solid var(--line);border-radius:8px;padding:9px" placeholder="Search records…"></div><div class="table-wrap"><table class="master-table"><thead><tr><th>Code</th><th>Name</th><th>Description / Use</th><th>Status</th><th>Actions</th></tr></thead><tbody id="masterRows">${masterRowsHtmlDynamic(rows)}</tbody></table></div></div></div></div>`;

        $$('[data-master-category]').forEach((button) => button.addEventListener('click', () => {
            selectedMasterCategory = button.dataset.masterCategory;
            renderMasterData();
        }));
        $('#addMasterRecord')?.addEventListener('click', () => openMasterModal(selectedMasterCategory));
        $('#masterImport')?.addEventListener('click', () => showToast('Excel/CSV import will use the database section and automatic code sequence.'));
        $('#masterSearch')?.addEventListener('input', (event) => {
            const query = event.target.value.toLowerCase();
            $('#masterRows').innerHTML = masterRowsHtmlDynamic(rows.filter((record) => `${record.code} ${record.name} ${record.detail || record.description || ''}`.toLowerCase().includes(query)));
            bindDynamicMasterRows();
            applyActionPermissions();
        });
        bindDynamicMasterRows();
        applyActionPermissions();
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
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${existing ? 'Edit' : 'Add'} ${html(section.label)} Record</h2><div class="small muted" style="margin-top:5px">The code follows this section's automatic sequence and is permanently locked.</div></div><button class="close-btn" data-close>×</button></div>
        <div class="modal-body"><div class="form-grid">
            <div class="field"><label>Code</label><input id="masterCode" value="${html(code)}" readonly aria-readonly="true"><div class="inline-help">Automatically generated. This field cannot be edited.</div></div>
            <div class="field"><label>Status</label><select id="masterStatus"><option value="active" ${String(existing?.status || 'Active').toLowerCase() === 'active' ? 'selected' : ''}>Active</option><option value="inactive" ${String(existing?.status || '').toLowerCase() === 'inactive' ? 'selected' : ''}>Inactive</option></select></div>
            <div class="field full"><label>Name *</label><input id="masterName" value="${html(existing?.name)}" placeholder="Enter the record name"></div>
            ${section.parentKey ? `<div class="field full"><label>${html(masterLabels[section.parentKey] || 'Parent')}</label><select id="masterParent"><option value="">No parent</option>${parentRecords.map((parent) => `<option value="${parent.id}" ${Number(existing?.parent_id) === Number(parent.id) ? 'selected' : ''}>${html(parent.code)} · ${html(parent.name)}</option>`).join('')}</select></div>` : ''}
            <div class="field full"><label>Description / Use</label><textarea id="masterDetail" rows="3">${html(existing?.detail || existing?.description)}</textarea></div>
            <div class="field"><label>Display order</label><input id="masterSortOrder" type="number" min="0" value="${Number(existing?.sort_order || 0)}"></div>
        </div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveMasterRecord">Save Record</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        $('#saveMasterRecord').addEventListener('click', async () => {
            const payload = masterRecordPayload();
            if (!payload.name) {
                showToast('Name is required.');
                $('#masterName').focus();
                return;
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

    function blankTaskPackItem() {
        return {
            id: null,
            title: '',
            description: '',
            default_assignee_id: null,
            default_department_id: null,
            priority_id: null,
            due_offset_days: 1,
            is_required: true,
        };
    }

    function taskPackItemEditorHtml(items) {
        const departments = activeMaster('departments');
        const priorities = activeMaster('priorities');
        return items.map((item, index) => `<div class="card task-pack-editor-item" data-pack-item-index="${index}">
            <div class="section-head"><div><h3 style="font-size:14px">Task ${index + 1}</h3><div class="small muted">Sequence ${index + 1} in this Task Pack</div></div><div class="row-actions"><button type="button" class="mini-btn" data-pack-up="${index}" ${index === 0 ? 'disabled' : ''}>↑</button><button type="button" class="mini-btn" data-pack-down="${index}" ${index === items.length - 1 ? 'disabled' : ''}>↓</button><button type="button" class="mini-btn" data-pack-remove="${index}">Remove</button></div></div>
            <div class="form-grid">
                <div class="field full"><label>Task title *</label><input data-pack-field="title" data-pack-index="${index}" value="${html(item.title)}" placeholder="Task title"></div>
                <div class="field full"><label>Description</label><textarea data-pack-field="description" data-pack-index="${index}" rows="2">${html(item.description)}</textarea></div>
                <div class="field"><label>Default assignee</label><select data-pack-field="default_assignee_id" data-pack-index="${index}"><option value="">Job coordinator</option>${users.map((user) => `<option value="${user.id}" ${Number(item.default_assignee_id) === Number(user.id) ? 'selected' : ''}>${html(user.name)}</option>`).join('')}</select></div>
                <div class="field"><label>Default department</label><select data-pack-field="default_department_id" data-pack-index="${index}"><option value="">No department default</option>${departments.map((department) => `<option value="${department.id}" ${Number(item.default_department_id) === Number(department.id) ? 'selected' : ''}>${html(department.name)}</option>`).join('')}</select></div>
                <div class="field"><label>Priority</label><select data-pack-field="priority_id" data-pack-index="${index}"><option value="">Use Job priority</option>${priorities.map((priority) => `<option value="${priority.id}" ${Number(item.priority_id) === Number(priority.id) ? 'selected' : ''}>${html(priority.name)}</option>`).join('')}</select></div>
                <div class="field"><label>Due after phase starts</label><input data-pack-field="due_offset_days" data-pack-index="${index}" type="number" min="0" max="365" value="${Number(item.due_offset_days ?? 1)}"></div>
            </div>
            <label class="check-row"><input data-pack-field="is_required" data-pack-index="${index}" type="checkbox" ${item.is_required ? 'checked' : ''}> <span>Required task</span></label>
        </div>`).join('');
    }

    function openTaskPackModal(existing = null) {
        const items = (existing?.items?.length ? existing.items : [blankTaskPackItem()]).map((item) => ({ ...blankTaskPackItem(), ...item }));
        const renderItems = () => {
            $('#taskPackItemsEditor').innerHTML = taskPackItemEditorHtml(items);
            $$('[data-pack-field]').forEach((control) => {
                const eventName = control.type === 'checkbox' || control.tagName === 'SELECT' ? 'change' : 'input';
                control.addEventListener(eventName, () => {
                    const index = Number(control.dataset.packIndex);
                    const field = control.dataset.packField;
                    let value = control.type === 'checkbox' ? control.checked : control.value;
                    if (['default_assignee_id', 'default_department_id', 'priority_id'].includes(field)) value = value ? Number(value) : null;
                    if (field === 'due_offset_days') value = Number(value || 0);
                    items[index][field] = value;
                });
            });
            $$('[data-pack-up]').forEach((button) => button.addEventListener('click', () => {
                const index = Number(button.dataset.packUp);
                [items[index - 1], items[index]] = [items[index], items[index - 1]];
                renderItems();
            }));
            $$('[data-pack-down]').forEach((button) => button.addEventListener('click', () => {
                const index = Number(button.dataset.packDown);
                [items[index + 1], items[index]] = [items[index], items[index + 1]];
                renderItems();
            }));
            $$('[data-pack-remove]').forEach((button) => button.addEventListener('click', () => {
                if (items.length === 1) {
                    showToast('A Task Pack must contain at least one task.');
                    return;
                }
                items.splice(Number(button.dataset.packRemove), 1);
                renderItems();
            }));
        };

        const showModal = (code) => {
            $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${existing ? 'Edit Task Pack' : 'Create Task Pack'}</h2><div class="small muted" style="margin-top:5px">Build the complete reusable task sequence activated by workflow phases.</div></div><button class="close-btn" data-close>×</button></div>
            <div class="modal-body"><div class="form-grid">
                <div class="field"><label>Task Pack code</label><input id="taskPackCode" value="${html(code)}" readonly aria-readonly="true"><div class="inline-help">Automatically generated and permanently locked.</div></div>
                <div class="field"><label>Status</label><select id="taskPackStatus"><option value="active" ${(existing?.active ?? existing?.is_active ?? true) ? 'selected' : ''}>Active</option><option value="inactive" ${(existing?.active ?? existing?.is_active ?? true) ? '' : 'selected'}>Inactive</option></select></div>
                <div class="field full"><label>Task Pack name *</label><input id="taskPackName" value="${html(existing?.name)}" placeholder="e.g. Quality Inspection"></div>
                <div class="field full"><label>Description</label><textarea id="taskPackDescription" rows="3" placeholder="Explain when this Task Pack is used…">${html(existing?.description)}</textarea></div>
            </div>
            <div class="section-head" style="margin-top:8px"><div><h3>Task sequence</h3><div class="small muted">Tasks are created in this order when the phase becomes active.</div></div><button type="button" class="secondary" id="addTaskPackItem">＋ Add Task</button></div>
            <div id="taskPackItemsEditor" class="task-pack-items-editor"></div></div>
            <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveTaskPack">${existing ? 'Save Task Pack' : 'Create Task Pack'}</button></div>`;
            openOverlay();
            $('#jobModal').classList.add('open');
            $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
            $('#addTaskPackItem').addEventListener('click', () => {
                items.push(blankTaskPackItem());
                renderItems();
                $('#jobModal').scrollTo({ top: $('#jobModal').scrollHeight, behavior: 'smooth' });
            });
            renderItems();

            $('#saveTaskPack').addEventListener('click', async () => {
                const name = $('#taskPackName').value.trim();
                if (!name) {
                    showToast('Task Pack name is required.');
                    $('#taskPackName').focus();
                    return;
                }
                const missingTitleIndex = items.findIndex((item) => !String(item.title || '').trim());
                if (missingTitleIndex >= 0) {
                    showToast(`Task ${missingTitleIndex + 1} needs a title.`);
                    $(`[data-pack-field="title"][data-pack-index="${missingTitleIndex}"]`)?.focus();
                    return;
                }

                const button = $('#saveTaskPack');
                button.disabled = true;
                button.textContent = 'Saving…';
                try {
                    await api(existing ? `/flowtrack/template-api/task-packs/${existing.id}` : '/flowtrack/template-api/task-packs', {
                        method: existing ? 'PUT' : 'POST',
                        body: {
                            name,
                            description: $('#taskPackDescription').value.trim() || null,
                            is_active: $('#taskPackStatus').value === 'active',
                            items: items.map((item) => ({
                                id: item.id || null,
                                title: String(item.title).trim(),
                                description: String(item.description || '').trim() || null,
                                default_assignee_id: item.default_assignee_id || null,
                                default_department_id: item.default_department_id || null,
                                priority_id: item.priority_id || null,
                                due_offset_days: Number(item.due_offset_days || 0),
                                is_required: Boolean(item.is_required),
                            })),
                        },
                    });
                    await loadBootstrap();
                    closeAll();
                    renderSetupContent('packs');
                    showToast(existing ? 'Task Pack updated successfully.' : 'Task Pack and its task sequence created successfully.');
                } catch (error) {
                    showToast(error.message);
                } finally {
                    button.disabled = false;
                    button.textContent = existing ? 'Save Task Pack' : 'Create Task Pack';
                }
            });
        };

        if (existing) {
            showModal(existing.code);
        } else {
            api('/flowtrack/template-api/task-packs/next-code')
                .then((result) => showModal(result.code))
                .catch((error) => showToast(error.message));
        }
    }

    renderSetupContent = function (type) {
        if (type !== 'packs') {
            originalRenderSetupContent(type);
            return;
        }

        const element = $('#setupContent');
        element.innerHTML = `<div class="section-head"><div><h3>Phase-Based Task Packs</h3><div class="small muted">Tasks are generated only when a Job starts or enters the mapped phase.</div></div><button class="primary" id="addTaskPack">＋ Add Task Pack</button></div>
        <div class="pack-list">${taskPacks.map((pack) => `<div class="card pack"><div class="section-head"><div><h4>${html(pack.name)} Task Pack</h4><div class="small muted">${html(pack.code)} · ${(pack.items || []).length} predefined tasks · ${pack.active ? 'Active' : 'Inactive'}</div></div><button class="mini-btn" data-edit-pack-id="${pack.id}">Edit</button></div><div class="small muted">${html(pack.description || 'No description')}</div><ul class="pack-tasks">${(pack.items || []).map((item, index) => `<li><div><span>${index + 1}. ${html(item.title)}</span><div class="small muted" style="margin-top:3px">${html(item.default_assignee_name || item.default_department_name || 'Job coordinator')} · Due +${item.due_offset_days} day(s)${item.priority_name ? ` · ${html(item.priority_name)}` : ''}</div></div><span class="badge ${item.is_required ? 'b-blue' : 'b-gray'}">${item.is_required ? 'Mandatory' : 'Optional'}</span></li>`).join('') || '<li><span class="muted">No tasks configured</span></li>'}</ul></div>`).join('') || '<div class="card empty-state">No Task Packs have been created.</div>'}</div>`;
        $('#addTaskPack').addEventListener('click', () => openTaskPackModal());
        $$('[data-edit-pack-id]').forEach((button) => button.addEventListener('click', () => {
            const pack = taskPacks.find((item) => Number(item.id) === Number(button.dataset.editPackId));
            if (pack) openTaskPackModal(pack);
        }));
    };

    function productByName(name) {
        return (masterData.products || []).find((product) => product.name.toLowerCase() === String(name || '').trim().toLowerCase());
    }

    openJobModal = function (prefillClient) {
        const categories = activeMaster('productCategories');
        const defaultClient = clients.find((client) => Number(client.id) === Number(prefillClient)) || clients[0];
        const defaultPriority = (masterData.priorities || []).find((item) => item.code === 'MED') || activeMaster('priorities')[0];
        const defaultCurrency = (masterData.currencies || []).find((item) => item.code === 'USD') || activeMaster('currencies')[0];
        const salesUsers = users.filter((user) => user.dept === 'Sales');
        const ownerUsers = canFlow('jobs', 'assign') ? (salesUsers.length ? salesUsers : users) : users.filter((user) => Number(user.id) === Number(currentUser?.id));

        $('#jobModal').innerHTML = `<div class="modal-head"><h2>Create New Job</h2><button class="close-btn" data-close>×</button></div><div class="modal-body"><div class="form-grid">
        <div class="field"><label>Client *</label><select id="newJobClient">${clients.map((client) => `<option value="${client.id}" ${Number(defaultClient?.id) === Number(client.id) ? 'selected' : ''}>${html(client.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Client contact</label><input id="newJobContact" value="${html(defaultClient?.contact)}" readonly></div>
        <div class="field full"><label>Job title *</label><input id="newJobTitle" placeholder="e.g. 5,000 printed lanyards for conference"></div>
        <div class="field"><label>Product category *</label><select id="newJobCategory">${categories.map((item) => `<option value="${item.id}">${html(item.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Product *</label><input id="newJobProduct" list="productSuggestions" placeholder="Product name"><datalist id="productSuggestions">${activeMaster('products').map((product) => `<option value="${html(product.name)}"></option>`).join('')}</datalist></div>
        <div class="field"><label>Quantity *</label><input id="newJobQty" type="number" min="1" value="1000"></div>
        <div class="field"><label>Required delivery date *</label><input id="newJobDelivery" type="date"></div>
        <div class="field"><label>Priority</label><select id="newJobPriority">${activeMaster('priorities').map((item) => `<option value="${item.id}" ${Number(item.id) === Number(defaultPriority?.id) ? 'selected' : ''}>${html(item.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Sales owner</label><select id="newJobOwner" ${canFlow('jobs', 'assign') ? '' : 'disabled'}>${ownerUsers.map((user) => `<option value="${user.id}">${html(user.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Workflow template *</label><select id="newJobWorkflow">${workflowTemplates.filter((workflow) => workflow.active).map((workflow) => `<option value="${workflow.id}">${html(workflow.name)}</option>`).join('')}</select><div class="inline-help">The selected workflow controls available starting stages and Task Packs.</div></div>
        <div class="field"><label>Start Job from stage *</label><select id="newJobStartPhase"></select><div class="inline-help">Use this when earlier stages happened outside the system or do not apply.</div></div>
        <div id="skipHandlingWrap" class="field full hidden"><label>How should earlier phases be recorded? *</label><select id="newJobSkipHandling"><option>Completed outside FlowTrack</option><option>Not applicable for this Job</option><option>Migrated from an existing process</option><option>Manager-authorized skip</option></select></div>
        <div id="skipReasonWrap" class="field full hidden"><label>Reason / reference *</label><textarea id="newJobStartReason" rows="2" placeholder="Explain why the Job begins at this stage, and add any external approval or reference."></textarea></div>
        <div class="field full"><label>Request description</label><textarea id="newJobDesc" rows="3" placeholder="Specifications, target price, printing or customization requirements…"></textarea></div>
        </div><div id="jobStagePreview" class="stage-preview"></div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="secondary" id="saveDraftJob">Save Draft</button><button class="primary" id="createJob">Create & Activate Current Task Pack</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));

        const refreshStagePreview = () => {
            const workflow = getWorkflow(Number($('#newJobWorkflow').value));
            const phaseId = Number($('#newJobStartPhase').value || 0);
            const index = workflow.phases.findIndex((phase) => Number(phase.id) === phaseId);
            const phase = workflow.phases[index];
            const skipped = workflow.phases.slice(0, Math.max(0, index));
            $('#skipHandlingWrap').classList.toggle('hidden', index <= 0);
            $('#skipReasonWrap').classList.toggle('hidden', index <= 0);
            $('#jobStagePreview').innerHTML = `<b>Workflow activation preview</b><div class="stage-preview-grid"><div class="stage-preview-box"><span>Job will start at</span><b>${html(phase?.name || '—')}</b></div><div class="stage-preview-box"><span>Task Pack to activate</span><b>${html(phase?.taskPack || 'No tasks at this stage')}</b></div></div>${index > 0 ? `<div class="skip-warning"><b>${skipped.length} earlier phase${skipped.length === 1 ? '' : 's'} will not generate tasks.</b><ul>${skipped.map((item) => `<li>${html(item.name)}</li>`).join('')}</ul><div class="small" style="margin-top:6px">The reason and selected handling will be saved in the Job activity history.</div></div>` : '<div class="small muted" style="margin-top:9px">The Job starts normally from the first stage.</div>'}`;
        };
        const refreshStages = () => {
            const workflow = getWorkflow(Number($('#newJobWorkflow').value));
            const select = $('#newJobStartPhase');
            select.innerHTML = workflow.phases.filter((phase) => phase.allowStart && phase.active !== false).map((phase) => `<option value="${phase.id}">${phase.sequence || workflow.phases.indexOf(phase) + 1}. ${html(phase.name)}</option>`).join('');
            refreshStagePreview();
        };

        $('#newJobClient').addEventListener('change', (event) => {
            const client = clients.find((item) => Number(item.id) === Number(event.target.value));
            $('#newJobContact').value = client?.contact || '';
        });
        $('#newJobWorkflow').addEventListener('change', refreshStages);
        $('#newJobStartPhase').addEventListener('change', refreshStagePreview);
        refreshStages();

        const submit = async (draft) => {
            const title = $('#newJobTitle').value.trim();
            const productName = $('#newJobProduct').value.trim();
            const product = productByName(productName);
            const workflow = getWorkflow(Number($('#newJobWorkflow').value));
            const phaseId = Number($('#newJobStartPhase').value);
            const phaseIndex = workflow.phases.findIndex((phase) => Number(phase.id) === phaseId);
            const reason = $('#newJobStartReason')?.value.trim() || '';

            if (!title || !productName) {
                showToast('Please enter the Job title and product.');
                return;
            }
            if (!product) {
                showToast('Please select a valid product from the product list.');
                return;
            }
            if (phaseIndex > 0 && !reason) {
                showToast('Please explain why earlier workflow phases are being skipped.');
                return;
            }

            const button = draft ? $('#saveDraftJob') : $('#createJob');
            button.disabled = true;
            const initialText = button.textContent;
            button.textContent = draft ? 'Saving…' : 'Creating…';
            try {
                const result = await api('/flowtrack/template-api/jobs', {
                    method: 'POST',
                    body: {
                        client_id: Number($('#newJobClient').value),
                        workflow_template_id: Number($('#newJobWorkflow').value),
                        start_phase_id: phaseId,
                        product_id: product.id,
                        product_category_id: Number($('#newJobCategory').value) || null,
                        currency_id: defaultCurrency?.id || null,
                        priority_id: Number($('#newJobPriority').value) || null,
                        owner_id: Number($('#newJobOwner').value) || currentUser?.id,
                        coordinator_id: currentUser?.id || null,
                        title,
                        quantity: Number($('#newJobQty').value),
                        quoted_amount: 0,
                        delivery_date: $('#newJobDelivery').value || null,
                        start_reason: reason || null,
                        member_ids: [],
                        draft,
                    },
                });
                jobs.unshift(result.job);
                replaceTasksForJob(result.job.id, result.tasks);
                closeAll();
                navigate('jobs');
                updateBadges();
                showToast(draft ? 'Draft Job saved.' : `${result.job.id} created. Progress now follows phase and task statuses.`);
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
            requires_approval: Boolean(phase.approval),
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
            $('#page-workflowsetup').innerHTML = `<div class="page-head"><div><h1>Workflow Setup</h1><p>Define phase sequence, allowed starting stages, Task Packs, approvals and skip rules</p></div><div class="actions"><button class="primary" id="addWorkflow">＋ New Workflow</button></div></div><div class="card empty-state">No workflow templates have been configured.</div>`;
            $('#addWorkflow').addEventListener('click', () => openWorkflowModal());
            return;
        }

        let selected = getWorkflow(Number(selectedWorkflowId));
        if (!selected || !workflowTemplates.some((item) => Number(item.id) === Number(selected.id))) {
            selected = workflowTemplates[0];
            selectedWorkflowId = selected.id;
        }
        const phases = selected.phases || [];

        $('#page-workflowsetup').innerHTML = `<div class="page-head"><div><h1>Workflow Setup</h1><p>Define phase sequence, allowed starting stages, Task Packs, approvals and skip rules</p></div><div class="actions"><button class="ghost" id="cloneWorkflow">Duplicate</button><button class="primary" id="addWorkflow">＋ New Workflow</button></div></div>
        <div class="config-summary"><div class="card config-stat"><span>Active Templates</span><b>${workflowTemplates.filter((workflow) => workflow.active).length}</b></div><div class="card config-stat"><span>Phases in Selected Workflow</span><b>${phases.length}</b></div><div class="card config-stat"><span>Allowed Starting Stages</span><b>${phases.filter((phase) => phase.allowStart && phase.active !== false).length}</b></div><div class="card config-stat"><span>Approval Checkpoints</span><b>${phases.filter((phase) => phase.approval && phase.active !== false).length}</b></div></div>
        <div class="workflow-layout"><div class="card workflow-list"><div class="sidebar-section" style="padding-top:8px">Workflow Templates</div>${workflowTemplates.map((workflow) => `<button class="${Number(workflow.id) === Number(selectedWorkflowId) ? 'active' : ''}" data-workflow-id="${workflow.id}"><b>${html(workflow.name)}</b><small>${workflow.phases.length} phases · ${workflow.active ? 'Active' : 'Inactive'}</small></button>`).join('')}</div>
        <div class="workflow-editor"><div class="card section-card"><div class="section-head"><div><h3>${html(selected.name)}</h3><div class="small muted">${html(selected.description || '')}</div></div><div class="workflow-toolbar"><button class="ghost" id="editWorkflowInfo">Edit Details</button><button class="primary" id="addWorkflowPhase">＋ Add Phase</button></div></div>
        <div class="source-note"><b>Starting-stage rule</b><div class="small muted" style="margin-top:4px">Only phases marked “Allow Job Start” appear in the New Job form. Earlier phases are recorded as skipped, completed outside the system, or migrated.</div></div>
        <div class="table-wrap" style="margin-top:14px"><table class="phase-config-table"><thead><tr><th>#</th><th>Phase</th><th>Task Pack</th><th>Allow Job Start</th><th>Can Skip</th><th>Approval</th><th>Required Document</th><th>Entry / Exit</th><th>Actions</th></tr></thead><tbody>${phases.map((phase, index) => `<tr style="${phase.active === false ? 'opacity:.58' : ''}"><td><div class="sequence-controls"><button data-phase-up="${index}" ${index === 0 ? 'disabled' : ''}>↑</button><button data-phase-down="${index}" ${index === phases.length - 1 ? 'disabled' : ''}>↓</button></div></td><td><b>${html(phase.name)}</b><div class="small muted">Stage ${index + 1}${phase.active === false ? ' · Disabled' : ''}</div></td><td>${html(phase.taskPack || 'No Task Pack')}</td><td><label class="toggle-chip"><input type="checkbox" data-toggle-start="${index}" ${phase.allowStart ? 'checked' : ''} ${phase.active === false ? 'disabled' : ''}> ${phase.allowStart ? 'Allowed' : 'Not allowed'}</label></td><td><label class="toggle-chip"><input type="checkbox" data-toggle-skip="${index}" ${phase.skippable ? 'checked' : ''} ${phase.active === false ? 'disabled' : ''}> ${phase.skippable ? 'Yes' : 'No'}</label></td><td>${phase.approval ? '<span class="badge b-purple">Required</span>' : '<span class="badge b-gray">No</span>'}</td><td>${html(phase.document || '—')}</td><td><div><b>In:</b> ${html(phase.entry || '—')}</div><div class="small muted"><b>Out:</b> ${html(phase.exit || '—')}</div></td><td><div class="row-actions"><button class="mini-btn" data-edit-phase="${index}">Edit</button><button class="mini-btn" data-delete-phase="${index}">Remove</button></div></td></tr>`).join('') || '<tr><td colspan="9"><div class="empty-state">No phases have been configured for this workflow.</div></td></tr>'}</tbody></table></div></div></div></div>`;

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
            const reordered = [...phases];
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
            allowStart: true, skippable: true, approval: false, active: true,
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
        <label class="check-row"><input id="phaseApproval" type="checkbox" ${existing.approval ? 'checked' : ''}> <span>Require approval before phase completion</span></label>
        <label class="check-row"><input id="phaseActive" type="checkbox" ${existing.active !== false ? 'checked' : ''}> <span>Phase active</span></label></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="savePhase">Save Phase</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
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
                requires_approval: $('#phaseApproval').checked,
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
        const style = document.createElement('style');
        style.id = 'flowtrack-access-matrix-styles';
        style.textContent = `
        .access-role-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.access-role-card{padding:16px;cursor:pointer;transition:.15s}.access-role-card:hover{border-color:#aec5f7;transform:translateY(-1px)}.access-role-card.selected{border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.08)}.access-role-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.access-role-icon{width:38px;height:38px;border-radius:11px;background:var(--blue2);color:var(--blue);display:grid;place-items:center;font-weight:800;flex:0 0 auto}.access-role-name{font-weight:750;margin-bottom:4px}.access-role-desc{color:var(--muted);line-height:1.5;font-size:12px;margin-top:10px;min-height:38px}.access-role-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.access-tag{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:650;background:#f1f4f8;color:#536176}.access-role-actions{display:flex;gap:8px;margin-top:14px}.access-role-actions button{flex:1}.access-toolbar{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:14px}.access-toolbar .field{margin:0;min-width:160px}.access-toolbar .field.grow{flex:1}.access-segmented{display:inline-flex;border:1px solid var(--line);border-radius:9px;overflow:hidden;background:#fff}.access-segmented button{border:0;border-right:1px solid var(--line);background:#fff;padding:9px 12px;font-size:12px}.access-segmented button:last-child{border-right:0}.access-matrix-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px}.access-matrix{width:100%;border-collapse:separate;border-spacing:0;min-width:1080px}.access-matrix th,.access-matrix td{padding:10px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);text-align:center}.access-matrix th{background:#f8fafc;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.04em;position:sticky;top:0;z-index:2}.access-matrix th:first-child,.access-matrix td:first-child{text-align:left;position:sticky;left:0;background:#fff;z-index:1}.access-matrix th:first-child{background:#f8fafc;z-index:3}.access-matrix tr:last-child td{border-bottom:0}.access-matrix th:last-child,.access-matrix td:last-child{border-right:0}.access-perm-check{width:18px;height:18px;accent-color:var(--blue)}.access-module-title{font-weight:650}.access-module-sub{font-size:10px;color:var(--muted);margin-top:3px}.access-scope-select{border:1px solid var(--line);border-radius:8px;padding:7px 8px;background:#fff;width:135px;font-size:11px}.access-sensitive-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.access-sensitive-item{border:1px solid var(--line);border-radius:10px;padding:12px;display:flex;align-items:center;justify-content:space-between;gap:10px}.access-switch{position:relative;width:38px;height:22px;display:inline-block;flex:0 0 auto}.access-switch input{opacity:0;width:0;height:0}.access-slider{position:absolute;inset:0;background:#cbd3df;border-radius:999px;transition:.2s}.access-slider:before{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}.access-switch input:checked+.access-slider{background:var(--blue)}.access-switch input:checked+.access-slider:before{transform:translateX(16px)}.access-info{border:1px solid #c6d7fb;background:#f5f8ff;border-radius:11px;padding:13px;color:#31528a;margin-bottom:14px}.access-status{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:650}.access-status.green{background:var(--green2);color:var(--green)}.access-status.blue{background:var(--blue2);color:var(--blue)}.access-status.gray{background:#eef1f5;color:#617084}.access-member-table{min-width:850px}.access-member-table select{border:1px solid var(--line);border-radius:8px;padding:7px 8px;background:#fff;min-width:150px}.access-form-note{padding:10px 12px;border-radius:10px;background:#f7f9fc;color:var(--muted);font-size:12px;line-height:1.5}.access-code-input[readonly]{background:#f5f7fa;color:#66758b}.access-empty{padding:34px;text-align:center;color:var(--muted)}
        @media(max-width:1180px){.access-role-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.access-sensitive-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.access-role-grid,.access-sensitive-grid{grid-template-columns:1fr}.access-toolbar{align-items:stretch}.access-toolbar .field{width:100%}.access-role-actions{flex-direction:column}}
        `;
        document.head.appendChild(style);
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
        <div style="height:16px"></div><div class="card section-card"><div class="section-head"><h3>Permission model</h3></div><div class="grid-3" style="margin-bottom:0"><div class="card" style="padding:15px"><b>Role permission</b><div class="small muted" style="margin-top:6px;line-height:1.5">Controls module actions such as View, Edit, Approve, Export and Manage.</div></div><div class="card" style="padding:15px"><b>Record scope</b><div class="small muted" style="margin-top:6px;line-height:1.5">Limits records to own work, assigned Jobs, selected clients, department or all records.</div></div><div class="card" style="padding:15px"><b>Administrator control</b><div class="small muted" style="margin-top:6px;line-height:1.5">Admin and Super Admin roles always retain every action, every record and every sensitive field.</div></div></div></div>`;

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
        <div class="modal-body"><div class="form-grid"><div class="field"><label>Role name *</label><input id="accessRoleName" value="${html(role.name)}" placeholder="e.g. Quality Inspector"></div><div class="field"><label>Role code *</label><input class="access-code-input" id="accessRoleCode" value="${html(role.code)}" placeholder="QUALITY_INSPECTOR" ${role.system ? 'readonly' : ''}></div><div class="field full"><label>Description</label><textarea id="accessRoleDescription" rows="3" placeholder="What this role is responsible for">${html(role.description)}</textarea></div><div class="field"><label>Default record scope</label><select id="accessRoleDefaultScope" ${administratorRole || !canManageAccess ? 'disabled' : ''}>${accessCatalog.scopes.filter((scope) => scope.code !== 'none').map((scope) => `<option value="${scope.code}" ${(administratorRole ? scope.code === 'all_records' : scope.code === role.defaultScope) ? 'selected' : ''}>${html(scope.label)}</option>`).join('')}</select></div><div class="field"><label>Status</label><select id="accessRoleActive" ${administratorRole || !canManageAccess ? 'disabled' : ''}><option value="1" ${role.active ? 'selected' : ''}>Active</option><option value="0" ${!role.active ? 'selected' : ''}>Inactive</option></select></div>${existing ? '' : `<div class="field full"><label>Copy permissions from</label><select id="accessRoleCopy"><option value="">Start empty</option>${accessRoles.map((item) => `<option value="${item.id}">${html(item.name)}</option>`).join('')}</select></div>`}</div>${administratorRole ? '<div class="access-info"><b>Unrestricted administrator role</b><div class="small" style="margin-top:4px">All modules, actions, records and sensitive fields remain enabled automatically.</div></div>' : (role.system ? '<div class="access-form-note">This is a system role. Its code remains fixed so backend authorization mappings stay stable.</div>' : '')}</div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveAccessRole">${existing ? 'Save Role' : 'Create Role'}</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
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
        const groups = [...new Set(accessCatalog.modules.map((module) => module.group))];
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
                actions: [...document.querySelectorAll(`[data-access-module="${module.code}"]:checked`)].map((checkbox) => checkbox.dataset.accessAction),
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
            return `<tr><td><div class="person"><span class="avatar">${html(member.initials || roleInitials(member.name))}</span><div><b>${html(member.name)}</b><div class="small muted">${html(member.email)}</div>${member.jobTitle ? `<div class="small muted">${html(member.jobTitle)}</div>` : ''}</div></div></td><td>${html(member.department)}</td><td><select data-member-role="${member.id}" ${actorCanEdit ? '' : 'disabled'}>${roleOptions.map((role) => `<option value="${role.id}" ${Number(role.id) === Number(member.roleId) ? 'selected' : ''}>${html(role.name)}</option>`).join('')}</select></td><td><select data-member-status="${member.id}" ${actorCanEdit ? '' : 'disabled'}><option value="active" ${member.status === 'active' ? 'selected' : ''}>Active</option><option value="pending" ${member.status === 'pending' ? 'selected' : ''}>Pending</option><option value="inactive" ${member.status === 'inactive' ? 'selected' : ''}>Inactive</option></select></td><td><div class="row-actions">${actorCanEdit && canManageAccess ? `<button class="mini-btn" data-save-member-role="${member.id}">Save</button>${(isFlowAdmin || isFlowSuperAdmin) ? `<button class="mini-btn" data-edit-access-user="${member.id}">Edit</button>` : ''}` : ''}${actorCanEdit && canDeleteUsers && Number(member.userId) !== Number(currentUser?.id) ? `<button class="danger-btn" data-delete-access-user="${member.id}">Delete</button>` : ''}</div></td></tr>`;
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
        <div class="modal-body"><div class="form-grid"><div class="field"><label>Full name *</label><input id="accessUserName" value="${html(existing?.name || '')}"></div><div class="field"><label>Email *</label><input id="accessUserEmail" type="email" value="${html(existing?.email || '')}"></div><div class="field"><label>Role *</label><select id="accessUserRole">${roles.map((role) => `<option value="${role.id}" ${Number(role.id) === Number(existing?.roleId) ? 'selected' : ''}>${html(role.name)}</option>`).join('')}</select></div><div class="field"><label>Department</label><select id="accessUserDepartment"><option value="">Unassigned</option>${departments.map((department) => `<option value="${department.id}" ${Number(department.id) === Number(existing?.departmentId) ? 'selected' : ''}>${html(department.name)}</option>`).join('')}</select></div><div class="field full"><label>Job title</label><input id="accessUserJobTitle" value="${html(existing?.jobTitle || '')}"></div><div class="field"><label>Status</label><select id="accessUserStatus"><option value="active" ${!existing || existing.status === 'active' ? 'selected' : ''}>Active</option><option value="pending" ${existing?.status === 'pending' ? 'selected' : ''}>Pending</option><option value="inactive" ${existing?.status === 'inactive' ? 'selected' : ''}>Inactive</option></select></div><div class="field"><label>Language</label><select id="accessUserLocale"><option value="en">English</option><option value="zh">中文</option></select></div><div class="field"><label>${existing ? 'New password' : 'Password *'}</label><input id="accessUserPassword" type="password" autocomplete="new-password"></div><div class="field"><label>${existing ? 'Confirm new password' : 'Confirm password *'}</label><input id="accessUserPasswordConfirmation" type="password" autocomplete="new-password"></div></div><div class="access-form-note">Passwords require at least 8 characters with letters and numbers. Admin roles always receive unrestricted system access.</div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveAccessUser">${existing ? 'Save User' : 'Create User'}</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
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
                button.disabled = false;
                button.textContent = existing ? 'Save User' : 'Create User';
            }
        });
    }

    const profileNotificationFields = [
        ['task_assignments', 'Task assignments'],
        ['due_date_reminders', 'Due date reminders'],
        ['mentions_comments', 'Mentions and comments'],
        ['approval_requests', 'Approval requests'],
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

    const setupContentWithTaskPacks = renderSetupContent;
    renderSetupContent = function (type) {
        if (['roles', 'permissions', 'users'].includes(type) && !canViewAccess) {
            $('#setupContent').innerHTML = '<div class="card access-empty">You do not have permission to view roles and access.</div>';
            return;
        }
        if (type === 'roles') return renderAccessRoles();
        if (type === 'permissions') return renderPermissionMatrix();
        if (type === 'users') return renderRoleAssignments();
        return setupContentWithTaskPacks(type);
    };

    renderSetup = function () {
        originalRenderSetup();
        injectAccessMatrixStyles();
        const roleButton = $('.setup-menu [data-setup="roles"]');
        const userButton = $('.setup-menu [data-setup="users"]');
        if (!canViewAccess) {
            roleButton?.remove();
            userButton?.remove();
            return;
        }
        if (roleButton) roleButton.textContent = 'Roles & Policies';
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
    };

    const permissionObserver = new MutationObserver(() => applyActionPermissions());
    ['.content', '#jobModal', '#detailDrawer'].forEach((selector) => {
        const target = document.querySelector(selector);
        if (target) permissionObserver.observe(target, { childList: true, subtree: true });
    });

    $('#logoutBtn')?.addEventListener('click', (event) => {
        event.preventDefault();
        logout();
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) window.location.reload();
    });

    if (window.FLOWTRACK_AUTHENTICATED) {
        loadBootstrap()
            .then(showApplication)
            .catch((error) => {
                if ([401, 419].includes(error.status)) {
                    window.location.replace('/login');
                    return;
                }
                $('#authLoadingScreen')?.classList.add('hidden');
                showToast(error.message);
            });
    } else {
        $('#authLoadingScreen')?.classList.add('hidden');
        $('#loginScreen')?.classList.remove('hidden');
    }
})();
