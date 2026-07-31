(() => {
    function flowtrackToArray(value) {
        if (Array.isArray(value)) return value;
        if (value == null) return [];

        // Laravel collections/paginators may occasionally be encoded as keyed
        // objects after cache restoration. Normalize them without assuming that
        // Symbol.iterator exists or is callable.
        if (typeof value === 'object' && Array.isArray(value.data)) return value.data;

        const length = typeof value === 'object' && Number.isFinite(Number(value.length))
            ? Math.max(0, Number(value.length))
            : null;
        if (length !== null) {
            const items = [];
            for (let index = 0; index < length; index += 1) {
                if (index in value) items.push(value[index]);
            }
            return items;
        }

        try {
            const iterator = typeof Symbol !== 'undefined' ? value?.[Symbol.iterator] : null;
            if (typeof iterator === 'function') {
                const items = [];
                for (const item of value) items.push(item);
                return items;
            }
        } catch (_) {
            // Fall through to keyed-object normalization.
        }

        if (typeof value === 'object') return Object.values(value);
        return [];
    }

    function flowtrackNormalizeWorkflow(workflow) {
        const source = workflow && typeof workflow === 'object' ? workflow : {};
        return {
            ...source,
            phases: flowtrackToArray(source.phases).filter((phase) => phase && typeof phase === 'object'),
        };
    }

    function flowtrackNormalizeWorkflows(value) {
        return flowtrackToArray(value)
            .filter((workflow) => workflow && typeof workflow === 'object')
            .map(flowtrackNormalizeWorkflow);
    }


    const flowtrackNativeInnerHtml = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
    const flowtrackUnsafeTags = 'script,iframe,object,embed,base,meta[http-equiv],link[rel="import"]';
    const flowtrackUrlAttributes = new Set(['href', 'src', 'xlink:href', 'action', 'formaction', 'poster']);

    function flowtrackSafeUrl(value) {
        const candidate = String(value ?? '').trim();
        if (!candidate || candidate.startsWith('#') || candidate.startsWith('/') || candidate.startsWith('./') || candidate.startsWith('../') || candidate.startsWith('?')) return true;
        if (/^data:image\/(?:png|gif|jpe?g|webp|bmp);/i.test(candidate)) return true;
        try {
            const parsed = new URL(candidate, window.location.origin);
            return ['http:', 'https:', 'mailto:', 'tel:', 'blob:'].includes(parsed.protocol);
        } catch (_) {
            return false;
        }
    }

    function sanitizeFlowtrackHtml(value) {
        if (!flowtrackNativeInnerHtml?.set || !flowtrackNativeInnerHtml?.get) return String(value ?? '');
        const template = document.createElement('template');
        flowtrackNativeInnerHtml.set.call(template, String(value ?? ''));
        template.content.querySelectorAll(flowtrackUnsafeTags).forEach((element) => element.remove());
        template.content.querySelectorAll('*').forEach((element) => {
            flowtrackToArray(element.attributes).forEach((attribute) => {
                const name = attribute.name.toLowerCase();
                const attributeValue = attribute.value;
                if (name.startsWith('on') || name === 'srcdoc') {
                    element.removeAttribute(attribute.name);
                    return;
                }
                if (name === 'style' && /(?:expression\s*\(|javascript\s*:|vbscript\s*:|url\s*\()/i.test(attributeValue)) {
                    element.removeAttribute(attribute.name);
                    return;
                }
                if (flowtrackUrlAttributes.has(name) && !flowtrackSafeUrl(attributeValue)) {
                    element.removeAttribute(attribute.name);
                }
            });
            if (element instanceof HTMLAnchorElement && element.target === '_blank') {
                element.rel = 'noopener noreferrer';
            }
        });
        return flowtrackNativeInnerHtml.get.call(template);
    }

    if (flowtrackNativeInnerHtml?.configurable && !window.__FLOWTRACK_SAFE_HTML_INSTALLED__) {
        Object.defineProperty(Element.prototype, 'innerHTML', {
            configurable: true,
            enumerable: flowtrackNativeInnerHtml.enumerable,
            get() {
                return flowtrackNativeInnerHtml.get.call(this);
            },
            set(value) {
                flowtrackNativeInnerHtml.set.call(this, sanitizeFlowtrackHtml(value));
            },
        });
        window.__FLOWTRACK_SAFE_HTML_INSTALLED__ = true;
    }
    window.FlowtrackSafeHtml = Object.freeze({
        sanitize: sanitizeFlowtrackHtml,
        isSafeUrl: flowtrackSafeUrl,
    });

    function flowtrackInstallClickableDateFields() {
        if (window.__FLOWTRACK_CLICKABLE_DATE_FIELDS__) return;
        window.__FLOWTRACK_CLICKABLE_DATE_FIELDS__ = true;

        const style = document.createElement('style');
        style.textContent = `
            input[type="date"]{cursor:pointer}
            input[type="date"]::-webkit-calendar-picker-indicator{cursor:pointer}
            .field:has(input[type="date"]){cursor:pointer}
        `;
        document.head.appendChild(style);

        const dateInputFromTarget = (target) => {
            const element = target instanceof Element ? target : null;
            if (!element) return null;

            const direct = element.closest('input[type="date"]');
            if (direct instanceof HTMLInputElement) return direct;

            const label = element.closest('label');
            if (label?.htmlFor) {
                const labelled = document.getElementById(label.htmlFor);
                if (labelled instanceof HTMLInputElement && labelled.type === 'date') return labelled;
            }

            const field = element.closest('.field, .form-group, .input-group, .date-field');
            const input = field?.querySelector?.('input[type="date"]');
            return input instanceof HTMLInputElement ? input : null;
        };

        const openDatePicker = (input) => {
            if (!(input instanceof HTMLInputElement) || input.type !== 'date' || input.disabled || input.readOnly) return;

            try {
                input.focus({ preventScroll: true });
            } catch (_) {
                input.focus();
            }

            if (typeof input.showPicker === 'function') {
                try {
                    input.showPicker();
                } catch (_) {
                    // Browsers can reject showPicker() when there is no active
                    // user gesture. The focused native input still remains usable.
                }
            }
        };

        document.addEventListener('click', (event) => {
            const input = dateInputFromTarget(event.target);
            if (!input) return;

            const element = event.target instanceof Element ? event.target : null;
            if (element && element !== input && element.closest('button,a,select,textarea,[role="button"],[data-close]')) return;

            openDatePicker(input);
        }, true);

        document.addEventListener('keydown', (event) => {
            if (!['Enter', ' '].includes(event.key)) return;
            const input = dateInputFromTarget(event.target);
            if (!input) return;
            event.preventDefault();
            openDatePicker(input);
        }, true);
    }

    flowtrackInstallClickableDateFields();

    function flowtrackIsWorkflowPhaseDocumentTask(task) {
        if (!task || typeof task !== 'object') return false;
        const requirement = task.documentRequirement || task.document_requirement || {};
        const source = String(requirement.source || task.documentRequirementSource || task.document_requirement_source || '').toLowerCase();
        if (source === 'workflow_phase') return true;

        const hasSetupItem = Boolean(task.taskPackItemId || task.task_pack_item_id || task.taskPackItemTitle);
        const hasDocumentRequirement = Boolean(requirement.categoryId || requirement.category_id || task.documentCategoryId || task.document_category_id);
        return !hasSetupItem && hasDocumentRequirement && /^upload\s+/i.test(String(task.title || ''));
    }

    function flowtrackVisibleTaskRows(sourceTasks) {
        return flowtrackToArray(sourceTasks).filter((task) => !flowtrackIsWorkflowPhaseDocumentTask(task));
    }

    function flowtrackNavigateTo(url) {
        const target = String(url ?? '').trim();
        if (!flowtrackSafeUrl(target)) {
            showToast('The requested link was blocked because it is not a safe URL.');
            return false;
        }
        window.location.assign(target);
        return true;
    }

    const flowtrackExtensionRegistry = new Map();
    const flowtrackBaseImplementations = Object.freeze({ openJob, openTask, renderJobs, renderKanban });
    const flowtrackActiveImplementations = { ...flowtrackBaseImplementations };

    function currentFlowtrackImplementation(name) {
        const implementation = flowtrackActiveImplementations[name];
        if (typeof implementation !== 'function') throw new Error(`Missing FlowTrack implementation: ${name}`);
        return implementation;
    }

    function registerFlowtrackExtension(name, stage, implementation) {
        if (typeof implementation !== 'function') throw new TypeError(`FlowTrack extension ${name}:${stage} must be a function.`);
        const stages = flowtrackExtensionRegistry.get(name) || [];
        if (stages.some((entry) => entry.stage === stage)) throw new Error(`Duplicate FlowTrack extension stage: ${name}:${stage}`);
        stages.push({ stage, implementation });
        flowtrackExtensionRegistry.set(name, stages);
        flowtrackActiveImplementations[name] = implementation;
        return implementation;
    }

    const flowtrackDispatchers = Object.freeze({
        openJob: (...args) => currentFlowtrackImplementation('openJob')(...args),
        openTask: (...args) => currentFlowtrackImplementation('openTask')(...args),
        renderJobs: (...args) => currentFlowtrackImplementation('renderJobs')(...args),
        renderKanban: (...args) => currentFlowtrackImplementation('renderKanban')(...args),
    });

    // Install one stable public function per feature. Extension registration only
    // changes the private implementation target, so core globals are never
    // repeatedly overwritten while preserving the exact staged behavior.
    openJob = flowtrackDispatchers.openJob;
    openTask = flowtrackDispatchers.openTask;
    renderJobs = flowtrackDispatchers.renderJobs;
    renderKanban = flowtrackDispatchers.renderKanban;

    window.__FLOWTRACK_EXTENSION_REGISTRY__ = flowtrackExtensionRegistry;
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
    let boardAssigneeUsers = [];
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
    let sessionActivityPending = false;
    let sessionActivityBound = false;
    let sessionVisibilityBound = false;
    let sessionLastTouchSyncAt = 0;
    let sessionCheckNow = null;
    let notificationSignature;
    let notificationUnread = 0;
    let bootstrapGeneration = 0;
    let bootstrapAppliedGeneration = 0;
    const segmentPagination = { clients: null, jobs: null, tasks: null, documents: null };
    const segmentLoading = new Set();

    function invalidateBootstrapLoads() {
        bootstrapGeneration += 1;
    }

    function hasOwnBootstrapField(payload, field) {
        return Boolean(payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, field));
    }

    const taskFeedbackStyles = document.createElement('style');
    taskFeedbackStyles.textContent = `
        .task-head-actions{display:flex;align-items:flex-start;gap:8px}.task-menu-wrap{position:relative}.task-menu-button{width:42px;height:42px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;font-size:22px;line-height:1;cursor:pointer}.task-action-menu{position:absolute;right:0;top:48px;z-index:30;min-width:150px;padding:6px;background:#fff;border:1px solid #dbe3ef;border-radius:12px;box-shadow:0 16px 36px rgba(15,23,42,.18)}.task-action-menu.hidden{display:none}.task-action-menu button{display:block;width:100%;border:0;background:transparent;text-align:left;padding:10px 12px;border-radius:8px;font-weight:700;cursor:pointer}.task-action-menu button:hover{background:#f1f5f9}.task-action-menu .danger-option{color:#b91c1c}.task-job-link{border:0;background:transparent;padding:0;color:#2563eb;font:inherit;font-weight:800;cursor:pointer;text-decoration:underline;text-underline-offset:2px}.task-progress-track{height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden;margin-top:8px}.task-progress-fill{display:block;height:100%;min-width:0;border-radius:999px;background:#2f6fed;transition:width .2s ease,background .2s ease}.task-progress-fill.complete{background:#16a34a}.task-history{display:grid;gap:10px}.task-history-entry{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff}.task-history-head{display:flex;align-items:flex-start;gap:10px}.task-history-copy{flex:1}.task-change-list{margin:8px 0 0 34px;padding-left:16px;color:#475569}.mention-wrap{position:relative}.mention-menu{position:absolute;left:0;right:0;top:100%;z-index:25;max-height:190px;overflow:auto;background:#fff;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 12px 28px rgba(15,23,42,.16);padding:5px}.mention-menu.hidden{display:none}.mention-option{width:100%;display:flex;align-items:center;gap:9px;border:0;background:#fff;padding:9px;border-radius:8px;text-align:left;cursor:pointer}.mention-option:hover{background:#eff6ff}.job-attachment-drop{border:2px dashed #bfdbfe;border-radius:14px;background:#f8fbff;padding:20px;text-align:center;cursor:pointer;transition:.15s}.job-attachment-drop.dragging{border-color:#2563eb;background:#eff6ff}.job-attachment-list{display:grid;gap:8px;margin-top:10px}.job-attachment-row{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #dbe3ef;border-radius:10px;padding:9px 11px;background:#fff}.job-attachment-row button{border:0;background:#fee2e2;color:#b91c1c;border-radius:8px;padding:5px 9px;cursor:pointer}.task-document-section{margin-top:18px;border:1px solid #dbe3ef;border-radius:14px;padding:14px;background:#f8fafc}.task-document-list{display:grid;gap:10px;margin-top:10px}.task-document-file{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid #dbe3ef;border-radius:12px;padding:11px;background:#fff}.task-document-file-copy{min-width:0;flex:1}.task-document-file-name{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.task-document-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.task-document-actions a{display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.task-document-thumb{width:64px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #dbe3ef;background:#f1f5f9}.task-document-upload{display:grid;gap:10px;margin-top:12px;padding-top:12px;border-top:1px solid #dbe3ef}.kanban-job-link{border:0;background:transparent;padding:0;color:inherit;font:inherit;cursor:pointer;text-decoration:underline;text-underline-offset:2px}.progress span.complete{background:#16a34a!important}
    `;
    document.head.appendChild(taskFeedbackStyles);

    const taskDocumentSelectionStyles = document.createElement('style');
    taskDocumentSelectionStyles.textContent = `
        .task-document-selected-preview{display:flex;align-items:center;gap:12px;margin-top:10px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:10px 11px}
        .task-document-selected-preview img{width:64px;height:48px;object-fit:cover;border:1px solid #dbe3ef;border-radius:8px;background:#fff}
        .task-upload-progress{height:6px;margin-top:7px;border-radius:999px;background:#dbeafe;overflow:hidden}
        .task-upload-progress span{display:block;height:100%;border-radius:999px;background:#2563eb;transition:width .2s ease}
    `;
    document.head.appendChild(taskDocumentSelectionStyles);


    function bindSessionActivityTracking() {
        if (sessionActivityBound) return;
        sessionActivityBound = true;
        const markActive = () => {
            sessionActivityPending = true;
            const now = Date.now();
            const expiresAt = window.FLOWTRACK_SESSION?.expiresAt ? new Date(window.FLOWTRACK_SESSION.expiresAt).getTime() : 0;
            if (typeof sessionCheckNow === 'function' && (now - sessionLastTouchSyncAt > 15000 || (expiresAt && expiresAt - now < 60000))) {
                sessionLastTouchSyncAt = now;
                sessionCheckNow();
            }
        };
        ['click', 'keydown', 'input', 'change', 'pointerdown', 'touchstart', 'scroll'].forEach((eventName) => {
            window.addEventListener(eventName, markActive, { passive: true, capture: true });
        });
    }

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
        bindSessionActivityTracking();
        const checkStatus = async () => {
            const activityHeader = sessionActivityPending ? { 'X-FlowTrack-Activity': '1' } : {};
            const sentActivity = sessionActivityPending;
            try {
                const response = await fetch(config.statusEndpoint, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...activityHeader },
                    cache: 'no-store',
                });
                if (response.status === 401) {
                    const payload = await response.json().catch(() => ({}));
                    sessionRedirect(payload.reason || 'expired');
                    return;
                }
                if (!response.ok) return;
                const payload = await response.json();
                if (sentActivity) sessionActivityPending = false;
                const refreshedExpiry = payload.expiresAt || payload.expires_at;
                if (refreshedExpiry && refreshedExpiry !== config.expiresAt) {
                    scheduleSessionGuard(refreshedExpiry);
                }
            } catch (_) {
                // A temporary network outage must not log the user out locally.
            }
        };
        sessionCheckNow = checkStatus;
        checkStatus();
        sessionStatusTimer = setInterval(checkStatus, 30000);
        if (!sessionVisibilityBound) {
            sessionVisibilityBound = true;
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && typeof sessionCheckNow === 'function') sessionCheckNow();
            });
        }
    }

    async function refreshNotificationFeed() {
        const payload = await api('/flowtrack/notifications/feed?limit=50', { cache: 'no-store' });
        replaceArray(notifications, payload?.notifications || []);
        notificationUnread = Number(payload?.unread_count || notifications.filter((item) => !item.read).length);
        notificationSignature = payload?.latest_signature ?? notificationSignature;
        notificationAccess = { ...notificationAccess, authorityView: Boolean(payload?.authority_view) };
        updateBadges();
        if (currentPage === 'notifications') renderNotifications();
        return payload;
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

                await refreshNotificationFeed();
                return;
            }

            if (nextSignature === notificationSignature && nextUnread === notificationUnread) return;

            notificationSignature = nextSignature;
            notificationUnread = nextUnread;
            await refreshNotificationFeed();
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
                await refreshNotificationFeed();
            } catch (error) {
                console.warn('FlowTrack realtime notification refresh failed.', error);
            }
        }, 650);
    }

    function receiveRealtimeNotification(payload) {
        invalidateBootstrapLoads();
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
    const originalOpenJob = flowtrackBaseImplementations.openJob;
    const originalOpenDoc = openDoc;

    function replaceArray(target, values) {
        target.splice(0, target.length);
        flowtrackToArray(values).forEach((value) => target.push(value));
    }


    function flowtrackRecordMatches(left, right) {
        if (!left || !right) return false;
        const leftDbId = Number(left.dbId || left.id || 0);
        const rightDbId = Number(right.dbId || right.id || 0);
        if (leftDbId > 0 && rightDbId > 0 && leftDbId === rightDbId) return true;
        return String(left.id || '').trim() !== '' && String(left.id) === String(right.id || '');
    }

    function flowtrackUpsertRecord(target, record, prepend = true) {
        if (!record || typeof record !== 'object') return null;
        const index = target.findIndex((item) => flowtrackRecordMatches(item, record));
        if (index >= 0) {
            target.splice(index, 1, { ...target[index], ...record });
            return target[index];
        }
        if (prepend) target.unshift(record);
        else target.push(record);
        return record;
    }

    function flowtrackRemoveRecord(target, record) {
        const index = target.findIndex((item) => flowtrackRecordMatches(item, record));
        if (index >= 0) target.splice(index, 1);
    }

    function html(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }


    function flowtrackUserById(id) {
        const numericId = Number(id || 0);
        if (!numericId) return null;
        return flowtrackToArray(users).find((user) => Number(user?.id) === numericId) || null;
    }

    function flowtrackUserByName(name) {
        const target = String(name || '').trim().toLowerCase();
        if (!target || target === 'unassigned') return null;
        return flowtrackToArray(users).find((user) => String(user?.name || '').trim().toLowerCase() === target) || null;
    }

    function flowtrackResolveTaskAssigneeId(task) {
        if (!task || typeof task !== 'object') return null;
        if (Number(task.assigneeId || 0)) return Number(task.assigneeId);
        if (!task.assigneeOverridden && Number(task.setupAssigneeId || 0)) return Number(task.setupAssigneeId);
        const matched = flowtrackUserByName(task.assignee || task.setupAssignee || '');
        return matched ? Number(matched.id) : null;
    }

    function flowtrackResolveTaskAssigneeName(task, resolvedId = null) {
        const user = flowtrackUserById(resolvedId || flowtrackResolveTaskAssigneeId(task));
        if (user?.name) return user.name;
        if (task?.assignee && String(task.assignee).trim() && String(task.assignee).trim().toLowerCase() !== 'unassigned') return task.assignee;
        if (task?.setupAssignee && String(task.setupAssignee).trim()) return task.setupAssignee;
        return 'Unassigned';
    }

    function flowtrackHydrateTaskAssignee(task) {
        if (!task || typeof task !== 'object') return task;
        const resolvedId = flowtrackResolveTaskAssigneeId(task);
        if (resolvedId && !Number(task.assigneeId || 0)) task.assigneeId = resolvedId;
        const resolvedName = flowtrackResolveTaskAssigneeName(task, resolvedId);
        if (resolvedName && resolvedName !== 'Unassigned') task.assignee = resolvedName;
        return task;
    }

    function flowtrackTaskAssigneeOptions(task, extraUsers = []) {
        const resolvedId = flowtrackResolveTaskAssigneeId(task);
        const resolvedName = flowtrackResolveTaskAssigneeName(task, resolvedId);
        const source = flowtrackToArray(users).concat(flowtrackToArray(extraUsers));

        if (resolvedId && !source.some((user) => Number(user?.id) === Number(resolvedId))) {
            source.push({ id: resolvedId, name: resolvedName, initials: typeof userInitials === 'function' ? userInitials(resolvedName) : '' });
        }

        if (!resolvedId && resolvedName && resolvedName !== 'Unassigned' && !source.some((user) => String(user?.name || '').trim().toLowerCase() === resolvedName.toLowerCase())) {
            source.push({ id: '', name: resolvedName, initials: typeof userInitials === 'function' ? userInitials(resolvedName) : '' });
        }

        return source
            .filter((user) => user && (Number(user.id || 0) || String(user.name || '').trim()))
            .filter((user, index, collection) => {
                if (Number(user.id || 0)) return collection.findIndex((candidate) => Number(candidate?.id) === Number(user.id)) === index;
                const name = String(user.name || '').trim().toLowerCase();
                return collection.findIndex((candidate) => String(candidate?.name || '').trim().toLowerCase() === name) === index;
            })
            .sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));
    }

    function bindFlowtrackMentionInput(input, menu, mentionableUsers, selectedIds) {
        if (!input || !menu) return () => {};
        input.__flowtrackMentionAbortController?.abort();
        const controller = new AbortController();
        input.__flowtrackMentionAbortController = controller;
        const { signal } = controller;

        const close = () => menu.classList.add('hidden');
        const context = () => {
            const cursor = input.selectionStart ?? 0;
            const before = input.value.slice(0, cursor);
            const match = before.match(/(^|\s)@([^@\n]*)$/);
            return match
                ? { cursor, query: match[2].trim().toLowerCase(), start: cursor - match[0].length + match[1].length }
                : null;
        };
        const render = () => {
            const activeContext = context();
            if (!activeContext) return close();
            const matches = mentionableUsers
                .filter((user) => String(user.name || '').toLowerCase().includes(activeContext.query))
                .slice(0, 8);
            if (!matches.length) return close();
            menu.innerHTML = matches.map((user) => `<button type="button" class="mention-option" data-mention-user="${html(user.id)}"><span class="avatar">${html(user.initials || userInitials(user.name))}</span><span><b>${html(user.name)}</b></span></button>`).join('');
            menu.classList.remove('hidden');
        };

        input.addEventListener('input', render, { signal });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') close();
        }, { signal });
        menu.addEventListener('mousedown', (event) => {
            const button = event.target.closest('[data-mention-user]');
            if (!button) return;
            event.preventDefault();
            const user = mentionableUsers.find((item) => Number(item.id) === Number(button.dataset.mentionUser));
            const activeContext = context();
            if (!user || !activeContext) return;
            const insertion = `@${user.name} `;
            input.value = `${input.value.slice(0, activeContext.start)}${insertion}${input.value.slice(activeContext.cursor)}`;
            const nextCursor = activeContext.start + insertion.length;
            input.setSelectionRange(nextCursor, nextCursor);
            input.focus();
            selectedIds.add(Number(user.id));
            close();
        }, { signal });
        document.addEventListener('pointerdown', (event) => {
            if (!menu.contains(event.target) && event.target !== input) close();
        }, { signal, capture: true });

        return () => controller.abort();
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

        const method = String(options.method || 'GET').toUpperCase();
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
            // A mutation makes every bootstrap request that started before this
            // response obsolete. Those responses must never overwrite the
            // authoritative local save result when they finish later.
            invalidateBootstrapLoads();
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
                        ? `<div class="item-meta" style="margin-top:4px">For: ${html(flowtrackToArray(new Set(flowtrackToArray(notification.audience).map((recipient) => recipient?.name).filter(Boolean))).join(', '))}</div>`
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

                    const notificationTask = notification.task_id
                        ? tasks.find((task) => Number(task.dbId) === Number(notification.task_id))
                        : null;
                    if (notificationTask) {
                        openTask(notificationTask.id);
                    } else if (notification.job_id) {
                        const notificationJob = jobs.find((job) => Number(job.dbId) === Number(notification.job_id) || String(job.id) === String(notification.job_id));
                        if (notificationJob) openJob(notificationJob.id);
                        else if (notification.url) flowtrackNavigateTo(notification.url);
                    } else if (notification.url) {
                        flowtrackNavigateTo(notification.url);
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
            if (currentUser?.avatarUrl && flowtrackSafeUrl(currentUser.avatarUrl)) {
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
        replaceArray(boardAssigneeUsers, data.boardAssigneeUsers || data.users);
        replaceArray(clients, data.clients);
        replaceArray(jobs, data.jobs);
        boardJobIds = new Set(flowtrackToArray(data.boardJobIds).map((id) => Number(id)));
        boardAccess = data.boardAccess || { isAdministrator: false, taskVisibility: 'assigned_job_tasks' };
        replaceArray(tasks, data.tasks);
        tasks.forEach(flowtrackHydrateTaskAssignee);
        replaceArray(docs, data.documents);
        if (hasOwnBootstrapField(data, 'notifications')) replaceArray(notifications, data.notifications);
        if (hasOwnBootstrapField(data, 'activities')) replaceArray(dashboardActivities, data.activities);
        if (hasOwnBootstrapField(data, 'workflows')) replaceArray(workflowTemplates, flowtrackNormalizeWorkflows(data.workflows));
        if (hasOwnBootstrapField(data, 'taskPacks')) {
            taskPacks = flowtrackToArray(data.taskPacks).filter((pack) => pack && typeof pack === 'object');
        }
        capabilities = data.capabilities && typeof data.capabilities === 'object' ? data.capabilities : {};
        summaryMetrics = data.summaryMetrics && typeof data.summaryMetrics === 'object' && !Array.isArray(data.summaryMetrics) ? data.summaryMetrics : {};
        notificationRules = flowtrackToArray(data.notificationRules);
        notificationAccess = data.notificationAccess || notificationAccess;
        notificationUnread = notifications.filter((notification) => !notification.read).length;
        currentSensitiveFields = flowtrackToArray(data.currentUser?.sensitiveFields);
        canViewAccess = Boolean(data.accessControl);
        canManageAccess = Boolean(data.accessControl?.canManage);
        canCreateUsers = Boolean(data.accessControl?.canCreateUsers);
        canDeleteUsers = Boolean(data.accessControl?.canDeleteUsers);
        isFlowAdmin = Boolean(data.accessControl?.isAdmin || data.currentUser?.isAdmin);
        isFlowSuperAdmin = Boolean(data.accessControl?.isSuperAdmin || data.currentUser?.isSuperAdmin);
        accessRoles = flowtrackToArray(data.accessControl?.roles);
        accessMembers = flowtrackToArray(data.accessControl?.members);
        const incomingAccessCatalog = data.accessControl?.catalog || accessCatalog || {};
        accessCatalog = {
            modules: flowtrackToArray(incomingAccessCatalog.modules),
            actions: flowtrackToArray(incomingAccessCatalog.actions),
            scopes: flowtrackToArray(incomingAccessCatalog.scopes),
            sensitiveFields: flowtrackToArray(incomingAccessCatalog.sensitiveFields),
        };
        if (!selectedAccessRoleId || !accessRoles.some((role) => Number(role.id) === Number(selectedAccessRoleId))) {
            selectedAccessRoleId = accessRoles[0]?.id || null;
        }

        masterCatalog = flowtrackToArray(data.masterDataCatalog);
        for (const key of Object.keys(masterLabels)) delete masterLabels[key];
        for (const key of Object.keys(masterData)) delete masterData[key];
        masterCatalog.forEach((section) => {
            masterLabels[section.key] = section.label;
            masterData[section.key] = flowtrackToArray(data.masterData?.[section.key]);
        });
        if (!masterCatalog.some((section) => section.key === selectedMasterCategory)) {
            selectedMasterCategory = masterCatalog[0]?.key || '';
        }
        rebuildTaskPackDerivedState();
        selectedWorkflowId = workflowTemplates[0]?.id ?? selectedWorkflowId;
        if (!boardWorkflowId || !workflowTemplates.some((workflow) => Number(workflow.id) === Number(boardWorkflowId))) {
            boardWorkflowId = workflowTemplates.find((workflow) => workflow.active !== false)?.id
                ?? workflowTemplates[0]?.id
                ?? null;
        }

        applyCurrentUserIdentity();
        initializeRealtimeNotifications();
    }

    async function loadBootstrapSegment(url, perPage = 50, generation = bootstrapGeneration) {
        if (!url) return { rows: [], pagination: null };
        if (generation !== bootstrapGeneration) return null;

        const pageUrl = `${url}${url.includes('?') ? '&' : '?'}per_page=${encodeURIComponent(perPage)}`;
        const page = await api(pageUrl, { cache: 'no-store' });
        if (generation !== bootstrapGeneration) return null;
        if (!page || typeof page !== 'object') {
            throw new Error('FlowTrack returned an invalid data page. Please reload once more.');
        }

        return {
            rows: flowtrackToArray(page.data),
            relatedJobs: flowtrackToArray(page.relatedJobs),
            relatedClients: flowtrackToArray(page.relatedClients),
            pagination: page.pagination || null,
        };
    }

    async function loadBootstrap() {
        const generation = ++bootstrapGeneration;
        const coreUrl = `/flowtrack/template-api/bootstrap?_flowtrack_generation=${generation}&_=${Date.now()}`;
        flowtrackSetLoadingProgress(6, 'Preparing FlowTrack workspace…');
        const core = await api(coreUrl, { cache: 'no-store' });
        if (generation !== bootstrapGeneration) return null;
        if (!core || typeof core !== 'object') {
            throw new Error('FlowTrack bootstrap data is unavailable. Please reload the page.');
        }

        flowtrackSetLoadingProgress(18, 'Loading configuration…');
        const segmentUrls = core.segments || {};
        const perPage = Number(segmentUrls.perPage || 100);
        let completedSegments = 0;
        const loadSegmentWithProgress = async (url, label) => {
            const page = await loadBootstrapSegment(url, perPage, generation);
            completedSegments += 1;
            flowtrackSetLoadingProgress(18 + (completedSegments * 16), label);
            return page;
        };
        const [segmentClients, segmentJobs, segmentTasks, segmentDocuments] = await Promise.all([
            loadSegmentWithProgress(segmentUrls.clients, 'Loading clients…'),
            loadSegmentWithProgress(segmentUrls.jobs, 'Loading jobs…'),
            loadSegmentWithProgress(segmentUrls.tasks, 'Loading tasks and assignees…'),
            loadSegmentWithProgress(segmentUrls.documents, 'Loading documents…'),
        ]);
        if (generation !== bootstrapGeneration) return null;
        if ([segmentClients, segmentJobs, segmentTasks, segmentDocuments].some((segment) => segment === null)) return null;

        segmentPagination.clients = segmentClients.pagination;
        segmentPagination.jobs = segmentJobs.pagination;
        segmentPagination.tasks = segmentTasks.pagination;
        segmentPagination.documents = segmentDocuments.pagination;

        replaceArray(clientLabels, []);
        [segmentJobs, segmentTasks, segmentDocuments].forEach((segment) => {
            flowtrackToArray(segment.relatedClients).forEach((client) => flowtrackUpsertRecord(clientLabels, client, false));
        });
        const initialJobs = [];
        segmentJobs.rows.forEach((job) => flowtrackUpsertRecord(initialJobs, job, false));
        [segmentTasks, segmentDocuments].forEach((segment) => {
            flowtrackToArray(segment.relatedJobs).forEach((job) => flowtrackUpsertRecord(initialJobs, job, false));
        });

        const data = Object.assign({}, core, {
            clients: segmentClients.rows,
            jobs: initialJobs,
            tasks: segmentTasks.rows,
            documents: segmentDocuments.rows,
        });
        if (generation !== bootstrapGeneration) return null;
        flowtrackSetLoadingProgress(88, 'Applying workspace data…');
        applyBootstrap(data);
        bootstrapAppliedGeneration = generation;
        flowtrackFinishLoadingProgress('FlowTrack loaded');
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

    function flowtrackEnsurePageProgress() {
        let bar = document.getElementById('flowtrackPageProgress');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'flowtrackPageProgress';
            bar.className = 'flowtrack-page-progress hidden';
            bar.innerHTML = '<div class="flowtrack-page-progress-track"><span></span></div><small>Loading…</small>';
            document.body.appendChild(bar);
        }
        return bar;
    }

    function flowtrackSetLoadingProgress(percent, label = 'Loading workspace…') {
        const value = Math.max(0, Math.min(100, Number(percent) || 0));
        const auth = document.getElementById('authLoadingScreen');
        auth?.querySelector('[data-auth-loading-progress]')?.style.setProperty('width', `${value}%`);
        const authLabel = auth?.querySelector('[data-auth-loading-label]');
        if (authLabel) authLabel.textContent = label;
        const authPct = auth?.querySelector('[data-auth-loading-percent]');
        if (authPct) authPct.textContent = `${Math.round(value)}%`;

        const appVisible = !document.getElementById('appScreen')?.classList.contains('hidden');
        if (appVisible) {
            const bar = flowtrackEnsurePageProgress();
            bar.classList.remove('hidden');
            bar.querySelector('span')?.style.setProperty('width', `${value}%`);
            const small = bar.querySelector('small');
            if (small) small.textContent = `${label} ${Math.round(value)}%`;
        }
    }

    function flowtrackFinishLoadingProgress(label = 'Ready') {
        flowtrackSetLoadingProgress(100, label);
        window.setTimeout(() => document.getElementById('flowtrackPageProgress')?.classList.add('hidden'), 350);
    }

    function flowtrackInlineProgress(label = 'Loading latest data…') {
        return `<div class="flowtrack-inline-progress"><div class="flowtrack-inline-progress-copy"><b>${html(label)}</b><span>Refreshing assigned task setup, due dates and history.</span></div><div class="flowtrack-inline-progress-track"><span></span></div></div>`;
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
        let requirements = flowtrackToArray(jobDocumentRequirements(job))
            .sort((left, right) => Number(left.present) - Number(right.present));
        if (!requirements.length) {
            const workflow = getWorkflow(Number(job.workflow));
            const currentPhase = (workflow?.phases || []).find((phase) => Number(phase.id) === Number(job.phaseId)) || (workflow?.phases || [])[0];
            requirements = activeMaster('documentCategories').map((category) => ({
                key: `generic:${category.id}`,
                phaseId: Number(job.phaseId || currentPhase?.id || 0),
                phaseName: currentPhase?.name || phaseNameFor(job),
                taskPackItemId: null,
                categoryId: Number(category.id),
                categoryName: category.name,
                sourceLabel: 'Job document',
                requiredForReadiness: false,
                present: false,
            })).filter((item) => item.categoryId && item.phaseId);
        }
        if (!requirements.length) {
            showToast('No document type is configured for this Job.');
            return;
        }

        const defaultRequirement = requirements.find((item) => !item.present) || requirements[0];
        const singleRequirement = requirements.length === 1;
        const title = singleRequirement ? `Upload ${html(defaultRequirement.categoryName)}` : 'Document attachment';

        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>${title}</h2><div class="small muted" style="margin-top:5px">The selected file will be linked to ${html(job.id)}, ${html(defaultRequirement.phaseName)}, and its exact Workflow requirement.</div></div><button class="close-btn" data-upload-close>×</button></div>
        <div class="modal-body"><div class="source-note"><b>Job and client</b><div class="small muted" style="margin-top:4px">${html(job.id)} · ${html(job.title)} · ${html(clientName(job.client))}</div></div>
        <div class="source-note" style="margin-top:12px;background:var(--amber2);color:var(--amber)"><b>Current Workflow Phase</b><div class="small" style="margin-top:4px">${html(defaultRequirement.phaseName)}</div></div>
        <div class="form-grid" style="margin-top:16px">
          <div class="field full"><label>Document type *</label>${singleRequirement
              ? `<input value="${html(defaultRequirement.categoryName)} · ${html(defaultRequirement.sourceLabel)}" readonly aria-readonly="true"><input id="jobDocumentRequirement" type="hidden" value="${html(defaultRequirement.key)}">`
              : `<select id="jobDocumentRequirement" data-searchable data-search-placeholder="Search required documents…">${requirements.map((requirement) => `<option value="${html(requirement.key)}" ${requirement.key === defaultRequirement.key ? 'selected' : ''}>${html(requirement.categoryName)} · ${html(requirement.sourceLabel)}${requirement.present ? ' · Uploaded' : ''}</option>`).join('')}</select>`}
            <div class="inline-help">Only files configured in Workflow Setup or Task Pack Setup for this current Phase can be uploaded.</div>
          </div>
          <div class="field full"><label>Upload file *</label><div class="task-attachment-dropzone job-document-dropzone" data-job-upload-dropzone><input id="jobDocumentFile" class="task-document-file-input" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip"><div class="task-upload-cloud">☁↑</div><b>Drag and drop your ${html(String(defaultRequirement.categoryName || 'document').toLowerCase())} here</b><span>or</span><div class="task-upload-actions"><button type="button" class="primary" data-job-upload-browse>Browse files</button><button type="button" class="ghost" data-job-choose-documents>Choose from Documents</button></div><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Maximum 20 MB</small></div><div id="jobDocumentSelectionPreview"></div><div class="task-attachment-info"><span>ⓘ</span><b>The file will be attached when you save this document.</b></div></div>
          <div class="field full"><label>Notes</label><textarea id="jobDocumentNotes" rows="3" placeholder="Add a version note, reference number or document description…"></textarea></div>
        </div></div>
        <div class="modal-foot"><button class="ghost" data-upload-close>Cancel</button><button class="primary" id="uploadJobDocumentBtn">Upload & Link</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-upload-close]').forEach((button) => button.addEventListener('click', flowtrackCloseModalOnly));
        window.FlowtrackSearchableDropdown?.enhance($('#jobModal'));
        const jobDocumentFileInput = $('#jobDocumentFile');
        const jobDropzone = $('[data-job-upload-dropzone]');
        const renderJobDocumentPreview = (file) => {
            const target = $('#jobDocumentSelectionPreview');
            if (!target || !file) return;
            const safeSize = typeof formatTaskFileSize === 'function' ? formatTaskFileSize(file.size) : `${Math.round(file.size / 1024)} KB`;
            target.innerHTML = `<div class="task-document-selected-preview"><div class="doc-icon" style="flex:0 0 auto">FILE</div><div class="task-document-file-copy"><b class="task-document-file-name">${html(file.name)}</b><div class="small muted">${safeSize} · Selected. It will be stored when you press Upload & Link.</div><div class="task-upload-progress"><span style="width:100%"></span></div></div></div>`;
        };
        $('[data-job-upload-browse]')?.addEventListener('click', () => jobDocumentFileInput?.click());
        $('[data-job-choose-documents]')?.addEventListener('click', () => showToast('Choose an existing document from the Job Documents list.'));
        jobDropzone?.addEventListener('dragover', (event) => { event.preventDefault(); jobDropzone.classList.add('is-dragging'); });
        jobDropzone?.addEventListener('dragleave', () => jobDropzone.classList.remove('is-dragging'));
        jobDropzone?.addEventListener('drop', (event) => {
            event.preventDefault();
            jobDropzone.classList.remove('is-dragging');
            const file = event.dataTransfer?.files?.[0];
            if (!file || !jobDocumentFileInput) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            jobDocumentFileInput.files = transfer.files;
            renderJobDocumentPreview(file);
        });
        jobDocumentFileInput?.addEventListener('change', (event) => renderJobDocumentPreview(event.target.files?.[0] || null));

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
                const updatedJob = result.job ? (jobs.find((item) => Number(item.dbId) === Number(result.job.dbId)) || result.job) : job;
                flowtrackCloseModalOnly();
                if (updatedJob && String(currentPage || '').startsWith('record-job-')) {
                    flowtrackEnsureJobPanels(updatedJob, 8);
                } else if (currentPage === 'documents') {
                    renderDocuments();
                } else {
                    renderPage(currentPage);
                }
                updateBadges();
                showToast(result.message || 'Document uploaded and linked.');
            } catch (error) {
                showToast(error.message);
                button.disabled = false;
                button.textContent = 'Upload & Link';
            }
        });
    }

    function bindJobDocumentUploadButtons(root, job, preferredTab = 8) {
        const scope = root || document;
        if (!job || !scope?.querySelectorAll) return;
        scope.querySelectorAll('[data-upload-job]').forEach((button) => {
            if (!canFlow('documents', 'create') || !canFlow('documents', 'link')) {
                button.remove();
                return;
            }
            if (!button.textContent?.trim()) button.textContent = '＋ Add document';
            flowtrackBindOnce(button, 'upload-job-document', 'click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const selectedJob = jobs.find((item) => String(item.id) === String(button.dataset.uploadJob || job.id) || Number(item.dbId) === Number(button.dataset.uploadJob || job.dbId)) || job;
                openJobDocumentUpload(selectedJob, preferredTab === 8);
            });
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
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Document attachment</h2><div class="small muted" style="margin-top:5px">Choose the Job first. Only its configured Workflow Phase and Task Pack document categories will be available.</div></div><button class="close-btn" data-required-job-close>×</button></div>
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

    registerFlowtrackExtension('openJob', 'openJob-stage-1', function (id) {        const job = jobs.find((item) => String(item.id) === String(id) || Number(item.dbId) === Number(id));
        if (!job) return;
        originalOpenJob(job.id);
        const drawer = $('#detailDrawer');
        if (!drawer?.classList.contains('open')) return;

        const head = drawer.querySelector('.drawer-head');
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
        const timeline = drawer.querySelector('#detailPanel1 .timeline');
        if (timeline && activePhases.length) {
            timeline.innerHTML = activePhases.map((phase) => {
                const originalIndex = (workflow?.phases || []).findIndex((item) => Number(item.id) === Number(phase.id));
                const skipped = (job.skippedPhases || []).includes(originalIndex);
                const beforeCurrent = currentPhase && Number(phase.sequence) < Number(currentPhase.sequence);
                const isCurrent = Number(phase.id) === Number(job.phaseId);
                const state = skipped ? 'skipped' : beforeCurrent ? 'done' : isCurrent ? 'current' : '';
                const dot = skipped ? '↷' : beforeCurrent ? '✓' : phase.sequence;
                return `<button type="button" class="step workflow-phase-step ${state}" data-flow-phase="${html(phase.id)}" data-flow-job="${html(job.id)}" title="View ${html(phase.name)} task history"><div class="step-dot">${dot}</div><div class="step-label">${html(phase.name)}${skipped ? '<br><small>Skipped / external</small>' : ''}</div></button>`;
            }).join('');
            const workflowPanel = drawer.querySelector('#detailPanel1');
            if (workflowPanel && !workflowPanel.querySelector('#flowtrackPhaseHistoryPanel')) {
                timeline.insertAdjacentHTML('afterend', `<div id="flowtrackPhaseHistoryPanel" style="margin-top:14px">${flowtrackRenderPhaseHistoryPanel(job, currentPhase?.id || activePhases[0]?.id)}</div>`);
            }
        }

        const requirement = jobPhaseDocumentRequirement(job);
        const workflowActions = drawer.querySelector('#detailPanel1 .actions');
        if (canFlow('documents', 'create') && canFlow('documents', 'link') && workflowActions && requirement.categoryId && !requirement.present && !workflowActions.querySelector('[data-upload-required-document]')) {
            const uploadButton = document.createElement('button');
            uploadButton.className = 'secondary';
            uploadButton.dataset.uploadRequiredDocument = job.id;
            uploadButton.textContent = 'Open Document Upload';
            workflowActions.insertBefore(uploadButton, workflowActions.firstChild);
            uploadButton.addEventListener('click', () => goToDocumentUpload(job));
        }

        bindJobDocumentUploadButtons(drawer, job, 8);

        // The Job Tasks tab must reflect only the Job's current Workflow Phase.
        // Completed Tasks from previous phases remain available in Workflow history,
        // but they are intentionally excluded from this operational list.
        const taskPanel = drawer.querySelector('#detailPanel2');
        if (taskPanel) {
            const panelHeading = taskPanel.querySelector('.section-head h3');
            if (panelHeading) panelHeading.textContent = 'Current Phase Tasks';

            const panelHead = taskPanel.querySelector('.section-head');
            if (panelHead && !panelHead.querySelector('[data-current-phase-task-label]')) {
                const phaseLabel = document.createElement('span');
                phaseLabel.className = 'small muted';
                phaseLabel.dataset.currentPhaseTaskLabel = '1';
                phaseLabel.textContent = currentPhase?.name || job.phase || 'Current Phase';
                phaseLabel.style.marginLeft = '8px';
                phaseLabel.style.marginRight = 'auto';
                const addButton = panelHead.querySelector('[data-add-task]');
                panelHead.insertBefore(phaseLabel, addButton || null);
            }

            const currentPhaseTaskIds = new Set(
                (typeof jobCurrentPhaseTasks === 'function' ? jobCurrentPhaseTasks(job, tasks.filter((item) => String(item.job) === String(job.id) || Number(item.jobDbId) === Number(job.dbId))) : [])
                    .map((item) => String(item.id))
            );
            let visibleCurrentTasks = 0;
            taskPanel.querySelectorAll('[data-task]').forEach((row) => {
                const rowTask = tasks.find((item) => item.id === row.dataset.task || Number(item.dbId) === Number(row.dataset.task));
                if (!rowTask || (currentPhaseTaskIds.size && !currentPhaseTaskIds.has(String(rowTask.id)))) {
                    row.remove();
                    return;
                }
                visibleCurrentTasks += 1;
            });

            taskPanel.querySelectorAll('.empty-state').forEach((empty) => empty.remove());
            if (!visibleCurrentTasks) {
                const empty = document.createElement('div');
                empty.className = 'empty-state';
                empty.textContent = `No Tasks are configured for ${currentPhase?.name || 'the current phase'} yet.`;
                taskPanel.appendChild(empty);
            }
        }
        applyActionPermissions();
    });

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
            const hydratedTask = flowtrackHydrateTaskAssignee(result.task);
            if (taskIndex >= 0) tasks.splice(taskIndex, 1, hydratedTask);
            else tasks.push(hydratedTask);
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
        flowtrackToArray(newTasks).forEach((task) => tasks.push(flowtrackHydrateTaskAssignee(task)));
    }

    async function persistTask(task, status, extras = {}) {
        if (!task?.canUpdate && !isFlowAdmin && !isFlowSuperAdmin) {
            throw new Error('Only the assigned user, an Administrator, or the Super Admin can update this Task.');
        }
        const statusRecord = taskStatusRecord(status);
        if (!statusRecord) throw new Error('The selected task status is not available in this workspace.');
        const payload = {
            title: extras.title ?? task.title,
            description: extras.description ?? task.description ?? null,
            task_status_id: statusRecord.id,
            assignee_id: Object.prototype.hasOwnProperty.call(extras, 'assignee_id') ? extras.assignee_id : (flowtrackResolveTaskAssigneeId(task) || null),
            priority_id: extras.priority_id ?? task.priorityId ?? null,
            due_date: extras.due_date ?? task.due ?? null,
            progress: taskProgressForStatus(statusRecord),
            attention_required: extras.attention_required ?? task.attention ?? false,
            blocker_reason: extras.blocker_reason ?? task.blockerReason ?? null,
            comment: extras.comment ?? '',
            mentioned_user_ids: extras.mentioned_user_ids ?? [],
        };
        const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'PUT', body: payload });
        updateTaskAndJob(result);
        return result;
    }

    registerFlowtrackExtension('openTask', 'openTask-stage-1', function (id) {        const task = flowtrackHydrateTaskAssignee(tasks.find((item) => item.id === id || Number(item.dbId) === Number(id)));
        if (!task) return;
        const job = jobs.find((item) => item.id === task.job || Number(item.dbId) === Number(task.jobDbId));
        const selectedStatus = taskStatusRecord(task.statusId || task.statusCode || task.status);
        const statuses = selectableTaskStatuses(selectedStatus);
        const priorityOptions = (masterData.priorities || [])
            .filter((priority) => String(priority.status || 'Active').toLowerCase() === 'active' || Number(priority.id) === Number(task.priorityId))
            .map((priority) => `<option value="${html(priority.id)}" ${Number(priority.id) === Number(task.priorityId) ? 'selected' : ''}>${html(priority.name)}</option>`)
            .join('');
        const taskAlreadyCompleted = taskIsCompleted(task) || taskStatusMatches(selectedStatus, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
        const completedTaskLocked = taskAlreadyCompleted && !(isFlowAdmin || isFlowSuperAdmin);
        const canEdit = !completedTaskLocked && (task.canUpdate || isFlowAdmin || isFlowSuperAdmin);
        const canDeleteTask = Boolean(task.canDelete || isFlowAdmin || isFlowSuperAdmin);
        const canChangeDue = !completedTaskLocked && (task.canChangeDueDate || isFlowAdmin || isFlowSuperAdmin);
        const initialCompleted = taskAlreadyCompleted;
        const progress = Math.max(0, Math.min(100, Number(task.progress) || 0));
        const history = Array.isArray(task.history) ? task.history : [];
        const mentionableUsers = flowtrackToArray(task.mentionableUsers).concat(flowtrackToArray(users))
            .filter((user, index, collection) => user && collection.findIndex((candidate) => Number(candidate.id) === Number(user.id)) === index);
        const taskAssigneeId = flowtrackResolveTaskAssigneeId(task);
        const taskAssigneeName = flowtrackResolveTaskAssigneeName(task, taskAssigneeId);
        const taskAssigneeOptions = flowtrackTaskAssigneeOptions(task, mentionableUsers);
        const taskAssigneeOptionsHtml = taskAssigneeOptions.map((user) => `<option value="${html(user.id)}" ${Number(user.id) === Number(taskAssigneeId) ? 'selected' : ''}>${html(user.name)}</option>`).join('');
        const documentRequirement = task.documentRequirement || null;
        const taskDocuments = Array.isArray(task.documents) ? task.documents : [];
        const formatTaskFileSize = (bytes) => {
            const value = Math.max(0, Number(bytes) || 0);
            if (value < 1024) return `${value} B`;
            if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
            return `${(value / (1024 * 1024)).toFixed(1)} MB`;
        };
        const taskDocumentCategories = (masterData.documentCategories || []).filter((category) => String(category.status || 'Active').toLowerCase() === 'active');
        const genericTaskAttachment = Boolean(documentRequirement?.genericAttachment);
        const selectedTaskDocumentCategoryId = taskDocuments[0]?.categoryId || documentRequirement?.categoryId || null;
        const taskDocumentCategoryOptions = taskDocumentCategories.map((category) => `<option value="${html(category.id)}" ${Number(category.id) === Number(selectedTaskDocumentCategoryId) ? 'selected' : ''}>${html(category.name)}</option>`).join('');
        const documentRowsHtml = taskDocuments.flatMap((documentRecord) => {
            const versions = Array.isArray(documentRecord.versions) ? documentRecord.versions : [];
            return versions.map((version, versionIndex) => {
                const imagePreview = version.previewable && String(version.mimeType || '').startsWith('image/') && version.previewUrl
                    ? `<a href="${html(version.previewUrl)}" target="_blank" rel="noopener" title="Preview ${html(version.originalName)}"><img class="task-document-thumb" src="${html(version.previewUrl)}" alt="Preview of ${html(version.originalName)}"></a>`
                    : '<div class="doc-icon" style="flex:0 0 auto">FILE</div>';
                const currentBadge = versionIndex === 0 ? '<span class="badge b-green">Current</span>' : '';
                return `<div class="task-document-file">${imagePreview}<div class="task-document-file-copy"><b class="task-document-file-name">${html(version.originalName || documentRecord.name || 'Document')}</b><div class="small muted" style="margin-top:4px">Version ${version.version || 1} · ${formatTaskFileSize(version.size)} · ${html(version.uploadedBy || documentRecord.uploadedBy || 'System')}</div>${version.notes ? `<div class="small" style="margin-top:5px">${html(version.notes)}</div>` : ''}</div><div class="task-document-actions">${currentBadge}${version.previewUrl ? `<a class="secondary" href="${html(version.previewUrl)}" target="_blank" rel="noopener">Preview</a>` : ''}${version.downloadUrl ? `<a class="ghost" href="${html(version.downloadUrl)}">Download</a>` : ''}</div></div>`;
            });
        }).join('');
        const taskAttachmentVersions = taskDocuments.flatMap((documentRecord) => (Array.isArray(documentRecord.versions) ? documentRecord.versions : []).map((version, versionIndex) => ({ documentRecord, version, versionIndex })));
        const selectedTaskAttachment = taskAttachmentVersions[0] || null;
        const taskAttachmentRowsHtml = taskAttachmentVersions.map(({ documentRecord, version, versionIndex }, index) => {
            const fileName = version.originalName || documentRecord.name || 'Document';
            const owner = version.uploadedBy || documentRecord.uploadedBy || taskAssigneeName || 'System';
            const updated = version.uploadedAt ? flowtrackFormatDateTime(version.uploadedAt).split(',')[0] : (documentRecord.date || '—');
            const typeLabel = documentRecord.type || documentRequirement.categoryName || 'Attachment';
            const versionLabel = `v${version.version || documentRecord.version || versionIndex + 1}`;
            return `<tr class="${index === 0 ? 'selected' : ''}"><td><input type="checkbox" ${index === 0 ? 'checked' : ''} disabled></td><td><span class="job-v3-pdf">${String(fileName).toLowerCase().endsWith('.pdf') ? 'PDF' : 'FILE'}</span><b>${html(fileName)}</b><small>${html(typeLabel)} · ${formatTaskFileSize(version.size)}</small></td><td>${html(documentRequirement.sourceLabel || task.phase || 'Task')}</td><td>${html(typeLabel)}</td><td>${html(versionLabel)}</td><td><span class="avatar">${userInitials(owner)}</span> ${html(owner)}</td><td><span class="badge b-green">Approved</span></td><td>${html(updated)}</td><td>${version.previewUrl ? `<a class="mini-btn" href="${html(version.previewUrl)}" target="_blank" rel="noopener">Open</a>` : ''}</td></tr>`;
        }).join('');
        const taskAttachmentUploadHtml = task.canUploadDocument ? `<div class="task-v3-upload-panel task-document-attachment-card"><div class="task-attachment-template-head"><div class="task-attachment-icon">▧</div><div><h3>Document attachment</h3><p>${genericTaskAttachment ? `Task attachment: ${html(task.title || 'Task')}` : `Required for ${html(task.title || documentRequirement.sourceLabel || 'this task')}`}</p></div><span class="badge ${genericTaskAttachment ? 'b-blue' : 'b-amber'}">${genericTaskAttachment ? 'Attachment' : 'Required'}</span></div><div class="field"><label>Document type *</label><select id="taskDocumentCategory" ${genericTaskAttachment ? '' : 'disabled'}><option value="">Select document type</option>${taskDocumentCategoryOptions}</select>${genericTaskAttachment ? '' : `<input type="hidden" id="taskDocumentCategoryHidden" value="${html(documentRequirement.categoryId || '')}">`}</div><div class="field task-upload-field"><label>Upload file *</label><div class="task-attachment-dropzone" data-task-upload-dropzone><input id="taskDocumentFile" class="task-document-file-input" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip"><div class="task-upload-cloud">☁↑</div><b>Drag and drop your ${html(String(documentRequirement.categoryName || 'document').toLowerCase())} here</b><span>or</span><div class="task-upload-actions"><button type="button" class="primary" data-task-upload-browse>Browse files</button><button type="button" class="ghost" data-task-choose-documents>Choose from Documents</button></div><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Maximum 20 MB</small></div><div data-task-upload-preview-anchor></div></div><div class="task-attachment-info"><span>ⓘ</span><b>The file will be attached when you save this task.</b></div><div class="field"><label>Attachment note</label><textarea id="taskDocumentNotes" rows="2" placeholder="Add a version note, reference number or document description…"></textarea></div></div>` : (!taskDocuments.length ? '<div class="source-note" style="margin-top:12px"><b>Upload unavailable</b><div class="small muted" style="margin-top:4px">Documents can only be uploaded while this is the Job’s current phase and by users with Document Upload & Link access.</div></div>' : '');
        const taskAttachedFilesHtml = taskAttachmentVersions.length ? `<div class="task-attachment-existing-files"><h4>Attached files</h4>${taskAttachmentVersions.map(({ documentRecord, version, versionIndex }) => { const fileName = version.originalName || documentRecord.name || 'Document'; const owner = version.uploadedBy || documentRecord.uploadedBy || taskAssigneeName || 'System'; const typeLabel = documentRecord.type || documentRequirement.categoryName || 'Attachment'; return `<div class="task-attachment-existing-file"><span class="job-v3-pdf">${String(fileName).toLowerCase().endsWith('.pdf') ? 'PDF' : 'FILE'}</span><div><b>${html(fileName)}</b><small>${html(typeLabel)} · v${version.version || documentRecord.version || versionIndex + 1} · ${html(owner)}</small></div>${version.previewUrl ? `<a class="mini-btn" href="${html(version.previewUrl)}" target="_blank" rel="noopener">Open</a>` : ''}</div>`; }).join('')}</div>` : '';
        const taskAttachmentReadonlyHtml = taskAttachedFilesHtml ? `<div class="task-document-attachment-card"><div class="task-attachment-template-head"><div class="task-attachment-icon">▧</div><div><h3>Document attachment</h3><p>${html(documentRequirement.categoryName)} · ${html(documentRequirement.sourceLabel || task.title || 'Task')}</p></div><span class="badge b-green">Uploaded</span></div>${taskAttachedFilesHtml}</div>` : '';
        const documentSectionHtml = documentRequirement ? `<section class="task-v3-doc-section task-attachment-system-section">${taskAttachmentUploadHtml || taskAttachmentReadonlyHtml}</section>` : '';
        const historyHtml = history.map((entry) => `<div class="task-history-entry">
            <div class="task-history-head"><span class="avatar">${html(entry.actorInitials || userInitials(entry.actor))}</span><div class="task-history-copy"><b>${html(entry.actor || 'System')}</b><div style="margin-top:3px">${html(entry.description || 'Task updated')}</div><div class="small muted" style="margin-top:3px">${html(entry.time || (entry.occurredAt ? new Date(entry.occurredAt).toLocaleString() : ''))}</div></div></div>
            ${(entry.changes || []).length ? `<ul class="task-change-list">${entry.changes.map((change) => `<li><b>${html(change.field)}:</b> ${html(change.from)} → ${html(change.to)}</li>`).join('')}</ul>` : ''}
        </div>`).join('');

        $('#detailDrawer').innerHTML = `<div class="drawer-head"><div><div class="small muted">${html(task.id)} · <button type="button" class="task-job-link" data-task-job-link>${html(task.job)}</button></div><h2>${html(task.title)}</h2><div>${statusBadge(task.status)} <span class="badge ${task.priority === 'Critical' ? 'b-red' : task.priority === 'High' ? 'b-amber' : 'b-gray'}">${html(task.priority)}</span></div></div><div class="task-head-actions">${canDeleteTask ? `<div class="task-menu-wrap"><button class="task-menu-button" type="button" id="taskActionMenuButton" aria-label="Task actions" aria-expanded="false">•••</button><div class="task-action-menu hidden" id="taskActionMenu"><button type="button" class="danger-option" data-delete-task="${task.dbId}">Delete Task</button></div></div>` : ''}<button class="close-btn" data-close>×</button></div></div>
        <div class="drawer-body"><div class="summary-grid"><div class="summary-box"><span>Job</span><button type="button" class="task-job-link" data-task-job-link>${html(job?.title || task.jobTitle || task.job)}</button></div><div class="summary-box"><span>Assignee</span><b>${html(taskAssigneeName)}</b></div><div class="summary-box"><span>Due Date</span><b class="${dueClass(task.due)}">${formatDate(task.due)}</b></div></div>${completedTaskLocked ? '<div class="source-note" style="margin-top:14px"><b>Completed task locked</b><div class="small muted" style="margin-top:4px">Only Admin or Super Admin can edit/update a completed Task. You can still view documents, comments and history.</div></div>' : ''}
        <div class="form-grid" style="margin-top:18px"><div class="field full"><label>Task title</label><input id="taskUpdateTitle" value="${html(task.title || '')}" maxlength="255" ${canEdit ? '' : 'disabled'}></div>
        <div class="field full"><label>Description</label><textarea id="taskUpdateDescription" rows="3" maxlength="5000" placeholder="Add clear sub task details, expected output or instructions…" ${canEdit ? '' : 'disabled'}>${html(task.description || '')}</textarea><div class="inline-help">Admins can update completed tasks. Before completion, authorised task owners can update their assigned work without changing the workflow template.</div></div>
        <div class="field"><label>Status</label><select id="taskUpdateStatus" ${canEdit ? '' : 'disabled'}>${statuses.map((status) => `<option value="${status.id}" ${Number(status.id) === Number(selectedStatus?.id) ? 'selected' : ''}>${html(status.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Priority</label><select id="taskUpdatePriority" ${canEdit ? '' : 'disabled'}><option value="">Use job priority</option>${priorityOptions}</select></div>
        <div class="field"><label>Assignee</label><select id="taskUpdateAssignee" data-original-assignee="${html(taskAssigneeId || '')}" ${task.canAssign || isFlowAdmin || isFlowSuperAdmin ? '' : 'disabled'}><option value="" ${taskAssigneeId ? '' : 'selected'}>Unassigned</option>${taskAssigneeOptionsHtml}</select><div class="inline-help">Default assignee from Task Pack Setup is selected automatically unless Admin/Super Admin changes it.</div></div>
        <div class="field"><label>Due Date</label><input type="date" id="taskUpdateDueDate" value="${task.due ? task.due.split('T')[0] : ''}" ${canChangeDue ? '' : 'disabled'}><div class="inline-help">Available for regular tasks and subtasks.</div></div>
        <div class="field full"><label>Progress: <b id="progressValue">${progress}%</b></label><div class="task-progress-track" aria-label="Task progress"><span id="taskProgressFill" class="task-progress-fill ${progress >= 100 ? 'complete' : ''}" style="width:${progress}%"></span></div><div class="inline-help">Progress is calculated automatically from the selected status.</div></div></div>
        ${documentSectionHtml}
        <div class="field mention-wrap" style="margin-top:18px"><label>Update comment</label><textarea id="taskComment" rows="3" placeholder="Add progress, decision or next action. Type @ to mention a Job team member…" ${canEdit ? '' : 'disabled'}></textarea><div id="taskMentionMenu" class="mention-menu hidden"></div><div class="inline-help">Type @ and choose a person. They will receive a notification that opens this Task.</div></div>
        <label class="check-row task-attention-row"><input id="taskAttention" type="checkbox" ${task.attention ? 'checked' : ''} ${canEdit && !initialCompleted ? '' : 'disabled'}><span class="task-attention-control" aria-hidden="true"></span><span class="task-attention-copy"><b>Mark as attention needed</b><small>Show this Task in Dashboard → Needs Attention until the flag is cleared.</small></span></label>
        <div class="section-head" style="margin-top:18px"><h3>Activity & Comments</h3></div>
        ${(task.comments || []).map((comment) => `<div class="comment"><span class="avatar">${userInitials(comment.user)}</span><div><b>${html(comment.user)}</b><div style="margin-top:4px">${html(comment.text)}</div><div class="small muted" style="margin-top:4px">${html(comment.time)}</div></div></div>`).join('') || '<div class="empty">No comments yet.</div>'}
        <div class="section-head" style="margin-top:22px"><h3>History & Change Log</h3></div><div class="task-history">${historyHtml || '<div class="empty">No recorded changes yet.</div>'}</div>
        ${canEdit ? '' : '<div class="source-note" style="margin-top:18px"><b>Limited Task Access</b><div class="small muted" style="margin-top:4px">Everyone assigned to this Job can view its Tasks. Full updates remain limited to the assignee or an Administrator; authorised Job managers can still change the due date.</div></div>'}
        <div class="actions" style="margin-top:18px">${(canEdit || canChangeDue) ? '<button class="secondary" id="saveTaskDraft">Draft</button>' : ''}${canEdit ? '<button class="primary" id="saveTaskUpdate" style="flex:1">Save</button>' : '<button class="ghost" data-close style="flex:1">Close</button>'}</div></div>`;

        openOverlay();
        $('#detailDrawer').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));

        const openLinkedJob = () => {
            closeAll();
            setTimeout(() => {
                if (job) void openJob(job.id);
                else if (task.jobDbId) flowtrackNavigateTo(`/flowtrack/jobs/${task.jobDbId}`);
            }, 30);
        };
        $$('[data-task-job-link]').forEach((button) => button.addEventListener('click', openLinkedJob));

        $('#taskActionMenuButton')?.addEventListener('click', (event) => {
            event.stopPropagation();
            const menu = $('#taskActionMenu');
            menu?.classList.toggle('hidden');
            $('#taskActionMenuButton')?.setAttribute('aria-expanded', menu?.classList.contains('hidden') ? 'false' : 'true');
        });
        const uploadSelectedTaskDocument = async () => {
            const fileInput = $('#taskDocumentFile');
            const file = fileInput?.files?.[0];
            if (!file) return null;
            if (!documentRequirement || !task.jobDbId) {
                throw new Error('The exact document requirement could not be resolved.');
            }
            if (file.size > 20 * 1024 * 1024) {
                throw new Error('The document must not be larger than 20 MB.');
            }

            const selectedDocumentCategoryId = genericTaskAttachment
                ? Number($('#taskDocumentCategory')?.value || 0)
                : Number(documentRequirement.categoryId || 0);
            if (!selectedDocumentCategoryId) {
                $('#taskDocumentCategory')?.focus();
                throw new Error('Choose the document type before saving.');
            }

            const formData = new FormData();
            formData.append('job_id', String(task.jobDbId));
            formData.append('workflow_phase_id', String(documentRequirement.phaseId));
            if (documentRequirement.taskPackItemId) {
                formData.append('task_pack_item_id', String(documentRequirement.taskPackItemId));
            }
            formData.append('document_category_id', String(selectedDocumentCategoryId));
            if (documentRequirement.taskId) formData.append('task_id', String(documentRequirement.taskId));
            const existingDocument = taskDocuments[0];
            if (existingDocument?.dbId) formData.append('document_id', String(existingDocument.dbId));
            formData.append('file', file);
            const notes = $('#taskDocumentNotes')?.value?.trim();
            if (notes) formData.append('notes', notes);

            const result = await api('/flowtrack/template-api/documents', { method: 'POST', body: formData });
            const documentIndex = docs.findIndex((item) => Number(item.dbId) === Number(result.document?.dbId));
            if (result.document && documentIndex >= 0) docs.splice(documentIndex, 1, result.document);
            else if (result.document) docs.unshift(result.document);

            if (result.job) {
                const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
            }

            return result;
        };

        const syncProgressPreview = () => {
            const status = taskStatusRecord($('#taskUpdateStatus').value);
            const nextProgress = taskProgressForStatus(status);
            const completed = taskStatusMatches(status, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
            $('#progressValue').textContent = `${nextProgress}%`;
            $('#taskProgressFill').style.width = `${nextProgress}%`;
            $('#taskProgressFill').classList.toggle('complete', nextProgress >= 100);
            const attentionInput = $('#taskAttention');
            if (completed) {
                attentionInput.checked = false;
                attentionInput.disabled = true;
            } else {
                attentionInput.disabled = !canEdit;
            }
        };
        $('#taskUpdateStatus')?.addEventListener('change', syncProgressPreview);
        syncProgressPreview();

        const selectedFilePreviewUrl = { value: null };
        const renderSelectedTaskDocumentPreview = (file, state = 'ready') => {
            const existingPreview = $('#taskDocumentSelectionPreview');
            if (selectedFilePreviewUrl.value) {
                URL.revokeObjectURL(selectedFilePreviewUrl.value);
                selectedFilePreviewUrl.value = null;
            }
            if (!file) {
                existingPreview?.remove();
                return;
            }

            const safeSize = formatTaskFileSize(file.size);
            const isImage = String(file.type || '').startsWith('image/');
            const previewUrl = isImage ? URL.createObjectURL(file) : '';
            if (previewUrl) selectedFilePreviewUrl.value = previewUrl;
            const statusText = state === 'uploading'
                ? 'Uploading on Save…'
                : 'Selected in browser cache. It will be stored on the server only after Save.';
            const progressWidth = state === 'uploading' ? 70 : 100;
            const previewHtml = `<div id="taskDocumentSelectionPreview" class="task-document-selected-preview" aria-live="polite">
                ${previewUrl ? `<img src="${previewUrl}" alt="Selected file preview">` : '<div class="doc-icon" style="flex:0 0 auto">FILE</div>'}
                <div class="task-document-file-copy"><b class="task-document-file-name">${html(file.name)}</b><div class="small muted">${safeSize} · ${html(statusText)}</div><div class="task-upload-progress"><span style="width:${progressWidth}%"></span></div></div>
            </div>`;
            if (existingPreview) existingPreview.outerHTML = previewHtml;
            else {
                const previewAnchor = $('[data-task-upload-preview-anchor]') || $('#taskDocumentFile');
                previewAnchor?.insertAdjacentHTML('afterend', previewHtml);
            }
        };
        const taskDocumentFileInput = $('#taskDocumentFile');
        const taskDropzone = $('[data-task-upload-dropzone]');
        $('[data-task-upload-browse]')?.addEventListener('click', () => taskDocumentFileInput?.click());
        $('[data-task-choose-documents]')?.addEventListener('click', () => showToast('Choose an existing document from the Job Documents tab after saving this Task.'));
        taskDropzone?.addEventListener('dragover', (event) => {
            event.preventDefault();
            taskDropzone.classList.add('is-dragging');
        });
        taskDropzone?.addEventListener('dragleave', () => taskDropzone.classList.remove('is-dragging'));
        taskDropzone?.addEventListener('drop', (event) => {
            event.preventDefault();
            taskDropzone.classList.remove('is-dragging');
            const file = event.dataTransfer?.files?.[0];
            if (!file || !taskDocumentFileInput) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            taskDocumentFileInput.files = transfer.files;
            taskDocumentFileInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
        taskDocumentFileInput?.addEventListener('change', (event) => {
            renderSelectedTaskDocumentPreview(event.target.files?.[0] || null);
        });

        const mentionedIds = new Set();
        const commentBox = $('#taskComment');
        const mentionMenu = $('#taskMentionMenu');
        bindFlowtrackMentionInput(commentBox, mentionMenu, mentionableUsers, mentionedIds);

        $('[data-delete-task]')?.addEventListener('click', async () => {
            $('#taskActionMenu')?.classList.add('hidden');
            if (!confirm(`Delete task ${task.id}?`)) return;
            try {
                const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'DELETE' });
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                flowtrackRemoveRecord(tasks, result.deletedTask || task);
                closeAll(); renderPage(currentPage); updateBadges(); showToast('Task deleted successfully.');
            } catch (error) { showToast(error.message); }
        });
        applyActionPermissions();

        const collectActiveMentionIds = (comment) => flowtrackToArray(new Set(
            flowtrackToArray(mentionedIds).concat(
                flowtrackToArray(mentionableUsers)
                    .filter((user) => String(comment || '').toLowerCase().includes(`@${String(user.name || '').toLowerCase()}`))
                    .map((user) => Number(user.id)),
            ),
        )).filter((userId) => Number.isFinite(Number(userId)));

        $('#saveTaskDraft')?.addEventListener('click', async () => {
            const button = $('#saveTaskDraft');
            const comment = $('#taskComment')?.value?.trim() || '';
            const selectedFile = $('#taskDocumentFile')?.files?.[0] || null;
            const draftStatus = selectedStatus || taskStatusRecord(task.statusId || task.statusCode || task.status) || taskStatusRecord(task.status) || taskStatusRecord('READY');
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const result = await persistTask(task, draftStatus, {
                    title: $('#taskUpdateTitle')?.value?.trim() || task.title,
                    description: $('#taskUpdateDescription')?.value?.trim() || null,
                    assignee_id: $('#taskUpdateAssignee')?.value ? Number($('#taskUpdateAssignee').value) : null,
                    priority_id: $('#taskUpdatePriority')?.value ? Number($('#taskUpdatePriority').value) : null,
                    due_date: $('#taskUpdateDueDate')?.value || null,
                    attention_required: taskIsCompleted(task) ? false : Boolean($('#taskAttention')?.checked),
                    blocker_reason: task.blockerReason || null,
                    comment,
                    mentioned_user_ids: collectActiveMentionIds(comment),
                });
                updateTaskAndJob(result);
                renderPage(currentPage);
                updateBadges();
                showToast(selectedFile ? 'Draft saved. The selected file will upload only after Save.' : 'Draft saved.');
                openTask(result.task?.id || task.id);
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Draft';
            }
        });

        $('#saveTaskUpdate')?.addEventListener('click', async () => {
            const status = taskStatusRecord($('#taskUpdateStatus').value);
            const comment = $('#taskComment').value.trim();
            const completed = taskStatusMatches(status, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
            const attention = completed ? false : $('#taskAttention').checked;
            const isBlocked = taskStatusMatches(status, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);
            if ((isBlocked || attention) && !comment && !task.blockerReason) {
                showToast('Please add a short reason for the blocker or attention flag.');
                $('#taskComment').focus();
                return;
            }

            const selectedFile = $('#taskDocumentFile')?.files?.[0] || null;
            if (selectedFile && selectedFile.size > 20 * 1024 * 1024) {
                showToast('The document must not be larger than 20 MB.');
                $('#taskDocumentFile')?.focus();
                return;
            }
            if (selectedFile && genericTaskAttachment && !Number($('#taskDocumentCategory')?.value || 0)) {
                showToast('Choose the document type before saving.');
                $('#taskDocumentCategory')?.focus();
                return;
            }

            const activeMentionIds = collectActiveMentionIds(comment);
            const button = $('#saveTaskUpdate');
            button.disabled = true;
            button.textContent = selectedFile ? 'Saving & Uploading…' : 'Saving…';
            try {
                let uploadResult = null;
                if (selectedFile) {
                    renderSelectedTaskDocumentPreview(selectedFile, 'uploading');
                    uploadResult = await uploadSelectedTaskDocument();
                }

                const completedStatus = taskStatusRecord('CMP') || taskStatusRecord('Completed') || taskStatusRecord('Complete') || taskStatusRecord('Done');
                const statusToSave = selectedFile && documentRequirement && !genericTaskAttachment && completedStatus
                    ? completedStatus
                    : status;
                const finalCompleted = taskStatusMatches(statusToSave, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
                const taskResult = await persistTask(task, statusToSave, {
                    title: $('#taskUpdateTitle')?.value?.trim() || task.title,
                    description: $('#taskUpdateDescription')?.value?.trim() || null,
                    assignee_id: $('#taskUpdateAssignee').value ? Number($('#taskUpdateAssignee').value) : null,
                    priority_id: $('#taskUpdatePriority')?.value ? Number($('#taskUpdatePriority').value) : null,
                    due_date: $('#taskUpdateDueDate').value || null,
                    attention_required: finalCompleted ? false : attention,
                    blocker_reason: (!finalCompleted && (isBlocked || attention)) ? (comment || task.blockerReason) : null,
                    comment,
                    mentioned_user_ids: activeMentionIds,
                });

                renderPage(currentPage);
                updateBadges();
                showToast(uploadResult?.message || (selectedFile
                    ? 'Task update and attachment saved successfully.'
                    : 'Task update saved. Progress and history were updated.'));
                const updatedTask = (uploadResult?.tasks || []).find((item) => Number(item.dbId) === Number(task.dbId));
                openTask(updatedTask?.id || taskResult.task?.id || task.id);
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Save';
            }
        });
    });

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

    registerFlowtrackExtension('renderKanban', 'renderKanban-stage-1', function () {        const area = $('#kanbanArea');
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
            const columns = flowtrackToArray(configuredStatuses).concat(flowtrackToArray(legacyStatuses));
            if (!columns.length) columns.push({ id: null, name: 'No task statuses configured', code: null, configured: false });

            const tasksForColumn = (column) => tasks.filter((task) => column.id !== null
                ? Number(task.statusId) === Number(column.id)
                : !task.statusId && normalizedStatusValue(task.status || 'Not Started') === normalizedStatusValue(column.name));

            area.innerHTML = `<div class="kanban" style="grid-template-columns:repeat(${Math.max(1, columns.length)},minmax(230px,1fr))">${columns.map((column) => {
                const columnTasks = tasksForColumn(column);
                const dropValue = column.configured ? String(column.id) : '';
                return `<div class="kanban-col"><div class="kanban-head"><span>${html(column.name)}${column.configured ? '' : ' · Legacy'}</span><span class="count">${columnTasks.length}</span></div><div class="kanban-list" ${dropValue ? `data-drop-status="${html(dropValue)}"` : 'data-readonly-status="true"'}>${columnTasks.map((task) => `<article class="kanban-card" draggable="${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? 'true' : 'false'}" data-task="${task.id}" data-task-updatable="${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? '1' : '0'}"><span class="badge ${task.priority === 'Critical' ? 'b-red' : task.priority === 'High' ? 'b-amber' : 'b-gray'}">${html(task.priority)}</span><div class="kanban-card-title">${html(task.title)}</div><div class="kanban-job"><button type="button" class="kanban-job-link" data-board-job-link="${html(task.job)}">${html(task.job)}</button> · ${html(task.phase)}</div><div class="kanban-meta"><span class="avatar" style="width:25px;height:25px;font-size:9px">${userInitials(task.assignee)}</span><span class="due ${dueClass(task.due)} small">${formatDate(task.due)}</span></div><div class="progress" style="margin-top:9px"><span class="${Number(task.progress) >= 100 ? 'complete' : ''}" style="width:${Math.max(0, Math.min(100, Number(task.progress) || 0))}%"></span></div></article>`).join('')}</div></div>`;
            }).join('')}</div>`;
        } else {
            const activeWorkflows = (workflowTemplates || []).filter((workflow) => workflow.active !== false);
            const selectedWorkflow = activeWorkflows.find((workflow) => Number(workflow.id) === Number(boardWorkflowId))
                || activeWorkflows[0]
                || workflowTemplates[0];
            if (selectedWorkflow) boardWorkflowId = selectedWorkflow.id;

            // Board columns are workflow-wide. Job-specific excluded phases are checked when a card is dropped.
            const phases = (selectedWorkflow?.phases || [])
                .filter((phase) => phase.active !== false)
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
    });



    let boardSearchValue = '';
    let boardQuickFilter = 'all';
    let boardFilters = { job: '', client: '', assignee: '', status: '', due: '', priority: '' };
    let myWorkSearchValue = '';
    let myWorkQuickFilter = 'all';
    let myWorkFilters = { job: '', client: '', status: '', priority: '', due: '' };
    const collapsedBoardJobs = new Set();
    const expandedBoardJobCards = new Set();
    let jobListFilters = { owner: '', delivery: '', invoice: '', more: '', saved: 'all' };
    const selectedBulkJobIds = new Set();

    const operationsBoardStyles = document.createElement('style');
    operationsBoardStyles.textContent = `
        .ops-board-page .page-head{margin-bottom:16px}.ops-board-tabs{display:flex;gap:24px;border-bottom:1px solid var(--line);margin-bottom:16px}.ops-board-tabs .tab-btn{padding:11px 0 12px;font-weight:800;color:#536276;border:0;background:transparent;border-bottom:3px solid transparent}.ops-board-tabs .tab-btn.active{color:var(--blue);border-color:var(--blue)}.ops-board-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.ops-toolbar{display:grid;grid-template-columns:minmax(220px,260px) minmax(280px,1fr) repeat(5,minmax(130px,1fr)) minmax(96px,110px);gap:12px;align-items:stretch;margin-bottom:14px}.ops-view-box,.ops-search,.ops-filter,.ops-clear,.ops-group,.ops-view-icon{height:48px;border:1px solid var(--line);border-radius:10px;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.02)}.ops-view-box{display:flex;align-items:center;gap:13px;padding:7px 12px;text-align:left}.ops-view-icon-box{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;background:#f7fafc;color:#42536b}.ops-view-title{font-weight:800;font-size:13px}.ops-view-sub{font-size:11px;color:var(--muted);margin-top:2px}.ops-search{display:flex;align-items:center;gap:8px;padding:0 12px}.ops-search svg{width:18px;color:#8a98aa}.ops-search input{border:0;outline:0;background:transparent;width:100%;font-size:13px}.ops-filter{padding:0 12px;font-weight:750;font-size:12px;color:#29364a;min-width:0}.ops-clear{font-weight:750;color:#5b687c}.ops-quick-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:4px 0 12px}.ops-quick-left{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.ops-quick-label{font-weight:750;color:#39485d;margin-right:4px}.ops-chip{height:38px;border:1px solid var(--line);border-radius:10px;background:#fff;padding:0 15px;font-size:12px;font-weight:800;color:#526276;display:inline-flex;align-items:center;gap:9px}.ops-chip.active{border-color:#9dbaff;background:#edf4ff;color:#215ed5;box-shadow:0 0 0 2px rgba(37,99,235,.05)}.ops-chip.danger{background:#fff6f5;color:#a63b36}.ops-chip.warn{background:#fff9ed;color:#9a5b10}.ops-chip .count,.ops-count{background:#eef2f7;border-radius:999px;padding:2px 8px;font-size:11px;color:inherit}.ops-group-row{display:flex;align-items:center;gap:10px}.ops-group{display:flex;align-items:center;gap:7px;padding:0 13px;font-weight:750;font-size:12px}.ops-view-icon{width:54px;display:grid;place-items:center}.ops-status-summary{min-height:48px;border:1px solid var(--line);background:#fff;border-radius:11px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;color:#526276;font-size:12px}.ops-status-summary b{color:#172235}.ops-board-canvas{overflow:auto;border:1px solid var(--line);border-radius:14px;background:#fff;box-shadow:0 2px 8px rgba(19,39,67,.025)}.ops-status-grid{display:grid;grid-template-columns:repeat(5,minmax(250px,1fr));min-width:1260px}.ops-status-head{min-height:46px;background:#fbfcff;border-bottom:1px solid var(--line);border-right:1px solid var(--line);display:flex;align-items:center;justify-content:center;gap:9px;font-size:12px;font-weight:850;text-transform:uppercase;color:#29364a}.ops-status-head:last-child{border-right:0}.ops-status-head .warn-mark{color:#e0a008;font-size:15px}.ops-job-group{min-width:1260px;border-bottom:1px solid var(--line)}.ops-job-group:last-child{border-bottom:0}.ops-job-row-head{height:62px;display:flex;align-items:center;justify-content:space-between;gap:12px;background:#fff;padding:0 18px;border-bottom:1px solid var(--line)}.ops-job-left{display:flex;align-items:center;gap:12px;min-width:0}.ops-collapse{border:0;background:transparent;width:26px;height:26px;border-radius:8px;font-size:18px;line-height:1}.ops-collapse:hover{background:#f1f5f9}.ops-job-title{font-weight:850;color:#172235}.ops-job-title button{border:0;background:transparent;padding:0;font:inherit;font-weight:850;color:#172235;cursor:pointer}.ops-client-pill{background:#f3f6fa;color:#5f6f83;border-radius:999px;padding:4px 10px;font-size:11px;font-weight:700}.ops-job-right{display:flex;align-items:center;gap:18px;color:#4f5e72;font-size:12px;white-space:nowrap}.ops-job-kebab{border:0;background:transparent;font-size:20px;color:#18253b;padding:5px 8px;border-radius:8px}.ops-job-kebab:hover{background:#f1f5f9}.ops-task-row{display:grid;grid-template-columns:repeat(5,minmax(250px,1fr))}.ops-task-cell{min-height:156px;padding:14px 10px;border-right:1px solid var(--line);background:#fff}.ops-task-cell:last-child{border-right:0}.ops-task-cell.drop-hover{background:#f8fbff}.ops-job-empty{min-height:86px;display:flex;align-items:center;justify-content:center;color:#9aa6b5;font-size:12px}.ops-task-card{position:relative;border:1px solid #dfe6ef;background:#fff;border-radius:12px;padding:14px;margin:0 0 10px;box-shadow:0 2px 7px rgba(15,35,65,.04);cursor:pointer;transition:.18s}.ops-task-card:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(15,35,65,.08);border-color:#cfd9e7}.ops-task-card.dragging{opacity:.48}.ops-task-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}.ops-priority{border-radius:999px;padding:6px 10px;font-size:12px;font-weight:850}.ops-priority.high{background:#fff1d8;color:#a35405}.ops-priority.critical{background:#ffe9e8;color:#bb3029}.ops-priority.medium{background:#eef2f6;color:#526276}.ops-priority.low{background:#edf7ef;color:#137333}.ops-task-alerts{display:flex;align-items:center;gap:6px}.ops-overdue{background:#ffe9e8;color:#ba2f28;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:850}.ops-task-menu{border:0;background:transparent;color:#5d6a7c;font-size:24px;line-height:1;padding:0 2px;border-radius:7px}.ops-task-menu:hover{background:#f1f5f9}.ops-card-title{font-weight:900;font-size:16px;line-height:1.22;margin:0 0 10px;color:#101a2e}.ops-card-meta{font-size:13px;line-height:1.45;color:#5a687b}.ops-card-meta .job-link{border:0;background:transparent;color:#1565c0;font:inherit;font-weight:800;padding:0;cursor:pointer}.ops-card-phase{font-size:13px;color:#5f6e82;margin-top:3px}.ops-waiting-box{display:flex;align-items:center;gap:12px;margin:12px 0 0;padding:10px 12px;border:1px solid #f3daa4;background:#fffaf0;border-radius:10px;color:#111827}.ops-wait-icon{width:34px;height:34px;border-radius:50%;background:#ffecc4;display:grid;place-items:center;font-size:18px;flex:0 0 auto}.ops-wait-title{font-weight:850}.ops-wait-date{color:#5d6a7c;margin-top:2px}.ops-card-sep{height:1px;background:var(--line);margin:13px 0}.ops-assignee-row,.ops-card-footer{display:flex;align-items:center;justify-content:space-between;gap:10px}.ops-assignee{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:750;min-width:0}.ops-assignee .avatar{width:34px;height:34px;font-size:12px}.ops-due{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:800;color:#111827}.ops-due.overdue{color:#dc2626}.ops-card-footer{font-size:13px;color:#536276}.ops-card-stats{display:flex;align-items:center;gap:16px}.ops-stat{display:inline-flex;align-items:center;gap:6px}.ops-updated{white-space:nowrap;color:#69778a}.ops-phase-kanban{display:grid;gap:12px;overflow:auto;grid-template-columns:repeat(var(--phase-cols,6),minmax(260px,1fr));padding-bottom:8px}.ops-phase-column{min-height:500px;background:#f1f5f9;border:1px solid var(--line);border-radius:14px;padding:12px}.ops-phase-column .kanban-list{min-height:420px}.ops-phase-card{border:1px solid #dfe6ef;border-radius:13px;background:#fff;padding:14px;margin-bottom:11px;box-shadow:0 2px 7px rgba(15,35,65,.04);cursor:pointer}.ops-phase-card.dragging{opacity:.48}.ops-phase-card h4{margin:10px 0 8px;font-size:15px;line-height:1.25}.ops-phase-meta{font-size:12px;color:#5b6b80;line-height:1.5}.ops-phase-card-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:13px}.ops-progress{height:9px;border-radius:999px;background:#e8edf4;overflow:hidden;margin-top:10px}.ops-progress span{display:block;height:100%;background:#2563eb;border-radius:999px}.ops-progress span.complete{background:#16a34a}.ops-board-empty{padding:32px;text-align:center;color:#78869a}.ops-board-mobile-note{display:none;color:var(--muted);font-size:12px;margin:-6px 0 10px}@media(max-width:1320px){.ops-toolbar{grid-template-columns:minmax(190px,230px) minmax(240px,1fr) repeat(3,minmax(120px,1fr)) minmax(90px,100px)}.ops-toolbar .ops-filter:nth-of-type(n+4){display:none}}@media(max-width:820px){.ops-toolbar{grid-template-columns:1fr}.ops-quick-row{align-items:flex-start}.ops-group-row{width:100%;justify-content:flex-start}.ops-board-mobile-note{display:block}.ops-board-tabs{gap:18px}.ops-status-summary{align-items:flex-start;flex-direction:column}.ops-job-row-head{height:auto;align-items:flex-start;flex-direction:column;padding:14px}.ops-job-right{width:100%;justify-content:space-between;gap:8px}}
    `;
    document.head.appendChild(operationsBoardStyles);

    const myWorkBoardStyles = document.createElement('style');
    myWorkBoardStyles.textContent = `
        .my-work-board .page-head{margin-bottom:14px}.my-work-board .metrics{margin-bottom:16px}.my-work-toolbar{grid-template-columns:minmax(280px,2fr) repeat(4,minmax(150px,1fr)) 120px}.my-work-board .ops-status-summary{margin-top:0}.my-work-board .ops-board-canvas{max-height:none}.my-work-owner-note{display:inline-flex;align-items:center;gap:8px;margin-top:7px;color:#5c6a7e;font-size:12px}.my-work-owner-note .avatar{width:24px;height:24px;font-size:10px}@media(max-width:1180px){.my-work-toolbar{grid-template-columns:1fr 1fr}.my-work-toolbar .ops-search{grid-column:1/-1}}@media(max-width:720px){.my-work-toolbar{grid-template-columns:1fr}}
    `;
    document.head.appendChild(myWorkBoardStyles);
    const metricClickableStyles = document.createElement('style');
    metricClickableStyles.textContent = `
        #page-dashboard .metric[data-metric],#page-mywork .metric[data-metric]{cursor:pointer;transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}
        #page-dashboard .metric[data-metric]:hover,#page-mywork .metric[data-metric]:hover,#page-dashboard .metric[data-metric]:focus-visible,#page-mywork .metric[data-metric]:focus-visible{transform:translateY(-1px);border-color:#b7ccff;box-shadow:0 12px 26px rgba(37,99,235,.10);outline:none}
    `;
    document.head.appendChild(metricClickableStyles);

    const jobBulkActionStyles = document.createElement('style');
    jobBulkActionStyles.textContent = `
        .jobs-v2-bulkbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 10px;padding:8px 10px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:11px;color:#172235;font-size:12px}
        .jobs-v2-bulkbar.hidden{display:none}
        .jobs-v2-bulkbar strong{font-weight:900}.jobs-v2-bulk-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
        .jobs-v2-bulk-actions button{height:32px;border-radius:8px;border:1px solid #dbe3ef;background:#fff;padding:0 10px;font-size:12px;font-weight:750;cursor:pointer;line-height:1}
        .jobs-v2-bulk-actions .danger-btn{border:0;background:#fee2e2;color:#b91c1c;padding:0 10px}.jobs-v2-bulk-actions .primary{border:0;color:#fff;background:#2563eb;padding:0 10px}
        .jobs-v2-row-selected{background:#f8fbff!important}.jobs-v2-table input[type=checkbox]{width:16px;height:16px;accent-color:#2563eb;cursor:pointer}
        @media(max-width:760px){.jobs-v2-bulkbar{align-items:flex-start;flex-direction:column}.jobs-v2-bulk-actions{width:100%}.jobs-v2-bulk-actions button{flex:1}}
    `;
    document.head.appendChild(jobBulkActionStyles);

    const jobBoardRedesignStyles = document.createElement('style');
    jobBoardRedesignStyles.textContent = `
        .ops-board-page .page-head{margin-bottom:14px}.ops-board-tabs{gap:22px;margin-bottom:14px}.ops-board-tabs .tab-btn{font-size:13px}.ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(330px,1fr));gap:14px;align-items:start}.ops-phase-column{background:#edf3fb;border:1px solid #dce5f0;border-radius:18px;padding:16px;min-height:520px}.ops-phase-column>.kanban-head{border:0;background:transparent;padding:0 0 14px;margin:0;display:flex;align-items:center;justify-content:space-between;text-transform:uppercase;letter-spacing:.04em;font-size:13px;font-weight:900;color:#0f172a}.ops-phase-column>.kanban-head .count{width:38px;height:32px;display:inline-grid;place-items:center;border-radius:10px;background:#fff;border:1px solid #e4ebf4;color:#0f172a;font-size:14px}.ops-phase-column .kanban-list{min-height:440px}.ops-job-card{position:relative;border:1px solid #d7e1ec!important;border-left:5px solid #22c55e!important;border-radius:16px!important;background:#fff!important;padding:18px!important;margin-bottom:14px!important;box-shadow:0 8px 22px rgba(15,35,65,.08)!important;color:#0f172a;cursor:pointer;transition:.18s}.ops-job-card:hover{transform:translateY(-1px);box-shadow:0 14px 30px rgba(15,35,65,.11)!important}.ops-job-card.health-risk,.ops-job-card.health-delayed{border-left-color:#ef4444!important}.ops-job-card.health-blocked{border-left-color:#7c3aed!important}.ops-job-card.health-waiting{border-left-color:#d97706!important}.ops-job-card.health-completed{border-left-color:#16a34a!important}.ops-job-card-top{display:flex;align-items:center;gap:12px;margin-bottom:14px}.ops-health-pill{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;border:1px solid #bbf7d0;background:#f0fdf4;color:#15803d;font-weight:850;font-size:13px;line-height:1}.ops-health-pill:before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor}.ops-health-pill.risk,.ops-health-pill.delayed{background:#fff1f2;border-color:#fecdd3;color:#be123c}.ops-health-pill.blocked{background:#f5f3ff;border-color:#ddd6fe;color:#6d28d9}.ops-health-pill.waiting{background:#fffbeb;border-color:#fde68a;color:#b45309}.ops-health-pill.completed{background:#ecfdf5;border-color:#bbf7d0;color:#15803d}.ops-phase-age{display:inline-flex;align-items:center;gap:6px;color:#526174;font-weight:700}.ops-job-card-toggle{margin-left:auto;border:0;background:transparent;color:#3f4d60;border-radius:9px;font-size:22px;line-height:1;padding:4px 8px;letter-spacing:2px}.ops-job-card-toggle:hover{background:#f1f5f9}.ops-job-card h4{margin:0 0 10px!important;font-size:22px!important;line-height:1.18!important;font-weight:900!important;letter-spacing:-.02em}.ops-job-card .ops-phase-meta{font-size:15px;line-height:1.45;color:#56657a}.ops-job-card .kanban-job-link{color:#1d6ed6;font-weight:850;text-decoration:none}.ops-product-row{display:flex;align-items:center;gap:10px;color:#334155;font-weight:700;margin:12px 0 18px;font-size:14px}.ops-product-row .cube{font-size:21px;color:#475569}.ops-job-progress-head{display:flex;align-items:center;justify-content:space-between;font-weight:850;margin-bottom:8px;font-size:14px}.ops-job-card .ops-progress{height:9px;margin:0 0 16px}.ops-job-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}.ops-job-metric{height:42px;border:1px solid #dbe3ef;border-radius:12px;background:#f8fafc;display:flex;align-items:center;justify-content:center;gap:8px;font-size:14px;font-weight:850;color:#172235}.ops-job-metric.warn{background:#fffaf0;border-color:#f7dfa8;color:#a35a05}.ops-job-metric.ok{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}.ops-next-action,.ops-phase-tasks-box{border:1px solid #cfe0f5;background:#eff6ff;border-radius:14px;margin-bottom:16px;overflow:hidden}.ops-next-action{padding:14px 16px}.ops-card-section-label{font-size:13px;letter-spacing:.06em;color:#1d6ed6;font-weight:850;text-transform:uppercase;margin-bottom:5px}.ops-next-title{font-size:19px;font-weight:900;margin-bottom:10px}.ops-next-meta{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:14px}.ops-next-meta .avatar{width:34px;height:34px;font-size:12px}.ops-next-due{display:flex;align-items:center;gap:7px;color:#dc2626;font-weight:850}.ops-phase-tasks-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 16px;border-bottom:1px solid #cfe0f5;background:#f8fbff}.ops-phase-tasks-head span:first-child{font-size:13px;letter-spacing:.06em;color:#1d6ed6;font-weight:900}.ops-phase-tasks-head b{font-size:13px}.ops-phase-task-row{display:grid;grid-template-columns:auto 1fr auto auto;gap:10px;align-items:center;padding:12px 16px;border-bottom:1px solid #dce9f8;background:#fff;cursor:pointer}.ops-phase-task-row:last-child{border-bottom:0}.ops-phase-task-row:hover{background:#f8fbff}.ops-task-next-pill{background:#eff6ff;color:#1d4ed8;border-radius:10px;padding:5px 9px;font-size:12px;font-weight:850}.ops-phase-task-title{font-weight:850;line-height:1.25}.ops-phase-task-person{display:flex;align-items:center;gap:8px;color:#334155;margin-top:6px;font-size:13px}.ops-phase-task-person .avatar{width:30px;height:30px;font-size:11px}.ops-task-status-pill{border:1px solid #bbf7d0;background:#f0fdf4;color:#15803d;border-radius:999px;padding:5px 11px;font-weight:800;font-size:12px}.ops-task-status-pill.done{background:#ecfdf5;color:#15803d}.ops-task-status-pill.waiting{background:#fffbeb;color:#b45309;border-color:#fde68a}.ops-task-status-pill.review{background:#fff1f2;color:#be123c;border-color:#fecdd3}.ops-task-date{font-weight:850;white-space:nowrap}.ops-team-row{display:flex;align-items:center;gap:11px;color:#475569;margin:13px 0 15px;font-size:14px}.ops-team-avatars{display:flex;align-items:center}.ops-team-avatars .avatar{width:34px;height:34px;font-size:12px;margin-left:-7px;border:2px solid #fff}.ops-team-avatars .avatar:first-child{margin-left:0}.ops-team-more{width:34px;height:34px;border-radius:50%;background:#e5eaf1;color:#334155;display:grid;place-items:center;font-weight:850;border:2px solid #fff;margin-left:-7px}.ops-lead{margin-left:2px;color:#64748b}.ops-job-bottom{display:grid;grid-template-columns:1fr 1fr;gap:14px;border-top:1px solid #dce3ed;padding-top:14px}.ops-bottom-item{display:flex;align-items:center;gap:12px;min-width:0}.ops-bottom-icon{font-size:24px;color:#475569}.ops-bottom-label{color:#64748b;font-size:13px}.ops-bottom-value{font-weight:900;font-size:15px;margin-top:2px}.ops-job-card-updated{color:#64748b;margin-top:13px;font-size:13px}.jobs-v2 .page-head{margin-bottom:12px}.jobs-v2-stats{font-size:12px;font-weight:800;color:#475569;margin-top:5px}.jobs-v2 .ft-top-actions,.jobs-v2-actions{display:flex;gap:10px;align-items:center}.jobs-v2-saved{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.jobs-v2-chip{border:1px solid #dbe3ef;background:#fff;border-radius:11px;padding:9px 13px;font-size:12px;font-weight:850;color:#334155}.jobs-v2-chip.active{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}.jobs-v2-chip.danger{color:#b91c1c}.jobs-v2-chip.warn{color:#a35a05}.jobs-v2-toolbar{display:grid;grid-template-columns:minmax(260px,1fr) repeat(7,minmax(105px,auto)) auto;gap:10px;align-items:center;padding:12px;margin-bottom:12px}.jobs-v2-search{height:42px;border:1px solid #dbe3ef;border-radius:10px;padding:0 13px;background:#fff}.jobs-v2-toolbar select,.jobs-v2-toolbar button{height:42px;border-radius:10px;border:1px solid #dbe3ef;background:#fff;padding:0 12px;font-weight:750;font-size:12px}.jobs-v2-table{overflow:auto}.jobs-v2-table .data-table{min-width:1320px}.jobs-v2-table .data-table th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;background:#f8fafc}.jobs-v2-table .data-table td{padding:11px 10px;font-size:12px}.jobs-v2-job-code{border:0;background:transparent;color:#1d6ed6;font-weight:900;padding:0;cursor:pointer}.jobs-v2-order{color:#64748b;margin-top:2px}.jobs-v2-client{font-weight:850}.jobs-v2-brief{color:#64748b;margin-top:2px;max-width:210px}.jobs-v2-product{font-weight:750}.jobs-v2-phase{display:inline-flex;border-radius:999px;padding:4px 8px;background:#eef2ff;color:#3154b8;font-weight:850;font-size:11px}.jobs-v2-next{font-weight:850}.jobs-v2-due-note{color:#dc2626;font-weight:800;margin-top:3px;font-size:11px}.jobs-v2-owner{display:flex;align-items:center;gap:8px;white-space:nowrap}.jobs-v2-owner .avatar{width:28px;height:28px;font-size:10px}.jobs-v2-progress{display:flex;align-items:center;gap:8px;min-width:112px}.jobs-v2-progress .progress{width:70px;height:6px}.jobs-v2-invoice{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:850}.jobs-v2-invoice.blue{background:#eaf1ff;color:#1d4ed8}.jobs-v2-invoice.green{background:#eaf8ef;color:#15803d}.jobs-v2-invoice.amber{background:#fff5df;color:#b45309}.jobs-v2-invoice.red{background:#ffedeb;color:#c2413a}.jobs-v2-menu{border:0!important;background:transparent!important;font-size:20px!important;line-height:1!important;padding:4px 6px!important}.jobs-v2-empty{padding:22px;text-align:center;color:#64748b}.jobs-v2-date.overdue{color:#dc2626;font-weight:850}@media(max-width:1100px){.jobs-v2-toolbar{grid-template-columns:1fr 1fr}.ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(310px,1fr))}.ops-job-card h4{font-size:19px!important}.ops-job-metrics{grid-template-columns:1fr}}@media(max-width:720px){.jobs-v2-toolbar{grid-template-columns:1fr}.ops-job-bottom{grid-template-columns:1fr}.ops-phase-column{padding:12px}.ops-job-card{padding:15px!important}}
    `;
    document.head.appendChild(jobBoardRedesignStyles);


    const compactOperationsBoardStyles = document.createElement('style');
    compactOperationsBoardStyles.textContent = `
        /* Compact, lightweight Operations Board polish */
        .ops-board-page .page-head{margin-bottom:10px!important}.ops-board-tabs{gap:18px;margin-bottom:12px!important}.ops-board-tabs .tab-btn{padding:8px 0 10px!important;font-size:12px!important;font-weight:750!important}.ops-board-actions{gap:8px!important}.ops-board-actions .ghost,.ops-board-actions .primary{height:36px!important;padding:0 12px!important;border-radius:9px!important;font-size:12px!important}.ops-toolbar{grid-template-columns:minmax(190px,230px) minmax(240px,1fr) repeat(5,minmax(120px,1fr)) minmax(92px,100px)!important;gap:8px!important;margin-bottom:10px!important}.ops-view-box,.ops-search,.ops-filter,.ops-clear,.ops-group,.ops-view-icon{height:38px!important;border-radius:9px!important;box-shadow:none!important}.ops-view-box{gap:9px!important;padding:6px 10px!important}.ops-view-icon-box{width:27px!important;height:27px!important;border-radius:7px!important}.ops-view-title{font-size:12px!important;font-weight:750!important}.ops-view-sub{font-size:10px!important}.ops-search input{font-size:12px!important}.ops-filter,.ops-clear,.ops-group{padding:0 9px!important;font-size:11px!important;font-weight:700!important}.ops-view-icon{width:42px!important}.ops-quick-row{margin:2px 0 10px!important;gap:8px!important}.ops-quick-left{gap:7px!important}.ops-quick-label{font-size:12px!important;font-weight:750!important}.ops-chip{height:32px!important;border-radius:9px!important;padding:0 11px!important;font-size:11px!important;font-weight:750!important;gap:7px!important}.ops-chip .count,.ops-count{padding:1px 7px!important;font-size:10px!important}.ops-status-summary{min-height:38px!important;border-radius:10px!important;padding:9px 13px!important;margin-bottom:10px!important;font-size:12px!important;box-shadow:none!important}.ops-board-canvas{border-radius:12px!important;box-shadow:none!important}.ops-status-grid,.ops-job-group{min-width:1120px!important}.ops-status-grid,.ops-task-row{grid-template-columns:repeat(5,minmax(220px,1fr))!important}.ops-status-head{min-height:38px!important;font-size:11px!important;font-weight:800!important;background:#fbfcff!important}.ops-job-row-head{height:48px!important;padding:0 14px!important}.ops-job-left{gap:9px!important}.ops-collapse{width:22px!important;height:22px!important;font-size:15px!important}.ops-job-title{font-size:13px!important;font-weight:800!important}.ops-client-pill{padding:3px 8px!important;font-size:10px!important;background:#f1f5f9!important}.ops-job-right{gap:12px!important;font-size:11px!important}.ops-job-kebab{font-size:18px!important;padding:3px 6px!important}.ops-task-cell{min-height:118px!important;padding:10px 8px!important;background:#fff!important}.ops-job-empty{min-height:62px!important;font-size:11px!important;color:#94a3b8!important}.ops-task-card{border-radius:10px!important;padding:10px!important;margin-bottom:8px!important;box-shadow:none!important;border-color:#e2e8f0!important}.ops-task-card:hover{transform:none!important;box-shadow:0 6px 18px rgba(15,35,65,.07)!important;border-color:#cbd5e1!important}.ops-task-card-top{margin-bottom:7px!important}.ops-priority,.ops-overdue{padding:4px 8px!important;font-size:11px!important;font-weight:750!important}.ops-task-menu{font-size:19px!important;padding:0 2px!important}.ops-card-title{font-size:14px!important;line-height:1.22!important;margin-bottom:7px!important;font-weight:850!important}.ops-card-meta{font-size:12px!important;line-height:1.35!important}.ops-card-meta .job-link{font-weight:800!important}.ops-card-phase{font-size:12px!important;margin-top:2px!important}.ops-waiting-box{gap:9px!important;margin-top:9px!important;padding:8px 9px!important;border-radius:9px!important}.ops-wait-icon{width:28px!important;height:28px!important;font-size:14px!important}.ops-wait-title{font-size:12px!important;line-height:1.22!important}.ops-wait-date{font-size:11px!important}.ops-card-sep{margin:9px 0!important}.ops-assignee,.ops-due{font-size:12px!important;gap:7px!important}.ops-assignee .avatar{width:28px!important;height:28px!important;font-size:10px!important}.ops-card-footer{font-size:11px!important}.ops-card-stats{gap:10px!important}.ops-updated{font-size:11px!important}.ops-task-card .avatar{box-shadow:none!important}
        .ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(270px,1fr))!important;gap:10px!important;padding-bottom:6px!important}.ops-phase-column{background:#f7f9fc!important;border-color:#e2e8f0!important;border-radius:14px!important;padding:10px!important;min-height:420px!important}.ops-phase-column>.kanban-head{padding:0 1px 10px!important;font-size:12px!important;letter-spacing:.035em!important}.ops-phase-column>.kanban-head .count{width:30px!important;height:27px!important;border-radius:8px!important;font-size:12px!important}.ops-phase-column .kanban-list{min-height:340px!important}.ops-job-card{padding:12px!important;margin-bottom:10px!important;border-radius:12px!important;border-left-width:4px!important;box-shadow:none!important}.ops-job-card:hover{transform:none!important;box-shadow:0 8px 20px rgba(15,35,65,.07)!important}.ops-job-card-top{gap:8px!important;margin-bottom:9px!important}.ops-health-pill{padding:5px 9px!important;font-size:11px!important;font-weight:750!important;gap:6px!important}.ops-health-pill:before{width:7px!important;height:7px!important}.ops-phase-age{font-size:11px!important;font-weight:650!important;gap:4px!important}.ops-job-card-toggle{font-size:18px!important;padding:2px 5px!important;letter-spacing:2px!important}.ops-job-card h4{font-size:17px!important;line-height:1.18!important;margin:0 0 7px!important;font-weight:850!important;letter-spacing:-.01em!important}.ops-job-card .ops-phase-meta{font-size:12px!important;line-height:1.35!important}.ops-product-row{gap:7px!important;margin:8px 0 12px!important;font-size:12px!important;font-weight:650!important}.ops-product-row .cube{font-size:16px!important}.ops-job-progress-head{font-size:12px!important;margin-bottom:5px!important;font-weight:800!important}.ops-job-card .ops-progress{height:6px!important;margin:0 0 11px!important}.ops-job-metrics{gap:6px!important;margin-bottom:10px!important}.ops-job-metric{height:31px!important;border-radius:9px!important;font-size:12px!important;font-weight:750!important;gap:5px!important}.ops-next-action,.ops-phase-tasks-box{border-radius:10px!important;margin-bottom:11px!important;background:#f3f8ff!important}.ops-next-action{padding:10px 11px!important}.ops-card-section-label{font-size:11px!important;letter-spacing:.055em!important;margin-bottom:4px!important;font-weight:800!important}.ops-next-title{font-size:15px!important;line-height:1.18!important;margin-bottom:8px!important;font-weight:850!important}.ops-next-meta{gap:8px!important;font-size:12px!important}.ops-next-meta .avatar{width:28px!important;height:28px!important;font-size:10px!important}.ops-next-due{gap:5px!important;font-weight:800!important}.ops-phase-tasks-head{padding:9px 11px!important}.ops-phase-tasks-head span:first-child{font-size:11px!important;font-weight:850!important}.ops-phase-tasks-head b{font-size:11px!important;font-weight:750!important}.ops-phase-task-row{grid-template-columns:auto 1fr auto!important;gap:7px!important;padding:9px 11px!important}.ops-phase-task-row .ops-task-date{grid-column:3!important}.ops-task-next-pill{padding:3px 7px!important;border-radius:8px!important;font-size:10px!important}.ops-phase-task-title{font-size:12px!important;font-weight:800!important}.ops-phase-task-person{gap:6px!important;margin-top:4px!important;font-size:11px!important}.ops-phase-task-person .avatar{width:24px!important;height:24px!important;font-size:9px!important}.ops-task-status-pill{padding:3px 8px!important;font-size:10px!important;font-weight:750!important}.ops-task-date{font-size:11px!important;font-weight:800!important}.ops-team-row{gap:8px!important;margin:9px 0 11px!important;font-size:12px!important}.ops-team-avatars .avatar,.ops-team-more{width:27px!important;height:27px!important;font-size:10px!important;margin-left:-6px!important}.ops-team-avatars .avatar:first-child{margin-left:0!important}.ops-lead{font-size:11px!important;line-height:1.2!important}.ops-job-bottom{gap:10px!important;padding-top:10px!important}.ops-bottom-item{gap:8px!important}.ops-bottom-icon{font-size:17px!important}.ops-bottom-label{font-size:11px!important}.ops-bottom-value{font-size:12px!important;font-weight:850!important;margin-top:1px!important;line-height:1.15!important}.ops-job-card-updated{margin-top:9px!important;font-size:11px!important}.jobs-v2 .page-head{margin-bottom:9px!important}.jobs-v2-stats{font-size:11px!important}.jobs-v2-saved{gap:6px!important;margin:10px 0!important}.jobs-v2-chip{border-radius:9px!important;padding:7px 10px!important;font-size:11px!important;font-weight:750!important}.jobs-v2-toolbar{gap:7px!important;padding:9px!important;margin-bottom:10px!important}.jobs-v2-search,.jobs-v2-toolbar select,.jobs-v2-toolbar button{height:36px!important;border-radius:9px!important;font-size:11px!important}.jobs-v2-table .data-table{min-width:1180px!important}.jobs-v2-table .data-table th{font-size:9px!important}.jobs-v2-table .data-table td{padding:8px 8px!important;font-size:11px!important}.jobs-v2-owner .avatar{width:24px!important;height:24px!important;font-size:9px!important}.jobs-v2-progress .progress{height:5px!important}.jobs-v2-invoice,.jobs-v2-phase{font-size:10px!important;padding:3px 7px!important}.jobs-v2-menu{font-size:18px!important;padding:2px 5px!important}@media(max-width:1320px){.ops-toolbar{grid-template-columns:minmax(190px,230px) minmax(220px,1fr) repeat(3,minmax(92px,auto))!important}.ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(260px,1fr))!important}}@media(max-width:720px){.ops-task-row,.ops-status-grid{min-width:980px!important;grid-template-columns:repeat(5,minmax(196px,1fr))!important}.ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(245px,1fr))!important}.ops-job-card{padding:11px!important}.ops-job-metrics{grid-template-columns:repeat(3,1fr)!important}.ops-job-bottom{grid-template-columns:1fr 1fr!important}}
    `;
    document.head.appendChild(compactOperationsBoardStyles);

    const denseWorkflowBoardStyles = document.createElement('style');
    denseWorkflowBoardStyles.textContent = `
        /* Final compact board pass: lighter typography, dynamic columns, less visual weight. */
        .ops-board-page{--ops-card-radius:10px;--ops-soft:#f7f9fc;--ops-text:#172033;--ops-muted:#64748b;font-size:12px!important}.ops-board-page .page-head{margin-bottom:8px!important}.ops-board-page .page-head h1{font-size:21px!important;font-weight:750!important;letter-spacing:-.02em!important}.ops-board-page .page-head p{font-size:12px!important;margin-top:2px!important}.ops-board-actions{gap:7px!important}.ops-board-actions .ghost,.ops-board-actions .primary{height:34px!important;padding:0 12px!important;border-radius:9px!important;font-size:12px!important;font-weight:650!important}.ops-board-tabs{gap:18px!important;margin-bottom:9px!important}.ops-board-tabs .tab-btn{padding:7px 0 8px!important;font-size:12px!important;font-weight:650!important;border-bottom-width:2px!important}.ops-toolbar{grid-template-columns:minmax(170px,210px) minmax(230px,1fr) repeat(4,minmax(94px,auto)) 74px!important;gap:7px!important;margin-bottom:8px!important}.ops-view-box,.ops-search,.ops-filter,.ops-clear,.ops-group,.ops-view-icon{height:34px!important;border-radius:9px!important;box-shadow:none!important}.ops-view-box{padding:5px 9px!important;gap:8px!important}.ops-view-icon-box{width:24px!important;height:24px!important;border-radius:7px!important;font-size:12px!important}.ops-view-title{font-size:11px!important;font-weight:650!important}.ops-view-sub{display:none!important}.ops-search{padding:0 10px!important}.ops-search input,.ops-filter{font-size:11px!important;font-weight:500!important}.ops-filter{padding:0 8px!important}.ops-clear{font-size:11px!important;font-weight:650!important}.ops-toolbar .ops-filter[data-board-filter="priority"]{display:none!important}.ops-quick-row{gap:8px!important;margin:0 0 8px!important}.ops-quick-left{gap:7px!important}.ops-quick-label{font-size:12px!important;font-weight:650!important}.ops-chip{height:32px!important;padding:0 10px!important;border-radius:9px!important;font-size:11px!important;font-weight:650!important;gap:6px!important}.ops-count,.ops-chip .count{padding:1px 6px!important;font-size:10px!important;font-weight:650!important}.ops-group-row{gap:7px!important}.ops-group{height:34px!important;padding:0 10px!important;font-size:11px!important;font-weight:650!important}.ops-view-icon{width:38px!important}.ops-status-summary{min-height:34px!important;padding:7px 11px!important;margin-bottom:8px!important;border-radius:9px!important;font-size:11px!important}.ops-board-canvas{border-radius:10px!important}.ops-board-canvas .ops-status-grid,.ops-board-canvas .ops-task-row{grid-template-columns:repeat(var(--task-status-cols,5),minmax(184px,1fr))!important;min-width:var(--task-board-min,940px)!important}.ops-board-canvas .ops-job-group{min-width:var(--task-board-min,940px)!important}.ops-status-head{min-height:32px!important;font-size:10.5px!important;font-weight:650!important;letter-spacing:.015em!important;text-transform:uppercase!important}.ops-status-head .count{font-size:10px!important;padding:1px 6px!important}.ops-status-head .warn-mark{font-size:12px!important}.ops-job-row-head{height:42px!important;padding:0 12px!important}.ops-job-title,.ops-job-title button{font-size:12px!important;font-weight:650!important}.ops-client-pill{padding:2px 7px!important;font-size:10px!important;font-weight:600!important}.ops-job-right{font-size:11px!important;gap:10px!important}.ops-task-cell{min-height:90px!important;padding:8px 7px!important}.ops-task-card{border-radius:9px!important;padding:9px 9px 8px!important;margin-bottom:7px!important}.ops-task-card-top{margin-bottom:5px!important}.ops-priority,.ops-overdue{padding:3px 7px!important;font-size:10.5px!important;font-weight:650!important}.ops-task-menu{font-size:17px!important}.ops-card-title{font-size:13px!important;line-height:1.2!important;font-weight:700!important;margin-bottom:5px!important;letter-spacing:-.005em!important}.ops-card-meta,.ops-card-phase{font-size:11.5px!important;line-height:1.3!important}.ops-card-meta .job-link{font-weight:650!important}.ops-waiting-box{padding:7px 8px!important;margin-top:7px!important;gap:7px!important;border-radius:8px!important}.ops-wait-icon{width:24px!important;height:24px!important;font-size:12px!important}.ops-wait-title{font-size:11.5px!important;font-weight:650!important}.ops-wait-date{font-size:10.5px!important}.ops-card-sep{margin:7px 0!important}.ops-assignee,.ops-due{font-size:11.5px!important;font-weight:650!important}.ops-assignee .avatar{width:24px!important;height:24px!important;font-size:9px!important}.ops-card-footer{font-size:10.5px!important}.ops-card-stats{gap:7px!important}.ops-updated{font-size:10.5px!important}.ops-job-empty{min-height:48px!important;font-size:11px!important}.ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(220px,1fr))!important;gap:8px!important}.ops-phase-column{border-radius:12px!important;padding:8px!important;min-height:340px!important;background:#f7faff!important}.ops-phase-column>.kanban-head{padding:0 0 8px!important;font-size:11px!important;font-weight:700!important;letter-spacing:.025em!important}.ops-phase-column>.kanban-head .count{width:27px!important;height:24px!important;font-size:11px!important;border-radius:8px!important;font-weight:650!important}.ops-phase-column .kanban-list{min-height:280px!important}.ops-job-card{border-radius:10px!important;border-left-width:3px!important;padding:10px!important;margin-bottom:8px!important}.ops-job-card-top{margin-bottom:7px!important}.ops-health-pill{padding:4px 8px!important;font-size:10.5px!important;font-weight:650!important}.ops-phase-age{font-size:10.5px!important;font-weight:550!important}.ops-job-card-toggle{font-size:16px!important;padding:0 4px!important}.ops-job-card h4{font-size:14px!important;line-height:1.18!important;font-weight:700!important;margin-bottom:5px!important;letter-spacing:-.01em!important}.ops-job-card .ops-phase-meta{font-size:11.5px!important;line-height:1.3!important}.ops-product-row{font-size:11.5px!important;font-weight:550!important;margin:6px 0 9px!important}.ops-product-row .cube{font-size:13px!important}.ops-job-progress-head{font-size:11px!important;font-weight:650!important;margin-bottom:4px!important}.ops-job-card .ops-progress{height:5px!important;margin-bottom:8px!important}.ops-job-metrics{gap:5px!important;margin-bottom:8px!important}.ops-job-metric{height:28px!important;font-size:11px!important;font-weight:650!important;border-radius:8px!important}.ops-next-action,.ops-phase-tasks-box{border-radius:9px!important;margin-bottom:8px!important}.ops-next-action{padding:8px!important}.ops-card-section-label{font-size:10px!important;font-weight:650!important;margin-bottom:3px!important}.ops-next-title{font-size:13px!important;line-height:1.18!important;font-weight:700!important;margin-bottom:6px!important}.ops-next-meta{font-size:11px!important;gap:6px!important}.ops-next-meta .avatar{width:24px!important;height:24px!important;font-size:9px!important}.ops-next-due{font-weight:650!important}.ops-phase-tasks-head{padding:7px 8px!important}.ops-phase-tasks-head span:first-child,.ops-phase-tasks-head b{font-size:10.5px!important;font-weight:650!important}.ops-phase-task-row{padding:7px 8px!important;gap:6px!important}.ops-phase-task-title{font-size:11.5px!important;font-weight:650!important}.ops-phase-task-person{font-size:10.5px!important}.ops-phase-task-person .avatar{width:22px!important;height:22px!important;font-size:8.5px!important}.ops-task-next-pill,.ops-task-status-pill{padding:2px 6px!important;font-size:9.5px!important;font-weight:650!important}.ops-task-date{font-size:10.5px!important;font-weight:650!important}.ops-team-row{margin:7px 0 8px!important;gap:6px!important;font-size:11px!important}.ops-team-avatars .avatar,.ops-team-more{width:23px!important;height:23px!important;font-size:8.5px!important;margin-left:-6px!important}.ops-lead{font-size:10.5px!important;line-height:1.15!important}.ops-job-bottom{gap:7px!important;padding-top:8px!important}.ops-bottom-item{gap:6px!important}.ops-bottom-icon{font-size:13px!important}.ops-bottom-label{font-size:10.5px!important}.ops-bottom-value{font-size:11.5px!important;font-weight:650!important}.ops-job-card-updated{font-size:10.5px!important;margin-top:7px!important;color:#64748b!important}@media(max-width:1320px){.ops-toolbar{grid-template-columns:minmax(160px,200px) minmax(220px,1fr) repeat(3,minmax(110px,1fr)) minmax(88px,96px)!important}.ops-toolbar .ops-filter:nth-of-type(n+4){display:none!important}.ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(210px,1fr))!important}}@media(max-width:820px){.ops-toolbar{grid-template-columns:1fr!important}.ops-toolbar .ops-filter{display:block!important}.ops-board-canvas .ops-status-grid,.ops-board-canvas .ops-task-row{grid-template-columns:repeat(var(--task-status-cols,5),minmax(170px,1fr))!important}.ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(205px,1fr))!important}}
    `;
    document.head.appendChild(denseWorkflowBoardStyles);


    const boardScopeCorrectionStyles = document.createElement('style');
    boardScopeCorrectionStyles.textContent = `
        /* Requested correction: compact only the Job Board. Restore Task Board card sizing from Archive 5 while keeping dynamic Task Status columns from Master Data. */
        .ops-board-page.ops-mode-tasks .ops-toolbar{grid-template-columns:minmax(190px,230px) minmax(240px,1fr) repeat(5,minmax(120px,1fr)) minmax(92px,100px)!important;gap:8px!important;margin-bottom:10px!important}
        .ops-board-page.ops-mode-tasks .ops-view-box,.ops-board-page.ops-mode-tasks .ops-search,.ops-board-page.ops-mode-tasks .ops-filter,.ops-board-page.ops-mode-tasks .ops-clear,.ops-board-page.ops-mode-tasks .ops-group,.ops-board-page.ops-mode-tasks .ops-view-icon{height:38px!important;border-radius:9px!important;box-shadow:none!important}
        .ops-board-page.ops-mode-tasks .ops-view-box{gap:9px!important;padding:6px 10px!important}.ops-board-page.ops-mode-tasks .ops-view-icon-box{width:27px!important;height:27px!important;border-radius:7px!important}.ops-board-page.ops-mode-tasks .ops-view-title{font-size:12px!important;font-weight:750!important}.ops-board-page.ops-mode-tasks .ops-view-sub{font-size:10px!important;display:block!important}.ops-board-page.ops-mode-tasks .ops-search input{font-size:12px!important}.ops-board-page.ops-mode-tasks .ops-filter,.ops-board-page.ops-mode-tasks .ops-clear,.ops-board-page.ops-mode-tasks .ops-group{padding:0 9px!important;font-size:11px!important;font-weight:700!important}.ops-board-page.ops-mode-tasks .ops-view-icon{width:42px!important}
        .ops-board-page.ops-mode-tasks .ops-quick-row{margin:2px 0 10px!important;gap:8px!important}.ops-board-page.ops-mode-tasks .ops-quick-left{gap:7px!important}.ops-board-page.ops-mode-tasks .ops-quick-label{font-size:12px!important;font-weight:750!important}.ops-board-page.ops-mode-tasks .ops-chip{height:32px!important;border-radius:9px!important;padding:0 11px!important;font-size:11px!important;font-weight:750!important;gap:7px!important}.ops-board-page.ops-mode-tasks .ops-chip .count,.ops-board-page.ops-mode-tasks .ops-count{padding:1px 7px!important;font-size:10px!important}
        .ops-board-page.ops-mode-tasks .ops-status-summary{min-height:38px!important;border-radius:10px!important;padding:9px 13px!important;margin-bottom:10px!important;font-size:12px!important;box-shadow:none!important}.ops-board-page.ops-mode-tasks .ops-board-canvas{border-radius:12px!important;box-shadow:none!important}.ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid,.ops-board-page.ops-mode-tasks .ops-board-canvas .ops-task-row{grid-template-columns:repeat(var(--task-status-cols,5),minmax(220px,1fr))!important;min-width:max(1120px,var(--task-board-min,1120px))!important}.ops-board-page.ops-mode-tasks .ops-board-canvas .ops-job-group{min-width:max(1120px,var(--task-board-min,1120px))!important}
        .ops-board-page.ops-mode-tasks .ops-status-head{min-height:38px!important;font-size:11px!important;font-weight:800!important;background:#fbfcff!important}.ops-board-page.ops-mode-tasks .ops-job-row-head{height:48px!important;padding:0 14px!important}.ops-board-page.ops-mode-tasks .ops-job-title,.ops-board-page.ops-mode-tasks .ops-job-title button{font-size:13px!important;font-weight:800!important}.ops-board-page.ops-mode-tasks .ops-client-pill{padding:3px 8px!important;font-size:10px!important;background:#f1f5f9!important}.ops-board-page.ops-mode-tasks .ops-job-right{gap:12px!important;font-size:11px!important}.ops-board-page.ops-mode-tasks .ops-job-kebab{font-size:18px!important;padding:3px 6px!important}
        .ops-board-page.ops-mode-tasks .ops-task-cell{min-height:118px!important;padding:10px 8px!important;background:#fff!important}.ops-board-page.ops-mode-tasks .ops-job-empty{min-height:62px!important;font-size:11px!important;color:#94a3b8!important}.ops-board-page.ops-mode-tasks .ops-task-card{border-radius:10px!important;padding:10px!important;margin-bottom:8px!important;box-shadow:none!important;border-color:#e2e8f0!important}.ops-board-page.ops-mode-tasks .ops-task-card:hover{transform:none!important;box-shadow:0 6px 18px rgba(15,35,65,.07)!important;border-color:#cbd5e1!important}.ops-board-page.ops-mode-tasks .ops-task-card-top{margin-bottom:7px!important}.ops-board-page.ops-mode-tasks .ops-priority,.ops-board-page.ops-mode-tasks .ops-overdue{padding:4px 8px!important;font-size:11px!important;font-weight:750!important}.ops-board-page.ops-mode-tasks .ops-task-menu{font-size:19px!important;padding:0 2px!important}.ops-board-page.ops-mode-tasks .ops-card-title{font-size:14px!important;line-height:1.22!important;margin-bottom:7px!important;font-weight:850!important}.ops-board-page.ops-mode-tasks .ops-card-meta{font-size:12px!important;line-height:1.35!important}.ops-board-page.ops-mode-tasks .ops-card-meta .job-link{font-weight:800!important}.ops-board-page.ops-mode-tasks .ops-card-phase{font-size:12px!important;margin-top:2px!important}.ops-board-page.ops-mode-tasks .ops-waiting-box{gap:9px!important;margin-top:9px!important;padding:8px 9px!important;border-radius:9px!important}.ops-board-page.ops-mode-tasks .ops-wait-icon{width:28px!important;height:28px!important;font-size:14px!important}.ops-board-page.ops-mode-tasks .ops-wait-title{font-size:12px!important;line-height:1.22!important;font-weight:850!important}.ops-board-page.ops-mode-tasks .ops-wait-date{font-size:11px!important}.ops-board-page.ops-mode-tasks .ops-card-sep{margin:9px 0!important}.ops-board-page.ops-mode-tasks .ops-assignee,.ops-board-page.ops-mode-tasks .ops-due{font-size:12px!important;gap:7px!important;font-weight:750!important}.ops-board-page.ops-mode-tasks .ops-assignee .avatar{width:28px!important;height:28px!important;font-size:10px!important}.ops-board-page.ops-mode-tasks .ops-card-footer{font-size:11px!important}.ops-board-page.ops-mode-tasks .ops-card-stats{gap:10px!important}.ops-board-page.ops-mode-tasks .ops-updated{font-size:11px!important}.ops-board-page.ops-mode-tasks .ops-task-card .avatar{box-shadow:none!important}
        .ops-board-page.ops-mode-jobs .ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(285px,1fr))!important;gap:10px!important;padding-bottom:6px!important}.ops-board-page.ops-mode-jobs .ops-phase-column{background:#f7f9fc!important;border-color:#e2e8f0!important;border-radius:14px!important;padding:10px!important;min-height:420px!important}.ops-board-page.ops-mode-jobs .ops-phase-column>.kanban-head{padding:0 1px 10px!important;font-size:12px!important;font-weight:650!important;letter-spacing:.03em!important;color:#172033!important}.ops-board-page.ops-mode-jobs .ops-phase-column>.kanban-head .count{width:30px!important;height:27px!important;border-radius:8px!important;font-size:12px!important;font-weight:650!important}.ops-board-page.ops-mode-jobs .ops-phase-column .kanban-list{min-height:340px!important}.ops-board-page.ops-mode-jobs .ops-job-card{padding:11px!important;margin-bottom:9px!important;border-radius:12px!important;border-left-width:4px!important;box-shadow:none!important}.ops-board-page.ops-mode-jobs .ops-job-card:hover{transform:none!important;box-shadow:0 8px 18px rgba(15,35,65,.06)!important}.ops-board-page.ops-mode-jobs .ops-job-card-top{gap:8px!important;margin-bottom:8px!important}.ops-board-page.ops-mode-jobs .ops-health-pill{padding:4px 8px!important;font-size:10.5px!important;font-weight:650!important;gap:6px!important}.ops-board-page.ops-mode-jobs .ops-health-pill:before{width:6px!important;height:6px!important}.ops-board-page.ops-mode-jobs .ops-phase-age{font-size:10.5px!important;font-weight:500!important}.ops-board-page.ops-mode-jobs .ops-job-card-toggle{font-size:17px!important;padding:1px 5px!important;letter-spacing:1.5px!important}.ops-board-page.ops-mode-jobs .ops-job-card h4{font-size:15px!important;line-height:1.2!important;margin:0 0 6px!important;font-weight:650!important;letter-spacing:-.01em!important}.ops-board-page.ops-mode-jobs .ops-job-card .ops-phase-meta{font-size:11.5px!important;line-height:1.35!important}.ops-board-page.ops-mode-jobs .ops-product-row{gap:6px!important;margin:7px 0 10px!important;font-size:11.5px!important;font-weight:550!important}.ops-board-page.ops-mode-jobs .ops-product-row .cube{font-size:14px!important}.ops-board-page.ops-mode-jobs .ops-job-progress-head{font-size:11.5px!important;margin-bottom:5px!important;font-weight:650!important}.ops-board-page.ops-mode-jobs .ops-job-card .ops-progress{height:5px!important;margin:0 0 9px!important}.ops-board-page.ops-mode-jobs .ops-job-metrics{gap:5px!important;margin-bottom:9px!important}.ops-board-page.ops-mode-jobs .ops-job-metric{height:29px!important;border-radius:8px!important;font-size:11px!important;font-weight:600!important;gap:5px!important}.ops-board-page.ops-mode-jobs .ops-next-action,.ops-board-page.ops-mode-jobs .ops-phase-tasks-box{border-radius:9px!important;margin-bottom:9px!important;background:#f3f8ff!important}.ops-board-page.ops-mode-jobs .ops-next-action{padding:9px!important}.ops-board-page.ops-mode-jobs .ops-card-section-label{font-size:10px!important;letter-spacing:.055em!important;margin-bottom:3px!important;font-weight:650!important}.ops-board-page.ops-mode-jobs .ops-next-title{font-size:13.5px!important;line-height:1.2!important;margin-bottom:7px!important;font-weight:650!important}.ops-board-page.ops-mode-jobs .ops-next-meta{gap:7px!important;font-size:11.5px!important}.ops-board-page.ops-mode-jobs .ops-next-meta .avatar{width:25px!important;height:25px!important;font-size:9px!important}.ops-board-page.ops-mode-jobs .ops-next-due{font-weight:650!important}.ops-board-page.ops-mode-jobs .ops-phase-tasks-head{padding:8px 9px!important}.ops-board-page.ops-mode-jobs .ops-phase-tasks-head span:first-child,.ops-board-page.ops-mode-jobs .ops-phase-tasks-head b{font-size:10.5px!important;font-weight:650!important}.ops-board-page.ops-mode-jobs .ops-phase-task-row{padding:8px 9px!important;gap:6px!important}.ops-board-page.ops-mode-jobs .ops-phase-task-title{font-size:11.5px!important;font-weight:650!important}.ops-board-page.ops-mode-jobs .ops-phase-task-person{font-size:10.5px!important}.ops-board-page.ops-mode-jobs .ops-phase-task-person .avatar{width:23px!important;height:23px!important;font-size:8.5px!important}.ops-board-page.ops-mode-jobs .ops-task-next-pill,.ops-board-page.ops-mode-jobs .ops-task-status-pill{padding:2px 6px!important;font-size:9.5px!important;font-weight:600!important}.ops-board-page.ops-mode-jobs .ops-task-date{font-size:10.5px!important;font-weight:650!important}.ops-board-page.ops-mode-jobs .ops-team-row{margin:8px 0 9px!important;gap:7px!important;font-size:11px!important}.ops-board-page.ops-mode-jobs .ops-team-avatars .avatar,.ops-board-page.ops-mode-jobs .ops-team-more{width:24px!important;height:24px!important;font-size:8.5px!important;margin-left:-6px!important}.ops-board-page.ops-mode-jobs .ops-team-avatars .avatar:first-child{margin-left:0!important}.ops-board-page.ops-mode-jobs .ops-lead{font-size:10.5px!important;line-height:1.15!important}.ops-board-page.ops-mode-jobs .ops-job-bottom{gap:8px!important;padding-top:9px!important}.ops-board-page.ops-mode-jobs .ops-bottom-item{gap:6px!important}.ops-board-page.ops-mode-jobs .ops-bottom-icon{font-size:14px!important}.ops-board-page.ops-mode-jobs .ops-bottom-label{font-size:10.5px!important}.ops-board-page.ops-mode-jobs .ops-bottom-value{font-size:11.5px!important;font-weight:650!important;line-height:1.15!important}.ops-board-page.ops-mode-jobs .ops-job-card-updated{margin-top:8px!important;font-size:10.5px!important;color:#64748b!important}
        @media(max-width:1320px){.ops-board-page.ops-mode-tasks .ops-toolbar{grid-template-columns:minmax(190px,230px) minmax(220px,1fr) repeat(3,minmax(92px,auto))!important}.ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid,.ops-board-page.ops-mode-tasks .ops-board-canvas .ops-task-row{grid-template-columns:repeat(var(--task-status-cols,5),minmax(210px,1fr))!important}.ops-board-page.ops-mode-jobs .ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(275px,1fr))!important}}
        @media(max-width:720px){.ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid,.ops-board-page.ops-mode-tasks .ops-board-canvas .ops-task-row{grid-template-columns:repeat(var(--task-status-cols,5),minmax(196px,1fr))!important;min-width:max(980px,var(--task-board-min,980px))!important}.ops-board-page.ops-mode-tasks .ops-board-canvas .ops-job-group{min-width:max(980px,var(--task-board-min,980px))!important}.ops-board-page.ops-mode-jobs .ops-phase-kanban{grid-template-columns:repeat(var(--phase-cols,6),minmax(260px,1fr))!important}.ops-board-page.ops-mode-jobs .ops-job-card{padding:10px!important}.ops-board-page.ops-mode-jobs .ops-job-metrics{grid-template-columns:repeat(3,1fr)!important}.ops-board-page.ops-mode-jobs .ops-job-bottom{grid-template-columns:1fr 1fr!important}}
    `;
    document.head.appendChild(boardScopeCorrectionStyles);

    const myWorkBoardFinalStyles = document.createElement('style');
    myWorkBoardFinalStyles.textContent = `
        .my-work-board.my-work-board .my-work-toolbar{grid-template-columns:minmax(280px,2fr) repeat(4,minmax(150px,1fr)) 120px!important}.my-work-board.my-work-board .my-work-toolbar .ops-filter{display:block!important}.my-work-board.my-work-board .ops-status-summary{margin-bottom:8px!important}.my-work-board.my-work-board .ops-board-canvas .ops-status-grid,.my-work-board.my-work-board .ops-board-canvas .ops-task-row{grid-template-columns:repeat(var(--task-status-cols,5),minmax(184px,1fr))!important;min-width:var(--task-board-min,940px)!important}@media(max-width:1180px){.my-work-board.my-work-board .my-work-toolbar{grid-template-columns:1fr 1fr!important}.my-work-board.my-work-board .my-work-toolbar .ops-search{grid-column:1/-1!important}}@media(max-width:720px){.my-work-board.my-work-board .my-work-toolbar{grid-template-columns:1fr!important}}
    `;
    document.head.appendChild(myWorkBoardFinalStyles);

    const boardFilterCompactFinalStyles = document.createElement('style');
    boardFilterCompactFinalStyles.textContent = `
        .ops-board-page .ops-board-actions .ghost{display:none!important}
        .ops-board-page .ops-toolbar{grid-template-columns:minmax(190px,230px) minmax(240px,1fr) repeat(5,minmax(128px,1fr)) minmax(92px,100px)!important}
        .ops-board-page .ops-toolbar .ops-filter,.ops-board-page .ops-toolbar .ops-clear{width:100%!important;min-width:0!important}
        .jobs-v2-bulkbar{padding:8px 10px!important;border-radius:11px!important;font-size:12px!important;min-height:44px!important}
        .jobs-v2-bulk-actions{gap:7px!important}
        .jobs-v2-bulk-actions button{height:32px!important;min-height:32px!important;padding:0 10px!important;border-radius:8px!important;font-size:12px!important;font-weight:750!important;line-height:1!important}
        @media(max-width:1320px){.ops-board-page .ops-toolbar{grid-template-columns:minmax(160px,200px) minmax(220px,1fr) repeat(3,minmax(120px,1fr)) minmax(88px,96px)!important}.ops-board-page .ops-toolbar .ops-filter:nth-of-type(n+4){display:none!important}}
        @media(max-width:820px){.ops-board-page .ops-toolbar{grid-template-columns:1fr!important}.ops-board-page .ops-toolbar .ops-filter{display:block!important}}
    `;
    document.head.appendChild(boardFilterCompactFinalStyles);


    try { if (typeof boardMode !== 'undefined' && !sessionStorage.getItem('flowtrackBoardModeTouched')) boardMode = 'jobs'; } catch (_) { boardMode = 'jobs'; }

    function boardDateOnly(value) {
        if (!value) return '';
        const raw = String(value);
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
        const parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) return '';
        return localDateKey(parsed);
    }

    function boardShortDate(value) {
        const key = boardDateOnly(value);
        if (!key) return '—';
        return new Date(`${key}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    function boardDaysOverdue(value) {
        const key = boardDateOnly(value);
        if (!key || key >= today) return 0;
        return Math.max(1, Math.round((new Date(`${today}T00:00:00`) - new Date(`${key}T00:00:00`)) / 86400000));
    }

    function boardDaysUntil(value) {
        const key = boardDateOnly(value);
        if (!key) return null;
        return Math.round((new Date(`${key}T00:00:00`) - new Date(`${today}T00:00:00`)) / 86400000);
    }

    function boardJobForTask(task) {
        return jobs.find((job) => String(job.id) === String(task.job) || Number(job.dbId) === Number(task.jobDbId)) || null;
    }

    function boardActiveJobs() {
        return jobs.filter((job) => String(job.status || '').toLowerCase() !== 'completed' && String(job.health || '').toLowerCase() !== 'completed');
    }

    function boardActiveTaskStatuses() {
        return (masterData.taskStatuses || [])
            .filter((status) => String(status.status || 'Active').toLowerCase() === 'active')
            .slice()
            .sort((left, right) => {
                const leftOrder = Number(left.sortOrder ?? left.sort_order ?? left.order ?? left.sequence ?? left.id ?? 0);
                const rightOrder = Number(right.sortOrder ?? right.sort_order ?? right.order ?? right.sequence ?? right.id ?? 0);
                if (leftOrder !== rightOrder) return leftOrder - rightOrder;
                return String(left.name || left.code || '').localeCompare(String(right.name || right.code || ''));
            });
    }

    function boardColumnKeyForStatus(status) {
        if (!status) return 'status-unassigned';
        if (status.id !== undefined && status.id !== null && status.id !== '') return `status-${status.id}`;
        const fallback = String(status.code || status.name || 'unassigned').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'unassigned';
        return `status-${fallback}`;
    }

    function boardTaskStatusBucket(task) {
        const statusRecord = taskStatusRecord(task.statusId || { code: task.statusCode, name: task.status });
        const statusName = normalizedStatusValue(`${statusRecord?.name || ''} ${task.status || ''}`);
        const statusCode = normalizedStatusValue(statusRecord?.code || task.statusCode || '');
        if (['CMP', 'COMPLETED', 'COMPLETE', 'DONE'].includes(statusCode) || /COMPLET|DONE|PAID|APPROVED|DELIVERED/.test(statusName)) return 'done';
        if (['REV', 'REVIEW', 'REVISION'].includes(statusCode) || /REVIEW|REVISION|REJECT|RETURN/.test(statusName)) return 'review';
        if (['BLK', 'BLOCKED', 'ATTN', 'ATTENTION'].includes(statusCode) || /BLOCK|ATTENTION/.test(statusName)) return 'review';
        if (['WC', 'WS', 'WP', 'WIA', 'WAIT', 'WAITING', 'HOLD'].includes(statusCode) || /WAIT|HOLD|CLIENT|SUPPLIER|PAYMENT|EXTERNAL|APPROVAL/.test(statusName)) return 'waiting';
        if (['IP', 'IN_PROGRESS', 'PROGRESS'].includes(statusCode) || /IN PROGRESS|PROGRESS|STARTED|TRANSIT/.test(statusName)) return 'progress';
        return 'ready';
    }

    function boardColumnDefinitions() {
        const statuses = boardActiveTaskStatuses();
        if (statuses.length) {
            return statuses.map((status, index) => ({
                key: boardColumnKeyForStatus(status),
                label: status.name || status.code || `Status ${index + 1}`,
                statusId: status.id ?? null,
                statusRecord: status,
                code: status.code || null,
                bucket: boardTaskStatusBucket({ statusId: status.id, status: status.name, statusCode: status.code }),
                configured: true,
            }));
        }
        return [
            { key: 'ready', label: 'Ready', target: ['READY', 'NOT STARTED', 'NEW'], bucket: 'ready', configured: false },
            { key: 'progress', label: 'In Progress', target: ['IP', 'IN PROGRESS', 'PROGRESS'], bucket: 'progress', configured: false },
            { key: 'waiting', label: 'Waiting', target: ['WS', 'WC', 'WAITING FOR SUPPLIER', 'WAITING FOR CLIENT', 'WAITING'], bucket: 'waiting', configured: false },
            { key: 'review', label: 'Review', target: ['REV', 'REVISION REQUIRED', 'REVIEW', 'BLK', 'BLOCKED'], bucket: 'review', configured: false },
            { key: 'done', label: 'Done', target: ['CMP', 'COMPLETED', 'DONE'], bucket: 'done', configured: false },
        ];
    }

    function boardTaskColumnKey(task) {
        const columns = boardColumnDefinitions();
        const statusId = Number(task.statusId);
        if (Number.isFinite(statusId) && statusId > 0) {
            const byId = columns.find((column) => column.statusId !== undefined && column.statusId !== null && Number(column.statusId) === statusId);
            if (byId) return byId.key;
        }
        const statusRecord = taskStatusRecord(task.statusId || { code: task.statusCode, name: task.status });
        if (statusRecord) {
            const key = boardColumnKeyForStatus(statusRecord);
            if (columns.some((column) => column.key === key)) return key;
        }
        const normalizedName = normalizedStatusValue(task.status || '');
        const normalizedCode = normalizedStatusValue(task.statusCode || '');
        const byNameOrCode = columns.find((column) => normalizedStatusValue(column.label) === normalizedName || normalizedStatusValue(column.code) === normalizedCode || normalizedStatusValue(column.code) === normalizedName);
        if (byNameOrCode) return byNameOrCode.key;
        const bucket = boardTaskStatusBucket(task);
        const fallback = columns.find((column) => column.bucket === bucket) || columns[0];
        return fallback?.key || bucket;
    }

    function boardTargetStatusForColumn(columnKey) {
        const column = boardColumnDefinitions().find((item) => item.key === columnKey);
        if (!column) return null;
        if (column.statusRecord) return column.statusRecord;
        const statuses = boardActiveTaskStatuses();
        for (const wanted of (column.target || [])) {
            const normalizedWanted = normalizedStatusValue(wanted);
            const found = statuses.find((status) => String(status.code || '').toUpperCase() === String(wanted).toUpperCase()
                || normalizedStatusValue(status.name || '') === normalizedWanted
                || normalizedStatusValue(status.code || '') === normalizedWanted);
            if (found) return found;
        }
        return statuses[0] || null;
    }

    function boardPriorityClass(priority) {
        const normalized = String(priority || 'Medium').toLowerCase();
        if (normalized.includes('critical')) return 'critical';
        if (normalized.includes('high')) return 'high';
        if (normalized.includes('low')) return 'low';
        return 'medium';
    }

    function boardWaitingLabel(task) {
        const status = normalizedStatusValue(task.status || '');
        if (status.includes('SUPPLIER')) return 'Supplier costing';
        if (status.includes('CLIENT')) return 'Client approval';
        if (status.includes('PAYMENT')) return 'Client payment';
        if (status.includes('HOLD')) return 'External response';
        return task.blockerReason || task.title || 'External dependency';
    }

    function boardTaskUpdatedText(task) {
        const key = boardDateOnly(task.updatedAt || task.completedAt || task.createdAt);
        if (!key) return 'Updated recently';
        const days = boardDaysUntil(key);
        if (days === 0) return 'Updated today';
        if (days === -1) return 'Updated 1d ago';
        if (days < 0) return `Updated ${Math.abs(days)}d ago`;
        return `Updated ${boardShortDate(key)}`;
    }

    function boardChecklistCount(task) {
        if (task.isDocumentTask) {
            const uploaded = (task.documents || []).length;
            return `${uploaded ? 1 : 0}/1`;
        }
        const checklist = Array.isArray(task.checklist) ? task.checklist : [];
        if (!checklist.length) return '0/0';
        return `${checklist.filter((item) => item.done).length}/${checklist.length}`;
    }

    function boardAttachmentCount(task) {
        const explicit = Array.isArray(task.documents) ? task.documents.length : 0;
        if (explicit) return explicit;
        return docs.filter((doc) => String(doc.job) === String(task.job)).length;
    }

    function boardCommentCount(task) {
        const comments = Array.isArray(task.comments) ? task.comments.length : 0;
        const historyComments = Array.isArray(task.history) ? task.history.filter((entry) => entry.commentAdded).length : 0;
        return comments + historyComments;
    }

    function boardFilteredTasks() {
        let list = tasks.slice();
        const search = boardSearchValue.trim().toLowerCase();
        if (search) {
            list = list.filter((task) => {
                const job = boardJobForTask(task);
                const haystack = [task.id, task.title, task.job, task.phase, task.assignee, task.priority, task.status, job?.title, clientName(job?.client)].join(' ').toLowerCase();
                return haystack.includes(search);
            });
        }
        if (boardFilters.job) list = list.filter((task) => String(task.job) === String(boardFilters.job));
        if (boardFilters.client) list = list.filter((task) => String(boardJobForTask(task)?.client) === String(boardFilters.client));
        if (boardFilters.assignee) list = list.filter((task) => {
            const selected = String(boardFilters.assignee);
            const selectedUser = boardAssigneeFilterUsers().find((user) => String(user.id || '') === selected || String(user.name || '') === selected)
                || users.find((user) => String(user.id || '') === selected || String(user.name || '') === selected);
            const selectedName = selectedUser ? String(selectedUser.name || '') : selected;
            return boardTaskMatchesAssignee(task, selected, selectedName);
        });
        if (boardFilters.status) list = list.filter((task) => boardTaskColumnKey(task) === boardFilters.status || String(task.statusId) === String(boardFilters.status));
        if (boardFilters.priority) list = list.filter((task) => String(task.priorityId || task.priority) === String(boardFilters.priority));
        if (boardFilters.due === 'overdue') list = list.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done');
        if (boardFilters.due === 'today') list = list.filter((task) => boardDateOnly(task.due) === today);
        if (boardFilters.due === 'week') list = list.filter((task) => {
            const days = boardDaysUntil(task.due);
            return days !== null && days >= 0 && days <= 7;
        });
        if (boardFilters.more === 'document') list = list.filter((task) => task.isDocumentTask);
        if (boardFilters.more === 'attention') list = list.filter((task) => task.attention || task.blockerReason);
        if (boardQuickFilter === 'mine') list = list.filter((task) => boardCurrentUserTask(task));
        if (boardQuickFilter === 'overdue') list = list.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done');
        if (boardQuickFilter === 'week') list = list.filter((task) => {
            const days = boardDaysUntil(task.due);
            return days !== null && days >= 0 && days <= 7;
        });
        if (boardQuickFilter === 'blocked') list = list.filter((task) => /BLOCK|ATTENTION|HOLD/.test(normalizedStatusValue(task.status || '')) || task.attention || task.blockerReason);
        if (boardQuickFilter === 'waiting') list = list.filter((task) => boardTaskStatusBucket(task) === 'waiting');
        if (boardQuickFilter === 'unassigned') list = list.filter((task) => !task.assigneeId && (!task.assignee || String(task.assignee).toLowerCase() === 'unassigned'));
        return list;
    }

    function boardFilteredJobsForPhases() {
        let list = boardActiveJobs();
        const search = boardSearchValue.trim().toLowerCase();
        if (search) {
            list = list.filter((job) => [job.id, job.order, job.title, clientName(job.client), job.product, job.coordinator, job.owner, job.health, job.status].join(' ').toLowerCase().includes(search));
        }
        if (boardFilters.job) list = list.filter((job) => String(job.id) === String(boardFilters.job));
        if (boardFilters.client) list = list.filter((job) => String(job.client) === String(boardFilters.client));
        if (boardFilters.assignee) list = list.filter((job) => {
            const selected = String(boardFilters.assignee);
            const selectedUser = boardAssigneeFilterUsers().find((user) => String(user.id || '') === selected || String(user.name || '') === selected)
                || users.find((user) => String(user.id || '') === selected || String(user.name || '') === selected);
            const selectedName = selectedUser ? String(selectedUser.name || '') : selected;
            return boardJobMatchesAssignee(job, selected, selectedName);
        });
        if (boardFilters.priority) list = list.filter((job) => String(job.priorityId || job.priority) === String(boardFilters.priority));
        if (boardFilters.due === 'overdue') list = list.filter((job) => boardDaysOverdue(job.delivery) > 0);
        if (boardFilters.due === 'today') list = list.filter((job) => boardDateOnly(job.delivery) === today);
        if (boardFilters.due === 'week') list = list.filter((job) => {
            const days = boardDaysUntil(job.delivery);
            return days !== null && days >= 0 && days <= 7;
        });
        if (boardFilters.more === 'attention') list = list.filter((job) => job.attention || /RISK|BLOCK|DELAY|ATTENTION/.test(normalizedStatusValue(job.health || '')));
        if (boardQuickFilter === 'mine') list = list.filter((job) => boardCurrentUserJob(job));
        if (boardQuickFilter === 'overdue') list = list.filter((job) => boardDaysOverdue(job.delivery) > 0);
        if (boardQuickFilter === 'week') list = list.filter((job) => {
            const days = boardDaysUntil(job.delivery);
            return days !== null && days >= 0 && days <= 7;
        });
        if (boardQuickFilter === 'blocked') list = list.filter((job) => /BLOCK|RISK|DELAY|ATTENTION/.test(normalizedStatusValue(job.health || '')) || job.attention);
        if (boardQuickFilter === 'waiting') list = list.filter((job) => /WAIT/.test(normalizedStatusValue(job.status || '')));
        if (boardQuickFilter === 'unassigned') list = list.filter((job) => !job.coordinator && !job.owner);
        return list;
    }

    function boardColumnCounts(taskList) {
        return boardColumnDefinitions().reduce((acc, column) => {
            acc[column.key] = taskList.filter((task) => boardTaskColumnKey(task) === column.key).length;
            return acc;
        }, {});
    }

    function boardSelectOptions(items, getValue, getLabel, selected, allLabel) {
        const seen = new Set();
        const options = [`<option value="">${html(allLabel)}</option>`];
        items.forEach((item) => {
            const value = getValue(item);
            if (value === undefined || value === null || value === '' || seen.has(String(value))) return;
            seen.add(String(value));
            options.push(`<option value="${html(value)}" ${String(selected) === String(value) ? 'selected' : ''}>${html(getLabel(item))}</option>`);
        });
        return options.join('');
    }

    function boardCurrentUserOnlyOption() {
        if (!currentUser?.id && !currentUser?.name) return [];
        return [{
            id: currentUser.id || currentUser.name,
            name: currentUser.name || 'Me',
            initials: currentUser.initials || userInitials(currentUser.name || 'Me'),
            role: currentUser.role || '',
            dept: currentUser.department || currentUser.dept || '',
        }];
    }

    function boardAssigneeFilterUsers() {
        const canSeeEveryAssignee = Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin);
        let source = boardCurrentUserOnlyOption();
        if (canSeeEveryAssignee) {
            const derivedFromTasks = flowtrackToArray(tasks).map((task) => {
                const id = flowtrackResolveTaskAssigneeId(task) || task.assigneeId || task.setupAssigneeId || '';
                const name = flowtrackResolveTaskAssigneeName(task, id) || task.assignee || '';
                return { id: id || name, name, initials: userInitials(name) };
            });
            const derivedFromJobs = flowtrackToArray(jobs).flatMap((job) => ([
                { id: job.ownerId || job.owner, name: job.owner, initials: userInitials(job.owner || '') },
                { id: job.coordinatorId || job.coordinator, name: job.coordinator, initials: userInitials(job.coordinator || '') },
            ]));
            source = []
                .concat(flowtrackToArray(boardAssigneeUsers))
                .concat(flowtrackToArray(users))
                .concat(typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : [])
                .concat(derivedFromTasks)
                .concat(derivedFromJobs)
                .concat(boardCurrentUserOnlyOption());
        }
        const seen = new Set();
        return source.filter((user) => {
            const name = String(user?.name || '').trim();
            const key = String(user?.id || name || '').trim();
            if (!name || name.toLowerCase() === 'unassigned' || !key || seen.has(key)) return false;
            seen.add(key);
            return true;
        }).sort((left, right) => {
            if (Number(left.id || 0) === Number(currentUser?.id || 0)) return -1;
            if (Number(right.id || 0) === Number(currentUser?.id || 0)) return 1;
            return String(left.name || '').localeCompare(String(right.name || ''));
        });
    }

    function boardTaskMatchesAssignee(task, selectedId, selectedName) {
        const resolvedId = flowtrackResolveTaskAssigneeId(task);
        const resolvedName = flowtrackResolveTaskAssigneeName(task, resolvedId);
        return (selectedId && String(resolvedId || task.assigneeId || '') === String(selectedId))
            || (selectedName && String(resolvedName || task.assignee || '').trim().toLowerCase() === String(selectedName).trim().toLowerCase());
    }

    function boardJobMatchesAssignee(job, selectedId, selectedName) {
        const normalizedName = String(selectedName || '').trim().toLowerCase();
        const jobOwnerMatches = (selectedId && (String(job.coordinatorId || '') === String(selectedId) || String(job.ownerId || '') === String(selectedId)))
            || (normalizedName && [job.coordinator, job.owner, job.createdBy].some((name) => String(name || '').trim().toLowerCase() === normalizedName));
        if (jobOwnerMatches) return true;
        return tasks.some((task) => String(task.job) === String(job.id) && boardTaskMatchesAssignee(task, selectedId, selectedName));
    }

    function boardCurrentUserTask(task) {
        return boardTaskMatchesAssignee(task, currentUser?.id, currentUser?.name);
    }

    function boardCurrentUserJob(job) {
        return boardJobMatchesAssignee(job, currentUser?.id, currentUser?.name);
    }

    function boardVisibleJobIdsForExpandToggle() {
        if (boardMode === 'tasks') {
            const visibleTasks = boardFilteredTasks();
            const ids = [];
            visibleTasks.forEach((task) => {
                const job = boardJobForTask(task);
                const id = String(job?.id || task.job || '').trim();
                if (id && !ids.includes(id)) ids.push(id);
            });
            return ids;
        }
        return boardFilteredJobsForPhases().map((job) => String(job.id || '').trim()).filter(Boolean);
    }

    function boardAllVisibleJobsExpanded() {
        const ids = boardVisibleJobIdsForExpandToggle();
        if (!ids.length) return false;
        if (boardMode === 'tasks') return ids.every((id) => !collapsedBoardJobs.has(id));
        return ids.every((id) => expandedBoardJobCards.has(id));
    }

    function operationsBoardToolbar(filteredCount, filteredJobCount) {
        const activeJobs = boardActiveJobs();
        const activeTasks = flowtrackVisibleTaskRows(tasks).filter((task) => boardActiveJobs().some((job) => String(job.id) === String(task.job)));
        const jobOptions = boardSelectOptions(activeJobs, (job) => job.id, (job) => `${job.id} · ${clientName(job.client)}`, boardFilters.job, 'Job');
        const clientOptions = boardSelectOptions(clients, (client) => client.id, (client) => client.name, boardFilters.client, 'Client');
        const userOptions = boardSelectOptions(boardAssigneeFilterUsers(), (user) => user.id || user.name, (user) => user.name, boardFilters.assignee, 'Assignee');
        const quickMineLabel = boardMode === 'tasks' ? 'My task' : 'My job';
        const quickCounts = {
            mine: boardMode === 'tasks'
                ? activeTasks.filter((task) => boardCurrentUserTask(task)).length
                : activeJobs.filter((job) => boardCurrentUserJob(job)).length,
            overdue: activeTasks.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done').length,
            week: activeTasks.filter((task) => { const days = boardDaysUntil(task.due); return days !== null && days >= 0 && days <= 7; }).length,
            blocked: activeTasks.filter((task) => /BLOCK|ATTENTION|HOLD/.test(normalizedStatusValue(task.status || '')) || task.attention || task.blockerReason).length,
            waiting: activeTasks.filter((task) => boardTaskStatusBucket(task) === 'waiting').length,
            unassigned: activeTasks.filter((task) => !task.assigneeId && (!task.assignee || String(task.assignee).toLowerCase() === 'unassigned')).length,
        };
        return `<div class="ops-toolbar">
            <button type="button" class="ops-view-box" data-board-view-menu><span class="ops-view-icon-box">▣</span><span><div class="ops-view-title">All active jobs</div><div class="ops-view-sub">${activeJobs.length} jobs · ${activeTasks.length} tasks</div></span><span style="margin-left:auto">⌄</span></button>
            <label class="ops-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg><input id="opsBoardSearch" value="${html(boardSearchValue)}" placeholder="Search job ID, task or client"></label>
            <select class="ops-filter" data-board-filter="job">${jobOptions}</select>
            <select class="ops-filter" data-board-filter="client">${clientOptions}</select>
            <select class="ops-filter" data-board-filter="assignee">${userOptions}</select>
            <select class="ops-filter" data-board-filter="status"><option value="">Status</option>${boardColumnDefinitions().map((column) => `<option value="${column.key}" ${boardFilters.status === column.key ? 'selected' : ''}>${column.label}</option>`).join('')}</select>
            <select class="ops-filter" data-board-filter="due"><option value="" ${!boardFilters.due ? 'selected' : ''}>Due date</option><option value="overdue" ${boardFilters.due === 'overdue' ? 'selected' : ''}>Overdue</option><option value="today" ${boardFilters.due === 'today' ? 'selected' : ''}>Due today</option><option value="week" ${boardFilters.due === 'week' ? 'selected' : ''}>Due this week</option></select>
            <button type="button" class="ops-clear" id="opsBoardClear">Clear</button>
            <button type="button" class="ops-clear ops-expand-toggle" id="opsBoardExpandAll">${boardAllVisibleJobsExpanded() ? 'Minimize all jobs' : 'Expand all jobs'}</button>
        </div>
        <div class="ops-quick-row"><div class="ops-quick-left"><span class="ops-quick-label">Quick filters</span>${[
            ['mine', quickMineLabel, quickCounts.mine, ''],
            ['overdue', 'Overdue', quickCounts.overdue, 'danger'],
            ['week', 'Due this week', quickCounts.week, ''],
            ['blocked', 'Blocked', quickCounts.blocked, 'danger'],
            ['waiting', 'Waiting external', quickCounts.waiting, ''],
            ['unassigned', 'Unassigned', quickCounts.unassigned, 'warn'],
        ].map(([key, label, count, tone]) => `<button type="button" class="ops-chip ${tone} ${boardQuickFilter === key ? 'active' : ''}" data-board-quick="${key}">${label} <span class="ops-count">${count}</span></button>`).join('')}</div></div>
        <div class="ops-status-summary"><span>Showing <b>${filteredCount}</b> ${boardMode === 'tasks' ? 'of ' + flowtrackVisibleTaskRows(tasks).length + ' tasks' : 'Jobs'} across <b>${filteredJobCount}</b> ${filteredJobCount === 1 ? 'job' : 'jobs'}</span></div>`;
    }

    function boardTaskCardHtml(task) {
        const job = boardJobForTask(task);
        const overdueDays = boardTaskStatusBucket(task) !== 'done' ? boardDaysOverdue(task.due) : 0;
        const attachments = boardAttachmentCount(task);
        const comments = boardCommentCount(task);
        const canDrag = task.canUpdate || isFlowAdmin || isFlowSuperAdmin;
        const waiting = boardTaskStatusBucket(task) === 'waiting' || task.blockerReason;
        return `<article class="ops-task-card" draggable="${canDrag ? 'true' : 'false'}" data-task="${html(task.id)}" data-task-updatable="${canDrag ? '1' : '0'}">
            <div class="ops-task-card-top"><span class="ops-priority ${boardPriorityClass(task.priority)}">${html(task.priority || 'Medium')}</span><span class="ops-task-alerts">${overdueDays ? `<span class="ops-overdue">Overdue · ${overdueDays}d</span>` : ''}<span class="ops-board-card-menu-wrap"><button type="button" class="ops-task-menu" data-board-card-menu="${html(task.id)}" aria-label="Task actions" aria-expanded="false">⋮</button><span class="ops-board-card-action-menu hidden">${task.canDelete || isFlowAdmin || isFlowSuperAdmin ? `<button type="button" data-board-delete-task="${html(task.dbId)}" data-board-delete-task-code="${html(task.id)}">Delete Task</button>` : '<span class="ops-menu-disabled">No available actions</span>'}</span></span></span></div>
            <h4 class="ops-card-title">${html(task.title)}</h4>
            <div class="ops-card-meta"><button type="button" class="job-link" data-board-job-link="${html(task.job)}">${html(task.job)}</button> <span>· ${html(clientName(job?.client))}</span><div class="ops-card-phase">${html(task.phase || phaseNameFor(job || {}, job?.phase))}</div></div>
            ${waiting ? `<div class="ops-waiting-box"><span class="ops-wait-icon">🔗</span><span><div class="ops-wait-title">Waiting on: ${html(boardWaitingLabel(task))}</div><div class="ops-wait-date">Since ${boardShortDate(task.updatedAt || task.createdAt || task.due)}</div></span></div>` : ''}
            <div class="ops-card-sep"></div>
            <div class="ops-assignee-row"><div class="ops-assignee"><span class="avatar">${userInitials(task.assignee || 'Unassigned')}</span><span class="truncate">${html(task.assignee || 'Unassigned')}</span></div><div class="ops-due ${overdueDays ? 'overdue' : ''}">▣ ${boardShortDate(task.due)}</div></div>
            <div class="ops-card-sep"></div>
            <div class="ops-card-footer"><div class="ops-card-stats"><span class="ops-stat">☑ ${html(boardChecklistCount(task))}</span><span class="ops-stat">📎 ${attachments}</span><span class="ops-stat">◯ ${comments}</span></div><span class="ops-updated">${html(boardTaskUpdatedText(task))}</span></div>
        </article>`;
    }

    function renderOperationsTaskBoard(taskList) {
        const columns = boardColumnDefinitions();
        const counts = boardColumnCounts(taskList);
        const jobsWithTasks = [];
        const seen = new Set();
        taskList.forEach((task) => {
            const job = boardJobForTask(task);
            const key = String(job?.id || task.job);
            if (!seen.has(key)) {
                seen.add(key);
                jobsWithTasks.push(job || { id: task.job, title: task.jobTitle || task.job, client: null, delivery: task.due, coordinator: task.assignee });
            }
        });
        jobsWithTasks.sort((a, b) => String(a.delivery || '').localeCompare(String(b.delivery || '')) || String(a.id).localeCompare(String(b.id)));
        const header = `<div class="ops-status-grid">${columns.map((column) => `<div class="ops-status-head"><span>${html(column.label)}</span><span class="count">${counts[column.key] || 0}</span>${['progress','review'].includes(column.bucket || column.key) && (counts[column.key] || 0) ? '<span class="warn-mark">⚠</span>' : ''}</div>`).join('')}</div>`;
        const body = jobsWithTasks.map((job) => {
            const jobTasks = taskList.filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId));
            const completedCount = jobTasks.filter((task) => boardTaskStatusBucket(task) === 'done').length;
            const totalCount = flowtrackVisibleTaskRows(tasks).filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId)).length || jobTasks.length;
            const collapsed = collapsedBoardJobs.has(String(job.id));
            return `<section class="ops-job-group" data-board-job-group="${html(job.id)}"><div class="ops-job-row-head"><div class="ops-job-left"><button type="button" class="ops-collapse" data-board-collapse="${html(job.id)}">${collapsed ? '›' : '⌄'}</button><span class="ops-job-title"><button type="button" data-board-job-link="${html(job.id)}">${html(job.id)}</button> · ${html(clientName(job.client))}</span><span class="ops-client-pill">${html(clientName(job.client))}</span></div><div class="ops-job-right"><span>${completedCount} of ${totalCount} tasks</span><span>▣ ${boardShortDate(job.delivery)}</span><button type="button" class="ops-job-kebab" data-board-row-menu>⋮</button></div></div>${collapsed ? '' : `<div class="ops-task-row">${columns.map((column) => {
                const columnTasks = jobTasks.filter((task) => boardTaskColumnKey(task) === column.key);
                return `<div class="ops-task-cell kanban-list" data-drop-column="${column.key}">${columnTasks.map(boardTaskCardHtml).join('') || '<div class="ops-job-empty">No tasks</div>'}</div>`;
            }).join('')}</div>`}</section>`;
        }).join('');
        const minWidth = Math.max(900, columns.length * 190);
        return `<div class="ops-board-mobile-note">Scroll horizontally to see all board columns.</div><div class="ops-board-canvas" style="--task-status-cols:${columns.length};--task-board-min:${minWidth}px">${header}${body || '<div class="ops-board-empty">No task cards match the selected board filters.</div>'}</div>`;
    }

    function boardJobAllTasks(job) {
        return flowtrackVisibleTaskRows(tasks).filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId));
    }

    function boardJobPhaseTasks(job) {
        const allTasks = boardJobAllTasks(job);
        const phaseId = Number(job.phaseId || 0);
        const phaseIndex = Number(job.phase || 0);
        const currentPhaseName = normalizedStatusValue(phaseNameFor(job) || '');
        const currentPhaseShort = normalizedStatusValue(phaseShortFor(job) || '');
        const matched = allTasks.filter((task) => {
            if (phaseId && Number(task.phaseId) === phaseId) return true;
            if (Number.isFinite(phaseIndex) && Number(task.phaseIndex) === phaseIndex) return true;
            const taskPhase = normalizedStatusValue(task.phase || '');
            return Boolean(taskPhase && (taskPhase === currentPhaseName || taskPhase === currentPhaseShort || currentPhaseName.includes(taskPhase) || taskPhase.includes(currentPhaseShort)));
        });
        return matched.length ? matched : allTasks.filter((task) => !taskIsCompleted(task));
    }

    function boardJobHealthTone(job) {
        const normalized = normalizedStatusValue(job.health || job.status || 'On Track');
        if (/BLOCK/.test(normalized)) return 'blocked';
        if (/RISK|ATTENTION/.test(normalized)) return 'risk';
        if (/DELAY|OVERDUE/.test(normalized)) return 'delayed';
        if (/WAIT|HOLD/.test(normalized)) return 'waiting';
        if (/COMPLET|DONE|PAID/.test(normalized)) return 'completed';
        return 'track';
    }

    function boardJobDaysInPhase(job) {
        const key = boardDateOnly(job.phaseStartedAt || job.phase_started_at || job.updatedAt || job.createdAt);
        if (!key) return '—';
        const days = Math.max(0, Math.round((new Date(`${today}T00:00:00`) - new Date(`${key}T00:00:00`)) / 86400000));
        return `${days || 1}d in phase`;
    }

    function boardJobUpdatedText(job) {
        const key = boardDateOnly(job.updatedAt || job.createdAt);
        if (!key) return 'Updated recently';
        const days = boardDaysUntil(key);
        if (days === 0) return `Updated today by ${html(job.coordinator || job.owner || 'FlowTrack')}`;
        if (days === -1) return `Updated 1d ago by ${html(job.coordinator || job.owner || 'FlowTrack')}`;
        if (days < 0) return `Updated ${Math.abs(days)}d ago by ${html(job.coordinator || job.owner || 'FlowTrack')}`;
        return `Updated ${boardShortDate(key)} by ${html(job.coordinator || job.owner || 'FlowTrack')}`;
    }

    function boardJobCommercialLabel(job) {
        if (!canSensitive('invoice_amount')) return 'Restricted';
        const phaseText = normalizedStatusValue(`${phaseNameFor(job)} ${phaseShortFor(job)} ${job.status || ''}`);
        if (Number(job.price || 0) > 0 && /PAID|COMPLETED/.test(phaseText)) return `Paid ${currency(job.price, job.currency)}`;
        if (Number(job.price || 0) > 0 && /INVOICE|PAYMENT|COLLECT/.test(phaseText)) return `Invoice ${currency(job.price, job.currency)}`;
        if (Number(job.price || 0) > 0) return `Draft ${currency(job.price, job.currency)}`;
        return 'Quotation pending';
    }

    function boardPhaseTaskStatusClass(task) {
        const key = boardTaskStatusBucket(task);
        if (key === 'done') return 'done';
        if (key === 'waiting') return 'waiting';
        if (key === 'review') return 'review';
        return 'ready';
    }

    function boardJobTeam(job, phaseTasks) {
        const names = [];
        [job.coordinator, job.owner].concat(flowtrackToArray(phaseTasks).map((task) => task.assignee)).forEach((name) => {
            const clean = String(name || '').trim();
            if (clean && clean.toLowerCase() !== 'unassigned' && !names.includes(clean)) names.push(clean);
        });
        return names.length ? names : ['Unassigned'];
    }

    function boardJobCardHtml(job) {
        const phaseTasks = boardJobPhaseTasks(job);
        const allJobTasks = boardJobAllTasks(job);
        const expanded = expandedBoardJobCards.has(String(job.id));
        const completedPhaseTasks = phaseTasks.filter(taskIsCompleted).length;
        const openPhaseTasks = phaseTasks.filter((task) => !taskIsCompleted(task));
        const nextTask = openPhaseTasks.slice().sort((left, right) => String(left.due || '').localeCompare(String(right.due || '')))[0] || phaseTasks[0] || null;
        const overdueJob = boardDaysOverdue(job.delivery);
        const dueSoon = phaseTasks.filter((task) => {
            const days = boardDaysUntil(task.due);
            return !taskIsCompleted(task) && days !== null && days >= 0 && days <= 7;
        }).length;
        const blockedCount = phaseTasks.filter((task) => taskIsBlocked(task) || /BLOCK|HOLD/.test(normalizedStatusValue(task.status || ''))).length;
        const productCount = Number(job.productCount || (Array.isArray(job.products) ? job.products.length : (job.product ? 1 : 0)) || 0);
        const unitCount = Number(job.qty || (Array.isArray(job.products) ? job.products.reduce((sum, item) => sum + Number(item.qty || item.quantity || 0), 0) : 0) || 0);
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        const tone = boardJobHealthTone(job);
        const team = boardJobTeam(job, phaseTasks);
        const lead = job.coordinator || job.owner || team[0] || 'Unassigned';
        const phaseRows = phaseTasks.slice(0, 5).map((task, index) => `<div class="ops-phase-task-row" data-task="${html(task.id)}">
                <span>${index === 0 && !taskIsCompleted(task) ? '<span class="ops-task-next-pill">NEXT</span>' : ''}</span>
                <div><div class="ops-phase-task-title">${html(task.title)}</div><div class="ops-phase-task-person"><span class="avatar">${userInitials(task.assignee || 'Unassigned')}</span><span>${html(task.assignee || 'Unassigned')}</span></div></div>
                <span class="ops-task-status-pill ${boardPhaseTaskStatusClass(task)}">${html(task.status || 'Ready')}</span>
                <span class="ops-task-date ${boardDaysOverdue(task.due) && !taskIsCompleted(task) ? 'due overdue' : ''}">${boardShortDate(task.due)}</span>
            </div>`).join('');
        const nextHtml = nextTask
            ? `<div class="ops-next-action"><div class="ops-card-section-label">Next Action</div><div class="ops-next-title">${html(nextTask.title || job.next || 'Review current phase')}</div><div class="ops-next-meta"><span class="avatar">${userInitials(nextTask.assignee || lead)}</span><span>${html(nextTask.assignee || lead)}</span><span class="ops-next-due">▣ Due ${boardShortDate(nextTask.due || job.delivery)}</span></div></div>`
            : `<div class="ops-next-action"><div class="ops-card-section-label">Next Action</div><div class="ops-next-title">${html(job.next || 'Review current phase')}</div><div class="ops-next-meta"><span class="avatar">${userInitials(lead)}</span><span>${html(lead)}</span><span class="ops-next-due">▣ Due ${boardShortDate(job.delivery)}</span></div></div>`;
        const phaseTasksHtml = `<div class="ops-phase-tasks-box"><div class="ops-phase-tasks-head"><span>Phase Tasks</span><b>${completedPhaseTasks} of ${phaseTasks.length} complete</b><span>⌃</span></div>${phaseRows || '<div class="ops-job-empty">No current phase tasks</div>'}</div>`;
        return `<article class="ops-phase-card ops-job-card health-${tone}" draggable="true" data-job="${html(job.id)}">
            <div class="ops-job-card-top"><span class="ops-health-pill ${tone}">${html(job.health || 'On Track')}</span><span class="ops-phase-age">◷ ${html(boardJobDaysInPhase(job))}</span><button type="button" class="ops-job-card-toggle" data-board-job-card-toggle="${html(job.id)}" aria-label="${expanded ? 'Show next action' : 'Show phase tasks'}">•••</button></div>
            <h4>${html(job.title)}</h4>
            <div class="ops-phase-meta"><button type="button" class="kanban-job-link" data-board-job-link="${html(job.id)}">${html(job.id)}</button> <span>· ${html(clientName(job.client))}</span></div>
            <div class="ops-product-row"><span class="cube">▧</span><span>${productCount || 1} product${(productCount || 1) === 1 ? '' : 's'} · ${unitCount.toLocaleString()} units</span></div>
            <div class="ops-job-progress-head"><span>Overall progress</span><b>${progress}%</b></div><div class="ops-progress"><span class="${progress >= 100 ? 'complete' : ''}" style="width:${progress}%"></span></div>
            <div class="ops-job-metrics"><div class="ops-job-metric">☷ ${openPhaseTasks.length} open</div><div class="ops-job-metric warn">◷ ${dueSoon} due soon</div><div class="ops-job-metric ok">✓ ${blockedCount} blocked</div></div>
            ${expanded ? phaseTasksHtml : nextHtml}
            <div class="ops-team-row"><span>Team</span><span class="ops-team-avatars">${team.slice(0, 3).map((name) => `<span class="avatar">${userInitials(name)}</span>`).join('')}${team.length > 3 ? `<span class="ops-team-more">+${team.length - 3}</span>` : ''}</span><span class="ops-lead">Lead: ${html(lead)}</span></div>
            <div class="ops-job-bottom"><div class="ops-bottom-item"><span class="ops-bottom-icon">▣</span><span><div class="ops-bottom-label">Delivery</div><div class="ops-bottom-value ${overdueJob ? 'due overdue' : ''}">${boardShortDate(job.delivery)}</div></span></div><div class="ops-bottom-item"><span class="ops-bottom-icon">▤</span><span><div class="ops-bottom-label">Commercial</div><div class="ops-bottom-value">${html(boardJobCommercialLabel(job))}</div></span></div></div>
            <div class="ops-job-card-updated">${boardJobUpdatedText(job)}</div>
        </article>`;
    }

    function renderOperationsJobBoard(jobList) {
        const activeWorkflows = (workflowTemplates || []).filter((workflow) => workflow.active !== false);
        const selectedWorkflow = activeWorkflows.find((workflow) => Number(workflow.id) === Number(boardWorkflowId))
            || activeWorkflows.find((workflow) => String(workflow.id) === String(boardWorkflowId))
            || activeWorkflows[0]
            || workflowTemplates[0];
        if (selectedWorkflow) boardWorkflowId = selectedWorkflow.id;
        const phases = (selectedWorkflow?.phases || []).filter((phase) => phase.active !== false).slice().sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
        const workflowJobs = selectedWorkflow ? jobList.filter((job) => !job.workflow || String(job.workflow) === String(selectedWorkflow.id) || Number(job.workflow) === Number(selectedWorkflow.id)) : jobList;
        const workflowOptions = activeWorkflows.map((workflow) => `<option value="${html(workflow.id)}" ${String(workflow.id) === String(selectedWorkflow?.id) ? 'selected' : ''}>${html(workflow.name)}</option>`).join('');
        const board = `<div class="card" style="padding:12px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"><b>Workflow</b><select id="boardWorkflowSelect" style="min-width:240px">${workflowOptions}</select><span class="small muted">Job cards show current phase progress, next action and expandable phase tasks.</span></div>
        <div class="ops-phase-kanban" style="--phase-cols:${Math.max(1, phases.length)}">${phases.map((phase, phaseIndex) => {
            const phaseId = phase.id ?? phase.phaseId ?? phase.dbId ?? phaseIndex;
            const phaseSequenceIndex = Number(phase.sequence ? phase.sequence - 1 : phaseIndex);
            const phaseJobs = workflowJobs.filter((job) => Number(job.phaseId) === Number(phaseId) || (!job.phaseId && Number(job.phase) === phaseSequenceIndex));
            return `<div class="ops-phase-column"><div class="kanban-head"><span>${html(phase.short || phase.shortName || phase.name)}</span><span class="count">${phaseJobs.length}</span></div><div class="kanban-list" data-drop-phase-id="${html(phaseId)}">${phaseJobs.map(boardJobCardHtml).join('') || '<div class="ops-job-empty">No Jobs</div>'}</div></div>`;
        }).join('') || '<div class="card" style="padding:18px">No active phases are configured for this workflow.</div>'}</div>`;
        setTimeout(() => {
            $('#boardWorkflowSelect')?.addEventListener('change', (event) => { boardWorkflowId = event.target.value; renderKanban(); });
        }, 0);
        return board;
    }

    function jobIsInactive(job) {
        return job?.active === false || ['inactive', 'deactivated', 'archived'].includes(String(job?.status || '').toLowerCase()) || ['inactive', 'deactivated', 'archived'].includes(String(job?.health || '').toLowerCase());
    }

    function jobListActiveJobs() {
        return jobs.filter((job) => !jobIsCompleted(job) && !jobIsInactive(job));
    }

    function jobListHasWaitingClient(job) {
        const jobText = normalizedStatusValue(`${job.health || ''} ${job.status || ''} ${job.next || ''}`);
        if (/WAIT.*CLIENT|CLIENT.*WAIT|CLIENT APPROVAL/.test(jobText)) return true;
        return boardJobAllTasks(job).some((task) => /CLIENT/.test(normalizedStatusValue(`${task.status || ''} ${task.title || ''} ${task.blockerReason || ''}`)) && !taskIsCompleted(task));
    }

    function jobListInvoiceState(job) {
        const phaseText = normalizedStatusValue(`${phaseNameFor(job)} ${phaseShortFor(job)} ${job.status || ''}`);
        if (!canSensitive('invoice_amount')) return { label: 'Restricted', tone: 'blue' };
        if (/PAID|COMPLETED/.test(phaseText)) return { label: 'Paid', tone: 'green' };
        if (boardDaysOverdue(job.delivery) > 0 && /INVOICE|PAYMENT|COLLECT/.test(phaseText)) return { label: `Overdue ${currency(Number(job.price || 0), job.currency)}`, tone: 'red' };
        if (/INVOICE|PAYMENT|COLLECT/.test(phaseText)) return { label: `Pending ${currency(Number(job.price || 0), job.currency)}`, tone: 'amber' };
        if (Number(job.price || 0) > 0) return { label: `Draft ${currency(Number(job.price || 0), job.currency)}`, tone: 'blue' };
        return { label: 'Quotation pending', tone: 'amber' };
    }

    function jobListNextTask(job) {
        const phaseTasks = boardJobPhaseTasks(job).filter((task) => !taskIsCompleted(task));
        return phaseTasks.slice().sort((left, right) => String(left.due || '').localeCompare(String(right.due || '')))[0] || null;
    }

    function jobListFilteredJobs() {
        let list = jobs.slice();
        if (jobListFilters.saved !== 'completed') list = list.filter((job) => !jobIsInactive(job));
        const search = String(jobFilter.search || '').trim().toLowerCase();
        if (search) {
            list = list.filter((job) => [job.id, job.order, job.title, clientName(job.client), job.product, job.coordinator, job.owner, job.next].join(' ').toLowerCase().includes(search));
        }
        if (jobFilter.phase) list = list.filter((job) => String(job.phaseId || job.phase) === String(jobFilter.phase) || String(job.phase) === String(jobFilter.phase));
        if (jobFilter.health) list = list.filter((job) => String(job.health) === String(jobFilter.health) || normalizedStatusValue(job.health) === normalizedStatusValue(jobFilter.health));
        if (jobFilter.client) list = list.filter((job) => String(job.client) === String(jobFilter.client));
        if (jobListFilters.owner) list = list.filter((job) => String(job.coordinatorId || job.ownerId || job.coordinator || job.owner) === String(jobListFilters.owner));
        if (jobListFilters.delivery === 'overdue') list = list.filter((job) => boardDaysOverdue(job.delivery) > 0 && !jobIsCompleted(job));
        if (jobListFilters.delivery === 'week') list = list.filter((job) => { const days = boardDaysUntil(job.delivery); return days !== null && days >= 0 && days <= 7; });
        if (jobListFilters.invoice === 'unpaid') list = list.filter((job) => /PENDING|OVERDUE|INVOICE/.test(normalizedStatusValue(jobListInvoiceState(job).label)));
        if (jobListFilters.more === 'documents') list = list.filter((job) => jobDocumentRequirements(job).some((req) => !req.present));
        if (jobListFilters.saved === 'all') list = list.filter((job) => !jobIsCompleted(job));
        if (jobListFilters.saved === 'attention') list = list.filter((job) => job.attention || !['ON TRACK', 'COMPLETED'].includes(normalizedStatusValue(job.health || '')) || boardJobPhaseTasks(job).some(taskIsBlocked));
        if (jobListFilters.saved === 'week') list = list.filter((job) => { const days = boardDaysUntil(job.delivery); return !jobIsCompleted(job) && days !== null && days >= 0 && days <= 7; });
        if (jobListFilters.saved === 'waiting') list = list.filter(jobListHasWaitingClient);
        if (jobListFilters.saved === 'unpaid') list = list.filter((job) => /PENDING|OVERDUE|INVOICE/.test(normalizedStatusValue(jobListInvoiceState(job).label)));
        if (jobListFilters.saved === 'completed') list = list.filter(jobIsCompleted);
        return list.slice().sort((left, right) => {
            const createdComparison = String(right.createdAt || '').localeCompare(String(left.createdAt || ''));
            if (createdComparison) return createdComparison;
            const idComparison = Number(right.dbId || 0) - Number(left.dbId || 0);
            if (idComparison) return idComparison;
            return String(right.id || '').localeCompare(String(left.id || ''));
        });
    }

    function jobListPhaseOptions() {
        const options = [];
        (workflowTemplates || []).forEach((workflow) => (workflow.phases || []).forEach((phase) => {
            const value = phase.id ?? phase.phaseId ?? '';
            if (value && !options.some((item) => String(item.value) === String(value))) options.push({ value, label: phase.name || phase.short || phase.shortName });
        }));
        if (!options.length && typeof phases !== 'undefined') phases.forEach((label, index) => options.push({ value: index, label }));
        return options.map((phase) => `<option value="${html(phase.value)}" ${String(jobFilter.phase || '') === String(phase.value) ? 'selected' : ''}>${html(phase.label)}</option>`).join('');
    }

    function jobListOwnerOptions() {
        return users.map((user) => `<option value="${html(user.id || user.name)}" ${String(jobListFilters.owner) === String(user.id || user.name) ? 'selected' : ''}>${html(user.name)}</option>`).join('');
    }

    function jobListRow(job) {
        const nextTask = jobListNextTask(job);
        const invoice = jobListInvoiceState(job);
        const productCount = Number(job.productCount || (Array.isArray(job.products) ? job.products.length : (job.product ? 1 : 0)) || 0);
        const unitCount = Number(job.qty || (Array.isArray(job.products) ? job.products.reduce((sum, item) => sum + Number(item.qty || item.quantity || 0), 0) : 0) || 0);
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        const totalPhases = (getWorkflow(Number(job.workflow))?.phases || []).filter((phase) => phase.active !== false).length || 11;
        const phaseNumber = Math.min(totalPhases, Math.max(1, Number(job.phase || 0) + 1));
        const ownerName = job.coordinator || job.owner || 'Unassigned';
        const overdue = boardDaysOverdue(job.delivery) > 0 && !jobIsCompleted(job);
        const checked = selectedBulkJobIds.has(Number(job.dbId)) ? 'checked' : '';
        return `<tr class="${checked ? 'jobs-v2-row-selected' : ''}">
            <td><input type="checkbox" data-job-bulk-checkbox="${html(job.dbId)}" aria-label="Select ${html(job.id)}" ${checked}></td>
            <td><button type="button" class="jobs-v2-job-code" data-board-job-link="${html(job.id)}">${html(job.id)}</button><div class="jobs-v2-order">${html(job.order || 'RFQ-' + String(job.id || '').slice(-5))}</div></td>
            <td><div class="jobs-v2-client">${html(clientName(job.client))}</div><div class="jobs-v2-brief truncate">${html(job.title || '')}</div></td>
            <td><div class="jobs-v2-product">${html(job.product || 'Unspecified product')}</div><div class="muted small">${productCount || 1} product${(productCount || 1) === 1 ? '' : 's'} · ${unitCount.toLocaleString()} units</div></td>
            <td><span class="jobs-v2-phase">${html(phaseShortFor(job))}</span><div class="muted small" style="margin-top:4px">${phaseNumber} of ${totalPhases}</div></td>
            <td><div class="jobs-v2-next">${html(nextTask?.title || job.next || 'Review current phase')}</div><div class="jobs-v2-due-note">${boardDaysOverdue(nextTask?.due || job.delivery) ? 'Overdue' : 'Due'} ${boardShortDate(nextTask?.due || job.delivery)}</div></td>
            <td><div class="jobs-v2-owner"><span class="avatar">${userInitials(ownerName)}</span><span>${html(ownerName)}</span></div></td>
            <td class="jobs-v2-date ${overdue ? 'overdue' : ''}">${boardShortDate(job.delivery)}</td>
            <td><div class="jobs-v2-progress"><div class="progress"><span class="${progress >= 100 ? 'complete' : ''}" style="width:${progress}%"></span></div><span>${progress}%</span></div></td>
            <td><span class="jobs-v2-invoice ${invoice.tone}">${html(invoice.label)}</span></td>
            <td>${healthBadge(job.health, job.healthReason)}</td>
            <td>${html(boardTaskUpdatedText({ updatedAt: job.updatedAt || job.createdAt }).replace('Updated ', ''))}</td>
            <td><button type="button" class="jobs-v2-menu" data-job-list-menu="${html(job.id)}" aria-label="Job actions">•••</button></td>
        </tr>`;
    }

    function selectedVisibleBulkJobs(filteredJobs) {
        const selected = new Set(Array.from(selectedBulkJobIds).map(Number));
        return filteredJobs.filter((job) => selected.has(Number(job.dbId)));
    }

    function jobBulkActionBar(filteredJobs) {
        const selected = selectedVisibleBulkJobs(filteredJobs);
        const count = selected.length;
        if (!count) return '<div class="jobs-v2-bulkbar hidden"></div>';
        const canDeactivate = canFlow('jobs', 'edit_all') || canFlow('jobs', 'override') || canFlow('jobs', 'manage');
        const canDelete = (isFlowAdmin || isFlowSuperAdmin) && canFlow('jobs', 'delete');
        return `<div class="jobs-v2-bulkbar"><div><strong>${count}</strong> selected · bulk action available</div><div class="jobs-v2-bulk-actions"><button type="button" id="jobBulkClear">Clear selection</button>${canDeactivate ? '<button type="button" class="primary" id="jobBulkDeactivate">Deactivate selected</button>' : ''}${canDelete ? '<button type="button" class="danger-btn" id="jobBulkDelete">Delete selected</button>' : ''}</div></div>`;
    }

    async function runJobBulkAction(action, filteredJobs) {
        const selected = selectedVisibleBulkJobs(filteredJobs);
        if (!selected.length) {
            showToast('Select at least one Job first.');
            return;
        }
        const verb = action === 'delete' ? 'delete permanently' : 'deactivate';
        if (!window.confirm(`Are you sure you want to ${verb} ${selected.length} selected Job${selected.length === 1 ? '' : 's'}?`)) return;
        const button = action === 'delete' ? $('#jobBulkDelete') : $('#jobBulkDeactivate');
        const originalText = button?.textContent || '';
        if (button) {
            button.disabled = true;
            button.textContent = action === 'delete' ? 'Deleting…' : 'Deactivating…';
        }
        try {
            const payload = await api('/flowtrack/template-api/jobs/bulk', {
                method: 'PATCH',
                body: { action, job_ids: selected.map((job) => Number(job.dbId)) },
            });
            if (action === 'delete') {
                const deletedDbIds = new Set((payload.deleted || []).map((job) => Number(job.dbId)));
                const deletedCodes = new Set((payload.deleted || []).map((job) => String(job.id)));
                for (let i = jobs.length - 1; i >= 0; i -= 1) if (deletedDbIds.has(Number(jobs[i].dbId)) || deletedCodes.has(String(jobs[i].id))) jobs.splice(i, 1);
                for (let i = tasks.length - 1; i >= 0; i -= 1) if (deletedCodes.has(String(tasks[i].job)) || selected.some((job) => String(job.id) === String(tasks[i].job))) tasks.splice(i, 1);
                for (let i = docs.length - 1; i >= 0; i -= 1) if (deletedCodes.has(String(docs[i].job)) || selected.some((job) => String(job.id) === String(docs[i].job))) docs.splice(i, 1);
                deletedDbIds.forEach((id) => selectedBulkJobIds.delete(id));
            } else {
                (payload.deactivated || []).forEach((updated) => {
                    const job = jobs.find((item) => Number(item.dbId) === Number(updated.dbId) || String(item.id) === String(updated.id));
                    if (job) {
                        job.status = updated.status || 'Inactive';
                        job.health = updated.health || 'Inactive';
                        job.active = false;
                        job.attention = false;
                        job.next = 'Deactivated by bulk action';
                    }
                    selectedBulkJobIds.delete(Number(updated.dbId));
                });
            }
            selected.forEach((job) => selectedBulkJobIds.delete(Number(job.dbId)));
            updateBadges();
            renderJobs();
            showToast(payload.message || `Selected Jobs ${action === 'delete' ? 'deleted' : 'deactivated'} successfully.`);
        } catch (error) {
            showToast(error.message);
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
        }
    }

    registerFlowtrackExtension('renderJobs', 'renderJobs-stage-1', function () {        const activeJobs = jobListActiveJobs();
        const stats = {
            active: activeJobs.length,
            attention: activeJobs.filter((job) => job.attention || !['ON TRACK', 'COMPLETED'].includes(normalizedStatusValue(job.health || ''))).length,
            week: activeJobs.filter((job) => { const days = boardDaysUntil(job.delivery); return days !== null && days >= 0 && days <= 7; }).length,
            waiting: activeJobs.filter(jobListHasWaitingClient).length,
            unpaid: activeJobs.filter((job) => /PENDING|OVERDUE|INVOICE/.test(normalizedStatusValue(jobListInvoiceState(job).label))).length,
            completed: jobs.filter(jobIsCompleted).length,
        };
        const filtered = jobListFilteredJobs();
        $('#page-jobs').innerHTML = `<div class="jobs-v2"><div class="page-head"><div><h1>Jobs</h1><p>Track every job from request to final collection</p><div class="jobs-v2-stats">${stats.active} active · ${stats.attention} need attention · ${stats.unpaid} unpaid</div></div><div class="jobs-v2-actions"><button class="ghost" id="jobExport">Export</button><button class="ghost" id="jobColumns">Columns</button><button class="primary" id="jobCreate">＋ New Job</button></div></div>
        <div class="jobs-v2-saved">${[
            ['all', 'All active', stats.active, ''], ['attention', 'Needs attention', stats.attention, 'danger'], ['week', 'Due this week', stats.week, ''], ['waiting', 'Waiting for client', stats.waiting, ''], ['unpaid', 'Unpaid invoices', stats.unpaid, 'warn'], ['completed', 'Completed', stats.completed, '']
        ].map(([key, label, count, tone]) => `<button type="button" class="jobs-v2-chip ${tone} ${jobListFilters.saved === key ? 'active' : ''}" data-job-saved="${key}">${label} <span class="ops-count">${count}</span></button>`).join('')}</div>
        <div class="card jobs-v2-toolbar"><input id="jobSearch" class="jobs-v2-search" placeholder="Search job ID, order, client or product" value="${html(jobFilter.search || '')}"><select id="jobPhase"><option value="">Phase</option>${jobListPhaseOptions()}</select><select id="jobHealth"><option value="">Health</option>${['On Track','Needs Attention','At Risk','Delayed','Blocked','Completed'].map((health) => `<option value="${html(health)}" ${normalizedStatusValue(jobFilter.health || '') === normalizedStatusValue(health) ? 'selected' : ''}>${html(health)}</option>`).join('')}</select><select id="jobOwner"><option value="">Owner</option>${jobListOwnerOptions()}</select><select id="jobClient"><option value="">Client</option>${clients.map((client) => `<option value="${html(client.id)}" ${String(jobFilter.client || '') === String(client.id) ? 'selected' : ''}>${html(client.name)}</option>`).join('')}</select><select id="jobDelivery"><option value="">Delivery</option><option value="week" ${jobListFilters.delivery === 'week' ? 'selected' : ''}>Due this week</option><option value="overdue" ${jobListFilters.delivery === 'overdue' ? 'selected' : ''}>Overdue</option></select><select id="jobInvoice"><option value="">Invoice</option><option value="unpaid" ${jobListFilters.invoice === 'unpaid' ? 'selected' : ''}>Unpaid</option></select><select id="jobMore"><option value="">More</option><option value="documents" ${jobListFilters.more === 'documents' ? 'selected' : ''}>Missing documents</option></select><button type="button" id="clearJobFilter">Clear</button></div>
        ${jobBulkActionBar(filtered)}
        <div class="card jobs-v2-table table-wrap"><table class="data-table"><thead><tr><th><input type="checkbox" id="jobBulkSelectAll" aria-label="Select all shown jobs"></th><th>Job / Order</th><th>Client / Brief</th><th>Product / Qty</th><th>Phase</th><th>Next Action</th><th>Owner</th><th>Delivery ↓</th><th>Progress</th><th>Invoice</th><th>Health</th><th>Updated ↓</th><th></th></tr></thead><tbody>${filtered.map(jobListRow).join('') || '<tr><td colspan="13"><div class="jobs-v2-empty">No jobs match the selected filters.</div></td></tr>'}</tbody></table></div></div>`;
        $('#jobCreate')?.addEventListener('click', openJobModal);
        $('#jobExport')?.addEventListener('click', () => showToast('Job list export prepared.'));
        $('#jobColumns')?.addEventListener('click', () => showToast('Compact columns are enabled for this view.'));
        $('#jobSearch')?.addEventListener('input', (event) => {
            const value = event.target.value;
            const caret = event.target.selectionStart ?? value.length;
            jobFilter.search = value;
            renderJobs();
            requestAnimationFrame(() => {
                const input = $('#jobSearch');
                if (!input) return;
                input.focus({ preventScroll: true });
                input.setSelectionRange(caret, caret);
            });
        });
        $('#jobPhase')?.addEventListener('change', (event) => { jobFilter.phase = event.target.value; renderJobs(); });
        $('#jobHealth')?.addEventListener('change', (event) => { jobFilter.health = event.target.value; renderJobs(); });
        $('#jobOwner')?.addEventListener('change', (event) => { jobListFilters.owner = event.target.value; renderJobs(); });
        $('#jobClient')?.addEventListener('change', (event) => { jobFilter.client = event.target.value; renderJobs(); });
        $('#jobDelivery')?.addEventListener('change', (event) => { jobListFilters.delivery = event.target.value; renderJobs(); });
        $('#jobInvoice')?.addEventListener('change', (event) => { jobListFilters.invoice = event.target.value; renderJobs(); });
        $('#jobMore')?.addEventListener('change', (event) => { jobListFilters.more = event.target.value; renderJobs(); });
        $('#clearJobFilter')?.addEventListener('click', () => { jobFilter = { search: '', phase: '', health: '', client: '' }; jobListFilters = { owner: '', delivery: '', invoice: '', more: '', saved: 'all' }; renderJobs(); });
        const selectAll = $('#jobBulkSelectAll');
        if (selectAll) {
            const selectable = filtered.filter((job) => Number(job.dbId));
            const selectedVisibleCount = selectedVisibleBulkJobs(filtered).length;
            selectAll.checked = selectable.length > 0 && selectedVisibleCount === selectable.length;
            selectAll.indeterminate = selectedVisibleCount > 0 && selectedVisibleCount < selectable.length;
            selectAll.addEventListener('change', (event) => {
                selectable.forEach((job) => {
                    if (event.target.checked) selectedBulkJobIds.add(Number(job.dbId));
                    else selectedBulkJobIds.delete(Number(job.dbId));
                });
                renderJobs();
            });
        }
        $$('[data-job-bulk-checkbox]').forEach((checkbox) => checkbox.addEventListener('change', (event) => {
            const id = Number(event.target.dataset.jobBulkCheckbox);
            if (event.target.checked) selectedBulkJobIds.add(id); else selectedBulkJobIds.delete(id);
            renderJobs();
        }));
        $('#jobBulkClear')?.addEventListener('click', () => { selectedBulkJobIds.clear(); renderJobs(); });
        $('#jobBulkDeactivate')?.addEventListener('click', () => runJobBulkAction('deactivate', filtered));
        $('#jobBulkDelete')?.addEventListener('click', () => runJobBulkAction('delete', filtered));
        $$('[data-job-saved]').forEach((button) => button.addEventListener('click', () => { jobListFilters.saved = button.dataset.jobSaved; renderJobs(); }));
        $$('[data-job-list-menu]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); openJob(button.dataset.jobListMenu); }));
        bindCommon();
        applyActionPermissions();
    });

    renderBoard = function () {
        const taskList = boardFilteredTasks();
        const jobList = boardMode === 'tasks' ? Array.from(new Set(taskList.map((task) => boardJobForTask(task)?.id || task.job))).map((id) => jobs.find((job) => String(job.id) === String(id)) || { id }).filter(Boolean) : boardFilteredJobsForPhases();
        const filteredCount = boardMode === 'tasks' ? taskList.length : jobList.length;
        const filteredJobCount = boardMode === 'tasks' ? jobList.length : jobList.length;
        $('#page-board').innerHTML = `<div class="ops-board-page ops-mode-${boardMode === 'tasks' ? 'tasks' : 'jobs'}"><div class="page-head"><div><h1>Operations Board</h1><p>Track work across all active Jobs</p></div><div class="ops-board-actions"><button type="button" class="ghost ops-expand-toggle" id="opsBoardExpandAllTop">${boardAllVisibleJobsExpanded() ? 'Minimize all jobs' : 'Expand all jobs'}</button><button class="primary" id="boardCreate">＋ New Job</button></div></div><div class="ops-board-tabs"><button class="tab-btn ${boardMode === 'jobs' ? 'active' : ''}" data-boardmode="jobs">Job Board</button><button class="tab-btn ${boardMode === 'tasks' ? 'active' : ''}" data-boardmode="tasks">Task Board</button></div>${operationsBoardToolbar(filteredCount, filteredJobCount)}<div id="kanbanArea"></div></div>`;
        renderKanban();
        $$('[data-boardmode]').forEach((button) => button.addEventListener('click', () => { boardMode = button.dataset.boardmode; try { sessionStorage.setItem('flowtrackBoardModeTouched', '1'); } catch (_) {} renderBoard(); }));
        $('#boardCreate')?.addEventListener('click', openJobModal);
        $('#opsBoardSearch')?.addEventListener('input', (event) => {
            const value = event.target.value;
            const caret = event.target.selectionStart ?? value.length;
            boardSearchValue = value;
            renderBoard();
            requestAnimationFrame(() => {
                const input = $('#opsBoardSearch');
                if (!input) return;
                input.focus({ preventScroll: true });
                input.setSelectionRange(caret, caret);
            });
        });
        $$('[data-board-filter]').forEach((select) => select.addEventListener('change', () => { boardFilters[select.dataset.boardFilter] = select.value; renderBoard(); }));
        $$('[data-board-quick]').forEach((button) => button.addEventListener('click', () => { boardQuickFilter = boardQuickFilter === button.dataset.boardQuick ? 'all' : button.dataset.boardQuick; renderBoard(); }));
        $('#opsBoardClear')?.addEventListener('click', () => { boardSearchValue = ''; boardQuickFilter = 'all'; boardFilters = { job: '', client: '', assignee: '', status: '', due: '', priority: '' }; renderBoard(); });
        const toggleAllBoardJobs = () => {
            const ids = boardVisibleJobIdsForExpandToggle();
            if (boardMode === 'tasks') {
                const allOpen = ids.length && ids.every((id) => !collapsedBoardJobs.has(id));
                ids.forEach((id) => { if (allOpen) collapsedBoardJobs.add(id); else collapsedBoardJobs.delete(id); });
            } else {
                const allOpen = ids.length && ids.every((id) => expandedBoardJobCards.has(id));
                ids.forEach((id) => { if (allOpen) expandedBoardJobCards.delete(id); else expandedBoardJobCards.add(id); });
            }
            renderBoard();
        };
        $('#opsBoardExpandAll')?.addEventListener('click', toggleAllBoardJobs);
        $('#opsBoardExpandAllTop')?.addEventListener('click', toggleAllBoardJobs);
        $('[data-board-save-view]')?.addEventListener('click', () => showToast('Board view saved for quick access.'));
        applyActionPermissions();
    };

    registerFlowtrackExtension('renderKanban', 'renderKanban-stage-2', function () {        const area = $('#kanbanArea');
        if (!area) return;
        if (boardMode === 'tasks') {
            area.innerHTML = renderOperationsTaskBoard(boardFilteredTasks());
        } else {
            area.innerHTML = renderOperationsJobBoard(boardFilteredJobsForPhases());
        }

        let dragged = null;
        $$('.ops-task-card,.ops-phase-card').forEach((card) => {
            card.addEventListener('dragstart', (event) => {
                if (card.dataset.task && card.dataset.taskUpdatable === '0') {
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

        $$('.ops-task-cell').forEach((cell) => {
            cell.addEventListener('dragover', (event) => {
                event.preventDefault();
                cell.classList.add('drop-hover');
            });
            cell.addEventListener('dragleave', () => cell.classList.remove('drop-hover'));
            cell.addEventListener('drop', async () => {
                cell.classList.remove('drop-hover');
                if (!dragged?.dataset.task) return;
                try {
                    const task = tasks.find((item) => item.id === dragged.dataset.task);
                    if (!task) throw new Error('The selected task is no longer available.');
                    const targetStatus = boardTargetStatusForColumn(cell.dataset.dropColumn);
                    if (!targetStatus) throw new Error('The selected task status is not available in this workspace.');
                    if (taskStatusMatches(targetStatus, ['BLK', 'ATTN', 'BLOCKED', 'ATTENTION', 'HOLD', 'ON HOLD'])) {
                        showToast('Open the task and add a blocker reason before marking it blocked.');
                        openTask(task.id);
                        return;
                    }
                    await persistTask(task, targetStatus);
                    renderBoard();
                    updateBadges();
                    showToast('Task status updated on the board.');
                } catch (error) {
                    showToast(error.message);
                    renderBoard();
                }
            });
        });

        $$('.ops-phase-column .kanban-list').forEach((list) => {
            list.addEventListener('dragover', (event) => event.preventDefault());
            list.addEventListener('drop', async () => {
                if (!dragged?.dataset.job) return;
                try {
                    const job = jobs.find((item) => item.id === dragged.dataset.job);
                    if (!job) throw new Error('The selected Job is no longer available.');
                    const workflow = getWorkflow(Number(job.workflow)) || getWorkflow(job.workflow);
                    const phaseId = Number(list.dataset.dropPhaseId);
                    const excludedPhaseIds = new Set((job.excludedPhaseIds || []).map((id) => Number(id)));
                    const phase = (workflow?.phases || []).find((item) => Number(item.id) === phaseId && item.active !== false && !excludedPhaseIds.has(Number(item.id)));
                    if (!phase) throw new Error('The selected workflow phase is not configured for this Job.');
                    const activePhases = (workflow.phases || []).filter((item) => item.active !== false && !excludedPhaseIds.has(Number(item.id))).slice().sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
                    const currentIndex = activePhases.findIndex((item) => Number(item.id) === Number(job.phaseId));
                    const targetIndex = activePhases.findIndex((item) => Number(item.id) === Number(phase.id));
                    let reason = null;
                    if (currentIndex >= 0 && targetIndex > currentIndex + 1) {
                        reason = window.prompt('Enter the authorised reason for skipping workflow phases:')?.trim() || '';
                        if (!reason) {
                            showToast('A reason is required when skipping workflow phases.');
                            renderBoard();
                            return;
                        }
                    }
                    await moveJobToPhase(job, phase, reason);
                    renderBoard();
                    updateBadges();
                    showToast('Job phase updated on the board.');
                } catch (error) {
                    showToast(error.message);
                    renderBoard();
                }
            });
        });

        $$('[data-board-collapse]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            const id = button.dataset.boardCollapse;
            if (collapsedBoardJobs.has(id)) collapsedBoardJobs.delete(id); else collapsedBoardJobs.add(id);
            renderKanban();
        }));
        $$('[data-board-job-card-toggle]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const id = button.dataset.boardJobCardToggle;
            if (expandedBoardJobCards.has(id)) expandedBoardJobCards.delete(id); else expandedBoardJobCards.add(id);
            renderKanban();
        }));
        $$('[data-board-card-menu]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const currentMenu = button.closest('.ops-board-card-menu-wrap')?.querySelector('.ops-board-card-action-menu');
            $$('.ops-board-card-action-menu').forEach((menu) => {
                if (menu !== currentMenu) menu.classList.add('hidden');
            });
            currentMenu?.classList.toggle('hidden');
            button.setAttribute('aria-expanded', currentMenu?.classList.contains('hidden') ? 'false' : 'true');
        }));
        $$('[data-board-row-menu]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            showToast('Open the Job to manage its actions.');
        }));
        $$('[data-board-delete-task]').forEach((button) => button.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const taskCode = button.dataset.boardDeleteTaskCode || 'this Task';
            if (!window.confirm(`Delete ${taskCode}?`)) return;
            button.disabled = true;
            try {
                const result = await api(`/flowtrack/template-api/tasks/${button.dataset.boardDeleteTask}`, { method: 'DELETE' });
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                flowtrackRemoveRecord(tasks, result.deletedTask || { dbId: Number(button.dataset.boardDeleteTask) });
                renderBoard();
                updateBadges();
                showToast(result.message || 'Task deleted successfully.');
            } catch (error) {
                button.disabled = false;
                showToast(error.message);
            }
        }));
        bindCommon();
    });

    const flowtrackBoundHandlers = new WeakMap();
    function flowtrackBindOnce(element, key, eventName, handler, options) {
        if (!element) return;
        const keys = flowtrackBoundHandlers.get(element) || new Set();
        if (keys.has(key)) return;
        keys.add(key);
        flowtrackBoundHandlers.set(element, keys);
        element.addEventListener(eventName, handler, options);
    }

    bindCommon = function () {
        const newClientJob = $('[data-new-client-job]');
        flowtrackBindOnce(newClientJob, 'new-client-job', 'click', () => {
            const id = newClientJob.dataset.newClientJob;
            closeAll();
            setTimeout(() => openJobModal(id), 50);
        });
        $$('[data-go]').forEach((button) => flowtrackBindOnce(button, 'go', 'click', () => navigate(button.dataset.go)));
        $$('[data-job]').forEach((element) => flowtrackBindOnce(element, 'open-job', 'click', (event) => {
            event.stopPropagation();
            openJob(element.dataset.job);
        }));
        $$('[data-task]').forEach((element) => flowtrackBindOnce(element, 'open-task', 'click', (event) => {
            if (element.classList.contains('dragging')) return;
            event.stopPropagation();
            openTask(element.dataset.task);
        }));
        $$('[data-board-job-link]').forEach((button) => flowtrackBindOnce(button, 'board-job-link', 'click', (event) => {
            event.stopPropagation();
            openJob(button.dataset.boardJobLink);
        }));
        $$('[data-client]').forEach((element) => flowtrackBindOnce(element, 'open-client', 'click', () => openClient(element.dataset.client)));
        $$('[data-doc]').forEach((element) => flowtrackBindOnce(element, 'open-document', 'click', () => openDoc(element.dataset.doc)));
        $$('[data-metric]').forEach((metricElement) => {
            if (!metricElement.dataset.metric) return;
            metricElement.setAttribute('role', 'button');
            metricElement.setAttribute('tabindex', '0');
            metricElement.setAttribute('title', 'Click to filter the related records');
            flowtrackBindOnce(metricElement, 'metric', 'click', (event) => {
                event.preventDefault();
                if (flowtrackHandleMetricFilter(metricElement)) return;
                const filter = metricElement.dataset.metric;
                navigate(filter === 'done' ? 'reports' : filter === 'blocked' || filter === 'overdue' ? 'mywork' : 'jobs');
                if (filter === 'risk') { jobListFilters.saved = 'attention'; renderJobs(); }
                if (filter === 'shipment') { const phase = flowtrackShippingPhaseFilterValue(); if (phase !== '') jobFilter.phase = String(phase); renderJobs(); }
                if (filter === 'payment') { jobListFilters.invoice = 'unpaid'; renderJobs(); }
            });
            flowtrackBindOnce(metricElement, 'metric-keyboard', 'keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                metricElement.click();
            });
        });

        $$('[data-next-phase]').forEach((next) => flowtrackBindOnce(next, 'next-phase', 'click', async () => {
            const job = jobs.find((item) => item.id === next.dataset.nextPhase);
            if (!job) return;
            const phases = flowtrackActivePhases(job);
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
        }));
        $$('[data-flow-phase]').forEach((button) => flowtrackBindOnce(button, 'flow-phase-history', 'click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const job = flowtrackFindJob(button.dataset.flowJob);
            if (!job) return;
            const scope = button.closest('.drawer-body') || button.closest('.standalone-record-content') || document;
            const panel = scope.querySelector('#flowtrackPhaseHistoryPanel');
            if (panel) panel.innerHTML = flowtrackInlineProgress('Loading selected phase tasks…');
            try {
                await flowtrackRefreshJobTasks(job);
            } catch (error) {
                // Keep the current in-memory data visible if the refresh fails.
            }
            flowtrackOpenPhaseHistory(button.dataset.flowJob, button.dataset.flowPhase, button);
        }));
        $$('[data-add-task]').forEach((button) => flowtrackBindOnce(button, 'add-job-task', 'click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            openJobTaskModal(button.dataset.addTask, button.dataset.addTaskPhase || null);
        }));
        $$('[data-open-job-tasks]').forEach((viewTasks) => flowtrackBindOnce(viewTasks, 'view-job-tasks', 'click', () => $('[data-detailtab="2"]')?.click()));
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

    function jobNeedsAttentionFromTasks(job) {
        return tasks.some((task) => {
            const sameJob = String(task.job) === String(job?.id) || Number(task.jobDbId) === Number(job?.dbId);
            if (!sameJob || taskIsCompleted(task)) return false;
            const status = normalizedStatusValue(task.status || task.statusCode || '');
            return Boolean(task.attention)
                || Boolean(task.blockerReason)
                || ['BLOCKED', 'BLK', 'ATTENTION', 'ATTN', 'HOLD', 'ON HOLD', 'REVISION REQUIRED'].includes(status);
        });
    }

    function dynamicDashboardMetrics() {
        const date = summaryMetrics.asOf?.date || metricDateKey();
        const activeJobs = jobs.filter((job) => !jobIsCompleted(job));
        return {
            activeJobs: activeJobs.length,
            needsAttention: activeJobs.filter((job) => jobNeedsAttentionFromTasks(job) || job.attention || ['needs attention', 'at risk', 'delayed', 'blocked'].includes(String(job.health || '').toLowerCase())).length,
            overdueTasks: tasks.filter((task) => !taskIsCompleted(task) && task.due && task.due < date).length,
            blockedTasks: tasks.filter(taskIsBlocked).length,
            shippingNow: activeJobs.filter((job) => /ship/i.test(phaseNameFor(job)) || /ship/i.test(phaseShortFor(job))).length,
            outstanding: canSensitive('invoice_amount') ? clients.reduce((sum, client) => sum + Number(client.outstanding || 0), 0) : null,
            canViewFinancials: canSensitive('invoice_amount'),
        };
    }

    function resetFlowtrackJobFilters() {
        jobFilter = { search: '', phase: '', health: '', client: '' };
        jobListFilters = { owner: '', delivery: '', invoice: '', more: '', saved: 'all' };
        selectedBulkJobIds.clear();
    }

    function resetFlowtrackMyWorkFilters() {
        myWorkSearchValue = '';
        myWorkQuickFilter = 'all';
        myWorkFilters = { job: '', client: '', status: '', priority: '', due: '' };
    }

    function flowtrackShippingPhaseFilterValue() {
        for (const workflow of flowtrackToArray(workflowTemplates)) {
            const phasesForWorkflow = flowtrackToArray(workflow?.phases);
            for (let index = 0; index < phasesForWorkflow.length; index += 1) {
                const phase = phasesForWorkflow[index] || {};
                const label = [phase.name, phase.short, phase.shortName, phase.taskPack, phase.document, phase.entry, phase.exit].join(' ');
                if (/ship|shipping|shipment|delivery|dispatch/i.test(label)) {
                    return phase.id ?? phase.phaseId ?? index;
                }
            }
        }
        return '';
    }

    function flowtrackApplyDashboardMetricFilter(filter) {
        resetFlowtrackJobFilters();
        resetFlowtrackMyWorkFilters();
        if (filter === 'jobs') {
            jobListFilters.saved = 'all';
            navigate('jobs');
            showToast('Showing active Jobs.');
            return true;
        }
        if (filter === 'risk') {
            jobListFilters.saved = 'attention';
            navigate('jobs');
            showToast('Showing Jobs that need attention.');
            return true;
        }
        if (filter === 'overdue') {
            myWorkFilters.due = 'overdue';
            navigate('mywork');
            showToast('Showing overdue Tasks.');
            return true;
        }
        if (filter === 'blocked') {
            myWorkQuickFilter = 'blocked';
            navigate('mywork');
            showToast('Showing blocked and attention Tasks.');
            return true;
        }
        if (filter === 'shipment') {
            const shippingPhase = flowtrackShippingPhaseFilterValue();
            if (shippingPhase !== '') jobFilter.phase = String(shippingPhase);
            else jobFilter.search = 'ship';
            navigate('jobs');
            showToast('Showing Jobs currently in shipping or delivery phases.');
            return true;
        }
        if (filter === 'payment') {
            jobListFilters.invoice = 'unpaid';
            navigate('jobs');
            showToast('Showing Jobs with open balances or unpaid invoices.');
            return true;
        }
        return false;
    }

    function flowtrackApplyMyWorkMetricFilter(filter) {
        resetFlowtrackMyWorkFilters();
        if (filter === 'today') {
            myWorkFilters.due = 'today';
            navigate('mywork');
            showToast('Showing Tasks due today.');
            return true;
        }
        if (filter === 'overdue') {
            myWorkFilters.due = 'overdue';
            navigate('mywork');
            showToast('Showing overdue Tasks.');
            return true;
        }
        if (filter === 'blocked') {
            myWorkQuickFilter = 'blocked';
            navigate('mywork');
            showToast('Showing blocked and attention Tasks.');
            return true;
        }
        if (filter === 'waiting') {
            myWorkQuickFilter = 'waiting';
            navigate('mywork');
            showToast('Showing Tasks waiting for review.');
            return true;
        }
        if (filter === 'done-week') {
            myWorkQuickFilter = 'done-week';
            navigate('mywork');
            showToast('Showing Tasks completed this week.');
            return true;
        }
        if (filter === 'upcoming') {
            myWorkQuickFilter = 'upcoming';
            navigate('mywork');
            showToast('Showing upcoming Tasks.');
            return true;
        }
        if (filter === 'done') {
            myWorkQuickFilter = 'done';
            navigate('mywork');
            showToast('Showing completed Tasks.');
            return true;
        }
        return false;
    }

    function flowtrackHandleMetricFilter(metricElement) {
        const filter = metricElement?.dataset?.metric || '';
        if (!filter) return false;
        const sourcePage = metricElement.closest('#page-mywork') ? 'mywork' : metricElement.closest('#page-dashboard') ? 'dashboard' : '';
        if (sourcePage === 'dashboard') return flowtrackApplyDashboardMetricFilter(filter);
        if (sourcePage === 'mywork') return flowtrackApplyMyWorkMetricFilter(filter);
        return false;
    }

    function recentTaskActivities() {
        const apiActivities = (Array.isArray(dashboardActivities) ? dashboardActivities : []).map((item) => ({
            ...item,
            createdAt: item.createdAt || item.created_at || '',
        }));
        const derivedActivities = tasks.slice()
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
        return flowtrackToArray(apiActivities).concat(flowtrackToArray(derivedActivities))
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
            .filter((job) => jobNeedsAttentionFromTasks(job) || job.attention || ['needs attention', 'at risk', 'delayed', 'blocked'].includes(String(job.health || '').toLowerCase()))
            .sort((a, b) => String(a.delivery || '9999-12-31').localeCompare(String(b.delivery || '9999-12-31')))
            .slice(0, 6);
        const phaseMap = new Map();
        activeJobs.forEach((job) => {
            const label = phaseShortFor(job) || phaseNameFor(job) || 'Unassigned';
            phaseMap.set(label, (phaseMap.get(label) || 0) + 1);
        });
        const phaseCounts = Array.from(phaseMap.entries())
            .filter((entry) => Array.isArray(entry) && entry.length >= 2)
            .sort((left, right) => Number(right[1] || 0) - Number(left[1] || 0));
        const maxPhase = Math.max(1, ...phaseCounts.map((entry) => Number(entry?.[1] || 0)));
        const workloadMap = new Map();
        tasks.filter((task) => !taskIsCompleted(task)).forEach((task) => {
            const assignee = task.assignee || 'Unassigned';
            workloadMap.set(assignee, (workloadMap.get(assignee) || 0) + 1);
        });
        const workload = Array.from(workloadMap.entries())
            .filter((entry) => Array.isArray(entry) && entry.length >= 2)
            .sort((left, right) => Number(right[1] || 0) - Number(left[1] || 0))
            .slice(0, 6);
        const maxWorkload = Math.max(1, ...workload.map((entry) => Number(entry?.[1] || 0)));
        const displayDate = summaryMetrics.asOf?.displayDate || new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
        const outstandingValue = metrics.canViewFinancials ? currency(metrics.outstanding || 0) : 'Restricted';
        const recentActivities = recentTaskActivities();

        $('#page-dashboard').innerHTML = `<div class="page-head"><div><h1>Management Dashboard</h1><p>${html(displayDate)} · Exceptions, workload and delivery health</p></div><div class="actions"><button class="ghost" data-dashboard-action="export">Export summary</button><button class="primary" data-dashboard-action="create">＋ New Job</button></div></div>
        <div class="metrics">${metric('Active Jobs', metrics.activeJobs, 'Across visible workflow phases', 'jobs')}${metric('Needs Attention', metrics.needsAttention, 'Risk, delay or blocker', 'risk')}${metric('Overdue Tasks', metrics.overdueTasks, 'Require immediate update', 'overdue')}${metric('Blocked Tasks', metrics.blockedTasks, 'Require resolution', 'blocked')}${metric('Shipping Now', metrics.shippingNow, 'Ready or in transit', 'shipment')}${metric('Outstanding', outstandingValue, metrics.canViewFinancials ? 'Open client balance' : 'Financial access required', 'payment')}</div>
        <div class="grid-2"><div class="card section-card"><div class="section-head"><h3>Needs Attention</h3><button class="link-btn" data-go="jobs">View all Jobs</button></div><div class="attention-list">${attentionJobs.map((job) => `<div class="attention-item" data-job="${job.id}"><span class="signal ${String(job.health).toLowerCase() === 'blocked' ? 'purple' : String(job.health).toLowerCase() === 'needs attention' ? 'amber' : 'red'}"></span><div><div class="item-title">${html(job.next || job.title)}</div><div class="item-meta">${html(job.id)} · ${html(clientName(job.client))} · ${html(phaseShortFor(job))}</div></div>${healthBadge(job.health)}</div>`).join('') || '<div class="empty-state">No visible Jobs currently require attention.</div>'}</div></div>
        <div class="card section-card"><div class="section-head"><h3>Jobs by Phase</h3><button class="link-btn" data-go="board">Open board</button></div><div class="phase-list">${phaseCounts.map((entry) => { const label = entry?.[0] ?? 'Unassigned'; const count = Number(entry?.[1] || 0); return `<div class="phase-row"><span>${html(label)}</span><div class="progress"><span style="width:${Math.max(8, count / maxPhase * 100)}%"></span></div><b>${count}</b></div>`; }).join('') || '<div class="empty-state">No active Jobs are visible.</div>'}</div></div></div>
        <div class="grid-3"><div class="card section-card"><div class="section-head"><h3>Team Workload</h3><button class="link-btn" data-go="reports">Details</button></div>${workload.map((entry) => { const name = String(entry?.[0] || 'Unassigned'); const count = Number(entry?.[1] || 0); return `<div class="workload-row"><div class="person"><span class="avatar">${html(userInitials(name))}</span>${html(name)}</div><div class="progress"><span style="width:${Math.max(8, count / maxWorkload * 100)}%"></span></div><b>${count}</b></div>`; }).join('') || '<div class="empty-state">No open Tasks are visible.</div>'}</div>
        <div class="card section-card"><div class="section-head"><h3>Upcoming Deliveries</h3><button class="link-btn" data-go="jobs">View Jobs</button></div><table class="mini-table"><thead><tr><th>Job</th><th>Client</th><th>Delivery</th></tr></thead><tbody>${activeJobs.filter((job) => job.delivery).sort((a, b) => a.delivery.localeCompare(b.delivery)).slice(0, 6).map((job) => `<tr data-job="${job.id}" style="cursor:pointer"><td><b>${html(String(job.id).slice(-5))}</b><div class="muted small truncate" style="max-width:125px">${html(job.title)}</div></td><td>${html(String(clientName(job.client) || '—').trim().split(/\s+/).filter(Boolean)[0] || '—')}</td><td class="${job.delivery < (summaryMetrics.asOf?.date || metricDateKey()) ? 'due overdue' : ''}">${formatDate(job.delivery)}</td></tr>`).join('') || '<tr><td colspan="3"><div class="empty-state">No upcoming deliveries.</div></td></tr>'}</tbody></table></div>
        <div class="card section-card"><div class="section-head"><h3>Recent Activity</h3><button class="link-btn" data-go="mywork">View Tasks</button></div>${recentActivities.map((item) => `<div class="activity" ${item.task ? `data-task="${html(item.task)}"` : item.job ? `data-job="${html(item.job)}"` : ''} style="cursor:pointer"><span class="avatar">${html(item.user?.initials || userInitials(item.user?.name || 'System'))}</span><div><div class="activity-text"><b>${html(item.title || 'Task created')}</b><br>${html(item.text || '')}</div><div class="activity-time">${html(item.time || '')}</div></div></div>`).join('') || '<div class="empty-state">No recently created tasks.</div>'}</div></div>`;
        bindCommon();
        $$('[data-dashboard-action="create"]').forEach((button) => button.addEventListener('click', openJobModal));
        $('[data-dashboard-action="export"]')?.addEventListener('click', () => window.print());
        applyActionPermissions();
    };

    function taskAssignedToCurrentUser(task) {
        const userId = Number(currentUser?.id || 0);
        const taskAssigneeId = Number(task?.assigneeId || task?.assignee_id || 0);
        if (userId && taskAssigneeId && userId === taskAssigneeId) return true;
        const userName = String(currentUser?.name || '').trim().toLowerCase();
        const taskAssigneeName = String(task?.assignee || task?.assigneeName || '').trim().toLowerCase();
        return Boolean(userName && taskAssigneeName && userName === taskAssigneeName);
    }

    function myWorkBaseTasks() {
        // Admin and Super Admin use My Work as the full work board so they can supervise every job/task.
        if (isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin) {
            return tasks.slice();
        }
        return tasks.filter(taskAssignedToCurrentUser);
    }

    function myWorkFilteredTasks() {
        let list = myWorkBaseTasks();
        const date = summaryMetrics.asOf?.date || metricDateKey();
        const weekStart = summaryMetrics.asOf?.weekStart || metricDateKey(new Date(new Date().setDate(new Date().getDate() - new Date().getDay() + 1)));
        const weekEnd = summaryMetrics.asOf?.weekEnd || metricDateKey(new Date(new Date().setDate(new Date().getDate() - new Date().getDay() + 7)));
        const search = myWorkSearchValue.trim().toLowerCase();
        if (search) {
            list = list.filter((task) => {
                const job = boardJobForTask(task);
                const haystack = [task.id, task.title, task.job, task.phase, task.assignee, task.priority, task.status, job?.title, clientName(job?.client)].join(' ').toLowerCase();
                return haystack.includes(search);
            });
        }
        if (myWorkFilters.job) list = list.filter((task) => String(task.job) === String(myWorkFilters.job));
        if (myWorkFilters.client) list = list.filter((task) => String(boardJobForTask(task)?.client) === String(myWorkFilters.client));
        if (myWorkFilters.status) list = list.filter((task) => boardTaskColumnKey(task) === myWorkFilters.status || String(task.statusId) === String(myWorkFilters.status));
        if (myWorkFilters.priority) list = list.filter((task) => String(task.priorityId || task.priority) === String(myWorkFilters.priority));
        if (myWorkFilters.due === 'overdue') list = list.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done');
        if (myWorkFilters.due === 'today') list = list.filter((task) => boardDateOnly(task.due) === today);
        if (myWorkFilters.due === 'week') list = list.filter((task) => {
            const days = boardDaysUntil(task.due);
            return days !== null && days >= 0 && days <= 7;
        });
        if (myWorkQuickFilter === 'open') list = list.filter((task) => !taskIsCompleted(task));
        if (myWorkQuickFilter === 'overdue') list = list.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done');
        if (myWorkQuickFilter === 'week') list = list.filter((task) => { const days = boardDaysUntil(task.due); return days !== null && days >= 0 && days <= 7; });
        if (myWorkQuickFilter === 'blocked') list = list.filter((task) => /BLOCK|ATTENTION|HOLD/.test(normalizedStatusValue(task.status || '')) || task.attention || task.blockerReason);
        if (myWorkQuickFilter === 'waiting') list = list.filter((task) => boardTaskStatusBucket(task) === 'waiting');
        if (myWorkQuickFilter === 'done') list = list.filter(taskIsCompleted);
        if (myWorkQuickFilter === 'done-week') list = list.filter((task) => taskIsCompleted(task) && task.completedAt && task.completedAt.slice(0, 10) >= weekStart && task.completedAt.slice(0, 10) <= weekEnd);
        if (myWorkQuickFilter === 'upcoming') list = list.filter((task) => !taskIsCompleted(task) && task.due && task.due > date);
        return list;
    }

    function myWorkToolbar(filteredCount, filteredJobCount) {
        const ownTasks = myWorkBaseTasks();
        const ownJobs = Array.from(new Set(ownTasks.map((task) => boardJobForTask(task)?.id || task.job)))
            .map((id) => jobs.find((job) => String(job.id) === String(id)) || { id })
            .filter(Boolean);
        const jobOptions = boardSelectOptions(ownJobs, (job) => job.id, (job) => `${job.id} · ${clientName(job.client)}`, myWorkFilters.job, 'Job');
        const clientIds = new Set(ownJobs.map((job) => String(job.client)).filter(Boolean));
        const clientOptions = boardSelectOptions(clients.filter((client) => clientIds.has(String(client.id))), (client) => client.id, (client) => client.name, myWorkFilters.client, 'Client');
        const priorityOptions = boardSelectOptions(masterData.priorities || [], (priority) => priority.id || priority.name, (priority) => priority.name, myWorkFilters.priority, 'Priority');
        const quickCounts = {
            open: ownTasks.filter((task) => !taskIsCompleted(task)).length,
            overdue: ownTasks.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done').length,
            week: ownTasks.filter((task) => { const days = boardDaysUntil(task.due); return days !== null && days >= 0 && days <= 7; }).length,
            blocked: ownTasks.filter((task) => /BLOCK|ATTENTION|HOLD/.test(normalizedStatusValue(task.status || '')) || task.attention || task.blockerReason).length,
            waiting: ownTasks.filter((task) => boardTaskStatusBucket(task) === 'waiting').length,
            done: ownTasks.filter(taskIsCompleted).length,
        };
        return `<div class="ops-toolbar my-work-toolbar">
            <label class="ops-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg><input id="myWorkBoardSearch" value="${html(myWorkSearchValue)}" placeholder="Search my assigned tasks, job ID or client"></label>
            <select class="ops-filter" data-mywork-filter="job">${jobOptions}</select>
            <select class="ops-filter" data-mywork-filter="client">${clientOptions}</select>
            <select class="ops-filter" data-mywork-filter="status"><option value="">Status</option>${boardColumnDefinitions().map((column) => `<option value="${column.key}" ${myWorkFilters.status === column.key ? 'selected' : ''}>${column.label}</option>`).join('')}</select>
            <select class="ops-filter" data-mywork-filter="priority">${priorityOptions}</select>
            <select class="ops-filter" data-mywork-filter="due"><option value="" ${!myWorkFilters.due ? 'selected' : ''}>Due date</option><option value="overdue" ${myWorkFilters.due === 'overdue' ? 'selected' : ''}>Overdue</option><option value="today" ${myWorkFilters.due === 'today' ? 'selected' : ''}>Due today</option><option value="week" ${myWorkFilters.due === 'week' ? 'selected' : ''}>Due this week</option></select>
            <button type="button" class="ops-clear" id="myWorkBoardClear">Clear</button>
        </div>
        <div class="ops-quick-row"><div class="ops-quick-left"><span class="ops-quick-label">Quick filters</span>${[
            ['open', (isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin) ? 'All open' : 'My open', quickCounts.open, ''],
            ['overdue', 'Overdue', quickCounts.overdue, 'danger'],
            ['week', 'Due this week', quickCounts.week, ''],
            ['blocked', 'Blocked', quickCounts.blocked, 'danger'],
            ['waiting', 'Waiting external', quickCounts.waiting, ''],
            ['done', 'Completed', quickCounts.done, ''],
        ].map(([key, label, count, tone]) => `<button type="button" class="ops-chip ${tone} ${myWorkQuickFilter === key ? 'active' : ''}" data-mywork-quick="${key}">${label} <span class="ops-count">${count}</span></button>`).join('')}</div></div>
        <div class="ops-status-summary"><span>Showing <b>${filteredCount}</b> ${isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin ? 'visible task' : 'assigned task'}${filteredCount === 1 ? '' : 's'} ${isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin ? 'for admin review' : `for <b>${html(currentUser?.name || 'current user')}</b>`} across <b>${filteredJobCount}</b> ${filteredJobCount === 1 ? 'job' : 'jobs'}</span></div>`;
    }

    function renderMyWorkBoard() {
        const area = $('#myWorkKanbanArea');
        if (!area) return;
        const taskList = myWorkFilteredTasks();
        area.innerHTML = renderOperationsTaskBoard(taskList);
        let dragged = null;
        $$('#myWorkKanbanArea .ops-task-card').forEach((card) => {
            card.addEventListener('dragstart', (event) => {
                if (card.dataset.task && card.dataset.taskUpdatable === '0') {
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
        $$('#myWorkKanbanArea .ops-task-cell').forEach((cell) => {
            cell.addEventListener('dragover', (event) => {
                event.preventDefault();
                cell.classList.add('drop-hover');
            });
            cell.addEventListener('dragleave', () => cell.classList.remove('drop-hover'));
            cell.addEventListener('drop', async () => {
                cell.classList.remove('drop-hover');
                if (!dragged?.dataset.task) return;
                try {
                    const task = tasks.find((item) => item.id === dragged.dataset.task);
                    if (!task) throw new Error('The selected task is no longer available.');
                    if (!taskAssignedToCurrentUser(task) && !(isFlowAdmin || isFlowSuperAdmin)) throw new Error('This Task is not assigned to your account.');
                    const targetStatus = boardTargetStatusForColumn(cell.dataset.dropColumn);
                    if (!targetStatus) throw new Error('The selected task status is not available in this workspace.');
                    if (taskStatusMatches(targetStatus, ['BLK', 'ATTN', 'BLOCKED', 'ATTENTION', 'HOLD', 'ON HOLD'])) {
                        showToast('Open the task and add a blocker reason before marking it blocked.');
                        openTask(task.id);
                        return;
                    }
                    await persistTask(task, targetStatus);
                    renderMyWorkBoard();
                    updateBadges();
                    showToast('Task status updated.');
                } catch (error) {
                    showToast(error.message);
                    renderMyWorkBoard();
                }
            });
        });
        $$('#myWorkKanbanArea [data-board-collapse]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            const id = button.dataset.boardCollapse;
            if (collapsedBoardJobs.has(id)) collapsedBoardJobs.delete(id); else collapsedBoardJobs.add(id);
            renderMyWorkBoard();
        }));
        $$('#myWorkKanbanArea [data-board-card-menu]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const currentMenu = button.closest('.ops-board-card-menu-wrap')?.querySelector('.ops-board-card-action-menu');
            $$('#myWorkKanbanArea .ops-board-card-action-menu').forEach((menu) => {
                if (menu !== currentMenu) menu.classList.add('hidden');
            });
            currentMenu?.classList.toggle('hidden');
            button.setAttribute('aria-expanded', currentMenu?.classList.contains('hidden') ? 'false' : 'true');
        }));
        $$('#myWorkKanbanArea [data-board-row-menu]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            showToast('Open the Job to manage its actions.');
        }));
        $$('#myWorkKanbanArea [data-board-delete-task]').forEach((button) => button.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const taskCode = button.dataset.boardDeleteTaskCode || 'this Task';
            if (!window.confirm(`Delete ${taskCode}?`)) return;
            button.disabled = true;
            try {
                const result = await api(`/flowtrack/template-api/tasks/${button.dataset.boardDeleteTask}`, { method: 'DELETE' });
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                flowtrackRemoveRecord(tasks, result.deletedTask || { dbId: Number(button.dataset.boardDeleteTask) });
                renderMyWork();
                updateBadges();
                showToast(result.message || 'Task deleted successfully.');
            } catch (error) {
                button.disabled = false;
                showToast(error.message);
            }
        }));
        bindCommon();
    }

    renderMyWork = function () {
        const filteredTasks = myWorkFilteredTasks();
        const filteredJobCount = new Set(filteredTasks.map((task) => boardJobForTask(task)?.id || task.job)).size;
        const adminWorkView = Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin);
        $('#page-mywork').innerHTML = `<div class="ops-board-page ops-mode-tasks my-work-board"><div class="page-head"><div><h1>My Work</h1><p>${adminWorkView ? 'All visible job-based task work for admin review' : 'Task board for your assigned work only'}</p><span class="my-work-owner-note"><span class="avatar">${html(currentUser?.initials || userInitials(currentUser?.name || 'ME'))}</span>${html(currentUser?.name || 'Current user')}${adminWorkView ? ' · Admin view' : ''}</span></div><div class="actions"><button class="ghost" id="myCalendar">Calendar</button><button class="primary" data-go="board">Open full board</button></div></div>
        ${myWorkToolbar(filteredTasks.length, filteredJobCount)}
        <div id="myWorkKanbanArea"></div></div>`;

        renderMyWorkBoard();
        $('#myWorkBoardSearch')?.addEventListener('input', (event) => {
            const value = event.target.value;
            const caret = event.target.selectionStart ?? value.length;
            myWorkSearchValue = value;
            renderMyWork();
            requestAnimationFrame(() => {
                const input = $('#myWorkBoardSearch');
                if (!input) return;
                input.focus({ preventScroll: true });
                input.setSelectionRange(caret, caret);
            });
        });
        $$('[data-mywork-filter]').forEach((select) => select.addEventListener('change', () => { myWorkFilters[select.dataset.myworkFilter] = select.value; renderMyWork(); }));
        $$('[data-mywork-quick]').forEach((button) => button.addEventListener('click', () => { myWorkQuickFilter = myWorkQuickFilter === button.dataset.myworkQuick ? 'all' : button.dataset.myworkQuick; renderMyWork(); }));
        $('#myWorkBoardClear')?.addEventListener('click', () => { myWorkSearchValue = ''; myWorkQuickFilter = 'all'; myWorkFilters = { job: '', client: '', status: '', priority: '', due: '' }; renderMyWork(); });
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
        const phaseData = flowtrackToArray(phaseMap).map(([label, value]) => ({ label, value }));
        const workloadMap = new Map();
        tasks.filter((task) => !taskIsCompleted(task)).forEach((task) => workloadMap.set(task.assignee || 'Unassigned', (workloadMap.get(task.assignee || 'Unassigned') || 0) + 1));
        const workload = flowtrackToArray(workloadMap).map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value).slice(0, 10);
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
        const ownOpenTasks = tasks.filter((task) => !taskIsCompleted(task) && ((isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin) || taskAssignedToCurrentUser(task)));
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
                <div class="field"><label>Required document</label><select data-pack-field="document_category_id" data-pack-index="${index}"><option value="">No task-specific file</option>${documentCategories.map((category) => `<option value="${category.id}" ${Number(item.document_category_id) === Number(category.id) ? 'selected' : ''}>${html(category.name)}</option>`).join('')}</select><div class="inline-help">The file must be attached to the Job before this task can be completed.</div></div>
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
                    const result = await api(existing ? `/flowtrack/template-api/task-packs/${existing.id}` : '/flowtrack/template-api/task-packs', {
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
                    const savedTaskPack = upsertTaskPack(result?.taskPack);
                    if (!savedTaskPack) throw new Error('The Task Pack was saved, but the server returned an invalid response.');

                    closeAll();
                    renderTaskPackSetup();
                    showToast(existing ? 'Task Pack updated successfully.' : 'Task Pack and its task sequence created successfully.');

                    // Refresh related generated tasks and workflow data without hiding the
                    // authoritative Task Pack that was just returned by the save request.
                    void loadBootstrap()
                        .then(() => {
                            upsertTaskPack(savedTaskPack);
                            if (currentPage === 'taskpacksetup') renderTaskPackSetup();
                        })
                        .catch(() => {
                            // The local save response is already authoritative; a temporary
                            // refresh failure must not make the new Task Pack disappear.
                        });
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
        <div id="taskPackSetupContent"><div class="pack-list">${taskPacks.map((pack) => `<div class="card pack"><div class="section-head"><div><h4>${html(pack.name)} Task Pack</h4><div class="small muted">${html(pack.code)} · ${(pack.items || []).length} predefined tasks · ${pack.active ? 'Active' : 'Inactive'}</div></div><button class="mini-btn" data-edit-pack-id="${pack.id}">Edit</button></div><div class="small muted">${html(pack.description || 'No description')}</div><ul class="pack-tasks">${(pack.items || []).map((item, index) => `<li><div><span>${index + 1}. ${html(item.title)}</span><div class="small muted" style="margin-top:3px">${html(item.default_assignee_name || item.default_department_name || 'Job coordinator')} · Due set from Task details${item.priority_name ? ` · ${html(item.priority_name)}` : ''}${item.document_category_name ? ` · Required file: ${html(item.document_category_name)}` : ''}</div></div><span class="badge ${item.is_required ? 'b-blue' : 'b-gray'}">${item.is_required ? 'Mandatory' : 'Optional'}</span></li>`).join('') || '<li><span class="muted">No tasks configured</span></li>'}</ul></div>`).join('') || '<div class="card empty-state">No Task Packs have been created.</div>'}</div></div>`;
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
        <div class="field full"><label>Attachments</label><div class="task-document-attachment-card job-create-attachment-card"><div class="task-attachment-template-head"><div class="task-attachment-icon">▧</div><div><h3>Document attachment</h3><p>Optional files for this new Job</p></div><span class="badge b-blue">Attachment</span></div><div id="newJobAttachmentDrop" class="task-attachment-dropzone job-attachment-drop" tabindex="0"><input id="newJobAttachments" class="task-document-file-input" type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.webp,.zip,.txt,.csv"><div class="task-upload-cloud">☁↑</div><b>Drag and drop your job documents here</b><span>or</span><div class="task-upload-actions"><button type="button" class="primary">Browse files</button></div><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Maximum 20 MB</small></div><div class="task-attachment-info"><span>ⓘ</span><b>The files will be attached when you save this Job.</b></div><div id="newJobAttachmentList" class="job-attachment-list"></div></div></div>
        </div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="secondary" id="saveDraftJob">Save Draft</button><button class="primary" id="createJob">Create</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));

        let productRows = [{ product_category_id: '', product_id: '', quantity: 1000 }];
        let attachmentFiles = [];
        const attachmentLimit = 10;
        const maxAttachmentBytes = 20 * 1024 * 1024;
        const supportedAttachmentExtensions = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','webp','zip','txt','csv'];
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
            $('#newJobAttachmentList').innerHTML = attachmentFiles.map((file, index) => `<div class="job-attachment-row"><div><b>${html(file.name)}</b><div class="small muted">${formatFileSize(file.size)}</div></div><button type="button" data-remove-attachment="${index}">Remove</button></div>`).join('');
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
                if (next.length >= attachmentLimit) {
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
        attachmentDrop.addEventListener('click', () => attachmentInput.click());
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
            attachmentDrop.classList.add('dragging');
        }));
        ['dragleave', 'drop'].forEach((name) => attachmentDrop.addEventListener(name, (event) => {
            event.preventDefault();
            attachmentDrop.classList.remove('dragging');
        }));
        attachmentDrop.addEventListener('drop', (event) => addAttachments(event.dataTransfer?.files));
        $('#newJobAttachmentList').addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-attachment]');
            if (!button) return;
            attachmentFiles.splice(Number(button.dataset.removeAttachment), 1);
            renderAttachments();
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
                const attachmentText = attachmentFiles.length ? ` and ${attachmentFiles.length} attachment${attachmentFiles.length === 1 ? '' : 's'}` : '';
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

    const recordPagePolishStyles = document.createElement('style');
    recordPagePolishStyles.textContent = `
        .standalone-record-host{background:#f5f7fb!important}.standalone-record-page{max-width:1280px!important;margin:0 auto!important;padding:0 0 30px!important}.standalone-record-head{align-items:center!important;margin:0 0 12px!important}.standalone-record-title h1{font-size:22px!important;font-weight:720!important;letter-spacing:-.018em!important}.standalone-record-title p{font-size:12px!important}.standalone-record-shell{border-radius:16px!important;box-shadow:0 8px 22px rgba(15,23,42,.045)!important;border-color:#dde6f2!important}.standalone-record-content .drawer-head,.standalone-record-content .modal-head{padding:16px 18px!important;align-items:flex-start!important;gap:14px!important}.standalone-record-content .drawer-head h2,.standalone-record-content .modal-head h2{font-size:21px!important;font-weight:720!important;line-height:1.15!important}.standalone-record-content .drawer-body,.standalone-record-content .modal-body{padding:18px!important}.standalone-record-content .detail-tabs{margin:0 0 16px!important;padding:0 4px!important;border-bottom:1px solid #e2e8f0!important;gap:2px!important}.standalone-record-content .detail-tab{padding:9px 13px!important;border-radius:9px 9px 0 0!important;font-size:13px!important;font-weight:620!important;color:#334155!important}.standalone-record-content .detail-tab.active{background:#edf4ff!important;color:#1d5fd8!important;border-color:#2563eb!important}.standalone-record-content .summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:10px!important}.standalone-record-content .summary-box{padding:12px!important;border-radius:12px!important;background:#fff!important}.standalone-record-content .summary-box span{font-size:11px!important}.standalone-record-content .summary-box b{font-size:15px!important;font-weight:700!important}.standalone-record-content .section-head{align-items:center!important;margin-bottom:10px!important}.standalone-record-content .section-head h3{font-size:18px!important;font-weight:720!important}.standalone-record-content .attention-item{border-radius:12px!important;padding:11px 12px!important}.standalone-record-content .modal-foot{justify-content:flex-end!important;gap:10px!important}.standalone-record-content .modal-foot button,.standalone-record-content .modal-foot .primary,.standalone-record-content .modal-foot .secondary,.standalone-record-content .modal-foot .ghost{flex:0 0 auto!important;min-width:148px!important;width:auto!important;height:42px!important;white-space:nowrap!important}.standalone-save-actions{justify-content:flex-end!important;gap:10px!important;align-items:center!important}.standalone-save-actions button{flex:0 0 168px!important;width:168px!important;height:42px!important;min-height:42px!important;padding:0 16px!important}.standalone-save-actions #saveTaskUpdate{flex:0 0 168px!important;width:168px!important}.job-doc-list{display:grid;gap:10px}.job-doc-row{display:grid;grid-template-columns:42px minmax(0,1fr) auto;gap:12px;align-items:center;border:1px solid #dfe7f1;border-radius:12px;background:#fff;padding:12px;cursor:pointer}.job-doc-row:hover{border-color:#b9cae3;background:#fbfdff}.job-doc-main b{font-size:14px;font-weight:700;color:#172033}.job-doc-meta{font-size:12px;color:#64748b;margin-top:4px}.job-doc-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.job-doc-empty{border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc;padding:18px;text-align:center;color:#64748b}.job-detail-doc-summary{margin-bottom:13px;color:#64748b;font-size:12px}.workflow-card-clean{padding:14px!important}.workflow-readiness-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}.workflow-readiness-grid .check-row{border:1px solid #e2e8f0;border-radius:10px;padding:10px;background:#fff}.standalone-record-content .timeline{border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc;padding:14px 10px 18px}.standalone-record-content .step-label{font-size:11px;line-height:1.35}.standalone-record-content .job-header-actions{display:flex!important;justify-content:flex-end!important;align-items:center!important;gap:8px!important}.standalone-record-content .job-header-actions button{height:40px!important;min-width:128px!important}.standalone-record-content .task-head-actions{margin-left:auto!important;display:flex!important;align-items:center!important;gap:8px!important}.standalone-record-form .modal-foot{position:sticky!important;bottom:0!important;background:rgba(255,255,255,.98)!important}.standalone-record-form .job-form-stack,.standalone-record-form .client-form-stack{max-width:980px!important}.standalone-record-form .modal-body{padding-bottom:12px!important}.standalone-record-form .field{margin-bottom:10px!important}.standalone-record-form .field label{font-weight:650!important}.standalone-record-content .task-document-upload{border-radius:12px!important;background:#f8fbff!important;border:1px solid #dbeafe!important;padding:13px!important}.standalone-record-content .task-document-upload button{width:auto!important;min-width:188px!important;height:42px!important}
        @media(max-width:900px){.standalone-record-content .summary-grid,.workflow-readiness-grid{grid-template-columns:1fr!important}.job-doc-row{grid-template-columns:36px minmax(0,1fr)}.job-doc-actions{grid-column:1/-1;justify-content:flex-start}.standalone-save-actions button{flex:1 1 0!important;width:auto!important}.standalone-record-content .modal-foot button{min-width:0!important}.standalone-record-content .detail-tab{font-size:12px!important;padding:8px 10px!important}}
    `;
    document.head.appendChild(recordPagePolishStyles);

    function jobDocsFor(job) {
        return (docs || [])
            .filter((documentRecord) => String(documentRecord.job || '') === String(job?.id || ''))
            .slice()
            .sort((left, right) => String(right.date || '').localeCompare(String(left.date || '')) || String(right.id || '').localeCompare(String(left.id || '')));
    }

    function documentMatchesKeywords(documentRecord, keywords) {
        const haystack = normalizedStatusValue([
            documentRecord.type,
            documentRecord.name,
            documentRecord.phase,
            documentRecord.taskRequirement,
            documentRecord.categoryName,
            documentRecord.version,
        ].join(' '));
        return keywords.some((keyword) => haystack.includes(normalizedStatusValue(keyword)));
    }

    function currentDocumentCardsHtml(documentRows, emptyLabel) {
        if (!documentRows.length) return `<div class="job-doc-empty">${html(emptyLabel || 'No documents uploaded yet.')}</div>`;
        const seen = new Set();
        const rows = documentRows.filter((documentRecord) => {
            const key = String(documentRecord.dbId || documentRecord.id || documentRecord.name || Math.random());
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
        return `<div class="job-doc-list">${rows.map((documentRecord) => {
            const preview = documentRecord.previewUrl ? `<a class="mini-btn" href="${html(documentRecord.previewUrl)}" target="_blank" rel="noopener" onclick="event.stopPropagation()">Preview</a>` : '';
            const download = documentRecord.downloadUrl ? `<a class="mini-btn" href="${html(documentRecord.downloadUrl)}" onclick="event.stopPropagation()">Download</a>` : '';
            return `<div class="job-doc-row" data-doc="${html(documentRecord.id)}"><div class="doc-icon" style="width:40px;height:40px">FILE</div><div class="job-doc-main"><b>${html(documentRecord.name || 'Document')}</b><div class="job-doc-meta">${html(documentRecord.type || 'Document')} · ${html(documentRecord.version || 'Current')} · ${documentRecord.date ? formatDate(documentRecord.date) : 'No date'} · ${html(documentRecord.uploadedBy || 'System')}</div>${documentRecord.phase ? `<div class="job-doc-meta">${html(documentRecord.phase)}${documentRecord.taskRequirement ? ` · ${html(documentRecord.taskRequirement)}` : ''}</div>` : ''}</div><div class="job-doc-actions">${documentRecord.final ? '<span class="badge b-green">Final</span>' : '<span class="badge b-gray">Current</span>'}${preview}${download}</div></div>`;
        }).join('')}</div>`;
    }

    function documentPanelFor(job, label, keywords) {
        const matching = jobDocsFor(job).filter((documentRecord) => documentMatchesKeywords(documentRecord, keywords));
        return `<div class="section-head"><div><h3>${html(label)}</h3><div class="job-detail-doc-summary">Showing the latest uploaded version for each ${html(label.toLowerCase())} document linked to this Job.</div></div><button class="primary" data-upload-job="${html(job.id)}">＋ Upload</button></div>${currentDocumentCardsHtml(matching, `No ${label.toLowerCase()} document has been uploaded for this Job yet.`)}`;
    }

    function jobCurrentPhaseTasks(job, jobTasks) {
        const current = (typeof boardJobPhaseTasks === 'function') ? boardJobPhaseTasks(job) : [];
        if (current.length) return current;
        const currentPhaseName = normalizedStatusValue(phaseNameFor(job));
        return jobTasks.filter((task) => Number(task.phaseId) === Number(job.phaseId) || normalizedStatusValue(task.phase || '') === currentPhaseName);
    }

    function flowtrackFindJob(jobId) {
        return jobs.find((item) => String(item.id) === String(jobId) || Number(item.dbId) === Number(jobId));
    }

    function flowtrackActivePhases(job) {
        const workflow = getWorkflow(job?.workflow || 'standard') || { phases: [] };
        const excludedPhaseIds = new Set((job?.excludedPhaseIds || []).map((phaseId) => Number(phaseId)));
        return (workflow.phases || [])
            .filter((phase) => phase.active !== false && !excludedPhaseIds.has(Number(phase.id)))
            .slice()
            .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
    }

    function flowtrackPhaseFor(job, phaseId) {
        const activePhases = flowtrackActivePhases(job);
        return activePhases.find((phase) => Number(phase.id) === Number(phaseId))
            || activePhases.find((phase) => Number(phase.sequence) === Number(phaseId))
            || activePhases[0]
            || null;
    }

    function flowtrackTasksForJob(job) {
        return flowtrackVisibleTaskRows(tasks).filter((task) => String(task.job) === String(job?.id || '') || Number(task.jobDbId) === Number(job?.dbId || 0));
    }

    function flowtrackTasksForPhase(job, phaseId, sourceTasks = null) {
        const phase = flowtrackPhaseFor(job, phaseId);
        const all = Array.isArray(sourceTasks) ? sourceTasks : flowtrackTasksForJob(job);
        if (!phase) return all;
        const phaseName = normalizedStatusValue(phase.name || phase.short || '');
        return all.filter((task) => Number(task.phaseId) === Number(phase.id) || normalizedStatusValue(task.phase || '') === phaseName);
    }

    function flowtrackFormatDateTime(value) {
        if (!value) return 'Not recorded';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString([], { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function flowtrackPhaseHistoryFor(job, phase) {
        const rows = Array.isArray(job?.phaseHistory) ? job.phaseHistory : [];
        return rows.find((row) => Number(row.phaseId) === Number(phase?.id))
            || rows.find((row) => Number(row.phaseIndex) === Math.max(0, Number(phase?.sequence || 1) - 1))
            || null;
    }

    function flowtrackPhaseTimelineStep(job, phase, index, currentPhase, skippedIndexes) {
        const workflow = getWorkflow(job?.workflow || 'standard') || { phases: [] };
        const originalIndex = (workflow.phases || []).findIndex((item) => Number(item.id) === Number(phase.id));
        const skippedPhase = skippedIndexes.includes(originalIndex);
        const beforeCurrent = currentPhase ? Number(phase.sequence) < Number(currentPhase.sequence) : index < Number(job?.phase || 0);
        const isCurrent = Number(phase.id) === Number(job?.phaseId) || (!job?.phaseId && index === Number(job?.phase || 0));
        const state = skippedPhase ? 'skipped' : beforeCurrent ? 'done' : isCurrent ? 'current' : '';
        const dot = skippedPhase ? '↷' : beforeCurrent ? '✓' : Number(phase.sequence || index + 1);
        return `<button type="button" class="step workflow-phase-step ${state}" data-flow-phase="${html(phase.id)}" data-flow-job="${html(job?.id || '')}" title="View ${html(phase.name)} task history"><div class="step-dot">${dot}</div><div class="step-label">${html(phase.name)}${skippedPhase ? '<br><small>Skipped / external</small>' : ''}</div></button>`;
    }

    function flowtrackRenderPhaseActivityEvent(task, entry) {
        const occurredAt = entry.occurredAt ? flowtrackFormatDateTime(entry.occurredAt) : html(entry.time || 'Not recorded');
        const changes = Array.isArray(entry.changes) ? entry.changes : [];
        const changeRows = changes.length
            ? `<ul class="task-change-list phase-change-list">${changes.map((change) => `<li><b>${html(change.field || 'Update')}:</b> ${html(change.from || '—')} → ${html(change.to || '—')}</li>`).join('')}</ul>`
            : '';
        const fileRow = entry.fileName
            ? `<div class="phase-event-detail"><span>Uploaded file</span><b>${html(entry.fileName)}</b></div>`
            : '';
        const commentRow = entry.commentAdded
            ? '<div class="phase-event-detail"><span>Comment</span><b>Comment added with this update</b></div>'
            : '';
        const requirementRow = entry.documentRequirement
            ? `<div class="phase-event-detail"><span>Document requirement</span><b>${html(entry.documentRequirement)}</b></div>`
            : '';
        const eventLabel = entry.eventLabel || String(entry.event || 'Task update').replace(/[._-]/g, ' ');
        const completionBadge = entry.isCompletionEvent
            ? '<span class="badge b-green">Completion record</span>'
            : `<span class="badge b-gray">${html(eventLabel)}</span>`;

        return `<div class="phase-history-event ${entry.isCompletionEvent ? 'is-completion' : ''}">
            <span class="avatar">${html(entry.actorInitials || userInitials(entry.actor || 'System'))}</span>
            <div class="phase-event-body">
                <div class="phase-event-head"><b>${html(entry.actor || 'System')}</b>${completionBadge}</div>
                <div class="phase-event-description">${html(entry.description || `Updated ${task.id}`)}</div>
                <div class="phase-event-grid">
                    <div class="phase-event-detail"><span>Task</span><b>${html(task.id)} · ${html(task.title || '')}</b></div>
                    <div class="phase-event-detail"><span>Time</span><b>${occurredAt}</b></div>
                    <div class="phase-event-detail"><span>Status now</span><b>${html(task.status || 'Not recorded')}</b></div>
                    ${fileRow}${commentRow}${requirementRow}
                </div>
                ${changeRows}
            </div>
        </div>`;
    }

    function flowtrackRenderPhaseHistoryPanel(job, phaseId, sourceTasks = null) {
        const phase = flowtrackPhaseFor(job, phaseId);
        if (!phase) return '<div class="empty-state">No workflow phase selected.</div>';
        const phaseTasks = flowtrackTasksForPhase(job, phase.id, sourceTasks).map((task) => flowtrackHydrateTaskAssignee(task));
        const history = flowtrackPhaseHistoryFor(job, phase);
        const completedTasks = phaseTasks.filter((task) => taskIsCompleted(task));
        const taskRows = phaseTasks.map((task) => {
            const completed = taskIsCompleted(task);
            const latestCompletion = (task.history || []).find((entry) => entry.isCompletionEvent) || null;
            const assigneeId = flowtrackResolveTaskAssigneeId(task);
            const assigneeName = flowtrackResolveTaskAssigneeName(task, assigneeId);
            const dueValue = task.due || task.dueDate || '';
            const uploadedFile = latestCompletion?.fileName ? ` · Uploaded: ${html(latestCompletion.fileName)}` : '';
            const completedMeta = completed
                ? `${html(task.completedBy || latestCompletion?.actor || assigneeName || 'System')} · ${flowtrackFormatDateTime(task.completedAt || latestCompletion?.occurredAt)}${uploadedFile}`
                : `${html(assigneeName || 'Unassigned')} · ${dueValue ? formatDate(dueValue) : 'No due date'}`;
            return `<div class="phase-history-task-row" data-task="${html(task.id)}"><span class="signal ${completed ? 'green' : task.attention ? 'red' : 'amber'}"></span><div><b>${html(task.title)}</b><div class="small muted" style="margin-top:3px">${completed ? 'Completed by ' : 'Assigned to '}${completedMeta}</div></div>${statusBadge(task.status)}</div>`;
        }).join('');
        const taskChangeRows = phaseTasks.flatMap((task) => (task.history || []).map((entry) => ({ task, entry })))
            .sort((left, right) => String(right.entry.occurredAt || '').localeCompare(String(left.entry.occurredAt || '')))
            .map(({ task, entry }) => flowtrackRenderPhaseActivityEvent(task, entry))
            .join('');
        return `<div class="phase-history-panel" data-current-phase="${html(phase.id)}"><div class="section-head"><div><h3>${html(phase.name)} Task History</h3><div class="small muted" style="margin-top:4px">Click a task to open its full update and change log.</div></div><span class="badge ${history?.status === 'completed' ? 'b-green' : history?.status === 'skipped' ? 'b-amber' : Number(phase.id) === Number(job.phaseId) ? 'b-blue' : 'b-gray'}">${html(history?.statusLabel || (Number(phase.id) === Number(job.phaseId) ? 'Current' : 'Pending'))}</span></div><div class="phase-history-summary"><div><span>Entered</span><b>${flowtrackFormatDateTime(history?.enteredAt)}</b></div><div><span>Completed</span><b>${flowtrackFormatDateTime(history?.completedAt)}</b></div><div><span>Completed Tasks</span><b>${completedTasks.length}/${phaseTasks.length}</b></div><div><span>Last Actor</span><b>${html(history?.changedBy || 'System')}</b></div></div><div class="phase-history-task-list">${taskRows || '<div class="empty-state">No tasks were generated for this phase yet.</div>'}</div><div class="section-head" style="margin-top:16px"><div><h3>Phase Activity</h3><div class="small muted" style="margin-top:4px">Full audit of task updates, completions, document uploads, comments, actor and time for this phase.</div></div></div><div class="phase-history-events">${taskChangeRows || '<div class="empty-state">No task history has been recorded for this phase yet.</div>'}</div></div>`;
    }

    function flowtrackOpenPhaseHistory(jobId, phaseId, root = document) {
        const job = flowtrackFindJob(jobId);
        if (!job) return;
        const scope = root?.closest?.('.drawer-body') || root?.closest?.('.standalone-record-content') || root || document;
        const panel = scope.querySelector('#flowtrackPhaseHistoryPanel');
        if (panel) panel.innerHTML = flowtrackRenderPhaseHistoryPanel(job, phaseId);
        scope.querySelectorAll('[data-flow-phase]').forEach((button) => button.classList.toggle('selected', Number(button.dataset.flowPhase) === Number(phaseId)));
        bindCommon();
    }

    async function flowtrackRefreshJobTasks(job) {
        if (!job?.dbId) return null;
        flowtrackSetLoadingProgress(22, 'Loading latest phase tasks…');
        const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/tasks`);
        flowtrackSetLoadingProgress(72, 'Applying task setup assignments…');
        if (result.job) {
            const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
            if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
        }
        if (result.job && Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
        flowtrackFinishLoadingProgress('Phase tasks updated');
        return result;
    }

    function openJobTaskModal(jobId, phaseId = null) {
        const job = flowtrackFindJob(jobId);
        if (!job) return;
        const activePhases = flowtrackActivePhases(job);
        const defaultPhase = flowtrackPhaseFor(job, phaseId || job.phaseId) || activePhases[0];
        const phaseOptions = activePhases.map((phase) => `<option value="${html(phase.id)}" ${Number(phase.id) === Number(defaultPhase?.id) ? 'selected' : ''}>${html(phase.name)}</option>`).join('');
        const priorityOptions = (masterData.priorities || [])
            .filter((priority) => String(priority.status || 'Active').toLowerCase() === 'active')
            .map((priority) => `<option value="${html(priority.id)}" ${Number(priority.id) === Number(job.priorityId) ? 'selected' : ''}>${html(priority.name)}</option>`)
            .join('');
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Add Sub Task</h2><div class="small muted">${html(job.id)} · ${html(job.title || '')}</div></div><button class="close-btn" data-close>×</button></div><div class="modal-body"><div class="form-grid"><div class="field full"><label>Sub task title *</label><input id="newJobTaskTitle" maxlength="255" placeholder="e.g. Verify client artwork version"></div><div class="field full"><label>Description</label><textarea id="newJobTaskDescription" rows="3" maxlength="5000" placeholder="Instruction, expected result, or handover details…"></textarea></div><div class="field"><label>Workflow phase *</label><select id="newJobTaskPhase">${phaseOptions}</select></div><div class="field"><label>Assignee</label><select id="newJobTaskAssignee"><option value="">Unassigned</option>${users.map((user) => `<option value="${html(user.id)}">${html(user.name)}</option>`).join('')}</select></div><div class="field"><label>Priority</label><select id="newJobTaskPriority"><option value="">Use job priority</option>${priorityOptions}</select></div><div class="field"><label>Due date</label><input type="date" id="newJobTaskDueDate"></div><div class="field full"><label>Checklist items</label><textarea id="newJobTaskChecklist" rows="3" placeholder="One checklist item per line"></textarea></div></div></div><div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="createJobSubTask">Create Sub Task</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        $('#createJobSubTask')?.addEventListener('click', async () => {
            const title = $('#newJobTaskTitle')?.value.trim();
            if (!title) { showToast('Enter a sub task title.'); $('#newJobTaskTitle')?.focus(); return; }
            const button = $('#createJobSubTask');
            button.disabled = true;
            button.textContent = 'Creating…';
            try {
                const checklist = String($('#newJobTaskChecklist')?.value || '').split('\n').map((line) => line.trim()).filter(Boolean);
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/tasks`, {
                    method: 'POST',
                    body: {
                        workflow_phase_id: Number($('#newJobTaskPhase')?.value || defaultPhase?.id || job.phaseId),
                        title,
                        description: $('#newJobTaskDescription')?.value.trim() || null,
                        assignee_id: $('#newJobTaskAssignee')?.value ? Number($('#newJobTaskAssignee').value) : null,
                        priority_id: $('#newJobTaskPriority')?.value ? Number($('#newJobTaskPriority').value) : null,
                        due_date: $('#newJobTaskDueDate')?.value || null,
                        checklist,
                    },
                });
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                    else if (result.task) flowtrackUpsertRecord(tasks, result.task);
                }
                closeAll();
                renderPage(currentPage);
                updateBadges();
                showToast(result.message || 'Sub task created successfully.');
                setTimeout(() => {
                    openJob(result.job?.id || job.id);
                    setTimeout(() => {
                        const page = document.querySelector(`#page-record-job-${flowtrackRecordSlug(result.job?.id || job.id)} .standalone-record-content`);
                        if (page) {
                            activateScopedDetailTab(page, 2);
                            flowtrackOpenPhaseHistory(result.job?.id || job.id, $('#newJobTaskPhase')?.value || defaultPhase?.id, page);
                        }
                    }, 40);
                }, 30);
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Create Sub Task';
            }
        });
    }

    function compactJobTaskRows(taskRows, emptyLabel = 'No Tasks are configured for this phase yet.') {
        return taskRows.map((task) => `<button type="button" class="attention-item subtask-row-button" data-task="${html(task.id)}"><span class="signal ${task.attention ? 'red' : taskIsCompleted(task) ? 'green' : 'amber'}"></span><div><div class="item-title">${html(task.title)}</div><div class="item-meta">${html(task.assignee || 'Unassigned')} · ${task.due ? formatDate(task.due) : 'No due date'} · ${Number(task.progress || 0)}% · ${html(task.phase || '')}</div></div>${statusBadge(task.status)}</button>`).join('') || `<div class="empty-state">${html(emptyLabel)}</div>`;
    }

    const flowtrackOriginalJobPanels = jobPanels;
    jobPanels = function (j, jt, jd) {
        const wf = getWorkflow(j.workflow || 'standard') || { phases: [], name: 'Workflow' };
        const skipped = j.skippedPhases || [];
        const activity = j.activity || [];
        const currentTasks = jobCurrentPhaseTasks(j, jt || []);
        const openCurrentTasks = currentTasks.filter((task) => !taskIsCompleted(task));
        const activePhases = (wf.phases || []).filter((phase) => phase.active !== false && !(j.excludedPhaseIds || []).map(Number).includes(Number(phase.id))).slice().sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(j.phaseId));
        const totalQty = Number(j.qty || jobProductItems(j).reduce((sum, item) => sum + Number(item.qty || 0), 0));
        const allJobDocs = jobDocsFor(j);
        const latestUpdate = activity[0];
        const readiness = j.phaseReadinessDetails || {};
        return `<section id="detailPanel0" class="detail-panel active"><div class="summary-grid"><div class="summary-box"><span>Current Phase</span><b>${html(phaseNameFor(j))}</b></div><div class="summary-box"><span>Next Required Action</span><b>${html(j.next || 'Review current phase')}</b></div><div class="summary-box"><span>Delivery Deadline</span><b class="${j.delivery < today ? 'due overdue' : ''}">${formatDate(j.delivery)}</b></div><div class="summary-box"><span>Workflow Template</span><b>${html(wf.name || 'Workflow')}</b></div><div class="summary-box"><span>Job Started At</span><b>${html(phaseNameFor(j, j.createdFromPhase ?? 0))}</b></div><div class="summary-box"><span>Commercial Value</span><b>${j.price ? currency(j.price, j.currency) : 'Not recorded'}</b></div></div>${j.requestDescription ? `<div class="source-note" style="margin-top:14px"><b>Request description</b><div class="small muted" style="margin-top:5px;white-space:pre-wrap">${safeHtml(j.requestDescription)}</div></div>` : ''}${jobProductSummary(j)}${skipped.length ? `<div class="source-note"><b>Started after earlier phases</b><div class="small muted" style="margin-top:4px">${skipped.length} phase${skipped.length === 1 ? ' was' : 's were'} recorded as “${html(j.startHandling || 'Skipped')}”. ${html(j.startReason || '')}</div></div>` : ''}<div style="margin-top:16px"><div class="section-head"><h3>Overall Progress</h3><b>${Number(j.progress || 0)}%</b></div><div class="progress" style="height:9px"><span class="${Number(j.progress || 0) >= 100 ? 'complete' : ''}" style="width:${Math.max(0, Math.min(100, Number(j.progress || 0)))}%"></span></div></div><div class="card" style="margin-top:16px;padding:13px;background:#f8faff"><b>Latest update</b><div class="muted" style="margin-top:5px">${latestUpdate ? `${html(latestUpdate.title || 'Updated')}${latestUpdate.text ? ` · ${html(latestUpdate.text)}` : ''} · ${html(latestUpdate.time || '')}` : `${html(j.next || 'No update recorded yet.')}`}</div></div><div class="section-head" style="margin-top:18px"><h3>Current Phase Tasks</h3><button class="link-btn" data-open-job-tasks>View all</button></div>${compactJobTaskRows(openCurrentTasks.slice(0, 5), 'No open tasks in the current phase.')}</section>
<section id="detailPanel1" class="detail-panel"><div class="timeline clickable-workflow-timeline">${activePhases.map((phase, index) => flowtrackPhaseTimelineStep(j, phase, index, currentPhase, skipped)).join('') || '<div class="empty-state">No workflow phases configured.</div>'}</div><div id="flowtrackPhaseHistoryPanel" style="margin-top:14px">${flowtrackRenderPhaseHistoryPanel(j, currentPhase?.id || activePhases[0]?.id, jt || [])}</div>${skipped.length ? `<div class="skip-warning"><b>Historical phase handling</b><div style="margin-top:5px">${html(j.startHandling || '')}: ${html(j.startReason || '')}</div></div>` : ''}<div class="card section-card workflow-card-clean" style="margin-top:14px"><div class="section-head"><h3>Current Phase Controls</h3><div><span class="badge b-blue">${html(phasePackFor(j.workflow || 'standard', j.phase) || 'No Task Pack')}</span> <span class="badge ${j.phaseReadiness === 'ready' ? 'b-green' : j.phaseReadiness === 'blocked' ? 'b-red' : 'b-amber'}">${html(j.phaseReadinessLabel || 'In Progress')}</span></div></div><div class="source-note"><b>Phase readiness</b><div class="small muted" style="margin-top:4px">${html((readiness.blockers || []).join(' ') || 'All configured task, document and blocker gates are satisfied.')}</div></div><div class="workflow-readiness-grid"><label class="check-row"><input type="checkbox" disabled ${readiness.task_gate?.met ? 'checked' : ''}> <span>Required tasks completed (${Number(readiness.task_gate?.completed || 0)}/${Number(readiness.task_gate?.required || 0)})</span></label>${readiness.document_gate?.required ? `<label class="check-row"><input type="checkbox" disabled ${readiness.document_gate?.met ? 'checked' : ''}> <span>Required document(s) uploaded</span></label>` : ''}</div><div class="actions standalone-save-actions" style="margin-top:14px"><button class="secondary" data-open-job-tasks>View Phase Tasks</button>${j.phase < (wf.phases || []).length - 1 ? `<button class="primary" data-next-phase="${html(j.id)}" ${j.phaseReadiness === 'ready' ? '' : 'disabled'}>${j.phaseReadiness === 'ready' ? 'Complete Phase' : 'Complete Required Work'}</button>` : '<span class="badge b-green">Workflow completed</span>'}</div></div></section>
<section id="detailPanel2" class="detail-panel"><div class="section-head"><div><h3>Current Phase Tasks</h3><div class="small muted" style="margin-top:4px">${html(currentPhase?.name || phaseNameFor(j) || 'Current phase')}</div></div><button class="primary" data-add-task="${html(j.id)}">＋ Add Task</button></div>${compactJobTaskRows(currentTasks, `No Tasks are configured for ${currentPhase?.name || 'the current phase'} yet.`)}</section>
<section id="detailPanel3" class="detail-panel">${documentPanelFor(j, 'Quotation', ['quotation', 'quote', 'rfq', 'pricing', 'commercial'])}</section>
<section id="detailPanel4" class="detail-panel">${documentPanelFor(j, 'Artwork', ['artwork', 'art proof', 'proof', 'design', 'logo'])}</section>
<section id="detailPanel5" class="detail-panel"><div class="summary-grid"><div class="summary-box"><span>Quantity Ordered</span><b>${totalQty.toLocaleString()} units</b></div><div class="summary-box"><span>Progress</span><b>${Number(j.progress || 0)}%</b></div><div class="summary-box"><span>Current Phase</span><b>${html(phaseNameFor(j))}</b></div></div><div style="margin-top:18px">${documentPanelFor(j, 'Production Documents', ['production', 'inspection', 'quality', 'packing', 'sample', 'proof'])}</div></section>
<section id="detailPanel6" class="detail-panel"><div class="summary-grid"><div class="summary-box"><span>Delivery Deadline</span><b class="${j.delivery < today ? 'due overdue' : ''}">${formatDate(j.delivery)}</b></div><div class="summary-box"><span>Shipment Phase</span><b>${html(phaseNameFor(j))}</b></div><div class="summary-box"><span>Status</span><b>${html(j.status || 'Not started')}</b></div></div><div style="margin-top:18px">${documentPanelFor(j, 'Shipment Documents', ['shipment', 'shipping', 'delivery', 'tracking', 'packing list', 'bill of lading', 'air waybill'])}</div></section>
<section id="detailPanel7" class="detail-panel"><div class="summary-grid"><div class="summary-box"><span>Invoice Amount</span><b>${j.price ? currency(j.price, j.currency) : 'Not recorded'}</b></div><div class="summary-box"><span>Commercial Status</span><b>${html(boardJobCommercialLabel(j))}</b></div><div class="summary-box"><span>Client</span><b>${html(clientName(j.client))}</b></div></div><div style="margin-top:18px">${documentPanelFor(j, 'Invoice Documents', ['invoice', 'payment', 'receipt', 'commercial'])}</div></section>
<section id="detailPanel8" class="detail-panel"><div class="section-head"><div><h3>Documents</h3><div class="job-detail-doc-summary">All files currently linked to this Job.</div></div><button class="primary" data-upload-job="${html(j.id)}">＋ Upload</button></div>${currentDocumentCardsHtml(allJobDocs, 'No documents have been uploaded for this Job yet.')}</section>
<section id="detailPanel9" class="detail-panel">${activity.map((a) => `<div class="comment"><span class="avatar">${userInitials(a.actor || 'System')}</span><div><b>${html(a.title || 'Activity')}</b><div style="margin-top:4px">${html(a.text || '')}</div><div class="small muted" style="margin-top:4px">${html(a.actor || 'System')} · ${html(a.time || '')}</div></div></div>`).join('') || '<div class="empty-state">No Activity recorded yet.</div>'}</section>`;
    };

    function activateScopedDetailTab(root, index) {
        const scope = root?.closest('.drawer-body') || root?.closest('.standalone-record-content') || root || document;
        scope.querySelectorAll('[data-detailtab]').forEach((tab) => tab.classList.toggle('active', String(tab.dataset.detailtab) === String(index)));
        scope.querySelectorAll('.detail-panel').forEach((panel) => panel.classList.toggle('active', panel.id === `detailPanel${index}`));
    }

    function bindStandaloneDetailTabs(content) {
        if (!content) return;
        content.querySelectorAll('[data-detailtab]').forEach((button) => {
            if (button.dataset.scopedStandaloneTab === '1') return;
            button.dataset.scopedStandaloneTab = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                activateScopedDetailTab(button, button.dataset.detailtab);
            }, true);
        });
        content.querySelectorAll('[data-open-job-tasks]').forEach((button) => {
            if (button.dataset.scopedOpenTasks === '1') return;
            button.dataset.scopedOpenTasks = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                activateScopedDetailTab(button, 2);
            }, true);
        });
        content.querySelectorAll('[data-flow-phase]').forEach((button) => {
            if (button.dataset.scopedFlowPhase === '1') return;
            button.dataset.scopedFlowPhase = '1';
            button.addEventListener('click', async (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                const job = flowtrackFindJob(button.dataset.flowJob);
                if (job) {
                    const scope = button.closest('.drawer-body') || button.closest('.standalone-record-content') || document;
                    const panel = scope.querySelector('#flowtrackPhaseHistoryPanel');
                    if (panel) panel.innerHTML = flowtrackInlineProgress('Loading selected phase tasks…');
                    try { await flowtrackRefreshJobTasks(job); } catch (error) {}
                    flowtrackOpenPhaseHistory(button.dataset.flowJob, button.dataset.flowPhase, button);
                }
            }, true);
        });
        content.querySelectorAll('[data-add-task]').forEach((button) => {
            if (button.dataset.scopedAddTask === '1') return;
            button.dataset.scopedAddTask = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                openJobTaskModal(button.dataset.addTask, button.dataset.addTaskPhase || null);
            }, true);
        });
    }

    function polishStandaloneContent(content) {
        if (!content) return;
        ['saveTaskUpdate', 'saveTaskDraft', 'saveTaskDueDate', 'createJob', 'saveDraftJob', 'saveClient', 'createClient', 'saveWorkflow', 'saveTaskPack'].forEach((id) => {
            const element = content.querySelector(`#${id}`);
            const parent = element?.closest('.actions,.modal-foot,.task-document-upload');
            if (parent) parent.classList.add('standalone-save-actions');
        });
        const updateButton = content.querySelector('#saveTaskUpdate');
        if (updateButton) updateButton.removeAttribute('style');
    }






    const flowtrackClickablePhaseStyles = document.createElement('style');
    flowtrackClickablePhaseStyles.textContent = `
        .clickable-workflow-timeline .step,.workflow-phase-step{border:0;background:transparent;font:inherit;color:inherit;cursor:pointer;padding:0}.workflow-phase-step:hover .step-dot,.workflow-phase-step.selected .step-dot{box-shadow:0 0 0 6px rgba(37,99,235,.12)}.workflow-phase-step.done.selected .step-dot{box-shadow:0 0 0 6px rgba(21,128,61,.14)}.workflow-phase-step.selected .step-label{color:#2563eb;font-weight:750}.phase-history-panel{border:1px solid #dbe3ef;border-radius:16px;background:#fff;padding:16px;box-shadow:0 8px 24px rgba(15,23,42,.04)}.phase-history-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:12px 0}.phase-history-summary>div{border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:11px}.phase-history-summary span{display:block;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}.phase-history-summary b{font-size:12px}.phase-history-task-list{display:grid;gap:8px}.phase-history-task-row,.subtask-row-button{width:100%;border:1px solid #e2e8f0;background:#fff;text-align:left}.phase-history-task-row{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;padding:10px;border-radius:12px;cursor:pointer}.phase-history-task-row:hover,.subtask-row-button:hover{background:#f8faff;border-color:#bfd4ff}.phase-history-events{display:grid;gap:10px}.phase-history-event{display:flex;gap:10px;align-items:flex-start;border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff}.phase-history-event.is-completion{border-color:#bbf7d0;background:#f7fff9}.phase-event-body{min-width:0;flex:1}.phase-event-head{display:flex;align-items:center;gap:8px;justify-content:space-between;flex-wrap:wrap}.phase-event-description{margin-top:4px;color:#0f172a}.phase-event-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:9px}.phase-event-detail{border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;padding:8px;min-width:0}.phase-event-detail span{display:block;color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}.phase-event-detail b{display:block;font-size:12px;font-weight:650;overflow-wrap:anywhere}.phase-change-list{padding-left:20px!important;margin-top:9px!important}.signal.green{background:#16a34a}.task-history-entry{border:1px solid #e2e8f0;border-radius:12px;padding:11px;margin-bottom:9px;background:#fff}.task-history-head{display:flex;gap:10px}.task-change-list{margin:9px 0 0;padding-left:38px;color:#475569;font-size:12px}.task-menu-wrap{position:relative}.task-action-menu{position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 16px 40px rgba(15,23,42,.16);padding:6px;z-index:10;min-width:150px}.task-action-menu button{display:block;width:100%;border:0;background:transparent;text-align:left;padding:9px 10px;border-radius:9px}.task-action-menu .danger-option{color:#b91c1c}.task-action-menu button:hover{background:#f8fafc}@media(max-width:760px){.phase-history-summary{grid-template-columns:1fr 1fr}.phase-event-grid{grid-template-columns:1fr}.phase-history-task-row{grid-template-columns:auto 1fr}.phase-history-task-row .badge{grid-column:2}}
    `;
    document.head.appendChild(flowtrackClickablePhaseStyles);

    const standalonePageStyles = document.createElement('style');
    standalonePageStyles.textContent = `
        .standalone-record-page{max-width:1180px;margin:0 auto;padding:0 0 28px}.standalone-record-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin:0 0 14px}.standalone-record-title{min-width:0}.standalone-record-title h1{margin:3px 0 4px;font-size:24px;line-height:1.15;font-weight:760;letter-spacing:-.02em}.standalone-record-title p{margin:0;color:#64748b;font-size:13px}.standalone-record-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.standalone-record-shell{background:#fff;border:1px solid #dbe3ef;border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.06);overflow:hidden}.standalone-record-content .drawer-head,.standalone-record-content .modal-head{position:relative;top:auto;border-bottom:1px solid #e2e8f0;padding:18px 20px;background:#fff}.standalone-record-content .drawer-head h2,.standalone-record-content .modal-head h2{font-size:22px;line-height:1.14;font-weight:760;letter-spacing:-.02em}.standalone-record-content .drawer-body,.standalone-record-content .modal-body{padding:18px 20px}.standalone-record-content .modal-foot{position:sticky;bottom:0;padding:12px 20px;background:rgba(255,255,255,.96);backdrop-filter:blur(6px);border-top:1px solid #e2e8f0}.standalone-record-content .summary-grid{gap:12px}.standalone-record-content .summary-box{border-radius:13px;padding:14px}.standalone-record-content .detail-tabs{margin-bottom:16px}.standalone-record-content .section-head h3{font-size:18px;font-weight:750}.standalone-record-content .card{box-shadow:none}.standalone-record-content .modal-head>.close-btn,.standalone-record-content .drawer-head>.close-btn,.standalone-record-content .job-header-actions>.close-btn{display:none!important}.standalone-record-content .job-header-actions{margin-left:auto}.standalone-record-form .modal-body{padding-bottom:8px}.standalone-record-form .form-grid{gap:14px}.standalone-record-form .field label{font-size:12px;font-weight:700;color:#334155}.standalone-record-form input,.standalone-record-form select,.standalone-record-form textarea{border-radius:12px}.standalone-record-form .job-form-stack,.standalone-record-form .client-form-stack{max-width:920px}.standalone-record-form .task-pack-items-editor{display:grid;gap:12px}.standalone-back-btn{border:1px solid #dbe3ef;background:#fff;color:#0f172a;border-radius:12px;padding:10px 13px;font-weight:700;cursor:pointer}.standalone-back-btn:hover{background:#f8fafc}.standalone-record-content .flow-hidden-source{display:none!important}@media(max-width:900px){.standalone-record-page{padding:0 10px 20px}.standalone-record-head{flex-direction:column}.standalone-record-actions{width:100%}.standalone-record-actions button{flex:1}.standalone-record-content .summary-grid,.standalone-record-content .form-grid{grid-template-columns:1fr}.standalone-record-content .form-grid .full{grid-column:auto}.standalone-record-content .drawer-head,.standalone-record-content .modal-head,.standalone-record-content .drawer-body,.standalone-record-content .modal-body,.standalone-record-content .modal-foot{padding-left:14px;padding-right:14px}}`;
    document.head.appendChild(standalonePageStyles);

    let standaloneBackPage = 'dashboard';
    let standaloneActivePageId = null;
    const baseCloseAllForStandalone = closeAll;
    closeAll = function () {
        baseCloseAllForStandalone();
        if (standaloneActivePageId && currentPage === standaloneActivePageId) {
            const destination = standaloneBackPage || 'dashboard';
            standaloneActivePageId = null;
            setTimeout(() => navigate(destination), 0);
        }
    };

    function flowtrackCloseModalOnly() {
        // Close an overlay/modal without leaving a standalone Job/Task detail route.
        baseCloseAllForStandalone();
    }

    function standaloneSourcePage(fallback = 'dashboard') {
        const page = currentPage && !String(currentPage).startsWith('record-') ? currentPage : standaloneBackPage;
        return page || fallback;
    }

    function ensureStandalonePage(pageId) {
        let page = document.getElementById(`page-${pageId}`);
        if (!page) {
            page = document.createElement('section');
            page.id = `page-${pageId}`;
            page.className = 'page standalone-record-host';
            document.querySelector('.content')?.appendChild(page);
        }
        return page;
    }

    function activateStandalonePage(pageId, backPage) {
        const page = ensureStandalonePage(pageId);
        standaloneActivePageId = pageId;
        standaloneBackPage = backPage || standaloneBackPage || 'dashboard';
        $$('.page').forEach((section) => section.classList.remove('active'));
        page.classList.add('active');
        $$('[data-page]').forEach((button) => button.classList.toggle('active', button.dataset.page === standaloneBackPage));
        $('#sidebar')?.classList.remove('open');
        $('#sidebarShade')?.classList.remove('open');
        currentPage = pageId;
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return page;
    }

    function standaloneBackButton(label = 'Back') {
        return `<button type="button" class="standalone-back-btn" data-standalone-back>← ${html(label)}</button>`;
    }

    function bindStandaloneBack(page) {
        page.querySelectorAll('[data-standalone-back]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const destination = standaloneBackPage || 'dashboard';
                standaloneActivePageId = null;
                navigate(destination);
            });
        });
        page.querySelectorAll('[data-close]').forEach((button) => {
            button.addEventListener('click', (event) => {
                if (!standaloneActivePageId || currentPage !== standaloneActivePageId) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                const destination = standaloneBackPage || 'dashboard';
                standaloneActivePageId = null;
                navigate(destination);
            }, true);
        });
    }

    function moveChildren(from, to) {
        while (from.firstChild) to.appendChild(from.firstChild);
    }

    function renderMovedRecordPage({ pageId, backPage, eyebrow, title, subtitle, sourceSelector, form = false }) {
        const source = document.querySelector(sourceSelector);
        if (!source || !source.childNodes.length) return false;
        document.querySelectorAll('.standalone-record-host').forEach((recordPage) => {
            if (recordPage.id !== `page-${pageId}`) recordPage.innerHTML = '';
        });
        const page = activateStandalonePage(pageId, backPage);
        page.innerHTML = `<div class="standalone-record-page ${form ? 'standalone-record-form' : ''}"><div class="standalone-record-head"><div class="standalone-record-title"><p>${html(eyebrow || 'FlowTrack')}</p><h1>${html(title || 'Details')}</h1>${subtitle ? `<p>${html(subtitle)}</p>` : ''}</div><div class="standalone-record-actions">${standaloneBackButton(`Back to ${backPageLabel(backPage)}`)}</div></div><div class="standalone-record-shell"><div class="standalone-record-content"></div></div></div>`;
        const content = page.querySelector('.standalone-record-content');
        moveChildren(source, content);
        source.classList.remove('open', 'document-job-chooser');
        source.innerHTML = '';
        $('#overlay')?.classList.remove('open');
        bindStandaloneBack(page);
        bindStandaloneDetailTabs(content);
        polishStandaloneContent(content);
        applyActionPermissions();
        window.FlowtrackSearchableDropdown?.enhance(page);
        return true;
    }

    function backPageLabel(page) {
        const map = { dashboard: 'Dashboard', mywork: 'My Work', jobs: 'Jobs', clients: 'Clients', board: 'Board', documents: 'Documents', workflowsetup: 'Workflow Setup', taskpacksetup: 'Task Pack Setup', masterdata: 'Master Data', setup: 'Setup' };
        return map[page] || 'Previous Page';
    }

    function detailSubtitleForJob(job) {
        if (!job) return '';
        return `${job.id}${job.order ? ` · ${job.order}` : ''} · ${clientName(job.client)} · ${phaseNameFor(job)}`;
    }

    function detailSubtitleForTask(task) {
        const job = tasks && task ? jobs.find((item) => item.id === task.job || Number(item.dbId) === Number(task.jobDbId)) : null;
        return `${task?.id || ''}${task?.job ? ` · ${task.job}` : ''}${job ? ` · ${job.title}` : ''}`;
    }

    const drawerJobDetailPage = currentFlowtrackImplementation('openJob');
    const drawerTaskDetailPage = currentFlowtrackImplementation('openTask');
    const drawerClientDetailPage = openClient;
    const modalClientFormPage = openClientModal;
    const modalJobFormPage = openJobModal;
    const modalEditJobFormPage = openEditJobModal;
    const modalWorkflowFormPage = openWorkflowModal;
    const modalTaskPackFormPage = openTaskPackModal;

    registerFlowtrackExtension('openJob', 'openJob-stage-2', function (id) {        const backPage = standaloneSourcePage('jobs');
        const job = jobs.find((item) => String(item.id) === String(id) || Number(item.dbId) === Number(id));
        if (!job) return;
        drawerJobDetailPage(job.id);
        const moved = renderMovedRecordPage({ pageId: `record-job-${String(job.id).replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`, backPage, eyebrow: 'Job Details', title: job.title || job.id, subtitle: detailSubtitleForJob(job), sourceSelector: '#detailDrawer' });
        if (moved) {
            const content = document.querySelector(`#page-record-job-${String(job.id).replace(/[^a-z0-9]+/gi, '-').toLowerCase()} .standalone-record-content`);
            if (content && !content.querySelector('#jobDetailPanels')) {
                const drawerBody = content.querySelector('.drawer-body');
                if (drawerBody) drawerBody.insertAdjacentHTML('beforeend', sanitizeFlowtrackHtml(`<div id="jobDetailPanels">${jobPanels(job, tasks.filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId)), docs.filter((documentRecord) => String(documentRecord.job) === String(job.id)))}</div>`));
                bindStandaloneDetailTabs(content);
                activateScopedDetailTab(content, 0);
            }
        }
    });

    registerFlowtrackExtension('openTask', 'openTask-stage-2', function (id) {        const backPage = standaloneSourcePage('mywork');
        const task = tasks.find((item) => item.id === id || Number(item.dbId) === Number(id));
        drawerTaskDetailPage(id);
        if (!task) return;
        renderMovedRecordPage({ pageId: `record-task-${String(task.id).replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`, backPage, eyebrow: 'Task Details', title: task.title || task.id, subtitle: detailSubtitleForTask(task), sourceSelector: '#detailDrawer' });
    });

    openClient = function (id) {
        const backPage = standaloneSourcePage('clients');
        const client = clients.find((item) => Number(item.id) === Number(id));
        drawerClientDetailPage(id);
        if (!client) return;
        renderMovedRecordPage({ pageId: `record-client-${client.id}`, backPage, eyebrow: 'Client Details', title: client.name || 'Client', subtitle: client.country || '', sourceSelector: '#detailDrawer' });
    };

    openClientModal = function (existing = null) {
        const backPage = standaloneSourcePage('clients');
        modalClientFormPage(existing);
        renderMovedRecordPage({ pageId: existing ? `record-client-edit-${existing.id}` : 'record-client-new', backPage, eyebrow: existing ? 'Edit Client' : 'Add Client', title: existing ? `Edit ${existing.name}` : 'Add Client', subtitle: 'Client information is saved as a full page, not a drawer.', sourceSelector: '#jobModal', form: true });
    };

    openJobModal = function (prefillClient) {
        const backPage = standaloneSourcePage('jobs');
        modalJobFormPage(prefillClient);
        renderMovedRecordPage({ pageId: 'record-job-new', backPage, eyebrow: 'New Job', title: 'Create New Job', subtitle: 'Enter the job details, products, workflow stage and attachments.', sourceSelector: '#jobModal', form: true });
    };

    openEditJobModal = function (job) {
        if (!job) return;
        const backPage = standaloneSourcePage('jobs');
        modalEditJobFormPage(job);
        renderMovedRecordPage({ pageId: `record-job-edit-${String(job.id).replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`, backPage, eyebrow: 'Edit Job', title: `Edit ${job.title || job.id}`, subtitle: detailSubtitleForJob(job), sourceSelector: '#jobModal', form: true });
    };

    openWorkflowModal = function (existing = null, duplicate = false) {
        const backPage = standaloneSourcePage('workflowsetup');
        modalWorkflowFormPage(existing, duplicate);
        const title = existing && !duplicate ? `Edit ${existing.name}` : duplicate ? `Duplicate ${existing?.name || 'Workflow'}` : 'Create New Workflow';
        renderMovedRecordPage({ pageId: existing && !duplicate ? `record-workflow-edit-${existing.id}` : duplicate ? `record-workflow-copy-${existing?.id || 'new'}` : 'record-workflow-new', backPage, eyebrow: existing && !duplicate ? 'Edit Workflow' : duplicate ? 'Duplicate Workflow' : 'New Workflow', title, subtitle: 'Configure workflow identity on a dedicated page.', sourceSelector: '#jobModal', form: true });
    };

    openTaskPackModal = function (existing = null) {
        const backPage = standaloneSourcePage('taskpacksetup');
        modalTaskPackFormPage(existing);
        const moveWhenReady = () => {
            const moved = renderMovedRecordPage({ pageId: existing ? `record-task-pack-edit-${existing.id}` : 'record-task-pack-new', backPage, eyebrow: existing ? 'Edit Task Pack' : 'Add Task Pack', title: existing ? `Edit ${existing.name}` : 'Add Task Pack', subtitle: 'Build the task sequence on a full page for easier editing.', sourceSelector: '#jobModal', form: true });
            if (moved) {
                const addButton = $('#addTaskPackItem');
                if (addButton && !addButton.dataset.pageScrollBound) {
                    addButton.dataset.pageScrollBound = '1';
                    addButton.addEventListener('click', () => setTimeout(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }), 30));
                }
            }
            return moved;
        };
        if (!moveWhenReady() && !existing) {
            const start = Date.now();
            const timer = setInterval(() => {
                if (moveWhenReady() || Date.now() - start > 4000) clearInterval(timer);
            }, 50);
        }
    };

    const standaloneRenderJobsBase = currentFlowtrackImplementation('renderJobs');
    registerFlowtrackExtension('renderJobs', 'renderJobs-stage-2', function () {        standaloneRenderJobsBase();
        $('#newJob')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopImmediatePropagation();
            openJobModal();
        }, true);
    });

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


    const flowtrackFinalPolishStyles = document.createElement('style');
    flowtrackFinalPolishStyles.textContent = `
        html,body,#appScreen,.app,.main{max-width:100%;overflow-x:hidden!important}.content{width:100%;box-sizing:border-box;overflow-x:hidden}.page{max-width:100%}.standalone-record-page{max-width:1360px!important;width:100%!important}.standalone-record-form .standalone-record-page,.standalone-record-form{width:100%!important}.standalone-record-form .job-form-stack,.standalone-record-form .client-form-stack,.standalone-record-content .job-form-stack,.standalone-record-content .client-form-stack{max-width:none!important;width:100%!important}.standalone-record-content .form-grid{grid-template-columns:1fr!important;width:100%!important}.standalone-record-content .form-grid .field,.standalone-record-content .form-grid .full{grid-column:1/-1!important;min-width:0!important}.standalone-record-content input,.standalone-record-content select,.standalone-record-content textarea,.standalone-record-content .searchable-select{width:100%!important;box-sizing:border-box}.standalone-record-content .job-product-row{grid-template-columns:minmax(0,1fr) minmax(0,1.4fr) minmax(110px,.4fr) 42px!important;width:100%;box-sizing:border-box}.standalone-record-content .job-product-labels{grid-template-columns:minmax(0,1fr) minmax(0,1.4fr) minmax(110px,.4fr) 42px!important}.standalone-record-content .modal-foot,.standalone-record-content .actions.standalone-save-actions{justify-content:flex-end!important;align-items:center!important;gap:10px!important}.standalone-record-content .modal-foot button,.standalone-record-content .actions.standalone-save-actions button,.standalone-record-content #saveTaskDraft,.standalone-record-content #saveTaskDueDate,.standalone-record-content #saveTaskUpdate{flex:0 0 158px!important;width:158px!important;min-width:158px!important;height:40px!important;min-height:40px!important;padding:0 14px!important;border-radius:11px!important}.standalone-record-content #saveTaskUpdate{flex-basis:158px!important}.standalone-record-content .drawer-head,.standalone-record-content .modal-head{align-items:flex-start!important}.standalone-record-content .task-head-actions,.standalone-record-content .job-header-actions{margin-left:auto!important;display:flex!important;gap:8px!important;align-items:center!important;justify-content:flex-end!important}.standalone-record-content .detail-panel{display:none}.standalone-record-content .detail-panel.active{display:block}.standalone-record-content #jobDetailPanels{min-height:220px}.standalone-record-content .summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important}.standalone-record-content .attention-item{min-width:0}.standalone-record-content .attention-item .item-title,.standalone-record-content .attention-item .item-meta{overflow-wrap:anywhere}.standalone-record-content .job-doc-row{grid-template-columns:38px minmax(0,1fr) auto!important}.standalone-record-content .job-doc-actions{min-width:0;flex-wrap:wrap}.drawer,.modal{max-width:100vw!important}.searchable-options{max-width:min(620px,calc(100vw - 36px))!important}
        @media(max-width:920px){.standalone-record-content .summary-grid{grid-template-columns:1fr!important}.standalone-record-content .job-product-row,.standalone-record-content .job-product-labels{grid-template-columns:1fr!important}.standalone-record-content .job-product-labels{display:none!important}.standalone-record-content .modal-foot button,.standalone-record-content .actions.standalone-save-actions button,.standalone-record-content #saveTaskDraft,.standalone-record-content #saveTaskDueDate,.standalone-record-content #saveTaskUpdate{flex:1 1 0!important;width:auto!important;min-width:0!important}}
    `;
    document.head.appendChild(flowtrackFinalPolishStyles);

    const flowtrackPolishMovedRecordPage = renderMovedRecordPage;
    renderMovedRecordPage = function (options) {
        const moved = flowtrackPolishMovedRecordPage(options);
        if (!moved) return false;
        const page = document.getElementById(`page-${options.pageId}`);
        const content = page?.querySelector('.standalone-record-content');
        if (content) {
            bindStandaloneDetailTabs(content);
            const activeTab = content.querySelector('[data-detailtab].active');
            activateScopedDetailTab(content, activeTab?.dataset.detailtab || 0);
            content.querySelectorAll('.modal-foot,.actions').forEach((actions) => {
                if (actions.querySelector('#saveTaskUpdate,#saveTaskDraft,#saveTaskDueDate,#createJob,#saveDraftJob,#saveEditJob,#saveClient,#createClient,#saveWorkflow,#saveTaskPack')) {
                    actions.classList.add('standalone-save-actions');
                }
            });
        }
        return moved;
    };

    const flowtrackPolishOpenJobModal = openJobModal;
    openJobModal = function (prefillClient) {
        flowtrackPolishOpenJobModal(prefillClient);
        queueMicrotask(() => {
            const owner = document.querySelector('#newJobOwner');
            if (!owner) return;
            const host = owner.closest('.standalone-record-host') || document;
            flowtrackRefreshAssigneeSelect(host, '#newJobOwner', owner.value || currentUser?.id);
        });
    };

    const flowtrackPolishOpenEditJobModal = openEditJobModal;
    openEditJobModal = function (job) {
        flowtrackPolishOpenEditJobModal(job);
        queueMicrotask(() => {
            if (!job || !canFlow('jobs', 'assign')) return;
            ['#editJobOwner', '#editJobCoordinator'].forEach((selector) => {
                const select = document.querySelector(selector);
                if (!select) return;
                const selected = select.value;
                select.innerHTML = flowtrackWorkspaceMembers().map((user) => `<option value="${html(user.id)}" ${String(user.id) === String(selected) ? 'selected' : ''}>${html(user.name)}</option>`).join('');
            });
            window.FlowtrackSearchableDropdown?.enhance(document.querySelector('.standalone-record-host.active') || document);
        });
    };


    const flowtrackFinalFixStyles = document.createElement('style');
    flowtrackFinalFixStyles.textContent = `
        @media (min-width:901px){
            #appScreen.app:not(.hidden){display:block!important;min-height:100vh!important}
            #sidebar.sidebar{position:fixed!important;left:0!important;top:0!important;bottom:0!important;width:248px!important;height:100dvh!important;overflow-y:auto!important;overscroll-behavior:contain!important;z-index:45!important}
            #appScreen .main{margin-left:248px!important;min-width:0!important;width:calc(100% - 248px)!important}
            #appScreen .topbar{position:sticky!important;top:0!important;z-index:30!important}
        }
        .standalone-record-content .task-menu-button,.standalone-record-content .close-btn{width:38px!important;height:38px!important;min-width:38px!important;min-height:38px!important;padding:0!important;display:inline-grid!important;place-items:center!important;line-height:1!important;border-radius:10px!important;font-size:18px!important}
        .standalone-record-content .task-menu-button{font-size:20px!important;letter-spacing:1px!important;background:#fff!important;border:1px solid #dbe3ef!important}
        .standalone-record-content .close-btn{background:#f1f5f9!important;border:0!important;color:#0f172a!important}
        .standalone-record-content .task-head-actions,.standalone-record-content .job-header-actions{align-items:flex-start!important;gap:8px!important}
        .workflow-card-clean .workflow-readiness-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:10px!important}
        .workflow-card-clean .workflow-readiness-grid .check-row{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important;min-height:52px!important;padding:12px!important;border:1px solid #dbe3ef!important;border-radius:12px!important;background:#fff!important}
        .workflow-card-clean .workflow-readiness-grid .check-row input{width:16px!important;height:16px!important;flex:0 0 16px!important;margin:0!important;accent-color:#2563eb!important}
        .workflow-card-clean .workflow-readiness-grid .check-row span{font-size:13px!important;line-height:1.35!important;color:#243247!important;font-weight:600!important}
        .workflow-card-clean .standalone-save-actions{justify-content:flex-end!important;flex-wrap:wrap!important;gap:10px!important}
        .workflow-card-clean .standalone-save-actions button{flex:0 0 218px!important;width:218px!important;min-width:218px!important;height:42px!important;min-height:42px!important;padding:0 14px!important;border-radius:12px!important;font-size:14px!important;font-weight:700!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}
        .standalone-record-content .task-pack-editor-item .form-grid{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:12px!important;width:100%!important}
        .standalone-record-content .task-pack-editor-item .form-grid .full{grid-column:1/-1!important}
        .standalone-record-content .task-pack-editor-item .field{min-width:0!important;margin-bottom:0!important}
        .standalone-record-content .task-pack-editor-item .check-row{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important;width:100%!important;border:1px solid #e2e8f0!important;border-radius:10px!important;padding:10px 12px!important;margin-top:12px!important;background:#fff!important}
        .standalone-record-content .task-pack-editor-item .check-row input{width:16px!important;height:16px!important;flex:0 0 16px!important;margin:0!important;accent-color:#2563eb!important}
        .standalone-record-content .task-pack-editor-item .row-actions{display:flex!important;align-items:center!important;gap:6px!important;flex-wrap:wrap!important;justify-content:flex-end!important}
        .standalone-record-content .task-pack-editor-item .mini-btn{height:32px!important;min-height:32px!important;padding:0 10px!important;border-radius:8px!important;font-size:12px!important}
        .standalone-record-content .job-product-row,.standalone-record-content .job-product-labels{max-width:100%!important;overflow:hidden!important}
        .standalone-record-content .job-form-stack,.standalone-record-content .client-form-stack{max-width:100%!important;width:100%!important}
        .standalone-record-content .modal-body,.standalone-record-content .drawer-body{overflow-x:hidden!important}
        .standalone-record-content .form-grid .field.full{width:100%!important;max-width:100%!important}
        @media(max-width:920px){
            .workflow-card-clean .workflow-readiness-grid{grid-template-columns:1fr!important}
            .workflow-card-clean .standalone-save-actions button{flex:1 1 100%!important;width:100%!important;min-width:0!important}
            .standalone-record-content .task-pack-editor-item .form-grid{grid-template-columns:1fr!important}
        }
    `;
    document.head.appendChild(flowtrackFinalFixStyles);

    function flowtrackRecordSlug(value) {
        return String(value ?? 'record').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
    }

    function flowtrackClearStandalonePage(pageId) {
        const page = document.getElementById(`page-${pageId}`);
        if (!page) return;
        page.innerHTML = '';
    }

    function flowtrackClearRecordFor(prefix, id) {
        flowtrackClearStandalonePage(`${prefix}-${flowtrackRecordSlug(id)}`);
    }

    function flowtrackEnsureJobPanels(job, preferredTab = null) {
        if (!job) return;
        const page = document.getElementById(`page-record-job-${flowtrackRecordSlug(job.id)}`);
        const content = page?.querySelector('.standalone-record-content');
        if (!content) return;
        let panels = content.querySelector('#jobDetailPanels');
        const body = content.querySelector('.drawer-body') || content;
        if (!panels) {
            panels = document.createElement('div');
            panels.id = 'jobDetailPanels';
            body.appendChild(panels);
        }
        panels.innerHTML = jobPanels(
            job,
            tasks.filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId)),
            docs.filter((documentRecord) => String(documentRecord.job) === String(job.id) || Number(documentRecord.jobDbId) === Number(job.dbId))
        );
        bindStandaloneDetailTabs(content);
        const activeTab = preferredTab ?? content.querySelector('[data-detailtab].active')?.dataset.detailtab ?? 0;
        activateScopedDetailTab(content, activeTab);
        bindCommon();
        applyActionPermissions();
    }

    function flowtrackRefreshStartStages(root = document) {
        const scope = root || document;
        const workflowSelect = scope.querySelector('#newJobWorkflow');
        const stageSelect = scope.querySelector('#newJobStartPhase');
        if (!workflowSelect || !stageSelect) return;
        const workflow = getWorkflow(Number(workflowSelect.value));
        const phases = (workflow?.phases || [])
            .filter((phase) => phase.active !== false)
            .slice()
            .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
        const allowed = phases.filter((phase) => phase.allowStart !== false);
        const options = (allowed.length ? allowed : phases).map((phase, index) => {
            const sequence = Number(phase.sequence || index + 1);
            return `<option value="${html(phase.id)}">${sequence}. ${html(phase.name)}</option>`;
        }).join('');
        stageSelect.innerHTML = options || '<option value="">No active workflow stage configured</option>';
        stageSelect.disabled = !options;
        window.FlowtrackSearchableDropdown?.refresh?.(stageSelect);
    }

    const flowtrackStableOpenTask = currentFlowtrackImplementation('openTask');
    registerFlowtrackExtension('openTask', 'openTask-stage-3', function (id) {        const task = tasks.find((item) => item.id === id || Number(item.dbId) === Number(id));
        if (task) flowtrackClearRecordFor('record-task', task.id);
        return flowtrackStableOpenTask(id);
    });

    const flowtrackStableOpenJob = currentFlowtrackImplementation('openJob');
    registerFlowtrackExtension('openJob', 'openJob-stage-3', function (id) {        const job = jobs.find((item) => String(item.id) === String(id) || Number(item.dbId) === Number(id));
        if (job) flowtrackClearRecordFor('record-job', job.id);
        const result = flowtrackStableOpenJob(id);
        if (job) {
            flowtrackEnsureJobPanels(job);
            setTimeout(() => flowtrackEnsureJobPanels(job, document.querySelector('.standalone-record-content [data-detailtab].active')?.dataset.detailtab ?? null), 0);
        }
        return result;
    });

    const flowtrackStableOpenClient = openClient;
    openClient = function (id) {
        flowtrackClearRecordFor('record-client', id);
        return flowtrackStableOpenClient(id);
    };

    const flowtrackStableOpenClientModal = openClientModal;
    openClientModal = function (existing = null) {
        flowtrackClearStandalonePage(existing ? `record-client-edit-${existing.id}` : 'record-client-new');
        return flowtrackStableOpenClientModal(existing);
    };

    const flowtrackStableOpenJobModal = openJobModal;
    openJobModal = function (prefillClient) {
        flowtrackClearStandalonePage('record-job-new');
        const result = flowtrackStableOpenJobModal(prefillClient);
        queueMicrotask(() => {
            const host = document.querySelector('#page-record-job-new') || document;
            flowtrackRefreshAssigneeSelect(host, '#newJobOwner', host.querySelector('#newJobOwner')?.value || currentUser?.id);
            flowtrackRefreshStartStages(host);
            host.querySelector('#newJobWorkflow')?.addEventListener('change', () => flowtrackRefreshStartStages(host));
            window.FlowtrackSearchableDropdown?.enhance(host);
        });
        return result;
    };

    const flowtrackStableOpenEditJobModal = openEditJobModal;
    openEditJobModal = function (job) {
        if (job) flowtrackClearStandalonePage(`record-job-edit-${flowtrackRecordSlug(job.id)}`);
        const result = flowtrackStableOpenEditJobModal(job);
        queueMicrotask(() => {
            if (!job || !canFlow('jobs', 'assign')) return;
            const host = document.querySelector(`#page-record-job-edit-${flowtrackRecordSlug(job.id)}`) || document;
            ['#editJobOwner', '#editJobCoordinator'].forEach((selector) => {
                const select = host.querySelector(selector);
                if (!select) return;
                const selected = select.value;
                select.innerHTML = flowtrackWorkspaceMembers().map((user) => `<option value="${html(user.id)}" ${String(user.id) === String(selected) ? 'selected' : ''}>${html(user.name)}</option>`).join('');
            });
            window.FlowtrackSearchableDropdown?.enhance(host);
        });
        return result;
    };

    const flowtrackStableOpenWorkflowModal = openWorkflowModal;
    openWorkflowModal = function (existing = null, duplicate = false) {
        flowtrackClearStandalonePage(existing && !duplicate ? `record-workflow-edit-${existing.id}` : duplicate ? `record-workflow-copy-${existing?.id || 'new'}` : 'record-workflow-new');
        return flowtrackStableOpenWorkflowModal(existing, duplicate);
    };

    const flowtrackStableOpenTaskPackModal = openTaskPackModal;
    openTaskPackModal = function (existing = null) {
        flowtrackClearStandalonePage(existing ? `record-task-pack-edit-${existing.id}` : 'record-task-pack-new');
        return flowtrackStableOpenTaskPackModal(existing);
    };

    const flowtrackStablePersistTask = persistTask;
    persistTask = async function (task, status, extras = {}) {
        const result = await flowtrackStablePersistTask(task, status, extras);
        if (result?.task) flowtrackUpsertRecord(tasks, result.task);
        if (result?.job) flowtrackUpsertRecord(jobs, result.job);
        return result;
    };


    const flowtrackEmergencyFormFixStyles = document.createElement('style');
    flowtrackEmergencyFormFixStyles.textContent = `
        #appScreen.hidden{display:none!important}
        @media (min-width:901px){#appScreen.app.hidden{display:none!important}#appScreen.app:not(.hidden){display:block!important}}
        .standalone-record-content .job-products-field{overflow:visible!important;background:#f8fbff!important}
        .standalone-record-content .job-product-list{overflow:visible!important}
        .standalone-record-content .job-product-row,.standalone-record-content .job-product-labels{display:grid!important;grid-template-columns:minmax(220px,1fr) minmax(260px,1.25fr) minmax(120px,.42fr) 42px!important;gap:10px!important;align-items:start!important;overflow:visible!important}
        .standalone-record-content .job-product-row .flow-searchable,.standalone-record-content .job-product-row input{min-width:0!important;width:100%!important}
        .standalone-record-content .job-product-row .flow-searchable-panel{z-index:800!important;max-width:none!important}
        .standalone-record-content .job-remove-product{width:40px!important;height:40px!important;min-width:40px!important;display:inline-grid!important;place-items:center!important;align-self:start!important}
        .standalone-record-content .modal-foot,.standalone-record-content .standalone-save-actions{display:flex!important;justify-content:flex-end!important;align-items:center!important;gap:10px!important;flex-wrap:wrap!important}
        .standalone-record-content .modal-foot button,.standalone-record-content .standalone-save-actions button{height:40px!important;min-height:40px!important;border-radius:11px!important;padding:0 16px!important;font-size:13px!important;line-height:1!important}
        .standalone-record-content .task-menu-button,.standalone-record-content .close-btn{width:38px!important;height:38px!important;min-width:38px!important;min-height:38px!important;display:inline-grid!important;place-items:center!important;text-align:center!important;line-height:1!important}
        .standalone-record-content .workflow-readiness-grid .check-row{justify-content:flex-start!important;text-align:left!important}
        .standalone-record-content .workflow-readiness-grid .check-row input{margin:0!important;align-self:center!important}
        @media(max-width:920px){.standalone-record-content .job-product-row{grid-template-columns:1fr 1fr 112px 40px!important}.standalone-record-content .job-product-labels{display:none!important}}
        @media(max-width:640px){.standalone-record-content .job-product-row{grid-template-columns:1fr 40px!important}.standalone-record-content .job-product-row>:nth-child(1),.standalone-record-content .job-product-row>:nth-child(2),.standalone-record-content .job-product-row>:nth-child(3){grid-column:1!important}.standalone-record-content .job-product-row>:nth-child(4){grid-column:2!important;grid-row:1!important}}
    `;
    document.head.appendChild(flowtrackEmergencyFormFixStyles);

    document.addEventListener('click', (event) => {
        if (event.target.closest('#createJob,#saveDraftJob,#saveEditJob,#saveTaskUpdate,#saveTaskDraft,#saveTaskDueDate,#saveClient,#createClient,#saveWorkflow,#saveTaskPack')) {
            sessionActivityPending = true;
        }
    }, true);


    const flowtrackTaskInteractionStyles = document.createElement('style');
    flowtrackTaskInteractionStyles.textContent = `
        .ops-board-page.ops-mode-tasks .ops-board-canvas{overflow-x:auto!important;overflow-y:visible!important;position:relative!important}
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid{position:sticky!important;top:68px!important;z-index:60!important;background:#fff!important;box-shadow:0 8px 18px rgba(15,23,42,.08)!important}
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-head{background:#fbfcff!important}
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid,
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-task-row{
            grid-template-columns:repeat(var(--task-status-cols,5),minmax(270px,1fr))!important;
            min-width:max(100%,calc(var(--task-status-cols,5) * 270px))!important;
        }
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-job-group{min-width:max(100%,calc(var(--task-status-cols,5) * 270px))!important}
        .ops-board-page.ops-mode-tasks .ops-task-cell{padding:10px!important}
        .ops-board-page.ops-mode-tasks .ops-task-card{width:100%!important;min-width:0!important}
        .ops-board-card-menu-wrap{position:relative;display:inline-flex;align-items:center}
        .ops-board-card-action-menu{position:absolute;right:0;top:calc(100% + 6px);z-index:900;min-width:142px;padding:5px;background:#fff;border:1px solid #dbe3ef;border-radius:10px;box-shadow:0 14px 30px rgba(15,23,42,.16)}
        .ops-board-card-action-menu.hidden{display:none!important}
        .ops-board-card-action-menu button{width:100%;border:0;background:transparent;color:#c0392b;text-align:left;padding:9px 10px;border-radius:7px;font-size:12px;font-weight:650;cursor:pointer}
        .ops-board-card-action-menu button:hover{background:#fff1f0}
        .ops-menu-disabled{display:block;padding:8px 9px;color:#94a3b8;font-size:11px;white-space:nowrap}
        .standalone-record-shell{overflow:visible!important}
        .standalone-record-content{position:relative;overflow:visible!important;border-radius:18px;background:#fff}
        .standalone-record-content .drawer-head,.standalone-record-content .modal-head{border-radius:18px 18px 0 0}
        .standalone-record-content .drawer-body,.standalone-record-content .modal-body{overflow:visible!important}
        .task-menu-wrap{z-index:1000}
        .task-action-menu{z-index:1100!important;min-width:160px!important}
        .mention-wrap{z-index:40}
        .mention-menu{z-index:1200!important;max-height:220px!important;overflow-y:auto!important;border-color:#b9c8dc!important}
        .mention-option b{font-weight:650}
        .task-attention-row{position:relative;display:flex!important;align-items:flex-start!important;justify-content:flex-start!important;gap:11px!important;width:100%!important;margin-top:16px!important;padding:12px 14px!important;border:1px solid #dbe3ef!important;border-radius:12px!important;background:#fbfdff!important;cursor:pointer!important;text-align:left!important}
        .task-attention-row>input{position:absolute!important;opacity:0!important;pointer-events:none!important}
        .task-attention-control{width:20px;height:20px;flex:0 0 20px;border:1.5px solid #a9b7ca;border-radius:6px;background:#fff;display:grid;place-items:center;margin-top:1px;transition:.15s}
        .task-attention-row>input:checked+.task-attention-control{background:#2563eb;border-color:#2563eb}
        .task-attention-row>input:checked+.task-attention-control:after{content:'✓';color:#fff;font-size:13px;font-weight:800;line-height:1}
        .task-attention-copy{display:grid;gap:3px;min-width:0;color:#172033}
        .task-attention-copy b{font-size:13px;font-weight:650}
        .task-attention-copy small{font-size:11px;line-height:1.35;color:#64748b}
        .task-attention-row:has(input:focus-visible){outline:3px solid rgba(37,99,235,.18);border-color:#6d95ff!important}
        .task-attention-row:has(input:disabled){opacity:.58;cursor:not-allowed!important;background:#f8fafc!important}
        .task-document-section{overflow:visible!important}
        .task-document-upload{grid-template-columns:minmax(0,1fr)!important}
        .task-document-upload-actions{justify-content:flex-end!important;margin-top:0!important}
        .task-document-upload-actions button{width:auto!important;min-width:170px!important}
        @media(max-width:760px){
            .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid,
            .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-task-row{grid-template-columns:repeat(var(--task-status-cols,5),minmax(250px,1fr))!important;min-width:calc(var(--task-status-cols,5) * 250px)!important}
            .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-job-group{min-width:calc(var(--task-status-cols,5) * 250px)!important}
            .task-document-upload-actions button{width:100%!important}
        }
    `;
    document.head.appendChild(flowtrackTaskInteractionStyles);

    const flowtrackBoardFilterCleanupStyles = document.createElement('style');
    flowtrackBoardFilterCleanupStyles.textContent = `
        /* Board filter cleanup: remove More / Group by / Save view controls and keep visible filter widths consistent. */
        .ops-board-page .ops-toolbar,
        .ops-board-page.ops-mode-jobs .ops-toolbar,
        .ops-board-page.ops-mode-tasks .ops-toolbar{
            grid-template-columns:minmax(180px,1fr) minmax(280px,2fr) repeat(5,minmax(140px,1fr)) minmax(120px,.75fr)!important;
            align-items:stretch!important;
        }
        .ops-board-page .ops-filter[data-board-filter="more"],
        .ops-board-page .ops-group-row,
        .ops-board-page [data-board-save-view]{display:none!important}
        .ops-board-page .ops-filter[data-board-filter="job"],
        .ops-board-page .ops-filter[data-board-filter="client"],
        .ops-board-page .ops-filter[data-board-filter="assignee"],
        .ops-board-page .ops-filter[data-board-filter="status"],
        .ops-board-page .ops-filter[data-board-filter="due"]{width:100%!important;min-width:0!important}
        .ops-board-page .ops-status-summary{justify-content:flex-start!important}
        .ops-board-page .ops-quick-row{justify-content:flex-start!important}
        .ops-board-page.ops-mode-tasks .ops-board-canvas{overflow:auto!important;position:relative!important}
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid{
            position:sticky!important;
            top:0!important;
            z-index:25!important;
            background:#fff!important;
            box-shadow:0 2px 0 rgba(226,232,240,.95),0 8px 14px rgba(15,23,42,.04)!important;
        }
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-head{background:#fbfcff!important}
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-job-group:first-of-type{border-top:0!important}
        @media(max-width:1180px){
            .ops-board-page .ops-toolbar,
            .ops-board-page.ops-mode-jobs .ops-toolbar,
            .ops-board-page.ops-mode-tasks .ops-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))!important}
            .ops-board-page .ops-search{grid-column:auto!important}
        }
        @media(max-width:720px){
            .ops-board-page .ops-toolbar,
            .ops-board-page.ops-mode-jobs .ops-toolbar,
            .ops-board-page.ops-mode-tasks .ops-toolbar{grid-template-columns:1fr!important}
        }
    `;
    document.head.appendChild(flowtrackBoardFilterCleanupStyles);

    const flowtrackBoardAssigneeStickyFinalStyles = document.createElement('style');
    flowtrackBoardAssigneeStickyFinalStyles.textContent = `
        /* Final Board fix: show Assignee filter and keep task status header sticky during page-level vertical scrolling. */
        .ops-board-page .ops-board-actions .ghost{display:none!important}
        .ops-board-page .ops-toolbar,
        .ops-board-page.ops-mode-jobs .ops-toolbar,
        .ops-board-page.ops-mode-tasks .ops-toolbar{
            grid-template-columns:minmax(180px,1fr) minmax(280px,2fr) repeat(5,minmax(140px,1fr)) minmax(112px,.72fr)!important;
            gap:8px!important;
            align-items:stretch!important;
        }
        .ops-board-page .ops-filter[data-board-filter="job"],
        .ops-board-page .ops-filter[data-board-filter="client"],
        .ops-board-page .ops-filter[data-board-filter="assignee"],
        .ops-board-page .ops-filter[data-board-filter="status"],
        .ops-board-page .ops-filter[data-board-filter="due"]{
            display:block!important;
            visibility:visible!important;
            width:100%!important;
            min-width:0!important;
        }
        .ops-board-page .ops-filter[data-board-filter="assignee"]{order:5!important}
        .ops-board-page .ops-filter[data-board-filter="status"]{order:6!important}
        .ops-board-page .ops-filter[data-board-filter="due"]{order:7!important}
        .ops-board-page .ops-clear{order:8!important;width:100%!important;min-width:0!important}
        .ops-board-page.ops-mode-tasks .ops-board-canvas{
            overflow-x:auto!important;
            overflow-y:visible!important;
            position:relative!important;
            isolation:isolate!important;
        }
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid{
            position:sticky!important;
            top:68px!important;
            z-index:120!important;
            background:#fff!important;
            box-shadow:0 2px 0 rgba(226,232,240,.95),0 10px 18px rgba(15,23,42,.08)!important;
        }
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-head{
            background:#fbfcff!important;
        }
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-job-group:first-of-type{
            border-top:0!important;
        }
        @media(max-width:1380px){
            .ops-board-page .ops-toolbar,
            .ops-board-page.ops-mode-jobs .ops-toolbar,
            .ops-board-page.ops-mode-tasks .ops-toolbar{
                grid-template-columns:minmax(160px,1fr) minmax(220px,1.4fr) repeat(5,minmax(118px,1fr)) minmax(92px,.7fr)!important;
            }
            .ops-board-page .ops-toolbar .ops-filter[data-board-filter="assignee"]{display:block!important}
        }
        @media(max-width:1180px){
            .ops-board-page .ops-toolbar,
            .ops-board-page.ops-mode-jobs .ops-toolbar,
            .ops-board-page.ops-mode-tasks .ops-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))!important}
            .ops-board-page .ops-search{grid-column:1/-1!important}
            .ops-board-page .ops-toolbar .ops-filter[data-board-filter="assignee"]{display:block!important}
            .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid{top:64px!important}
        }
        @media(max-width:720px){
            .ops-board-page .ops-toolbar,
            .ops-board-page.ops-mode-jobs .ops-toolbar,
            .ops-board-page.ops-mode-tasks .ops-toolbar{grid-template-columns:1fr!important}
            .ops-board-page .ops-toolbar .ops-filter[data-board-filter="assignee"]{display:block!important}
            .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid{top:58px!important}
        }
    `;
    document.head.appendChild(flowtrackBoardAssigneeStickyFinalStyles);


    const flowtrackMyWorkAdminStickyTaskBoardStyles = document.createElement('style');
    flowtrackMyWorkAdminStickyTaskBoardStyles.textContent = `
        /* Final task board sticky behavior: the job-based task area scrolls vertically while status headers stay visible. */
        .ops-board-page.ops-mode-tasks #kanbanArea,
        .ops-board-page.ops-mode-tasks #myWorkKanbanArea{position:relative!important;min-height:0!important}
        .ops-board-page.ops-mode-tasks .ops-board-canvas{
            position:relative!important;
            overflow:auto!important;
            max-height:calc(100vh - 300px)!important;
            min-height:360px!important;
            overscroll-behavior:contain!important;
            isolation:isolate!important;
        }
        .my-work-board.ops-board-page.ops-mode-tasks .ops-board-canvas{max-height:calc(100vh - 360px)!important}
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-grid{
            position:sticky!important;
            top:0!important;
            z-index:300!important;
            background:#fff!important;
            box-shadow:0 2px 0 rgba(226,232,240,.98),0 8px 16px rgba(15,23,42,.08)!important;
        }
        .ops-board-page.ops-mode-tasks .ops-board-canvas .ops-status-head{background:#fbfcff!important}
        @media(max-height:780px){
            .ops-board-page.ops-mode-tasks .ops-board-canvas{max-height:calc(100vh - 250px)!important}
            .my-work-board.ops-board-page.ops-mode-tasks .ops-board-canvas{max-height:calc(100vh - 310px)!important}
        }
        @media(max-width:820px){
            .ops-board-page.ops-mode-tasks .ops-board-canvas{max-height:calc(100vh - 250px)!important}
            .my-work-board.ops-board-page.ops-mode-tasks .ops-board-canvas{max-height:calc(100vh - 300px)!important}
        }
    `;
    document.head.appendChild(flowtrackMyWorkAdminStickyTaskBoardStyles);

    let flowtrackRouteSyncSuppressed = false;

    function flowtrackRoutePath(pageId) {
        const page = String(pageId || 'dashboard');
        const base = '/flowtrack/view';
        if (page.startsWith('record-task-')) return `${base}/tasks/${encodeURIComponent(page.slice('record-task-'.length))}`;
        if (page.startsWith('record-job-edit-')) return `${base}/jobs/${encodeURIComponent(page.slice('record-job-edit-'.length))}/edit`;
        if (page === 'record-job-new') return `${base}/jobs/new`;
        if (page.startsWith('record-job-')) return `${base}/jobs/${encodeURIComponent(page.slice('record-job-'.length))}`;
        if (page.startsWith('record-client-edit-')) return `${base}/clients/${encodeURIComponent(page.slice('record-client-edit-'.length))}/edit`;
        if (page === 'record-client-new') return `${base}/clients/new`;
        if (page.startsWith('record-client-')) return `${base}/clients/${encodeURIComponent(page.slice('record-client-'.length))}`;
        if (page === 'record-workflow-new') return `${base}/workflows/new`;
        if (page.startsWith('record-workflow-edit-')) return `${base}/workflows/${encodeURIComponent(page.slice('record-workflow-edit-'.length))}/edit`;
        if (page === 'record-task-pack-new') return `${base}/task-packs/new`;
        if (page.startsWith('record-task-pack-edit-')) return `${base}/task-packs/${encodeURIComponent(page.slice('record-task-pack-edit-'.length))}/edit`;
        return `${base}/${encodeURIComponent(page)}`;
    }

    function flowtrackSyncRoute(pageId, replace = false) {
        if (flowtrackRouteSyncSuppressed || !window.history?.pushState) return;
        const path = flowtrackRoutePath(pageId);
        if (window.location.pathname === path) return;
        window.history[replace ? 'replaceState' : 'pushState']({ flowtrackPage: pageId }, '', path);
    }

    function flowtrackInitialRoute() {
        const prefix = '/flowtrack/view/';
        if (!window.location.pathname.startsWith(prefix)) return null;
        const parts = window.location.pathname.slice(prefix.length).split('/').filter(Boolean).map(decodeURIComponent);
        if (!parts.length) return { type: 'page', page: 'dashboard' };
        if (parts[0] === 'tasks' && parts[1]) return { type: 'task', slug: parts[1] };
        if (parts[0] === 'jobs' && parts[1] === 'new') return { type: 'job-new' };
        if (parts[0] === 'jobs' && parts[1] && parts[2] === 'edit') return { type: 'job-edit', slug: parts[1] };
        if (parts[0] === 'jobs' && parts[1]) return { type: 'job', slug: parts[1] };
        if (parts[0] === 'clients' && parts[1] === 'new') return { type: 'client-new' };
        if (parts[0] === 'clients' && parts[1] && parts[2] === 'edit') return { type: 'client-edit', slug: parts[1] };
        if (parts[0] === 'clients' && parts[1]) return { type: 'client', slug: parts[1] };
        if (parts[0] === 'workflows' && parts[1] === 'new') return { type: 'workflow-new' };
        if (parts[0] === 'workflows' && parts[1] && parts[2] === 'edit') return { type: 'workflow-edit', slug: parts[1] };
        if (parts[0] === 'task-packs' && parts[1] === 'new') return { type: 'task-pack-new' };
        if (parts[0] === 'task-packs' && parts[1] && parts[2] === 'edit') return { type: 'task-pack-edit', slug: parts[1] };
        return { type: 'page', page: parts[0] };
    }

    function flowtrackFindBySlug(collection, slug) {
        return (collection || []).find((item) => flowtrackRecordSlug(item.id ?? item.code ?? item.name) === flowtrackRecordSlug(slug)
            || String(item.dbId ?? item.id) === String(slug));
    }

    function flowtrackOpenInitialRoute(route) {
        if (!route) return false;
        flowtrackRouteSyncSuppressed = true;
        try {
            if (route.type === 'page') {
                const page = canOpenPage(route.page) ? route.page : 'dashboard';
                navigate(page);
                flowtrackRouteSyncSuppressed = false;
                flowtrackSyncRoute(page, true);
                return true;
            }
            if (route.type === 'task') {
                const task = flowtrackFindBySlug(tasks, route.slug);
                navigate('board');
                void openTask(task?.id || route.slug);
            } else if (route.type === 'job') {
                const job = flowtrackFindBySlug(jobs, route.slug);
                navigate('jobs');
                void openJob(job?.id || route.slug);
            } else if (route.type === 'job-new') {
                navigate('jobs');
                openJobModal();
            } else if (route.type === 'job-edit') {
                const job = flowtrackFindBySlug(jobs, route.slug);
                navigate('jobs');
                void flowtrackEnsureJobDetail(job?.id || route.slug).then((resolved) => resolved && openEditJobModal(resolved));
            } else if (route.type === 'client') {
                const client = flowtrackFindBySlug(clients, route.slug);
                navigate('clients');
                void openClient(client?.id || route.slug);
            } else if (route.type === 'client-new') {
                navigate('clients');
                openClientModal();
            } else if (route.type === 'client-edit') {
                const client = flowtrackFindBySlug(clients, route.slug);
                navigate('clients');
                void flowtrackEnsureClientDetail(client?.id || route.slug).then((resolved) => resolved && openClientModal(resolved));
            } else if (route.type === 'workflow-new') {
                navigate('workflowsetup');
                openWorkflowModal();
            } else if (route.type === 'workflow-edit') {
                const workflow = flowtrackFindBySlug(workflowTemplates, route.slug);
                navigate('workflowsetup');
                if (workflow) openWorkflowModal(workflow);
            } else if (route.type === 'task-pack-new') {
                navigate('taskpacksetup');
                openTaskPackModal();
            } else if (route.type === 'task-pack-edit') {
                const pack = flowtrackFindBySlug(taskPacks, route.slug);
                navigate('taskpacksetup');
                if (pack) openTaskPackModal(pack);
            }
        } finally {
            flowtrackRouteSyncSuppressed = false;
        }
        flowtrackSyncRoute(currentPage || 'dashboard', true);
        return true;
    }

    const flowtrackHistoryNavigate = navigate;
    navigate = function (page) {
        const result = flowtrackHistoryNavigate(page);
        if (currentPage === page) flowtrackSyncRoute(page);
        return result;
    };

    const flowtrackHistoryActivateStandalonePage = activateStandalonePage;
    activateStandalonePage = function (pageId, backPage) {
        const page = flowtrackHistoryActivateStandalonePage(pageId, backPage);
        flowtrackSyncRoute(pageId);
        return page;
    };

    const flowtrackHistoryShowApplication = showApplication;
    showApplication = function () {
        const initialRoute = flowtrackInitialRoute();
        flowtrackRouteSyncSuppressed = true;
        flowtrackHistoryShowApplication();
        flowtrackRouteSyncSuppressed = false;
        if (!flowtrackOpenInitialRoute(initialRoute)) {
            flowtrackSyncRoute(currentPage || currentUser?.defaultLandingPage || 'dashboard', true);
        }
    };

    window.addEventListener('popstate', () => window.location.reload());

    const expectedFlowtrackStages = {
        openJob: ['openJob-stage-1', 'openJob-stage-2', 'openJob-stage-3'],
        openTask: ['openTask-stage-1', 'openTask-stage-2', 'openTask-stage-3'],
        renderJobs: ['renderJobs-stage-1', 'renderJobs-stage-2'],
        renderKanban: ['renderKanban-stage-1', 'renderKanban-stage-2'],
    };
    const actualFlowtrackStages = Object.fromEntries(
        flowtrackToArray(flowtrackExtensionRegistry).map(([name, stages]) => [name, stages.map((entry) => entry.stage)]),
    );
    Object.entries(expectedFlowtrackStages).forEach(([name, expected]) => {
        const actual = actualFlowtrackStages[name] || [];
        if (actual.length !== expected.length || actual.some((stage, index) => stage !== expected[index])) {
            throw new Error(`Invalid FlowTrack extension chain for ${name}: ${actual.join(', ')}`);
        }
    });

    // Freeze the final public implementations after all deterministic stages have
    // been installed. This preserves the existing UI result while preventing any
    // later script from silently replacing core behavior.
    const flowtrackFinalImplementations = { openJob, openTask, renderJobs, renderKanban, bindCommon };
    Object.entries(flowtrackFinalImplementations).forEach(([name, implementation]) => {
        try {
            Object.defineProperty(window, name, {
                value: implementation,
                writable: false,
                configurable: false,
            });
        } catch (error) {
            if (window[name] !== implementation) throw error;
        }
    });
    window.__FLOWTRACK_FINALIZED__ = true;
    window.__FLOWTRACK_EXTENSION_STAGES__ = actualFlowtrackStages;



    const flowtrackFullWidthTimelineStyles = document.createElement('style');
    flowtrackFullWidthTimelineStyles.textContent = `
        .standalone-record-content .timeline,
        .standalone-record-content .clickable-workflow-timeline{
            display:flex!important;
            width:100%!important;
            max-width:100%!important;
            justify-content:space-between!important;
            align-items:flex-start!important;
            gap:0!important;
            overflow-x:visible!important;
            padding:22px 18px 26px!important;
            box-sizing:border-box!important;
        }
        .standalone-record-content .timeline .step,
        .standalone-record-content .timeline .workflow-phase-step,
        .standalone-record-content .clickable-workflow-timeline .step,
        .standalone-record-content .clickable-workflow-timeline .workflow-phase-step{
            flex:1 1 0!important;
            min-width:0!important;
            max-width:none!important;
            width:auto!important;
            padding:0 8px!important;
            text-align:center!important;
            box-sizing:border-box!important;
        }
        .standalone-record-content .timeline .step:before,
        .standalone-record-content .clickable-workflow-timeline .step:before{
            left:-50%!important;
            right:50%!important;
            top:18px!important;
        }
        .standalone-record-content .timeline .step-dot,
        .standalone-record-content .clickable-workflow-timeline .step-dot{
            width:36px!important;
            height:36px!important;
            border-radius:999px!important;
            font-size:13px!important;
            font-weight:750!important;
            margin:0 auto!important;
        }
        .standalone-record-content .timeline .step-label,
        .standalone-record-content .clickable-workflow-timeline .step-label{
            display:block!important;
            width:100%!important;
            max-width:150px!important;
            margin:10px auto 0!important;
            color:#64748b;
            font-size:13px!important;
            line-height:1.3!important;
            white-space:normal!important;
            overflow-wrap:normal!important;
            word-break:normal!important;
            hyphens:none!important;
        }
        .standalone-record-content .timeline .step.current .step-label,
        .standalone-record-content .timeline .workflow-phase-step.current .step-label,
        .standalone-record-content .timeline .workflow-phase-step.selected .step-label,
        .standalone-record-content .clickable-workflow-timeline .step.current .step-label,
        .standalone-record-content .clickable-workflow-timeline .workflow-phase-step.current .step-label,
        .standalone-record-content .clickable-workflow-timeline .workflow-phase-step.selected .step-label{
            color:#2563eb!important;
            font-weight:760!important;
        }
        .standalone-record-content .timeline .step.done .step-label,
        .standalone-record-content .clickable-workflow-timeline .step.done .step-label{
            color:#475569!important;
        }
        @media(max-width:1180px){
            .standalone-record-content .timeline,
            .standalone-record-content .clickable-workflow-timeline{
                justify-content:flex-start!important;
                overflow-x:auto!important;
                padding-left:14px!important;
                padding-right:14px!important;
            }
            .standalone-record-content .timeline .step,
            .standalone-record-content .timeline .workflow-phase-step,
            .standalone-record-content .clickable-workflow-timeline .step,
            .standalone-record-content .clickable-workflow-timeline .workflow-phase-step{
                flex:0 0 128px!important;
                min-width:128px!important;
                padding:0 6px!important;
            }
        }
        @media(max-width:640px){
            .standalone-record-content .timeline .step,
            .standalone-record-content .timeline .workflow-phase-step,
            .standalone-record-content .clickable-workflow-timeline .step,
            .standalone-record-content .clickable-workflow-timeline .workflow-phase-step{
                flex-basis:118px!important;
                min-width:118px!important;
            }
            .standalone-record-content .timeline .step-label,
            .standalone-record-content .clickable-workflow-timeline .step-label{
                font-size:12px!important;
            }
        }
    `;
    document.head.appendChild(flowtrackFullWidthTimelineStyles);

    /* Final Job Detail Redesign: matches the supplied Overview, Workflow and Documents reference screens. */
    function flowtrackJobDetailSlug(job) {
        return String(job?.id || '').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
    }

    function flowtrackJobAllTasks(job) {
        const phaseOrder = new Map(flowtrackActivePhases(job || {}).map((phase, index) => [Number(phase.id), Number(phase.sequence || index + 1)]));
        return flowtrackTasksForJob(job || {})
            .map((task) => flowtrackHydrateTaskAssignee(task))
            .sort((left, right) => {
                const leftPhase = phaseOrder.get(Number(left.phaseId)) ?? Number(left.phaseIndex ?? 999);
                const rightPhase = phaseOrder.get(Number(right.phaseId)) ?? Number(right.phaseIndex ?? 999);
                if (leftPhase !== rightPhase) return leftPhase - rightPhase;
                const leftSort = Number(left.sortOrder ?? left.sort_order ?? left.taskPackItemSortOrder ?? 9999);
                const rightSort = Number(right.sortOrder ?? right.sort_order ?? right.taskPackItemSortOrder ?? 9999);
                if (leftSort !== rightSort) return leftSort - rightSort;
                return String(left.id || '').localeCompare(String(right.id || ''), undefined, { numeric: true });
            });
    }

    function flowtrackJobTeamMembers(job, jobTasks = null) {
        const pool = [];
        const addByName = (name) => {
            const label = String(name || '').trim();
            if (!label || label.toLowerCase() === 'unassigned') return;
            if (!pool.some((item) => item.name.toLowerCase() === label.toLowerCase())) pool.push({ name: label, initials: userInitials(label) });
        };
        // The header team bubbles must represent only the people assigned to Tasks
        // on this Job, not every workspace member, owner, coordinator or cached team.
        (jobTasks || flowtrackJobAllTasks(job)).forEach((task) => addByName(flowtrackResolveTaskAssigneeName(task, flowtrackResolveTaskAssigneeId(task)) || task.assignee));
        return pool.slice(0, 5);
    }

    function flowtrackCurrentUserAssignedToJob(job, jobTasks = null) {
        if (isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin) return true;
        return (jobTasks || flowtrackJobAllTasks(job)).some((task) => boardTaskMatchesAssignee(task, currentUser?.id, currentUser?.name));
    }

    function flowtrackPhaseProgress(phaseTasks) {
        const total = phaseTasks.length;
        const completed = phaseTasks.filter((task) => taskIsCompleted(task)).length;
        return { total, completed, percent: total ? Math.round((completed / total) * 100) : 0 };
    }

    function flowtrackTaskStatusTone(status) {
        const value = String(status || '').toLowerCase();
        if (/complete|approved|passed/.test(value)) return 'b-green';
        if (/block|overdue|risk|attention/.test(value)) return 'b-red';
        if (/progress|transit|ready/.test(value)) return 'b-blue';
        if (/wait|pending|revision/.test(value)) return 'b-amber';
        return 'b-gray';
    }

    function flowtrackJobHealthPill(job) {
        const label = job.health || job.status || 'On Track';
        const tone = /block|risk|overdue|attention|delay/i.test(label) ? 'b-red' : /complete/i.test(label) ? 'b-green' : /wait|pending/i.test(label) ? 'b-amber' : 'b-blue';
        return `<span class="badge ${tone}">${html(label)}</span>`;
    }

    function flowtrackJobPhaseDocumentRequirements(job, activePhases = null) {
        const phases = activePhases || flowtrackActivePhases(job);
        return phases
            .filter((phase) => String(phase.document || '').trim())
            .map((phase) => {
                const label = String(phase.document || '').trim();
                const present = jobDocsFor(job).some((documentRecord) => documentMatchesKeywords(documentRecord, [label, phase.name, phase.short]));
                return { phase, label, present };
            });
    }

    function flowtrackJobMetricCard(icon, label, title, foot = '', extra = '') {
        return `<div class="job-v3-metric"><div class="job-v3-metric-icon">${icon}</div><div><span>${html(label)}</span><b>${title}</b>${foot ? `<small>${foot}</small>` : ''}</div>${extra}</div>`;
    }

    function flowtrackMiniTimeline(job, activePhases, currentPhase) {
        if (!activePhases.length) return '';
        return `<div class="job-v3-mini-timeline">${activePhases.map((phase, index) => {
            const isCurrent = Number(phase.id) === Number(job.phaseId);
            const isDone = currentPhase ? Number(phase.sequence || index + 1) < Number(currentPhase.sequence || 0) : index < Number(job.phase || 0);
            const state = isDone ? 'done' : isCurrent ? 'current' : '';
            return `<button type="button" class="job-v3-mini-step ${state}" data-detailtab="1" data-flow-phase="${html(phase.id)}" data-flow-job="${html(job.id)}"><span>${isDone ? '✓' : Number(phase.sequence || index + 1)}</span><b>${html(phase.short || phase.name)}</b></button>`;
        }).join('')}</div>`;
    }

    function flowtrackTaskRowCompact(rawTask, index = null) {
        const task = flowtrackHydrateTaskAssignee(rawTask || {});
        const assigneeId = flowtrackResolveTaskAssigneeId(task);
        const assigneeName = flowtrackResolveTaskAssigneeName(task, assigneeId);
        const dueValue = task.due || task.dueDate || '';
        const dueClassName = dueValue && dueValue < today ? 'due overdue' : '';
        const progressValue = Math.max(0, Math.min(100, Number(task.progress || 0)));
        return `<tr class="job-v3-task-line" data-task="${html(task.id)}"><td>${index !== null ? html(index + 1) : ''}</td><td><b>${html(task.title || task.id)}</b><small>${html(task.id)}${task.documentRequirement ? ` · ${html(task.documentRequirement.categoryName || task.documentRequirement.name || '')}` : ''}</small></td><td><span class="avatar">${userInitials(assigneeName || 'U')}</span> ${html(assigneeName || 'Unassigned')}</td><td class="${dueClassName}">${dueValue ? formatDate(dueValue) : '—'}</td><td><div class="job-v3-task-progress"><span style="width:${progressValue}%"></span></div>${progressValue}%</td><td>${statusBadge(task.status)}</td></tr>`;
    }


    function flowtrackPhaseAccordion(job, allTasks, activePhases, currentPhase, expandedCurrent = true) {
        return `<div class="job-v3-phase-stack">${activePhases.map((phase, index) => {
            const phaseTasks = flowtrackTasksForPhase(job, phase.id, allTasks).map((task) => flowtrackHydrateTaskAssignee(task));
            const progress = flowtrackPhaseProgress(phaseTasks);
            const current = Number(phase.id) === Number(job.phaseId);
            const done = progress.total > 0 && progress.completed >= progress.total;
            const open = expandedCurrent ? current : false;
            return `<section class="job-v3-phase-block ${current ? 'current' : ''} ${done ? 'done' : ''}"><button type="button" class="job-v3-phase-head" data-job-v3-toggle-phase><span class="job-v3-phase-caret">${open ? '⌄' : '›'}</span><span class="job-v3-phase-dot">${done ? '✓' : Number(phase.sequence || index + 1)}</span><b>${html(phase.name)}</b><small>${progress.completed} of ${progress.total} complete</small><i><span style="width:${progress.percent}%"></span></i>${current ? '<em>Current phase</em>' : ''}</button><div class="job-v3-phase-body ${open ? '' : 'hidden'}"><table class="job-v3-task-table"><thead><tr><th>#</th><th>Task</th><th>Assignee</th><th>Due date</th><th>Progress</th><th>Status</th></tr></thead><tbody>${phaseTasks.map((task, taskIndex) => flowtrackTaskRowCompact(task, taskIndex)).join('') || '<tr><td colspan="6"><div class="empty-state">No tasks configured in Task Pack Setup for this phase.</div></td></tr>'}</tbody></table></div></section>`;
        }).join('')}</div>`;
    }


    function flowtrackWorkflowHistoryTable(job, activePhases, allTasks) {
        return `<div class="job-v3-panel-card"><div class="job-v3-panel-head"><div><h3>Phase history</h3><p>Progress, ownership and gate results</p></div></div><table class="job-v3-history-table"><thead><tr><th>Phase</th><th>Status</th><th>Owner</th><th>Dates</th><th>Duration</th><th>Tasks</th><th>Gates</th><th></th></tr></thead><tbody>${activePhases.map((phase, index) => {
            const history = flowtrackPhaseHistoryFor(job, phase) || {};
            const phaseTasks = flowtrackTasksForPhase(job, phase.id, allTasks);
            const progress = flowtrackPhaseProgress(phaseTasks);
            const current = Number(phase.id) === Number(job.phaseId);
            const passed = progress.total ? progress.completed >= progress.total : Number(phase.sequence || index + 1) < Number((activePhases.find((item) => Number(item.id) === Number(job.phaseId)) || {}).sequence || 0);
            const statusLabel = history.statusLabel || (current ? (job?.phaseReadinessLabel || 'Current') : passed ? 'Completed' : 'Not started');
            const statusTone = /complete|passed/i.test(statusLabel) ? 'b-green' : /block|overdue/i.test(statusLabel) ? 'b-red' : current ? 'b-purple' : 'b-gray';
            const dates = history.enteredAt || history.completedAt ? `${history.enteredAt ? flowtrackFormatDateTime(history.enteredAt).split(',')[0] : '—'} – ${history.completedAt ? flowtrackFormatDateTime(history.completedAt).split(',')[0] : current ? 'Now' : '—'}` : current ? 'Started' : '—';
            return `<tr class="${current ? 'current' : ''}" data-flow-phase="${html(phase.id)}" data-flow-job="${html(job.id)}"><td><b>${Number(phase.sequence || index + 1)}</b> ${html(phase.short || phase.name)}</td><td><span class="badge ${statusTone}">${html(statusLabel)}</span></td><td>${html(history.changedBy || job.owner || '—')}</td><td>${dates}</td><td>${history.duration || (current ? 'In progress' : '—')}</td><td>${progress.completed}/${progress.total}</td><td>${passed ? '<span class="badge b-green">Passed</span>' : current ? '<span class="badge b-amber">Pending</span>' : '<span class="badge b-gray">Pending</span>'}</td><td>›</td></tr>`;
        }).join('')}</tbody></table><div id="flowtrackPhaseHistoryPanel" class="job-v3-phase-history-inline">${flowtrackRenderPhaseHistoryPanel(job, job.phaseId || activePhases[0]?.id, allTasks)}</div></div>`;
    }

    function flowtrackCurrentGatesCard(job, currentTasks, requirements) {
        const taskProgress = flowtrackPhaseProgress(currentTasks);
        const missing = requirements.filter((item) => !item.present);
        const shippingConfirmed = Number(job.phase || 0) >= 8 || /ship|invoice|complete/i.test(phaseNameFor(job));
        const finalInvoiceApproved = jobDocsFor(job).some((documentRecord) => documentMatchesKeywords(documentRecord, ['invoice', 'final invoice']));
        return `<div class="job-v3-panel-card job-v3-gates-card"><div class="job-v3-panel-head"><div><h3>Current phase gates</h3><p>${html(phaseNameFor(job))}</p></div></div><div class="job-v3-gate-list"><div class="job-v3-gate ${shippingConfirmed ? 'passed' : ''}"><span>${shippingConfirmed ? '✓' : '○'}</span><b>Shipment confirmed</b><small>${shippingConfirmed ? 'Entry requirement passed' : 'Waiting confirmation'}</small></div><div class="job-v3-gate ${finalInvoiceApproved ? 'passed' : ''}"><span>${finalInvoiceApproved ? '✓' : '○'}</span><b>Final invoice approved</b><small>${finalInvoiceApproved ? 'Requirement satisfied' : 'Invoice is pending'}</small></div>${missing.slice(0, 2).map((item) => `<div class="job-v3-gate blocked"><span>!</span><b>${html(item.label)}</b><small>Missing required document</small></div>`).join('')}<div class="job-v3-gate ${taskProgress.total && taskProgress.completed >= taskProgress.total ? 'passed' : ''}"><span>${taskProgress.total && taskProgress.completed >= taskProgress.total ? '✓' : '○'}</span><b>All phase tasks completed</b><small>${taskProgress.completed} of ${taskProgress.total} complete</small></div></div>${job.phaseReadiness === 'ready' ? `<button type="button" class="primary" data-next-phase="${html(job.id)}">Complete phase</button>` : '<button type="button" class="ghost" disabled>Resolve requirements to continue</button>'}</div>`;
    }

    function flowtrackDocumentSizeLabel(size) {
        const bytes = Number(size || 0);
        if (!bytes) return '0 KB';
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(bytes < 100 * 1024 ? 1 : 0)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    function flowtrackDocumentFileBadge(documentRecord) {
        const name = String(documentRecord?.name || '').toLowerCase();
        if (name.endsWith('.pdf') || /pdf/i.test(String(documentRecord?.mimeType || ''))) return 'PDF';
        const extension = (name.split('.').pop() || '').trim();
        return extension && extension.length <= 4 ? extension.toUpperCase() : 'FILE';
    }

    function flowtrackDocumentStatusMeta(documentRecord) {
        const raw = String(documentRecord?.status || documentRecord?.state || '').trim();
        const status = raw || (documentRecord?.final ? 'Approved' : 'Approved');
        const tone = /missing|reject|block|action/i.test(status) ? 'b-red' : /wait|pending|review/i.test(status) ? 'b-amber' : /approved|final|satisfied|received/i.test(status) ? 'b-green' : 'b-gray';
        return { status, tone };
    }

    function flowtrackJobDocumentPreviewCard(documentRecord, job) {
        if (!documentRecord) {
            return '<div class="job-v3-doc-preview" data-job-doc-preview-card><b>No document selected</b></div>';
        }
        const statusMeta = flowtrackDocumentStatusMeta(documentRecord);
        const fileBadge = flowtrackDocumentFileBadge(documentRecord);
        const uploadedDate = documentRecord.date ? flowtrackFormatDateTime(documentRecord.date).replace(' 00:00', '') : '—';
        const fileMeta = `${fileBadge} · ${flowtrackDocumentSizeLabel(documentRecord.size)} · ${html(documentRecord.version || 'v1')}`;
        const openAction = documentRecord.previewUrl
            ? `<a class="primary" href="${html(documentRecord.previewUrl)}" target="_blank" rel="noopener">Open</a>`
            : `<button type="button" class="primary" data-doc="${html(documentRecord.id)}">Open</button>`;
        const downloadAction = documentRecord.downloadUrl
            ? `<a class="icon-btn" href="${html(documentRecord.downloadUrl)}" title="Download">⇩</a>`
            : '<button type="button" class="icon-btn" disabled>⇩</button>';
        return `<div class="job-v3-doc-preview" data-job-doc-preview-card>
            <div class="job-v3-doc-preview-top"><span class="job-v3-pdf">${html(fileBadge)}</span><div><b>${html(documentRecord.name || 'Document')}</b><small>${fileMeta}</small></div></div>
            <div class="job-v3-doc-preview-actions"><span class="badge ${statusMeta.tone}">${html(statusMeta.status)}</span>${openAction}${downloadAction}<button type="button" class="icon-btn" data-doc="${html(documentRecord.id)}">•••</button></div>
            <dl>
                <dt>Phase</dt><dd>${html(documentRecord.phase || phaseNameFor(job) || '—')}</dd>
                <dt>Document type</dt><dd>${html(documentRecord.type || 'Document')}</dd>
                <dt>Owner</dt><dd><span class="avatar">${userInitials(documentRecord.uploadedBy || job?.owner || 'U')}</span> ${html(documentRecord.uploadedBy || job?.owner || '—')}</dd>
                <dt>Uploaded</dt><dd>${html(uploadedDate)}</dd>
                <dt>Requirement</dt><dd>${documentRecord.taskRequirement ? html(documentRecord.taskRequirement) : 'Satisfied'}</dd>
                ${documentRecord.taskRequirement ? `<dt>Related task</dt><dd>${html(documentRecord.taskRequirement)}</dd>` : ''}
            </dl>
            ${documentRecord.versionNumber && Number(documentRecord.versionNumber) > 1 ? `<button type="button" class="job-v3-version-link" data-doc="${html(documentRecord.id)}">View version history (${html(documentRecord.versionNumber)}) ›</button>` : ''}
        </div>`;
    }

    function flowtrackNormalizeJobDocumentRequirementsForView(job, activePhases = null) {
        const configured = flowtrackToArray(jobDocumentRequirements(job));
        if (configured.length) {
            const seen = new Set();
            return configured.filter((requirement) => {
                const key = String(requirement.key || `${requirement.phaseId}:${requirement.taskPackItemId || 0}:${requirement.categoryId}`);
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            }).map((requirement) => ({
                key: requirement.key,
                phase: { id: requirement.phaseId, name: requirement.phaseName || phaseNameFor(job) },
                label: requirement.categoryName || 'Required document',
                sourceLabel: requirement.sourceLabel || 'Workflow requirement',
                present: Boolean(requirement.present),
                requiredForReadiness: requirement.requiredForReadiness !== false,
            }));
        }
        return flowtrackJobPhaseDocumentRequirements(job, activePhases).map((requirement) => ({
            ...requirement,
            sourceLabel: requirement.phase?.name || 'Workflow requirement',
            requiredForReadiness: true,
        }));
    }

    function flowtrackDocumentsPanel(job, allJobDocs, activePhases) {
        const requirements = flowtrackNormalizeJobDocumentRequirementsForView(job, activePhases);
        const missing = requirements.filter((item) => !item.present);
        const approved = allJobDocs.filter((documentRecord) => flowtrackDocumentStatusMeta(documentRecord).tone === 'b-green');
        const selected = allJobDocs[0] || null;
        const receivedCount = Math.max(0, requirements.length - missing.length);
        const completionPercent = requirements.length ? Math.round((receivedCount / requirements.length) * 100) : 0;
        const selectedCount = selected ? 1 : 0;
        const missingRequirement = missing[0] || null;
        const addMissingLabel = missingRequirement ? `Add ${String(missingRequirement.label || 'document').toLowerCase()}` : 'Add document';
        const allFilesChip = `<span class="active">All files <b>${allJobDocs.length}</b></span>`;
        const rows = allJobDocs.map((documentRecord, index) => {
            const statusMeta = flowtrackDocumentStatusMeta(documentRecord);
            const fileBadge = flowtrackDocumentFileBadge(documentRecord);
            const selectedRow = index === 0;
            return `<tr class="${selectedRow ? 'selected' : ''}" data-job-doc-row="${html(documentRecord.id)}">
                <td><input type="checkbox" ${selectedRow ? 'checked' : ''} aria-label="Select ${html(documentRecord.name || 'document')}"></td>
                <td class="job-v3-doc-name-cell"><span class="job-v3-pdf">${html(fileBadge)}</span><div><b>${html(documentRecord.name || 'Document')}</b><small>${fileBadge} · ${flowtrackDocumentSizeLabel(documentRecord.size)}</small></div></td>
                <td>${html(documentRecord.phase || phaseNameFor(job) || '—')}</td>
                <td>${html(documentRecord.type || 'File')}</td>
                <td>${html(documentRecord.version || 'v1')}</td>
                <td><span class="avatar">${userInitials(documentRecord.uploadedBy || job.owner || 'U')}</span> ${html(documentRecord.uploadedBy || job.owner || '—')}</td>
                <td><span class="badge ${statusMeta.tone}">${html(statusMeta.status)}</span></td>
                <td>${documentRecord.date ? html(formatDate(documentRecord.date)) : '—'}</td>
                <td><button type="button" class="mini-btn" data-doc="${html(documentRecord.id)}">•••</button></td>
            </tr>`;
        }).join('') || '<tr><td colspan="9"><div class="empty-state">No documents have been uploaded for this Job yet.</div></td></tr>';

        return `<section id="detailPanel8" class="detail-panel job-v3-doc-template">
            <div class="job-v3-doc-head">
                <div><h3>Documents</h3><p>${allJobDocs.length} files · ${receivedCount} of ${requirements.length} required documents received</p></div>
                <div class="job-v3-doc-top-actions">
                    <button type="button" class="ghost" data-job-doc-request="${html(job.id)}">Request document</button>
                    ${canFlow('documents', 'create') ? `<div class="job-v3-upload-split"><button type="button" class="primary" data-upload-job="${html(job.id)}">⇧ Upload document</button><button type="button" class="primary job-v3-upload-caret" data-upload-job="${html(job.id)}">⌄</button></div>` : ''}
                </div>
            </div>
            ${missing.length ? `<div class="job-v3-doc-alert job-v3-doc-alert-template"><div class="job-v3-alert-icon">!</div><div><b>${missing.length} required document${missing.length === 1 ? ' is' : 's are'} missing</b><span>${html(missingRequirement?.label || 'Document')} is required to complete ${html(missingRequirement?.phase?.name || phaseNameFor(job))}.</span></div><dl><dt>Owner:</dt><dd>${html(job.owner || 'Unassigned')}</dd><dt>Due</dt><dd>${job.delivery ? html(formatDate(job.delivery)) : '—'}</dd></dl>${canFlow('documents', 'create') ? `<button type="button" class="ghost" data-upload-job="${html(job.id)}">${html(addMissingLabel)}</button>` : ''}</div>` : ''}
            <div class="job-v3-doc-chips">${allFilesChip}<span>Required <b>${requirements.length}</b></span><span class="danger-chip">Needs action <b>${missing.length}</b></span><span class="success-chip">Approved <b>${approved.length}</b></span><span>Missing <b>${missing.length}</b></span><span>Recently updated <b>${allJobDocs.slice(0, 2).length}</b></span></div>
            <div class="job-v3-doc-filters job-v3-doc-filters-template">
                <input placeholder="Search documents or file names">
                <select><option>Phase</option>${activePhases.map((phase) => `<option>${html(phase.name)}</option>`).join('')}</select>
                <select><option>Document type</option>${requirements.map((item) => `<option>${html(item.label)}</option>`).join('')}</select>
                <select><option>Status</option><option>Approved</option><option>Needs action</option><option>Missing</option></select>
                <select><option>Owner</option>${flowtrackJobTeamMembers(job).map((member) => `<option>${html(member.name)}</option>`).join('')}</select>
                <button type="button" class="ghost job-v3-more-filter">☰ More filters</button>
                <select><option>Last updated</option><option>Newest first</option><option>Oldest first</option></select>
                <div class="job-v3-view-toggle"><button type="button" class="active">☷ List</button><button type="button">▦ Grid</button></div>
            </div>
            <div class="job-v3-doc-layout">
                <div class="job-v3-panel-card job-v3-doc-main-card"><div class="job-v3-panel-head"><div><h3>Job documents</h3></div></div>
                    <table class="job-v3-doc-table"><thead><tr><th><input type="checkbox" aria-label="Select all documents"></th><th>Document</th><th>Phase</th><th>Type</th><th>Version</th><th>Owner</th><th>Status</th><th>Updated</th><th>•••</th></tr></thead><tbody>${rows}</tbody></table>
                    <div class="job-v3-doc-foot"><span>${allJobDocs.length} documents</span><span>·</span><span>${selectedCount} selected</span><div class="job-v3-doc-bulk-actions">${selected ? `${selected.downloadUrl ? `<a class="ghost" href="${html(selected.downloadUrl)}">⇩ Download</a>` : '<button type="button" class="ghost" disabled>⇩ Download</button>'}<button type="button" class="ghost" data-job-doc-request="${html(job.id)}">✈ Request update</button>${canFlow('documents', 'delete') ? `<button type="button" class="danger-btn" data-doc="${html(selected.id)}">⌫</button>` : ''}` : ''}${canFlow('documents', 'create') ? `<button type="button" class="link-btn" data-upload-job="${html(job.id)}">＋ Add document</button>` : ''}</div></div>
                </div>
                <aside class="job-v3-doc-side">${flowtrackJobDocumentPreviewCard(selected, job)}<div class="job-v3-required-list"><div class="job-v3-required-head"><h3>Required documents</h3><span>${receivedCount} of ${requirements.length} received</span></div><div class="job-v3-required-progress"><span style="width:${completionPercent}%"></span></div>${requirements.map((item) => `<div class="job-v3-required-row ${item.present ? 'passed' : 'missing'}"><span>${item.present ? '✓' : '!'}</span><b>${html(item.label)}</b><small>${item.present ? 'Received' : 'Missing'}</small>${!item.present ? '<em>Blocks phase completion</em>' : ''}</div>`).join('') || '<div class="empty-state">No required documents configured.</div>'}<button type="button" class="job-v3-version-link">View requirement details ›</button></div></aside>
            </div>
        </section>`;
    }

    function flowtrackActivityPanel(job, activity, allTasks) {
        const events = [
            ...activity.map((item) => ({ actor: item.actor || 'System', title: item.title || 'Activity', text: item.text || '', time: item.time || '' })),
            ...allTasks.flatMap((task) => (task.history || []).map((entry) => ({ actor: entry.actor || 'System', title: entry.description || `Task ${task.id} updated`, text: `${task.id} · ${task.title || ''}`, time: entry.occurredAt ? flowtrackFormatDateTime(entry.occurredAt) : entry.time || '' })))
        ].slice(0, 25);
        return `<section id="detailPanel9" class="detail-panel"><div class="job-v3-panel-card"><div class="job-v3-panel-head"><div><h3>Activity</h3><p>Recent task, phase and document changes</p></div></div><div class="job-v3-activity-list">${events.map((a) => `<div class="job-v3-activity-item"><span class="avatar">${userInitials(a.actor)}</span><div><b>${html(a.actor)}</b><p>${html(a.title)}</p><small>${html(a.text)}${a.time ? ` · ${html(a.time)}` : ''}</small></div></div>`).join('') || '<div class="empty-state">No Activity recorded yet.</div>'}</div></div></section>`;
    }

    jobPanels = function (j, jt, jd) {
        const wf = getWorkflow(j.workflow || 'standard') || { phases: [], name: 'Workflow' };
        const allTasks = Array.isArray(jt) && jt.length ? jt : flowtrackJobAllTasks(j);
        const activePhases = flowtrackActivePhases(j);
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(j.phaseId)) || activePhases[Math.max(0, Number(j.phase || 0))] || activePhases[0] || null;
        const currentTasks = jobCurrentPhaseTasks(j, allTasks);
        const allDocs = jobDocsFor(j);
        const requirements = flowtrackJobPhaseDocumentRequirements(j, activePhases);
        const missingRequirements = requirements.filter((item) => !item.present);
        const latestUpdate = (j.activity || [])[0];
        const totalQty = Number(j.qty || jobProductItems(j).reduce((sum, item) => sum + Number(item.qty || 0), 0));
        const completedTasks = allTasks.filter((task) => taskIsCompleted(task)).length;
        const openTasks = allTasks.length - completedTasks;
        const overdue = j.delivery && j.delivery < today;
        const currentProduct = j.product || jobProductItems(j)[0]?.name || 'Product not recorded';
        const nextTask = currentTasks.find((task) => !taskIsCompleted(task)) || allTasks.find((task) => !taskIsCompleted(task));
        const alertHtml = overdue || /block|risk|attention|delay/i.test(`${j.health || ''} ${j.status || ''}`)
            ? `<div class="job-v3-alert"><span>!</span><div><b>${overdue ? `Delivery overdue by ${Math.max(1, Math.ceil((new Date(today).getTime() - new Date(j.delivery).getTime()) / 86400000))} days` : `${html(j.health || 'Needs attention')}`}</b><small>${overdue ? `Deadline was ${formatDate(j.delivery)}` : html(j.next || 'This Job needs attention.')}</small></div><div><b>Next required action</b><small>${html(j.next || nextTask?.title || 'Review current phase')}</small></div>${nextTask ? `<button type="button" class="primary" data-task="${html(nextTask.id)}">Open task</button>` : ''}</div>`
            : '';
        const team = flowtrackJobTeamMembers(j, allTasks);
        const overviewTasks = flowtrackPhaseAccordion(j, allTasks, activePhases, currentPhase, true);
        const commercialStatus = typeof boardJobCommercialLabel === 'function' ? boardJobCommercialLabel(j) : (j.price ? 'Invoice pending' : 'Quotation pending');
        return `<section id="detailPanel0" class="detail-panel active job-v3-overview">${alertHtml}<div class="job-v3-metric-grid">${flowtrackJobMetricCard('▣', 'Current phase', `<span>${html(phaseNameFor(j))}</span>`, `Phase ${Number(currentPhase?.sequence || j.phase || 0)} of ${activePhases.length}`)}${flowtrackJobMetricCard('↗', 'Overall progress', `<span>${Number(j.progress || 0)}%</span>`, `<div class="job-v3-progress"><span style="width:${Math.max(0, Math.min(100, Number(j.progress || 0)))}%"></span></div>`)}${flowtrackJobMetricCard('$', 'Commercial', `<span>${j.price ? currency(j.price, j.currency) : 'Not recorded'}</span>`, `<em>${html(commercialStatus)}</em>`)}${flowtrackJobMetricCard('⬡', 'Product', `<span>${html(currentProduct)}</span>`, `${totalQty.toLocaleString()} units`)}</div>${flowtrackMiniTimeline(j, activePhases, currentPhase)}<div class="job-v3-overview-grid"><main class="job-v3-main-panel"><div class="job-v3-panel-head"><div><h3>All phase tasks</h3><p>${allTasks.length} tasks across ${activePhases.length} phases</p></div><div class="job-v3-summary-pills"><span>${completedTasks} completed</span><span>${openTasks} open</span><span>${currentTasks.length} current</span></div></div><div class="job-v3-task-source-note">Tasks are generated from Task Pack Setup and reflect the latest configured assignee, due date and status.</div>${overviewTasks}</main><aside class="job-v3-side-panel"><div class="job-v3-next-card"><h3>Next required action</h3><b>${html(nextTask?.title || j.next || 'Review current phase')}</b><div>${nextTask ? `<span class="avatar">${userInitials(nextTask.assignee || 'U')}</span> ${html(nextTask.assignee || 'Unassigned')} · <span class="due ${nextTask.due && nextTask.due < today ? 'overdue' : ''}">${nextTask.due ? formatDate(nextTask.due) : 'No due date'}</span>` : html(j.next || 'No pending task')}</div>${nextTask ? `<button type="button" class="link-btn" data-task="${html(nextTask.id)}">View task details</button>` : ''}</div><div class="job-v3-side-card"><h3>Job details</h3><dl><dt>Workflow</dt><dd>${html(wf.name || 'Workflow')}</dd><dt>Job started</dt><dd>${html(phaseNameFor(j, j.createdFromPhase ?? 0))}</dd><dt>Job lead</dt><dd>${html(j.owner || 'Unassigned')}</dd><dt>Delivery</dt><dd class="${overdue ? 'due overdue' : ''}">${j.delivery ? formatDate(j.delivery) : '—'}</dd></dl></div><div class="job-v3-side-card"><h3>Latest update</h3><p>${latestUpdate ? `${html(latestUpdate.title || 'Updated')} · ${html(latestUpdate.time || '')}` : html(j.next || 'No update recorded yet.')}</p><button type="button" class="link-btn" data-detailtab="9">View activity</button></div></aside></div></section>
<section id="detailPanel2" class="detail-panel job-v3-tasks"><div class="job-v3-panel-card"><div class="job-v3-panel-head"><div><h3>All phase tasks</h3><p>Generated from Task Pack Setup with the latest assignee, due date, progress and status.</p></div></div>${flowtrackPhaseAccordion(j, allTasks, activePhases, currentPhase, true)}</div></section>
<section id="detailPanel1" class="detail-panel job-v3-workflow"><div class="job-v3-workflow-title"><div><h3>Workflow</h3><p>${html(wf.name || 'Workflow')} · Version ${html(wf.version || '3')} · <button type="button" class="link-btn" data-detailtab="2">View phase tasks</button></p></div></div>${/block|risk|attention|overdue|delay/i.test(`${j.health || ''} ${j.status || ''}`) || missingRequirements.length ? `<div class="job-v3-alert"><span>!</span><div><b>${html(phaseNameFor(j))} is blocked</b><small>${html((j.phaseReadinessDetails?.blockers || []).join(' ') || missingRequirements[0]?.label || j.next || 'Resolve open requirements before completion.')}</small></div><div><b>${missingRequirements.length} blocker${missingRequirements.length === 1 ? '' : 's'}</b><small>${openTasks} open tasks</small></div></div>` : ''}<div class="job-v3-workflow-metrics">${flowtrackJobMetricCard(Number(currentPhase?.sequence || j.phase || 0), 'Current phase', `<span>${html(phaseNameFor(j))}</span>`, `Phase ${Number(currentPhase?.sequence || j.phase || 0)} of ${activePhases.length}`)}${flowtrackJobMetricCard('▣', 'Phase started', `<span>${flowtrackFormatDateTime(flowtrackPhaseHistoryFor(j, currentPhase)?.enteredAt).split(',')[0]}</span>`, `${currentTasks.length} tasks in phase`)}${flowtrackJobMetricCard('□', 'Target completion', `<span class="${overdue ? 'due overdue' : ''}">${j.delivery ? formatDate(j.delivery) : 'No target'}</span>`, overdue ? 'Overdue' : 'On schedule')}${flowtrackJobMetricCard('⌁', 'Overall progress', `<span>${Number(j.progress || 0)}%</span>`, `<div class="job-v3-progress"><span style="width:${Number(j.progress || 0)}%"></span></div>`)}</div><div class="timeline clickable-workflow-timeline job-v3-workflow-line">${activePhases.map((phase, index) => flowtrackPhaseTimelineStep(j, phase, index, currentPhase, j.skippedPhases || [])).join('')}</div><div class="job-v3-workflow-grid">${flowtrackWorkflowHistoryTable(j, activePhases, allTasks)}${flowtrackCurrentGatesCard(j, currentTasks, requirements)}</div></section>
<section id="detailPanel7" class="detail-panel job-v3-commercial"><div class="job-v3-panel-card"><div class="job-v3-panel-head"><div><h3>Commercial</h3><p>Quotation, invoice and payment status.</p></div></div><div class="job-v3-metric-grid">${flowtrackJobMetricCard('$', 'Commercial value', `<span>${j.price ? currency(j.price, j.currency) : 'Not recorded'}</span>`, html(commercialStatus))}${flowtrackJobMetricCard('▣', 'Client', `<span>${html(clientName(j.client))}</span>`, html(j.order || j.id))}${flowtrackJobMetricCard('○', 'Invoice', `<span>${html(commercialStatus)}</span>`, `${allDocs.filter((doc) => documentMatchesKeywords(doc, ['invoice', 'payment', 'receipt'])).length} commercial documents`)}</div><div style="margin-top:16px">${documentPanelFor(j, 'Invoice Documents', ['invoice', 'payment', 'receipt', 'commercial'])}</div></div></section>
${flowtrackDocumentsPanel(j, allDocs, activePhases)}
${flowtrackActivityPanel(j, j.activity || [], allTasks)}`;
    };

    function flowtrackBindJobDocumentTemplate(content, job) {
        if (!content || !job) return;
        content.querySelectorAll('[data-job-doc-row]').forEach((row) => {
            flowtrackBindOnce(row, 'job-doc-row-select', 'click', (event) => {
                if (event.target.closest('button,a')) return;
                const documentRecord = docs.find((item) => String(item.id) === String(row.dataset.jobDocRow) || Number(item.dbId) === Number(row.dataset.jobDocRow));
                if (!documentRecord) return;
                row.closest('tbody')?.querySelectorAll('[data-job-doc-row]').forEach((otherRow) => {
                    otherRow.classList.toggle('selected', otherRow === row);
                    const checkbox = otherRow.querySelector('input[type="checkbox"]');
                    if (checkbox) checkbox.checked = otherRow === row;
                });
                const preview = content.querySelector('[data-job-doc-preview-card]');
                if (preview) {
                    preview.outerHTML = flowtrackJobDocumentPreviewCard(documentRecord, job);
                    bindCommon();
                }
            });
        });
        content.querySelectorAll('[data-job-doc-request]').forEach((button) => {
            flowtrackBindOnce(button, 'job-doc-request', 'click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                showToast('Document request workflow is ready visually. Upload or update the required document from this page.');
            });
        });
        content.querySelectorAll('.job-v3-doc-filters-template input').forEach((input) => {
            flowtrackBindOnce(input, 'job-doc-search', 'input', () => {
                const term = normalizedStatusValue(input.value);
                content.querySelectorAll('[data-job-doc-row]').forEach((row) => {
                    row.classList.toggle('hidden', Boolean(term) && !normalizedStatusValue(row.textContent).includes(term));
                });
            });
        });
    }

    function flowtrackApplyJobDetailReferenceDesign(job) {
        const page = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .standalone-record-page`);
        const content = page?.querySelector('.standalone-record-content');
        if (!page || !content) return;
        page.classList.add('job-detail-v3-page');
        content.classList.add('job-detail-v3-content');
        const allTasks = flowtrackJobAllTasks(job);
        const allDocs = jobDocsFor(job);
        const header = content.querySelector('.drawer-head');
        if (header) {
            const team = flowtrackJobTeamMembers(job, allTasks);
            header.className = 'drawer-head job-v3-header';
            const canShowJobHeaderActions = flowtrackCurrentUserAssignedToJob(job, allTasks);
            const teamHtml = team.length ? `<div class="job-v3-team">${team.slice(0, 4).map((member) => `<span class="avatar" title="${html(member.name)}">${html(member.initials)}</span>`).join('')}${team.length > 4 ? `<span class="job-v3-more">+${team.length - 4}</span>` : ''}</div>` : '<div class="job-v3-team job-v3-team-empty"></div>';
            header.innerHTML = `<div class="job-v3-head-main"><div class="job-v3-breadcrumb">Jobs / ${html(job.id)}</div><h2>${html(job.title || job.id)}</h2><div class="job-v3-subtitle">${html(clientName(job.client))}${job.order ? ` · ${html(job.order)}` : ''}</div><div class="job-v3-head-meta">${flowtrackJobHealthPill(job)}${statusBadge(job.status || 'In Progress')}<span class="job-v3-lead-label">Job lead</span><span class="avatar">${userInitials(job.owner || 'U')}</span><b>${html(job.owner || 'Unassigned')}</b></div></div>${teamHtml}<div class="job-v3-header-actions">${canShowJobHeaderActions && canEditJobRecord() ? '<button type="button" class="ghost" data-job-v3-edit>✎ Edit job</button>' : ''}${canShowJobHeaderActions && canFlow('jobs', 'delete') ? '<button type="button" class="ghost" data-job-v3-actions>••• Job actions</button>' : ''}<button type="button" class="close-btn" data-standalone-back>×</button></div>`;
            header.querySelector('[data-job-v3-edit]')?.addEventListener('click', () => openEditJobModal(job));
            header.querySelector('[data-job-v3-actions]')?.addEventListener('click', () => openDeleteJobConfirmation(job));
        }
        const tabs = content.querySelector('.detail-tabs');
        if (tabs) {
            tabs.innerHTML = `<button class="detail-tab active" data-detailtab="0">Overview</button><button class="detail-tab" data-detailtab="2">Tasks <span>${allTasks.length}</span></button><button class="detail-tab" data-detailtab="1">Workflow</button><button class="detail-tab" data-detailtab="7">Commercial</button><button class="detail-tab" data-detailtab="8">Documents <span>${allDocs.length}</span></button><button class="detail-tab" data-detailtab="9">Activity</button>`;
        }
        bindStandaloneBack(page);
        bindStandaloneDetailTabs(content);
        activateScopedDetailTab(content, 0);
        content.querySelectorAll('[data-job-v3-toggle-phase]').forEach((button) => {
            if (button.dataset.boundToggle === '1') return;
            button.dataset.boundToggle = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                const body = button.closest('.job-v3-phase-block')?.querySelector('.job-v3-phase-body');
                body?.classList.toggle('hidden');
                const caret = button.querySelector('.job-v3-phase-caret');
                if (caret) caret.textContent = body?.classList.contains('hidden') ? '›' : '⌄';
            });
        });
        content.querySelector('[data-job-v3-expand-all]')?.addEventListener('click', (event) => {
            event.preventDefault();
            content.querySelectorAll('.job-v3-phase-body').forEach((body) => body.classList.remove('hidden'));
            content.querySelectorAll('.job-v3-phase-caret').forEach((caret) => { caret.textContent = '⌄'; });
        });
        bindJobDocumentUploadButtons(content, job, 8);
        flowtrackBindJobDocumentTemplate(content, job);
        applyActionPermissions();
    }

    const flowtrackJobDetailPreviousOpenJob = currentFlowtrackImplementation('openJob');
    registerFlowtrackExtension('openJob', 'openJob-reference-design-final', function (id) {
        flowtrackJobDetailPreviousOpenJob(id);
        const job = flowtrackFindJob(id);
        if (!job) return;
        setTimeout(() => flowtrackApplyJobDetailReferenceDesign(job), 0);
        if (job._detailLoaded) return;
        window.setTimeout(async () => {
            const content = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .standalone-record-content`);
            if (content) {
                const activePanel = content.querySelector('.detail-panel.active');
                activePanel?.insertAdjacentHTML('afterbegin', flowtrackInlineProgress('Refreshing task setup values…'));
            }
            try {
                const result = await flowtrackRefreshJobTasks(job);
                const updatedJob = flowtrackFindJob(result?.job?.id || id) || job;
                flowtrackEnsureJobPanels(updatedJob);
                flowtrackApplyJobDetailReferenceDesign(updatedJob);
            } catch (error) {
                document.querySelectorAll('.flowtrack-inline-progress').forEach((node) => node.remove());
            }
        }, 80);
    });


    const flowtrackJobDetailPreviousEnsurePanels = flowtrackEnsureJobPanels;
    flowtrackEnsureJobPanels = function (job, preferredTab = null) {
        flowtrackJobDetailPreviousEnsurePanels(job, preferredTab);
        flowtrackApplyJobDetailReferenceDesign(job);
        const content = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .standalone-record-content`);
        if (content && preferredTab !== null && preferredTab !== undefined) activateScopedDetailTab(content, preferredTab);
    };

    const flowtrackJobDetailReferenceStyles = document.createElement('style');
    flowtrackJobDetailReferenceStyles.textContent = `
        .job-detail-v3-page{max-width:1500px!important;padding-bottom:32px!important}.job-detail-v3-page>.standalone-record-head{display:none!important}.job-detail-v3-page .standalone-record-shell{border:0!important;box-shadow:none!important;border-radius:0!important;background:transparent!important;overflow:visible!important}.job-detail-v3-content{background:transparent!important;border-radius:0!important}.job-detail-v3-content .drawer-body{padding:0!important}.job-v3-header{display:grid!important;grid-template-columns:minmax(0,1fr) auto auto!important;gap:18px!important;align-items:start!important;padding:14px 22px 0!important;border:0!important;background:transparent!important}.job-v3-breadcrumb{color:#64748b;font-size:12px;margin-bottom:4px}.job-v3-head-main h2{font-size:25px!important;line-height:1.05!important;margin:0!important;font-weight:800!important;color:#0f172a!important}.job-v3-subtitle{color:#52627a;font-size:13px;margin-top:7px}.job-v3-head-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:13px}.job-v3-lead-label{color:#64748b;margin-left:14px}.job-v3-team{display:flex;align-items:center;gap:0;margin-top:42px}.job-v3-team .avatar{margin-left:-7px;border:2px solid #fff}.job-v3-team .avatar:first-child{margin-left:0}.job-v3-more{width:31px;height:31px;border-radius:50%;display:grid;place-items:center;background:#fff;border:1px solid #dbe3ef;color:#475569;font-size:12px;font-weight:700;margin-left:-7px}.job-v3-header-actions{display:flex;align-items:center;gap:10px}.job-v3-header-actions .ghost{height:34px!important;border-radius:8px!important;border-color:#b8cdf9!important;color:#1d4ed8!important;font-size:12px!important;font-weight:750!important;background:#fff!important;min-width:auto!important;padding:0 13px!important}.job-v3-header-actions .close-btn{background:transparent!important;border:0!important;font-size:22px!important;color:#334155!important}.job-detail-v3-content .detail-tabs{display:flex!important;gap:32px!important;margin:20px 0 14px!important;padding:0 22px!important;border-bottom:1px solid #dbe3ef!important;background:transparent!important}.job-detail-v3-content .detail-tab{border:0!important;background:transparent!important;border-radius:0!important;padding:12px 0 11px!important;color:#263247!important;font-size:14px!important;font-weight:760!important}.job-detail-v3-content .detail-tab.active{background:transparent!important;color:#2563eb!important;border-bottom:2px solid #2563eb!important}.job-detail-v3-content .detail-tab span{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:18px;border-radius:999px;background:#eef2f7;color:#475569;font-size:11px;margin-left:5px}.job-detail-v3-content #jobDetailPanels{padding:0 22px 26px!important}.job-v3-alert{display:grid;grid-template-columns:auto minmax(0,1fr) minmax(260px,.8fr) auto;align-items:center;gap:16px;border:1px solid #fecaca;background:#fff1f2;border-radius:10px;padding:12px 14px;margin:0 0 14px}.job-v3-alert>span{width:30px;height:30px;border-radius:50%;background:#dc2626;color:#fff;display:grid;place-items:center;font-weight:900}.job-v3-alert b{display:block;color:#b91c1c;font-weight:800}.job-v3-alert small{display:block;color:#334155;margin-top:3px}.job-v3-metric-grid,.job-v3-workflow-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}.job-v3-metric{display:grid;grid-template-columns:58px 1fr;gap:12px;align-items:center;border:1px solid #dbe3ef;background:#fff;border-radius:12px;padding:16px;min-height:92px}.job-v3-metric-icon{width:54px;height:54px;border-radius:50%;background:#eef5ff;color:#2563eb;display:grid;place-items:center;font-weight:900;font-size:22px}.job-v3-metric span{display:block;color:#64748b;font-size:12px}.job-v3-metric b{display:block;color:#0f172a;font-size:17px;line-height:1.2;margin-top:3px}.job-v3-metric small{display:block;color:#475569;font-size:13px;margin-top:4px}.job-v3-metric em{color:#dc2626;font-style:normal;font-weight:700}.job-v3-progress{height:7px;background:#e7ecf2;border-radius:999px;overflow:hidden;margin-top:6px}.job-v3-progress span{display:block;height:100%;background:#2563eb;border-radius:inherit}.job-v3-mini-timeline{display:flex;align-items:center;border:1px solid #dbe3ef;border-radius:12px;background:#fff;padding:12px 14px;margin:12px 0 14px;overflow-x:auto}.job-v3-mini-step{border:0;background:transparent;display:flex;align-items:center;gap:8px;color:#64748b;font-size:12px;font-weight:700;white-space:nowrap;position:relative;min-width:max-content}.job-v3-mini-step:not(:last-child){padding-right:28px;margin-right:10px}.job-v3-mini-step:not(:last-child):after{content:'';position:absolute;right:5px;top:50%;width:18px;height:2px;background:#cbd5e1}.job-v3-mini-step span{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;background:#e8eef6;color:#64748b}.job-v3-mini-step.done span{background:#15803d;color:#fff}.job-v3-mini-step.done:after{background:#15803d}.job-v3-mini-step.current span{background:#5b21b6;color:#fff}.job-v3-mini-step.current b{color:#5b21b6}.job-v3-overview-grid,.job-v3-workflow-grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:14px}.job-v3-main-panel,.job-v3-panel-card,.job-v3-side-card,.job-v3-next-card,.job-v3-doc-side>div{background:#fff;border:1px solid #dbe3ef;border-radius:12px;padding:14px}.job-v3-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.job-v3-panel-head h3,.job-v3-side-card h3,.job-v3-next-card h3,.job-v3-required-list h3,.job-v3-doc-head h3{margin:0;color:#0f172a;font-size:18px;font-weight:800}.job-v3-panel-head p,.job-v3-doc-head p{margin:3px 0 0;color:#64748b;font-size:13px}.job-v3-summary-pills{display:flex;gap:10px;flex-wrap:wrap}.job-v3-summary-pills span{background:#f1f5ff;color:#1d4ed8;border-radius:9px;padding:7px 12px;font-weight:700;font-size:12px}.job-v3-task-filters,.job-v3-doc-filters{display:grid;grid-template-columns:1.4fr 1fr 1fr auto;gap:10px;margin-bottom:12px}.job-v3-task-filters input,.job-v3-task-filters select,.job-v3-doc-filters input,.job-v3-doc-filters select{border:1px solid #dbe3ef;border-radius:8px;padding:10px;background:#fff}.job-v3-phase-stack{display:grid;gap:8px}.job-v3-phase-block{border:1px solid #dbe3ef;border-radius:9px;background:#fff;overflow:hidden}.job-v3-phase-block.current{border-color:#93c5fd}.job-v3-phase-head{width:100%;border:0;background:#fff;display:grid;grid-template-columns:22px 26px minmax(0,1fr) auto 220px auto;gap:10px;align-items:center;padding:10px 12px;text-align:left;color:#0f172a}.job-v3-phase-head b{font-size:14px}.job-v3-phase-head small{color:#64748b;font-weight:700}.job-v3-phase-head i{height:4px;background:#e5e7eb;border-radius:999px;overflow:hidden}.job-v3-phase-head i span{display:block;height:100%;background:#15803d}.job-v3-phase-head em{font-style:normal;color:#5b21b6;background:#f1eaff;border:1px solid #ddd6fe;border-radius:8px;padding:5px 9px;font-size:11px;font-weight:800}.job-v3-phase-dot{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;background:#e5e7eb;font-size:12px;font-weight:800}.job-v3-phase-block.done .job-v3-phase-dot{background:#15803d;color:#fff}.job-v3-phase-block.current .job-v3-phase-dot{background:#5b21b6;color:#fff}.job-v3-phase-body{border-top:1px solid #e5e7eb;padding:0 12px 12px}.job-v3-phase-body.hidden{display:none!important}.job-v3-task-table,.job-v3-history-table,.job-v3-doc-table{width:100%;border-collapse:collapse}.job-v3-task-table th,.job-v3-task-table td,.job-v3-history-table th,.job-v3-history-table td,.job-v3-doc-table th,.job-v3-doc-table td{border-bottom:1px solid #e5e7eb;text-align:left;padding:9px 8px;font-size:12px;vertical-align:middle}.job-v3-task-table th,.job-v3-history-table th,.job-v3-doc-table th{color:#64748b;text-transform:uppercase;letter-spacing:.05em;font-size:10px}.job-v3-task-line{cursor:pointer}.job-v3-task-line:hover{background:#f8faff}.job-v3-task-line small,.job-v3-doc-table small{display:block;color:#64748b;margin-top:3px}.job-v3-task-progress{display:inline-block;vertical-align:middle;width:72px;height:5px;background:#e5e7eb;border-radius:999px;margin-right:7px;overflow:hidden}.job-v3-task-progress span{display:block;height:100%;background:#2563eb}.job-v3-add-task{margin-top:10px}.job-v3-side-panel{display:grid;gap:14px;align-content:start}.job-v3-next-card{border-color:#f5c2d6;border-right-width:4px}.job-v3-next-card b{display:block;font-size:16px;margin:10px 0}.job-v3-next-card>div{color:#475569;margin-bottom:10px}.job-v3-side-card dl,.job-v3-doc-preview dl{display:grid;grid-template-columns:110px 1fr;gap:10px;margin:12px 0 0}.job-v3-side-card dt,.job-v3-doc-preview dt{color:#64748b}.job-v3-side-card dd,.job-v3-doc-preview dd{margin:0;font-weight:700;color:#0f172a}.job-v3-workflow-title{display:flex;justify-content:space-between;margin-bottom:10px}.job-v3-workflow-title h3{font-size:20px;margin:0}.job-v3-workflow-title p{margin:4px 0 0;color:#64748b}.job-v3-workflow-line.timeline{border:0!important;background:#fff!important;border-radius:12px!important;margin:0 0 14px!important;padding:15px 12px 20px!important}.job-v3-history-table tbody tr{cursor:pointer}.job-v3-history-table tbody tr.current{outline:1px solid #c4b5fd;background:#faf5ff}.job-v3-gates-card{align-self:start}.job-v3-gate-list{display:grid;gap:8px;margin-bottom:12px}.job-v3-gate{display:grid;grid-template-columns:22px minmax(0,1fr) auto;gap:9px;align-items:center;border:1px solid #e5e7eb;border-radius:9px;padding:9px}.job-v3-gate span{width:18px;height:18px;border-radius:50%;display:grid;place-items:center;border:1px solid #94a3b8;color:#64748b}.job-v3-gate.passed span{background:#16a34a;border-color:#16a34a;color:#fff}.job-v3-gate.blocked span{background:#dc2626;border-color:#dc2626;color:#fff}.job-v3-gate small{color:#64748b}.job-v3-hidden-history{margin-top:14px}.job-v3-doc-head{margin-bottom:10px}.job-v3-doc-alert{display:grid;grid-template-columns:auto 1fr;gap:6px;border:1px solid #fecaca;background:#fff7f7;border-radius:10px;padding:12px;margin-bottom:10px;color:#991b1b}.job-v3-doc-alert span{grid-column:2;color:#475569}.job-v3-doc-chips{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px}.job-v3-doc-chips span{border:1px solid #dbe3ef;border-radius:999px;background:#fff;padding:8px 12px;color:#475569;font-weight:700}.job-v3-doc-chips span.active{background:#eff6ff;color:#2563eb}.job-v3-doc-chips b{background:#eef2f7;border-radius:999px;padding:2px 7px;margin-left:6px}.job-v3-doc-filters{grid-template-columns:1.4fr .8fr .9fr .8fr .8fr}.job-v3-doc-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:14px}.job-v3-doc-table tr.selected{background:#eff6ff;outline:1px solid #bfdbfe}.job-v3-pdf{display:inline-grid;place-items:center;width:30px;height:28px;border-radius:5px;background:#ef4444;color:#fff;font-size:10px;font-weight:800;margin-right:8px}.job-v3-doc-foot{display:flex;justify-content:space-between;align-items:center;margin-top:12px;color:#64748b}.job-v3-doc-preview{display:grid;gap:10px}.job-v3-required-list{margin-top:14px!important}.job-v3-required-row{display:grid;grid-template-columns:20px 1fr auto;gap:8px;align-items:center;margin-top:8px}.job-v3-required-row span{width:18px;height:18px;border-radius:50%;display:grid;place-items:center;font-size:11px}.job-v3-required-row.passed span{background:#16a34a;color:#fff}.job-v3-required-row.missing span{background:#dc2626;color:#fff}.job-v3-required-row small{font-size:12px;color:#64748b}.job-v3-commercial .job-doc-list{margin-top:10px}.job-v3-activity-list{display:grid;gap:10px}.job-v3-activity-item{display:grid;grid-template-columns:36px 1fr;gap:10px;border:1px solid #e5e7eb;border-radius:10px;padding:10px}.job-v3-activity-item p{margin:2px 0;color:#0f172a}.job-v3-activity-item small{color:#64748b}.job-v3-overview .source-note,.job-v3-workflow .workflow-card-clean{display:none!important}@media(max-width:1180px){.job-v3-overview-grid,.job-v3-workflow-grid,.job-v3-doc-layout{grid-template-columns:1fr}.job-v3-metric-grid,.job-v3-workflow-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.job-v3-phase-head{grid-template-columns:22px 26px minmax(0,1fr);gap:8px}.job-v3-phase-head small,.job-v3-phase-head i,.job-v3-phase-head em{grid-column:3}}@media(max-width:760px){.job-v3-header{grid-template-columns:1fr!important;padding-left:14px!important;padding-right:14px!important}.job-v3-team{margin-top:0}.job-v3-header-actions{justify-content:flex-start}.job-detail-v3-content .detail-tabs{overflow-x:auto;gap:22px;padding:0 14px!important}.job-detail-v3-content #jobDetailPanels{padding-left:14px!important;padding-right:14px!important}.job-v3-metric-grid,.job-v3-workflow-metrics,.job-v3-task-filters,.job-v3-doc-filters{grid-template-columns:1fr}.job-v3-alert{grid-template-columns:auto 1fr}.job-v3-alert button{grid-column:1/-1}.job-v3-doc-table,.job-v3-task-table,.job-v3-history-table{min-width:760px}.job-v3-panel-card,.job-v3-main-panel{overflow-x:auto}}
    `;
    document.head.appendChild(flowtrackJobDetailReferenceStyles);

    const flowtrackJobDetailCompactFinalStyles = document.createElement('style');
    flowtrackJobDetailCompactFinalStyles.textContent = `
        /* Final compact Job Details polish: keeps the reference design, only tightens spacing/height. */
        .job-detail-v3-page{max-width:1440px!important;padding-bottom:18px!important}
        .job-v3-header{padding:8px 14px 0!important;gap:12px!important}
        .job-v3-breadcrumb{font-size:11px!important;margin-bottom:2px!important}
        .job-v3-head-main h2{font-size:22px!important;line-height:1.05!important}
        .job-v3-subtitle{font-size:12px!important;margin-top:5px!important}
        .job-v3-head-meta{margin-top:8px!important;gap:6px!important}
        .job-v3-head-meta .badge{padding:3px 8px!important;font-size:10px!important}
        .job-v3-lead-label{margin-left:8px!important;font-size:12px!important}
        .job-v3-team{margin-top:30px!important}
        .job-v3-team .avatar{width:30px!important;height:30px!important;font-size:10px!important}
        .job-v3-more{width:29px!important;height:29px!important;font-size:11px!important}
        .job-v3-header-actions{gap:7px!important}
        .job-v3-header-actions .ghost{height:30px!important;min-height:30px!important;padding:0 10px!important;font-size:11px!important;border-radius:7px!important}
        .job-v3-header-actions .close-btn{width:30px!important;height:30px!important;font-size:20px!important}
        .job-detail-v3-content .detail-tabs{gap:24px!important;margin:12px 0 10px!important;padding:0 14px!important}
        .job-detail-v3-content .detail-tab{padding:9px 0 8px!important;font-size:13px!important}
        .job-detail-v3-content .detail-tab span{min-width:19px!important;height:16px!important;font-size:10px!important;margin-left:4px!important}
        .job-detail-v3-content #jobDetailPanels{padding:0 14px 18px!important}
        .job-v3-alert{grid-template-columns:auto minmax(0,1fr) minmax(220px,.75fr) auto!important;gap:10px!important;padding:9px 12px!important;margin-bottom:10px!important;border-radius:9px!important}
        .job-v3-alert>span{width:27px!important;height:27px!important;font-size:13px!important}
        .job-v3-alert b{font-size:13px!important}
        .job-v3-alert small{font-size:12px!important;margin-top:1px!important}
        .job-v3-alert .primary{height:32px!important;min-height:32px!important;padding:0 11px!important;font-size:11px!important;border-radius:8px!important}
        .job-v3-metric-grid,.job-v3-workflow-metrics{gap:10px!important;margin-bottom:10px!important}
        .job-v3-metric{grid-template-columns:44px 1fr!important;gap:10px!important;min-height:70px!important;padding:11px 12px!important;border-radius:10px!important}
        .job-v3-metric-icon{width:40px!important;height:40px!important;font-size:17px!important}
        .job-v3-metric span{font-size:11px!important}
        .job-v3-metric b{font-size:14px!important;margin-top:1px!important}
        .job-v3-metric small{font-size:11.5px!important;margin-top:2px!important}
        .job-v3-progress{height:6px!important;margin-top:4px!important}
        .job-v3-mini-timeline{padding:8px 10px!important;margin:8px 0 10px!important;border-radius:10px!important}
        .job-v3-mini-step{gap:6px!important;font-size:11px!important}
        .job-v3-mini-step:not(:last-child){padding-right:20px!important;margin-right:6px!important}
        .job-v3-mini-step:not(:last-child):after{right:3px!important;width:14px!important}
        .job-v3-mini-step span{width:22px!important;height:22px!important;font-size:11px!important}
        .job-v3-overview-grid,.job-v3-workflow-grid{grid-template-columns:minmax(0,1fr) 300px!important;gap:10px!important}
        .job-v3-main-panel,.job-v3-panel-card,.job-v3-side-card,.job-v3-next-card,.job-v3-doc-side>div{padding:11px!important;border-radius:10px!important}
        .job-v3-panel-head{gap:8px!important;margin-bottom:8px!important}
        .job-v3-panel-head h3,.job-v3-side-card h3,.job-v3-next-card h3,.job-v3-required-list h3,.job-v3-doc-head h3{font-size:15px!important}
        .job-v3-panel-head p,.job-v3-doc-head p{font-size:11.5px!important;margin-top:1px!important}
        .job-v3-summary-pills{gap:7px!important}
        .job-v3-summary-pills span{padding:5px 9px!important;border-radius:8px!important;font-size:11px!important}
        .job-v3-task-filters,.job-v3-doc-filters{gap:8px!important;margin-bottom:9px!important}
        .job-v3-task-filters input,.job-v3-task-filters select,.job-v3-doc-filters input,.job-v3-doc-filters select{height:34px!important;padding:0 9px!important;border-radius:7px!important;font-size:12px!important}
        .job-v3-phase-stack{gap:6px!important}
        .job-v3-phase-block{border-radius:8px!important}
        .job-v3-phase-head{grid-template-columns:20px 23px minmax(0,1fr) auto 185px auto!important;gap:8px!important;padding:8px 10px!important}
        .job-v3-phase-head b{font-size:12.5px!important}
        .job-v3-phase-head small{font-size:11px!important}
        .job-v3-phase-head em{padding:3px 7px!important;font-size:10px!important;border-radius:7px!important}
        .job-v3-phase-dot{width:20px!important;height:20px!important;font-size:10.5px!important}
        .job-v3-phase-body{padding:0 9px 9px!important}
        .job-v3-task-table th,.job-v3-task-table td,.job-v3-history-table th,.job-v3-history-table td,.job-v3-doc-table th,.job-v3-doc-table td{padding:6px 6px!important;font-size:11.5px!important}
        .job-v3-task-table th,.job-v3-history-table th,.job-v3-doc-table th{font-size:9.5px!important}
        .job-v3-task-line small,.job-v3-doc-table small{margin-top:1px!important;font-size:10.5px!important}
        .job-v3-task-table .avatar,.job-v3-history-table .avatar,.job-v3-doc-table .avatar{width:24px!important;height:24px!important;font-size:9px!important}
        .job-v3-task-progress{width:58px!important;height:4px!important;margin-right:5px!important}
        .job-v3-add-task{margin-top:7px!important;font-size:12px!important}
        .job-v3-side-panel{gap:10px!important}
        .job-v3-next-card{border-right-width:3px!important}
        .job-v3-next-card b{font-size:14px!important;margin:6px 0!important}
        .job-v3-next-card>div{font-size:12px!important;margin-bottom:7px!important}
        .job-v3-side-card dl,.job-v3-doc-preview dl{grid-template-columns:90px 1fr!important;gap:7px!important;margin-top:8px!important;font-size:12px!important}
        .job-v3-workflow-title{margin-bottom:7px!important}
        .job-v3-workflow-title h3{font-size:17px!important}
        .job-v3-workflow-title p{font-size:12px!important;margin-top:2px!important}
        .job-v3-workflow-line.timeline{padding:10px 10px 14px!important;margin-bottom:10px!important}
        .job-v3-gate-list{gap:6px!important;margin-bottom:9px!important}
        .job-v3-gate{grid-template-columns:19px minmax(0,1fr) auto!important;gap:7px!important;padding:7px!important;border-radius:8px!important;font-size:11.5px!important}
        .job-v3-gate span{width:16px!important;height:16px!important;font-size:10px!important}
        .job-v3-gate small{font-size:10.5px!important}
        .job-v3-doc-alert{padding:9px 10px!important;margin-bottom:8px!important;border-radius:9px!important}
        .job-v3-doc-chips{gap:7px!important;margin-bottom:8px!important}
        .job-v3-doc-chips span{padding:5px 9px!important;font-size:11px!important}
        .job-v3-doc-layout{grid-template-columns:minmax(0,1fr) 320px!important;gap:10px!important}
        .job-v3-pdf{width:26px!important;height:24px!important;font-size:9px!important;margin-right:6px!important}
        .job-v3-doc-foot{margin-top:8px!important;font-size:11.5px!important}
        .job-v3-doc-preview{gap:7px!important}
        .job-v3-required-list{margin-top:10px!important}
        .job-v3-required-row{grid-template-columns:18px 1fr auto!important;gap:6px!important;margin-top:6px!important}
        .job-v3-required-row span{width:16px!important;height:16px!important;font-size:10px!important}
        .job-v3-required-row small{font-size:10.5px!important}
        .job-v3-activity-list{gap:7px!important}
        .job-v3-activity-item{grid-template-columns:30px 1fr!important;gap:8px!important;padding:8px!important;border-radius:8px!important}
        .job-v3-activity-item .avatar{width:28px!important;height:28px!important;font-size:10px!important}
        .job-v3-activity-item p{margin:1px 0!important;font-size:12px!important}
        .job-v3-activity-item small{font-size:11px!important}
        @media(max-width:1180px){.job-v3-overview-grid,.job-v3-workflow-grid,.job-v3-doc-layout{grid-template-columns:1fr!important}.job-v3-phase-head{grid-template-columns:20px 23px minmax(0,1fr)!important}.job-v3-phase-head small,.job-v3-phase-head i,.job-v3-phase-head em{grid-column:3!important}}
    `;
    document.head.appendChild(flowtrackJobDetailCompactFinalStyles);

    const flowtrackTaskDocumentAndPhaseHistoryStyles = document.createElement('style');
    flowtrackTaskDocumentAndPhaseHistoryStyles.textContent = `
        /* Task attachment view uses the same document-management language as the Job Documents tab. */
        .task-v3-doc-section{background:#f8fbff!important;border:1px solid #dbe7f7!important;border-radius:14px!important;padding:14px!important;margin-top:18px!important}
        .task-v3-doc-section .job-v3-doc-head{display:flex!important;align-items:flex-start!important;justify-content:space-between!important;gap:12px!important;margin-bottom:10px!important}
        .task-v3-doc-section .job-v3-doc-head h3{font-size:18px!important;margin:0!important;color:#0f172a!important;font-weight:800!important}
        .task-v3-doc-section .job-v3-doc-head p{font-size:12px!important;color:#64748b!important;margin:4px 0 0!important}
        .task-v3-doc-empty{display:grid!important;place-items:center!important;min-height:92px!important;color:#64748b!important;font-size:15px!important;background:#f8fafc!important;border:1px dashed #cbd5e1!important;border-radius:12px!important;margin:8px 0 12px!important;text-align:center!important}
        .task-v3-doc-layout{display:grid!important;grid-template-columns:minmax(0,1fr) 320px!important;gap:12px!important;align-items:start!important}
        .task-v3-doc-section .job-v3-panel-card,.task-v3-doc-section .job-v3-doc-preview,.task-v3-doc-section .job-v3-required-list,.task-v3-upload-panel{background:#fff!important;border:1px solid #dbe3ef!important;border-radius:12px!important;padding:12px!important}
        .task-v3-doc-table{min-width:820px!important}
        .task-v3-doc-table th,.task-v3-doc-table td{padding:8px 7px!important;font-size:12px!important}
        .task-v3-doc-table tr.selected{background:#eff6ff!important;outline:1px solid #bfdbfe!important}
        .task-v3-doc-side{display:grid!important;gap:12px!important}
        .task-v3-doc-section .job-v3-doc-preview .primary{height:34px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;text-decoration:none!important;border-radius:8px!important}
        .task-v3-upload-panel{margin-top:12px!important;display:grid!important;gap:8px!important}
        .task-v3-upload-panel .field{margin-bottom:8px!important}
        .task-v3-upload-panel input[type=file]{height:auto!important;padding:10px!important;background:#fff!important;border:1px solid #dbe3ef!important;border-radius:9px!important}
        .task-v3-upload-panel textarea{min-height:68px!important}
        .task-v3-doc-section .task-document-selected-preview{margin-top:10px!important;background:#eff6ff!important;border-color:#bfdbfe!important}
        .job-v3-phase-history-inline{margin-top:12px!important;border-top:1px solid #e5e7eb!important;padding-top:12px!important}
        .job-v3-phase-history-inline .phase-history-panel{border:0!important;background:transparent!important;padding:0!important;margin:0!important}
        .job-v3-phase-history-inline .phase-history-task-list{display:grid!important;gap:8px!important;margin-top:10px!important}
        .job-v3-phase-history-inline .phase-history-task-row{border:1px solid #dbe3ef!important;border-radius:9px!important;background:#fff!important;padding:9px 10px!important}
        .job-v3-phase-history-inline .phase-history-events{display:grid!important;gap:8px!important;margin-top:8px!important}
        .job-v3-hidden-history{display:none!important}
        @media(max-width:1180px){.task-v3-doc-layout{grid-template-columns:1fr!important}.task-v3-doc-section .job-v3-panel-card{overflow-x:auto!important}}
    `;
    document.head.appendChild(flowtrackTaskDocumentAndPhaseHistoryStyles);



    const flowtrackLatestJobsAndBoardExpandStyles = document.createElement('style');
    flowtrackLatestJobsAndBoardExpandStyles.textContent = `
        .ops-toolbar .ops-expand-toggle{min-width:138px!important;white-space:nowrap!important}
        .job-v3-team-empty{min-width:0!important;visibility:hidden!important}
    `;
    document.head.appendChild(flowtrackLatestJobsAndBoardExpandStyles);


    const flowtrackAttachmentTemplateStyles = document.createElement('style');
    flowtrackAttachmentTemplateStyles.textContent = `
        .task-document-attachment-card{background:#fff!important;border:1px solid #dbe3ef!important;border-radius:16px!important;padding:18px!important;box-shadow:0 6px 18px rgba(15,23,42,.035)!important}
        .task-attachment-template-head{display:grid!important;grid-template-columns:42px minmax(0,1fr) auto!important;gap:12px!important;align-items:center!important;margin-bottom:16px!important}
        .task-attachment-template-head h3{font-size:20px!important;line-height:1.1!important;margin:0!important;color:#0f172a!important;font-weight:800!important}
        .task-attachment-template-head p{font-size:13px!important;line-height:1.25!important;color:#64748b!important;margin:3px 0 0!important}
        .task-attachment-icon{width:36px!important;height:36px!important;border-radius:10px!important;background:#eff6ff!important;border:1px solid #bfdbfe!important;color:#2563eb!important;display:grid!important;place-items:center!important;font-size:20px!important;font-weight:800!important}
        .task-attachment-dropzone{position:relative!important;min-height:230px!important;border:1.5px dashed #b8c7dc!important;background:#f8fbff!important;border-radius:15px!important;display:grid!important;align-content:center!important;justify-items:center!important;text-align:center!important;gap:8px!important;padding:28px 16px!important;transition:.16s ease!important}
        .task-attachment-dropzone:hover,.task-attachment-dropzone.is-dragging{background:#eff6ff!important;border-color:#2563eb!important;box-shadow:0 0 0 4px rgba(37,99,235,.08)!important}
        .task-document-file-input{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important}
        .task-upload-cloud{font-size:43px!important;line-height:1!important;color:#2563eb!important;font-weight:800!important}
        .task-attachment-dropzone b{font-size:18px!important;color:#0f172a!important;font-weight:800!important}
        .task-attachment-dropzone span{font-size:13px!important;color:#94a3b8!important}
        .task-upload-actions{display:flex!important;gap:12px!important;align-items:center!important;justify-content:center!important;flex-wrap:wrap!important;margin-top:2px!important}
        .task-upload-actions .primary,.task-upload-actions .ghost{height:42px!important;min-width:150px!important;padding:0 18px!important;border-radius:9px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important}
        .task-attachment-dropzone small{display:block!important;margin-top:6px!important;color:#64748b!important;font-size:13px!important}
        .task-attachment-info{display:flex!important;align-items:center!important;gap:10px!important;background:#eff6ff!important;border:1px solid #dbeafe!important;border-radius:10px!important;padding:10px 12px!important;color:#334155!important;margin:10px 0 14px!important}
        .task-attachment-info span{width:22px!important;height:22px!important;border-radius:50%!important;border:1px solid #60a5fa!important;color:#2563eb!important;display:grid!important;place-items:center!important;font-weight:800!important;background:#fff!important}
        .task-attachment-info b{font-size:13px!important;font-weight:650!important}
        .task-document-selected-preview{display:grid!important;grid-template-columns:auto minmax(0,1fr)!important;gap:12px!important;align-items:center!important;border:1px solid #bfdbfe!important;background:#eff6ff!important;border-radius:12px!important;padding:10px 12px!important;margin-top:10px!important;text-align:left!important}
        .task-document-selected-preview img{width:52px!important;height:52px!important;border-radius:8px!important;object-fit:cover!important;border:1px solid #dbe3ef!important;background:#fff!important}
        .task-upload-progress{height:6px!important;background:#dbeafe!important;border-radius:999px!important;overflow:hidden!important;margin-top:7px!important}
        .task-upload-progress span{display:block!important;height:100%!important;background:#2563eb!important;border-radius:999px!important}
        .task-v3-doc-section .task-v3-upload-panel.task-document-attachment-card{margin-top:14px!important}
        .job-document-dropzone{min-height:210px!important}
        .ops-board-actions{display:flex!important;gap:10px!important;align-items:center!important;flex-wrap:wrap!important;justify-content:flex-end!important}
        .ops-board-actions .ops-expand-toggle{height:42px!important;min-width:150px!important;border-radius:10px!important;font-weight:750!important;background:#fff!important}
        .flowtrack-page-progress{position:fixed;left:0;right:0;top:0;z-index:9999;background:rgba(255,255,255,.95);box-shadow:0 1px 10px rgba(15,23,42,.08);padding:4px 12px 5px;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center}
        .flowtrack-page-progress.hidden{display:none!important}
        .flowtrack-page-progress-track{height:4px;background:#dbeafe;border-radius:999px;overflow:hidden}
        .flowtrack-page-progress-track span{display:block;height:100%;width:0;background:#2563eb;border-radius:999px;transition:width .22s ease}
        .flowtrack-page-progress small{font-size:11px;color:#475569;font-weight:700;white-space:nowrap}
        .flowtrack-inline-progress{border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:12px 14px;margin-bottom:14px}
        .flowtrack-inline-progress-copy{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:9px;color:#1e3a8a}
        .flowtrack-inline-progress-copy b{font-size:13px}.flowtrack-inline-progress-copy span{font-size:12px;color:#64748b}
        .flowtrack-inline-progress-track{height:7px;border-radius:999px;background:#dbeafe;overflow:hidden}.flowtrack-inline-progress-track span{display:block;height:100%;width:82%;border-radius:999px;background:#2563eb;animation:flowtrackProgressPulse 1s ease-in-out infinite alternate}
        @keyframes flowtrackProgressPulse{from{width:28%}to{width:94%}}
        .job-v3-task-source-note{border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;padding:9px 11px;color:#475569;font-size:12px;font-weight:650;margin:8px 0 12px}
        .job-v3-task-line{cursor:pointer}
        @media(max-width:760px){.task-attachment-template-head{grid-template-columns:36px 1fr!important}.task-attachment-template-head .badge{grid-column:1/-1;width:max-content}.task-attachment-dropzone{min-height:190px!important}.task-upload-actions .primary,.task-upload-actions .ghost{width:100%!important}.ops-board-actions{justify-content:flex-start!important}}
    `;
    document.head.appendChild(flowtrackAttachmentTemplateStyles);


    function flowtrackMergeDetailPayload(payload) {
        if (!payload || typeof payload !== 'object') return payload;
        const mergedJob = payload.job ? flowtrackUpsertRecord(jobs, payload.job) : null;
        if (payload.client) flowtrackUpsertRecord(clients, payload.client);
        if (payload.task) flowtrackUpsertRecord(tasks, payload.task);
        if (payload.document) flowtrackUpsertRecord(docs, payload.document);

        if (Array.isArray(payload.tasks)) {
            payload.tasks.forEach((task) => flowtrackUpsertRecord(tasks, task, false));
        }
        if (Array.isArray(payload.documents)) {
            payload.documents.forEach((document) => flowtrackUpsertRecord(docs, document, false));
        }
        if (Array.isArray(payload.jobs)) {
            payload.jobs.forEach((job) => flowtrackUpsertRecord(jobs, job, false));
        }
        return { ...payload, job: mergedJob || payload.job };
    }

    async function flowtrackEnsureJobDetail(identifier) {
        const current = jobs.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier));
        if (current?._detailLoaded) return current;
        const payload = flowtrackMergeDetailPayload(await api(`/flowtrack/template-api/jobs/${encodeURIComponent(identifier)}`, { cache: 'no-store' }));
        return payload?.job || current || null;
    }

    async function flowtrackEnsureTaskDetail(identifier) {
        const current = tasks.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier));
        if (current?._detailLoaded) return current;
        const payload = flowtrackMergeDetailPayload(await api(`/flowtrack/template-api/tasks/${encodeURIComponent(identifier)}`, { cache: 'no-store' }));
        return payload?.task || current || null;
    }

    async function flowtrackEnsureClientDetail(identifier) {
        const current = clients.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier) || String(item.code) === String(identifier));
        if (current?._detailLoaded) return current;
        const payload = flowtrackMergeDetailPayload(await api(`/flowtrack/template-api/clients/${encodeURIComponent(identifier)}`, { cache: 'no-store' }));
        return payload?.client || current || null;
    }

    async function flowtrackEnsureDocumentDetail(identifier) {
        const current = docs.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier));
        if (current?._detailLoaded) return current;
        const payload = flowtrackMergeDetailPayload(await api(`/flowtrack/template-api/documents/${encodeURIComponent(identifier)}`, { cache: 'no-store' }));
        return payload?.document || current || null;
    }

    const flowtrackPerformanceOpenJob = currentFlowtrackImplementation('openJob');
    registerFlowtrackExtension('openJob', 'openJob-performance-detail-loader', async function (id) {
        try {
            const job = await flowtrackEnsureJobDetail(id);
            if (!job) return showToast('Job could not be loaded.');
            return flowtrackPerformanceOpenJob(job.id || job.dbId);
        } catch (error) {
            showToast(error.message || 'Job could not be loaded.');
        }
    });

    const flowtrackPerformanceOpenTask = currentFlowtrackImplementation('openTask');
    registerFlowtrackExtension('openTask', 'openTask-performance-detail-loader', async function (id) {
        try {
            const task = await flowtrackEnsureTaskDetail(id);
            if (!task) return showToast('Task could not be loaded.');
            return flowtrackPerformanceOpenTask(task.id || task.dbId);
        } catch (error) {
            showToast(error.message || 'Task could not be loaded.');
        }
    });

    const flowtrackPerformanceOpenClient = openClient;
    openClient = async function (id) {
        try {
            const client = await flowtrackEnsureClientDetail(id);
            if (!client) return showToast('Client could not be loaded.');
            return flowtrackPerformanceOpenClient(client.id || client.code);
        } catch (error) {
            showToast(error.message || 'Client could not be loaded.');
        }
    };

    const flowtrackPerformanceOpenDocument = openDoc;
    openDoc = async function (id) {
        try {
            const documentRecord = await flowtrackEnsureDocumentDetail(id);
            if (!documentRecord) return showToast('Document could not be loaded.');
            return flowtrackPerformanceOpenDocument(documentRecord.id || documentRecord.dbId);
        } catch (error) {
            showToast(error.message || 'Document could not be loaded.');
        }
    };

    function flowtrackSegmentCollection(segment) {
        return ({ clients, jobs, tasks, documents: docs })[segment] || [];
    }

    async function flowtrackLoadNextSegment(segment) {
        const pagination = segmentPagination[segment];
        const nextUrl = pagination?.nextPageUrl;
        if (!nextUrl || segmentLoading.has(segment)) return;
        segmentLoading.add(segment);
        try {
            const page = await api(nextUrl, { cache: 'no-store' });
            const target = flowtrackSegmentCollection(segment);
            flowtrackToArray(page?.data).forEach((record) => flowtrackUpsertRecord(target, record, false));
            flowtrackToArray(page?.relatedJobs).forEach((job) => flowtrackUpsertRecord(jobs, job, false));
            flowtrackToArray(page?.relatedClients).forEach((client) => flowtrackUpsertRecord(clientLabels, client, false));
            segmentPagination[segment] = page?.pagination || null;
            renderPage(currentPage);
            applyActionPermissions();
        } catch (error) {
            showToast(error.message || 'More records could not be loaded.');
        } finally {
            segmentLoading.delete(segment);
        }
    }

    function flowtrackPaginationSegmentsForPage(page) {
        if (page === 'clients') return ['clients'];
        if (page === 'jobs') return ['jobs'];
        if (page === 'documents') return ['documents'];
        if (page === 'mywork') return ['tasks'];
        if (page === 'board') return ['jobs', 'tasks'];
        return [];
    }

    function flowtrackRenderPaginationControls(page) {
        const section = document.getElementById(`page-${page}`);
        if (!section) return;
        section.querySelectorAll('.flowtrack-load-more').forEach((node) => node.remove());
        const segments = flowtrackPaginationSegmentsForPage(page)
            .filter((segment) => Boolean(segmentPagination[segment]?.nextPageUrl));
        if (!segments.length) return;
        const controls = document.createElement('div');
        controls.className = 'flowtrack-load-more';
        controls.innerHTML = segments.map((segment) => `<button type="button" class="ghost" data-load-segment="${segment}">Load more ${segment === 'documents' ? 'documents' : segment}</button>`).join('');
        section.appendChild(controls);
        controls.querySelectorAll('[data-load-segment]').forEach((button) => button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = 'Loading…';
            await flowtrackLoadNextSegment(button.dataset.loadSegment);
        }));
    }

    const flowtrackPerformanceRenderPage = renderPage;
    renderPage = function (page) {
        const result = flowtrackPerformanceRenderPage(page);
        queueMicrotask(() => flowtrackRenderPaginationControls(page));
        return result;
    };

    const flowtrackPaginationStyles = document.createElement('style');
    flowtrackPaginationStyles.textContent = '.flowtrack-load-more{display:flex;justify-content:center;gap:10px;padding:14px 0 4px}.flowtrack-load-more .ghost{min-width:150px;height:36px;border-radius:8px;font-size:12px;font-weight:750}';
    document.head.appendChild(flowtrackPaginationStyles);

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

    const flowtrackDocumentTemplateEverywhereStyles = document.createElement('style');
    flowtrackDocumentTemplateEverywhereStyles.textContent = `
        .task-attachment-system-section{background:transparent!important;border:0!important;border-radius:0!important;padding:0!important;margin-top:18px!important}
        .task-attachment-system-section .task-v3-upload-panel{margin:0!important}
        .task-attachment-system-section .source-note{border-radius:14px!important}
        .task-attachment-existing-files{border-top:1px solid #e2e8f0!important;margin-top:12px!important;padding-top:12px!important;display:grid!important;gap:8px!important}
        .task-attachment-existing-files h4{margin:0 0 4px!important;font-size:14px!important;color:#0f172a!important}
        .task-attachment-existing-file{display:grid!important;grid-template-columns:auto minmax(0,1fr) auto!important;gap:10px!important;align-items:center!important;border:1px solid #dbe3ef!important;border-radius:10px!important;padding:9px 10px!important;background:#f8fbff!important}
        .task-attachment-existing-file b{display:block!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;font-size:13px!important;color:#0f172a!important}
        .task-attachment-existing-file small{display:block!important;color:#64748b!important;margin-top:2px!important}
        .job-create-attachment-card{margin-top:0!important}
        .job-create-attachment-card .job-attachment-list{margin-top:10px!important}
        .job-create-attachment-card .job-attachment-row{border-radius:10px!important;background:#f8fbff!important}
        .job-create-attachment-card .job-attachment-drop{padding:28px 16px!important}
        .job-create-attachment-card .job-attachment-drop.dragging{border-color:#2563eb!important;background:#eff6ff!important;box-shadow:0 0 0 4px rgba(37,99,235,.08)!important}
    `;
    document.head.appendChild(flowtrackDocumentTemplateEverywhereStyles);

    const flowtrackJobDocumentReferenceExactStyles = document.createElement('style');
    flowtrackJobDocumentReferenceExactStyles.textContent = `
        .job-v3-doc-template{background:transparent!important;color:#172033!important}
        .job-v3-doc-template .job-v3-doc-head{display:flex!important;align-items:flex-start!important;justify-content:space-between!important;gap:16px!important;margin:0 0 14px!important}
        .job-v3-doc-template .job-v3-doc-head h3{font-size:20px!important;line-height:1.1!important;font-weight:820!important;margin:0!important;color:#0f172a!important}
        .job-v3-doc-template .job-v3-doc-head p{font-size:13px!important;color:#64748b!important;margin:5px 0 0!important;font-weight:560!important}
        .job-v3-doc-top-actions{display:flex!important;align-items:center!important;gap:10px!important;flex-wrap:wrap!important;justify-content:flex-end!important}
        .job-v3-doc-top-actions .ghost,.job-v3-doc-top-actions .primary,.job-v3-upload-split .primary{height:36px!important;border-radius:8px!important;padding:0 14px!important;font-size:12px!important;font-weight:800!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;white-space:nowrap!important}
        .job-v3-doc-top-actions .ghost{background:#fff!important;border:1px solid #9ebcff!important;color:#2563eb!important}
        .job-v3-upload-split{display:flex!important;align-items:center!important;gap:2px!important}
        .job-v3-upload-split .primary{background:#2563eb!important;border-color:#2563eb!important;color:#fff!important}
        .job-v3-upload-split .job-v3-upload-caret{width:36px!important;min-width:36px!important;padding:0!important}
        .job-v3-doc-alert-template{display:grid!important;grid-template-columns:36px minmax(0,1fr) auto auto!important;align-items:center!important;gap:12px!important;border:1px solid #f7b4b4!important;background:#fff7f7!important;border-radius:8px!important;padding:12px 14px!important;margin:0 0 10px!important;color:#991b1b!important}
        .job-v3-doc-alert-template .job-v3-alert-icon{width:32px!important;height:32px!important;border-radius:50%!important;background:#ef233c!important;color:#fff!important;display:grid!important;place-items:center!important;font-weight:900!important;font-size:18px!important}
        .job-v3-doc-alert-template b{display:block!important;font-size:14px!important;color:#991b1b!important;font-weight:820!important;margin:0!important}
        .job-v3-doc-alert-template span{display:block!important;color:#334155!important;font-size:12px!important;margin-top:3px!important;grid-column:auto!important}
        .job-v3-doc-alert-template dl{display:grid!important;grid-template-columns:auto auto auto auto!important;gap:6px 12px!important;align-items:center!important;margin:0!important;color:#334155!important;font-size:12px!important;white-space:nowrap!important}
        .job-v3-doc-alert-template dt{color:#64748b!important;margin:0!important}.job-v3-doc-alert-template dd{margin:0!important;font-weight:750!important}
        .job-v3-doc-alert-template .ghost{height:32px!important;padding:0 14px!important;border-radius:7px!important;background:#fff!important;border:1px solid #9ebcff!important;color:#2563eb!important;font-size:12px!important;font-weight:800!important;white-space:nowrap!important}
        .job-v3-doc-template .job-v3-doc-chips{display:flex!important;gap:8px!important;align-items:center!important;flex-wrap:wrap!important;margin:0 0 10px!important}
        .job-v3-doc-template .job-v3-doc-chips span{height:32px!important;display:inline-flex!important;align-items:center!important;gap:6px!important;border:1px solid #d5e1f2!important;background:#fff!important;border-radius:999px!important;padding:0 13px!important;color:#475569!important;font-size:12px!important;font-weight:800!important}
        .job-v3-doc-template .job-v3-doc-chips span.active{background:#eff6ff!important;border-color:#9cc0ff!important;color:#2563eb!important}
        .job-v3-doc-template .job-v3-doc-chips .danger-chip{background:#fff7f7!important;border-color:#fecaca!important;color:#b91c1c!important}
        .job-v3-doc-template .job-v3-doc-chips .success-chip{background:#f0fdf4!important;border-color:#bbf7d0!important;color:#15803d!important}
        .job-v3-doc-template .job-v3-doc-chips b{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-width:22px!important;height:20px!important;border-radius:999px!important;background:#eef2f7!important;color:#475569!important;padding:0 6px!important;margin:0!important;font-size:11px!important}
        .job-v3-doc-filters-template{display:grid!important;grid-template-columns:minmax(220px,1.35fr) .48fr .6fr .48fr .5fr auto .55fr auto!important;gap:10px!important;align-items:center!important;margin:0 0 10px!important}
        .job-v3-doc-filters-template input,.job-v3-doc-filters-template select,.job-v3-doc-filters-template .ghost{height:34px!important;border:1px solid #dbe3ef!important;border-radius:6px!important;background:#fff!important;color:#1f2a3d!important;font-size:12px!important;padding:0 12px!important;box-shadow:none!important;min-width:0!important}
        .job-v3-doc-filters-template input{font-size:13px!important}.job-v3-doc-filters-template .job-v3-more-filter{font-weight:750!important;white-space:nowrap!important;color:#334155!important}
        .job-v3-view-toggle{height:34px!important;display:flex!important;align-items:center!important;border:1px solid #dbe3ef!important;border-radius:6px!important;background:#fff!important;overflow:hidden!important;white-space:nowrap!important}
        .job-v3-view-toggle button{height:100%!important;border:0!important;background:#fff!important;color:#475569!important;padding:0 10px!important;font-size:12px!important;font-weight:760!important}.job-v3-view-toggle button.active{background:#eff6ff!important;color:#2563eb!important}
        .job-v3-doc-template .job-v3-doc-layout{display:grid!important;grid-template-columns:minmax(0,1fr) 360px!important;gap:12px!important;align-items:start!important}
        .job-v3-doc-main-card,.job-v3-doc-template .job-v3-doc-side>div{background:#fff!important;border:1px solid #dbe3ef!important;border-radius:8px!important;box-shadow:none!important;padding:12px!important;overflow:hidden!important}
        .job-v3-doc-main-card .job-v3-panel-head{margin:0 0 8px!important}.job-v3-doc-main-card .job-v3-panel-head h3{font-size:17px!important;font-weight:820!important;margin:0!important}
        .job-v3-doc-template .job-v3-doc-table{width:100%!important;border-collapse:collapse!important;table-layout:auto!important;min-width:860px!important}
        .job-v3-doc-template .job-v3-doc-table th{height:34px!important;padding:6px 8px!important;border-bottom:1px solid #e2e8f0!important;color:#64748b!important;text-transform:uppercase!important;letter-spacing:.08em!important;font-size:10px!important;font-weight:820!important;background:#fff!important;text-align:left!important;white-space:nowrap!important}
        .job-v3-doc-template .job-v3-doc-table td{height:50px!important;padding:8px 8px!important;border-bottom:1px solid #e5e7eb!important;color:#334155!important;font-size:12px!important;vertical-align:middle!important;background:#fff!important;text-align:left!important}
        .job-v3-doc-template .job-v3-doc-table tr.selected td{background:#eff6ff!important;outline:0!important}.job-v3-doc-template .job-v3-doc-table tr:hover td{background:#f8fbff!important}
        .job-v3-doc-template .job-v3-doc-table input[type=checkbox]{width:14px!important;height:14px!important;border-radius:2px!important;accent-color:#2563eb!important}
        .job-v3-doc-name-cell{display:grid!important;grid-template-columns:34px minmax(0,1fr)!important;gap:10px!important;align-items:center!important;min-width:230px!important}.job-v3-doc-name-cell b{display:block!important;color:#0f172a!important;font-size:12px!important;font-weight:800!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}.job-v3-doc-name-cell small{display:block!important;color:#64748b!important;font-size:11px!important;margin-top:3px!important}
        .job-v3-doc-template .job-v3-pdf{width:28px!important;height:30px!important;border-radius:4px!important;background:#ef4444!important;color:#fff!important;font-size:9px!important;font-weight:900!important;display:inline-grid!important;place-items:center!important;margin:0!important;line-height:1!important;box-shadow:inset 0 -3px 0 rgba(0,0,0,.08)!important}
        .job-v3-doc-template .avatar{width:24px!important;height:24px!important;font-size:9px!important;margin-right:6px!important;vertical-align:middle!important}.job-v3-doc-template .badge{height:24px!important;padding:0 10px!important;border-radius:999px!important;font-size:11px!important;font-weight:820!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;white-space:nowrap!important}
        .job-v3-doc-template .mini-btn,.job-v3-doc-template .icon-btn{width:30px!important;height:30px!important;min-width:30px!important;border:1px solid #dbe3ef!important;border-radius:7px!important;background:#fff!important;color:#334155!important;font-size:12px!important;font-weight:800!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;text-decoration:none!important}.job-v3-doc-template .mini-btn:hover,.job-v3-doc-template .icon-btn:hover{background:#f8fafc!important}
        .job-v3-doc-foot{height:46px!important;display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:10px!important;margin:0!important;padding-top:8px!important;color:#64748b!important;font-size:12px!important}.job-v3-doc-bulk-actions{margin-left:auto!important;display:flex!important;align-items:center!important;gap:8px!important;flex-wrap:wrap!important}.job-v3-doc-bulk-actions .ghost,.job-v3-doc-bulk-actions .danger-btn,.job-v3-doc-bulk-actions .link-btn{height:32px!important;border-radius:7px!important;padding:0 12px!important;font-size:12px!important;font-weight:780!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;text-decoration:none!important;white-space:nowrap!important}.job-v3-doc-bulk-actions .ghost{background:#fff!important;border:1px solid #dbe3ef!important;color:#334155!important}.job-v3-doc-bulk-actions .danger-btn{width:32px!important;min-width:32px!important;padding:0!important;background:#fff!important;color:#dc2626!important;border:1px solid #fecaca!important}.job-v3-doc-bulk-actions .link-btn{border:0!important;background:transparent!important;color:#2563eb!important}
        .job-v3-doc-side{display:grid!important;gap:10px!important;align-content:start!important}.job-v3-doc-preview{display:grid!important;gap:10px!important}.job-v3-doc-preview-top{display:grid!important;grid-template-columns:40px minmax(0,1fr)!important;gap:10px!important;align-items:start!important}.job-v3-doc-preview-top b{display:block!important;font-size:13px!important;color:#0f172a!important;font-weight:820!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}.job-v3-doc-preview-top small{display:block!important;margin-top:5px!important;color:#64748b!important;font-size:11px!important}.job-v3-doc-preview-actions{display:flex!important;align-items:center!important;gap:8px!important;flex-wrap:wrap!important}.job-v3-doc-preview-actions .primary{height:32px!important;min-width:74px!important;border-radius:7px!important;font-size:12px!important;font-weight:820!important;text-decoration:none!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;background:#2563eb!important;color:#fff!important;border:1px solid #2563eb!important}.job-v3-doc-preview dl{display:grid!important;grid-template-columns:100px minmax(0,1fr)!important;gap:8px 10px!important;margin:6px 0 0!important;font-size:12px!important}.job-v3-doc-preview dt{color:#64748b!important;margin:0!important}.job-v3-doc-preview dd{margin:0!important;color:#0f172a!important;font-weight:730!important;min-width:0!important}.job-v3-version-link{border:0!important;background:transparent!important;color:#2563eb!important;font-size:12px!important;font-weight:760!important;text-align:left!important;padding:6px 0 0!important;cursor:pointer!important}
        .job-v3-required-list{margin-top:0!important;display:grid!important;gap:7px!important}.job-v3-required-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:10px!important}.job-v3-required-head h3{font-size:15px!important;color:#0f172a!important;font-weight:820!important;margin:0!important}.job-v3-required-head span{font-size:11px!important;color:#64748b!important;font-weight:760!important;white-space:nowrap!important}.job-v3-required-progress{height:5px!important;background:#e5e7eb!important;border-radius:999px!important;overflow:hidden!important;margin:0 0 4px!important}.job-v3-required-progress span{display:block!important;height:100%!important;background:#2563eb!important;border-radius:999px!important}.job-v3-required-row{display:grid!important;grid-template-columns:18px minmax(0,1fr) auto!important;gap:7px!important;align-items:center!important;margin:0!important;min-height:22px!important}.job-v3-required-row span{width:16px!important;height:16px!important;border-radius:50%!important;font-size:10px!important;font-weight:900!important;display:grid!important;place-items:center!important}.job-v3-required-row.passed span{background:#16a34a!important;color:#fff!important}.job-v3-required-row.missing span{background:#ef233c!important;color:#fff!important}.job-v3-required-row b{font-size:12px!important;color:#0f172a!important;font-weight:820!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}.job-v3-required-row small{font-size:11px!important;color:#64748b!important;text-align:right!important}.job-v3-required-row em{grid-column:2 / -1!important;color:#991b1b!important;font-size:10px!important;font-style:normal!important;margin-top:-5px!important}
        @media(max-width:1280px){.job-v3-doc-filters-template{grid-template-columns:minmax(220px,1fr) repeat(3,minmax(120px,.5fr))!important}.job-v3-doc-filters-template .job-v3-more-filter,.job-v3-doc-filters-template>select:nth-last-child(2),.job-v3-view-toggle{grid-column:auto!important}.job-v3-doc-template .job-v3-doc-layout{grid-template-columns:1fr!important}.job-v3-doc-template .job-v3-panel-card{overflow-x:auto!important}}
        @media(max-width:760px){.job-v3-doc-template .job-v3-doc-head,.job-v3-doc-alert-template{grid-template-columns:1fr!important}.job-v3-doc-top-actions{justify-content:flex-start!important}.job-v3-doc-filters-template{grid-template-columns:1fr!important}.job-v3-doc-foot{height:auto!important;align-items:flex-start!important;flex-wrap:wrap!important}.job-v3-doc-bulk-actions{margin-left:0!important;width:100%!important;justify-content:flex-start!important}}
    `;
    document.head.appendChild(flowtrackJobDocumentReferenceExactStyles);

})();
