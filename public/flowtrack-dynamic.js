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
    let boardWorkflowId = null;
    let boardJobIds = new Set();
    let boardAccess = { isAdministrator: false, taskVisibility: 'assigned_job_tasks' };
    let pendingDocumentUploadJobId = null;
    let summaryMetrics = {};
    let dashboardActivities = [];
    let notificationRules = [];
    let notificationAccess = { authorityView: false, statusEndpoint: '/flowtrack/notifications/status' };
    let realtimePusher = null;
    let realtimeChannel = null;
    let realtimeUserId = null;
    let realtimeRefreshTimer = null;
    let sessionExpiryTimer = null;
    let sessionStatusTimer = null;
    let notificationStatusTimer = null;
    let notificationSignature;
    let notificationUnread = 0;



    function sessionRedirect(reason = 'expired') {
        if (sessionExpiryTimer) clearTimeout(sessionExpiryTimer);
        if (sessionStatusTimer) clearInterval(sessionStatusTimer);
        if (notificationStatusTimer) clearInterval(notificationStatusTimer);
        const safeReason = reason === 'replaced' ? 'replaced' : 'expired';
        window.location.replace(`/login?session=${safeReason}`);
    }

    function scheduleSessionGuard(expiresAt = null) {
        const config = window.FLOWTRACK_SESSION || {};
        const resolvedExpiry = expiresAt || config.expiresAt;

        if (sessionExpiryTimer) clearTimeout(sessionExpiryTimer);
        if (sessionStatusTimer) clearInterval(sessionStatusTimer);

        if (resolvedExpiry) {
            config.expiresAt = resolvedExpiry;
            window.FLOWTRACK_SESSION = config;
            const delay = Math.max(0, new Date(resolvedExpiry).getTime() - Date.now());
            sessionExpiryTimer = setTimeout(() => {
                window.location.replace(config.expiredEndpoint || '/flowtrack/session-expired');
            }, delay + 250);
        }

        if (!config.statusEndpoint) return;
        const checkStatus = async () => {
            try {
                const response = await fetch(config.statusEndpoint, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store',
                });
                if (response.status === 401) {
                    const payload = await response.json().catch(() => ({}));
                    sessionRedirect(payload.reason || 'expired');
                    return;
                }
                if (!response.ok) return;
                const payload = await response.json();
                if (payload.expires_at && payload.expires_at !== config.expiresAt) {
                    scheduleSessionGuard(payload.expires_at);
                }
            } catch (_) {
                // A temporary network outage must not log the user out locally.
            }
        };
        checkStatus();
        sessionStatusTimer = setInterval(checkStatus, 10000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) checkStatus();
        });
    }

    async function pollNotificationStatus() {
        if (document.hidden || !notificationAccess?.statusEndpoint) return;

        try {
            const payload = await api(notificationAccess.statusEndpoint);
            const nextSignature = payload?.latest_signature ?? null;
            const nextUnread = Number(payload?.unread_count || 0);

            if (notificationSignature === undefined) {
                const unreadChanged = nextUnread !== notificationUnread;
                notificationSignature = nextSignature;
                notificationUnread = nextUnread;
                if (!unreadChanged) return;

                await loadBootstrap();
                updateBadges();
                if (currentPage) renderPage(currentPage);
                applyActionPermissions();
                return;
            }

            if (nextSignature === notificationSignature && nextUnread === notificationUnread) return;

            notificationSignature = nextSignature;
            notificationUnread = nextUnread;
            await loadBootstrap();
            updateBadges();
            if (currentPage) renderPage(currentPage);
            applyActionPermissions();
        } catch (_) {
            // Polling is the reliable fallback when Pusher is absent or misconfigured.
        }
    }

    function startNotificationPolling() {
        if (notificationStatusTimer) clearInterval(notificationStatusTimer);
        void pollNotificationStatus();
        notificationStatusTimer = setInterval(() => void pollNotificationStatus(), 20000);
    }

    function realtimeNotificationRecord(payload) {
        return {
            id: payload.id || `live-${Date.now()}`,
            title: payload.title || 'Notification',
            text: payload.text || '',
            time: payload.time || 'Just now',
            read: false,
            type: payload.type || 'info',
            job: payload.job || null,
            task: payload.task || null,
            url: payload.url || null,
            event: payload.event || null,
        };
    }

    function scheduleRealtimeRefresh() {
        if (realtimeRefreshTimer) clearTimeout(realtimeRefreshTimer);
        realtimeRefreshTimer = setTimeout(async () => {
            try {
                await loadBootstrap();
                if (currentPage) renderPage(currentPage);
                updateBadges();
                applyActionPermissions();
            } catch (error) {
                console.warn('FlowTrack realtime refresh failed.', error);
            }
        }, 650);
    }

    function receiveRealtimeNotification(payload) {
        const record = realtimeNotificationRecord(payload || {});
        if (!notifications.some((item) => String(item.id) === String(record.id))) {
            notifications.unshift(record);
        }
        updateBadges();
        showToast(`${record.title}${record.text ? `: ${record.text}` : ''}`);
        if (currentPage === 'notifications') renderNotifications();
        window.dispatchEvent(new CustomEvent('flowtrack:realtime-notification', { detail: payload }));
        scheduleRealtimeRefresh();
    }

    function initializeRealtimeNotifications() {
        const config = window.FLOWTRACK_PUSHER || {};
        const userId = Number(currentUser?.id || config.userId || 0);
        if (!config.enabled || !config.key || !userId || typeof window.Pusher !== 'function') return;
        if (realtimePusher && realtimeUserId === userId) return;

        try {
            realtimeChannel?.unbind_all?.();
            realtimePusher?.disconnect?.();

            realtimePusher = new window.Pusher(config.key, {
                cluster: config.cluster || 'ap2',
                forceTLS: true,
                channelAuthorization: {
                    endpoint: config.authEndpoint || '/flowtrack/pusher/auth',
                    transport: 'ajax',
                    headers: {
                        'X-CSRF-TOKEN': config.csrfToken || csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                },
            });
            realtimeUserId = userId;
            realtimeChannel = realtimePusher.subscribe(`private-flowtrack.user.${userId}`);
            realtimeChannel.bind('notification.created', receiveRealtimeNotification);
            realtimeChannel.bind('pusher:subscription_error', (status) => console.warn('FlowTrack Pusher subscription failed.', status));
        } catch (error) {
            console.warn('FlowTrack realtime notifications could not start.', error);
        }
    }

    const originalRenderClients = renderClients;
    const originalRenderDocuments = renderDocuments;
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
        const errors = payload?.errors || {};
        const first = Object.values(errors).flat()[0];
        return first || payload?.message || fallback;
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
            if (response.status === 401 && payload?.reason) {
                setTimeout(() => sessionRedirect(payload.reason), 0);
            }
            const error = new Error(firstValidationMessage(payload, `Request failed (${response.status}).`));
            error.payload = payload;
            error.status = response.status;
            throw error;
        }

        return payload;
    }

    window.renderNotifications = function renderPersistentNotifications() {
        const page = document.querySelector('#page-notifications');
        if (!page) return;

        const rows = notifications.map((notification) => {
            const signal = notification.type === 'risk' || notification.severity === 'warning'
                ? 'red'
                : notification.severity === 'success'
                    ? 'green'
                    : 'purple';

            return `<div class="attention-item" data-notification-id="${html(notification.id)}" style="${notification.read ? 'opacity:.62' : ''}">
                <span class="signal ${signal}"></span>
                <div>
                    <div class="item-title">${html(notification.title)} ${notification.read ? '' : '<span class="badge b-blue">New</span>'}</div>
                    <div class="item-meta">${html(notification.text)}${notification.phase ? ` · ${html(notification.phase)}` : ''} · ${html(notification.time)}</div>
                    ${notificationAccess.authorityView && Array.isArray(notification.audience) && notification.audience.length
                        ? `<div class="item-meta" style="margin-top:4px">For: ${html([...new Set(notification.audience.map((recipient) => recipient?.name).filter(Boolean))].join(', '))}</div>`
                        : ''}
                </div>
                <button class="mini-btn" type="button">Open</button>
            </div>`;
        }).join('');

        page.innerHTML = `<div class="page-head"><div><h1>Notifications</h1><p>${notificationAccess.authorityView ? 'Workspace-wide' : 'Your'} assignment, Task, Workflow, document and health updates</p></div><div class="actions"><button class="ghost" id="markAllRead" type="button" ${notifications.some((item) => !item.read) ? '' : 'disabled'}>Mark all read</button></div></div>
            <div class="card section-card"><div class="attention-list">${rows || '<div class="empty">You are all caught up.</div>'}</div></div>`;

        document.querySelector('#markAllRead')?.addEventListener('click', async () => {
            try {
                await api('/flowtrack/notifications/read-all', { method: 'PATCH' });
                notifications.forEach((item) => { item.read = true; });
                window.renderNotifications();
                updateBadges();
                showToast('All notifications marked as read.');
            } catch (error) {
                showToast(error.message || 'Unable to mark notifications as read.');
            }
        });

        page.querySelectorAll('[data-notification-id]').forEach((row) => {
            row.addEventListener('click', async () => {
                const notification = notifications.find((item) => String(item.id) === String(row.dataset.notificationId));
                if (!notification) return;

                try {
                    if (!notification.read) {
                        await api(`/flowtrack/notifications/${encodeURIComponent(notification.id)}/read`, { method: 'PATCH' });
                        notification.read = true;
                        updateBadges();
                    }

                    if (notification.job_id && jobs.some((job) => String(job.id) === String(notification.job_id))) {
                        openJob(notification.job_id);
                    } else if (notification.url) {
                        window.location.href = notification.url;
                    }
                } catch (error) {
                    showToast(error.message || 'Unable to open the notification.');
                }
            });
        });
    };

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
        boardJobIds = new Set((data.boardJobIds || []).map((id) => Number(id)));
        boardAccess = data.boardAccess || { isAdministrator: false, taskVisibility: 'assigned_job_tasks' };
        replaceArray(tasks, data.tasks);
        replaceArray(docs, data.documents);
        replaceArray(notifications, data.notifications);
        replaceArray(dashboardActivities, data.activities);
        replaceArray(workflowTemplates, data.workflows);
        taskPacks = data.taskPacks || [];
        capabilities = data.capabilities || {};
        summaryMetrics = data.summaryMetrics || {};
        notificationRules = Array.isArray(data.notificationRules) ? data.notificationRules : [];
        notificationAccess = data.notificationAccess || notificationAccess;
        notificationUnread = notifications.filter((notification) => !notification.read).length;
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
        if (!boardWorkflowId || !workflowTemplates.some((workflow) => Number(workflow.id) === Number(boardWorkflowId))) {
            boardWorkflowId = workflowTemplates.find((workflow) => workflow.active !== false)?.id
                ?? workflowTemplates[0]?.id
                ?? null;
        }

        applyCurrentUserIdentity();
        initializeRealtimeNotifications();
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
        taskpacksetup: ['workflow', 'view'],
        masterdata: ['masterdata', 'view'],
        setup: ['users', 'view'],
    };

    function canOpenPage(page) {
        if (['notifications', 'profile'].includes(page)) return true;
        if (page === 'board') return canFlow('jobs', 'view') || canFlow('tasks', 'view');
        if (page === 'setup') return canFlow('users', 'view');
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
        scheduleSessionGuard();
        startNotificationPolling();
    }

    async function login() {
        const button = $('#loginBtn');
        const email = $('#loginEmail').value.trim();
        const password = $('#loginPassword').value;
        const originalText = button.textContent;

        button.disabled = true;
        button.textContent = 'Signing in…';

        try {
            const loginResult = await api('/login', {
                method: 'POST',
                body: { email, password, remember: Boolean($('#loginRemember')?.checked) },
            });
            if (loginResult?.expires_at) {
                window.FLOWTRACK_SESSION = { ...(window.FLOWTRACK_SESSION || {}), expiresAt: loginResult.expires_at };
            }
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
            if (sessionExpiryTimer) clearTimeout(sessionExpiryTimer);
            if (sessionStatusTimer) clearInterval(sessionStatusTimer);
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

    function nextClientCodePreview() {
        const max = clients.reduce((highest, client) => {
            const match = String(client.code || '').match(/^CL-(\d+)$/i);
            return match ? Math.max(highest, Number(match[1])) : highest;
        }, 0);
        return `CL-${String(max + 1).padStart(3, '0')}`;
    }

    function clientPayload(existing = null) {
        const managerValue = $('#clientManager').value;
        const payload = {
            name: $('#clientName').value.trim(),
            country: $('#clientCountryInput').value.trim() || null,
            account_manager_id: managerValue ? Number(managerValue) : null,
            preferred_language: $('#clientLanguage').value,
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
        const displayedCode = existing?.code || nextClientCodePreview();
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${existing ? 'Edit Client' : 'Create New Client'}</h2><div class="small muted" style="margin-top:5px">Client details are shared across Jobs, documents and reporting.</div></div><button class="close-btn" data-close>×</button></div>
        <div class="modal-body"><div class="form-grid client-form-stack">
            <div class="field full client-code-field"><label>Client code</label><input id="clientCode" value="${html(displayedCode)}" readonly aria-readonly="true" tabindex="-1"><div class="client-code-note">Automatically generated by FlowTrack and permanently locked.</div></div>
            <div class="field full"><label>Client name *</label><input id="clientName" value="${html(existing?.name)}" placeholder="Company or organisation name"></div>
            <div class="field full"><label>Country</label><input id="clientCountryInput" value="${html(existing?.country)}" placeholder="e.g. United States"></div>
            <div class="field full client-sensitive-contact"><label>Primary contact</label><input id="clientContact" value="${html(existing?.contact || existing?.contact_name)}" placeholder="Contact person"></div>
            <div class="field full client-sensitive-contact"><label>Email</label><input id="clientEmail" type="email" value="${html(existing?.email)}" placeholder="contact@company.com"></div>
            <div class="field full client-sensitive-contact"><label>Phone</label><input id="clientPhone" value="${html(existing?.phone)}" placeholder="Phone number"></div>
            <div class="field full"><label>Account manager</label><select id="clientManager" data-searchable data-search-placeholder="Search account managers…" ${canFlow('clients', 'assign') ? '' : 'disabled'}><option value="">Unassigned</option>${users.map((user) => `<option value="${user.id}" ${Number(existing?.manager_id || existing?.account_manager_id) === Number(user.id) ? 'selected' : ''}>${html(user.name)}</option>`).join('')}</select></div>
            <div class="field full"><label>Preferred language</label><select id="clientLanguage" data-searchable data-search-placeholder="Search languages…"><option value="en" ${(existing?.preferred_language || 'en') === 'en' ? 'selected' : ''}>English</option><option value="zh" ${existing?.preferred_language === 'zh' ? 'selected' : ''}>Simplified Chinese</option></select></div>
            <div class="field full client-sensitive-financial"><label>Outstanding balance</label><input id="clientOutstanding" type="number" min="0" step="0.01" value="${Number(existing?.outstanding ?? existing?.outstanding_balance ?? 0)}"></div>
            <div class="field full client-sensitive-contact"><label>Notes</label><textarea id="clientNotes" rows="3" placeholder="Commercial notes, preferences or account instructions…">${html(existing?.notes)}</textarea></div>
        </div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveClientBtn">${existing ? 'Save Changes' : 'Create Client'}</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        applyActionPermissions();
        window.FlowtrackSearchableDropdown?.enhance($('#jobModal'));

        $('#saveClientBtn').addEventListener('click', async () => {
            const button = $('#saveClientBtn');
            const payload = clientPayload(existing);
            if (!payload.name) {
                showToast('Client name is required.');
                $('#clientName').focus();
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
                showToast(existing ? 'Client updated successfully.' : `${result.client.code} created successfully.`);
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

    function jobDocumentRequirements(job) {
        const configured = Array.isArray(job?.documentRequirements) ? job.documentRequirements : [];
        return configured.map((requirement) => {
            const phaseId = Number(requirement.workflow_phase_id || requirement.workflowPhaseId || job?.phaseId || 0);
            const itemId = requirement.task_pack_item_id || requirement.taskPackItemId || null;
            const categoryId = Number(requirement.category_id || requirement.categoryId || 0);
            return {
                key: requirement.requirement_key || `${itemId ? 'task' : 'phase'}:${itemId || phaseId}:${categoryId}`,
                phaseId,
                phaseName: requirement.workflow_phase_name || requirement.workflowPhaseName || phaseNameFor(job),
                taskPackItemId: itemId ? Number(itemId) : null,
                categoryId,
                categoryName: requirement.category_name || requirement.categoryName || 'Required document',
                sourceLabel: requirement.source_label || (requirement.sources || [])[0] || 'Workflow requirement',
                requiredForReadiness: requirement.required_for_readiness !== false,
                present: docs.some((documentRecord) =>
                    documentRecord.job === job?.id
                    && Number(documentRecord.phaseId) === phaseId
                    && Number(documentRecord.categoryId) === categoryId
                    && Number(documentRecord.taskPackItemId || 0) === Number(itemId || 0)
                ),
            };
        }).filter((requirement) => requirement.categoryId && requirement.phaseId);
    }

    function jobPhaseDocumentRequirement(job) {
        const requirements = jobDocumentRequirements(job);
        const first = requirements[0] || null;
        return first
            ? { ...first, requirements, present: requirements.filter((item) => item.requiredForReadiness).every((requirement) => requirement.present) }
            : { categoryId: null, categoryName: '', present: true, requirements: [] };
    }

    function goToDocumentUpload(job) {
        if (!job) return;
        pendingDocumentUploadJobId = job.id;
        closeAll();
        navigate('documents');
        setTimeout(() => {
            const selectedJob = jobs.find((item) => item.id === pendingDocumentUploadJobId);
            pendingDocumentUploadJobId = null;
            if (selectedJob) openJobDocumentUpload(selectedJob, true);
        }, 60);
    }

    function openJobDocumentUpload(job, requiredOnly = false) {
        $('#jobModal')?.classList.remove('document-job-chooser');
        if (!job) return;
        if (currentPage !== 'documents') {
            goToDocumentUpload(job);
            return;
        }

        const requirements = [...jobDocumentRequirements(job)]
            .sort((left, right) => Number(left.present) - Number(right.present));
        if (!requirements.length) {
            showToast('No required document is configured for this Job’s current phase.');
            return;
        }

        const defaultRequirement = requirements.find((item) => !item.present) || requirements[0];
        const singleRequirement = requirements.length === 1;
        const canLinkAndContinue = canFlow('documents', 'link');
        const title = singleRequirement ? `Upload ${html(defaultRequirement.categoryName)}` : 'Upload Required Job Document';

        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${title}</h2><div class="small muted" style="margin-top:5px">Upload from Documents. The file will be linked to ${html(job.id)}, ${html(defaultRequirement.phaseName)}, and its exact Workflow requirement.</div></div><button class="close-btn" data-upload-close>×</button></div>
        <div class="modal-body"><div class="source-note"><b>Job and client</b><div class="small muted" style="margin-top:4px">${html(job.id)} · ${html(job.title)} · ${html(clientName(job.client))}</div></div>
        <div class="source-note" style="margin-top:12px;background:var(--amber2);color:var(--amber)"><b>Current Workflow Phase</b><div class="small" style="margin-top:4px">${html(defaultRequirement.phaseName)}</div></div>
        <div class="form-grid" style="margin-top:16px">
          <div class="field full"><label>Required document *</label>${singleRequirement
              ? `<input value="${html(defaultRequirement.categoryName)} · ${html(defaultRequirement.sourceLabel)}" readonly aria-readonly="true"><input id="jobDocumentRequirement" type="hidden" value="${html(defaultRequirement.key)}">`
              : `<select id="jobDocumentRequirement" data-searchable data-search-placeholder="Search required documents…">${requirements.map((requirement) => `<option value="${html(requirement.key)}" ${requirement.key === defaultRequirement.key ? 'selected' : ''}>${html(requirement.categoryName)} · ${html(requirement.sourceLabel)}${requirement.present ? ' · Uploaded' : ''}</option>`).join('')}</select>`}
            <div class="inline-help">Only files configured in Workflow Setup or Task Pack Setup for this current Phase can be uploaded.</div>
          </div>
          <div class="field full"><label>File *</label><input id="jobDocumentFile" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip"><div class="inline-help">PDF, Office documents, images or ZIP. Maximum 20 MB.</div></div>
          <div class="field full"><label>Notes</label><textarea id="jobDocumentNotes" rows="3" placeholder="Add a version note, reference number or document description…"></textarea></div>
          <label class="check-row full"><input id="jobDocumentFinal" type="checkbox"> <span>Mark this as the final file</span></label>
          ${canLinkAndContinue ? `<label class="check-row full"><input id="jobDocumentAdvance" type="checkbox" checked> <span>When every current-phase Task and document requirement is complete, continue the Job to the next Phase and activate its Task Pack.</span></label>` : ''}
        </div></div>
        <div class="modal-foot"><button class="ghost" data-upload-close>Cancel</button><button class="primary" id="uploadJobDocumentBtn">${canLinkAndContinue ? 'Upload, Link & Continue' : 'Upload & Link'}</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-upload-close]').forEach((button) => button.addEventListener('click', closeAll));
        window.FlowtrackSearchableDropdown?.enhance($('#jobModal'));

        $('#uploadJobDocumentBtn').addEventListener('click', async () => {
            const file = $('#jobDocumentFile').files?.[0];
            const requirement = requirements.find((item) => item.key === $('#jobDocumentRequirement').value);
            if (!file) {
                showToast('Choose a document to upload.');
                $('#jobDocumentFile').focus();
                return;
            }
            if (!requirement) {
                showToast('Select an exact document requirement for the current Workflow Phase.');
                return;
            }

            const button = $('#uploadJobDocumentBtn');
            const formData = new FormData();
            formData.append('job_id', String(job.dbId));
            formData.append('workflow_phase_id', String(requirement.phaseId));
            if (requirement.taskPackItemId) formData.append('task_pack_item_id', String(requirement.taskPackItemId));
            formData.append('document_category_id', String(requirement.categoryId));
            formData.append('file', file);
            formData.append('is_final', $('#jobDocumentFinal').checked ? '1' : '0');
            formData.append('advance_phase', canLinkAndContinue && $('#jobDocumentAdvance')?.checked ? '1' : '0');
            const notes = $('#jobDocumentNotes').value.trim();
            if (notes) formData.append('notes', notes);

            button.disabled = true;
            button.textContent = 'Uploading…';
            try {
                const result = await api('/flowtrack/template-api/documents', { method: 'POST', body: formData });
                const documentIndex = docs.findIndex((item) => Number(item.dbId) === Number(result.document.dbId));
                if (documentIndex >= 0) docs.splice(documentIndex, 1, result.document);
                else docs.unshift(result.document);
                const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                closeAll();
                renderDocuments();
                showToast(result.message || 'Document uploaded and linked.');
            } catch (error) {
                showToast(error.message);
                button.disabled = false;
                button.textContent = canLinkAndContinue ? 'Upload, Link & Continue' : 'Upload & Link';
            }
        });
    }

    function openRequiredDocumentJobChooser() {
        if (!canFlow('documents', 'create') || !canFlow('documents', 'link')) {
            showToast('Your role does not have Document Upload & Link access.');
            return;
        }
        const eligibleJobs = jobs.filter((job) => jobDocumentRequirements(job).length);
        if (!eligibleJobs.length) {
            showToast('No visible Job currently has a required document configured for its current phase.');
            return;
        }

        $('#jobModal').classList.add('document-job-chooser');
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Upload Required Job Document</h2><div class="small muted" style="margin-top:5px">Choose the Job first. Only its configured Workflow Phase and Task Pack document categories will be available.</div></div><button class="close-btn" data-required-job-close>×</button></div>
        <div class="modal-body"><div class="field"><label>Job *</label><select id="requiredDocumentJob" data-searchable data-dropdown-direction="down" data-search-placeholder="Search Jobs…">${eligibleJobs.map((job) => `<option value="${job.id}">${html(job.id)} · ${html(job.title)} · ${html(clientName(job.client))}</option>`).join('')}</select></div></div>
        <div class="modal-foot"><button class="ghost" data-required-job-close>Cancel</button><button class="primary" id="continueRequiredDocument">Continue</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-required-job-close]').forEach((button) => button.addEventListener('click', closeAll));
        window.FlowtrackSearchableDropdown?.enhance($('#jobModal'));
        $('#continueRequiredDocument').addEventListener('click', () => {
            const job = jobs.find((item) => item.id === $('#requiredDocumentJob').value);
            if (!job) return;
            closeAll();
            setTimeout(() => openJobDocumentUpload(job, true), 30);
        });
    }

    renderDocuments = function () {
        originalRenderDocuments();
        const uploadButton = $('#uploadBtn');
        if (uploadButton) {
            const cleanButton = uploadButton.cloneNode(true);
            cleanButton.textContent = '＋ Upload Required Document';
            uploadButton.replaceWith(cleanButton);
            cleanButton.addEventListener('click', openRequiredDocumentJobChooser);
        }
        const uploadZone = $('#uploadZone');
        if (uploadZone) {
            const replacement = document.createElement('div');
            replacement.id = 'uploadZone';
            replacement.className = 'upload-zone';
            replacement.tabIndex = 0;
            replacement.innerHTML = '<b>Upload a required Job document</b><div class="muted small" style="margin-top:5px">Only document categories configured in Workflow Setup or Task Pack Setup can be uploaded.</div>';
            uploadZone.replaceWith(replacement);
            replacement.addEventListener('click', openRequiredDocumentJobChooser);
            replacement.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') openRequiredDocumentJobChooser();
            });
        }
        applyActionPermissions();
    };

    function openDeleteJobConfirmation(job) {
        const taskCount = tasks.filter((task) => task.job === job.id).length;
        const documentCount = docs.filter((documentRecord) => documentRecord.job === job.id).length;
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Delete this Job?</h2><div class="small muted" style="margin-top:5px">Review the impact before permanently removing the Job.</div></div><button class="close-btn" data-delete-close>×</button></div>
        <div class="modal-body"><div class="card" style="padding:16px;border-color:#fecaca;background:#fff7f7"><div class="small muted">${html(job.id)}</div><h3 style="margin:5px 0 8px">${html(job.title)}</h3><div class="small">Deleting this Job will also remove <b>${taskCount} task${taskCount === 1 ? '' : 's'}</b> and <b>${documentCount} document record${documentCount === 1 ? '' : 's'}</b> connected to it.</div></div>
        <label class="check-row" style="margin-top:16px"><input id="confirmJobDeletion" type="checkbox"> <span>I understand that this action cannot be undone.</span></label></div>
        <div class="modal-foot"><button class="ghost" data-delete-close>Keep Job</button><button class="danger-btn" id="confirmDeleteJobBtn" disabled>Delete Job Permanently</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-delete-close]').forEach((button) => button.addEventListener('click', () => {
            closeAll();
            setTimeout(() => openJob(job.id), 30);
        }));
        $('#confirmJobDeletion').addEventListener('change', (event) => {
            $('#confirmDeleteJobBtn').disabled = !event.target.checked;
        });
        $('#confirmDeleteJobBtn').addEventListener('click', async () => {
            const button = $('#confirmDeleteJobBtn');
            button.disabled = true;
            button.textContent = 'Deleting…';
            try {
                await api(`/flowtrack/template-api/jobs/${job.dbId}`, { method: 'DELETE' });
                const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(job.dbId));
                if (jobIndex >= 0) jobs.splice(jobIndex, 1);
                for (let i = tasks.length - 1; i >= 0; i -= 1) if (tasks[i].job === job.id) tasks.splice(i, 1);
                for (let i = docs.length - 1; i >= 0; i -= 1) if (docs[i].job === job.id) docs.splice(i, 1);
                closeAll();
                renderPage(currentPage);
                updateBadges();
                showToast('Job deleted successfully.');
            } catch (error) {
                showToast(error.message);
                button.disabled = false;
                button.textContent = 'Delete Job Permanently';
            }
        });
    }

    function openRemoveJobPhaseConfirmation(job, phase) {
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Remove this Phase?</h2><div class="small muted" style="margin-top:5px">This affects only this Job. The shared workflow template remains unchanged.</div></div><button class="close-btn" data-phase-remove-close>×</button></div>
        <div class="modal-body"><div class="card" style="padding:16px;border-color:#fecaca;background:#fff7f7"><div class="small muted">${html(job.id)}</div><h3 style="margin:5px 0 8px">${html(phase.name)}</h3><div class="small">Tasks in this phase will be removed from the Job and will not be regenerated. Documents stay archived but are detached from this phase.</div></div><div class="field" style="margin-top:16px"><label>Reason (optional)</label><textarea id="phaseRemovalReason" rows="3" placeholder="Why is this phase not needed for this Job?"></textarea></div></div>
        <div class="modal-foot"><button class="ghost" data-phase-remove-close>Keep Phase</button><button class="danger-btn" id="confirmRemoveJobPhase">Remove Phase</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-phase-remove-close]').forEach((button) => button.addEventListener('click', () => {
            closeAll();
            setTimeout(() => openJob(job.id), 30);
        }));
        $('#confirmRemoveJobPhase').addEventListener('click', async () => {
            const button = $('#confirmRemoveJobPhase');
            button.disabled = true;
            button.textContent = 'Removing…';
            try {
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/phases/${phase.id}`, {
                    method: 'DELETE',
                    body: { reason: $('#phaseRemovalReason').value.trim() || null },
                });
                const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                replaceTasksForJob(result.job.id, result.tasks);
                closeAll();
                renderPage(currentPage);
                updateBadges();
                showToast(`${phase.name} was removed from this Job.`);
                setTimeout(() => openJob(result.job.id), 30);
            } catch (error) {
                showToast(error.message);
                button.disabled = false;
                button.textContent = 'Remove Phase';
            }
        });
    }

    openJob = function (id) {
        const job = jobs.find((item) => item.id === id);
        originalOpenJob(id);
        if (!job || !$('#detailDrawer')?.classList.contains('open')) return;

        const head = $('#detailDrawer .drawer-head');
        const close = head?.querySelector('.close-btn');
        const titleBlock = head?.firstElementChild;
        if (titleBlock && !titleBlock.querySelector('[data-job-assignee]')) {
            const assignee = document.createElement('div');
            assignee.className = 'job-header-assignee';
            assignee.dataset.jobAssignee = String(job.ownerId || '');
            assignee.innerHTML = `<span class="small muted">Assignee</span><span class="avatar">${userInitials(job.owner || 'Unassigned')}</span><b>${html(job.owner || 'Unassigned')}</b>`;
            titleBlock.appendChild(assignee);
        }
        if (head && close) {
            let actionGroup = head.querySelector('.job-header-actions');
            if (!actionGroup) {
                actionGroup = document.createElement('div');
                actionGroup.className = 'actions job-header-actions';
                head.insertBefore(actionGroup, close);
                actionGroup.appendChild(close);
            }
            if (canEditJobRecord() && !actionGroup.querySelector('[data-edit-job]')) {
                const editButton = document.createElement('button');
                editButton.className = 'secondary';
                editButton.dataset.editJob = String(job.dbId);
                editButton.textContent = '✎ Edit Job';
                editButton.addEventListener('click', () => {
                    closeAll();
                    setTimeout(() => openEditJobModal(job), 30);
                });
                actionGroup.insertBefore(editButton, close);
            }
            if (canFlow('jobs', 'delete') && !actionGroup.querySelector('[data-job-actions]')) {
                const button = document.createElement('button');
                button.className = 'ghost';
                button.dataset.jobActions = String(job.dbId);
                button.textContent = 'Job Actions';
                button.title = 'Open Job actions';
                button.addEventListener('click', () => {
                    closeAll();
                    setTimeout(() => openDeleteJobConfirmation(job), 30);
                });
                actionGroup.insertBefore(button, close);
            }
        }

        const workflow = getWorkflow(Number(job.workflow));
        const excludedPhaseIds = new Set((job.excludedPhaseIds || []).map((phaseId) => Number(phaseId)));
        const activePhases = (workflow?.phases || [])
            .filter((phase) => phase.active !== false && !excludedPhaseIds.has(Number(phase.id)))
            .slice()
            .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(job.phaseId));
        const timeline = $('#detailPanel1 .timeline');
        if (timeline && activePhases.length) {
            timeline.innerHTML = activePhases.map((phase) => {
                const originalIndex = (workflow?.phases || []).findIndex((item) => Number(item.id) === Number(phase.id));
                const skipped = (job.skippedPhases || []).includes(originalIndex);
                const beforeCurrent = currentPhase && Number(phase.sequence) < Number(currentPhase.sequence);
                const isCurrent = Number(phase.id) === Number(job.phaseId);
                const state = skipped ? 'skipped' : beforeCurrent ? 'done' : isCurrent ? 'current' : '';
                const dot = skipped ? '↷' : beforeCurrent ? '✓' : phase.sequence;
                const remove = job.canDeletePhases && activePhases.length > 1
                    ? `<button class="mini-btn" data-remove-job-phase="${phase.id}" style="display:block;margin:6px auto 0">Remove</button>`
                    : '';
                return `<div class="step ${state}"><div class="step-dot">${dot}</div><div class="step-label">${html(phase.name)}${skipped ? '<br><small>Skipped / external</small>' : ''}${remove}</div></div>`;
            }).join('');
            $$('[data-remove-job-phase]').forEach((button) => button.addEventListener('click', (event) => {
                event.stopPropagation();
                const phase = activePhases.find((item) => Number(item.id) === Number(button.dataset.removeJobPhase));
                if (!phase) return;
                closeAll();
                setTimeout(() => openRemoveJobPhaseConfirmation(job, phase), 30);
            }));
        }

        const requirement = jobPhaseDocumentRequirement(job);
        const workflowActions = $('#detailPanel1 .actions');
        if (canFlow('documents', 'create') && canFlow('documents', 'link') && workflowActions && requirement.categoryId && !requirement.present && !workflowActions.querySelector('[data-upload-required-document]')) {
            const uploadButton = document.createElement('button');
            uploadButton.className = 'secondary';
            uploadButton.dataset.uploadRequiredDocument = job.id;
            uploadButton.textContent = 'Open Document Upload';
            workflowActions.insertBefore(uploadButton, workflowActions.firstChild);
            uploadButton.addEventListener('click', () => goToDocumentUpload(job));
        }

        $$('[data-upload-job]').forEach((button) => {
            if (!canFlow('documents', 'create') || !canFlow('documents', 'link') || !requirement.requirements?.length) { button.remove(); return; }
            button.textContent = 'Open Documents Page';
            button.addEventListener('click', () => goToDocumentUpload(job));
        });
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

    function normalizedStatusValue(value) {
        return String(value ?? '').trim().toUpperCase();
    }

    function taskStatusRecord(value) {
        const statuses = masterData.taskStatuses || [];
        if (value && typeof value === 'object') {
            if (value.id !== undefined && value.id !== null) {
                const byId = statuses.find((status) => Number(status.id) === Number(value.id));
                if (byId) return byId;
            }

            const objectCode = normalizedStatusValue(value.code);
            const objectName = normalizedStatusValue(value.name);
            const byObjectValue = statuses.find((status) => {
                const code = normalizedStatusValue(status.code);
                const name = normalizedStatusValue(status.name);
                return (objectCode && code === objectCode) || (objectName && name === objectName);
            });
            if (byObjectValue) return byObjectValue;
        }

        const numericValue = Number(value);
        if (Number.isFinite(numericValue)) {
            const byId = statuses.find((status) => Number(status.id) === numericValue);
            if (byId) return byId;
        }

        const normalized = normalizedStatusValue(value);
        return statuses.find((status) =>
            normalizedStatusValue(status.code) === normalized
            || normalizedStatusValue(status.name) === normalized
        );
    }

    function selectableTaskStatuses(currentStatus = null) {
        const current = taskStatusRecord(currentStatus);
        return (masterData.taskStatuses || []).filter((status) =>
            String(status.status || 'Active').toLowerCase() === 'active'
            || Number(status.id) === Number(current?.id)
        );
    }

    function taskStatusMatches(status, values) {
        const allowed = values.map((value) => normalizedStatusValue(value));
        if (status && typeof status === 'object') {
            return allowed.includes(normalizedStatusValue(status.code))
                || allowed.includes(normalizedStatusValue(status.name));
        }

        return allowed.includes(normalizedStatusValue(status));
    }

    function taskProgressForStatus(status) {
        if (taskStatusMatches(status, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'])) return 100;
        if (taskStatusMatches(status, ['WC', 'WAITING FOR CLIENT', 'WS', 'WAITING FOR SUPPLIER', 'WIA', 'WAITING FOR INTERNAL APPROVAL', 'WAITING FOR INTERNAL REVIEW'])) return 65;
        if (taskStatusMatches(status, ['IP', 'IN PROGRESS', 'BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD'])) return 50;
        if (taskStatusMatches(status, ['REV', 'REVISION REQUIRED'])) return 35;
        if (taskStatusMatches(status, ['READY'])) return 10;
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
            if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
        }
    }

    function replaceTasksForJob(jobCode, newTasks) {
        for (let index = tasks.length - 1; index >= 0; index -= 1) {
            if (tasks[index].job === jobCode) tasks.splice(index, 1);
        }
        tasks.push(...(newTasks || []));
    }

    async function persistTask(task, status, extras = {}) {
        if (!task?.canUpdate && !isFlowAdmin && !isFlowSuperAdmin) {
            throw new Error('Only the assigned user, an Administrator, or the Super Admin can update this Task.');
        }
        const statusRecord = taskStatusRecord(status);
        if (!statusRecord) throw new Error('The selected task status is not available in this workspace.');
        const payload = {
            task_status_id: statusRecord.id,
            assignee_id: extras.assignee_id ?? task.assigneeId ?? null,
            due_date: extras.due_date ?? task.due ?? null,
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
        const selectedStatus = taskStatusRecord(task.statusId || task.statusCode || task.status);
        const statuses = selectableTaskStatuses(selectedStatus);

        $('#detailDrawer').innerHTML = `<div class="drawer-head"><div><div class="small muted">${task.id} · ${task.job}</div><h2>${html(task.title)}</h2><div>${statusBadge(task.status)} <span class="badge ${task.priority === 'Critical' ? 'b-red' : task.priority === 'High' ? 'b-amber' : 'b-gray'}">${html(task.priority)}</span></div></div><button class="close-btn" data-close>×</button></div>
        <div class="drawer-body"><div class="summary-grid"><div class="summary-box"><span>Job</span><b>${html(job?.title || task.job)}</b></div><div class="summary-box"><span>Assignee</span><b>${html(task.assignee)}</b></div><div class="summary-box"><span>Due Date</span><b class="${dueClass(task.due)}">${formatDate(task.due)}</b></div></div>
        <div class="form-grid" style="margin-top:18px"><div class="field"><label>Status</label><select id="taskUpdateStatus" ${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? '' : 'disabled'}>${statuses.map((status) => `<option value="${status.id}" ${Number(status.id) === Number(selectedStatus?.id) ? 'selected' : ''}>${html(status.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Assignee</label><select id="taskUpdateAssignee" ${task.canAssign || isFlowAdmin || isFlowSuperAdmin ? '' : 'disabled'}><option value="">Unassigned</option>${users.map((user) => `<option value="${user.id}" ${Number(user.id) === Number(task.assigneeId) ? 'selected' : ''}>${html(user.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Due Date</label><input type="date" id="taskUpdateDueDate" value="${task.due ? task.due.split('T')[0] : ''}" ${task.canChangeDueDate || isFlowAdmin || isFlowSuperAdmin ? '' : 'disabled'}><div class="inline-help">Available for regular tasks and subtasks.</div></div>
        <div class="field full"><label>Progress: <b id="progressValue">${task.progress}%</b></label><input id="taskProgress" type="range" min="0" max="100" value="${task.progress}" disabled><div class="inline-help">Progress is calculated automatically from the selected status.</div></div></div>
        <div class="section-head"><h3>Checklist</h3><span class="small muted">${task.checklist.filter((item) => item.done).length}/${task.checklist.length} complete</span></div>
        ${task.checklist.map((item, index) => `<label class="check-row"><input class="task-check" data-check="${index}" type="checkbox" ${item.done ? 'checked' : ''} ${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? '' : 'disabled'}> <span>${html(item.text)}</span></label>`).join('')}
        <div class="field" style="margin-top:18px"><label>Update comment</label><textarea id="taskComment" rows="3" placeholder="Add progress, decision, blocker or next action…" ${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? '' : 'disabled'}></textarea></div>
        <label class="check-row"><input id="taskAttention" type="checkbox" ${task.attention ? 'checked' : ''} ${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? '' : 'disabled'}> <span>Mark as attention needed</span></label>
        <div class="section-head" style="margin-top:18px"><h3>Activity & Comments</h3></div>
        ${(task.comments || []).map((comment) => `<div class="comment"><span class="avatar">${userInitials(comment.user)}</span><div><b>${html(comment.user)}</b><div style="margin-top:4px">${html(comment.text)}</div><div class="small muted" style="margin-top:4px">${html(comment.time)}</div></div></div>`).join('')}
        ${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? '' : '<div class="source-note" style="margin-top:18px"><b>Limited Task Access</b><div class="small muted" style="margin-top:4px">Everyone assigned to this Job can view its Tasks. Full updates remain limited to the assignee or an Administrator; authorised Job managers can still change the due date.</div></div>'}
        <div class="actions" style="margin-top:18px">${task.canDelete || isFlowAdmin || isFlowSuperAdmin ? `<button class="danger-btn" data-delete-task="${task.dbId}">Delete Task</button>` : ''}${task.canChangeDueDate || isFlowAdmin || isFlowSuperAdmin ? '<button class="secondary" id="saveTaskDueDate">Save Due Date</button>' : ''}${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? '<button class="primary" id="saveTaskUpdate" style="flex:1">Save Task Update</button>' : '<button class="ghost" data-close style="flex:1">Close</button>'}</div></div>`;

        openOverlay();
        $('#detailDrawer').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));

        const syncProgressPreview = () => {
            const status = taskStatusRecord($('#taskUpdateStatus').value);
            const progress = taskProgressForStatus(status);
            $('#taskProgress').value = String(progress);
            $('#progressValue').textContent = `${progress}%`;
        };
        $('#taskUpdateStatus')?.addEventListener('change', syncProgressPreview);
        syncProgressPreview();
        $('[data-delete-task]')?.addEventListener('click', async () => {
            if (!confirm(`Delete task ${task.id}?`)) return;
            try {
                const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'DELETE' });
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                    replaceTasksForJob(result.job.id, result.tasks || []);
                }
                closeAll(); renderPage(currentPage); updateBadges(); showToast('Task deleted successfully.');
            } catch (error) { showToast(error.message); }
        });
        applyActionPermissions();

        $('#saveTaskDueDate')?.addEventListener('click', async () => {
            const button = $('#saveTaskDueDate');
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const result = await api(`/flowtrack/template-api/tasks/${task.dbId}/due-date`, {
                    method: 'PATCH',
                    body: { due_date: $('#taskUpdateDueDate').value || null },
                });
                updateTaskAndJob(result);
                renderPage(currentPage);
                updateBadges();
                showToast('Task due date updated.');
                openTask(result.task?.id || task.id);
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Save Due Date';
            }
        });

        $('#saveTaskUpdate')?.addEventListener('click', async () => {
            const status = taskStatusRecord($('#taskUpdateStatus').value);
            const comment = $('#taskComment').value.trim();
            const attention = $('#taskAttention').checked;
            const isBlocked = taskStatusMatches(status, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);
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
                    due_date: $('#taskUpdateDueDate').value || null,
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
            const configuredStatuses = (masterData.taskStatuses || [])
                .filter((status) => String(status.status || 'Active').toLowerCase() === 'active')
                .map((status) => ({ id: status.id, name: status.name, code: status.code, configured: true }));
            const configuredIds = new Set(configuredStatuses.map((status) => Number(status.id)));
            const legacyStatuses = [];
            tasks.forEach((task) => {
                if (task.statusId && !configuredIds.has(Number(task.statusId)) && !legacyStatuses.some((status) => Number(status.id) === Number(task.statusId))) {
                    legacyStatuses.push({
                        id: task.statusId,
                        name: task.status || 'Inactive status',
                        code: task.statusCode || null,
                        configured: false,
                    });
                } else if (!task.statusId && !legacyStatuses.some((status) => status.id === null && status.name === (task.status || 'Not Started'))) {
                    legacyStatuses.push({
                        id: null,
                        name: task.status || 'Not Started',
                        code: task.statusCode || null,
                        configured: false,
                    });
                }
            });
            const columns = [...configuredStatuses, ...legacyStatuses];
            if (!columns.length) columns.push({ id: null, name: 'No task statuses configured', code: null, configured: false });

            const tasksForColumn = (column) => tasks.filter((task) => column.id !== null
                ? Number(task.statusId) === Number(column.id)
                : !task.statusId && normalizedStatusValue(task.status || 'Not Started') === normalizedStatusValue(column.name));

            area.innerHTML = `<div class="kanban" style="grid-template-columns:repeat(${Math.max(1, columns.length)},minmax(230px,1fr))">${columns.map((column) => {
                const columnTasks = tasksForColumn(column);
                const dropValue = column.configured ? String(column.id) : '';
                return `<div class="kanban-col"><div class="kanban-head"><span>${html(column.name)}${column.configured ? '' : ' · Legacy'}</span><span class="count">${columnTasks.length}</span></div><div class="kanban-list" ${dropValue ? `data-drop-status="${html(dropValue)}"` : 'data-readonly-status="true"'}>${columnTasks.map((task) => `<article class="kanban-card" draggable="${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? 'true' : 'false'}" data-task="${task.id}" data-task-updatable="${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? '1' : '0'}"><span class="badge ${task.priority === 'Critical' ? 'b-red' : task.priority === 'High' ? 'b-amber' : 'b-gray'}">${html(task.priority)}</span><div class="kanban-card-title">${html(task.title)}</div><div class="kanban-job">${html(task.job)} · ${html(task.phase)}</div><div class="kanban-meta"><span class="avatar" style="width:25px;height:25px;font-size:9px">${userInitials(task.assignee)}</span><span class="due ${dueClass(task.due)} small">${formatDate(task.due)}</span></div><div class="progress" style="margin-top:9px"><span style="width:${task.progress}%"></span></div></article>`).join('')}</div></div>`;
            }).join('')}</div>`;
        } else {
            const activeWorkflows = (workflowTemplates || []).filter((workflow) => workflow.active !== false);
            const selectedWorkflow = activeWorkflows.find((workflow) => Number(workflow.id) === Number(boardWorkflowId))
                || activeWorkflows[0]
                || workflowTemplates[0];
            if (selectedWorkflow) boardWorkflowId = selectedWorkflow.id;

            const phases = (selectedWorkflow?.phases || [])
                .filter((phase) => phase.active !== false && !(job.excludedPhaseIds || []).map(Number).includes(Number(phase.id)))
                .slice()
                .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
            const workflowJobs = selectedWorkflow
                ? jobs.filter((job) => Number(job.workflow) === Number(selectedWorkflow.id)
                    && (isFlowAdmin || isFlowSuperAdmin || boardJobIds.has(Number(job.dbId))))
                : [];

            const workflowOptions = activeWorkflows.map((workflow) =>
                `<option value="${workflow.id}" ${Number(workflow.id) === Number(selectedWorkflow?.id) ? 'selected' : ''}>${html(workflow.name)}</option>`
            ).join('');

            area.innerHTML = `<div class="card" style="padding:12px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"><b>Workflow</b><select id="boardWorkflowSelect" style="min-width:240px">${workflowOptions}</select><span class="small muted">The board uses the selected workflow's configured phases and IDs.</span></div>
            <div class="kanban" style="grid-template-columns:repeat(${Math.max(1, phases.length)},minmax(230px,1fr))">${phases.map((phase) => {
                const phaseJobs = workflowJobs.filter((job) => Number(job.phaseId) === Number(phase.id));
                return `<div class="kanban-col"><div class="kanban-head"><span>${html(phase.short || phase.shortName || phase.name)}</span><span class="count">${phaseJobs.length}</span></div><div class="kanban-list" data-drop-phase-id="${phase.id}">${phaseJobs.map((job) => `<article class="kanban-card" draggable="true" data-job="${job.id}">${healthBadge(job.health)}<div class="kanban-card-title">${html(job.title)}</div><div class="kanban-job">${html(job.id)} · ${html(clientName(job.client))}</div><div class="kanban-meta"><span class="avatar" style="width:25px;height:25px;font-size:9px">${userInitials(job.coordinator)}</span><span class="small">${job.progress}%</span></div><div class="progress" style="margin-top:9px"><span style="width:${job.progress}%"></span></div></article>`).join('')}</div></div>`;
            }).join('') || '<div class="card" style="padding:18px">No active phases are configured for this workflow.</div>'}</div>`;

            $('#boardWorkflowSelect')?.addEventListener('change', (event) => {
                boardWorkflowId = Number(event.target.value);
                renderKanban();
            });
        }

        let dragged = null;
        $$('.kanban-card').forEach((card) => {
            card.addEventListener('dragstart', (event) => {
                if (boardMode === 'tasks' && card.dataset.taskUpdatable === '0') {
                    event.preventDefault();
                    showToast('Only the assigned user, an Administrator, or the Super Admin can update this Task.');
                    return;
                }
                dragged = card;
                card.classList.add('dragging');
            });
            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
                dragged = null;
            });
        });
        $$('.kanban-list').forEach((list) => {
            list.addEventListener('dragover', (event) => {
                if (list.dataset.readonlyStatus === 'true') return;
                event.preventDefault();
            });
            list.addEventListener('drop', async () => {
                if (!dragged) return;
                try {
                    if (boardMode === 'tasks') {
                        if (!list.dataset.dropStatus) {
                            throw new Error('Move the task to an active configured status.');
                        }
                        const task = tasks.find((item) => item.id === dragged.dataset.task);
                        if (!task) throw new Error('The selected task is no longer available.');
                        const targetStatus = taskStatusRecord(list.dataset.dropStatus);
                        if (!targetStatus) throw new Error('The selected task status is not available in this workspace.');
                        if (taskStatusMatches(targetStatus, ['BLK', 'ATTN', 'BLOCKED', 'ATTENTION', 'HOLD', 'ON HOLD'])) {
                            showToast('Open the task and add a blocker reason before marking it blocked.');
                            openTask(task.id);
                            return;
                        }
                        await persistTask(task, targetStatus);
                    } else {
                        const job = jobs.find((item) => item.id === dragged.dataset.job);
                        if (!job) throw new Error('The selected Job is no longer available.');
                        const workflow = getWorkflow(Number(job.workflow));
                        const phaseId = Number(list.dataset.dropPhaseId);
                        const excludedPhaseIds = new Set((job.excludedPhaseIds || []).map((id) => Number(id)));
                        const phase = (workflow?.phases || []).find((item) => Number(item.id) === phaseId && item.active !== false && !excludedPhaseIds.has(Number(item.id)));
                        if (!phase) throw new Error('The selected workflow phase is not configured for this Job.');

                        const activePhases = (workflow.phases || [])
                            .filter((item) => item.active !== false && !excludedPhaseIds.has(Number(item.id)))
                            .slice()
                            .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
                        const currentIndex = activePhases.findIndex((item) => Number(item.id) === Number(job.phaseId));
                        const targetIndex = activePhases.findIndex((item) => Number(item.id) === Number(phase.id));
                        let reason = null;
                        if (currentIndex >= 0 && targetIndex > currentIndex + 1) {
                            reason = window.prompt('Enter the authorised reason for skipping workflow phases:')?.trim() || '';
                            if (!reason) {
                                showToast('A reason is required when skipping workflow phases.');
                                renderKanban();
                                return;
                            }
                        }
                        await moveJobToPhase(job, phase, reason);
                    }
                    renderKanban();
                    updateBadges();
                    showToast('Board updated. Workflow state and progress were recalculated.');
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
            navigate(filter === 'done' ? 'reports' : filter === 'blocked' || filter === 'overdue' ? 'mywork' : 'jobs');
            if (filter === 'risk') { jobFilter.health = 'At Risk'; renderJobs(); }
            if (filter === 'shipment') { jobFilter.phase = '8'; renderJobs(); }
            if (filter === 'payment') { jobFilter.phase = '9'; renderJobs(); }
        }));

        const next = $('[data-next-phase]');
        if (next) next.addEventListener('click', async () => {
            const job = jobs.find((item) => item.id === next.dataset.nextPhase);
            if (!job) return;
            const workflow = getWorkflow(Number(job.workflow));
            const excludedPhaseIds = new Set((job.excludedPhaseIds || []).map((phaseId) => Number(phaseId)));
            const phases = (workflow?.phases || [])
                .filter((phase) => phase.active !== false && !excludedPhaseIds.has(Number(phase.id)))
                .slice()
                .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
            const currentIndex = phases.findIndex((phase) => Number(phase.id) === Number(job.phaseId));
            const target = currentIndex >= 0 ? phases[currentIndex + 1] : null;
            if (!target) {
                showToast('No next active workflow phase is configured.');
                return;
            }
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

    function metricDateKey(value = new Date()) {
        const date = value instanceof Date ? value : new Date(value);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function taskIsCompleted(task) {
        return Boolean(task.completedAt) || String(task.status || '').toLowerCase() === 'completed';
    }

    function jobIsCompleted(job) {
        return String(job.status || '').toLowerCase() === 'completed' || String(job.health || '').toLowerCase() === 'completed';
    }

    function taskIsBlocked(task) {
        const status = String(task.status || '').toLowerCase();
        return !taskIsCompleted(task) && (Boolean(task.attention) || Boolean(task.blockerReason) || status === 'blocked');
    }

    function dynamicDashboardMetrics() {
        const date = summaryMetrics.asOf?.date || metricDateKey();
        const activeJobs = jobs.filter((job) => !jobIsCompleted(job));
        return {
            activeJobs: activeJobs.length,
            needsAttention: activeJobs.filter((job) => job.attention || ['needs attention', 'at risk', 'delayed', 'blocked'].includes(String(job.health || '').toLowerCase())).length,
            overdueTasks: tasks.filter((task) => !taskIsCompleted(task) && task.due && task.due < date).length,
            blockedTasks: tasks.filter(taskIsBlocked).length,
            shippingNow: activeJobs.filter((job) => /ship/i.test(phaseNameFor(job)) || /ship/i.test(phaseShortFor(job))).length,
            outstanding: canSensitive('invoice_amount') ? clients.reduce((sum, client) => sum + Number(client.outstanding || 0), 0) : null,
            canViewFinancials: canSensitive('invoice_amount'),
        };
    }

    function recentTaskActivities() {
        const apiActivities = (Array.isArray(dashboardActivities) ? dashboardActivities : []).map((item) => ({
            ...item,
            createdAt: item.createdAt || item.created_at || '',
        }));
        const derivedActivities = [...tasks]
            .filter((task) => task.createdAt)
            .map((task) => ({
                id: `task-created-${task.dbId || task.id}`,
                title: 'Task created',
                text: `${task.id} · ${task.title}${task.job ? ` · ${task.job}` : ''}`,
                time: new Date(task.createdAt).toLocaleString(),
                createdAt: task.createdAt,
                event: 'task.created',
                task: task.id,
                job: task.job,
                user: { name: task.assignee || 'System', initials: userInitials(task.assignee || 'System') },
            }));

        const seenTasks = new Set();
        return [...apiActivities, ...derivedActivities]
            .sort((a, b) => String(b.createdAt || '').localeCompare(String(a.createdAt || '')))
            .filter((item) => {
                const key = String(item.task || item.id || '');
                if (!key || seenTasks.has(key)) return false;
                seenTasks.add(key);
                return true;
            })
            .slice(0, 6);
    }

    renderDashboard = function () {
        const metrics = { ...(summaryMetrics.dashboard || {}), ...dynamicDashboardMetrics() };
        const activeJobs = jobs.filter((job) => !jobIsCompleted(job));
        const attentionJobs = activeJobs
            .filter((job) => job.attention || ['needs attention', 'at risk', 'delayed', 'blocked'].includes(String(job.health || '').toLowerCase()))
            .sort((a, b) => String(a.delivery || '9999-12-31').localeCompare(String(b.delivery || '9999-12-31')))
            .slice(0, 6);
        const phaseMap = new Map();
        activeJobs.forEach((job) => {
            const label = phaseShortFor(job) || phaseNameFor(job) || 'Unassigned';
            phaseMap.set(label, (phaseMap.get(label) || 0) + 1);
        });
        const phaseCounts = [...phaseMap.entries()].sort((a, b) => b[1] - a[1]);
        const maxPhase = Math.max(1, ...phaseCounts.map(([, count]) => count));
        const workloadMap = new Map();
        tasks.filter((task) => !taskIsCompleted(task)).forEach((task) => {
            const assignee = task.assignee || 'Unassigned';
            workloadMap.set(assignee, (workloadMap.get(assignee) || 0) + 1);
        });
        const workload = [...workloadMap.entries()].sort((a, b) => b[1] - a[1]).slice(0, 6);
        const maxWorkload = Math.max(1, ...workload.map(([, count]) => count));
        const displayDate = summaryMetrics.asOf?.displayDate || new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
        const outstandingValue = metrics.canViewFinancials ? currency(metrics.outstanding || 0) : 'Restricted';
        const recentActivities = recentTaskActivities();

        $('#page-dashboard').innerHTML = `<div class="page-head"><div><h1>Management Dashboard</h1><p>${html(displayDate)} · Exceptions, workload and delivery health</p></div><div class="actions"><button class="ghost" data-dashboard-action="export">Export summary</button><button class="primary" data-dashboard-action="create">＋ New Job</button></div></div>
        <div class="metrics">${metric('Active Jobs', metrics.activeJobs, 'Across visible workflow phases', 'jobs')}${metric('Needs Attention', metrics.needsAttention, 'Risk, delay or blocker', 'risk')}${metric('Overdue Tasks', metrics.overdueTasks, 'Require immediate update', 'overdue')}${metric('Blocked Tasks', metrics.blockedTasks, 'Require resolution', 'blocked')}${metric('Shipping Now', metrics.shippingNow, 'Ready or in transit', 'shipment')}${metric('Outstanding', outstandingValue, metrics.canViewFinancials ? 'Open client balance' : 'Financial access required', 'payment')}</div>
        <div class="grid-2"><div class="card section-card"><div class="section-head"><h3>Needs Attention</h3><button class="link-btn" data-go="jobs">View all Jobs</button></div><div class="attention-list">${attentionJobs.map((job) => `<div class="attention-item" data-job="${job.id}"><span class="signal ${String(job.health).toLowerCase() === 'blocked' ? 'purple' : String(job.health).toLowerCase() === 'needs attention' ? 'amber' : 'red'}"></span><div><div class="item-title">${html(job.next || job.title)}</div><div class="item-meta">${html(job.id)} · ${html(clientName(job.client))} · ${html(phaseShortFor(job))}</div></div>${healthBadge(job.health)}</div>`).join('') || '<div class="empty-state">No visible Jobs currently require attention.</div>'}</div></div>
        <div class="card section-card"><div class="section-head"><h3>Jobs by Phase</h3><button class="link-btn" data-go="board">Open board</button></div><div class="phase-list">${phaseCounts.map(([label, count]) => `<div class="phase-row"><span>${html(label)}</span><div class="progress"><span style="width:${Math.max(8, count / maxPhase * 100)}%"></span></div><b>${count}</b></div>`).join('') || '<div class="empty-state">No active Jobs are visible.</div>'}</div></div></div>
        <div class="grid-3"><div class="card section-card"><div class="section-head"><h3>Team Workload</h3><button class="link-btn" data-go="reports">Details</button></div>${workload.map(([name, count]) => `<div class="workload-row"><div class="person"><span class="avatar">${html(userInitials(name))}</span>${html(name)}</div><div class="progress"><span style="width:${Math.max(8, count / maxWorkload * 100)}%"></span></div><b>${count}</b></div>`).join('') || '<div class="empty-state">No open Tasks are visible.</div>'}</div>
        <div class="card section-card"><div class="section-head"><h3>Upcoming Deliveries</h3><button class="link-btn" data-go="jobs">View Jobs</button></div><table class="mini-table"><thead><tr><th>Job</th><th>Client</th><th>Delivery</th></tr></thead><tbody>${activeJobs.filter((job) => job.delivery).sort((a, b) => a.delivery.localeCompare(b.delivery)).slice(0, 6).map((job) => `<tr data-job="${job.id}" style="cursor:pointer"><td><b>${html(String(job.id).slice(-5))}</b><div class="muted small truncate" style="max-width:125px">${html(job.title)}</div></td><td>${html(clientName(job.client).split(' ')[0])}</td><td class="${job.delivery < (summaryMetrics.asOf?.date || metricDateKey()) ? 'due overdue' : ''}">${formatDate(job.delivery)}</td></tr>`).join('') || '<tr><td colspan="3"><div class="empty-state">No upcoming deliveries.</div></td></tr>'}</tbody></table></div>
        <div class="card section-card"><div class="section-head"><h3>Recent Activity</h3><button class="link-btn" data-go="mywork">View Tasks</button></div>${recentActivities.map((item) => `<div class="activity" ${item.task ? `data-task="${html(item.task)}"` : item.job ? `data-job="${html(item.job)}"` : ''} style="cursor:pointer"><span class="avatar">${html(item.user?.initials || userInitials(item.user?.name || 'System'))}</span><div><div class="activity-text"><b>${html(item.title || 'Task created')}</b><br>${html(item.text || '')}</div><div class="activity-time">${html(item.time || '')}</div></div></div>`).join('') || '<div class="empty-state">No recently created tasks.</div>'}</div></div>`;
        bindCommon();
        $$('[data-dashboard-action="create"]').forEach((button) => button.addEventListener('click', openJobModal));
        $('[data-dashboard-action="export"]')?.addEventListener('click', () => window.print());
        applyActionPermissions();
    };

    renderMyWork = function () {
        const date = summaryMetrics.asOf?.date || metricDateKey();
        const weekStart = summaryMetrics.asOf?.weekStart || metricDateKey(new Date(new Date().setDate(new Date().getDate() - new Date().getDay() + 1)));
        const weekEnd = summaryMetrics.asOf?.weekEnd || metricDateKey(new Date(new Date().setDate(new Date().getDate() - new Date().getDay() + 7)));
        const openTasks = tasks.filter((task) => !taskIsCompleted(task));
        const metrics = {
            dueToday: openTasks.filter((task) => task.due === date).length,
            overdue: openTasks.filter((task) => task.due && task.due < date).length,
            blockedAttention: openTasks.filter(taskIsBlocked).length,
            waitingReview: openTasks.filter((task) => /waiting|review/i.test(task.status || '')).length,
            completedThisWeek: tasks.filter((task) => taskIsCompleted(task) && task.completedAt && task.completedAt.slice(0, 10) >= weekStart && task.completedAt.slice(0, 10) <= weekEnd).length,
            upcoming: openTasks.filter((task) => task.due && task.due > date).length,
        };
        const statusOptions = (masterData.taskStatuses || []).filter((status) => String(status.status).toLowerCase() === 'active');
        const showAssignees = boardAccess.isAdministrator;

        $('#page-mywork').innerHTML = `<div class="page-head"><div><h1>My Work</h1><p>What needs your action now</p></div><div class="actions"><button class="ghost" id="myCalendar">Calendar</button><button class="primary" data-go="board">Open board</button></div></div>
        <div class="metrics">${metric('Due Today', metrics.dueToday, 'Tasks requiring action', 'today')}${metric('Overdue', metrics.overdue, 'Past their due date', 'overdue')}${metric('Blocked / Attention', metrics.blockedAttention, 'Need support', 'blocked')}${metric('Waiting Review', metrics.waitingReview, 'Client, supplier or internal', 'blocked')}${metric('Completed This Week', metrics.completedThisWeek, 'Across visible assignments', 'done')}${metric('Upcoming', metrics.upcoming, 'Scheduled next', 'upcoming')}</div>
        <div class="card toolbar"><input id="taskSearch" class="search-small" placeholder="Search assigned tasks…"><select id="taskStatus"><option value="">All statuses</option>${statusOptions.map((status) => `<option value="${html(status.name)}">${html(status.name)}</option>`).join('')}</select>${showAssignees ? `<select id="taskAssignee"><option value="">All assignees</option>${users.map((user) => `<option value="${html(user.name)}">${html(user.name)}</option>`).join('')}</select>` : ''}</div>
        <div id="myTaskGrid" class="task-grid">${renderTaskCards(openTasks)}</div>`;

        const rerender = () => {
            const query = ($('#taskSearch')?.value || '').toLowerCase();
            const status = $('#taskStatus')?.value || '';
            const assignee = $('#taskAssignee')?.value || '';
            const filtered = openTasks.filter((task) => (!query || `${task.title} ${task.job}`.toLowerCase().includes(query)) && (!status || task.status === status) && (!assignee || task.assignee === assignee));
            $('#myTaskGrid').innerHTML = renderTaskCards(filtered);
            bindCommon();
        };
        $('#taskSearch')?.addEventListener('input', rerender);
        $('#taskStatus')?.addEventListener('change', rerender);
        $('#taskAssignee')?.addEventListener('change', rerender);
        $('#myCalendar')?.addEventListener('click', () => window.print());
        bindCommon();
        applyActionPermissions();
    };

    renderReports = function () {
        const report = summaryMetrics.reports || {};
        const activeJobs = jobs.filter((job) => !jobIsCompleted(job));
        const phaseMap = new Map();
        activeJobs.forEach((job) => {
            const label = phaseShortFor(job) || phaseNameFor(job) || 'Unassigned';
            phaseMap.set(label, (phaseMap.get(label) || 0) + 1);
        });
        const phaseData = [...phaseMap.entries()].map(([label, value]) => ({ label, value }));
        const workloadMap = new Map();
        tasks.filter((task) => !taskIsCompleted(task)).forEach((task) => workloadMap.set(task.assignee || 'Unassigned', (workloadMap.get(task.assignee || 'Unassigned') || 0) + 1));
        const workload = [...workloadMap.entries()].map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value).slice(0, 10);
        const maxPhase = Math.max(1, ...phaseData.map((item) => item.value));
        const maxWorkload = Math.max(1, ...workload.map((item) => item.value));
        const ageing = Array.isArray(report.ageing) ? report.ageing : [];
        const maxAgeing = Math.max(1, ...ageing.map((item) => Number(item.value)));
        const receivables = report.financialVisible === false ? 'Restricted' : currency(report.openReceivables || 0);

        $('#page-reports').innerHTML = `<div class="page-head"><div><h1>Reports</h1><p>Operational performance, conversion, workload and cash collection</p></div><div class="actions"><button class="ghost" id="reportPdf">Print / PDF</button><button class="primary" id="reportExcel">Export CSV</button></div></div>
        <div class="metrics">${metric('Quotation Conversion', `${Number(report.quotationConversion || 0)}%`, 'Visible Jobs', '')}${metric('On-time Jobs', `${Number(report.onTimeJobs || 0)}%`, 'Completed deliveries', '')}${metric('Task Completion', `${Number(report.taskCompletion || 0)}%`, 'Within target date', '')}${metric('Avg. Artwork Cycle', `${Number(report.artworkCycle || 0)}d`, 'Request to completion', '')}${metric('Shipment On Time', `${Number(report.shipmentOnTime || 0)}%`, 'Completed shipment phases', '')}${metric('Open Receivables', receivables, report.financialVisible === false ? 'Financial access required' : 'Across visible clients', '')}</div>
        <div class="report-grid"><div class="card section-card"><div class="section-head"><h3>Active Jobs by Phase</h3><span class="small muted">Current portfolio</span></div><div class="bars">${phaseData.map((item) => barRow(item.label, item.value, maxPhase)).join('') || '<div class="empty-state">No active Jobs.</div>'}</div></div>
        <div class="card section-card"><div class="section-head"><h3>Team Workload</h3><span class="small muted">Open Tasks</span></div><div class="bars">${workload.map((item) => barRow(item.label, item.value, maxWorkload)).join('') || '<div class="empty-state">No open Tasks.</div>'}</div></div>
        <div class="card section-card"><div class="section-head"><h3>Delivery Performance</h3></div><div class="kpi-list"><div class="kpi"><b>${Number(report.deliveredThisMonth || 0)}</b><span>Delivered this month</span></div><div class="kpi"><b>${Number(report.deliveredOnTimeThisMonth || 0)}</b><span>Delivered on time</span></div><div class="kpi"><b>${Number(report.supplierDelays || 0)}</b><span>Supplier delays</span></div><div class="kpi"><b>${Number(report.clientDependencies || 0)}</b><span>Client dependencies</span></div></div></div>
        <div class="card section-card"><div class="section-head"><h3>Receivable Ageing</h3></div><div class="bars">${report.financialVisible === false ? '<div class="empty-state">Financial access is restricted.</div>' : ageing.map((item) => barRow(item.label, Number(item.value), maxAgeing, currency(item.value))).join('') || '<div class="empty-state">No open receivables.</div>'}</div></div></div>`;
        $('#reportPdf')?.addEventListener('click', () => window.print());
        $('#reportExcel')?.addEventListener('click', () => {
            const rows = [['Metric', 'Value'], ['Quotation Conversion', `${report.quotationConversion || 0}%`], ['On-time Jobs', `${report.onTimeJobs || 0}%`], ['Task Completion', `${report.taskCompletion || 0}%`], ['Average Artwork Cycle', `${report.artworkCycle || 0} days`], ['Shipment On Time', `${report.shipmentOnTime || 0}%`], ['Open Receivables', report.openReceivables ?? 'Restricted']];
            const csv = rows.map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(',')).join('\n');
            const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
            const link = document.createElement('a'); link.href = url; link.download = `flowtrack-report-${metricDateKey()}.csv`; link.click(); URL.revokeObjectURL(url);
        });
    };

    clientCards = function (items) {
        return items.map((client) => {
            const clientJobs = jobs.filter((job) => Number(job.client) === Number(client.id));
            const activeJobs = clientJobs.filter((job) => !jobIsCompleted(job)).length;
            const attention = clientJobs.filter((job) => !jobIsCompleted(job) && (job.attention || ['needs attention', 'at risk', 'delayed', 'blocked'].includes(String(job.health || '').toLowerCase()))).length;
            const outstanding = canSensitive('invoice_amount') ? currency(client.outstanding || 0).replace('$', '') : 'Restricted';
            return `<div class="card client-card" data-client="${client.id}"><div class="client-head"><div class="company-logo">${html(client.code)}</div><div><h3>${html(client.name)}</h3><div class="small muted">${html(client.country || '—')} · ${html(client.contact || 'No contact')}</div></div></div><div class="client-stats"><div class="client-stat"><b>${activeJobs}</b><span>Active Jobs</span></div><div class="client-stat"><b>${attention}</b><span>Attention</span></div><div class="client-stat"><b>${html(outstanding)}</b><span>Outstanding</span></div></div><div class="small muted" style="margin-top:12px">Account manager: ${html(client.manager || 'Unassigned')}</div></div>`;
        }).join('') || '<div class="card empty-state">No clients found.</div>';
    };

    updateBadges = function () {
        const unread = notifications.filter((notification) => !notification.read).length;
        if ($('#notificationBadge')) $('#notificationBadge').textContent = unread;
        const visibleOpenTasks = tasks.filter((task) => !taskIsCompleted(task));
        const ownOpenTasks = boardAccess.isAdministrator ? visibleOpenTasks : visibleOpenTasks.filter((task) => Number(task.assigneeId) === Number(currentUser?.id));
        if ($('#myWorkBadge')) $('#myWorkBadge').textContent = ownOpenTasks.length;
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
            document_category_id: null,
            due_offset_days: 1,
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
                <div class="field"><label>Required document</label><select data-pack-field="document_category_id" data-pack-index="${index}"><option value="">No task-specific file</option>${documentCategories.map((category) => `<option value="${category.id}" ${Number(item.document_category_id) === Number(category.id) ? 'selected' : ''}>${html(category.name)}</option>`).join('')}</select><div class="inline-help">The file must be attached to the Job before this task can be completed.</div></div>
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
                    if (['default_assignee_id', 'default_department_id', 'priority_id', 'document_category_id'].includes(field)) value = value ? Number(value) : null;
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
                                document_category_id: item.document_category_id || null,
                                due_offset_days: Number(item.due_offset_days || 0),
                                is_required: Boolean(item.is_required),
                            })),
                        },
                    });
                    await loadBootstrap();
                    closeAll();
                    renderTaskPackSetup();
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

    renderTaskPackSetup = function () {
        const page = $('#page-taskpacksetup');
        if (!page) return;
        const configuredTasks = taskPacks.reduce((sum, pack) => sum + (pack.items || []).length, 0);
        const mappedPhases = workflowTemplates.reduce((sum, workflow) => sum + (workflow.phases || []).filter((phase) => phase.taskPackId).length, 0);
        page.innerHTML = `<div class="page-head"><div><h1>Task Pack Setup</h1><p>Create reusable task sequences that activate when a Job enters a workflow phase</p></div><div class="actions"><button class="primary" id="addTaskPack">＋ Add Task Pack</button></div></div>
        <div class="config-summary"><div class="card config-stat"><span>Total Task Packs</span><b>${taskPacks.length}</b></div><div class="card config-stat"><span>Active Task Packs</span><b>${taskPacks.filter((pack) => pack.active).length}</b></div><div class="card config-stat"><span>Configured Tasks</span><b>${configuredTasks}</b></div><div class="card config-stat"><span>Mapped Phases</span><b>${mappedPhases}</b></div></div>
        <div id="taskPackSetupContent"><div class="pack-list">${taskPacks.map((pack) => `<div class="card pack"><div class="section-head"><div><h4>${html(pack.name)} Task Pack</h4><div class="small muted">${html(pack.code)} · ${(pack.items || []).length} predefined tasks · ${pack.active ? 'Active' : 'Inactive'}</div></div><button class="mini-btn" data-edit-pack-id="${pack.id}">Edit</button></div><div class="small muted">${html(pack.description || 'No description')}</div><ul class="pack-tasks">${(pack.items || []).map((item, index) => `<li><div><span>${index + 1}. ${html(item.title)}</span><div class="small muted" style="margin-top:3px">${html(item.default_assignee_name || item.default_department_name || 'Job coordinator')} · Due +${item.due_offset_days} day(s)${item.priority_name ? ` · ${html(item.priority_name)}` : ''}${item.document_category_name ? ` · Required file: ${html(item.document_category_name)}` : ''}</div></div><span class="badge ${item.is_required ? 'b-blue' : 'b-gray'}">${item.is_required ? 'Mandatory' : 'Optional'}</span></li>`).join('') || '<li><span class="muted">No tasks configured</span></li>'}</ul></div>`).join('') || '<div class="card empty-state">No Task Packs have been created.</div>'}</div></div>`;
        $('#addTaskPack')?.addEventListener('click', () => openTaskPackModal());
        $$('[data-edit-pack-id]').forEach((button) => button.addEventListener('click', () => {
            const pack = taskPacks.find((item) => Number(item.id) === Number(button.dataset.editPackId));
            if (pack) openTaskPackModal(pack);
        }));
        queueMicrotask(applyActionPermissions);
    };

    function productByName(name) {
        return (masterData.products || []).find((product) => product.name.toLowerCase() === String(name || '').trim().toLowerCase());
    }

    function jobPriorityOptions(name, selectedId) {
        const priorities = activeMaster('priorities');
        return `<div class="job-priority-options">${priorities.map((item) => `<label class="job-priority-option"><input type="radio" name="${name}" value="${item.id}" ${Number(item.id) === Number(selectedId) ? 'checked' : ''}><span class="job-priority-check">✓</span><span>${html(item.name)}</span></label>`).join('')}</div>`;
    }

    function canEditJobRecord() {
        return canFlow('jobs', 'edit_all') || canFlow('jobs', 'edit_own');
    }

    openJobModal = function (prefillClient) {
        const categories = activeMaster('productCategories');
        const defaultClient = clients.find((client) => Number(client.id) === Number(prefillClient)) || clients[0];
        const defaultPriority = (masterData.priorities || []).find((item) => item.code === 'MED') || activeMaster('priorities')[0];
        const defaultCurrency = (masterData.currencies || []).find((item) => item.code === 'USD') || activeMaster('currencies')[0];
        const salesUsers = users.filter((user) => user.dept === 'Sales');
        const ownerUsers = canFlow('jobs', 'assign') ? (salesUsers.length ? salesUsers : users) : users.filter((user) => Number(user.id) === Number(currentUser?.id));

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
        <div class="field full"><label>Job assignee</label><select id="newJobOwner" data-searchable data-search-placeholder="Search assignees…" ${canFlow('jobs', 'assign') ? '' : 'disabled'}>${ownerUsers.map((user) => `<option value="${user.id}" ${Number(user.id) === Number(currentUser?.id) ? 'selected' : ''}>${html(user.name)}</option>`).join('')}</select></div>
        <div class="field full"><label>Workflow template *</label><select id="newJobWorkflow" data-searchable data-search-placeholder="Search workflow templates…">${workflowTemplates.filter((workflow) => workflow.active).map((workflow) => `<option value="${workflow.id}">${html(workflow.name)}</option>`).join('')}</select><div class="inline-help">The selected workflow controls available starting stages and Task Packs.</div></div>
        <div class="field full"><label>Start Job from stage *</label><select id="newJobStartPhase" data-searchable data-search-placeholder="Search starting stages…"></select><div class="inline-help">Use this when earlier stages happened outside the system or do not apply.</div></div>
        <div id="skipHandlingWrap" class="field full hidden"><label>How should earlier phases be recorded? *</label><select id="newJobSkipHandling" data-searchable data-search-placeholder="Search phase handling options…"><option>Completed outside FlowTrack</option><option>Not applicable for this Job</option><option>Migrated from an existing process</option><option>Manager-authorized skip</option></select></div>
        <div id="skipReasonWrap" class="field full hidden"><label>Reason / reference *</label><textarea id="newJobStartReason" rows="2" placeholder="Explain why the Job begins at this stage, and add any external reference."></textarea></div>
        </div><div id="jobStagePreview" class="stage-preview"></div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="secondary" id="saveDraftJob">Save Draft</button><button class="primary" id="createJob">Create</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));

        let productRows = [{ product_category_id: '', product_id: '', quantity: 1000 }];
        const productsForCategory = (categoryId) => activeMaster('products').filter((product) => Number(product.parent_id) === Number(categoryId));
        const categoryOptions = (selectedId) => `<option value="">Select category</option>${categories.map((item) => `<option value="${item.id}" ${Number(item.id) === Number(selectedId) ? 'selected' : ''}>${html(item.name)}</option>`).join('')}`;

        const renderProductRows = () => {
            $('#newJobProducts').innerHTML = productRows.map((row, index) => {
                const products = productsForCategory(row.product_category_id);
                return `<div class="job-product-row" data-product-row="${index}">
                    <select class="new-job-category" data-searchable data-search-placeholder="Search categories…" aria-label="Product category ${index + 1}">${categoryOptions(row.product_category_id)}</select>
                    <select class="new-job-product" data-searchable data-search-placeholder="Search products…" aria-label="Product ${index + 1}" ${row.product_category_id ? '' : 'disabled'}>
                        <option value="">${row.product_category_id ? (products.length ? 'Select product' : 'No products in this category') : 'Select category first'}</option>
                        ${products.map((product) => `<option value="${product.id}" ${Number(row.product_id) === Number(product.id) ? 'selected' : ''}>${html(product.code)} · ${html(product.name)}</option>`).join('')}
                    </select>
                    <input class="new-job-quantity" type="number" min="1" max="1000000000" value="${Math.max(1, Number(row.quantity) || 1)}" aria-label="Quantity for product ${index + 1}">
                    <button type="button" class="job-remove-product" data-remove-product="${index}" title="Remove product" aria-label="Remove product ${index + 1}" ${productRows.length === 1 ? 'disabled' : ''}>×</button>
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
            window.FlowtrackSearchableDropdown?.refresh(select);
            refreshStagePreview();
        };

        $('#newJobClient').addEventListener('change', (event) => {
            const client = clients.find((item) => Number(item.id) === Number(event.target.value));
            $('#newJobContact').value = client?.contact || '';
        });
        $('#newJobWorkflow').addEventListener('change', refreshStages);
        $('#newJobStartPhase').addEventListener('change', refreshStagePreview);
        renderProductRows();
        refreshStages();
        window.FlowtrackSearchableDropdown?.enhance($('#jobModal'));

        const submit = async (draft) => {
            const title = $('#newJobTitle').value.trim();
            const workflow = getWorkflow(Number($('#newJobWorkflow').value));
            const phaseId = Number($('#newJobStartPhase').value);
            const phaseIndex = workflow.phases.findIndex((phase) => Number(phase.id) === phaseId);
            const reason = $('#newJobStartReason')?.value.trim() || '';
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
            if (phaseIndex > 0 && !reason) return showToast('Please explain why earlier workflow phases are being skipped.');

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
                        products: normalizedProducts,
                        currency_id: defaultCurrency?.id || null,
                        priority_id: Number(document.querySelector('input[name="newJobPriority"]:checked')?.value || 0) || null,
                        owner_id: Number($('#newJobOwner').value) || currentUser?.id,
                        coordinator_id: currentUser?.id || null,
                        title,
                        request_description: $('#newJobDesc').value.trim() || null,
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
                showToast(draft ? 'Draft Job saved.' : `${result.job.id} created with ${result.job.productCount || normalizedProducts.length} product line${(result.job.productCount || normalizedProducts.length) === 1 ? '' : 's'}.`);
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
        const assignableUsers = canFlow('jobs', 'assign') ? users : users.filter((user) => Number(user.id) === Number(job.ownerId) || Number(user.id) === Number(currentUser?.id));
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
                replaceTasksForJob(result.job.id, result.tasks || []);
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
        <div class="modal-body"><div class="form-grid access-form-stack"><div class="field"><label>Full name *</label><input id="accessUserName" value="${html(existing?.name || '')}"></div><div class="field"><label>Email *</label><input id="accessUserEmail" type="email" value="${html(existing?.email || '')}"></div><div class="field"><label>Role *</label><select id="accessUserRole">${roles.map((role) => `<option value="${role.id}" ${Number(role.id) === Number(existing?.roleId) ? 'selected' : ''}>${html(role.name)}</option>`).join('')}</select></div><div class="field"><label>Department</label><select id="accessUserDepartment"><option value="">Unassigned</option>${departments.map((department) => `<option value="${department.id}" ${Number(department.id) === Number(existing?.departmentId) ? 'selected' : ''}>${html(department.name)}</option>`).join('')}</select></div><div class="field full"><label>Job title</label><input id="accessUserJobTitle" value="${html(existing?.jobTitle || '')}"></div><div class="field"><label>Status</label><select id="accessUserStatus"><option value="active" ${!existing || existing.status === 'active' ? 'selected' : ''}>Active</option><option value="pending" ${existing?.status === 'pending' ? 'selected' : ''}>Pending</option><option value="inactive" ${existing?.status === 'inactive' ? 'selected' : ''}>Inactive</option></select></div><div class="field"><label>Language</label><select id="accessUserLocale"><option value="en">English</option><option value="zh">中文</option></select></div><div class="field"><label>${existing ? 'New password' : 'Password *'}</label><input id="accessUserPassword" type="password" autocomplete="new-password"></div><div class="field"><label>${existing ? 'Confirm new password' : 'Confirm password *'}</label><input id="accessUserPasswordConfirmation" type="password" autocomplete="new-password"></div></div><div class="access-form-note">Passwords require at least 8 characters with letters and numbers. Admin roles always receive unrestricted system access.</div></div>
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

    const setupContentBase = renderSetupContent;
    renderSetupContent = function (type) {
        if (['roles', 'permissions', 'users'].includes(type) && !canViewAccess) {
            $('#setupContent').innerHTML = '<div class="card access-empty">You do not have permission to view roles and access.</div>';
            return;
        }
        if (type === 'roles') return renderAccessRoles();
        if (type === 'permissions') return renderPermissionMatrix();
        if (type === 'users') return renderRoleAssignments();
        if (type === 'notification') {
            const rules = notificationRules || [];
            $('#setupContent').innerHTML = `<div class="card section-card"><div class="section-head"><div><h3>Notification Rules</h3><div class="small muted">Configured reminder and escalation rules.</div></div></div><table class="mini-table"><thead><tr><th>Name</th><th>Trigger</th><th>Recipients</th><th>Status</th></tr></thead><tbody>${rules.map((rule) => `<tr><td><b>${html(rule.name)}</b></td><td>${html(rule.trigger)}</td><td>${html(rule.recipients)}</td><td><span class="badge ${rule.active === false ? 'b-gray' : 'b-green'}">${rule.active === false ? 'Inactive' : 'Active'}</span></td></tr>`).join('') || '<tr><td colspan="4"><div class="empty-state">No notification rules are configured.</div></td></tr>'}</tbody></table></div>`;
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
                    sessionRedirect(error.payload?.reason || 'expired');
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
