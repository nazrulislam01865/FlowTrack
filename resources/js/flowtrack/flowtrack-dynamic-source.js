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

        
    /* CSS externalized: style. */

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
    function flowtrackCookie(name) {
        const prefix = `${encodeURIComponent(name)}=`;
        const row = String(document.cookie || '').split('; ').find((entry) => entry.startsWith(prefix));
        if (!row) return '';
        try { return decodeURIComponent(row.slice(prefix.length)); } catch (_) { return row.slice(prefix.length); }
    }

    // Laravel publishes an encrypted XSRF-TOKEN cookie. Mutating AJAX requests
    // mirror it into X-XSRF-TOKEN; no custom token refresh endpoint is required.
    const xsrfToken = () => flowtrackCookie('XSRF-TOKEN');
    let currentUser = null;
    let flowtrackBranding = window.FLOWTRACK_BRANDING && typeof window.FLOWTRACK_BRANDING === 'object'
        ? { ...window.FLOWTRACK_BRANDING }
        : {};
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
    let workspaceDataVersion = null;
    let realtimeWorkspaceRefreshTimer = null;
    let realtimeWorkspaceRefreshInFlight = false;
    let bootstrapGeneration = 0;
    let bootstrapAppliedGeneration = 0;


    function applyFlowtrackBranding(branding = flowtrackBranding) {
        flowtrackBranding = branding && typeof branding === 'object' ? { ...branding } : {};
        window.FLOWTRACK_BRANDING = { ...flowtrackBranding };

        const logoUrl = String(flowtrackBranding.logoUrl || '').trim();
        document.querySelectorAll('[data-flowtrack-brand-mark]').forEach((mark) => {
            mark.replaceChildren();
            if (logoUrl && flowtrackSafeUrl(logoUrl)) {
                const image = document.createElement('img');
                image.className = 'flowtrack-brand-image';
                image.src = logoUrl;
                image.alt = 'FlowTrack logo';
                mark.appendChild(image);
                mark.classList.add('has-brand-image');
            } else {
                mark.textContent = 'FT';
                mark.classList.remove('has-brand-image');
            }
        });

        const favicon = document.querySelector('#flowtrackFavicon');
        const faviconUrl = String(flowtrackBranding.faviconUrl || '/favicon.ico').trim();
        if (favicon && flowtrackSafeUrl(faviconUrl)) favicon.setAttribute('href', faviconUrl);
    }

    applyFlowtrackBranding(flowtrackBranding);
    const segmentPagination = { clients: null, jobs: null, tasks: null, documents: null };
    const segmentLoading = new Set();
    let bootstrapSegmentUrls = {};
    let pageContextBase = '/flowtrack/template-api/context';
    const flowtrackLoadedPages = new Set();
    const flowtrackPageLoads = new Map();
    const flowtrackSegmentProfiles = { clients: 0, jobs: 0, tasks: 0, documents: 0 };

    function invalidateBootstrapLoads() {
        bootstrapGeneration += 1;
    }

    function hasOwnBootstrapField(payload, field) {
        return Boolean(payload && typeof payload === 'object' && Object.prototype.hasOwnProperty.call(payload, field));
    }

    
    /* CSS externalized: taskFeedbackStyles. */

    
    /* CSS externalized: taskDocumentSelectionStyles. */


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

    function realtimeRefreshWouldInterruptEditing() {
        const route = typeof flowtrackInitialRoute === 'function' ? flowtrackInitialRoute() : null;
        if (route && ['job-new', 'job-edit', 'client-new', 'client-edit', 'workflow-new', 'workflow-edit', 'task-pack-new', 'task-pack-edit'].includes(route.type)) {
            return true;
        }

        const active = document.activeElement;
        if (!active) return false;
        const tag = String(active.tagName || '').toLowerCase();
        return active.isContentEditable || ['input', 'textarea', 'select'].includes(tag);
    }

    function refreshVisibleSurfaceAfterRealtime(route, previousPage) {
        updateBadges();

        if (route && ['task', 'job', 'client'].includes(route.type) && typeof flowtrackOpenInitialRoute === 'function') {
            flowtrackOpenInitialRoute(route);
            return;
        }

        const page = flowtrackNormalizePageName(previousPage || currentPage);
        if (page && canOpenPage(page) && document.getElementById(`page-${page}`)) {
            renderPage(page);
            applyActionPermissions();
            window.FlowtrackSearchableDropdown?.enhance?.(document.getElementById(`page-${page}`));
        }
    }

    async function refreshWorkspaceDataSilently() {
        if (realtimeWorkspaceRefreshInFlight || document.hidden) return;
        if (realtimeRefreshWouldInterruptEditing()) {
            scheduleWorkspaceDataRefresh(1500);
            return;
        }

        realtimeWorkspaceRefreshInFlight = true;
        const route = typeof flowtrackInitialRoute === 'function' ? flowtrackInitialRoute() : null;
        const previousPage = currentPage;
        try {
            flowtrackInvalidatePageData(previousPage);
            const data = await flowtrackEnsurePageData(previousPage, { force: true });
            if (!data) return;
            refreshVisibleSurfaceAfterRealtime(route, previousPage);
        } catch (error) {
            console.warn('FlowTrack workspace background refresh failed.', error);
        } finally {
            realtimeWorkspaceRefreshInFlight = false;
        }
    }

    function scheduleWorkspaceDataRefresh(delay = 450) {
        if (realtimeWorkspaceRefreshTimer) clearTimeout(realtimeWorkspaceRefreshTimer);
        realtimeWorkspaceRefreshTimer = setTimeout(() => void refreshWorkspaceDataSilently(), Math.max(100, Number(delay) || 450));
    }

    async function pollNotificationStatus() {
        if (document.hidden || !notificationAccess?.statusEndpoint) return;

        try {
            const payload = await api(notificationAccess.statusEndpoint, { cache: 'no-store' });
            const nextSignature = payload?.latest_signature ?? null;
            const nextUnread = Number(payload?.unread_count || 0);
            const nextWorkspaceVersion = Number(payload?.workspace_version || 0);

            if (nextWorkspaceVersion > 0) {
                if (workspaceDataVersion === null) {
                    workspaceDataVersion = nextWorkspaceVersion;
                } else if (nextWorkspaceVersion !== workspaceDataVersion) {
                    // Do not advance the local version until loadBootstrap()
                    // succeeds; failed refreshes will therefore retry safely.
                    scheduleWorkspaceDataRefresh();
                }
            }

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
            // This small AJAX heartbeat is the reliable fallback when Pusher is
            // absent, misconfigured, delayed, or its queue worker is unavailable.
        }
    }

    function startNotificationPolling() {
        if (notificationStatusTimer) clearInterval(notificationStatusTimer);
        const configured = Number(window.FLOWTRACK_PUSHER?.pollIntervalMs || 5000);
        const interval = Math.max(3000, Math.min(30000, Number.isFinite(configured) ? configured : 5000));
        void pollNotificationStatus();
        notificationStatusTimer = setInterval(() => void pollNotificationStatus(), interval);
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
        scheduleWorkspaceDataRefresh(250);
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
                        ...(xsrfToken() ? { 'X-XSRF-TOKEN': xsrfToken() } : {}),
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

    window.addEventListener(
        'flowtrack:pusher-ready',
        initializeRealtimeNotifications
    );

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
        const message = String(first || payload?.message || fallback);

        // Never expose SQL, stack traces, server paths, or framework internals
        // in a user-facing toast. The exception remains available in Laravel's
        // logs while the interface gives a useful recovery action.
        if (/SQLSTATE|unknown column|stack trace|vendor\/laravel|\/app\/Http\//i.test(message)) {
            return 'FlowTrack needs a database update. Ask an administrator to run the pending migrations, then try again.';
        }

        return message;
    }

    const activeMutationRequests = new Map();

    function api(url, options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        const isReadRequest = ['GET', 'HEAD', 'OPTIONS'].includes(method);
        const requestKey = `${method}:${String(url)}`;

        // A double click or repeated event binding must not create the same
        // record twice. Every caller receives the authoritative in-flight result.
        if (!isReadRequest && activeMutationRequests.has(requestKey)) {
            return activeMutationRequests.get(requestKey);
        }

        const request = (async () => {
            const headers = {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers || {}),
            };
            if (!isReadRequest && xsrfToken() && !headers['X-XSRF-TOKEN']) {
                headers['X-XSRF-TOKEN'] = xsrfToken();
            }

            let body = options.body;
            if (body && !(body instanceof FormData) && typeof body !== 'string') {
                headers['Content-Type'] = 'application/json';
                body = JSON.stringify(body);
            }

            const externalSignal = options.signal;
            const controller = new AbortController();
            const timeoutMs = Math.max(
                5000,
                Number(options.timeoutMs) || (body instanceof FormData ? 120000 : (isReadRequest ? 30000 : 60000)),
            );
            let timedOut = false;
            let retryCount = 0;
            const timeout = setTimeout(() => {
                timedOut = true;
                controller.abort();
            }, timeoutMs);
            const abortFromCaller = () => controller.abort();
            externalSignal?.addEventListener?.('abort', abortFromCaller, { once: true });

            const { timeoutMs: ignoredTimeout, signal: ignoredSignal, ...fetchOptions } = options;
            let response;
            try {
                while (true) {
                    try {
                        response = await fetch(url, {
                            credentials: 'same-origin',
                            ...fetchOptions,
                            headers,
                            body,
                            signal: controller.signal,
                        });
                    } catch (error) {
                        if (!isReadRequest || retryCount >= 1 || error?.name === 'AbortError') throw error;
                        retryCount += 1;
                        await new Promise((resolve) => setTimeout(resolve, 250));
                        continue;
                    }

                    if (isReadRequest && retryCount < 1 && [502, 503, 504].includes(response.status)) {
                        retryCount += 1;
                        await new Promise((resolve) => setTimeout(resolve, 250));
                        continue;
                    }
                    break;
                }
            } catch (error) {
                if (timedOut) {
                    const timeoutError = new Error('The request took too long. Your form is still open; check your connection and try again.');
                    timeoutError.status = 0;
                    throw timeoutError;
                }
                if (error?.name === 'AbortError') throw error;
                const networkError = new Error('FlowTrack could not reach the server. Your changes were not cleared; reconnect and try again.');
                networkError.status = 0;
                throw networkError;
            } finally {
                clearTimeout(timeout);
                externalSignal?.removeEventListener?.('abort', abortFromCaller);
            }

            const contentType = response.headers.get('content-type') || '';
            const payload = contentType.includes('application/json') ? await response.json() : null;

            if (!response.ok) {
                if (response.status === 401 && payload?.reason) {
                    setTimeout(() => sessionRedirect(payload.reason), 0);
                }
                if (response.status === 419) {
                    setTimeout(() => sessionRedirect('expired'), 0);
                }
                const fallback = response.status === 419
                    ? 'Your secure session expired. FlowTrack is returning you to sign in.'
                    : response.status === 413
                    ? 'This document is larger than the server upload limit. Use a file up to 20 MB or ask the administrator to apply the supplied PHP/Nginx upload configuration.'
                    : response.status >= 500
                        ? 'FlowTrack could not complete this action. Nothing was cleared; please try again.'
                        : `Request failed (${response.status}).`;
                const error = new Error(firstValidationMessage(payload, fallback));
                error.payload = payload;
                error.status = response.status;
                throw error;
            }

            if (!isReadRequest) {
                // A mutation makes every bootstrap request that started before
                // this response obsolete, preventing a stale UI overwrite.
                invalidateBootstrapLoads();
            }

            return payload;
        })();

        if (!isReadRequest) {
            activeMutationRequests.set(requestKey, request);
            request.finally(() => activeMutationRequests.delete(requestKey)).catch(() => {});
        }

        return request;
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
        const actions = capabilities?.[module]?.actions || [];
        if (actions.includes(action) || actions.includes('manage')) return true;
        if (action === 'edit_own' && actions.includes('edit_all')) return true;
        if (action === 'assign' && actions.includes('edit_all')) return true;
        return false;
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
        if (!data || typeof data !== 'object') return;

        if (hasOwnBootstrapField(data, 'currentUser')) currentUser = data.currentUser || null;
        if (hasOwnBootstrapField(data, 'segments')) bootstrapSegmentUrls = data.segments || {};
        if (hasOwnBootstrapField(data, 'contextBase')) pageContextBase = String(data.contextBase || pageContextBase);
        if (hasOwnBootstrapField(data, 'users')) replaceArray(users, data.users);
        if (hasOwnBootstrapField(data, 'boardAssigneeUsers')) replaceArray(boardAssigneeUsers, data.boardAssigneeUsers || data.users);
        if (hasOwnBootstrapField(data, 'clients')) replaceArray(clients, data.clients);
        if (hasOwnBootstrapField(data, 'clientOptions')) {
            flowtrackToArray(data.clientOptions).forEach((client) => flowtrackUpsertRecord(clientLabels, client, false));
        }
        if (hasOwnBootstrapField(data, 'jobs')) replaceArray(jobs, data.jobs);
        if (hasOwnBootstrapField(data, 'boardJobIds')) boardJobIds = new Set(flowtrackToArray(data.boardJobIds).map((id) => Number(id)));
        if (hasOwnBootstrapField(data, 'boardAccess')) boardAccess = data.boardAccess || { isAdministrator: false, taskVisibility: 'assigned_job_tasks' };
        if (hasOwnBootstrapField(data, 'tasks')) {
            replaceArray(tasks, data.tasks);
            tasks.forEach(flowtrackHydrateTaskAssignee);
        }
        if (hasOwnBootstrapField(data, 'documents')) replaceArray(docs, data.documents);
        if (hasOwnBootstrapField(data, 'notifications')) replaceArray(notifications, data.notifications);
        if (hasOwnBootstrapField(data, 'activities')) replaceArray(dashboardActivities, data.activities);
        if (hasOwnBootstrapField(data, 'workflows')) replaceArray(workflowTemplates, flowtrackNormalizeWorkflows(data.workflows));
        if (hasOwnBootstrapField(data, 'taskPacks')) {
            taskPacks = flowtrackToArray(data.taskPacks).filter((pack) => pack && typeof pack === 'object');
        }
        if (hasOwnBootstrapField(data, 'capabilities')) {
            capabilities = data.capabilities && typeof data.capabilities === 'object' ? data.capabilities : {};
        }
        if (hasOwnBootstrapField(data, 'summaryMetrics')) {
            const incomingMetrics = data.summaryMetrics && typeof data.summaryMetrics === 'object' && !Array.isArray(data.summaryMetrics) ? data.summaryMetrics : {};
            summaryMetrics = { ...summaryMetrics, ...incomingMetrics };
        }
        if (hasOwnBootstrapField(data, 'notificationRules')) notificationRules = flowtrackToArray(data.notificationRules);
        if (hasOwnBootstrapField(data, 'notificationAccess')) notificationAccess = data.notificationAccess || notificationAccess;
        notificationUnread = notifications.filter((notification) => !notification.read).length;
        const incomingWorkspaceVersion = Number(data.bootstrapVersion || 0);
        if (incomingWorkspaceVersion > 0) workspaceDataVersion = incomingWorkspaceVersion;
        if (hasOwnBootstrapField(data, 'currentUser')) currentSensitiveFields = flowtrackToArray(data.currentUser?.sensitiveFields);

        if (hasOwnBootstrapField(data, 'accessControl')) {
            canViewAccess = Boolean(data.accessControl);
            canManageAccess = Boolean(data.accessControl?.canManage);
            canCreateUsers = Boolean(data.accessControl?.canCreateUsers);
            canDeleteUsers = Boolean(data.accessControl?.canDeleteUsers);
            isFlowAdmin = Boolean(data.accessControl?.isAdmin || currentUser?.isAdmin);
            isFlowSuperAdmin = Boolean(data.accessControl?.isSuperAdmin || currentUser?.isSuperAdmin);
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
        } else if (hasOwnBootstrapField(data, 'currentUser')) {
            isFlowAdmin = Boolean(currentUser?.isAdmin);
            isFlowSuperAdmin = Boolean(currentUser?.isSuperAdmin);
        }

        if (hasOwnBootstrapField(data, 'masterDataCatalog') || hasOwnBootstrapField(data, 'masterData')) {
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
        }

        if (hasOwnBootstrapField(data, 'taskPacks') || hasOwnBootstrapField(data, 'masterData')) {
            rebuildTaskPackDerivedState();
        }
        if (hasOwnBootstrapField(data, 'workflows')) {
            selectedWorkflowId = workflowTemplates[0]?.id ?? selectedWorkflowId;
            if (!boardWorkflowId || !workflowTemplates.some((workflow) => Number(workflow.id) === Number(boardWorkflowId))) {
                boardWorkflowId = workflowTemplates.find((workflow) => workflow.active !== false)?.id
                    ?? workflowTemplates[0]?.id
                    ?? null;
            }
        }

        applyCurrentUserIdentity();
        initializeRealtimeNotifications();
    }

    async function loadBootstrapSegment(url, perPage = 50, generation = bootstrapGeneration, allPages = false) {
        if (!url) return { rows: [], relatedJobs: [], relatedClients: [], pagination: null };
        if (generation !== bootstrapGeneration) return null;

        const rows = [];
        const relatedJobs = [];
        const relatedClients = [];
        let pagination = null;
        let pageNumber = 1;
        const maxPages = allPages ? 250 : 1;

        const requestPageSize = allPages ? Math.max(100, Number(perPage) || 50) : perPage;
        while (pageNumber <= maxPages) {
            const separator = url.includes('?') ? '&' : '?';
            const pageUrl = `${url}${separator}per_page=${encodeURIComponent(requestPageSize)}&page=${encodeURIComponent(pageNumber)}`;
            const page = await api(pageUrl, { cache: 'no-store' });
            if (generation !== bootstrapGeneration) return null;
            if (!page || typeof page !== 'object') {
                throw new Error('FlowTrack returned an invalid data page. Please reload once more.');
            }

            flowtrackToArray(page.data).forEach((record) => flowtrackUpsertRecord(rows, record, false));
            flowtrackToArray(page.relatedJobs).forEach((record) => flowtrackUpsertRecord(relatedJobs, record, false));
            flowtrackToArray(page.relatedClients).forEach((record) => flowtrackUpsertRecord(relatedClients, record, false));
            pagination = page.pagination || null;

            if (!allPages || !pagination?.hasMorePages) break;
            pageNumber += 1;
        }

        return { rows, relatedJobs, relatedClients, pagination };
    }

    async function loadBootstrap(options = {}) {
        const silent = Boolean(options?.silent);
        const generation = ++bootstrapGeneration;
        const coreUrl = `/flowtrack/template-api/bootstrap?_flowtrack_generation=${generation}&_=${Date.now()}`;
        if (!silent) flowtrackSetLoadingProgress(8, 'Preparing FlowTrack workspace…');
        const core = await api(coreUrl, { cache: 'no-store' });
        if (generation !== bootstrapGeneration) return null;
        if (!core || typeof core !== 'object') {
            throw new Error('FlowTrack bootstrap data is unavailable. Please reload the page.');
        }

        if (!silent) flowtrackSetLoadingProgress(34, 'Applying account and permissions…');
        applyBootstrap(core);
        bootstrapAppliedGeneration = generation;
        if (!silent) flowtrackFinishLoadingProgress('Workspace ready');
        return core;
    }

    function flowtrackPageDataSpec(page) {
        const specs = {
            dashboard: { jobs: 12, tasks: 12 },
            jobs: { jobs: 40 },
            clients: { clients: 40 },
            board: { jobs: 50, tasks: 50 },
            mywork: { tasks: 50 },
            documents: { documents: 40 },
            reports: {},
            notifications: {},
            workflowsetup: {},
            taskpacksetup: {},
            masterdata: {},
            setup: {},
            profile: {},
        };
        return specs[page] || {};
    }

    function flowtrackSegmentTarget(segment) {
        if (segment === 'clients') return clients;
        if (segment === 'jobs') return jobs;
        if (segment === 'tasks') return tasks;
        if (segment === 'documents') return docs;
        return [];
    }

    async function flowtrackLoadInitialSegment(segment, perPage, generation = bootstrapGeneration) {
        const url = bootstrapSegmentUrls?.[segment];
        if (!url) return null;
        const requestedSize = Math.max(1, Number(perPage) || Number(bootstrapSegmentUrls?.perPage) || 25);
        const page = await loadBootstrapSegment(url, requestedSize, generation, false);
        if (!page || generation !== bootstrapGeneration) return null;

        const target = flowtrackSegmentTarget(segment);
        replaceArray(target, page.rows);
        if (segment === 'tasks') tasks.forEach(flowtrackHydrateTaskAssignee);

        flowtrackToArray(page.relatedJobs).forEach((job) => flowtrackUpsertRecord(jobs, job, false));
        flowtrackToArray(page.relatedClients).forEach((client) => flowtrackUpsertRecord(clientLabels, client, false));
        segmentPagination[segment] = page.pagination;
        flowtrackSegmentProfiles[segment] = requestedSize;
        return page;
    }

    function flowtrackPageLoadingMarkup(page) {
        const label = ({
            dashboard: 'dashboard', mywork: 'assigned work', jobs: 'jobs', clients: 'clients', board: 'workflow board',
            documents: 'documents', reports: 'reports', notifications: 'notifications', workflowsetup: 'workflow setup',
            taskpacksetup: 'task pack setup', masterdata: 'master data', setup: 'administration', profile: 'profile',
        })[page] || 'page';
        return `<div class="flowtrack-lazy-page-state" role="status" aria-live="polite"><div class="flowtrack-lazy-spinner"></div><b>Loading ${html(label)}…</b><span>Only the records required for this screen are being fetched.</span></div>`;
    }

    function flowtrackShowPageLoading(page) {
        const section = document.getElementById(`page-${page}`);
        if (section) section.innerHTML = flowtrackPageLoadingMarkup(page);
    }

    async function flowtrackEnsurePageData(page, options = {}) {
        const pageName = flowtrackNormalizePageName(page);
        if (!pageName) return null;
        const force = Boolean(options?.force);
        if (!force && flowtrackLoadedPages.has(pageName)) return true;
        if (!force && flowtrackPageLoads.has(pageName)) return flowtrackPageLoads.get(pageName);

        const promise = (async () => {
            const generation = bootstrapGeneration;
            const contextUrl = `${pageContextBase}/${encodeURIComponent(pageName)}?_=${Date.now()}`;
            const context = await api(contextUrl, { cache: 'no-store' });
            if (generation !== bootstrapGeneration) return null;
            applyBootstrap(context || {});

            const spec = flowtrackPageDataSpec(pageName);
            const entries = Object.entries(spec);
            for (const [segment, perPage] of entries) {
                if (!force && flowtrackSegmentProfiles[segment] >= Number(perPage) && flowtrackSegmentTarget(segment).length) continue;
                await flowtrackLoadInitialSegment(segment, perPage, generation);
            }

            flowtrackLoadedPages.add(pageName);
            return true;
        })().finally(() => flowtrackPageLoads.delete(pageName));

        flowtrackPageLoads.set(pageName, promise);
        return promise;
    }

    function flowtrackInvalidatePageData(page = null) {
        if (page) flowtrackLoadedPages.delete(flowtrackNormalizePageName(page));
        else flowtrackLoadedPages.clear();
    }

    window.loadBootstrap = loadBootstrap;
    window.flowtrackEnsurePageData = flowtrackEnsurePageData;
    window.flowtrackInvalidatePageData = flowtrackInvalidatePageData;

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

    function flowtrackNormalizePageName(page) {
        if (typeof page !== 'string') return '';
        return page.trim();
    }

    function canOpenPage(page) {
        const pageName = flowtrackNormalizePageName(page);
        if (!pageName) return false;
        if (['notifications', 'profile'].includes(pageName)) return true;
        if (pageName === 'board') return canFlow('jobs', 'view') || canFlow('tasks', 'view');
        if (pageName === 'setup') return canFlow('users', 'view');
        const requirement = pagePermissions[pageName];
        // Unknown values are not application pages. Reject them instead of
        // treating them as unrestricted pages (e.g. "[object Object]").
        if (!requirement) return false;
        return canFlow(requirement[0], requirement[1]);
    }

    const landingPageOrder = [
        'dashboard', 'mywork', 'jobs', 'clients', 'board', 'documents',
        'reports', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup',
    ];

    function firstAllowedPage(preferredPage = null) {
        const preferred = flowtrackNormalizePageName(preferredPage);
        if (preferred && canOpenPage(preferred)) return preferred;
        return landingPageOrder.find((page) => canOpenPage(page)) || 'notifications';
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
        const pageName = flowtrackNormalizePageName(page);
        if (!pageName || !canOpenPage(pageName) || !document.getElementById(`page-${pageName}`)) {
            showToast('You do not have permission to open this section.');
            return false;
        }
        originalNavigate(pageName);
        queueMicrotask(applyActionPermissions);

        if (!flowtrackLoadedPages.has(pageName)) {
            flowtrackShowPageLoading(pageName);
            void flowtrackEnsurePageData(pageName)
                .then((loaded) => {
                    if (!loaded || currentPage !== pageName) return;
                    renderPage(pageName);
                    applyActionPermissions();
                    window.FlowtrackSearchableDropdown?.enhance?.(document.getElementById(`page-${pageName}`));
                })
                .catch((error) => {
                    if (currentPage === pageName) {
                        const section = document.getElementById(`page-${pageName}`);
                        if (section) section.innerHTML = `<div class="card empty-state">${html(error?.message || 'This page could not be loaded.')}</div>`;
                    }
                    showToast(error?.message || 'This page could not be loaded.');
                });
        }
        return true;
    };

    function showApplication(requestedPage = null) {
        $('#loginScreen')?.classList.add('hidden');
        $('#authLoadingScreen')?.classList.add('hidden');
        $('#appScreen')?.classList.remove('hidden');
        updateBadges();
        applyActionPermissions();

        // Promise callbacks receive the resolved bootstrap payload as their first
        // argument. Only an explicit string is a valid requested page.
        const preferredPage = flowtrackNormalizePageName(requestedPage) || currentUser?.defaultLandingPage || null;
        navigate(firstAllowedPage(preferredPage));
        scheduleSessionGuard();
        startNotificationPolling();
    }

    function setLoginInlineStatus(message = '') {
        const status = $('#loginInlineStatus');
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-visible', Boolean(message));
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

    $('#forgotPasswordLink')?.addEventListener('click', () => {
        setLoginInlineStatus('For a password reset, contact your FlowTrack workspace administrator.');
    });
    $('#contactAdminLink')?.addEventListener('click', () => {
        setLoginInlineStatus('Contact your workspace administrator to request access or reset your account credentials.');
    });
    $$('[data-login-info]').forEach((button) => button.addEventListener('click', () => {
        const topic = button.dataset.loginInfo || 'Help';
        const messages = {
            Help: 'For sign-in help, contact your FlowTrack workspace administrator.',
            Privacy: 'Your sign-in is protected by your organization’s FlowTrack workspace access controls.',
            Terms: 'Workspace access is governed by your organization’s FlowTrack usage policy.',
        };
        setLoginInlineStatus(messages[topic] || messages.Help);
    }));

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
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
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
            const matchingTask = itemId ? flowtrackToArray(tasks).find((task) =>
                String(task.job || '') === String(job?.id || '')
                && Number(task.phaseId || 0) === phaseId
                && Number(task.taskPackItemId || 0) === Number(itemId)
            ) : null;
            const matchingTaskId = Number(matchingTask?.dbId || 0);
            return {
                key: requirement.requirement_key || `${itemId ? 'task' : 'phase'}:${itemId || phaseId}:${categoryId}`,
                phaseId,
                phaseName: requirement.workflow_phase_name || requirement.workflowPhaseName || phaseNameFor(job),
                taskPackItemId: itemId ? Number(itemId) : null,
                categoryId,
                categoryName: requirement.category_name || requirement.categoryName || 'Required document',
                sourceLabel: requirement.source_label || (requirement.sources || [])[0] || 'Workflow requirement',
                requiredForReadiness: requirement.required_for_readiness !== false,
                present: docs.some((documentRecord) => {
                    if (String(documentRecord.job || '') !== String(job?.id || '')) return false;
                    if (Number(documentRecord.phaseId || 0) !== phaseId) return false;
                    if (Number(documentRecord.categoryId || 0) !== categoryId) return false;
                    if (itemId) {
                        return Number(documentRecord.taskPackItemId || 0) === Number(itemId)
                            || (matchingTaskId && Number(documentRecord.taskId || 0) === matchingTaskId);
                    }
                    return !Number(documentRecord.taskPackItemId || 0) && !Number(documentRecord.taskId || 0);
                }),
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

    function flowtrackApplyLinkedDocumentResult(result) {
        if (result?.document) {
            const documentIndex = docs.findIndex((item) => Number(item.dbId) === Number(result.document.dbId));
            if (documentIndex >= 0) docs.splice(documentIndex, 1, result.document);
            else docs.unshift(result.document);
        }
        if (result?.job) {
            const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
            if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
            else jobs.unshift(result.job);
            if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
        }
        updateBadges();
    }

    function flowtrackConfirmDeleteDocument(documentRecord, onDeleted = null) {
        if (!documentRecord?.dbId) return showToast('This document cannot be deleted because its record is unavailable.');
        if (!canFlow('documents', 'delete')) return showToast('Your role does not have permission to delete documents.');
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Delete document?</h2><div class="small muted" style="margin-top:5px">This permanently removes the document and every stored version.</div></div><button type="button" class="close-btn" data-document-delete-close>×</button></div>
            <div class="modal-body"><div class="source-note"><b>${html(documentRecord.name || documentRecord.id || 'Document')}</b><div class="small muted" style="margin-top:4px">${html(documentRecord.type || documentRecord.category || 'Document')} · ${html(documentRecord.job || 'FlowTrack')}</div></div><div class="task-attachment-info" style="margin-top:14px"><span>!</span><b>If this file satisfies a required Task or Workflow document, that requirement will become missing again after deletion.</b></div></div>
            <div class="modal-foot"><button type="button" class="ghost" data-document-delete-close>Cancel</button><button type="button" class="danger-btn" data-document-delete-confirm>Delete document</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-document-delete-close]').forEach((button) => button.addEventListener('click', flowtrackCloseModalOnly));
        $('[data-document-delete-confirm]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            button.textContent = 'Deleting…';
            try {
                const result = await api(`/flowtrack/template-api/documents/${documentRecord.dbId}`, { method: 'DELETE' });
                const index = docs.findIndex((item) => Number(item.dbId) === Number(documentRecord.dbId));
                if (index >= 0) docs.splice(index, 1);
                flowtrackApplyLinkedDocumentResult(result || {});
                flowtrackCloseModalOnly();
                if (typeof onDeleted === 'function') onDeleted(result || {});
                showToast(result?.message || 'Document deleted successfully.');
            } catch (error) {
                button.disabled = false;
                button.textContent = 'Delete document';
                showToast(error.message || 'Document could not be deleted.');
            }
        });
    }

    function flowtrackOpenExistingJobAttachmentChooser(job, onLinked = null) {
        if (!job?.dbId) return showToast('The Job could not be loaded.');
        if (!(canFlow('documents', 'create') && canFlow('documents', 'link'))) return showToast('Your role does not have Document Upload & Link access.');
        const available = flowtrackToArray(docs)
            .filter((item) => Number(item?.dbId) && String(item.job || '') !== String(job.id || ''))
            .sort((left, right) => String(right.date || '').localeCompare(String(left.date || '')));
        if (!available.length) return showToast('There are no other stored documents available to attach.');
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Choose from Documents</h2><div class="small muted" style="margin-top:5px">Attach a stored document to ${html(job.id)} without changing its original record.</div></div><button type="button" class="close-btn" data-job-attachment-chooser-close>×</button></div>
            <div class="modal-body"><label class="field full"><span>Search documents</span><input type="search" placeholder="Search file, type, Job or uploader" data-job-attachment-search></label><div class="job-v6-existing-list">${available.map((item) => `<div class="job-v6-existing-row" data-job-attachment-row data-search="${html(normalizedStatusValue(`${item.name || ''} ${item.type || ''} ${item.job || ''} ${item.uploadedBy || ''}`))}"><span class="job-v6-file-icon ${html(flowtrackDocumentFileBadge(item).toLowerCase())}">${html(flowtrackDocumentFileBadge(item))}</span><div><b>${html(item.name || 'Document')}</b><small>${html(item.job || 'Stored document')} · ${html(item.type || 'Document')} · ${html(item.uploadedBy || 'System')}</small></div><button type="button" class="primary" data-job-attachment-link="${html(item.dbId)}">Attach</button></div>`).join('')}</div></div>
            <div class="modal-foot"><button type="button" class="ghost" data-job-attachment-chooser-close>Cancel</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-job-attachment-chooser-close]').forEach((button) => button.addEventListener('click', flowtrackCloseModalOnly));
        $('[data-job-attachment-search]')?.addEventListener('input', (event) => {
            const term = normalizedStatusValue(event.target.value || '');
            $$('[data-job-attachment-row]').forEach((row) => row.classList.toggle('is-hidden', Boolean(term) && !String(row.dataset.search || '').includes(term)));
        });
        $$('[data-job-attachment-link]').forEach((button) => button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = 'Attaching…';
            try {
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/attachments/link-existing`, { method: 'POST', body: { source_document_id: Number(button.dataset.jobAttachmentLink) } });
                flowtrackApplyLinkedDocumentResult(result);
                flowtrackCloseModalOnly();
                if (typeof onLinked === 'function') onLinked(result);
                showToast(result.message || 'Stored document attached successfully.');
            } catch (error) {
                button.disabled = false;
                button.textContent = 'Attach';
                showToast(error.message || 'Stored document could not be attached.');
            }
        }));
    }

    function flowtrackOpenStoredDocumentChooser(job, requirement, task = null, onLinked = null) {
        if (!job || !requirement?.phaseId || !requirement?.categoryId) {
            showToast('Choose an exact document requirement first.');
            return;
        }
        if (!canFlow('documents', 'create') || !canFlow('documents', 'link')) {
            showToast('Your role does not have Document Upload & Link access.');
            return;
        }

        const available = flowtrackToArray(docs)
            .filter((item) => Number(item?.dbId) && (item.previewUrl || item.downloadUrl || item.versionCount || item.name))
            .sort((left, right) => String(right.date || right.updatedAt || '').localeCompare(String(left.date || left.updatedAt || '')));
        if (!available.length) {
            showToast('There are no stored documents available to link.');
            return;
        }

        const purposeLabel = `${requirement.phaseName || phaseNameFor(job)} · ${requirement.sourceLabel || task?.title || 'Document'} · ${requirement.categoryName || 'Document'}`;
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Choose from Documents</h2><div class="small muted" style="margin-top:5px">Link an existing stored file to ${html(job.id)} · ${html(purposeLabel)}</div></div><button type="button" class="close-btn" data-stored-doc-close>×</button></div>
            <div class="modal-body"><label class="field full"><span>Search documents</span><input type="search" placeholder="Search file, type, Job or uploader" data-stored-doc-search></label><div class="job-v6-existing-list" data-stored-doc-list>${available.map((item) => `<div class="job-v6-existing-row" data-stored-doc-row data-search="${html(normalizedStatusValue(`${item.name || ''} ${item.type || item.category || ''} ${item.job || ''} ${item.uploadedBy || ''}`))}"><span class="job-v6-file-icon ${html(flowtrackDocumentFileBadge(item).toLowerCase())}">${html(flowtrackDocumentFileBadge(item))}</span><div><b>${html(item.name || 'Document')}</b><small>${html(item.job || 'Stored document')} · ${html(item.type || item.category || 'Document')} · ${html(item.uploadedBy || 'System')}</small></div><button type="button" class="primary" data-stored-doc-link="${html(item.dbId)}">Link</button></div>`).join('')}</div></div>
            <div class="modal-foot"><button type="button" class="ghost" data-stored-doc-close>Cancel</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        $$('[data-stored-doc-close]').forEach((button) => button.addEventListener('click', flowtrackCloseModalOnly));
        $('[data-stored-doc-search]')?.addEventListener('input', (event) => {
            const term = normalizedStatusValue(event.target.value || '');
            $$('[data-stored-doc-row]').forEach((row) => row.classList.toggle('is-hidden', Boolean(term) && !String(row.dataset.search || '').includes(term)));
        });
        $$('[data-stored-doc-link]').forEach((button) => button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = 'Linking…';
            try {
                const body = {
                    source_document_id: Number(button.dataset.storedDocLink),
                    workflow_phase_id: Number(requirement.phaseId),
                    task_pack_item_id: requirement.taskPackItemId ? Number(requirement.taskPackItemId) : null,
                    document_category_id: Number(requirement.categoryId),
                };
                if (task?.dbId || requirement.taskId) body.task_id = Number(task?.dbId || requirement.taskId);
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/documents/link-existing`, { method: 'POST', body });
                flowtrackApplyLinkedDocumentResult(result);
                flowtrackCloseModalOnly();
                if (typeof onLinked === 'function') onLinked(result);
                showToast(result.message || 'Stored document linked successfully.');
            } catch (error) {
                button.disabled = false;
                button.textContent = 'Link';
                showToast(error.message || 'Stored document could not be linked.');
            }
        }));
        setTimeout(() => $('[data-stored-doc-search]')?.focus(), 0);
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
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
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
        $('[data-job-choose-documents]')?.addEventListener('click', () => {
            const selectedRequirement = requirements.find((item) => item.key === $('#jobDocumentRequirement')?.value) || defaultRequirement;
            if (!selectedRequirement) return showToast('Select an exact document requirement first.');
            flowtrackOpenStoredDocumentChooser(job, selectedRequirement, null, (result) => {
                const updatedJob = result?.job ? (jobs.find((item) => Number(item.dbId) === Number(result.job.dbId)) || result.job) : job;
                if (updatedJob && String(currentPage || '').startsWith('record-job-')) {
                    flowtrackEnsureJobPanels(updatedJob, 8);
                    if (typeof flowtrackJobV4Apply === 'function') flowtrackJobV4Apply(updatedJob, 8);
                } else if (currentPage === 'documents') {
                    renderDocuments();
                }
            });
        });
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
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
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
            replacement.className = 'task-document-attachment-card document-archive-attachment-card';
            replacement.tabIndex = 0;
            replacement.setAttribute('role', 'button');
            replacement.setAttribute('aria-label', 'Upload a required Job document');
            replacement.innerHTML = `<div class="task-attachment-template-head"><div class="task-attachment-icon">▧</div><div><h3>Document attachment</h3><p>Upload a required Job document</p></div><span class="badge b-amber">Required</span></div><div class="task-attachment-dropzone document-archive-dropzone"><div class="task-upload-cloud">☁↑</div><b>Upload a required Job document</b><span>or choose the related Job first</span><div class="task-upload-actions"><button type="button" class="primary">Select Job & Upload</button></div><small>Only document categories configured in Workflow Setup or Task Pack Setup can be uploaded.</small></div><div class="task-attachment-info"><span>ⓘ</span><b>The document will stay connected to its client, Job, phase and version.</b></div>`;
            uploadZone.replaceWith(replacement);
            replacement.addEventListener('click', openRequiredDocumentJobChooser);
            replacement.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openRequiredDocumentJobChooser();
                }
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
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
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
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
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
            button.addEventListener('click', () => flowtrackConfirmDeleteDocument(documentRecord, () => {
                closeAll();
                if (currentPage === 'documents') renderDocuments();
            }));
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
            priority_id: Object.prototype.hasOwnProperty.call(extras, 'priority_id') ? extras.priority_id : (task.priorityId ?? null),
            start_date: Object.prototype.hasOwnProperty.call(extras, 'start_date') ? extras.start_date : (task.startDate ?? (task.createdAt ? String(task.createdAt).split('T')[0] : null)),
            due_date: Object.prototype.hasOwnProperty.call(extras, 'due_date') ? extras.due_date : (task.due ?? null),
            progress: taskProgressForStatus(statusRecord),
            attention_required: extras.attention_required ?? task.attention ?? false,
            blocker_reason: extras.blocker_reason ?? task.blockerReason ?? null,
            completed_checklist_ids: extras.completed_checklist_ids ?? undefined,
            checklist_add: extras.checklist_add ?? undefined,
            checklist_delete_id: extras.checklist_delete_id ?? undefined,
            comment: extras.comment ?? '',
            mentioned_user_ids: extras.mentioned_user_ids ?? [],
        };
        const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'PUT', body: payload });
        updateTaskAndJob(result);
        return result;
    }

    function boardCanChangeTaskDueDate(task) {
        if (!task) return false;
        const completed = taskIsCompleted(task) || boardTaskStatusBucket(task) === 'done';
        if (completed && !(isFlowAdmin || isFlowSuperAdmin)) return false;
        return Boolean(task.canChangeDueDate || task.canUpdate || isFlowAdmin || isFlowSuperAdmin);
    }

    function boardTaskDueHtml(task, overdueDays = 0) {
        const dueKey = boardDateOnly(task?.due);
        if (dueKey) {
            return `<div class="ops-due ${overdueDays ? 'overdue' : ''}"><span class="ops-due-calendar" aria-hidden="true">📅</span> ${boardShortDate(dueKey)}</div>`;
        }

        if (!boardCanChangeTaskDueDate(task)) {
            return '<div class="ops-due ops-due-missing ops-due-readonly-missing" title="No due date"><span class="ops-due-calendar" aria-hidden="true">📅</span> No due date</div>';
        }

        const taskId = html(task.id || '');
        const taskDbId = html(task.dbId || task.id || '');
        const title = html(task.title || task.id || 'Task');

        return `<div class="ops-due ops-due-missing"><label class="ops-set-due-label" data-board-set-due="${taskId}" title="Set due date for ${title}"><span class="ops-due-calendar" aria-hidden="true">📅</span> <span>Set due date</span><input type="date" class="ops-due-picker" data-board-due-picker="${taskId}" data-board-due-db="${taskDbId}" aria-label="Set due date for ${title}"></label></div>`;
    }

    async function persistTaskDueDate(task, dueDate) {
        if (!boardCanChangeTaskDueDate(task)) {
            throw new Error('Only the assigned user, an Administrator, or the Super Admin can set this due date.');
        }
        if (!task?.dbId) throw new Error('The selected task is missing its database ID. Refresh the board and try again.');
        const result = await api(`/flowtrack/template-api/tasks/${task.dbId}/due-date`, { method: 'PATCH', body: { due_date: dueDate || null } });
        updateTaskAndJob(result);
        return result;
    }

    function bindBoardDueDatePickers(scope, refreshCallback) {
        const root = typeof scope === 'string' ? $(scope) : (scope || document);
        if (!root?.querySelectorAll) return;
        root.querySelectorAll('[data-board-set-due], [data-board-due-picker]').forEach((element) => {
            element.addEventListener('click', (event) => event.stopPropagation());
            element.addEventListener('mousedown', (event) => event.stopPropagation());
            element.addEventListener('pointerdown', (event) => event.stopPropagation());
        });
        root.querySelectorAll('[data-board-due-picker]').forEach((input) => {
            input.addEventListener('change', async (event) => {
                event.preventDefault();
                event.stopPropagation();
                const selectedDate = input.value;
                if (!selectedDate) return;
                const task = tasks.find((item) => String(item.id) === String(input.dataset.boardDuePicker) || Number(item.dbId) === Number(input.dataset.boardDueDb));
                if (!task) {
                    showToast('The selected task is no longer available.');
                    return;
                }
                input.disabled = true;
                const label = input.closest('.ops-set-due-label');
                label?.classList.add('saving');
                try {
                    await persistTaskDueDate(task, selectedDate);
                    updateBadges();
                    if (typeof refreshCallback === 'function') refreshCallback();
                    showToast('Due date set successfully.');
                } catch (error) {
                    input.disabled = false;
                    label?.classList.remove('saving');
                    input.value = '';
                    showToast(error.message || 'Could not set the due date.');
                }
            });
        });
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
        const taskAttachedFilesHtml = taskAttachmentVersions.length ? `<div class="task-attachment-existing-files"><h4>Attached files</h4>${taskAttachmentVersions.map(({ documentRecord, version, versionIndex }) => { const fileName = version.originalName || documentRecord.name || 'Document'; const owner = version.uploadedBy || documentRecord.uploadedBy || taskAssigneeName || 'System'; const typeLabel = documentRecord.type || documentRequirement.categoryName || 'Attachment'; const canDeleteDocument = Boolean(documentRecord.canDelete || canFlow('documents', 'delete')); return `<div class="task-attachment-existing-file"><span class="job-v3-pdf">${String(fileName).toLowerCase().endsWith('.pdf') ? 'PDF' : 'FILE'}</span><div><b>${html(fileName)}</b><small>${html(typeLabel)} · v${version.version || documentRecord.version || versionIndex + 1} · ${html(owner)}</small></div><div class="task-document-actions">${version.previewUrl ? `<a class="mini-btn" href="${html(version.previewUrl)}" target="_blank" rel="noopener">Open</a>` : ''}${versionIndex === 0 && canDeleteDocument ? `<button type="button" class="mini-btn task-attachment-delete" data-task-legacy-delete-document="${html(documentRecord.dbId)}">Delete</button>` : ''}</div></div>`; }).join('')}</div>` : '';
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
        $('[data-task-choose-documents]')?.addEventListener('click', () => {
            const selectedCategoryId = genericTaskAttachment
                ? Number($('#taskDocumentCategory')?.value || 0)
                : Number(documentRequirement?.categoryId || 0);
            if (!selectedCategoryId) return showToast('Choose the document type first.');
            const linkedRequirement = { ...documentRequirement, categoryId: selectedCategoryId };
            const linkedJob = job || jobs.find((item) => Number(item.dbId) === Number(task.jobDbId) || String(item.id) === String(task.job));
            if (!linkedJob) return showToast('The linked Job could not be loaded.');
            flowtrackOpenStoredDocumentChooser(linkedJob, linkedRequirement, task, () => {
                setTimeout(() => void openTask(task.id || task.dbId), 30);
            });
        });
        $$('[data-task-legacy-delete-document]').forEach((button) => button.addEventListener('click', () => {
            const documentId = Number(button.dataset.taskLegacyDeleteDocument || 0);
            const documentRecord = taskDocuments.find((item) => Number(item.dbId) === documentId);
            if (!documentRecord) return showToast('The selected document is no longer available.');
            flowtrackConfirmDeleteDocument(documentRecord, (result) => {
                if (result?.job && Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                setTimeout(() => void openTask(task.id || task.dbId), 30);
            });
        }));
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
    let myWorkFilters = { job: '', client: '', assignee: '', status: '', priority: '', due: '' };
    const collapsedBoardJobs = new Set();
    const expandedBoardJobCards = new Set();
    let jobListFilters = { owner: '', delivery: '', invoice: '', more: '', saved: 'all' };
    let jobListPage = 1;
    let jobListPageSize = 10;
    try {
        const storedJobPageSize = Number(localStorage.getItem('flowtrackJobListPageSize') || 10);
        if ([10, 20, 30, 40].includes(storedJobPageSize)) jobListPageSize = storedJobPageSize;
    } catch (_) {}
    const selectedBulkJobIds = new Set();

    
    /* CSS externalized: operationsBoardStyles. */

    
    /* CSS externalized: myWorkBoardStyles. */

    
    /* CSS externalized: myWorkBottomActionStyles. */
    
    /* CSS externalized: metricClickableStyles. */

    
    /* CSS externalized: jobBulkActionStyles. */

    
    /* CSS externalized: jobBoardRedesignStyles. */


    
    /* CSS externalized: compactOperationsBoardStyles. */

    
    /* CSS externalized: denseWorkflowBoardStyles. */


    
    /* CSS externalized: boardScopeCorrectionStyles. */

    
    /* CSS externalized: myWorkBoardFinalStyles. */

    
    /* CSS externalized: boardFilterCompactFinalStyles. */


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
        const selectedIdText = String(selectedId || '').trim();
        const directIdMatch = selectedIdText && [job.coordinatorId, job.ownerId, job.createdById]
            .some((id) => String(id || '').trim() === selectedIdText);
        const directNameMatch = normalizedName && [job.coordinator, job.owner, job.createdBy]
            .some((name) => String(name || '').trim().toLowerCase() === normalizedName);
        if (directIdMatch || directNameMatch) return true;

        const memberIds = flowtrackToArray(job.memberIds || job.member_ids).map((id) => String(id || '').trim());
        const memberRecords = flowtrackToArray(job.members || job.team);
        const memberIdMatch = selectedIdText && (memberIds.includes(selectedIdText)
            || memberRecords.some((member) => String(member?.id || member?.userId || member?.user_id || '').trim() === selectedIdText));
        const memberNameMatch = normalizedName && memberRecords.some((member) => String(member?.name || member?.userName || member?.user_name || member || '').trim().toLowerCase() === normalizedName);
        if (memberIdMatch || memberNameMatch) return true;

        return tasks.some((task) => (String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId))
            && boardTaskMatchesAssignee(task, selectedId, selectedName));
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
        </div>
        <div class="ops-quick-row"><div class="ops-quick-left"><span class="ops-quick-label">Quick filters</span>${[
            ['mine', quickMineLabel, quickCounts.mine, ''],
            ['overdue', 'Overdue', quickCounts.overdue, 'danger'],
            ['week', 'Due this week', quickCounts.week, ''],
            ['blocked', 'Blocked', quickCounts.blocked, 'danger'],
            ['waiting', 'Waiting external', quickCounts.waiting, ''],
            ['unassigned', 'Unassigned', quickCounts.unassigned, 'warn'],
        ].map(([key, label, count, tone]) => `<button type="button" class="ops-chip ${tone} ${boardQuickFilter === key ? 'active' : ''}" data-board-quick="${key}">${label} <span class="ops-count">${count}</span></button>`).join('')}</div><div class="ops-quick-right"><button type="button" class="ops-clear ops-expand-toggle" id="opsBoardExpandAll">${boardAllVisibleJobsExpanded() ? 'Minimize all jobs' : 'Expand all jobs'}</button></div></div>
        <div class="ops-status-summary"><span>Showing <b>${filteredCount}</b> ${boardMode === 'tasks' ? 'of ' + flowtrackVisibleTaskRows(tasks).length + ' tasks' : 'Jobs'} across <b>${filteredJobCount}</b> ${filteredJobCount === 1 ? 'job' : 'jobs'}</span></div>`;
    }

    function boardTaskInvoiceBadgeHtml(task, job = null) {
        const requirement = task?.documentRequirement || task?.document_requirement || null;
        const categoryText = [
            requirement?.categoryName,
            requirement?.category_name,
            requirement?.name,
            requirement?.code,
            task?.documentCategoryName,
            task?.document_category_name,
            task?.documentCategoryCode,
            task?.document_category_code,
        ].join(' ');
        const phaseText = [
            task?.phase,
            task?.phaseName,
            task?.phaseShortName,
            job ? phaseNameFor(job) : '',
            job ? phaseShortFor(job) : '',
        ].join(' ');
        const taskPackText = [task?.taskPackName, task?.taskPackItemTitle].join(' ');
        const isInvoiceDocument = /(^|\b)(INV|INVOICE|PAYMENT)(\b|$)/.test(normalizedStatusValue(categoryText));
        const isInvoicePhase = /(^|\b)(INV|INVOICE|PAYMENT)(\b|$)/.test(normalizedStatusValue(phaseText));
        const isInvoicePack = /(^|\b)(INV|INVOICE|PAYMENT)(\b|$)/.test(normalizedStatusValue(taskPackText));
        if (!isInvoiceDocument && !isInvoicePhase && !isInvoicePack) return '';
        return '<span class="ops-task-doc-badge ops-task-invoice-badge" title="Invoice phase/task">Invoice</span>';
    }

    function boardTaskPhaseHtml(task, job = null) {
        const phaseLabel = task?.phase || task?.phaseName || phaseNameFor(job || {}, job?.phase) || 'General';
        return `<div class="ops-card-phase"><span>${html(phaseLabel)}</span>${boardTaskInvoiceBadgeHtml(task, job)}</div>`;
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
            <div class="ops-card-meta"><button type="button" class="job-link" data-board-job-link="${html(task.job)}">${html(task.job)}</button> <span>· ${html(clientName(job?.client))}</span>${boardTaskPhaseHtml(task, job)}</div>
            ${waiting ? `<div class="ops-waiting-box"><span class="ops-wait-icon">🔗</span><span><div class="ops-wait-title">Waiting on: ${html(boardWaitingLabel(task))}</div><div class="ops-wait-date">Since ${boardShortDate(task.updatedAt || task.createdAt || task.due)}</div></span></div>` : ''}
            <div class="ops-card-sep"></div>
            <div class="ops-assignee-row"><div class="ops-assignee"><span class="avatar">${userInitials(task.assignee || 'Unassigned')}</span><span class="truncate">${html(task.assignee || 'Unassigned')}</span></div>${boardTaskDueHtml(task, overdueDays)}</div>
            <div class="ops-card-sep"></div>
            <div class="ops-card-footer"><div class="ops-card-stats"><span class="ops-stat">☑ ${html(boardChecklistCount(task))}</span><span class="ops-stat">📎 ${attachments}</span><span class="ops-stat">◯ ${comments}</span></div><span class="ops-updated">${html(boardTaskUpdatedText(task))}</span></div>
        </article>`;
    }

    function renderOperationsTaskBoard(taskList, explicitJobList = null) {
        const columns = boardColumnDefinitions();
        const counts = boardColumnCounts(taskList);
        const jobsWithTasks = [];
        const seen = new Set();
        const scopedJobs = Array.isArray(explicitJobList) ? explicitJobList : null;
        if (scopedJobs) {
            scopedJobs.forEach((job) => {
                const key = String(job?.id || `db:${Number(job?.dbId || 0)}`);
                if (!key || seen.has(key)) return;
                seen.add(key);
                jobsWithTasks.push(job);
            });
        } else taskList.forEach((task) => {
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
                return `<div class="ops-task-cell kanban-list ${columnTasks.length ? '' : 'is-empty'}" data-drop-column="${column.key}" data-column-label="${html(column.label)}"><div class="ops-mobile-column-head"><span>${html(column.label)}</span><span class="count">${columnTasks.length}</span></div>${columnTasks.map(boardTaskCardHtml).join('') || '<div class="ops-job-empty">No tasks</div>'}</div>`;
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

    function jobListPhaseFilterValues(job) {
        const workflow = getWorkflow(Number(job?.workflow));
        const workflowPhase = (workflow?.phases || []).find((phase) => Number(phase.id) === Number(job?.phaseId));
        return [
            job?.phaseId,
            job?.phase,
            job?.status,
            workflowPhase?.name,
            workflowPhase?.short,
            workflowPhase?.shortName,
            phaseNameFor(job || {}),
            phaseShortFor(job || {}),
        ].map((value) => String(value ?? '').trim()).filter(Boolean);
    }

    function jobListPhaseMatchesFilter(job, filterValue) {
        const wantedRaw = String(filterValue ?? '').trim();
        if (!wantedRaw) return true;
        const wanted = normalizedStatusValue(wantedRaw);
        return jobListPhaseFilterValues(job).some((candidate) => String(candidate) === wantedRaw || normalizedStatusValue(candidate) === wanted);
    }

    function jobListFilteredJobs() {
        let list = jobs.slice();
        if (jobListFilters.saved !== 'completed') list = list.filter((job) => !jobIsInactive(job));
        const search = String(jobFilter.search || '').trim().toLowerCase();
        if (search) {
            list = list.filter((job) => [job.id, job.order, job.title, clientName(job.client), job.product, job.coordinator, job.owner, job.next].join(' ').toLowerCase().includes(search));
        }
        if (jobFilter.phase) list = list.filter((job) => jobListPhaseMatchesFilter(job, jobFilter.phase));
        if (jobFilter.health) list = list.filter((job) => String(job.health) === String(jobFilter.health) || normalizedStatusValue(job.health) === normalizedStatusValue(jobFilter.health));
        if (jobFilter.client) list = list.filter((job) => String(job.client) === String(jobFilter.client));
        if (jobListFilters.owner) list = list.filter((job) => String(job.ownerId || job.coordinatorId || job.owner || job.coordinator) === String(jobListFilters.owner));
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
        const options = new Map();
        const addPhaseOption = (label, fallbackValue = '') => {
            const cleanLabel = String(label ?? '').trim();
            if (!cleanLabel) return;
            const key = normalizedStatusValue(cleanLabel);
            if (!key || options.has(key)) return;
            options.set(key, { value: key, label: cleanLabel, fallbackValue });
        };

        (workflowTemplates || []).forEach((workflow) => (workflow.phases || []).forEach((phase) => {
            addPhaseOption(phase.name || phase.short || phase.shortName, phase.id ?? phase.phaseId ?? '');
        }));

        jobs.forEach((job) => {
            addPhaseOption(phaseNameFor(job), job.phaseId || job.phase || '');
            addPhaseOption(phaseShortFor(job), job.phaseId || job.phase || '');
        });

        if (!options.size && typeof phases !== 'undefined') phases.forEach((label, index) => addPhaseOption(label, index));

        const selected = String(jobFilter.phase || '').trim();
        const selectedNormalized = normalizedStatusValue(selected);
        return Array.from(options.values()).map((phase) => {
            const isSelected = selectedNormalized === normalizedStatusValue(phase.value)
                || selectedNormalized === normalizedStatusValue(phase.label)
                || String(phase.fallbackValue || '') === selected;
            return `<option value="${html(phase.value)}" ${isSelected ? 'selected' : ''}>${html(phase.label)}</option>`;
        }).join('');
    }

    function flowtrackClientOptionRows() {
        const rows = new Map();
        flowtrackToArray(clientLabels).forEach((client) => {
            if (client?.id != null) rows.set(String(client.id), client);
        });
        flowtrackToArray(clients).forEach((client) => {
            if (client?.id != null) rows.set(String(client.id), client);
        });
        return Array.from(rows.values()).sort((left, right) => String(left?.name || '').localeCompare(String(right?.name || '')));
    }

    function jobListOwnerOptions() {
        return users.map((user) => `<option value="${html(user.id || user.name)}" ${String(jobListFilters.owner) === String(user.id || user.name) ? 'selected' : ''}>${html(user.name)}</option>`).join('');
    }

    function resetJobListPage() {
        jobListPage = 1;
    }

    function jobListPageSizeOptions() {
        return [10, 20, 30, 40].map((size) => `<option value="${size}" ${Number(jobListPageSize) === size ? 'selected' : ''}>${size}</option>`).join('');
    }

    function jobListPaginationState(filteredJobs) {
        const total = filteredJobs.length;
        const safePageSize = [10, 20, 30, 40].includes(Number(jobListPageSize)) ? Number(jobListPageSize) : 10;
        if (safePageSize !== Number(jobListPageSize)) jobListPageSize = safePageSize;
        const pageCount = Math.max(1, Math.ceil(total / safePageSize));
        jobListPage = Math.min(Math.max(1, Number(jobListPage) || 1), pageCount);
        const start = total ? (jobListPage - 1) * safePageSize : 0;
        const end = Math.min(start + safePageSize, total);
        return { total, page: jobListPage, pageSize: safePageSize, pageCount, start, end, items: filteredJobs.slice(start, end) };
    }

    function jobListPaginationHtml(pagination) {
        const showingStart = pagination.total ? pagination.start + 1 : 0;
        const showingEnd = pagination.end;
        const hasServerMore = Boolean(segmentPagination.jobs?.nextPageUrl);
        const totalLabel = hasServerMore ? `${pagination.total}+` : String(pagination.total);
        const pageLabel = hasServerMore && pagination.page >= pagination.pageCount
            ? `Page ${pagination.page}`
            : `Page ${pagination.page} of ${pagination.pageCount}`;
        const nextDisabled = pagination.page >= pagination.pageCount && !hasServerMore;
        return `<div class="jobs-v2-pagination"><div class="jobs-v2-page-count">Showing <b>${showingStart}</b>–<b>${showingEnd}</b> of <b>${totalLabel}</b> Jobs</div><label class="jobs-v2-page-size">Show <select id="jobPageSize">${jobListPageSizeOptions()}</select> per page</label><div class="jobs-v2-page-buttons"><button type="button" id="jobPrevPage" ${pagination.page <= 1 ? 'disabled' : ''}>Previous</button><span>${pageLabel}</span><button type="button" id="jobNextPage" ${nextDisabled ? 'disabled' : ''}>Next</button></div></div>`;
    }

    function jobListTableColumnsHtml() {
        return '<colgroup><col class="jobs-v2-col-select"><col class="jobs-v2-col-code"><col class="jobs-v2-col-client"><col class="jobs-v2-col-product"><col class="jobs-v2-col-phase"><col class="jobs-v2-col-next"><col class="jobs-v2-col-health"><col class="jobs-v2-col-owner"><col class="jobs-v2-col-delivery"><col class="jobs-v2-col-progress"><col class="jobs-v2-col-invoice"><col class="jobs-v2-col-menu"></colgroup>';
    }

    function jobListOwnerName(job) {
        return job?.owner || job?.coordinator || 'Unassigned';
    }

    function jobListCanEditOwner() {
        return canEditJobRecord() && canFlow('jobs', 'assign');
    }

    function jobListCanEditDelivery() {
        return canEditJobRecord();
    }

    function jobListOwnerCell(job) {
        const ownerName = jobListOwnerName(job);
        const edit = jobListCanEditOwner()
            ? `<button type="button" class="jobs-v2-inline-edit" data-job-list-edit-owner="${html(job.id)}" aria-label="Edit owner" title="Edit owner">✎</button><span class="jobs-v2-inline-caret">⌄</span>`
            : '';
        return `<div class="jobs-v2-owner-cell"><div class="jobs-v2-owner"><span class="avatar">${userInitials(ownerName)}</span><span class="jobs-v2-owner-name">${html(ownerName)}</span></div>${edit}</div>`;
    }

    function jobListDeliveryCell(job, overdue = false) {
        const edit = jobListCanEditDelivery()
            ? `<button type="button" class="jobs-v2-inline-edit" data-job-list-edit-delivery="${html(job.id)}" aria-label="Edit delivery date" title="Edit delivery date">✎</button>`
            : '';
        return `<div class="jobs-v2-delivery-cell ${overdue ? 'overdue' : ''}"><span class="jobs-v2-delivery-label">${html(boardShortDate(job.delivery))}</span><span class="jobs-v2-calendar" aria-hidden="true">▣</span>${edit}</div>`;
    }

    function jobListCloseInlineEditors(except = null) {
        $$('.jobs-v2-inline-popover,.jobs-v2-delivery-editor').forEach((node) => {
            if (node !== except) node.remove();
        });
        $$('.jobs-v2-owner-cell.is-editing,.jobs-v2-delivery-cell.is-editing').forEach((node) => node.classList.remove('is-editing'));
    }

    function jobListOpenOwnerEditor(button, job) {
        const cell = button.closest('td');
        const ownerCell = button.closest('.jobs-v2-owner-cell');
        if (!cell || !ownerCell) return;
        const existing = cell.querySelector('.jobs-v2-inline-popover');
        if (existing) { existing.remove(); ownerCell.classList.remove('is-editing'); return; }
        jobListCloseInlineEditors();
        ownerCell.classList.add('is-editing');
        const members = (typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : flowtrackToArray(users))
            .filter((member) => String(member.status || 'active').toLowerCase() !== 'inactive');
        const currentOwnerId = Number(job.ownerId || 0);
        const popup = document.createElement('div');
        popup.className = 'jobs-v2-inline-popover';
        popup.innerHTML = `<div class="jobs-v2-owner-search"><span>⌕</span><input type="search" placeholder="Search people" aria-label="Search people"></div><div class="jobs-v2-owner-options"><button type="button" class="jobs-v2-owner-option ${!currentOwnerId ? 'selected' : ''}" data-owner-id=""><span class="avatar jobs-v2-unassigned-avatar">♙</span><span>Unassigned</span>${!currentOwnerId ? '<b>✓</b>' : ''}</button>${members.map((member) => `<button type="button" class="jobs-v2-owner-option ${Number(member.id) === currentOwnerId ? 'selected' : ''}" data-owner-id="${html(member.id)}"><span class="avatar">${userInitials(member.name)}</span><span>${html(member.name)}</span>${Number(member.id) === currentOwnerId ? '<b>✓</b>' : ''}</button>`).join('')}</div>`;
        document.body.appendChild(popup);
        const anchorRect = ownerCell.getBoundingClientRect();
        const popupWidth = 190;
        popup.style.left = `${Math.max(8, Math.min(window.innerWidth - popupWidth - 8, anchorRect.left))}px`;
        popup.style.top = `${Math.min(window.innerHeight - 330, anchorRect.bottom + 6)}px`;
        const search = popup.querySelector('input');
        search?.addEventListener('input', () => {
            const term = String(search.value || '').trim().toLowerCase();
            popup.querySelectorAll('.jobs-v2-owner-option').forEach((option) => {
                option.hidden = term && !String(option.textContent || '').toLowerCase().includes(term);
            });
        });
        popup.querySelectorAll('[data-owner-id]').forEach((option) => option.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const ownerId = Number(option.dataset.ownerId || 0) || null;
            popup.classList.add('is-saving');
            popup.querySelectorAll('button,input').forEach((control) => { control.disabled = true; });
            try {
                await flowtrackJobV4SaveJob(job, { owner_id: ownerId }, 'Job owner updated.');
                renderJobs();
            } catch (error) {
                showToast(error.message || 'Unable to update Job owner.');
                popup.querySelectorAll('button,input').forEach((control) => { control.disabled = false; });
                popup.classList.remove('is-saving');
            }
        }));
        requestAnimationFrame(() => search?.focus({ preventScroll: true }));
        const closeOnOutside = (event) => {
            if (popup.contains(event.target) || event.target === button) return;
            popup.remove();
            ownerCell.classList.remove('is-editing');
            document.removeEventListener('click', closeOnOutside);
        };
        setTimeout(() => document.addEventListener('click', closeOnOutside), 0);
    }

    function jobListOpenDeliveryEditor(button, job) {
        const cell = button.closest('td');
        const deliveryCell = button.closest('.jobs-v2-delivery-cell');
        if (!cell || !deliveryCell) return;
        if (cell.querySelector('.jobs-v2-delivery-editor')) return;
        jobListCloseInlineEditors();
        deliveryCell.classList.add('is-editing');
        const editor = document.createElement('div');
        editor.className = 'jobs-v2-delivery-editor';
        editor.innerHTML = `<input type="date" value="${html(job.delivery || '')}" aria-label="Delivery date"><button type="button" class="jobs-v2-date-save" title="Save">✓</button><button type="button" class="jobs-v2-date-cancel" title="Cancel">×</button>`;
        document.body.appendChild(editor);
        const anchorRect = deliveryCell.getBoundingClientRect();
        const editorWidth = 212;
        editor.style.left = `${Math.max(8, Math.min(window.innerWidth - editorWidth - 8, anchorRect.left))}px`;
        editor.style.top = `${Math.min(window.innerHeight - 60, anchorRect.bottom + 6)}px`;
        const input = editor.querySelector('input');
        let closeOnOutside = null;
        const cancel = () => { editor.remove(); deliveryCell.classList.remove('is-editing'); if (closeOnOutside) document.removeEventListener('click', closeOnOutside); };
        editor.querySelector('.jobs-v2-date-cancel')?.addEventListener('click', cancel);
        const save = async () => {
            const value = input?.value || null;
            editor.querySelectorAll('button,input').forEach((control) => { control.disabled = true; });
            try {
                await flowtrackJobV4SaveJob(job, { delivery_date: value }, 'Delivery date updated.');
                renderJobs();
            } catch (error) {
                showToast(error.message || 'Unable to update delivery date.');
                editor.querySelectorAll('button,input').forEach((control) => { control.disabled = false; });
            }
        };
        editor.querySelector('.jobs-v2-date-save')?.addEventListener('click', save);
        input?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') { event.preventDefault(); save(); }
            if (event.key === 'Escape') { event.preventDefault(); cancel(); }
        });
        requestAnimationFrame(() => { input?.focus({ preventScroll: true }); try { input?.showPicker?.(); } catch (_) {} });
        closeOnOutside = (event) => { if (!editor.contains(event.target) && event.target !== button) cancel(); };
        setTimeout(() => document.addEventListener('click', closeOnOutside), 0);
    }

    function jobListMoreFilterMenuHtml() {
        return `<div class="jobs-v2-more-menu hidden" id="jobMoreFilterMenu"><button type="button" data-job-more-option="documents" class="${jobListFilters.more === 'documents' ? 'active' : ''}"><span>Missing documents</span>${jobListFilters.more === 'documents' ? '<b>✓</b>' : ''}</button><button type="button" data-job-more-option="" class="${!jobListFilters.more ? 'active' : ''}"><span>Any document state</span>${!jobListFilters.more ? '<b>✓</b>' : ''}</button></div>`;
    }

    function jobListActiveFilterLabel() {
        const labels = { all: 'All active', attention: 'Needs attention', week: 'Due this week', waiting: 'Waiting for client', unpaid: 'Unpaid invoices', completed: 'Completed' };
        return labels[jobListFilters.saved] || 'All active';
    }

    function jobListRow(job) {
        const nextTask = jobListNextTask(job);
        const invoice = jobListInvoiceState(job);
        const productCount = Number(job.productCount || (Array.isArray(job.products) ? job.products.length : (job.product ? 1 : 0)) || 0);
        const unitCount = Number(job.qty || (Array.isArray(job.products) ? job.products.reduce((sum, item) => sum + Number(item.qty || item.quantity || 0), 0) : 0) || 0);
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        const totalPhases = (getWorkflow(Number(job.workflow))?.phases || []).filter((phase) => phase.active !== false).length || 11;
        const phaseNumber = Math.min(totalPhases, Math.max(1, Number(job.phase || 0) + 1));
        const overdue = boardDaysOverdue(job.delivery) > 0 && !jobIsCompleted(job);
        const checked = selectedBulkJobIds.has(Number(job.dbId)) ? 'checked' : '';
        return `<tr class="${checked ? 'jobs-v2-row-selected' : ''}">
            <td class="jobs-v2-cell-select"><input type="checkbox" data-job-bulk-checkbox="${html(job.dbId)}" aria-label="Select ${html(job.id)}" ${checked}></td>
            <td class="jobs-v2-cell-code"><button type="button" class="jobs-v2-job-code" data-board-job-link="${html(job.id)}">${html(job.id)}</button><div class="jobs-v2-order">${html(job.order || 'RFQ-' + String(job.id || '').slice(-5))}</div></td>
            <td class="jobs-v2-cell-client"><div class="jobs-v2-client">${html(clientName(job.client))}</div><div class="jobs-v2-brief truncate">${html(job.title || '')}</div></td>
            <td class="jobs-v2-cell-product"><div class="jobs-v2-product">${html(job.product || 'Unspecified product')}</div><div class="muted small">${productCount || 1} product${(productCount || 1) === 1 ? '' : 's'} · ${unitCount.toLocaleString()} units</div></td>
            <td class="jobs-v2-cell-phase"><span class="jobs-v2-phase">${html(phaseShortFor(job))}</span><div class="muted small" style="margin-top:4px">${phaseNumber} of ${totalPhases}</div></td>
            <td class="jobs-v2-cell-next"><div class="jobs-v2-next">${html(nextTask?.title || job.next || 'Review current phase')}</div><div class="jobs-v2-due-note">${boardDaysOverdue(nextTask?.due || job.delivery) ? 'Overdue' : 'Due'} ${boardShortDate(nextTask?.due || job.delivery)}</div></td>
            <td class="jobs-v2-cell-health">${healthBadge(job.health, job.healthReason)}</td>
            <td class="jobs-v2-cell-owner">${jobListOwnerCell(job)}</td>
            <td class="jobs-v2-cell-delivery">${jobListDeliveryCell(job, overdue)}</td>
            <td><div class="jobs-v2-progress"><div class="progress"><span class="${progress >= 100 ? 'complete' : ''}" style="width:${progress}%"></span></div><span>${progress}%</span></div></td>
            <td><span class="jobs-v2-invoice ${invoice.tone}">${html(invoice.label)}</span></td>
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

    registerFlowtrackExtension('renderJobs', 'renderJobs-stage-1', function () {        jobListCloseInlineEditors();
        const activeJobs = jobListActiveJobs();
        const stats = {
            active: activeJobs.length,
            attention: activeJobs.filter((job) => job.attention || !['ON TRACK', 'COMPLETED'].includes(normalizedStatusValue(job.health || ''))).length,
            week: activeJobs.filter((job) => { const days = boardDaysUntil(job.delivery); return days !== null && days >= 0 && days <= 7; }).length,
            waiting: activeJobs.filter(jobListHasWaitingClient).length,
            unpaid: activeJobs.filter((job) => /PENDING|OVERDUE|INVOICE/.test(normalizedStatusValue(jobListInvoiceState(job).label))).length,
            completed: jobs.filter(jobIsCompleted).length,
        };
        const filtered = jobListFilteredJobs();
        const pagination = jobListPaginationState(filtered);
        const visibleJobs = pagination.items;
        $('#page-jobs').innerHTML = `<div class="jobs-v2"><div class="page-head"><div><h1>Jobs</h1><p>Manage active jobs from request to collection</p></div><div class="jobs-v2-actions"><button class="ghost" id="jobExport">Export</button><button class="ghost" id="jobColumns">Columns</button><button class="primary" id="jobCreate">＋ New Job</button></div></div>
        <div class="jobs-v2-saved">${[
            ['all', 'All active', stats.active, ''], ['attention', 'Needs attention', stats.attention, 'danger'], ['week', 'Due this week', stats.week, ''], ['waiting', 'Waiting for client', stats.waiting, ''], ['unpaid', 'Unpaid invoices', stats.unpaid, 'warn'], ['completed', 'Completed', stats.completed, '']
        ].map(([key, label, count, tone]) => `<button type="button" class="jobs-v2-chip ${tone} ${jobListFilters.saved === key ? 'active' : ''}" data-job-saved="${key}">${label} <span class="ops-count">${count}</span></button>`).join('')}</div>
        <div class="card jobs-v2-toolbar"><div class="jobs-v2-toolbar-main"><input id="jobSearch" class="jobs-v2-search" placeholder="Search Job ID, order, client or product" value="${html(jobFilter.search || '')}"><select id="jobPhase"><option value="">Phase</option>${jobListPhaseOptions()}</select><select id="jobHealth"><option value="">Health</option>${['On Track','Needs Attention','At Risk','Delayed','Blocked','Completed'].map((health) => `<option value="${html(health)}" ${normalizedStatusValue(jobFilter.health || '') === normalizedStatusValue(health) ? 'selected' : ''}>${html(health)}</option>`).join('')}</select><select id="jobOwner"><option value="">Owner</option>${jobListOwnerOptions()}</select><select id="jobClient"><option value="">Client</option>${flowtrackClientOptionRows().map((client) => `<option value="${html(client.id)}" ${String(jobFilter.client || '') === String(client.id) ? 'selected' : ''}>${html(client.name)}</option>`).join('')}</select><select id="jobDelivery"><option value="">Delivery</option><option value="week" ${jobListFilters.delivery === 'week' ? 'selected' : ''}>Due this week</option><option value="overdue" ${jobListFilters.delivery === 'overdue' ? 'selected' : ''}>Overdue</option></select><select id="jobInvoice"><option value="">Invoice</option><option value="unpaid" ${jobListFilters.invoice === 'unpaid' ? 'selected' : ''}>Unpaid</option></select><div class="jobs-v2-more-wrap"><button type="button" id="jobMoreFilterBtn" class="${jobListFilters.more ? 'active' : ''}"><span class="jobs-v2-filter-icon">▽</span> More filters</button>${jobListMoreFilterMenuHtml()}</div><button type="button" id="clearJobFilter" class="jobs-v2-clear-filter">× Clear filters</button></div><div class="jobs-v2-active-filters"><button type="button" id="jobSavedFilterToken">${html(jobListActiveFilterLabel())} <span>×</span></button>${jobListFilters.more === 'documents' ? '<button type="button" data-clear-job-more>Missing documents <span>×</span></button>' : ''}</div></div>
        ${jobBulkActionBar(visibleJobs)}
        <div class="card jobs-v2-table table-wrap"><table class="data-table jobs-v2-data-table">${jobListTableColumnsHtml()}<thead><tr><th><input type="checkbox" id="jobBulkSelectAll" aria-label="Select all shown jobs"></th><th>Job / Order</th><th>Client / Brief</th><th>Product / Qty</th><th>Phase</th><th>Next Action</th><th>Health</th><th>Owner</th><th>Delivery ↓</th><th>Progress</th><th>Invoice</th><th></th></tr></thead><tbody>${visibleJobs.map(jobListRow).join('') || '<tr><td colspan="12"><div class="jobs-v2-empty">No jobs match the selected filters.</div></td></tr>'}</tbody></table></div>${jobListPaginationHtml(pagination)}</div>`;
        $('#jobCreate')?.addEventListener('click', () => openJobModal());
        $('#jobExport')?.addEventListener('click', () => showToast('Job list export prepared.'));
        $('#jobColumns')?.addEventListener('click', () => showToast('Compact columns are enabled for this view.'));
        $('#jobSearch')?.addEventListener('input', (event) => {
            const value = event.target.value;
            const caret = event.target.selectionStart ?? value.length;
            jobFilter.search = value;
            resetJobListPage();
            renderJobs();
            requestAnimationFrame(() => {
                const input = $('#jobSearch');
                if (!input) return;
                input.focus({ preventScroll: true });
                input.setSelectionRange(caret, caret);
            });
        });
        $('#jobPhase')?.addEventListener('change', (event) => { jobFilter.phase = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobHealth')?.addEventListener('change', (event) => { jobFilter.health = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobOwner')?.addEventListener('change', (event) => { jobListFilters.owner = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobClient')?.addEventListener('change', (event) => { jobFilter.client = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobDelivery')?.addEventListener('change', (event) => { jobListFilters.delivery = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobInvoice')?.addEventListener('change', (event) => { jobListFilters.invoice = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobMoreFilterBtn')?.addEventListener('click', (event) => { event.stopPropagation(); $('#jobMoreFilterMenu')?.classList.toggle('hidden'); });
        $$('[data-job-more-option]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); jobListFilters.more = button.dataset.jobMoreOption || ''; resetJobListPage(); renderJobs(); }));
        $('[data-clear-job-more]')?.addEventListener('click', () => { jobListFilters.more = ''; resetJobListPage(); renderJobs(); });
        $('#jobSavedFilterToken')?.addEventListener('click', () => { jobListFilters.saved = 'all'; resetJobListPage(); renderJobs(); });
        $('#clearJobFilter')?.addEventListener('click', () => { jobFilter = { search: '', phase: '', health: '', client: '' }; jobListFilters = { owner: '', delivery: '', invoice: '', more: '', saved: 'all' }; resetJobListPage(); renderJobs(); });
        $('#jobPageSize')?.addEventListener('change', (event) => {
            const nextSize = Number(event.target.value) || 10;
            jobListPageSize = [10, 20, 30, 40].includes(nextSize) ? nextSize : 10;
            try { localStorage.setItem('flowtrackJobListPageSize', String(jobListPageSize)); } catch (_) {}
            resetJobListPage();
            renderJobs();
        });
        $('#jobPrevPage')?.addEventListener('click', () => { if (jobListPage > 1) { jobListPage -= 1; renderJobs(); } });
        $('#jobNextPage')?.addEventListener('click', async () => {
            if (jobListPage < pagination.pageCount) {
                jobListPage += 1;
                renderJobs();
                return;
            }
            if (segmentPagination.jobs?.nextPageUrl) {
                const loaded = await flowtrackLoadNextSegment('jobs', { render: false });
                if (loaded) {
                    const refreshed = jobListPaginationState(jobListFilteredJobs());
                    if (jobListPage < refreshed.pageCount) jobListPage += 1;
                    renderJobs();
                }
            }
        });
        const selectAll = $('#jobBulkSelectAll');
        if (selectAll) {
            const selectable = visibleJobs.filter((job) => Number(job.dbId));
            const selectedVisibleCount = selectedVisibleBulkJobs(visibleJobs).length;
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
        $('#jobBulkDeactivate')?.addEventListener('click', () => runJobBulkAction('deactivate', visibleJobs));
        $('#jobBulkDelete')?.addEventListener('click', () => runJobBulkAction('delete', visibleJobs));
        $$('[data-job-saved]').forEach((button) => button.addEventListener('click', () => { jobListFilters.saved = button.dataset.jobSaved; resetJobListPage(); renderJobs(); }));
        $$('[data-job-list-edit-owner]').forEach((button) => button.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); const job = jobs.find((item) => String(item.id) === String(button.dataset.jobListEditOwner)); if (job) jobListOpenOwnerEditor(button, job); }));
        $$('[data-job-list-edit-delivery]').forEach((button) => button.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); const job = jobs.find((item) => String(item.id) === String(button.dataset.jobListEditDelivery)); if (job) jobListOpenDeliveryEditor(button, job); }));
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
        $('#boardCreate')?.addEventListener('click', () => openJobModal());
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
        bindBoardDueDatePickers('#kanbanArea', renderBoard);
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
                if (filter === 'risk') { jobListFilters.saved = 'attention'; resetJobListPage(); renderJobs(); }
                if (filter === 'shipment') { const phase = flowtrackShippingPhaseFilterValue(); if (phase !== '') jobFilter.phase = String(phase); resetJobListPage(); renderJobs(); }
                if (filter === 'payment') { jobListFilters.invoice = 'unpaid'; resetJobListPage(); renderJobs(); }
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
        resetJobListPage();
    }

    function resetFlowtrackMyWorkFilters() {
        myWorkSearchValue = '';
        myWorkQuickFilter = 'all';
        myWorkFilters = { job: '', client: '', assignee: '', status: '', priority: '', due: '' };
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
        const metrics = { ...dynamicDashboardMetrics(), ...(summaryMetrics.dashboard || {}) };
        const activeJobs = jobs.filter((job) => !jobIsCompleted(job));
        const attentionJobs = activeJobs
            .filter((job) => jobNeedsAttentionFromTasks(job) || job.attention || ['needs attention', 'at risk', 'delayed', 'blocked'].includes(String(job.health || '').toLowerCase()))
            .sort((a, b) => String(a.delivery || '9999-12-31').localeCompare(String(b.delivery || '9999-12-31')))
            .slice(0, 6);
        const serverPhaseCounts = flowtrackToArray(summaryMetrics.dashboard?.phaseBreakdown)
            .map((item) => [item?.label || 'Unassigned', Number(item?.value || 0)]);
        const phaseMap = new Map();
        activeJobs.forEach((job) => {
            const label = phaseShortFor(job) || phaseNameFor(job) || 'Unassigned';
            phaseMap.set(label, (phaseMap.get(label) || 0) + 1);
        });
        const phaseCounts = (serverPhaseCounts.length ? serverPhaseCounts : Array.from(phaseMap.entries()))
            .filter((entry) => Array.isArray(entry) && entry.length >= 2)
            .sort((left, right) => Number(right[1] || 0) - Number(left[1] || 0));
        const maxPhase = Math.max(1, ...phaseCounts.map((entry) => Number(entry?.[1] || 0)));
        const serverWorkload = flowtrackToArray(summaryMetrics.dashboard?.workload)
            .map((item) => [item?.label || 'Unassigned', Number(item?.value || 0)]);
        const workloadMap = new Map();
        tasks.filter((task) => !taskIsCompleted(task)).forEach((task) => {
            const assignee = task.assignee || 'Unassigned';
            workloadMap.set(assignee, (workloadMap.get(assignee) || 0) + 1);
        });
        const workload = (serverWorkload.length ? serverWorkload : Array.from(workloadMap.entries()))
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
        $$('[data-dashboard-action="create"]').forEach((button) => button.addEventListener('click', () => openJobModal()));
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

    function myWorkIsAdministrativeView() {
        return Boolean(isFlowAdmin || isFlowSuperAdmin);
    }

    function myWorkBaseTasks() {
        const visibleTasks = flowtrackVisibleTaskRows(tasks);
        return myWorkIsAdministrativeView() ? visibleTasks : visibleTasks.filter(taskAssignedToCurrentUser);
    }

    function myWorkBaseJobs() {
        if (myWorkIsAdministrativeView()) return flowtrackToArray(jobs).filter(Boolean);

        const taskJobKeys = new Set();
        myWorkBaseTasks().forEach((task) => {
            if (task?.job) taskJobKeys.add(String(task.job));
            if (task?.jobDbId) taskJobKeys.add(`db:${Number(task.jobDbId)}`);
        });
        return flowtrackToArray(jobs).filter((job) => taskJobKeys.has(String(job.id)) || taskJobKeys.has(`db:${Number(job.dbId)}`));
    }

    function myWorkHasTaskScopedFilter() {
        return Boolean(
            myWorkFilters.assignee
            || myWorkFilters.status
            || myWorkFilters.priority
            || myWorkFilters.due
            || (myWorkQuickFilter && myWorkQuickFilter !== 'all')
        );
    }

    function myWorkFilteredJobs(filteredTasks = null) {
        const taskList = Array.isArray(filteredTasks) ? filteredTasks : myWorkFilteredTasks();
        let list = myWorkBaseJobs();
        const matchingTaskJobs = new Set();
        taskList.forEach((task) => {
            if (task?.job) matchingTaskJobs.add(String(task.job));
            if (task?.jobDbId) matchingTaskJobs.add(`db:${Number(task.jobDbId)}`);
        });
        const matchesTaskJob = (job) => matchingTaskJobs.has(String(job?.id)) || matchingTaskJobs.has(`db:${Number(job?.dbId)}`);

        if (myWorkFilters.job) list = list.filter((job) => String(job.id) === String(myWorkFilters.job));
        if (myWorkFilters.client) list = list.filter((job) => String(job.client) === String(myWorkFilters.client));

        const search = myWorkSearchValue.trim().toLowerCase();
        if (search) {
            list = list.filter((job) => {
                const directHaystack = [job?.id, job?.order, job?.title, job?.product, job?.owner, job?.coordinator, phaseNameFor(job), clientName(job?.client)].join(' ').toLowerCase();
                return directHaystack.includes(search) || matchesTaskJob(job);
            });
        }

        if (myWorkHasTaskScopedFilter()) list = list.filter(matchesTaskJob);

        return list.slice().sort((left, right) => String(left?.delivery || '9999-12-31').localeCompare(String(right?.delivery || '9999-12-31')) || String(left?.id || '').localeCompare(String(right?.id || '')));
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
        if (myWorkFilters.assignee) {
            const selectedAssignee = boardAssigneeFilterUsers().find((user) => String(user?.id || user?.name || '') === String(myWorkFilters.assignee));
            const selectedId = selectedAssignee?.id || myWorkFilters.assignee;
            const selectedName = selectedAssignee?.name || myWorkFilters.assignee;
            list = list.filter((task) => boardTaskMatchesAssignee(task, selectedId, selectedName));
        }
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
        const ownJobs = myWorkBaseJobs();
        const jobOptions = boardSelectOptions(ownJobs, (job) => job.id, (job) => `${job.id} · ${clientName(job.client)}`, myWorkFilters.job, 'Job');
        const clientIds = new Set(ownJobs.map((job) => String(job.client)).filter(Boolean));
        const clientOptions = boardSelectOptions(clients.filter((client) => clientIds.has(String(client.id))), (client) => client.id, (client) => client.name, myWorkFilters.client, 'Client');
        const assigneeOptions = boardSelectOptions(
            boardAssigneeFilterUsers().filter((user) => ownTasks.some((task) => boardTaskMatchesAssignee(task, user.id || user.name, user.name))),
            (user) => user.id || user.name,
            (user) => user.name,
            myWorkFilters.assignee,
            'Assignee'
        );
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
            <label class="ops-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg><input id="myWorkBoardSearch" value="${html(myWorkSearchValue)}" placeholder="${myWorkIsAdministrativeView() ? 'Search jobs, tasks, clients or assignees' : 'Search my assigned tasks, job ID or client'}"></label>
            <select class="ops-filter" data-mywork-filter="job">${jobOptions}</select>
            <select class="ops-filter" data-mywork-filter="client">${clientOptions}</select>
            <select class="ops-filter" data-mywork-filter="assignee">${assigneeOptions}</select>
            <select class="ops-filter" data-mywork-filter="status"><option value="">Status</option>${boardColumnDefinitions().map((column) => `<option value="${column.key}" ${myWorkFilters.status === column.key ? 'selected' : ''}>${column.label}</option>`).join('')}</select>
            <select class="ops-filter" data-mywork-filter="priority">${priorityOptions}</select>
            <select class="ops-filter" data-mywork-filter="due"><option value="" ${!myWorkFilters.due ? 'selected' : ''}>Due date</option><option value="overdue" ${myWorkFilters.due === 'overdue' ? 'selected' : ''}>Overdue</option><option value="today" ${myWorkFilters.due === 'today' ? 'selected' : ''}>Due today</option><option value="week" ${myWorkFilters.due === 'week' ? 'selected' : ''}>Due this week</option></select>
            <button type="button" class="ops-clear" id="myWorkBoardClear">Clear</button>
        </div>
        <div class="ops-quick-row"><div class="ops-quick-left"><span class="ops-quick-label">Quick filters</span>${[
            ['open', myWorkIsAdministrativeView() ? 'Open' : 'My open', quickCounts.open, ''],
            ['overdue', 'Overdue', quickCounts.overdue, 'danger'],
            ['week', 'Due this week', quickCounts.week, ''],
            ['blocked', 'Blocked', quickCounts.blocked, 'danger'],
            ['waiting', 'Waiting external', quickCounts.waiting, ''],
            ['done', 'Completed', quickCounts.done, ''],
        ].map(([key, label, count, tone]) => `<button type="button" class="ops-chip ${tone} ${myWorkQuickFilter === key ? 'active' : ''}" data-mywork-quick="${key}">${label} <span class="ops-count">${count}</span></button>`).join('')}</div></div>`;
    }

    function myWorkStatusSummary(filteredCount, filteredJobCount) {
        const taskLabel = myWorkIsAdministrativeView() ? 'visible task' : 'assigned task';
        return `<div class="ops-status-summary my-work-footer-summary"><span>Showing <b>${filteredCount}</b> ${taskLabel}${filteredCount === 1 ? '' : 's'} across <b>${filteredJobCount}</b> ${filteredJobCount === 1 ? 'job' : 'jobs'}</span></div>`;
    }

    function renderMyWorkBoard() {
        const area = $('#myWorkKanbanArea');
        if (!area) return;
        const taskList = myWorkFilteredTasks();
        const jobList = myWorkFilteredJobs(taskList);
        area.innerHTML = renderOperationsTaskBoard(taskList, jobList);
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
        bindBoardDueDatePickers('#myWorkKanbanArea', renderMyWorkBoard);
        bindCommon();
    }

    renderMyWork = function () {
        const filteredTasks = myWorkFilteredTasks();
        const filteredJobs = myWorkFilteredJobs(filteredTasks);
        const filteredJobCount = filteredJobs.length;
        const myWorkSubtitle = myWorkIsAdministrativeView() ? 'All visible jobs and tasks across the workspace' : 'All tasks assigned to you across jobs';
        $('#page-mywork').innerHTML = `<div class="ops-board-page ops-mode-tasks my-work-board"><div class="page-head"><div><h1>My Work</h1><p>${myWorkSubtitle}</p></div><div class="ops-board-actions"><button type="button" class="primary" data-go="jobs">Jobs</button></div></div>
        ${myWorkToolbar(filteredTasks.length, filteredJobCount)}
        <div id="myWorkKanbanArea"></div>
        ${myWorkStatusSummary(filteredTasks.length, filteredJobCount)}</div>`;

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
        $('#myWorkBoardClear')?.addEventListener('click', () => { myWorkSearchValue = ''; myWorkQuickFilter = 'all'; myWorkFilters = { job: '', client: '', assignee: '', status: '', priority: '', due: '' }; renderMyWork(); });
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
        const serverPhaseData = flowtrackToArray(report.phaseBreakdown).map((item) => ({ label: item?.label || 'Unassigned', value: Number(item?.value || 0) }));
        const phaseData = serverPhaseData.length ? serverPhaseData : flowtrackToArray(phaseMap).map(([label, value]) => ({ label, value }));
        const workloadMap = new Map();
        tasks.filter((task) => !taskIsCompleted(task)).forEach((task) => workloadMap.set(task.assignee || 'Unassigned', (workloadMap.get(task.assignee || 'Unassigned') || 0) + 1));
        const serverWorkload = flowtrackToArray(report.workload).map((item) => ({ label: item?.label || 'Unassigned', value: Number(item?.value || 0) }));
        const workload = (serverWorkload.length ? serverWorkload : flowtrackToArray(workloadMap).map(([label, value]) => ({ label, value }))).sort((a, b) => b.value - a.value).slice(0, 10);
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
        const visibleOpenTasks = flowtrackVisibleTaskRows(tasks).filter((task) => !taskIsCompleted(task));
        const myWorkOpenTasks = myWorkIsAdministrativeView() ? visibleOpenTasks : visibleOpenTasks.filter(taskAssignedToCurrentUser);
        if ($('#myWorkBadge')) $('#myWorkBadge').textContent = myWorkOpenTasks.length;
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
            name: $('#masterName').value.trim(),
            description: $('#masterDetail').value.trim() || null,
            status: $('#masterStatus').value.toLowerCase(),
            sort_order: Number($('#masterSortOrder').value || 0),
        };
    }

    function masterRowsHtmlDynamic(rows) {
        const section = masterSection();
        const hasParent = Boolean(section?.parentKey);
        const colspan = hasParent ? 6 : 5;
        return rows.map((record) => {
            const parentCell = hasParent ? `<td data-label="Parent">${html(masterParentName(record) || '—')}</td>` : '';
            return `<tr><td data-label="Code"><b>${html(record.code)}</b></td><td data-label="Name">${html(record.name)}</td>${parentCell}<td data-label="Description / Use">${html(record.detail || record.description || '')}</td><td data-label="Status"><span class="badge ${String(record.status).toLowerCase() === 'active' ? 'b-green' : 'b-gray'}">${html(record.status)}</span></td><td data-label="Actions"><div class="row-actions"><button class="mini-btn" data-master-edit="${record.id}">Edit</button><button class="mini-btn" data-master-toggle="${record.id}">${String(record.status).toLowerCase() === 'active' ? 'Deactivate' : 'Activate'}</button><button class="mini-btn danger-btn" data-master-delete="${record.id}">Delete</button></div></td></tr>`;
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
                flowtrackInvalidatePageData('masterdata');
                await flowtrackEnsurePageData('masterdata', { force: true });
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
        const allRows = masterCatalog.flatMap((item) => masterData[item.key] || []);
        $('#page-masterdata').innerHTML = `<div class="page-head"><div><h1>Master Data</h1><p>Maintain the values used in Jobs, tasks, documents, production and shipment forms</p></div><div class="actions"><button class="ghost" id="masterImport">Import</button><button class="primary" id="addMasterRecord">＋ Add Record</button></div></div>
        <div class="config-summary"><div class="card config-stat"><span>Master Categories</span><b>${masterCatalog.length}</b></div><div class="card config-stat"><span>Total Records</span><b>${allRows.length}</b></div><div class="card config-stat"><span>Active Records</span><b>${allRows.filter((item) => String(item.status).toLowerCase() === 'active').length}</b></div><div class="card config-stat"><span>Selected Category</span><b style="font-size:15px">${html(label)}</b></div></div>
        <div class="master-layout"><div class="card master-categories">${masterCatalog.map((item) => `<button class="${item.key === selectedMasterCategory ? 'active' : ''}" data-master-category="${html(item.key)}"><b>${html(item.label)}</b><small>${(masterData[item.key] || []).length} records</small></button>`).join('')}</div><div><div class="card section-card"><div class="master-head"><div><h3 style="margin:0 0 4px">${html(label)}</h3><div class="small muted">All records in this section are loaded from the database. Codes are generated automatically and cannot be changed.</div></div><input id="masterSearch" style="max-width:260px;border:1px solid var(--line);border-radius:8px;padding:9px" placeholder="Search records…"></div><div class="table-wrap"><table class="master-table"><thead><tr><th>Code</th><th>Name</th>${parentHeader}<th>Description / Use</th><th>Status</th><th>Actions</th></tr></thead><tbody id="masterRows">${masterRowsHtmlDynamic(rows)}</tbody></table></div></div></div></div>`;

        $$('[data-master-category]').forEach((button) => button.addEventListener('click', () => {
            selectedMasterCategory = button.dataset.masterCategory;
            renderMasterData();
        }));
        $('#addMasterRecord')?.addEventListener('click', () => openMasterModal(selectedMasterCategory));
        $('#masterImport')?.addEventListener('click', () => showToast('Excel/CSV import will use the database section and automatic code sequence.'));
        $('#masterSearch')?.addEventListener('input', (event) => {
            const query = event.target.value.toLowerCase();
            $('#masterRows').innerHTML = masterRowsHtmlDynamic(rows.filter((record) => `${record.code} ${record.name} ${masterParentName(record)} ${record.detail || record.description || ''}`.toLowerCase().includes(query)));
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
            ${section.parentKey ? `<div class="field full"><label>${html(masterLabels[section.parentKey] || 'Parent')}</label><select id="masterParent" data-searchable data-search-placeholder="Search parent…"><option value="">No parent</option>${parentRecords.map((parent) => `<option value="${parent.id}" ${Number(existing?.parent_id) === Number(parent.id) ? 'selected' : ''}>${html(parent.code)} · ${html(parent.name)}</option>`).join('')}</select></div>` : ''}
            <div class="field full"><label>Description / Use</label><textarea id="masterDetail" rows="3">${html(existing?.detail || existing?.description)}</textarea></div>
            <div class="field"><label>Display order</label><input id="masterSortOrder" type="number" min="0" value="${Number(existing?.sort_order || 0)}"></div>
        </div></div>
        <div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="saveMasterRecord">Save Record</button></div>`;

        openOverlay();
        $('#jobModal').classList.add('open');
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
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
                    flowtrackInvalidatePageData('taskpacksetup');
                    void flowtrackEnsurePageData('taskpacksetup', { force: true })
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
        <div class="field full"><label>Client *</label><select id="newJobClient" data-searchable data-search-placeholder="Search clients…">${flowtrackClientOptionRows().map((client) => `<option value="${client.id}" ${Number(defaultClient?.id) === Number(client.id) ? 'selected' : ''}>${html(client.name)}</option>`).join('')}</select></div>
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
        $('[data-new-job-choose-documents]')?.addEventListener('click', (event) => {
            event.preventDefault(); event.stopPropagation();
            if (!canFlow('documents', 'view')) return showToast('Your role cannot view stored documents.');
            const available = flowtrackToArray(docs).filter((item) => Number(item?.dbId));
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
            <div class="field full"><label>Client *</label><select id="editJobClient" data-searchable data-search-placeholder="Search clients…">${flowtrackClientOptionRows().map((client) => `<option value="${client.id}" ${Number(client.id) === Number(job.client) ? 'selected' : ''}>${html(client.name)}</option>`).join('')}</select></div>
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
                flowtrackInvalidatePageData('setup');
                await flowtrackEnsurePageData('setup', { force: true });
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
                flowtrackInvalidatePageData('setup');
                await flowtrackEnsurePageData('setup', { force: true });
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
                flowtrackInvalidatePageData('setup');
                await flowtrackEnsurePageData('setup', { force: true });
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
                flowtrackInvalidatePageData('setup');
                await flowtrackEnsurePageData('setup', { force: true });
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

    
    /* FlowTrack lazy module boundary: records. */

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
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
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






    
    /* CSS externalized: flowtrackClickablePhaseStyles. */

    
    /* CSS externalized: standalonePageStyles. */

    let standaloneBackPage = 'dashboard';
    let standaloneActivePageId = null;
    const baseCloseAllForStandalone = closeAll;
    closeAll = function () {
        baseCloseAllForStandalone();
        if (standaloneActivePageId && currentPage === standaloneActivePageId) {
            const destination = firstAllowedPage(standaloneBackPage);
            standaloneActivePageId = null;
            setTimeout(() => navigate(destination), 0);
        }
    };

    function flowtrackCloseModalOnly() {
        // Close an overlay/modal without leaving a standalone Job/Task detail route.
        baseCloseAllForStandalone();
    }

    function standaloneSourcePage(fallback = null) {
        const page = currentPage && !String(currentPage).startsWith('record-') ? currentPage : standaloneBackPage;
        return firstAllowedPage(page || fallback);
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
        standaloneBackPage = firstAllowedPage(backPage || standaloneBackPage);
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
                const destination = firstAllowedPage(standaloneBackPage);
                standaloneActivePageId = null;
                navigate(destination);
            });
        });
        page.querySelectorAll('[data-close]').forEach((button) => {
            button.addEventListener('click', (event) => {
                if (!standaloneActivePageId || currentPage !== standaloneActivePageId) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                const destination = firstAllowedPage(standaloneBackPage);
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


    
    /* CSS externalized: flowtrackFinalPolishStyles. */

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


    
    /* CSS externalized: flowtrackFinalFixStyles. */

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


    
    /* CSS externalized: flowtrackEmergencyFormFixStyles. */

    document.addEventListener('click', (event) => {
        if (event.target.closest('#createJob,#saveDraftJob,#saveEditJob,#saveTaskUpdate,#saveTaskDraft,#saveTaskDueDate,#saveClient,#createClient,#saveWorkflow,#saveTaskPack')) {
            sessionActivityPending = true;
        }
    }, true);


    
    /* CSS externalized: flowtrackTaskInteractionStyles. */

    
    /* CSS externalized: flowtrackBoardFilterCleanupStyles. */

    
    /* CSS externalized: flowtrackBoardAssigneeStickyFinalStyles. */


    
    /* CSS externalized: flowtrackMyWorkAdminStickyTaskBoardStyles. */

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
                const page = firstAllowedPage(route.page);
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
        flowtrackSyncRoute(currentPage || firstAllowedPage(), true);
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
    showApplication = function (requestedPage = null) {
        const initialRoute = flowtrackInitialRoute();
        flowtrackRouteSyncSuppressed = true;
        flowtrackHistoryShowApplication(requestedPage);
        flowtrackRouteSyncSuppressed = false;
        if (!flowtrackOpenInitialRoute(initialRoute)) {
            flowtrackSyncRoute(currentPage || firstAllowedPage(currentUser?.defaultLandingPage), true);
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



    
    /* FlowTrack lazy module boundary: job-shared. */

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

    function flowtrackJobDocumentPurposeOptions(job, activePhases = null) {
        const phases = activePhases || flowtrackActivePhases(job);
        const configured = flowtrackToArray(jobDocumentRequirements(job));
        if (configured.length) {
            return configured.map((requirement) => ({
                key: String(requirement.key || requirement.requirement_key || `${requirement.taskPackItemId || requirement.task_pack_item_id ? 'task' : 'phase'}:${requirement.taskPackItemId || requirement.task_pack_item_id || requirement.phaseId || requirement.workflow_phase_id}:${requirement.categoryId || requirement.category_id}`),
                phaseId: Number(requirement.phaseId || requirement.workflow_phase_id || job.phaseId || 0),
                phaseName: requirement.phaseName || requirement.workflow_phase_name || phaseNameFor(job),
                taskPackItemId: Number(requirement.taskPackItemId || requirement.task_pack_item_id || 0) || null,
                categoryId: Number(requirement.categoryId || requirement.category_id || 0),
                categoryName: requirement.categoryName || requirement.category_name || 'Required document',
                sourceLabel: requirement.sourceLabel || requirement.source_label || 'Workflow requirement',
                present: Boolean(requirement.present),
                requiredForReadiness: requirement.requiredForReadiness !== false && requirement.required_for_readiness !== false,
            })).filter((item) => item.phaseId && item.categoryId);
        }
        const currentPhase = phases.find((phase) => Number(phase.id) === Number(job.phaseId)) || phases[0] || null;
        return activeMaster('documentCategories').map((category) => ({
            key: `generic:${currentPhase?.id || job.phaseId || 0}:${category.id}`,
            phaseId: Number(currentPhase?.id || job.phaseId || 0),
            phaseName: currentPhase?.name || phaseNameFor(job),
            taskPackItemId: null,
            categoryId: Number(category.id),
            categoryName: category.name,
            sourceLabel: 'Job document',
            present: false,
            requiredForReadiness: false,
        })).filter((item) => item.phaseId && item.categoryId);
    }

    function flowtrackJobDocumentTask(documentRecord, allTasks) {
        const linkedTaskId = Number(documentRecord?.taskId || 0);
        if (linkedTaskId) {
            const exactById = allTasks.find((task) => Number(task.dbId || 0) === linkedTaskId);
            if (exactById) return exactById;
        }
        const taskPackItemId = Number(documentRecord?.taskPackItemId || 0);
        if (taskPackItemId) {
            const exact = allTasks.find((task) => Number(task.taskPackItemId || 0) === taskPackItemId && Number(task.phaseId || 0) === Number(documentRecord.phaseId || 0));
            if (exact) return exact;
        }
        const taskRequirement = normalizedStatusValue(documentRecord?.taskRequirement || '');
        if (taskRequirement) {
            const exactByTitle = allTasks.find((task) => normalizedStatusValue(task.title || task.taskPackItemTitle || '') === taskRequirement && Number(task.phaseId || 0) === Number(documentRecord.phaseId || 0));
            if (exactByTitle) return exactByTitle;
        }
        return null;
    }

    function flowtrackJobDocumentViewStatus(documentRecord) {
        const explicit = String(documentRecord?.status || documentRecord?.state || '').trim();
        if (explicit) return flowtrackDocumentStatusMeta(documentRecord);
        if (documentRecord?.final) return { status: 'Approved', tone: 'b-green' };
        return { status: 'In review', tone: 'b-blue' };
    }

    function flowtrackJobDocumentRefreshView(updatedJob) {
        const current = flowtrackFindJob(updatedJob?.id || updatedJob?.dbId) || updatedJob;
        if (!current) return;
        flowtrackEnsureJobPanels(current, 8);
        if (typeof flowtrackJobV4Apply === 'function') flowtrackJobV4Apply(current, 8);
    }

    function flowtrackDocumentsPanel(job, allJobDocs, activePhases) {
        const actualTasks = flowtrackJobAllTasks(job);
        const requirements = flowtrackJobDocumentPurposeOptions(job, activePhases).filter((item) => item.requiredForReadiness);
        const uploadPurposes = flowtrackJobDocumentPurposeOptions(job, activePhases);
        const missing = requirements.filter((item) => !item.present);
        const statusRows = allJobDocs.map((documentRecord) => ({ documentRecord, meta: flowtrackJobDocumentViewStatus(documentRecord) }));
        const approved = statusRows.filter((item) => item.meta.tone === 'b-green').length;
        const inReview = statusRows.filter((item) => item.meta.tone === 'b-blue' || item.meta.tone === 'b-amber').length;
        const needsActionDocuments = statusRows.filter((item) => item.meta.tone === 'b-red').length;
        const receivedCount = Math.max(0, requirements.length - missing.length);
        const completionPercent = requirements.length ? Math.round((receivedCount / requirements.length) * 100) : 100;
        const recentlyUpdatedCount = Math.min(3, allJobDocs.length);
        const recentIds = new Set(allJobDocs.slice(0, recentlyUpdatedCount).map((item) => String(item.id)));
        const latestDocument = allJobDocs[0] || null;
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(job.phaseId)) || activePhases[0] || null;
        const phasePalette = ['blue','violet','orange','green','cyan','purple','teal','amber','rose','brown','slate'];
        const ownerOptions = [...new Set(allJobDocs.map((item) => item.uploadedBy).filter(Boolean))].sort();
        const typeOptions = [...new Set([...allJobDocs.map((item) => item.type), ...requirements.map((item) => item.categoryName)].filter(Boolean))].sort();
        const taskOptions = [...new Map(actualTasks.map((task) => [String(task.id), task])).values()];
        const purposeOptions = uploadPurposes.map((item) => `<option value="${html(item.key)}">${html(item.phaseName)} · ${html(item.sourceLabel)} · ${html(item.categoryName)}</option>`).join('');
        const firstPurpose = uploadPurposes.find((item) => !item.present) || uploadPurposes[0] || null;
        const firstExpandedPhaseId = (activePhases.find((phase) => requirements.some((requirement) => Number(requirement.phaseId) === Number(phase.id) && !requirement.present)) || activePhases.find((phase) => allJobDocs.some((documentRecord) => Number(documentRecord.phaseId || 0) === Number(phase.id))) || currentPhase || activePhases[0])?.id;

        const phaseGroups = activePhases.map((phase, phaseIndex) => {
            const phaseDocs = allJobDocs.filter((documentRecord) => Number(documentRecord.phaseId || 0) === Number(phase.id) || (!documentRecord.phaseId && normalizedStatusValue(documentRecord.phase) === normalizedStatusValue(phase.name)));
            const phaseRequirements = requirements.filter((item) => Number(item.phaseId) === Number(phase.id));
            const phaseMissing = phaseRequirements.filter((item) => !item.present);
            const phaseReceived = phaseRequirements.length - phaseMissing.length;
            const isCurrent = Number(phase.id) === Number(job.phaseId);
            const expanded = isCurrent || phaseRequirements.length > 0 || Number(phase.id) === Number(firstExpandedPhaseId);
            const phaseTasks = actualTasks.filter((task) => Number(task.phaseId || 0) === Number(phase.id));
            const matchedDocIds = new Set();
            const taskGroups = phaseTasks.map((task) => {
                const taskDocs = phaseDocs.filter((documentRecord) => {
                    const linkedTask = flowtrackJobDocumentTask(documentRecord, actualTasks);
                    return linkedTask && String(linkedTask.id) === String(task.id);
                });
                if (!taskDocs.length) return '';
                taskDocs.forEach((item) => matchedDocIds.add(String(item.id)));
                const taskOwner = flowtrackResolveTaskAssigneeName(task, flowtrackResolveTaskAssigneeId(task)) || task.assignee || 'Unassigned';
                const documentRows = taskDocs.map((documentRecord) => {
                    const statusMeta = flowtrackJobDocumentViewStatus(documentRecord);
                    const fileBadge = flowtrackDocumentFileBadge(documentRecord);
                    const isRequired = requirements.some((item) => Number(item.phaseId) === Number(documentRecord.phaseId || phase.id) && Number(item.categoryId) === Number(documentRecord.categoryId) && Number(item.taskPackItemId || 0) === Number(documentRecord.taskPackItemId || 0));
                    return `<div class="job-v6-doc-row" data-job-doc-row="${html(documentRecord.id)}" data-phase="${html(phase.id)}" data-task="${html(task.id)}" data-type="${html(normalizedStatusValue(documentRecord.type || ''))}" data-status="${html(normalizedStatusValue(statusMeta.status))}" data-owner="${html(normalizedStatusValue(documentRecord.uploadedBy || ''))}" data-required="${isRequired ? '1' : '0'}" data-recent="${recentIds.has(String(documentRecord.id)) ? '1' : '0'}">
                        <div class="job-v6-doc-file"><span class="job-v6-file-icon ${html(fileBadge.toLowerCase())}">${html(fileBadge)}</span><div>${documentRecord.previewUrl ? `<a href="${html(documentRecord.previewUrl)}" target="_blank" rel="noopener">${html(documentRecord.name || 'Document')}</a>` : `<b>${html(documentRecord.name || 'Document')}</b>`}<small>${html(documentRecord.id || '')}</small></div></div>
                        <span>${html(documentRecord.type || fileBadge)}</span><span>${html(documentRecord.version || 'v1')}</span><span>${html(documentRecord.uploadedBy || job.owner || '—')}</span><span><em class="job-v6-status ${statusMeta.tone}">${html(statusMeta.status)}</em></span><span>${documentRecord.date ? html(formatDate(documentRecord.date)) : '—'}</span><span class="job-v6-row-actions"><button type="button" class="job-v6-row-more" data-doc="${html(documentRecord.id)}">•••</button>${canFlow('documents', 'delete') ? `<button type="button" class="job-v6-row-delete" data-job-doc-delete="${html(documentRecord.dbId)}" title="Delete document">×</button>` : ''}</span>
                    </div>`;
                }).join('');
                return `<div class="job-v6-task-group" data-job-doc-task-group data-task-id="${html(task.id)}"><button type="button" class="job-v6-task-head" data-job-doc-task-toggle><span class="job-v6-caret">⌄</span><span class="job-v6-task-icon">▧</span><b>${html(task.title || task.taskPackItemTitle || task.id)}</b><small>${html(task.id)} · ${html(taskOwner)}</small><span>${taskDocs.length} document${taskDocs.length === 1 ? '' : 's'}</span></button><div class="job-v6-task-body">${documentRows}</div></div>`;
            }).join('');
            const phaseRequirementRows = phaseRequirements.length ? `<div class="job-v6-phase-requirements"><div class="job-v6-phase-requirements-title"><b>Required documents</b><span>${phaseReceived} of ${phaseRequirements.length} received</span></div>${phaseRequirements.map((item) => {
                const taskForRequirement = item.taskPackItemId ? phaseTasks.find((task) => Number(task.taskPackItemId || 0) === Number(item.taskPackItemId || 0)) : null;
                const requirementStatus = item.present ? 'approved' : 'needs action';
                return `<div class="job-v6-requirement-row ${item.present ? 'is-complete' : 'is-missing'}" data-job-doc-requirement-row data-phase="${html(phase.id)}" data-task="${html(taskForRequirement?.id || '')}" data-type="${html(normalizedStatusValue(item.categoryName))}" data-status="${html(requirementStatus)}" data-purpose-key="${html(item.key)}"><span class="job-v6-requirement-icon">${item.present ? '✓' : '!'}</span><div><b>${html(item.categoryName)}</b><small>${html(item.sourceLabel)}${item.present ? ' · Document received' : ' · Required document missing'}</small></div><em>${item.present ? 'Received' : 'Needs action'}</em>${item.present ? '' : `<button type="button" class="job-v6-upload-required" data-job-doc-upload-requirement="${html(item.key)}">Upload</button>`}</div>`;
            }).join('')}</div>` : '';
            const ungroupedDocs = phaseDocs.filter((item) => !matchedDocIds.has(String(item.id)));
            const ungroupedRows = ungroupedDocs.length ? `<div class="job-v6-task-group" data-job-doc-task-group data-task-id="general-${html(phase.id)}"><button type="button" class="job-v6-task-head" data-job-doc-task-toggle><span class="job-v6-caret">⌄</span><span class="job-v6-task-icon">▧</span><b>Phase documents</b><small>${html(phase.name)} · ${html(job.owner || 'Unassigned')}</small><span>${ungroupedDocs.length} document${ungroupedDocs.length === 1 ? '' : 's'}</span></button><div class="job-v6-task-body">${ungroupedDocs.map((documentRecord) => {
                const statusMeta = flowtrackJobDocumentViewStatus(documentRecord);
                const fileBadge = flowtrackDocumentFileBadge(documentRecord);
                const isRequired = requirements.some((item) => Number(item.phaseId) === Number(documentRecord.phaseId || phase.id) && Number(item.categoryId) === Number(documentRecord.categoryId) && !item.taskPackItemId);
                return `<div class="job-v6-doc-row" data-job-doc-row="${html(documentRecord.id)}" data-phase="${html(phase.id)}" data-task="" data-type="${html(normalizedStatusValue(documentRecord.type || ''))}" data-status="${html(normalizedStatusValue(statusMeta.status))}" data-owner="${html(normalizedStatusValue(documentRecord.uploadedBy || ''))}" data-required="${isRequired ? '1' : '0'}" data-recent="${recentIds.has(String(documentRecord.id)) ? '1' : '0'}"><div class="job-v6-doc-file"><span class="job-v6-file-icon ${html(fileBadge.toLowerCase())}">${html(fileBadge)}</span><div>${documentRecord.previewUrl ? `<a href="${html(documentRecord.previewUrl)}" target="_blank" rel="noopener">${html(documentRecord.name || 'Document')}</a>` : `<b>${html(documentRecord.name || 'Document')}</b>`}<small>${html(documentRecord.id || '')}</small></div></div><span>${html(documentRecord.type || fileBadge)}</span><span>${html(documentRecord.version || 'v1')}</span><span>${html(documentRecord.uploadedBy || job.owner || '—')}</span><span><em class="job-v6-status ${statusMeta.tone}">${html(statusMeta.status)}</em></span><span>${documentRecord.date ? html(formatDate(documentRecord.date)) : '—'}</span><span class="job-v6-row-actions"><button type="button" class="job-v6-row-more" data-doc="${html(documentRecord.id)}">•••</button>${canFlow('documents', 'delete') ? `<button type="button" class="job-v6-row-delete" data-job-doc-delete="${html(documentRecord.dbId)}" title="Delete document">×</button>` : ''}</span></div>`;
            }).join('')}</div></div>` : '';
            const emptyBody = !taskGroups && !ungroupedRows && !phaseRequirementRows ? '<div class="job-v6-phase-empty">No documents or document requirements</div>' : '';
            const requirementSummary = phaseRequirements.length ? `${phaseReceived} of ${phaseRequirements.length} required` : 'No requirements';
            return `<section class="job-v6-phase-group ${expanded ? 'is-expanded' : ''}" data-job-doc-phase-group data-phase-id="${html(phase.id)}" data-has-requirements="${phaseRequirements.length ? '1' : '0'}" data-missing-count="${phaseMissing.length}"><button type="button" class="job-v6-phase-head" data-job-doc-phase-toggle><span class="job-v6-phase-caret">${expanded ? '⌄' : '›'}</span><span class="job-v6-phase-number tone-${phasePalette[phaseIndex % phasePalette.length]}">${html(phase.sequence || phaseIndex + 1)}</span><b>${html(phase.name)}</b><small>${phaseDocs.length} document${phaseDocs.length === 1 ? '' : 's'} · <em class="${phaseRequirements.length && !phaseMissing.length ? 'is-complete' : ''}">${html(requirementSummary)}</em>${isCurrent ? ' · <strong>Current phase</strong>' : ''}</small><span class="job-v6-phase-count">${phaseDocs.length}</span></button><div class="job-v6-phase-body">${phaseRequirementRows}${taskGroups}${ungroupedRows}${emptyBody}</div></section>`;
        }).join('');

        const requiredRows = requirements.map((item) => `<div class="job-v6-required-item ${item.present ? 'is-complete' : 'is-missing'}"><span>${item.present ? '✓' : '!'}</span><div><b>${html(item.categoryName)}</b><small>${html(item.phaseName)} · ${html(item.sourceLabel)} · ${item.present ? 'Received' : 'Needs action'}</small>${item.present ? '' : '<p>Required document missing.</p>'}</div>${item.present ? '' : `<button type="button" class="job-v6-required-upload" data-job-doc-upload-requirement="${html(item.key)}">Upload</button>`}</div>`).join('') || '<div class="job-v6-side-empty">No required documents configured.</div>';

        return `<section id="detailPanel8" class="detail-panel job-v3-doc-template job-v6-doc-template">
            <div class="job-v6-doc-shell">
                <main class="job-v6-doc-main">
                    <div class="job-v6-title-row"><div><h3>Documents</h3><p>${allJobDocs.length} files · ${receivedCount} of ${requirements.length} required received</p></div><div class="job-v6-title-actions"><button type="button" class="job-v6-outline" data-job-doc-request="${html(job.id)}">Request document</button><button type="button" class="job-v6-icon-button" data-job-doc-more>•••</button></div></div>
                    <div class="job-v6-chips" data-job-doc-chips><button type="button" class="is-active" data-job-doc-chip="all">All files <span>${allJobDocs.length}</span></button><button type="button" data-job-doc-chip="required">Required <span>${requirements.length}</span></button><button type="button" class="needs" data-job-doc-chip="needs-action">Needs action <span>${missing.length + needsActionDocuments}</span></button><button type="button" class="approved" data-job-doc-chip="approved">Approved <span>${approved}</span></button><button type="button" data-job-doc-chip="recent">Recently updated <span>${recentlyUpdatedCount}</span></button></div>
                    <section class="job-v6-add-card"><div class="job-v6-card-title"><b>Add documents</b><span>Upload a new file or choose one already stored in Documents.</span></div><div class="job-v6-dropzone" data-job-doc-dropzone tabindex="0"><input type="file" data-job-doc-file-input accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip"><span class="job-v6-paperclip">⌕</span><div><b>Drop files here or <button type="button" data-job-doc-browse>browse</button></b><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Max 20 MB</small></div><button type="button" class="job-v6-outline" data-job-doc-choose>Choose from Documents</button></div><div class="job-v6-upload-progress is-hidden" data-job-doc-upload-progress><span></span><b data-job-doc-upload-name></b><em data-job-doc-upload-state>Uploading…</em></div><label class="job-v6-purpose"><span>Document purpose</span><select data-job-doc-purpose ${uploadPurposes.length ? '' : 'disabled'}><option value="">Select phase, task or requirement</option>${purposeOptions}</select><small>Choose where this document belongs before uploading.</small></label></section>
                    <section class="job-v6-list-card"><div class="job-v6-list-head"><div><b>Job documents</b><span>Grouped by phase and related task</span></div></div><div class="job-v6-filters"><label class="job-v6-search">⌕<input type="search" placeholder="Search documents or file names" data-job-doc-search></label><select data-job-doc-phase-filter><option value="">Phase</option>${activePhases.map((phase) => `<option value="${html(phase.id)}">${html(phase.name)}</option>`).join('')}</select><select data-job-doc-task-filter><option value="">Task</option>${taskOptions.map((task) => `<option value="${html(task.id)}">${html(task.title || task.id)}</option>`).join('')}</select><select data-job-doc-type-filter><option value="">Document type</option>${typeOptions.map((type) => `<option value="${html(normalizedStatusValue(type))}">${html(type)}</option>`).join('')}</select><select data-job-doc-status-filter><option value="">Status</option><option value="approved">Approved</option><option value="in review">In review</option><option value="needs action">Needs action</option></select><select data-job-doc-owner-filter><option value="">Owner</option>${ownerOptions.map((owner) => `<option value="${html(normalizedStatusValue(owner))}">${html(owner)}</option>`).join('')}</select><button type="button" class="job-v6-filter-button" data-job-doc-more-filters>☷ More filters</button><select data-job-doc-sort><option value="latest">Last updated</option><option value="latest">Newest first</option><option value="oldest">Oldest first</option></select><div class="job-v6-view-toggle"><button type="button" class="is-active" data-job-doc-view="list">☷ List</button><button type="button" data-job-doc-view="grid">▦ Grid</button></div></div><div class="job-v6-extra-filters is-hidden" data-job-doc-extra-filters><label><input type="checkbox" data-job-doc-current-phase-only> Current phase only</label><button type="button" data-job-doc-clear-filters>Clear filters</button></div><div class="job-v6-groupby"><span>Group by:</span><select data-job-doc-groupby><option value="phase-task">Phase → Task</option><option value="phase">Phase only</option></select></div><div class="job-v6-doc-columns"><span>Document</span><span>Type</span><span>Version</span><span>Owner</span><span>Status</span><span>Updated</span><span>Actions</span></div><div class="job-v6-phase-list" data-job-doc-phase-list>${phaseGroups || '<div class="job-v6-side-empty">No workflow phases configured.</div>'}</div><div class="job-v6-filter-empty is-hidden" data-job-doc-filter-empty>No documents match these filters.</div></section>
                </main>
                <aside class="job-v6-doc-side"><section class="job-v6-side-card"><div class="job-v6-required-head"><b>Required documents</b><span>${receivedCount} of ${requirements.length} received</span></div><div class="job-v6-required-progress"><span style="width:${completionPercent}%"></span></div><div class="job-v6-required-percent">${completionPercent}%</div><div class="job-v6-required-list">${requiredRows}</div><button type="button" class="job-v6-side-link" data-job-doc-view-requirements>View all requirements</button></section><section class="job-v6-side-card job-v6-health"><b>Document health</b><dl><dt>Approved</dt><dd class="green">${approved}</dd><dt>In review</dt><dd class="blue">${inReview}</dd><dt>Draft / needs action</dt><dd class="orange">${missing.length + needsActionDocuments}</dd></dl><div class="job-v6-health-divider"></div><dl><dt>Latest update</dt><dd>${latestDocument?.date ? html(formatDate(latestDocument.date)) : '—'}</dd></dl><button type="button" class="job-v6-side-link" data-job-doc-activity>View document activity</button></section></aside>
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
        const panel = content.querySelector('.job-v6-doc-template');
        if (!panel) return;
        const purposes = flowtrackJobDocumentPurposeOptions(job, flowtrackActivePhases(job));
        const purposeSelect = panel.querySelector('[data-job-doc-purpose]');
        const currentPurpose = () => purposes.find((item) => String(item.key) === String(purposeSelect?.value || '')) || null;
        const refresh = (updatedJob = job) => flowtrackJobDocumentRefreshView(updatedJob);

        const uploadExistingResult = (result, successMessage) => {
            if (result?.document) {
                const documentIndex = docs.findIndex((item) => Number(item.dbId) === Number(result.document.dbId));
                if (documentIndex >= 0) docs.splice(documentIndex, 1, result.document); else docs.unshift(result.document);
            }
            if (result?.job) flowtrackUpsertRecord(jobs, result.job);
            if (result?.job && Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
            const updated = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
            refresh(updated);
            updateBadges();
            showToast(result?.message || successMessage);
        };

        const uploadInline = async (file) => {
            if (!file) return;
            if (!(canFlow('documents', 'create') && canFlow('documents', 'link'))) return showToast('Your role does not have Document Upload & Link access.');
            const purpose = currentPurpose();
            if (!purpose) {
                showToast('Choose a document purpose before uploading.');
                purposeSelect?.focus();
                return;
            }
            if (Number(file.size || 0) > 20 * 1024 * 1024) return showToast('The document must be 20 MB or smaller.');
            const progress = panel.querySelector('[data-job-doc-upload-progress]');
            const progressBar = progress?.querySelector('span');
            const progressName = panel.querySelector('[data-job-doc-upload-name]');
            const progressState = panel.querySelector('[data-job-doc-upload-state]');
            progress?.classList.remove('is-hidden');
            if (progressName) progressName.textContent = file.name;
            if (progressState) progressState.textContent = 'Uploading…';
            if (progressBar) progressBar.style.width = '35%';
            const formData = new FormData();
            formData.append('job_id', String(job.dbId));
            formData.append('workflow_phase_id', String(purpose.phaseId));
            if (purpose.taskPackItemId) formData.append('task_pack_item_id', String(purpose.taskPackItemId));
            formData.append('document_category_id', String(purpose.categoryId));
            formData.append('file', file);
            try {
                const result = await api('/flowtrack/template-api/documents', { method: 'POST', body: formData });
                if (progressBar) progressBar.style.width = '100%';
                if (progressState) progressState.textContent = 'Uploaded';
                window.setTimeout(() => uploadExistingResult(result, 'Document uploaded and linked.'), 160);
            } catch (error) {
                if (progressState) progressState.textContent = 'Upload failed';
                if (progressBar) progressBar.style.width = '0%';
                showToast(error.message || 'Document could not be uploaded.');
            }
        };

        const fileInput = panel.querySelector('[data-job-doc-file-input]');
        const dropzone = panel.querySelector('[data-job-doc-dropzone]');
        panel.querySelectorAll('[data-job-doc-upload-requirement]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (!(canFlow('documents', 'create') && canFlow('documents', 'link'))) return showToast('Your role does not have Document Upload & Link access.');
            const purposeKey = String(button.dataset.jobDocUploadRequirement || '');
            const purpose = purposes.find((item) => String(item.key) === purposeKey);
            if (!purpose || !purposeSelect) return showToast('This document requirement is no longer available. Refresh the Job and try again.');
            purposeSelect.value = purposeKey;
            purposeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            panel.querySelector('.job-v6-add-card')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (fileInput) fileInput.value = '';
            fileInput?.click();
        }));
        panel.querySelector('[data-job-doc-browse]')?.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); fileInput?.click(); });
        fileInput?.addEventListener('change', () => { const file = fileInput.files?.[0]; if (file) void uploadInline(file); });
        dropzone?.addEventListener('dragover', (event) => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
        dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
        dropzone?.addEventListener('drop', (event) => { event.preventDefault(); dropzone.classList.remove('is-dragging'); const file = event.dataTransfer?.files?.[0]; if (file) void uploadInline(file); });
        dropzone?.addEventListener('keydown', (event) => { if (['Enter', ' '].includes(event.key) && !event.target.closest('button,select,input')) { event.preventDefault(); fileInput?.click(); } });

        const openExistingChooser = () => {
            if (!(canFlow('documents', 'create') && canFlow('documents', 'link'))) return showToast('Your role does not have Document Upload & Link access.');
            const purpose = currentPurpose();
            if (!purpose) return showToast('Choose a document purpose first.');
            const available = (docs || []).filter((item) => Number(item.dbId) && String(item.job || '') !== String(job.id));
            if (!available.length) return showToast('There are no other stored documents available to link.');
            $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Choose from Documents</h2><div class="small muted">Link an existing stored file to ${html(job.id)} · ${html(purpose.phaseName)} · ${html(purpose.categoryName)}</div></div><button class="close-btn" data-doc-chooser-close>×</button></div><div class="modal-body"><label class="field full"><span>Search documents</span><input type="search" placeholder="Search by file, type or Job" data-existing-doc-search></label><div class="job-v6-existing-list" data-existing-doc-list>${available.map((item) => `<div class="job-v6-existing-row" data-existing-doc-row data-search="${html(normalizedStatusValue(`${item.name} ${item.type} ${item.job} ${item.uploadedBy}`))}"><span class="job-v6-file-icon ${html(flowtrackDocumentFileBadge(item).toLowerCase())}">${html(flowtrackDocumentFileBadge(item))}</span><div><b>${html(item.name || 'Document')}</b><small>${html(item.job || '')} · ${html(item.type || 'Document')} · ${html(item.uploadedBy || 'System')}</small></div><button type="button" class="primary" data-existing-doc-link="${html(item.dbId)}">Link</button></div>`).join('')}</div></div><div class="modal-foot"><button class="ghost" data-doc-chooser-close>Cancel</button></div>`;
            openOverlay(); $('#jobModal').classList.add('open');
            $$('[data-doc-chooser-close]').forEach((button) => button.addEventListener('click', flowtrackCloseModalOnly));
            $('[data-existing-doc-search]')?.addEventListener('input', (event) => { const term = normalizedStatusValue(event.target.value); $$('[data-existing-doc-row]').forEach((row) => row.classList.toggle('is-hidden', Boolean(term) && !String(row.dataset.search || '').includes(term))); });
            $$('[data-existing-doc-link]').forEach((button) => button.addEventListener('click', async () => {
                button.disabled = true; button.textContent = 'Linking…';
                try {
                    const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/documents/link-existing`, { method: 'POST', body: { source_document_id: Number(button.dataset.existingDocLink), workflow_phase_id: purpose.phaseId, task_pack_item_id: purpose.taskPackItemId, document_category_id: purpose.categoryId } });
                    flowtrackCloseModalOnly(); uploadExistingResult(result, 'Stored document linked.');
                } catch (error) { button.disabled = false; button.textContent = 'Link'; showToast(error.message || 'Stored document could not be linked.'); }
            }));
        };
        panel.querySelector('[data-job-doc-choose]')?.addEventListener('click', openExistingChooser);

        const openRequestDialog = () => {
            const requestable = purposes.filter((item) => !item.present).length ? purposes.filter((item) => !item.present) : purposes;
            if (!requestable.length) return showToast('No document requirements are configured for this Job.');
            $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Request document</h2><div class="small muted">Record a document request against this Job.</div></div><button class="close-btn" data-doc-request-close>×</button></div><div class="modal-body"><div class="field full"><label>Document *</label><select data-doc-request-purpose>${requestable.map((item) => `<option value="${html(item.key)}">${html(item.categoryName)} · ${html(item.phaseName)} · ${html(item.sourceLabel)}</option>`).join('')}</select></div><div class="field full"><label>Request note</label><textarea rows="3" maxlength="2000" data-doc-request-note placeholder="Add instructions or context…"></textarea></div></div><div class="modal-foot"><button class="ghost" data-doc-request-close>Cancel</button><button class="primary" data-doc-request-submit>Send request</button></div>`;
            openOverlay(); $('#jobModal').classList.add('open');
            $$('[data-doc-request-close]').forEach((button) => button.addEventListener('click', flowtrackCloseModalOnly));
            $('[data-doc-request-submit]')?.addEventListener('click', async () => {
                const chosen = requestable.find((item) => String(item.key) === String($('[data-doc-request-purpose]')?.value || '')) || requestable[0];
                const note = $('[data-doc-request-note]')?.value.trim();
                const comment = `Document request: ${chosen.categoryName} · ${chosen.phaseName}${chosen.sourceLabel ? ` · ${chosen.sourceLabel}` : ''}${note ? `. ${note}` : '.'}`;
                const button = $('[data-doc-request-submit]'); button.disabled = true; button.textContent = 'Sending…';
                try {
                    const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/comments`, { method: 'POST', body: { comment } });
                    if (result.job) flowtrackUpsertRecord(jobs, result.job);
                    flowtrackCloseModalOnly(); showToast('Document request recorded in Job activity.');
                } catch (error) { button.disabled = false; button.textContent = 'Send request'; showToast(error.message || 'Document request could not be recorded.'); }
            });
        };
        panel.querySelectorAll('[data-job-doc-request]').forEach((button) => button.addEventListener('click', openRequestDialog));
        panel.querySelector('[data-job-doc-more]')?.addEventListener('click', () => showToast('Use the document actions in the list for file-specific options.'));

        panel.querySelectorAll('[data-job-doc-delete]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault(); event.stopPropagation();
            const documentRecord = docs.find((item) => Number(item.dbId) === Number(button.dataset.jobDocDelete));
            if (!documentRecord) return showToast('The selected document could not be found.');
            flowtrackConfirmDeleteDocument(documentRecord, (result) => {
                const updated = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
                refresh(updated);
            });
        }));
        panel.querySelectorAll('[data-job-doc-phase-toggle]').forEach((button) => button.addEventListener('click', () => { const group = button.closest('[data-job-doc-phase-group]'); group?.classList.toggle('is-expanded'); const caret = button.querySelector('.job-v6-phase-caret'); if (caret) caret.textContent = group?.classList.contains('is-expanded') ? '⌄' : '›'; }));
        panel.querySelectorAll('[data-job-doc-task-toggle]').forEach((button) => button.addEventListener('click', () => { const group = button.closest('[data-job-doc-task-group]'); group?.classList.toggle('is-collapsed'); const caret = button.querySelector('.job-v6-caret'); if (caret) caret.textContent = group?.classList.contains('is-collapsed') ? '›' : '⌄'; }));

        let chip = 'all';
        const applyFilters = () => {
            const term = normalizedStatusValue(panel.querySelector('[data-job-doc-search]')?.value || '');
            const phase = String(panel.querySelector('[data-job-doc-phase-filter]')?.value || '');
            const task = String(panel.querySelector('[data-job-doc-task-filter]')?.value || '');
            const type = String(panel.querySelector('[data-job-doc-type-filter]')?.value || '');
            const status = String(panel.querySelector('[data-job-doc-status-filter]')?.value || '');
            const owner = String(panel.querySelector('[data-job-doc-owner-filter]')?.value || '');
            const currentOnly = Boolean(panel.querySelector('[data-job-doc-current-phase-only]')?.checked);
            let visibleRows = 0;
            panel.querySelectorAll('[data-job-doc-phase-group]').forEach((phaseGroup) => {
                const phaseMatchesBase = (!phase || String(phaseGroup.dataset.phaseId) === phase) && (!currentOnly || Number(phaseGroup.dataset.phaseId) === Number(job.phaseId));
                let phaseVisibleRows = 0;
                let visibleRequirementRows = 0;
                phaseGroup.querySelectorAll('[data-job-doc-row]').forEach((row) => {
                    let matches = phaseMatchesBase && (!term || normalizedStatusValue(row.textContent).includes(term)) && (!task || String(row.dataset.task || '') === task) && (!type || row.dataset.type === type) && (!status || row.dataset.status === status) && (!owner || row.dataset.owner === owner);
                    if (chip === 'required') matches = matches && row.dataset.required === '1';
                    if (chip === 'approved') matches = matches && row.dataset.status === 'approved';
                    if (chip === 'recent') matches = matches && row.dataset.recent === '1';
                    if (chip === 'needs-action') matches = false;
                    row.classList.toggle('is-filtered-out', !matches);
                    if (matches) { phaseVisibleRows++; visibleRows++; }
                });
                phaseGroup.querySelectorAll('[data-job-doc-requirement-row]').forEach((row) => {
                    let matches = phaseMatchesBase
                        && (!term || normalizedStatusValue(row.textContent).includes(term))
                        && (!task || String(row.dataset.task || '') === task)
                        && (!type || row.dataset.type === type)
                        && (!status || row.dataset.status === status)
                        && !owner;
                    if (chip === 'needs-action') matches = matches && row.dataset.status === 'needs action';
                    if (chip === 'approved') matches = matches && row.dataset.status === 'approved';
                    if (chip === 'recent') matches = false;
                    row.classList.toggle('is-filtered-out', !matches);
                    if (matches) visibleRequirementRows++;
                });
                phaseGroup.querySelectorAll('[data-job-doc-task-group]').forEach((taskGroup) => {
                    const taskRows = [...taskGroup.querySelectorAll('[data-job-doc-row]')];
                    const visibleTaskRows = taskRows.filter((row) => !row.classList.contains('is-filtered-out')).length;
                    taskGroup.classList.toggle('is-filtered-out', taskRows.length ? visibleTaskRows === 0 : true);
                });
                const showEmptyInitial = chip === 'all' && !term && !phase && !task && !type && !status && !owner && !currentOnly;
                const showPhase = phaseMatchesBase && (phaseVisibleRows > 0 || visibleRequirementRows > 0 || showEmptyInitial);
                phaseGroup.classList.toggle('is-filtered-out', !showPhase);
                if ((chip === 'required' || chip === 'needs-action') && visibleRequirementRows > 0) phaseGroup.classList.add('is-expanded');
            });
            const anyPhase = [...panel.querySelectorAll('[data-job-doc-phase-group]')].some((group) => !group.classList.contains('is-filtered-out'));
            panel.querySelector('[data-job-doc-filter-empty]')?.classList.toggle('is-hidden', anyPhase);
        };
        ['[data-job-doc-search]','[data-job-doc-phase-filter]','[data-job-doc-task-filter]','[data-job-doc-type-filter]','[data-job-doc-status-filter]','[data-job-doc-owner-filter]','[data-job-doc-current-phase-only]'].forEach((selector) => {
            const input = panel.querySelector(selector); if (!input) return; input.addEventListener(input.tagName === 'INPUT' && input.type === 'search' ? 'input' : 'change', applyFilters);
        });
        panel.querySelectorAll('[data-job-doc-chip]').forEach((button) => button.addEventListener('click', () => { chip = button.dataset.jobDocChip || 'all'; panel.querySelectorAll('[data-job-doc-chip]').forEach((other) => other.classList.toggle('is-active', other === button)); applyFilters(); }));
        panel.querySelector('[data-job-doc-more-filters]')?.addEventListener('click', () => panel.querySelector('[data-job-doc-extra-filters]')?.classList.toggle('is-hidden'));
        panel.querySelector('[data-job-doc-clear-filters]')?.addEventListener('click', () => { panel.querySelectorAll('[data-job-doc-search],[data-job-doc-phase-filter],[data-job-doc-task-filter],[data-job-doc-type-filter],[data-job-doc-status-filter],[data-job-doc-owner-filter]').forEach((input) => { input.value = ''; }); const currentOnly = panel.querySelector('[data-job-doc-current-phase-only]'); if (currentOnly) currentOnly.checked = false; chip = 'all'; panel.querySelectorAll('[data-job-doc-chip]').forEach((button) => button.classList.toggle('is-active', button.dataset.jobDocChip === 'all')); applyFilters(); });
        panel.querySelector('[data-job-doc-sort]')?.addEventListener('change', (event) => { const oldest = event.target.value === 'oldest'; panel.querySelectorAll('[data-job-doc-task-group]').forEach((group) => { const body = group.querySelector('.job-v6-task-body'); if (!body) return; const rows = [...body.querySelectorAll('[data-job-doc-row]')]; rows.sort((a,b) => { const da = docs.find((item) => String(item.id) === String(a.dataset.jobDocRow))?.date || ''; const db = docs.find((item) => String(item.id) === String(b.dataset.jobDocRow))?.date || ''; return oldest ? String(da).localeCompare(String(db)) : String(db).localeCompare(String(da)); }).forEach((row) => body.appendChild(row)); }); });
        panel.querySelectorAll('[data-job-doc-view]').forEach((button) => button.addEventListener('click', () => { const view = button.dataset.jobDocView; panel.classList.toggle('is-grid-view', view === 'grid'); panel.querySelectorAll('[data-job-doc-view]').forEach((other) => other.classList.toggle('is-active', other === button)); }));
        panel.querySelector('[data-job-doc-groupby]')?.addEventListener('change', (event) => panel.classList.toggle('is-phase-only', event.target.value === 'phase'));
        panel.querySelector('[data-job-doc-view-requirements]')?.addEventListener('click', () => { const button = panel.querySelector('[data-job-doc-chip="required"]'); button?.click(); panel.querySelector('.job-v6-list-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        panel.querySelector('[data-job-doc-activity]')?.addEventListener('click', () => { const activityTab = content.querySelector('[data-detailtab="9"]'); if (activityTab) activityTab.click(); else showToast('Document uploads and requests are recorded in Job activity.'); });
        applyFilters();
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

    
    /* CSS externalized: flowtrackJobDetailReferenceStyles. */

    
    /* CSS externalized: flowtrackJobDetailCompactFinalStyles. */

    
    /* CSS externalized: flowtrackTaskDocumentAndPhaseHistoryStyles. */



    
    /* CSS externalized: flowtrackLatestJobsAndBoardExpandStyles. */


    
    /* CSS externalized: flowtrackAttachmentTemplateStyles. */


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

    async function flowtrackLoadNextSegment(segment, options = {}) {
        const pagination = segmentPagination[segment];
        const nextUrl = pagination?.nextPageUrl;
        if (!nextUrl || segmentLoading.has(segment)) return false;
        segmentLoading.add(segment);
        try {
            const page = await api(nextUrl, { cache: 'no-store' });
            const target = flowtrackSegmentCollection(segment);
            flowtrackToArray(page?.data).forEach((record) => flowtrackUpsertRecord(target, record, false));
            flowtrackToArray(page?.relatedJobs).forEach((job) => flowtrackUpsertRecord(jobs, job, false));
            flowtrackToArray(page?.relatedClients).forEach((client) => flowtrackUpsertRecord(clientLabels, client, false));
            segmentPagination[segment] = page?.pagination || null;
            if (options.render !== false) {
                renderPage(currentPage);
                applyActionPermissions();
            }
            return true;
        } catch (error) {
            showToast(error.message || 'More records could not be loaded.');
            return false;
        } finally {
            segmentLoading.delete(segment);
        }
    }

    function flowtrackPaginationSegmentsForPage(page) {
        if (page === 'clients') return ['clients'];
        if (page === 'documents') return ['documents'];
        if (page === 'mywork') return ['tasks'];
        if (page === 'board') return ['jobs', 'tasks'];
        return [];
    }

    let flowtrackAutoLoadScheduled = false;
    let flowtrackAutoLoadRunning = false;
    const flowtrackAutoScrollTargets = new WeakSet();

    function flowtrackPageHasNextSegments(page) {
        return flowtrackPaginationSegmentsForPage(page)
            .filter((segment) => Boolean(segmentPagination[segment]?.nextPageUrl));
    }

    function flowtrackIsNearVerticalEnd(target, threshold = 280) {
        if (target && target !== window && target !== document) {
            return target.scrollHeight - target.scrollTop - target.clientHeight <= threshold;
        }
        const doc = document.documentElement;
        const scrollTop = window.scrollY || doc.scrollTop || document.body.scrollTop || 0;
        const viewport = window.innerHeight || doc.clientHeight || 0;
        const scrollHeight = Math.max(doc.scrollHeight || 0, document.body.scrollHeight || 0);
        return scrollHeight - scrollTop - viewport <= Math.max(threshold, 520);
    }

    function flowtrackBindAutoPaginationScrollers(page) {
        if (page !== 'board' && page !== 'mywork') return;
        if (!flowtrackAutoScrollTargets.has(window)) {
            flowtrackAutoScrollTargets.add(window);
            window.addEventListener('scroll', flowtrackScheduleAutoPaginationCheck, { passive: true });
            window.addEventListener('resize', flowtrackScheduleAutoPaginationCheck, { passive: true });
        }
        document.querySelectorAll(`#page-${page} .ops-board-canvas`).forEach((canvas) => {
            if (flowtrackAutoScrollTargets.has(canvas)) return;
            flowtrackAutoScrollTargets.add(canvas);
            canvas.addEventListener('scroll', flowtrackScheduleAutoPaginationCheck, { passive: true });
        });
    }

    function flowtrackScheduleAutoPaginationCheck() {
        if (flowtrackAutoLoadScheduled) return;
        flowtrackAutoLoadScheduled = true;
        requestAnimationFrame(() => {
            flowtrackAutoLoadScheduled = false;
            void flowtrackMaybeAutoLoadCurrentPageSegments();
        });
    }

    function flowtrackAutoLoadStatusText(segments) {
        const labels = segments.map((segment) => segment === 'jobs' ? 'jobs' : segment === 'tasks' ? 'tasks' : segment);
        if (labels.length <= 1) return labels[0] || 'records';
        return `${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]}`;
    }

    async function flowtrackMaybeAutoLoadCurrentPageSegments() {
        if (flowtrackAutoLoadRunning || (currentPage !== 'board' && currentPage !== 'mywork')) return;
        const page = currentPage;
        const segments = flowtrackPageHasNextSegments(page);
        if (!segments.length) return;
        const boardCanvas = document.querySelector(`#page-${page}.active .ops-board-canvas`);
        const nearPageEnd = flowtrackIsNearVerticalEnd(window);
        const nearCanvasEnd = boardCanvas ? flowtrackIsNearVerticalEnd(boardCanvas) : false;
        if (!nearPageEnd && !nearCanvasEnd) return;

        flowtrackAutoLoadRunning = true;
        const status = document.querySelector(`#page-${page} .flowtrack-auto-load span`);
        if (status) status.textContent = `Loading more ${flowtrackAutoLoadStatusText(segments)}…`;
        try {
            for (const segment of segments) {
                if (segmentPagination[segment]?.nextPageUrl) {
                    await flowtrackLoadNextSegment(segment);
                }
            }
        } finally {
            flowtrackAutoLoadRunning = false;
            setTimeout(flowtrackScheduleAutoPaginationCheck, 120);
        }
    }

    function flowtrackRenderPaginationControls(page) {
        const section = document.getElementById(`page-${page}`);
        if (!section) return;
        section.querySelectorAll('.flowtrack-load-more').forEach((node) => node.remove());
        const segments = flowtrackPageHasNextSegments(page);
        if (!segments.length) return;
        const controls = document.createElement('div');
        controls.className = page === 'board' ? 'flowtrack-load-more flowtrack-auto-load' : 'flowtrack-load-more';
        if (page === 'board') {
            controls.innerHTML = `<span>Scroll vertically to load more ${flowtrackAutoLoadStatusText(segments)}.</span>`;
            section.appendChild(controls);
            flowtrackBindAutoPaginationScrollers(page);
            flowtrackScheduleAutoPaginationCheck();
            return;
        }
        if (page === 'mywork') {
            flowtrackBindAutoPaginationScrollers(page);
            flowtrackScheduleAutoPaginationCheck();
            return;
        }
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

    
    /* CSS externalized: flowtrackPaginationStyles. */

    if (window.FLOWTRACK_AUTHENTICATED) {
        loadBootstrap()
            .then(() => showApplication())
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

    
    /* CSS externalized: flowtrackDocumentTemplateEverywhereStyles. */

    
    /* CSS externalized: flowtrackJobDocumentReferenceExactStyles. */


    
    /* CSS externalized: flowtrackJobDocumentsFinalPrototypeStyles. */


    
    /* CSS externalized: flowtrackJobDocumentsReadableScaleStyles. */

    
    /* CSS externalized: flowtrackJobDocumentRequirementsAllPhasesStyles. */

    function flowtrackTaskTemplateSlug(value) {
        return String(value ?? 'task').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
    }

    function flowtrackTaskTemplateCurrent(identifier) {
        return tasks.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier)) || null;
    }

    function flowtrackTaskTemplateJob(task) {
        const loadedJob = jobs.find((item) => String(item.id) === String(task?.job) || Number(item.dbId) === Number(task?.jobDbId)) || null;
        if (task?.jobContext && typeof task.jobContext === 'object') {
            return { ...(loadedJob || {}), ...task.jobContext };
        }
        return loadedJob;
    }

    function flowtrackTaskTemplateDate(value, fallback = 'Not set') {
        if (!value) return fallback;
        const source = String(value).slice(0, 10);
        const date = new Date(`${source}T00:00:00`);
        if (Number.isNaN(date.getTime())) return fallback;
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function flowtrackTaskTemplateDateTime(value, fallback = '') {
        if (!value) return fallback;
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return fallback;
        return date.toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
        });
    }

    function flowtrackTaskTemplateRelative(value, fallback = '') {
        if (!value) return fallback;
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return fallback;
        const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
        if (seconds < 60) return 'just now';
        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
        const hours = Math.round(minutes / 60);
        if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
        const days = Math.round(hours / 24);
        if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`;
        return flowtrackTaskTemplateDate(String(value).slice(0, 10), fallback);
    }

    function flowtrackTaskTemplateStatusTone(status) {
        const value = normalizedStatusValue(status?.code || status?.name || status);
        if (['CMP', 'COMPLETED', 'COMPLETE', 'DONE', 'APPROVED'].includes(value)) return 'green';
        if (['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD', 'REV', 'REVISION REQUIRED'].includes(value)) return 'red';
        if (['WC', 'WAITING FOR CLIENT', 'WS', 'WAITING FOR SUPPLIER', 'WIA', 'WAITING FOR INTERNAL APPROVAL', 'WAITING', 'PENDING'].includes(value)) return 'orange';
        if (['IP', 'IN PROGRESS'].includes(value)) return 'indigo';
        if (['READY'].includes(value)) return 'blue';
        return 'gray';
    }

    function flowtrackTaskTemplatePriorityTone(priority) {
        const value = String(priority || '').trim().toLowerCase();
        if (value.includes('critical')) return 'red';
        if (value.includes('high') || value.includes('urgent')) return 'orange';
        if (value.includes('medium') || value.includes('normal')) return 'amber';
        if (value.includes('low')) return 'green';
        return 'gray';
    }

    function flowtrackTaskTemplateFileSize(bytes) {
        const size = Number(bytes || 0);
        if (!size) return 'Unknown size';
        if (size < 1024) return `${size} B`;
        if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`;
        return `${(size / (1024 * 1024)).toFixed(size >= 10 * 1024 * 1024 ? 0 : 1)} MB`;
    }

    function flowtrackTaskTemplateSvg(name) {
        const icons = {
            calendar: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/></svg>',
            paperclip: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8.5 12.5 6.8-6.8a3.2 3.2 0 0 1 4.5 4.5l-8.7 8.7a5 5 0 0 1-7.1-7.1l8.2-8.2M7.2 14.8l8.1-8.1"/></svg>',
            file: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6zM14 3v5h5"/></svg>',
            flag: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21V4m0 1h10l-1.5 3L15 11H5"/></svg>',
            pencil: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4.2-1 10.4-10.4a2 2 0 0 0-2.8-2.8L5.4 16.2 4 20Zm10.6-12.6 2.8 2.8"/></svg>',
        };
        return icons[name] || '';
    }

    function flowtrackTaskTemplateDocumentVersions(task) {
        return flowtrackToArray(task?.documents).flatMap((documentRecord) => {
            const versions = flowtrackToArray(documentRecord?.versions);
            if (!versions.length) {
                return [{
                    document: documentRecord,
                    version: {
                        originalName: documentRecord?.name || 'Attachment',
                        uploadedBy: documentRecord?.uploadedBy || 'System',
                        uploadedAt: documentRecord?.uploadedAt || '',
                        size: 0,
                        previewUrl: documentRecord?.previewUrl || '',
                        downloadUrl: documentRecord?.downloadUrl || '',
                        version: documentRecord?.versionCount || 1,
                    },
                }];
            }
            return versions.map((version) => ({ document: documentRecord, version }));
        });
    }

    function flowtrackTaskTemplateMetaCard({ key, label, value, icon = '', tone = '', editable = false, editor = '', extraClass = '' }) {
        return `<div class="ft-task-meta-card ${editable ? 'is-editable' : ''} ${extraClass}" data-ft-task-inline="${html(key)}">
            <span class="ft-task-meta-label">${html(label)}</span>
            <div class="ft-task-meta-value">
                ${icon ? `<span class="ft-task-meta-icon ${tone ? `is-${tone}` : ''}" aria-hidden="true">${icon}</span>` : ''}
                <span class="ft-task-meta-text">${value}</span>
                ${editable ? `<button type="button" class="ft-task-pencil" data-ft-task-edit-field aria-label="Edit">${flowtrackTaskTemplateSvg('pencil')}</button>` : ''}
            </div>
            ${editable ? `<div class="ft-task-inline-editor" data-ft-task-editor>${editor}</div>` : ''}
        </div>`;
    }

    function flowtrackTaskTemplateMarkup(task, activeTab = 'all') {
        const job = flowtrackTaskTemplateJob(task);
        const completedLocked = taskIsCompleted(task) && !(isFlowAdmin || isFlowSuperAdmin);
        const canUpdate = !completedLocked && Boolean(task.canUpdate || isFlowAdmin || isFlowSuperAdmin);
        const canAssign = canUpdate && Boolean(task.canAssign || isFlowAdmin || isFlowSuperAdmin);
        const canChangeDue = boardCanChangeTaskDueDate(task);
        const canDelete = Boolean(task.canDelete || isFlowAdmin || isFlowSuperAdmin);
        const canManageChecklist = Boolean(task.canManageChecklist || isFlowAdmin || isFlowSuperAdmin);
        const status = taskStatusRecord(task.statusId || task.statusCode || task.status) || { id: task.statusId, name: task.status || 'Not Started', code: task.statusCode };
        const statuses = selectableTaskStatuses(status);
        const assigneeId = flowtrackResolveTaskAssigneeId(task);
        const assigneeName = flowtrackResolveTaskAssigneeName(task, assigneeId);
        const mentionableUsers = flowtrackToArray(task.mentionableUsers);
        const assigneeOptions = flowtrackTaskAssigneeOptions(task, mentionableUsers);
        const priorities = flowtrackToArray(masterData.priorities).filter((item) => String(item?.status || 'Active').toLowerCase() === 'active' || Number(item?.id) === Number(task.priorityId));
        const priorityRecord = priorities.find((item) => Number(item.id) === Number(task.priorityId))
            || priorities.find((item) => String(item.name || '').toLowerCase() === String(task.priority || '').toLowerCase());
        const priorityName = priorityRecord?.name || task.priority || 'Medium';
        const documentVersions = flowtrackTaskTemplateDocumentVersions(task);
        const requirement = task.documentRequirement || null;
        const hasRequiredEvidence = Boolean(documentVersions.length);
        const canUpload = Boolean(task.canUploadDocument && requirement);
        const checklist = flowtrackToArray(task.checklist);
        const checklistDone = checklist.filter((item) => item.done).length;
        const comments = flowtrackToArray(task.comments);
        const history = flowtrackToArray(task.history);
        const dependency = task.dependency || null;
        const activeBlocker = dependency && !dependency.completed ? dependency : null;
        const dependentTasks = flowtrackToArray(task.dependents);
        const health = job?.health || (task.attention ? 'Needs Attention' : 'On Track');
        const healthTone = /blocked|risk|delayed|attention/i.test(String(health)) ? 'red' : 'green';
        const taskProgress = Math.max(0, Math.min(100, Number(task.progress || taskProgressForStatus(status))));
        const jobProgress = Math.max(0, Math.min(100, Number(job?.progress || 0)));
        const createdLabel = flowtrackTaskTemplateDateTime(task.createdAt, 'Not recorded');
        const updatedLabel = flowtrackTaskTemplateRelative(task.updatedAt, 'Not recorded');
        const currentStatusTone = flowtrackTaskTemplateStatusTone(status);
        const currentPriorityTone = flowtrackTaskTemplatePriorityTone(priorityName);
        const attentionLockedByStatus = taskStatusMatches(status, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);
        const statusOptions = statuses.map((item) => {
            const tone = flowtrackTaskTemplateStatusTone(item);
            const selected = Number(item.id) === Number(status.id) || normalizedStatusValue(item.code || item.name) === normalizedStatusValue(status.code || status.name);
            return `<button type="button" class="ft-task-status-option ${selected ? 'is-selected' : ''}" data-ft-task-status-value="${html(item.id)}">
                <span class="ft-task-dot is-${tone}"></span><span>${html(item.name)}</span>${selected ? '<b>✓</b>' : ''}
            </button>`;
        }).join('');
        const assigneeEditor = `<select data-ft-task-assignee ${canAssign ? '' : 'disabled'}>
            <option value="" ${assigneeId ? '' : 'selected'}>Unassigned</option>
            ${assigneeOptions.map((user) => `<option value="${html(user.id || '')}" ${Number(user.id) === Number(assigneeId) ? 'selected' : ''}>${html(user.name || 'Unassigned')}</option>`).join('')}
        </select>`;
        const priorityEditor = `<select data-ft-task-priority ${canUpdate ? '' : 'disabled'}>
            <option value="">Use job priority</option>
            ${priorities.map((item) => `<option value="${html(item.id)}" ${Number(item.id) === Number(task.priorityId) ? 'selected' : ''}>${html(item.name)}</option>`).join('')}
        </select>`;
        const startDate = String(task.startDate || task.createdAt || '').slice(0, 10);
        const dueDate = String(task.due || '').slice(0, 10);
        const metadata = [
            flowtrackTaskTemplateMetaCard({
                key: 'assignee', label: 'Assignee', editable: canAssign,
                icon: `<span class="ft-task-avatar">${html(userInitials(assigneeName))}</span>`,
                value: html(assigneeName), editor: assigneeEditor,
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'status', label: 'Status', editable: canUpdate,
                icon: `<span class="ft-task-dot is-${currentStatusTone}"></span>`, value: html(status.name || task.status || 'Not Started'),
                editor: `<div class="ft-task-status-menu">${statusOptions}</div>`, extraClass: 'ft-task-status-card',
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'priority', label: 'Priority', editable: canUpdate,
                icon: `<span class="ft-task-dot is-${currentPriorityTone}"></span>`, value: html(priorityName), editor: priorityEditor,
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'phase', label: 'Phase', editable: false,
                value: html(task.phaseName || task.phase || 'General'),
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'start-date', label: 'Start date', editable: canUpdate,
                icon: `<span class="ft-task-calendar-icon">${flowtrackTaskTemplateSvg('calendar')}</span>`, value: html(flowtrackTaskTemplateDate(startDate)),
                editor: `<input type="date" value="${html(startDate)}" data-ft-task-start-date ${canUpdate ? '' : 'disabled'}>`,
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'due-date', label: 'Due date', editable: canChangeDue,
                icon: `<span class="ft-task-calendar-icon ${dueDate ? '' : 'is-orange'}">${flowtrackTaskTemplateSvg('calendar')}</span>`, value: `<span class="${dueClass(dueDate)}">${html(flowtrackTaskTemplateDate(dueDate))}</span>`,
                editor: `<input type="date" value="${html(dueDate)}" data-ft-task-due-date ${canChangeDue ? '' : 'disabled'}>`,
            }),
        ].join('');

        const checklistHtml = checklist.length
            ? checklist.map((item) => `<div class="ft-task-check-row">
                <label><input type="checkbox" data-ft-task-checklist="${html(item.id)}" ${item.done ? 'checked' : ''} ${canUpdate ? '' : 'disabled'}><span>${html(item.text || 'Checklist item')}</span></label>
                ${canManageChecklist ? `<button type="button" class="ft-task-row-menu" data-ft-task-delete-checklist="${html(item.id)}" aria-label="Delete checklist item" title="Delete checklist item">⋮</button>` : '<span></span>'}
            </div>`).join('')
            : '<div class="ft-task-empty-row">No checklist items are configured for this task.</div>';

        const filesHtml = documentVersions.length
            ? `<div class="ft-task-file-list">${documentVersions.map(({ document: documentRecord, version }) => {
                const fileName = version.originalName || documentRecord.name || 'Attachment';
                const extension = String(fileName).split('.').pop()?.toUpperCase().slice(0, 4) || 'FILE';
                const openUrl = version.previewUrl || version.downloadUrl || '';
                return `<div class="ft-task-file-row">
                    <span class="ft-task-file-icon">${html(extension)}</span>
                    <div><b>${html(fileName)}</b><small>${html(documentRecord.category || requirement?.categoryName || 'Attachment')} · v${html(version.version || 1)} · ${html(flowtrackTaskTemplateFileSize(version.size))} · ${html(version.uploadedBy || documentRecord.uploadedBy || 'System')}</small></div>
                    <div class="ft-task-file-actions">${openUrl ? `<a href="${html(openUrl)}" target="_blank" rel="noopener" class="ft-task-file-open">Open</a>` : ''}${documentRecord.canDelete || canFlow('documents', 'delete') ? `<button type="button" class="ft-task-file-delete" data-ft-task-delete-document="${html(documentRecord.dbId)}">Delete</button>` : ''}</div>
                </div>`;
            }).join('')}</div>`
            : '';

        const historyHasCreated = history.some((entry) => String(entry?.event || '').includes('created'));
        const commentRows = comments.map((comment) => ({
            kind: 'comment',
            occurredAt: comment.occurredAt || '',
            html: `<div class="ft-task-activity-row" data-ft-task-activity-kind="comment" data-ft-task-occurred-at="${html(comment.occurredAt || '')}">
                <span class="ft-task-activity-avatar">${html(comment.userInitials || userInitials(comment.user || 'System'))}</span>
                <div><b>${html(comment.user || 'System')}</b><p>${html(comment.text || '')}</p></div>
                <time>${html(comment.time || flowtrackTaskTemplateDateTime(comment.occurredAt, ''))}</time>
            </div>`,
        }));
        const historyRows = history.map((entry) => ({
            kind: 'history',
            historyComment: Boolean(entry.commentAdded) && flowtrackToArray(entry.changes).length === 0,
            occurredAt: entry.occurredAt || '',
            html: `<div class="ft-task-activity-row" data-ft-task-activity-kind="history" data-ft-task-history-comment="${entry.commentAdded && flowtrackToArray(entry.changes).length === 0 ? '1' : '0'}" data-ft-task-occurred-at="${html(entry.occurredAt || '')}">
                <span class="ft-task-activity-icon">${entry.isCompletionEvent ? '✓' : '↻'}</span>
                <div><b>${html(entry.actor || 'System')}</b><p>${html(entry.description || entry.eventLabel || 'Task updated')}</p>
                    ${flowtrackToArray(entry.changes).length ? `<small>${flowtrackToArray(entry.changes).map((change) => `${html(change.field || 'Field')}: ${html(change.from || '—')} → ${html(change.to || '—')}`).join(' · ')}</small>` : (entry.commentText ? `<small>${html(entry.commentText)}</small>` : '')}
                </div>
                <time>${html(entry.time || flowtrackTaskTemplateDateTime(entry.occurredAt, ''))}</time>
            </div>`,
        }));
        const syntheticHistoryRows = historyHasCreated ? [] : [{
            kind: 'history',
            historyComment: false,
            occurredAt: task.createdAt || '',
            html: `<div class="ft-task-activity-row" data-ft-task-activity-kind="history" data-ft-task-history-comment="0" data-ft-task-occurred-at="${html(task.createdAt || '')}">
                <span class="ft-task-activity-avatar">${html(task.createdByInitials || userInitials(task.createdBy || 'System'))}</span>
                <div><p><b>${html(task.createdBy || 'System')}</b> created this task</p></div>
                <time>${html(createdLabel)}</time>
            </div>`,
        }];
        const activityRows = [...historyRows, ...commentRows, ...syntheticHistoryRows]
            .sort((left, right) => {
                const leftTime = Date.parse(left.occurredAt || '') || 0;
                const rightTime = Date.parse(right.occurredAt || '') || 0;
                return rightTime - leftTime;
            });
        const activityHtml = activityRows.map((row) => row.html).join('');

        return `<div class="ft-task-final-page" data-ft-task-id="${html(task.id || task.dbId)}">
            <nav class="ft-task-breadcrumb" aria-label="Breadcrumb"><button type="button" data-standalone-back>My Work</button><span>/</span><span>${html(task.id || 'Task')}</span></nav>
            <div class="ft-task-final-layout">
                <main class="ft-task-final-main">
                    <header class="ft-task-header">
                        <div class="ft-task-heading">
                            <div class="ft-task-code">${html(task.id || '')}</div>
                            <h1 data-ft-task-title-view>${html(task.title || 'Untitled task')}</h1>
                        </div>
                        <div class="ft-task-header-actions">
                            ${canUpdate && !taskIsCompleted(task) ? '<button type="button" class="ft-task-btn ft-task-btn-primary" data-ft-task-mark-complete>Mark complete</button>' : ''}
                            ${canDelete ? '<div class="ft-task-action-wrap"><button type="button" class="ft-task-icon-btn" data-ft-task-action-menu aria-label="Task actions">•••</button><div class="ft-task-action-menu" data-ft-task-action-popover><button type="button" data-ft-task-delete>Delete task</button></div></div>' : ''}
                            <button type="button" class="ft-task-icon-btn ft-task-close" data-standalone-back aria-label="Close task">×</button>
                        </div>
                    </header>

                    ${completedLocked ? '<div class="ft-task-lock-note">This completed task is read-only. Administrators can still update it.</div>' : ''}
                    <section class="ft-task-meta-grid">${metadata}</section>

                    <section class="ft-task-section ft-task-description-section">
                        <div class="ft-task-section-head"><h2>Description</h2>${canUpdate ? `<button type="button" class="ft-task-pencil" data-ft-task-edit-description aria-label="Edit description">${flowtrackTaskTemplateSvg('pencil')}</button>` : ''}</div>
                        <div class="ft-task-description-view" data-ft-task-description-view>
                            <p>${html(task.description || 'No description has been provided for this task.')}</p>
                            ${task.blockerReason ? `<small><b>Blocker:</b> ${html(task.blockerReason)}</small>` : ''}
                        </div>
                        ${canUpdate ? `<div class="ft-task-description-editor" data-ft-task-description-editor>
                            <textarea rows="4" maxlength="5000" data-ft-task-description-input>${html(task.description || '')}</textarea>
                            <div><button type="button" class="ft-task-btn ft-task-btn-secondary" data-ft-task-description-cancel>Cancel</button><button type="button" class="ft-task-btn ft-task-btn-primary" data-ft-task-description-save>Save</button></div>
                        </div>` : ''}
                    </section>

                    <section class="ft-task-section ft-task-checklist-section">
                        <div class="ft-task-section-head ft-task-checklist-head"><div><h2>Checklist</h2><span>${checklistDone} of ${checklist.length} complete</span><span class="ft-task-mini-progress"><i style="width:${checklist.length ? Math.round(checklistDone / checklist.length * 100) : 0}%"></i></span></div>${canManageChecklist ? '<button type="button" class="ft-task-link-btn" data-ft-task-add-checklist>＋ Add item</button>' : ''}</div>
                        <div class="ft-task-checklist-list">${checklistHtml}</div>
                    </section>

                    <section class="ft-task-section ft-task-attachments-section" data-ft-task-attachment-section>
                        <div class="ft-task-section-head"><div><h2>Attachments</h2><span class="ft-task-count">${documentVersions.length}</span></div></div>
                        <div class="ft-task-dropzone ${canUpload ? '' : 'is-disabled'}" data-ft-task-dropzone tabindex="${canUpload ? '0' : '-1'}">
                            <input type="file" data-ft-task-file-input accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip" ${canUpload ? '' : 'disabled'}>
                            <span class="ft-task-paperclip">${flowtrackTaskTemplateSvg('paperclip')}</span>
                            <div><b>Drop files here or <button type="button" data-ft-task-browse ${canUpload ? '' : 'disabled'}>browse</button></b><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Max 20 MB${requirement?.categoryName ? ` · ${html(requirement.categoryName)}` : ''}</small></div>
                            <button type="button" class="ft-task-btn ft-task-btn-secondary" data-ft-task-choose-documents>Choose from Documents</button>
                        </div>
                        ${!canUpload && !hasRequiredEvidence ? '<div class="ft-task-upload-note">File upload is available only for document tasks in the job’s current phase and for users with document upload access.</div>' : ''}
                        ${filesHtml}
                    </section>

                    <section class="ft-task-section ft-task-activity-section">
                        <div class="ft-task-activity-tabs">
                            <h2>Activity</h2>
                            <button type="button" class="${activeTab === 'all' ? 'is-active' : ''}" data-ft-task-activity-tab="all">All</button>
                            <button type="button" class="${activeTab === 'comments' ? 'is-active' : ''}" data-ft-task-activity-tab="comments">Comments</button>
                            <button type="button" class="${activeTab === 'history' ? 'is-active' : ''}" data-ft-task-activity-tab="history">History</button>
                        </div>
                        <div class="ft-task-comment-compose">
                            <span class="ft-task-activity-avatar">${html(currentUser?.initials || userInitials(currentUser?.name || 'User'))}</span>
                            <div class="ft-task-comment-input-wrap"><input type="text" data-ft-task-comment-input placeholder="Add an update or comment..." ${canUpdate ? '' : 'disabled'}><div class="mention-menu hidden" data-ft-task-mention-menu></div></div>
                            <button type="button" class="ft-task-comment-btn" data-ft-task-comment-submit ${canUpdate ? '' : 'disabled'}>Comment</button>
                        </div>
                        <div class="ft-task-activity-list" data-ft-task-activity-list>
                            ${activityHtml}
                            <div class="ft-task-empty-activity" data-ft-task-activity-empty hidden>No activity yet</div>
                        </div>
                    </section>
                </main>

                <aside class="ft-task-final-side">
                    <section class="ft-task-side-card">
                        <h2>Management attention</h2>
                        <div class="ft-task-side-line"><span>Required evidence</span><div class="ft-task-side-value"><i class="ft-task-evidence-icon ${hasRequiredEvidence ? 'is-complete' : 'is-missing'}">${flowtrackTaskTemplateSvg('file')}</i><b>${html(requirement?.categoryName || (hasRequiredEvidence ? 'Attachment available' : 'No required evidence'))}${requirement && !hasRequiredEvidence ? ' missing' : ''}</b></div>${canUpload && !hasRequiredEvidence ? '<button type="button" data-ft-task-upload-link>Upload</button>' : '<span></span>'}</div>
                        <div class="ft-task-side-line"><span>Attention</span><div class="ft-task-side-value"><i class="ft-task-flag-icon">${flowtrackTaskTemplateSvg('flag')}</i><b>${task.attention ? 'Flagged' : 'Not flagged'}</b></div>${canUpdate && !taskIsCompleted(task) && !attentionLockedByStatus ? `<button type="button" data-ft-task-toggle-attention>${task.attention ? 'Unflag task' : 'Flag task'}</button>` : '<span></span>'}</div>
                        ${task.blockerReason ? `<div class="ft-task-side-note"><b>Reason</b><p>${html(task.blockerReason)}</p></div>` : ''}
                    </section>

                    <section class="ft-task-side-card ft-task-job-card">
                        <h2>Job context</h2>
                        ${job ? `<button type="button" class="ft-task-job-code" data-ft-task-open-job>${html(job.id || task.job)} ↗</button>
                        <h3>${html(job.title || task.jobTitle || task.job || 'Job')}</h3>
                        <dl><dt>Client</dt><dd>${html(job.clientName || clientName(job.client) || 'Unknown client')}</dd><dt>Job health</dt><dd><span class="ft-task-health is-${healthTone}"><i></i>${html(health)}</span></dd><dt>Delivery</dt><dd>${html(flowtrackTaskTemplateDate(job.delivery))}</dd><dt>Job progress</dt><dd><b>${jobProgress}%</b><span class="ft-task-job-progress"><i style="width:${jobProgress}%"></i></span></dd></dl>
                        <button type="button" class="ft-task-open-job-link" data-ft-task-open-job>Open job details ↗</button>` : `<h3>${html(task.jobTitle || task.job || 'No linked job')}</h3>`}
                    </section>

                    <section class="ft-task-side-card ft-task-dependency-card">
                        <h2>Dependencies</h2>
                        <div class="ft-task-blocker-state ${activeBlocker ? 'is-blocked' : ''}"><i>${activeBlocker ? '!' : '✓'}</i><span>${activeBlocker ? '1 active blocker' : 'No active blockers'}</span></div>
                        ${dependency ? `<div class="ft-task-dependency-row"><span>Previous task:</span><button type="button" data-ft-task-open-dependency="${html(dependency.id || dependency.dbId)}">${html(dependency.title || dependency.id)} · ${html(dependency.status || (dependency.completed ? 'Completed' : 'Open'))}</button></div>` : '<div class="ft-task-dependency-row"><span>Previous task:</span><b>None</b></div>'}
                        ${dependentTasks.length ? `<div class="ft-task-dependent-list"><span>Following tasks:</span>${dependentTasks.map((item) => `<button type="button" data-ft-task-open-dependency="${html(item.id || item.dbId)}">${html(item.title || item.id)} · ${html(item.status || 'Open')}</button>`).join('')}</div>` : ''}
                        <button type="button" class="ft-task-link-btn" data-ft-task-add-dependency>Add dependency</button>
                    </section>

                    <div class="ft-task-side-footer">Created ${html(flowtrackTaskTemplateDate(String(task.createdAt || '').slice(0, 10), '—'))}<span>·</span>Updated ${html(updatedLabel || '—')}</div>
                </aside>
            </div>
        </div>`;
    }

    function flowtrackTaskTemplateActiveTab(page) {
        return page?.querySelector('[data-ft-task-activity-tab].is-active')?.dataset.ftTaskActivityTab || 'all';
    }

    function flowtrackTaskTemplateRender(identifier, activeTab = 'all') {
        const task = flowtrackTaskTemplateCurrent(identifier);
        if (!task) return false;
        const pageId = `record-task-${flowtrackTaskTemplateSlug(task.id || task.dbId)}`;
        const page = document.getElementById(`page-${pageId}`);
        if (!page) return false;
        page.__flowtrackTaskTemplateController?.abort();
        page.__flowtrackTaskTemplateController = new AbortController();
        page.classList.add('ft-task-final-host');
        page.innerHTML = flowtrackTaskTemplateMarkup(task, activeTab);
        bindStandaloneBack(page);
        flowtrackTaskTemplateBind(page, task);
        applyActionPermissions();
        return true;
    }

    async function flowtrackTaskTemplateSave(identifier, status, extras, message, activeTab = 'all') {
        const current = flowtrackTaskTemplateCurrent(identifier);
        if (!current) throw new Error('The selected task is no longer available.');
        const result = await persistTask(current, status || current.statusId || current.statusCode || current.status, extras || {});
        updateTaskAndJob(result);
        updateBadges();
        const updated = result.task || flowtrackTaskTemplateCurrent(identifier);
        flowtrackTaskTemplateRender(updated?.id || identifier, activeTab);
        if (message) showToast(message);
        return updated;
    }

    function flowtrackTaskTemplateBind(page, renderedTask) {
        const signal = page.__flowtrackTaskTemplateController?.signal;
        const identifier = renderedTask.id || renderedTask.dbId;
        const getCurrent = () => flowtrackTaskTemplateCurrent(identifier);
        const getTab = () => flowtrackTaskTemplateActiveTab(page);
        const closeEditors = (except = null) => {
            page.querySelectorAll('[data-ft-task-inline].is-open').forEach((card) => {
                if (card !== except) card.classList.remove('is-open');
            });
            page.querySelector('[data-ft-task-action-popover]')?.classList.remove('is-open');
        };

        page.querySelectorAll('[data-ft-task-edit-field]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const card = button.closest('[data-ft-task-inline]');
                if (!card) return;
                const opening = !card.classList.contains('is-open');
                closeEditors(card);
                card.classList.toggle('is-open', opening);
                if (opening) setTimeout(() => card.querySelector('select,input,button[data-ft-task-status-value]')?.focus(), 0);
            });
        });
        page.querySelectorAll('[data-ft-task-inline].is-editable').forEach((card) => {
            card.addEventListener('click', (event) => {
                if (event.target.closest('select,input,button,a,[data-ft-task-editor]')) return;
                card.querySelector('[data-ft-task-edit-field]')?.click();
            });
        });
        document.addEventListener('pointerdown', (event) => {
            if (!page.isConnected) {
                page.__flowtrackTaskTemplateController?.abort();
                return;
            }
            const target = event.target instanceof Element ? event.target : null;
            if (target && page.contains(target) && target.closest('[data-ft-task-inline],.ft-task-action-wrap')) return;
            closeEditors();
        }, signal ? { signal } : undefined);

        page.querySelectorAll('[data-ft-task-status-value]').forEach((button) => {
            button.addEventListener('click', async () => {
                const current = getCurrent();
                const selected = taskStatusRecord(Number(button.dataset.ftTaskStatusValue));
                if (!current || !selected) return;
                button.disabled = true;
                const completed = taskStatusMatches(selected, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
                const blocked = taskStatusMatches(selected, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);
                if (completed && current.documentRequirement && flowtrackTaskTemplateDocumentVersions(current).length === 0) {
                    button.disabled = false;
                    page.querySelector('[data-ft-task-attachment-section]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return showToast(`Upload or provide the required ${current.documentRequirement.categoryName || 'document'} before completing this task.`);
                }
                try {
                    await flowtrackTaskTemplateSave(identifier, selected, {
                        attention_required: completed ? false : current.attention,
                        blocker_reason: completed ? null : (blocked ? (current.blockerReason || `Task status changed to ${selected.name}.`) : current.blockerReason),
                    }, 'Task status updated.', getTab());
                } catch (error) {
                    button.disabled = false;
                    showToast(error.message || 'Task status could not be updated.');
                }
            });
        });

        page.querySelector('[data-ft-task-assignee]')?.addEventListener('change', async (event) => {
            const select = event.currentTarget;
            select.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, { assignee_id: select.value ? Number(select.value) : null }, 'Assignee updated.', getTab());
            } catch (error) {
                select.disabled = false;
                showToast(error.message || 'Assignee could not be updated.');
            }
        });

        page.querySelector('[data-ft-task-priority]')?.addEventListener('change', async (event) => {
            const select = event.currentTarget;
            select.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, { priority_id: select.value ? Number(select.value) : null }, 'Priority updated.', getTab());
            } catch (error) {
                select.disabled = false;
                showToast(error.message || 'Priority could not be updated.');
            }
        });

        page.querySelector('[data-ft-task-start-date]')?.addEventListener('change', async (event) => {
            const input = event.currentTarget;
            const current = getCurrent();
            const due = String(current?.due || '').slice(0, 10);
            if (input.value && due && input.value > due) {
                showToast('Start date cannot be later than the due date.');
                input.value = String(current?.startDate || '').slice(0, 10);
                return;
            }
            input.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, { start_date: input.value || null }, 'Start date updated.', getTab());
            } catch (error) {
                input.disabled = false;
                showToast(error.message || 'Start date could not be updated.');
            }
        });

        page.querySelector('[data-ft-task-due-date]')?.addEventListener('change', async (event) => {
            const input = event.currentTarget;
            const current = getCurrent();
            const start = String(current?.startDate || '').slice(0, 10);
            if (input.value && start && input.value < start) {
                showToast('Due date cannot be earlier than the start date.');
                input.value = String(current?.due || '').slice(0, 10);
                return;
            }
            input.disabled = true;
            try {
                const result = await persistTaskDueDate(current, input.value || null);
                updateTaskAndJob(result);
                updateBadges();
                flowtrackTaskTemplateRender(result.task?.id || identifier, getTab());
                showToast('Due date updated.');
            } catch (error) {
                input.disabled = false;
                showToast(error.message || 'Due date could not be updated.');
            }
        });

        const setDescriptionEditing = (editing) => {
            page.querySelector('.ft-task-description-section')?.classList.toggle('is-editing', editing);
            if (editing) setTimeout(() => page.querySelector('[data-ft-task-description-input]')?.focus(), 0);
        };
        page.querySelector('[data-ft-task-edit-description]')?.addEventListener('click', () => setDescriptionEditing(true));
        page.querySelector('[data-ft-task-description-cancel]')?.addEventListener('click', () => setDescriptionEditing(false));
        page.querySelector('[data-ft-task-description-save]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, { description: page.querySelector('[data-ft-task-description-input]')?.value.trim() || null }, 'Description updated.', getTab());
            } catch (error) {
                button.disabled = false;
                showToast(error.message || 'Description could not be updated.');
            }
        });



        page.querySelector('[data-ft-task-mark-complete]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const complete = taskStatusRecord('CMP') || taskStatusRecord('COMPLETED') || taskStatusRecord('Completed') || selectableTaskStatuses().find((item) => taskStatusMatches(item, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']));
            if (!complete) return showToast('A completed task status is not configured in Master Data.');
            const current = getCurrent();
            if (current?.documentRequirement && flowtrackTaskTemplateDocumentVersions(current).length === 0) {
                page.querySelector('[data-ft-task-attachment-section]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return showToast(`Upload or provide the required ${current.documentRequirement.categoryName || 'document'} before completing this task.`);
            }
            button.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, complete, { attention_required: false, blocker_reason: null }, 'Task marked complete.', getTab());
            } catch (error) {
                button.disabled = false;
                showToast(error.message || 'Task could not be completed.');
            }
        });

        page.querySelector('[data-ft-task-action-menu]')?.addEventListener('click', (event) => {
            event.stopPropagation();
            const menu = page.querySelector('[data-ft-task-action-popover]');
            menu?.classList.toggle('is-open');
        });
        page.querySelector('[data-ft-task-delete]')?.addEventListener('click', async (event) => {
            event.currentTarget.disabled = true;
            const current = getCurrent();
            if (!current || !confirm(`Delete task ${current.id}?`)) {
                event.currentTarget.disabled = false;
                return;
            }
            try {
                const result = await api(`/flowtrack/template-api/tasks/${current.dbId}`, { method: 'DELETE' });
                if (result.job) {
                    flowtrackUpsertRecord(jobs, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                flowtrackRemoveRecord(tasks, result.deletedTask || current);
                updateBadges();
                const destination = standaloneBackPage || 'mywork';
                standaloneActivePageId = null;
                navigate(destination);
                showToast('Task deleted successfully.');
            } catch (error) {
                event.currentTarget.disabled = false;
                showToast(error.message || 'Task could not be deleted.');
            }
        });

        page.querySelectorAll('[data-ft-task-checklist]').forEach((input) => {
            input.addEventListener('change', async () => {
                const current = getCurrent();
                if (!current) return;
                const completedIds = flowtrackToArray(current.checklist)
                    .filter((item) => item.done || Number(item.id) === Number(input.dataset.ftTaskChecklist))
                    .map((item) => Number(item.id));
                if (!input.checked) {
                    const index = completedIds.indexOf(Number(input.dataset.ftTaskChecklist));
                    if (index >= 0) completedIds.splice(index, 1);
                }
                page.querySelectorAll('[data-ft-task-checklist]').forEach((item) => { item.disabled = true; });
                try {
                    await flowtrackTaskTemplateSave(identifier, null, { completed_checklist_ids: completedIds }, 'Checklist updated.', getTab());
                } catch (error) {
                    showToast(error.message || 'Checklist could not be updated.');
                    flowtrackTaskTemplateRender(identifier, getTab());
                }
            });
        });
        const closeTaskChecklistModal = () => {
            $('#overlay')?.classList.remove('open');
            $('#jobModal')?.classList.remove('open');
        };
        const bindTaskChecklistModalClose = (modal) => {
            modal.querySelectorAll('[data-ft-task-checklist-modal-close]').forEach((button) => {
                button.addEventListener('click', closeTaskChecklistModal);
            });
        };

        page.querySelector('[data-ft-task-add-checklist]')?.addEventListener('click', () => {
            const modal = $('#jobModal');
            if (!modal) return;
            modal.innerHTML = `<div class="modal-head"><div><h2>Add checklist item</h2><div class="small muted" style="margin-top:5px">Add a new item to this task checklist.</div></div><button type="button" class="close-btn" data-ft-task-checklist-modal-close aria-label="Close">×</button></div>
                <div class="modal-body"><div class="field"><label>Checklist item *</label><input id="ftTaskChecklistItemLabel" maxlength="255" autocomplete="off" placeholder="Enter checklist item"></div></div>
                <div class="modal-foot"><button type="button" class="ghost" data-ft-task-checklist-modal-close>Cancel</button><button type="button" class="primary" id="ftTaskChecklistAddConfirm">Add item</button></div>`;
            openOverlay();
            modal.classList.add('open');
            bindTaskChecklistModalClose(modal);

            const input = modal.querySelector('#ftTaskChecklistItemLabel');
            const submit = modal.querySelector('#ftTaskChecklistAddConfirm');
            const saveItem = async () => {
                const cleaned = String(input?.value || '').trim();
                if (!cleaned) {
                    showToast('Checklist item text is required.');
                    input?.focus();
                    return;
                }
                if (submit) submit.disabled = true;
                if (input) input.disabled = true;
                try {
                    await flowtrackTaskTemplateSave(identifier, null, { checklist_add: cleaned }, 'Checklist item added.', getTab());
                    closeTaskChecklistModal();
                } catch (error) {
                    if (submit) submit.disabled = false;
                    if (input) input.disabled = false;
                    showToast(error.message || 'Checklist item could not be added.');
                    input?.focus();
                }
            };
            submit?.addEventListener('click', saveItem);
            input?.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                void saveItem();
            });
            setTimeout(() => input?.focus(), 0);
        });

        page.querySelectorAll('[data-ft-task-delete-checklist]').forEach((button) => button.addEventListener('click', () => {
            const current = getCurrent();
            const itemId = Number(button.dataset.ftTaskDeleteChecklist || 0);
            const item = flowtrackToArray(current?.checklist).find((entry) => Number(entry.id) === itemId);
            if (!itemId || !item) return;

            const modal = $('#jobModal');
            if (!modal) return;
            modal.innerHTML = `<div class="modal-head"><div><h2>Delete checklist item?</h2><div class="small muted" style="margin-top:5px">This removes the item from this task checklist.</div></div><button type="button" class="close-btn" data-ft-task-checklist-modal-close aria-label="Close">×</button></div>
                <div class="modal-body"><div class="card" style="padding:16px;border-color:#fecaca;background:#fff7f7"><div class="small muted">Checklist item</div><h3 style="margin:5px 0 0">${html(item.text || 'Checklist item')}</h3></div></div>
                <div class="modal-foot"><button type="button" class="ghost" data-ft-task-checklist-modal-close>Cancel</button><button type="button" class="danger-btn" id="ftTaskChecklistDeleteConfirm">Delete item</button></div>`;
            openOverlay();
            modal.classList.add('open');
            bindTaskChecklistModalClose(modal);

            const confirmButton = modal.querySelector('#ftTaskChecklistDeleteConfirm');
            confirmButton?.addEventListener('click', async () => {
                confirmButton.disabled = true;
                try {
                    await flowtrackTaskTemplateSave(identifier, null, { checklist_delete_id: itemId }, 'Checklist item deleted.', getTab());
                    closeTaskChecklistModal();
                } catch (error) {
                    confirmButton.disabled = false;
                    showToast(error.message || 'Checklist item could not be deleted.');
                }
            });
            setTimeout(() => confirmButton?.focus(), 0);
        }));

        const uploadFile = async (file) => {
            const current = getCurrent();
            const requirement = current?.documentRequirement;
            if (!file) return;
            if (!current?.canUploadDocument || !requirement) return showToast('File upload is not available for this task.');
            if (file.size > 20 * 1024 * 1024) return showToast('The document must not be larger than 20 MB.');
            const dropzone = page.querySelector('[data-ft-task-dropzone]');
            dropzone?.classList.add('is-uploading');
            try {
                const formData = new FormData();
                formData.append('job_id', String(current.jobDbId));
                formData.append('workflow_phase_id', String(requirement.phaseId || current.phaseId));
                if (requirement.taskPackItemId) formData.append('task_pack_item_id', String(requirement.taskPackItemId));
                formData.append('document_category_id', String(requirement.categoryId));
                formData.append('task_id', String(requirement.taskId || current.dbId));
                const existing = flowtrackToArray(current.documents)[0];
                if (existing?.dbId) formData.append('document_id', String(existing.dbId));
                formData.append('file', file);
                const result = await api('/flowtrack/template-api/documents', { method: 'POST', body: formData });
                if (result.document) flowtrackUpsertRecord(docs, result.document);
                if (result.job) {
                    flowtrackUpsertRecord(jobs, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                updateBadges();
                const updated = flowtrackTaskTemplateCurrent(identifier);
                flowtrackTaskTemplateRender(updated?.id || identifier, getTab());
                showToast(result.message || 'Attachment uploaded successfully.');
            } catch (error) {
                dropzone?.classList.remove('is-uploading');
                showToast(error.message || 'Attachment upload failed.');
            }
        };
        const fileInput = page.querySelector('[data-ft-task-file-input]');
        page.querySelector('[data-ft-task-browse]')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            fileInput?.click();
        });
        fileInput?.addEventListener('change', () => uploadFile(fileInput.files?.[0]));
        const dropzone = page.querySelector('[data-ft-task-dropzone]');
        dropzone?.addEventListener('dragover', (event) => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
        dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
        dropzone?.addEventListener('drop', (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragging');
            uploadFile(event.dataTransfer?.files?.[0]);
        });
        dropzone?.addEventListener('keydown', (event) => {
            if (['Enter', ' '].includes(event.key) && !fileInput?.disabled) {
                event.preventDefault();
                fileInput.click();
            }
        });
        page.querySelectorAll('[data-ft-task-delete-document]').forEach((button) => button.addEventListener('click', () => {
            const current = getCurrent();
            const documentRecord = flowtrackToArray(current?.documents).find((item) => Number(item.dbId) === Number(button.dataset.ftTaskDeleteDocument))
                || docs.find((item) => Number(item.dbId) === Number(button.dataset.ftTaskDeleteDocument));
            if (!documentRecord) return showToast('The selected document could not be found.');
            flowtrackConfirmDeleteDocument(documentRecord, () => {
                const updated = flowtrackTaskTemplateCurrent(identifier);
                flowtrackTaskTemplateRender(updated?.id || identifier, getTab());
            });
        }));

        page.querySelector('[data-ft-task-choose-documents]')?.addEventListener('click', () => {
            const current = getCurrent();
            const requirement = current?.documentRequirement;
            const linkedJob = flowtrackTaskTemplateJob(current);
            if (!current?.canUploadDocument || !requirement || !linkedJob) return showToast('Stored-document linking is not available for this task.');
            flowtrackOpenStoredDocumentChooser(linkedJob, requirement, current, () => {
                const updated = flowtrackTaskTemplateCurrent(identifier);
                flowtrackTaskTemplateRender(updated?.id || identifier, getTab());
            });
        });
        page.querySelector('[data-ft-task-upload-link]')?.addEventListener('click', () => {
            page.querySelector('[data-ft-task-attachment-section]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => fileInput?.click(), 350);
        });

        page.querySelector('[data-ft-task-toggle-attention]')?.addEventListener('click', async (event) => {
            const current = getCurrent();
            if (!current) return;
            event.currentTarget.disabled = true;
            const next = !current.attention;
            try {
                await flowtrackTaskTemplateSave(identifier, null, {
                    attention_required: next,
                    blocker_reason: next ? (current.blockerReason || null) : null,
                }, next ? 'Task marked as attention needed.' : 'Attention mark cleared.', getTab());
            } catch (error) {
                event.currentTarget.disabled = false;
                showToast(error.message || 'Attention status could not be updated.');
            }
        });

        page.querySelectorAll('[data-ft-task-open-job]').forEach((button) => button.addEventListener('click', () => {
            const current = getCurrent();
            const job = flowtrackTaskTemplateJob(current);
            if (job) void openJob(job.id || job.dbId);
            else if (current?.jobDbId) flowtrackNavigateTo(`/flowtrack/jobs/${current.jobDbId}`);
        }));
        page.querySelectorAll('[data-ft-task-open-dependency]').forEach((button) => button.addEventListener('click', () => void openTask(button.dataset.ftTaskOpenDependency)));
        page.querySelector('[data-ft-task-add-dependency]')?.addEventListener('click', () => showToast('Dependencies are configured from task setup and workflow rules.'));

        const applyActivityTab = (tab) => {
            const normalizedTab = ['all', 'comments', 'history'].includes(tab) ? tab : 'all';
            page.querySelectorAll('[data-ft-task-activity-tab]').forEach((button) => {
                button.classList.toggle('is-active', button.dataset.ftTaskActivityTab === normalizedTab);
                button.setAttribute('aria-selected', button.dataset.ftTaskActivityTab === normalizedTab ? 'true' : 'false');
            });
            page.querySelectorAll('[data-ft-task-activity-kind]').forEach((row) => {
                const kind = row.dataset.ftTaskActivityKind;
                const commentAudit = row.dataset.ftTaskHistoryComment === '1';
                let shouldHide = false;

                if (normalizedTab === 'comments') {
                    // Comments must contain only actual user comments.
                    shouldHide = kind !== 'comment';
                } else if (normalizedTab === 'history') {
                    // History must contain only real task changes. Comment-only audit rows
                    // are intentionally excluded because they belong to the Comments tab.
                    shouldHide = kind !== 'history' || commentAudit;
                } else {
                    // All contains actual comments plus real task-change history. Hide the
                    // comment-only audit copy so a comment is never rendered twice.
                    shouldHide = kind === 'history' && commentAudit;
                }

                row.hidden = shouldHide;
                // Author CSS gives activity rows display:grid, which can override the
                // browser's native [hidden] rule. Keep an explicit class as the durable
                // visibility contract for tab filtering.
                row.classList.toggle('is-filtered-out', shouldHide);
                row.setAttribute('aria-hidden', shouldHide ? 'true' : 'false');
            });

            const visibleRows = [...page.querySelectorAll('[data-ft-task-activity-kind]')]
                .filter((row) => !row.hidden && !row.classList.contains('is-filtered-out'));
            const empty = page.querySelector('[data-ft-task-activity-empty]');
            if (empty) {
                const showEmpty = visibleRows.length === 0;
                empty.hidden = !showEmpty;
                empty.classList.toggle('is-filtered-out', !showEmpty);
                empty.textContent = normalizedTab === 'comments'
                    ? 'No comments yet'
                    : (normalizedTab === 'history' ? 'No task history yet' : 'No activity yet');
            }
        };
        page.querySelectorAll('[data-ft-task-activity-tab]').forEach((button) => button.addEventListener('click', () => applyActivityTab(button.dataset.ftTaskActivityTab)));
        applyActivityTab(getTab());

        const mentionedIds = new Set();
        const commentInput = page.querySelector('[data-ft-task-comment-input]');
        const mentionMenu = page.querySelector('[data-ft-task-mention-menu]');
        bindFlowtrackMentionInput(commentInput, mentionMenu, flowtrackToArray(renderedTask.mentionableUsers), mentionedIds);
        const submitComment = async () => {
            const comment = commentInput?.value.trim() || '';
            if (!comment) return;
            const current = getCurrent();
            const activeMentionIds = flowtrackToArray(renderedTask.mentionableUsers)
                .filter((user) => comment.toLowerCase().includes(`@${String(user.name || '').toLowerCase()}`))
                .map((user) => Number(user.id));
            flowtrackToArray(mentionedIds).forEach((id) => activeMentionIds.push(Number(id)));
            const button = page.querySelector('[data-ft-task-comment-submit]');
            button.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, {
                    comment,
                    mentioned_user_ids: [...new Set(activeMentionIds.filter(Number.isFinite))],
                }, 'Comment added.', 'all');
            } catch (error) {
                button.disabled = false;
                showToast(error.message || 'Comment could not be added.');
            }
        };
        page.querySelector('[data-ft-task-comment-submit]')?.addEventListener('click', submitComment);
        commentInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                submitComment();
            }
        });
    }

    
    /* CSS externalized: flowtrackTaskTemplateStyles. */

    const flowtrackFinalTaskTemplateBase = currentFlowtrackImplementation('openTask');
    registerFlowtrackExtension('openTask', 'openTask-stage-4-final-template', async function (id) {
        const result = await flowtrackFinalTaskTemplateBase(id);
        const task = flowtrackTaskTemplateCurrent(id);
        if (task) flowtrackTaskTemplateRender(task.id || task.dbId, 'all');
        return result;
    });

    /* Final Job Details reference template (v4): inline editing, all configured phase tasks,
       task-style attachments, functional filters/expanders, and job activity tabs. */
    function flowtrackJobV4Workflow(job) {
        return getWorkflow(job?.workflow || 'standard') || { phases: [], name: job?.workflowName || 'Workflow' };
    }

    function flowtrackJobV4ConfiguredPhaseTasks(job, phase, actualTasks = null) {
        const actual = flowtrackTasksForPhase(job, phase.id, actualTasks || flowtrackJobAllTasks(job))
            .map((task) => ({ ...flowtrackHydrateTaskAssignee(task), planned: false }));
        const actualPackItems = new Set(actual.map((task) => Number(task.taskPackItemId || 0)).filter(Boolean));
        const packId = Number(phase.taskPackId || phase.task_pack_id || 0);
        const pack = flowtrackToArray(taskPacks).find((item) => Number(item.id) === packId);
        const configured = flowtrackToArray(pack?.items)
            .filter((item) => !actualPackItems.has(Number(item.id)))
            .sort((left, right) => Number(left.sort_order ?? 9999) - Number(right.sort_order ?? 9999))
            .map((item) => ({
                planned: true,
                id: `setup-${phase.id}-${item.id}`,
                dbId: null,
                job: job.id,
                jobDbId: job.dbId,
                phaseId: phase.id,
                phase: phase.name,
                taskPackItemId: Number(item.id),
                title: item.title,
                description: item.description || '',
                assigneeId: item.default_assignee_id ? Number(item.default_assignee_id) : null,
                assignee: item.default_assignee_name || item.default_department_name || job.coordinator || job.owner || 'Unassigned',
                due: '',
                status: 'Not started',
                statusId: (masterData.taskStatuses || []).find((status) => /^(ready|not started|new)$/i.test(String(status.name || '')))?.id || (masterData.taskStatuses || [])[0]?.id || null,
                progress: 0,
                canUpdate: Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin || canFlow('tasks', 'create')),
                canAssign: Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin || canFlow('tasks', 'assign')),
                canChangeDueDate: Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin || canFlow('tasks', 'edit_all') || canFlow('tasks', 'edit_own')),
                documentRequirement: item.document_category_id ? {
                    source: 'task_pack_item',
                    phaseId: Number(phase.id),
                    taskPackItemId: Number(item.id),
                    taskId: null,
                    categoryId: Number(item.document_category_id),
                    categoryName: item.document_category_name || 'Required document',
                } : null,
                setupLabel: pack?.name || 'Task Setup',
            }));
        return [...actual, ...configured].sort((left, right) => {
            const leftSort = Number(left.sortOrder ?? left.sort_order ?? 9999);
            const rightSort = Number(right.sortOrder ?? right.sort_order ?? 9999);
            if (leftSort !== rightSort) return leftSort - rightSort;
            return String(left.title || '').localeCompare(String(right.title || ''));
        });
    }

    function flowtrackJobV4AllConfiguredTasks(job, actualTasks = null) {
        const phases = flowtrackActivePhases(job);
        const actual = actualTasks || flowtrackJobAllTasks(job);
        return phases.flatMap((phase) => flowtrackJobV4ConfiguredPhaseTasks(job, phase, actual));
    }

    function flowtrackJobV4TaskStatusOptions(task) {
        const statuses = (masterData.taskStatuses || []).filter((status) => String(status.status || 'Active').toLowerCase() === 'active');
        return statuses.map((status) => `<option value="${html(status.id)}" ${Number(status.id) === Number(task.statusId) ? 'selected' : ''}>${html(status.name)}</option>`).join('');
    }

    function flowtrackJobV4AssigneeOptions(task) {
        const members = typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : flowtrackToArray(users);
        return `<option value="">Unassigned</option>${members.map((member) => `<option value="${html(member.id)}" ${Number(member.id) === Number(task.assigneeId) ? 'selected' : ''}>${html(member.name)}</option>`).join('')}`;
    }

    function flowtrackJobV4StatusIsComplete(statusId) {
        const status = flowtrackToArray(masterData.taskStatuses).find((item) => Number(item.id) === Number(statusId));
        const key = normalizedStatusValue(status?.code || status?.name || '');
        return ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'].includes(key);
    }

    function flowtrackJobV4TaskDocumentRequirement(task, phaseId = null, packItemId = null) {
        const requirement = task?.documentRequirement || task?.document_requirement || null;
        if (requirement?.categoryId || requirement?.category_id) {
            return {
                phaseId: Number(requirement.phaseId || requirement.phase_id || phaseId || task?.phaseId || 0),
                taskPackItemId: Number(requirement.taskPackItemId || requirement.task_pack_item_id || packItemId || task?.taskPackItemId || 0) || null,
                taskId: Number(requirement.taskId || requirement.task_id || task?.dbId || 0) || null,
                categoryId: Number(requirement.categoryId || requirement.category_id || 0),
                categoryName: requirement.categoryName || requirement.category_name || 'required document',
            };
        }
        return null;
    }

    function flowtrackJobV4TaskHasRequiredEvidence(job, task, requirement) {
        if (!requirement?.categoryId) return true;
        const jobCode = String(job?.id || '');
        return flowtrackToArray(docs).some((documentRecord) => {
            if (jobCode && String(documentRecord?.job || '') !== jobCode) return false;
            if (Number(documentRecord?.phaseId || 0) !== Number(requirement.phaseId || task?.phaseId || 0)) return false;
            if (Number(documentRecord?.categoryId || 0) !== Number(requirement.categoryId || 0)) return false;
            const exactTaskId = Number(requirement.taskId || task?.dbId || 0);
            const linkedTaskId = Number(documentRecord?.taskId || 0);
            if (exactTaskId && linkedTaskId) return linkedTaskId === exactTaskId;
            const packItemId = Number(requirement.taskPackItemId || task?.taskPackItemId || 0);
            if (packItemId) return Number(documentRecord?.taskPackItemId || 0) === packItemId;
            return !linkedTaskId && !Number(documentRecord?.taskPackItemId || 0);
        });
    }

    function flowtrackJobV4TaskRow(job, phase, rawTask, index) {
        const task = rawTask.planned ? rawTask : flowtrackHydrateTaskAssignee(rawTask || {});
        const canUpdate = Boolean(task.canUpdate || task.planned && (isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin || canFlow('tasks', 'create')));
        // All Phase Tasks stays in view mode by default. Only Admin/Super Admin
        // gets the inline assignee edit affordance in this Job Details table.
        // Other assignment paths keep their existing backend/Role Matrix rules.
        const canAssign = canUpdate && Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin);
        const canDue = canUpdate && Boolean(task.canChangeDueDate || task.planned || isFlowAdmin || isFlowSuperAdmin);
        const assigneeName = task.assignee || 'Unassigned';
        const status = task.status || 'Not started';
        const due = task.due || task.dueDate || '';
        const sequence = `${Number(phase.sequence || 0)}.${index + 1}`;
        const documentRequirement = flowtrackJobV4TaskDocumentRequirement(task, phase.id, task.taskPackItemId);
        const evidencePresent = documentRequirement ? flowtrackJobV4TaskHasRequiredEvidence(job, task, documentRequirement) : true;
        const requirementNote = documentRequirement
            ? `<small class="job-v4-required-doc ${evidencePresent ? 'is-present' : 'is-missing'}">${evidencePresent ? '✓' : '!'} ${html(documentRequirement.categoryName)} ${evidencePresent ? 'uploaded' : 'required before completion'}</small>`
            : (task.planned ? `<small>Planned from ${html(task.setupLabel || 'Task Setup')}</small>` : '');
        return `<tr class="job-v4-task-row" data-job-v4-task-row data-task-id="${html(task.id)}" data-task-db-id="${html(task.dbId || '')}" data-phase-id="${html(phase.id)}" data-pack-item-id="${html(task.taskPackItemId || '')}" data-status="${html(normalizedStatusValue(status))}" data-assignee="${html(normalizedStatusValue(assigneeName))}" data-assignee-id="${html(task.assigneeId || '')}" data-planned="${task.planned ? '1' : '0'}" data-requires-document="${documentRequirement ? '1' : '0'}">
            <td class="job-v4-task-seq">${html(sequence)}</td>
            <td><button type="button" class="job-v4-task-title" data-job-v4-open-task ${task.planned ? 'disabled' : ''}>${html(task.title || task.id)}</button>${requirementNote}</td>
            <td><div class="job-v4-assignee-cell"><span class="job-v4-person" data-job-v4-assignee-label><span class="avatar">${userInitials(assigneeName)}</span><span>${html(assigneeName)}</span></span>${canAssign ? `<button type="button" class="job-v4-icon-btn" data-job-v4-edit-assignee title="Edit assignee" aria-label="Edit assignee">✎</button>` : ''}</div></td>
            <td><div class="job-v4-due-cell"><span data-job-v4-due-label>${due ? formatDate(due) : 'Not set'}</span>${canDue ? `<button type="button" class="job-v4-icon-btn" data-job-v4-edit-due title="Edit due date">✎</button><input type="date" class="job-v4-hidden-date" data-job-v4-due-input value="${html(due)}">` : ''}</div></td>
            <td>${canUpdate ? `<select class="job-v4-task-inline-select job-v4-status-select" data-job-v4-task-status data-previous-value="${html(task.statusId || '')}">${flowtrackJobV4TaskStatusOptions(task)}</select>` : statusBadge(status)}</td>
            <td class="job-v4-task-actions"><button type="button" class="job-v4-row-menu" data-job-v4-open-task ${task.planned ? 'disabled' : ''} aria-label="Open task">•••</button></td>
        </tr>`;
    }

    function flowtrackJobV4PhaseAccordion(job, actualTasks, activePhases, currentPhase) {
        return `<div class="job-v4-phase-stack">${activePhases.map((phase, phaseIndex) => {
            const rows = flowtrackJobV4ConfiguredPhaseTasks(job, phase, actualTasks);
            const completed = rows.filter((task) => !task.planned && taskIsCompleted(task)).length;
            const percent = rows.length ? Math.round((completed / rows.length) * 100) : 0;
            const isCurrent = Number(phase.id) === Number(job.phaseId);
            const open = isCurrent;
            return `<section class="job-v4-phase" data-job-v4-phase data-phase-id="${html(phase.id)}">
                <div class="job-v4-phase-head">
                    <button type="button" class="job-v4-phase-toggle" data-job-v4-toggle-phase><span class="job-v4-caret">${open ? '⌄' : '›'}</span><span class="job-v4-phase-number ${isCurrent ? 'is-current' : completed === rows.length && rows.length ? 'is-done' : ''}">${completed === rows.length && rows.length ? '✓' : Number(phase.sequence || phaseIndex + 1)}</span><b>${html(phase.name)}</b></button>
                    <div class="job-v4-phase-summary"><span>${completed} of ${rows.length} complete</span><i><span style="width:${percent}%"></span></i>${canFlow('tasks', 'create') ? `<button type="button" class="job-v4-phase-add" data-job-v4-add-task="${html(phase.id)}" title="Add task to ${html(phase.name)}">＋</button>` : ''}</div>
                </div>
                <div class="job-v4-phase-body ${open ? '' : 'is-collapsed'}">
                    <table class="job-v4-task-table"><thead><tr><th></th><th>Task</th><th>Assignee</th><th>Due date</th><th>Status</th><th>Action</th></tr></thead><tbody>${rows.map((task, index) => flowtrackJobV4TaskRow(job, phase, task, index)).join('') || '<tr><td colspan="6"><div class="empty-state">No tasks configured for this phase in Task Setup.</div></td></tr>'}</tbody></table>
                </div>
            </section>`;
        }).join('')}</div>`;
    }

    function flowtrackJobV4ProductTable(job) {
        const products = flowtrackToArray(job.products);
        const rows = products.length ? products : [{ category: job.category || '', product: job.product || '', productCode: '', categoryId: job.categoryId, productId: job.productId, qty: job.qty || 1 }];
        const totalQty = rows.reduce((sum, item) => sum + Number(item.qty || 0), 0);
        return `<div class="job-v4-products-head"><b>Products & quantities</b><span>${rows.length} product${rows.length === 1 ? '' : 's'} · ${totalQty.toLocaleString()} total units</span></div>
            <table class="job-v4-products-table"><thead><tr><th>Category</th><th>Product</th><th>Quantity</th></tr></thead><tbody>${rows.map((item, index) => `<tr data-job-v4-product-row="${index}"><td>${html(item.category || '—')}</td><td><span>${item.productCode ? `${html(item.productCode)} · ` : ''}${html(item.product || 'Unspecified')}</span>${canEditJobRecord() ? `<button type="button" class="job-v4-icon-btn" data-job-v4-edit-product="${index}">✎</button>` : ''}</td><td><span>${Number(item.qty || 0).toLocaleString()}</span>${canEditJobRecord() ? `<button type="button" class="job-v4-icon-btn" data-job-v4-edit-product="${index}">✎</button>` : ''}</td></tr>`).join('')}</tbody></table>
            ${canEditJobRecord() ? '<button type="button" class="job-v4-link" data-job-v4-add-product>＋ Add product</button>' : ''}`;
    }

    function flowtrackJobV4Attachments(job) {
        const jobDocs = jobDocsFor(job);
        const canUpload = canFlow('documents', 'create') && canFlow('documents', 'link');
        return `<section class="job-v4-card job-v4-attachments" data-job-v4-attachments>
            <div class="job-v4-section-title"><div><h3>Attachments</h3><span class="job-v4-count">${jobDocs.length}</span></div></div>
            <div class="ft-task-dropzone ${canUpload ? '' : 'is-disabled'}" data-job-v4-dropzone tabindex="${canUpload ? '0' : '-1'}">
                <input type="file" data-job-v4-file-input accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip" ${canUpload ? '' : 'disabled'}>
                <span class="ft-task-paperclip">⌕</span>
                <div><b>Drop files here or <button type="button" data-job-v4-browse ${canUpload ? '' : 'disabled'}>browse</button></b><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Max 20 MB</small></div>
                <button type="button" class="ft-task-btn ft-task-btn-secondary" data-job-v4-choose-documents>Choose from Documents</button>
            </div>
            ${jobDocs.length ? `<div class="ft-task-file-list">${jobDocs.slice(0, 6).map((documentRecord) => `<div class="ft-task-file-row"><span class="ft-task-file-icon">${html(flowtrackDocumentFileBadge(documentRecord))}</span><div><b>${html(documentRecord.name || documentRecord.id)}</b><small>${html(documentRecord.category || 'Document')} · ${html(documentRecord.uploadedBy || 'System')} · ${html(documentRecord.date ? flowtrackFormatDateTime(documentRecord.date) : '')}</small></div><div class="ft-task-file-actions">${documentRecord.downloadUrl ? `<a class="ft-task-file-open" href="${html(documentRecord.downloadUrl)}">Open</a>` : ''}${canFlow('documents', 'delete') ? `<button type="button" class="ft-task-file-delete" data-job-v4-delete-document="${html(documentRecord.dbId)}">Delete</button>` : ''}</div></div>`).join('')}</div>` : ''}
        </section>`;
    }

    function flowtrackJobV4Activity(job) {
        const events = flowtrackToArray(job.activity);
        const comments = events.filter((item) => item.kind === 'comment' || item.event === 'job.commented' || item.event === 'task.commented');
        return `<section class="job-v4-card job-v4-activity" data-job-v4-activity>
            <div class="job-v4-activity-tabs"><h3>Activity</h3><button type="button" class="is-active" data-job-v4-activity-tab="comments">Comments <span>${comments.length}</span></button><button type="button" data-job-v4-activity-tab="history">History</button><button type="button" data-job-v4-activity-tab="worklog">Work log</button></div>
            <div class="job-v4-comment-compose" data-job-v4-comment-compose><span class="avatar">${userInitials(currentUser?.name || 'U')}</span><div class="job-v4-comment-input"><input type="text" maxlength="5000" placeholder="Add a comment..." data-job-v4-comment-input><small>Use @ to mention a team member.</small></div><button type="button" class="primary" data-job-v4-comment-submit>Comment</button></div>
            <div class="job-v4-activity-list">${events.map((item) => {
                const kind = item.kind || (item.event === 'job.commented' || item.event === 'task.commented' ? 'comment' : String(item.event || '').startsWith('task.') ? 'worklog' : 'history');
                const displayText = kind === 'comment' ? (item.comment || item.text || item.title || '') : (item.text || item.title || 'Updated');
                return `<div class="job-v4-activity-row" data-job-v4-activity-kind="${html(kind)}"><span class="avatar">${html(item.actorInitials || userInitials(item.actor || 'System'))}</span><div><b>${html(item.actor || 'System')}</b><p>${html(displayText)}</p>${kind !== 'comment' && item.title && item.text !== item.title ? `<small>${html(item.title)}</small>` : ''}</div><time>${html(item.time || '')}</time></div>`;
            }).join('')}<div class="job-v4-empty is-hidden" data-job-v4-activity-empty>No comments yet</div></div>
        </section>`;
    }

    function flowtrackJobV4Overview(job, actualTasks, allDocs, activePhases, currentPhase) {
        const wf = flowtrackJobV4Workflow(job);
        const configuredTasks = flowtrackJobV4AllConfiguredTasks(job, actualTasks);
        const completed = configuredTasks.filter((task) => !task.planned && taskIsCompleted(task)).length;
        const currentRows = currentPhase ? flowtrackJobV4ConfiguredPhaseTasks(job, currentPhase, actualTasks) : [];
        const currentCompleted = currentRows.filter((task) => !task.planned && taskIsCompleted(task)).length;
        const nextTask = currentRows.find((task) => task.planned || !taskIsCompleted(task)) || configuredTasks.find((task) => task.planned || !taskIsCompleted(task));
        const commercialStatus = typeof boardJobCommercialLabel === 'function' ? boardJobCommercialLabel(job) : (job.price ? 'Invoice pending' : 'Quotation pending');
        return `<section id="detailPanel0" class="detail-panel active job-v4-overview">
            <div class="job-v4-metrics">
                <div class="job-v4-metric"><span class="job-v4-metric-icon">▣</span><div><small>Current phase</small><b>${html(phaseNameFor(job))} · Phase ${Number(currentPhase?.sequence || job.phase + 1 || 1)} of ${activePhases.length}</b><em>${currentRows.length} tasks · ${currentCompleted} of ${currentRows.length} complete</em></div></div>
                <div class="job-v4-metric"><span class="job-v4-metric-icon">↗</span><div><small>Overall progress</small><b>${Number(job.progress || 0)}%</b><i><span style="width:${Math.max(0, Math.min(100, Number(job.progress || 0)))}%"></span></i></div></div>
                <div class="job-v4-metric"><span class="job-v4-metric-icon">⌘</span><div><small>Next required action</small><b>${html(nextTask?.title || job.next || 'Review current phase')}</b><em>${html(nextTask?.assignee || job.coordinator || 'Unassigned')}</em></div></div>
                <div class="job-v4-metric job-v4-commercial-metric"><span class="job-v4-metric-icon">$</span><div><small>Commercial</small><b>${job.price ? currency(job.price, job.currency) : 'Not recorded'}</b><em>${html(commercialStatus)}</em></div></div>
            </div>
            <div class="job-v4-info-grid">
                <section class="job-v4-card job-v4-overview-card"><h3>Job overview</h3><div class="job-v4-description-row"><span data-job-v4-description-text>${html(job.requestDescription || 'No job description recorded.')}</span>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-description>✎</button>' : ''}</div>${flowtrackJobV4ProductTable(job)}</section>
                <section class="job-v4-card job-v4-planning"><h3>Planning & ownership</h3>
                    <div class="job-v4-plan-row"><span>Required delivery</span><b>${job.delivery ? formatDate(job.delivery) : 'Not set'}</b>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-field="delivery">✎</button>' : ''}</div>
                    <div class="job-v4-plan-row"><span>Priority</span><b>${html(job.priority || 'Medium')}</b>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-field="priority">✎</button>' : ''}</div>
                    <div class="job-v4-plan-row"><span>Job owner</span><b><span class="avatar">${userInitials(job.owner || 'U')}</span>${html(job.owner || 'Unassigned')}</b>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-field="owner">✎</button>' : ''}</div>
                    <div class="job-v4-plan-row"><span>Coordinator</span><b><span class="avatar">${userInitials(job.coordinator || 'U')}</span>${html(job.coordinator || 'Unassigned')}</b>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-field="coordinator">✎</button>' : ''}</div>
                    <div class="job-v4-plan-row"><span>Workflow</span><b>▣ ${html(job.workflowName || wf.name || 'Workflow')}</b><span></span></div>
                    <div class="job-v4-plan-row"><span>Created</span><b>${job.createdAt ? flowtrackFormatDateTime(job.createdAt) : 'Not recorded'}</b><span></span></div>
                </section>
            </div>
            ${flowtrackMiniTimeline(job, activePhases, currentPhase)}
            <section class="job-v4-card job-v4-all-tasks">
                <div class="job-v4-tasks-toolbar"><div><h3>All phase tasks</h3><p>${configuredTasks.length} tasks across ${activePhases.length} phases</p></div><div class="job-v4-task-actions-bar"><label class="job-v4-search"><span>⌕</span><input type="search" placeholder="Search tasks" data-job-v4-task-search></label><div class="job-v4-filter-wrap"><button type="button" class="ghost" data-job-v4-filter-toggle>☰ Filter</button><div class="job-v4-filter-popover is-hidden" data-job-v4-filter-popover><label>Status<select data-job-v4-status-filter><option value="">All statuses</option>${(masterData.taskStatuses || []).filter((item) => String(item.status || 'Active').toLowerCase() === 'active').map((item) => `<option value="${html(normalizedStatusValue(item.name))}">${html(item.name)}</option>`).join('')}</select></label><label>Assignee<select data-job-v4-assignee-filter><option value="">All assignees</option>${(typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : users).map((member) => `<option value="${html(normalizedStatusValue(member.name))}">${html(member.name)}</option>`).join('')}</select></label><button type="button" class="job-v4-link" data-job-v4-clear-filters>Clear filters</button></div></div><button type="button" class="ghost" data-job-v4-collapse-all>⌃ Collapse all</button><button type="button" class="primary" data-job-v4-expand-all>↗ Expand all</button></div></div>
                <div class="job-v4-task-meta"><span>ⓘ All ${configuredTasks.length} tasks are loaded</span><span data-job-v4-expanded-count>1 phase expanded</span><span>Changes save automatically</span></div>
                ${flowtrackJobV4PhaseAccordion(job, actualTasks, activePhases, currentPhase)}
            </section>
            ${flowtrackJobV4Attachments(job)}
            ${flowtrackJobV4Activity(job)}
        </section>`;
    }

    function flowtrackJobV5DateOnly(value) {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function flowtrackJobV5DaysBetween(startValue, endValue = null) {
        if (!startValue) return '—';
        const start = new Date(startValue);
        const end = endValue ? new Date(endValue) : new Date();
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return '—';
        const days = Math.max(0, Math.floor((end.getTime() - start.getTime()) / 86400000));
        return `${days} day${days === 1 ? '' : 's'}`;
    }

    function flowtrackJobV5HealthLabel(value) {
        const normalized = String(value || 'on_track').trim().toLowerCase();
        return normalized.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    }

    function flowtrackJobV5CurrentPhaseState(job, activePhases, currentPhase, actualTasks) {
        const history = flowtrackPhaseHistoryFor(job, currentPhase) || {};
        const rows = currentPhase ? flowtrackJobV4ConfiguredPhaseTasks(job, currentPhase, actualTasks) : [];
        const completed = rows.filter((task) => !task.planned && taskIsCompleted(task)).length;
        const total = rows.length;
        const progress = total ? Math.round((completed / total) * 100) : 0;
        const readiness = job.phaseReadinessDetails || history.readinessDetails || {};
        const taskGate = readiness.task_gate || {};
        const documentGate = readiness.document_gate || {};
        const documentCategories = flowtrackToArray(documentGate.categories);
        const missingDocuments = flowtrackToArray(documentGate.missing);
        const requiredDocuments = documentGate.required ? Math.max(1, documentCategories.length) : 0;
        const receivedDocuments = Math.max(0, requiredDocuments - missingDocuments.length);
        const blockingTask = rows.find((task) => !task.planned && (task.attention || task.blockerReason || /block|attention|hold/i.test(String(task.status || ''))));
        const firstOpenTask = blockingTask || rows.find((task) => !task.planned && !taskIsCompleted(task)) || null;
        const remaining = Math.max(0, total - completed);
        const blockerMessages = flowtrackToArray(readiness.blockers);
        const blockerCount = Math.max(0, (remaining > 0 ? 1 : 0) + missingDocuments.length + (readiness.has_blocking_task ? 1 : 0));
        const targetDate = history.targetDate || job.delivery || '';
        const phaseOwner = history.phaseOwner || job.coordinator || job.owner || 'Unassigned';
        const phaseOwnerId = history.phaseOwnerId || job.coordinatorId || job.ownerId || null;
        const automationMode = history.automationMode || (currentPhase?.autoAdvance ? 'automatic' : 'manual');
        const healthValue = history.healthOverride || String(job.health || 'On Track').toLowerCase().replaceAll(' ', '_');
        const currentIndex = activePhases.findIndex((phase) => Number(phase.id) === Number(currentPhase?.id));
        const nextPhase = currentIndex >= 0 ? activePhases[currentIndex + 1] || null : null;
        return { history, rows, completed, total, progress, readiness, taskGate, documentGate, requiredDocuments, receivedDocuments, missingDocuments, blockingTask, firstOpenTask, remaining, blockerMessages, blockerCount, targetDate, phaseOwner, phaseOwnerId, automationMode, healthValue, currentIndex, nextPhase };
    }

    function flowtrackJobV5Timeline(job, activePhases, currentPhase) {
        return `<div class="job-v5-timeline" style="grid-template-columns:repeat(${Math.max(1, activePhases.length)},minmax(72px,1fr))">${activePhases.map((phase, index) => {
            const isCurrent = Number(phase.id) === Number(currentPhase?.id);
            const isDone = currentPhase && Number(phase.sequence) < Number(currentPhase.sequence);
            const circle = isDone ? '✓' : Number(phase.sequence || index + 1);
            return `<div class="job-v5-timeline-step ${isDone ? 'is-done' : ''} ${isCurrent ? 'is-current' : ''}"><div class="job-v5-timeline-track"><span class="job-v5-timeline-dot">${circle}</span>${index < activePhases.length - 1 ? '<i></i>' : ''}</div><b>${html(phase.short || phase.name)}</b>${isCurrent ? '<small>Current</small>' : ''}</div>`;
        }).join('')}</div>`;
    }

    function flowtrackJobV5PhaseHistoryTable(job, activePhases, actualTasks, currentPhase) {
        return `<section class="job-v5-card job-v5-history"><div class="job-v5-card-title"><div><h3>Phase history</h3><p>One row per workflow phase; future phases remain planned.</p></div></div><table><thead><tr><th></th><th>Phase</th><th>Status</th><th>Entered</th><th>Completed</th><th>Time in phase</th><th>Outcome</th></tr></thead><tbody>${activePhases.map((phase, index) => {
            const history = flowtrackPhaseHistoryFor(job, phase) || {};
            const isCurrent = Number(phase.id) === Number(currentPhase?.id);
            const isPast = currentPhase ? Number(phase.sequence) < Number(currentPhase.sequence) : false;
            const skipped = String(history.status || '').toLowerCase() === 'skipped';
            const completed = String(history.status || '').toLowerCase() === 'completed' || (isPast && !skipped);
            const status = skipped ? 'Skipped' : isCurrent ? 'Current' : completed ? 'Completed' : 'Planned';
            const tone = skipped ? 'is-muted' : isCurrent ? 'is-current' : completed ? 'is-complete' : 'is-planned';
            const phaseRows = flowtrackJobV4ConfiguredPhaseTasks(job, phase, actualTasks);
            const doneCount = phaseRows.filter((task) => !task.planned && taskIsCompleted(task)).length;
            const outcome = skipped ? 'Skipped' : completed ? 'Passed' : isCurrent ? (job.phaseReadiness === 'ready' ? 'Ready' : `${Math.max(0, phaseRows.length - doneCount)} task${Math.max(0, phaseRows.length - doneCount) === 1 ? '' : 's'} remaining`) : '—';
            const entered = history.enteredAt ? flowtrackJobV5DateOnly(history.enteredAt) : '—';
            const completedAt = history.completedAt ? flowtrackJobV5DateOnly(history.completedAt) : '—';
            const duration = history.enteredAt ? (history.completedAt ? flowtrackJobV5DaysBetween(history.enteredAt, history.completedAt) : isCurrent ? flowtrackJobV5DaysBetween(history.enteredAt) : '—') : '—';
            return `<tr class="${isCurrent ? 'is-current-row' : ''}"><td>${Number(phase.sequence || index + 1)}</td><td><b>${html(phase.short || phase.name)}</b></td><td><span class="job-v5-status ${tone}">${html(status)}</span></td><td>${html(entered)}</td><td>${html(completedAt)}</td><td>${html(duration)}</td><td><span class="job-v5-outcome ${completed ? 'is-pass' : isCurrent && job.phaseReadiness !== 'ready' ? 'is-warning' : ''}">${html(outcome)}</span></td></tr>`;
        }).join('')}</tbody></table></section>`;
    }

    function flowtrackJobV5WorkflowPanel(job, actualTasks, activePhases, currentPhase) {
        const wf = flowtrackJobV4Workflow(job);
        const state = flowtrackJobV5CurrentPhaseState(job, activePhases, currentPhase, actualTasks);
        const enteredAt = state.history.enteredAt || job.createdAt || null;
        const taskGateMet = Boolean(state.taskGate.met) || (state.total > 0 && state.completed >= state.total);
        const documentGateMet = state.documentGate.required ? Boolean(state.documentGate.met) : true;
        const approvalGateMet = true;
        const blockingSummary = state.blockerMessages[0] || (state.remaining ? `Complete ${state.remaining} remaining phase task${state.remaining === 1 ? '' : 's'}.` : state.missingDocuments.length ? `Upload ${state.missingDocuments.length} required document${state.missingDocuments.length === 1 ? '' : 's'}.` : 'Resolve the current phase requirements.');
        const nextName = state.nextPhase?.name || 'Completed';
        const canEdit = canEditJobRecord();
        const healthLabel = flowtrackJobV5HealthLabel(state.healthValue);
        const healthTone = /block|risk|delay|attention/i.test(healthLabel) ? 'is-warning' : 'is-good';
        const phaseNumber = Number(currentPhase?.sequence || state.currentIndex + 1 || 1);
        const totalPhases = activePhases.length;
        return `<section id="detailPanel1" class="detail-panel job-v5-workflow">
            <div class="job-v5-workflow-head"><div><h3>Workflow</h3><p>${html(job.workflowName || wf.name || 'Workflow')} · Version ${html(wf.version || 1)}</p></div><button type="button" class="job-v5-link" data-job-v5-workflow-setup>View workflow setup ↗</button></div>
            ${job.phaseReadiness !== 'ready' && state.nextPhase ? `<div class="job-v5-blocker"><span>!</span><div><b>${Math.max(1, state.blockerCount)} ${Math.max(1, state.blockerCount) === 1 ? 'item blocks' : 'items block'} the next phase</b><p>${html(blockingSummary)}</p></div><button type="button" class="job-v5-link" data-job-v5-open-blocker>${state.firstOpenTask ? 'View blocking task' : 'View phase tasks'}</button></div>` : ''}
            ${flowtrackJobV5Timeline(job, activePhases, currentPhase)}
            <div class="job-v5-grid">
                <div class="job-v5-left">
                    <section class="job-v5-card job-v5-current-phase">
                        <div class="job-v5-current-head"><div><h3>Current phase · ${html(currentPhase?.name || phaseNameFor(job))}</h3><div class="job-v5-progress-line"><span>${state.completed} of ${state.total} tasks complete</span><i><b style="width:${state.progress}%"></b></i><strong>${state.progress}%</strong></div></div><span class="job-v5-phase-pill">Phase ${phaseNumber} of ${totalPhases}</span></div>
                        <div class="job-v5-phase-meta"><span class="avatar">${userInitials(state.phaseOwner)}</span><b>${html(state.phaseOwner)}</b><span>·</span><span>Entered ${html(flowtrackJobV5DateOnly(enteredAt))}</span><span>·</span><span>Target ${html(flowtrackJobV5DateOnly(state.targetDate))}</span><span>·</span><span>${html(flowtrackJobV5DaysBetween(enteredAt))} in phase</span><button type="button" class="job-v5-link" data-job-v5-view-phase-tasks>View ${state.total} phase tasks</button></div>
                        <div class="job-v5-gate-table">
                            <div class="job-v5-gate-row ${taskGateMet ? 'is-complete' : 'is-warning'}"><span class="job-v5-gate-icon">${taskGateMet ? '✓' : '!'}</span><b>1</b><span>Tasks</span><strong>${state.completed} of ${state.total} complete</strong><em>${state.remaining ? `${state.remaining} remaining` : 'Complete'}</em><button type="button" class="job-v5-link" data-job-v5-view-phase-tasks>${state.firstOpenTask ? 'Open task' : 'View tasks'}</button></div>
                            <div class="job-v5-gate-row ${documentGateMet ? 'is-complete' : 'is-warning'}"><span class="job-v5-gate-icon">${documentGateMet ? '✓' : '!'}</span><b>2</b><span>Required documents</span><strong>${state.requiredDocuments ? `${state.receivedDocuments} of ${state.requiredDocuments} received` : 'Not required'}</strong><em>${documentGateMet ? 'Complete' : `${state.missingDocuments.length} missing`}</em><button type="button" class="job-v5-link" data-job-v5-view-documents>View documents</button></div>
                            <div class="job-v5-gate-row is-complete"><span class="job-v5-gate-icon">✓</span><b>3</b><span>Approvals</span><strong>Not required</strong><em>Complete</em><button type="button" class="job-v5-link" data-job-v5-view-approvals>View approvals</button></div>
                            <div class="job-v5-gate-row ${state.readiness.has_blocking_task ? 'is-warning' : 'is-complete'}"><span class="job-v5-gate-icon">${state.readiness.has_blocking_task ? '!' : '✓'}</span><b>4</b><span>Other blockers</span><strong>${state.readiness.has_blocking_task ? 'Active blocker' : 'None'}</strong><em>${state.readiness.has_blocking_task ? 'Review' : 'Clear'}</em><button type="button" class="job-v5-link" data-job-v5-view-blockers>View details</button></div>
                        </div>
                        ${state.nextPhase ? `<div class="job-v5-next-phase"><span class="job-v5-lock">▣</span><div><b>Next phase: ${html(nextName)}</b><p>${job.phaseReadiness === 'ready' ? 'All transition requirements are satisfied.' : html(blockingSummary)}</p></div>${job.phaseReadiness === 'ready' ? '' : '<button type="button" class="job-v5-outline" data-job-v5-open-blocker>Open blocking task</button>'}<button type="button" class="job-v5-move" data-job-v5-move-next ${job.phaseReadiness === 'ready' ? '' : 'disabled'}>Move to ${html(nextName)}</button><button type="button" class="job-v5-more" data-job-v5-transition-rules>•••</button></div>` : `<div class="job-v5-next-phase"><span class="job-v5-lock is-done">✓</span><div><b>Workflow completed</b><p>This is the final active workflow phase.</p></div></div>`}
                    </section>
                    ${flowtrackJobV5PhaseHistoryTable(job, activePhases, actualTasks, currentPhase)}
                </div>
                <aside class="job-v5-right">
                    <section class="job-v5-card job-v5-controls"><h3>Phase controls</h3>
                        <div class="job-v5-control-row"><span>Phase owner</span><b><span class="avatar">${userInitials(state.phaseOwner)}</span>${html(state.phaseOwner)}</b>${canEdit ? '<button type="button" data-job-v5-edit-control="owner">✎</button>' : '<span></span>'}</div>
                        <div class="job-v5-control-row"><span>Target date</span><b>▣ ${html(flowtrackJobV5DateOnly(state.targetDate))}</b>${canEdit ? '<button type="button" data-job-v5-edit-control="target">✎</button>' : '<span></span>'}</div>
                        <div class="job-v5-control-row"><span>Health</span><b><i class="job-v5-health-dot ${healthTone}"></i><span class="job-v5-health-pill ${healthTone}">${html(healthLabel)}</span></b>${canEdit ? '<button type="button" data-job-v5-edit-control="health">✎</button>' : '<span></span>'}</div>
                        <div class="job-v5-control-row"><span>Automation</span><b>${state.automationMode === 'automatic' ? 'Automatic' : 'Manual'}</b>${canEdit ? '<button type="button" data-job-v5-edit-control="automation">✎</button>' : '<span></span>'}</div>
                        <div class="job-v5-transition-policy"><h4>Transition policy</h4><p class="${taskGateMet ? 'is-pass' : 'is-warn'}"><span>${taskGateMet ? '✓' : '!'}</span> All phase tasks complete</p><p class="${documentGateMet ? 'is-pass' : 'is-warn'}"><span>${documentGateMet ? '✓' : '!'}</span> Required documents received</p><p class="${approvalGateMet ? 'is-pass' : 'is-warn'}"><span>${approvalGateMet ? '✓' : '!'}</span> Required approvals completed</p><button type="button" class="job-v5-link" data-job-v5-transition-rules>View transition rules</button></div>
                    </section>
                    <section class="job-v5-card job-v5-details"><h3>Workflow details</h3><dl><dt>Template</dt><dd>${html(job.workflowName || wf.name || 'Workflow')}</dd><dt>Version</dt><dd>${html(wf.version || 1)}</dd><dt>Started at</dt><dd>${html(activePhases[Math.max(0, Number(job.createdFromPhase || 0))]?.name || activePhases[0]?.name || '—')}</dd><dt>Started</dt><dd>${html(flowtrackJobV5DateOnly(job.createdAt))}</dd></dl><button type="button" class="job-v5-link" data-job-v5-workflow-setup>Open workflow configuration ↗</button></section>
                </aside>
            </div>
        </section>`;
    }

    function flowtrackJobV4Panels(job) {
        const actualTasks = flowtrackJobAllTasks(job);
        const allDocs = jobDocsFor(job);
        const activePhases = flowtrackActivePhases(job);
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(job.phaseId)) || activePhases[Math.max(0, Number(job.phase || 0))] || activePhases[0] || null;
        const currentTasks = currentPhase ? flowtrackTasksForPhase(job, currentPhase.id, actualTasks) : [];
        const requirements = flowtrackJobPhaseDocumentRequirements(job, activePhases);
        const wf = flowtrackJobV4Workflow(job);
        const commercialStatus = typeof boardJobCommercialLabel === 'function' ? boardJobCommercialLabel(job) : (job.price ? 'Invoice pending' : 'Quotation pending');
        return `${flowtrackJobV4Overview(job, actualTasks, allDocs, activePhases, currentPhase)}
            ${flowtrackJobV5WorkflowPanel(job, actualTasks, activePhases, currentPhase)}
            <section id="detailPanel7" class="detail-panel job-v3-commercial"><div class="job-v3-panel-card"><div class="job-v3-panel-head"><div><h3>Commercial</h3><p>Quotation, invoice and payment status.</p></div></div><div class="job-v3-metric-grid">${flowtrackJobMetricCard('$', 'Commercial value', `<span>${job.price ? currency(job.price, job.currency) : 'Not recorded'}</span>`, html(commercialStatus))}${flowtrackJobMetricCard('▣', 'Client', `<span>${html(clientName(job.client))}</span>`, html(job.order || job.id))}${flowtrackJobMetricCard('○', 'Invoice', `<span>${html(commercialStatus)}</span>`, `${allDocs.filter((doc) => documentMatchesKeywords(doc, ['invoice', 'payment', 'receipt'])).length} commercial documents`)}</div><div style="margin-top:16px">${documentPanelFor(job, 'Invoice Documents', ['invoice', 'payment', 'receipt', 'commercial'])}</div></div></section>
            ${flowtrackDocumentsPanel(job, allDocs, activePhases)}`;
    }

    async function flowtrackJobV4SaveJob(job, patch, successMessage = 'Job updated.') {
        const current = flowtrackFindJob(job.id || job.dbId) || job;
        const products = (patch.products || flowtrackToArray(current.products).map((item) => ({
            product_category_id: Number(item.categoryId || 0),
            product_id: Number(item.productId || 0),
            quantity: Math.max(1, Number(item.qty || 1)),
        }))).filter((item) => item.product_category_id && item.product_id);
        if (!products.length && current.productId && current.categoryId) {
            products.push({ product_category_id: Number(current.categoryId), product_id: Number(current.productId), quantity: Math.max(1, Number(current.qty || 1)) });
        }
        const body = {
            client_id: Number(current.client),
            products,
            priority_id: patch.priority_id !== undefined ? patch.priority_id : (current.priorityId ? Number(current.priorityId) : null),
            owner_id: patch.owner_id !== undefined ? patch.owner_id : (current.ownerId ? Number(current.ownerId) : null),
            coordinator_id: patch.coordinator_id !== undefined ? patch.coordinator_id : (current.coordinatorId ? Number(current.coordinatorId) : null),
            title: patch.title !== undefined ? patch.title : current.title,
            request_description: patch.request_description !== undefined ? patch.request_description : (current.requestDescription || null),
            delivery_date: patch.delivery_date !== undefined ? patch.delivery_date : (current.delivery || null),
        };
        if (current.price !== null && current.price !== undefined) {
            body.quoted_amount = Number(current.price || 0);
            body.currency_id = current.currencyId ? Number(current.currencyId) : null;
        }
        const result = await api(`/flowtrack/template-api/jobs/${current.dbId}`, { method: 'PUT', body });
        if (result.job) flowtrackUpsertRecord(jobs, result.job);
        const updated = flowtrackFindJob(result.job?.id || current.id) || result.job || current;
        flowtrackEnsureJobPanels(updated, 0);
        flowtrackJobV4Apply(updated, 0);
        updateBadges();
        showToast(successMessage);
        return updated;
    }

    async function flowtrackJobV4EnsureActualTask(job, phaseId, packItemId) {
        let task = flowtrackTasksForJob(job).find((item) => Number(item.phaseId) === Number(phaseId) && Number(item.taskPackItemId) === Number(packItemId));
        if (task) return task;
        const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/phases/${phaseId}/tasks/sync`, { method: 'POST', body: {} });
        if (result.job) flowtrackUpsertRecord(jobs, result.job);
        if (result.job && Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
        task = flowtrackTasksForJob(flowtrackFindJob(job.id) || job).find((item) => Number(item.phaseId) === Number(phaseId) && Number(item.taskPackItemId) === Number(packItemId));
        if (!task) throw new Error('This Task Setup item could not be activated for the Job.');
        return task;
    }

    async function flowtrackJobV4UpdateTask(job, row, patch) {
        const planned = row.dataset.planned === '1';
        let task = planned
            ? await flowtrackJobV4EnsureActualTask(job, Number(row.dataset.phaseId), Number(row.dataset.packItemId))
            : tasks.find((item) => Number(item.dbId) === Number(row.dataset.taskDbId) || String(item.id) === String(row.dataset.taskId));
        if (!task) throw new Error('Task could not be loaded.');
        task = flowtrackHydrateTaskAssignee(task);
        const statusId = patch.task_status_id !== undefined ? Number(patch.task_status_id) : Number(task.statusId || 0);
        if (!statusId) throw new Error('Configure an active Task Status before editing tasks.');
        const body = {
            task_status_id: statusId,
            assignee_id: patch.assignee_id !== undefined ? patch.assignee_id : (task.assigneeId ? Number(task.assigneeId) : null),
            start_date: task.startDate || null,
            due_date: patch.due_date !== undefined ? patch.due_date : (task.due || null),
            priority_id: task.priorityId ? Number(task.priorityId) : null,
            attention_required: Boolean(task.attention),
            blocker_reason: task.blockerReason || null,
        };
        const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'PUT', body });
        updateTaskAndJob(result);
        const updatedJob = flowtrackFindJob(result.job?.id || job.id) || job;
        flowtrackEnsureJobPanels(updatedJob, 0);
        flowtrackJobV4Apply(updatedJob, 0);
        updateBadges();
        return result.task;
    }

    async function flowtrackJobV5SavePhaseControls(job, patch, successMessage = 'Phase controls updated.') {
        const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/phase-controls`, { method: 'PATCH', body: patch });
        if (result.job) flowtrackUpsertRecord(jobs, result.job);
        const updated = flowtrackFindJob(result.job?.id || job.id) || result.job || job;
        flowtrackEnsureJobPanels(updated, 1);
        flowtrackJobV4Apply(updated, 1);
        updateBadges();
        showToast(successMessage);
        return updated;
    }

    function flowtrackJobV5OpenWorkflowSetup(job) {
        const workflowId = Number(job?.workflow || 0);
        if (!workflowId) return showToast('Workflow configuration could not be resolved.');
        window.location.href = `/flowtrack/view/workflows/${encodeURIComponent(workflowId)}/edit`;
    }

    function flowtrackJobV5BindWorkflow(content, job) {
        const activePhases = flowtrackActivePhases(job);
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(job.phaseId)) || activePhases[Math.max(0, Number(job.phase || 0))] || activePhases[0] || null;
        if (!currentPhase) return;
        const actualTasks = flowtrackJobAllTasks(job);
        const state = flowtrackJobV5CurrentPhaseState(job, activePhases, currentPhase, actualTasks);
        const history = state.history || {};

        content.querySelectorAll('[data-job-v5-workflow-setup],[data-job-v5-transition-rules]').forEach((button) => button.addEventListener('click', () => flowtrackJobV5OpenWorkflowSetup(job)));

        const showPhaseTasks = () => {
            activateScopedDetailTab(content, 0);
            window.setTimeout(() => content.querySelector('.job-v4-all-tasks')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
        };
        content.querySelectorAll('[data-job-v5-view-phase-tasks]').forEach((button) => button.addEventListener('click', () => {
            if (/open task/i.test(button.textContent || '') && state.firstOpenTask?.id) void openTask(state.firstOpenTask.id);
            else showPhaseTasks();
        }));
        content.querySelector('[data-job-v5-view-documents]')?.addEventListener('click', () => activateScopedDetailTab(content, 8));
        content.querySelector('[data-job-v5-view-approvals]')?.addEventListener('click', () => showToast('This workflow does not require a separate approval operation.'));
        content.querySelector('[data-job-v5-view-blockers]')?.addEventListener('click', () => showToast(state.blockerMessages.join(' ') || (state.readiness.has_blocking_task ? 'A task blocker is active in this phase.' : 'No active blockers.')));
        content.querySelectorAll('[data-job-v5-open-blocker]').forEach((button) => button.addEventListener('click', () => {
            if (state.firstOpenTask?.id) void openTask(state.firstOpenTask.id); else showPhaseTasks();
        }));

        content.querySelectorAll('[data-job-v5-edit-control]').forEach((button) => button.addEventListener('click', () => {
            const field = button.dataset.jobV5EditControl;
            const row = button.closest('.job-v5-control-row');
            if (!row) return;
            if (field === 'owner') {
                const members = typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : users;
                const selected = Number(history.phaseOwnerId || job.coordinatorId || job.ownerId || 0);
                const options = `<option value="">Unassigned</option>${members.map((member) => `<option value="${html(member.id)}" ${Number(member.id) === selected ? 'selected' : ''}>${html(member.name)}</option>`).join('')}`;
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value data-searchable>${options}</select>`, async (target) => flowtrackJobV5SavePhaseControls(job, { phase_owner_id: Number(target.querySelector('[data-job-v4-inline-value]')?.value || 0) || null }, 'Phase owner updated.'));
            } else if (field === 'target') {
                flowtrackJobV4InlineEditor(row, `<input type="date" value="${html(history.targetDate || job.delivery || '')}" data-job-v4-inline-value>`, async (target) => flowtrackJobV5SavePhaseControls(job, { target_date: target.querySelector('[data-job-v4-inline-value]')?.value || null }, 'Phase target date updated.'));
            } else if (field === 'health') {
                const selected = String(history.healthOverride || '');
                const options = [['', 'Automatic (derived)'], ['on_track', 'On Track'], ['needs_attention', 'Needs Attention'], ['at_risk', 'At Risk'], ['delayed', 'Delayed'], ['blocked', 'Blocked']].map(([value, label]) => `<option value="${value}" ${value === selected ? 'selected' : ''}>${label}</option>`).join('');
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value>${options}</select>`, async (target) => flowtrackJobV5SavePhaseControls(job, { health_override: target.querySelector('[data-job-v4-inline-value]')?.value || null }, 'Phase health updated.'));
            } else if (field === 'automation') {
                const selected = String(history.automationMode || '');
                const defaultLabel = currentPhase.autoAdvance ? 'Workflow default (Automatic)' : 'Workflow default (Manual)';
                const options = `<option value="" ${selected === '' ? 'selected' : ''}>${defaultLabel}</option><option value="manual" ${selected === 'manual' ? 'selected' : ''}>Manual</option><option value="automatic" ${selected === 'automatic' ? 'selected' : ''}>Automatic</option>`;
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value>${options}</select>`, async (target) => flowtrackJobV5SavePhaseControls(job, { automation_mode: target.querySelector('[data-job-v4-inline-value]')?.value || null }, 'Phase automation updated.'));
            }
        }));

        content.querySelector('[data-job-v5-move-next]')?.addEventListener('click', async (event) => {
            if (!state.nextPhase || job.phaseReadiness !== 'ready') return;
            const button = event.currentTarget;
            button.disabled = true;
            try {
                const result = await moveJobToPhase(job, state.nextPhase);
                const updated = flowtrackFindJob(result.job?.id || job.id) || result.job;
                flowtrackEnsureJobPanels(updated, 1);
                flowtrackJobV4Apply(updated, 1);
                updateBadges();
                showToast(`${state.nextPhase.name} activated.`);
            } catch (error) {
                button.disabled = false;
                showToast(error.message || 'The phase could not be moved.');
            }
        });
    }

    function flowtrackJobV4InlineEditor(target, editorHtml, onSave) {
        if (!target || target.dataset.editing === '1') return;
        target.dataset.editing = '1';
        const original = target.innerHTML;
        target.innerHTML = `<div class="job-v4-inline-editor">${editorHtml}<div class="job-v4-inline-actions"><button type="button" class="job-v4-link" data-job-v4-inline-save>Save</button><button type="button" class="job-v4-link muted" data-job-v4-inline-cancel>Cancel</button></div></div>`;
        target.querySelector('[data-job-v4-inline-cancel]')?.addEventListener('click', () => { target.innerHTML = original; target.dataset.editing = '0'; });
        target.querySelector('[data-job-v4-inline-save]')?.addEventListener('click', async (event) => {
            event.currentTarget.disabled = true;
            try { await onSave(target); } catch (error) { event.currentTarget.disabled = false; showToast(error.message || 'Update failed.'); }
        });
        window.FlowtrackSearchableDropdown?.enhance(target);
    }

    function flowtrackJobV4ProductEditor(job, index = null) {
        const card = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .job-v4-overview-card`);
        if (!card || card.querySelector('.job-v4-product-editor')) return;
        const currentProducts = flowtrackToArray(job.products).map((item) => ({ product_category_id: Number(item.categoryId), product_id: Number(item.productId), quantity: Number(item.qty || 1) }));
        const editingIndex = index === null ? currentProducts.length : Number(index);
        const existing = currentProducts[editingIndex] || { product_category_id: '', product_id: '', quantity: 1 };
        const categories = activeMaster('productCategories');
        const productOptions = (categoryId, selected) => activeMaster('products').filter((product) => Number(product.parent_id) === Number(categoryId)).map((product) => `<option value="${html(product.id)}" ${Number(product.id) === Number(selected) ? 'selected' : ''}>${html(product.code)} · ${html(product.name)}</option>`).join('');
        const editor = document.createElement('div');
        editor.className = 'job-v4-product-editor';
        editor.innerHTML = `<select data-job-v4-product-category data-searchable><option value="">Select category</option>${categories.map((category) => `<option value="${html(category.id)}" ${Number(category.id) === Number(existing.product_category_id) ? 'selected' : ''}>${html(category.name)}</option>`).join('')}</select><select data-job-v4-product-select data-searchable><option value="">Select product</option>${productOptions(existing.product_category_id, existing.product_id)}</select><input type="number" min="1" max="1000000000" value="${Math.max(1, Number(existing.quantity || 1))}" data-job-v4-product-qty><button type="button" class="primary" data-job-v4-product-save>Save</button><button type="button" class="ghost" data-job-v4-product-cancel>Cancel</button>`;
        card.appendChild(editor);
        window.FlowtrackSearchableDropdown?.enhance(editor);
        const categorySelect = editor.querySelector('[data-job-v4-product-category]');
        categorySelect?.addEventListener('change', () => {
            const productSelect = editor.querySelector('[data-job-v4-product-select]');
            productSelect.innerHTML = `<option value="">Select product</option>${productOptions(categorySelect.value, '')}`;
            window.FlowtrackSearchableDropdown?.refresh(productSelect);
            window.FlowtrackSearchableDropdown?.enhance(editor);
        });
        editor.querySelector('[data-job-v4-product-cancel]')?.addEventListener('click', () => editor.remove());
        editor.querySelector('[data-job-v4-product-save]')?.addEventListener('click', async (event) => {
            const categoryId = Number(editor.querySelector('[data-job-v4-product-category]')?.value || 0);
            const productId = Number(editor.querySelector('[data-job-v4-product-select]')?.value || 0);
            const quantity = Math.max(1, Number(editor.querySelector('[data-job-v4-product-qty]')?.value || 1));
            if (!categoryId || !productId) return showToast('Select a category and product.');
            const nextProducts = currentProducts.slice();
            const next = { product_category_id: categoryId, product_id: productId, quantity };
            if (editingIndex >= nextProducts.length) nextProducts.push(next); else nextProducts[editingIndex] = next;
            if (new Set(nextProducts.map((item) => Number(item.product_id))).size !== nextProducts.length) return showToast('The same product cannot be added more than once.');
            event.currentTarget.disabled = true;
            try { await flowtrackJobV4SaveJob(job, { products: nextProducts }, 'Products updated.'); } catch (error) { event.currentTarget.disabled = false; showToast(error.message || 'Products could not be updated.'); }
        });
    }

    function flowtrackJobV4ApplyActivityFilter(content, tab = 'comments') {
        const normalized = ['comments', 'history', 'worklog'].includes(tab) ? tab : 'comments';
        content.querySelectorAll('[data-job-v4-activity-tab]').forEach((button) => button.classList.toggle('is-active', button.dataset.jobV4ActivityTab === normalized));
        let visible = 0;
        const wantedKind = normalized === 'comments' ? 'comment' : (normalized === 'history' ? 'history' : 'worklog');
        content.querySelectorAll('[data-job-v4-activity-kind]').forEach((row) => {
            const hide = row.dataset.jobV4ActivityKind !== wantedKind;
            row.classList.toggle('is-hidden', hide);
            if (!hide) visible++;
        });
        const compose = content.querySelector('[data-job-v4-comment-compose]');
        compose?.classList.toggle('is-hidden', normalized !== 'comments');
        const empty = content.querySelector('[data-job-v4-activity-empty]');
        if (empty) {
            empty.classList.toggle('is-hidden', visible > 0);
            empty.textContent = normalized === 'comments' ? 'No comments yet' : normalized === 'history' ? 'No job history yet' : 'No work log entries yet';
        }
    }

    function flowtrackJobV4Apply(job, preferredTab = 0) {
        const page = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .standalone-record-page`);
        const content = page?.querySelector('.standalone-record-content');
        if (!page || !content) return;
        page.classList.add('job-detail-v4-page');
        content.classList.add('job-detail-v4-content');
        const allTasks = flowtrackJobAllTasks(job);
        const allDocs = jobDocsFor(job);
        const team = flowtrackJobTeamMembers(job, allTasks);
        const header = content.querySelector('.drawer-head');
        if (header) {
            header.className = 'drawer-head job-v4-header';
            header.innerHTML = `<div class="job-v4-head-main"><div class="job-v4-breadcrumb">Jobs / ${html(job.id)}</div><div class="job-v4-title-row"><h2 data-job-v4-title-text>${html(job.title || job.id)}</h2>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn job-v4-title-edit" data-job-v4-edit-title>✎</button>' : ''}</div><div class="job-v4-head-meta"><span>${html(clientName(job.client))}</span>${flowtrackJobHealthPill(job)}<span class="badge b-red">${html(job.priority || 'Medium')}</span><span class="badge b-purple">${html(phaseNameFor(job))}</span><button type="button" class="job-v4-code job-v4-code-copy" data-job-v4-copy-id="${html(job.id)}" title="Copy Job ID" aria-label="Copy Job ID ${html(job.id)}"><span>${html(job.id)}</span><span class="job-v4-copy-icon" aria-hidden="true">⧉</span></button></div></div><div class="job-v4-team">${team.slice(0, 4).map((member) => `<span class="avatar" title="${html(member.name)}">${html(member.initials)}</span>`).join('')}${team.length > 4 ? `<span class="job-v4-more">+${team.length - 4}</span>` : ''}</div><button type="button" class="close-btn" data-standalone-back aria-label="Close">×</button>`;
        }
        const tabs = content.querySelector('.detail-tabs');
        if (tabs) tabs.innerHTML = `<button class="detail-tab active" data-detailtab="0">Overview</button><button class="detail-tab" data-detailtab="1">Workflow</button><button class="detail-tab" data-detailtab="7">Commercial</button><button class="detail-tab" data-detailtab="8">Documents <span>${allDocs.length}</span></button>`;
        const panels = content.querySelector('#jobDetailPanels');
        if (panels) panels.innerHTML = flowtrackJobV4Panels(job);
        bindStandaloneBack(page);
        bindStandaloneDetailTabs(content);
        activateScopedDetailTab(content, preferredTab ?? 0);

        header?.querySelector('[data-job-v4-copy-id]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const jobId = String(button?.dataset?.jobV4CopyId || job.id || '').trim();
            if (!jobId) return showToast('Job ID could not be copied.');
            let copied = false;
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(jobId);
                    copied = true;
                }
            } catch (_) {}
            if (!copied) {
                const textarea = document.createElement('textarea');
                textarea.value = jobId;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                textarea.style.pointerEvents = 'none';
                document.body.appendChild(textarea);
                textarea.select();
                try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
                textarea.remove();
            }
            showToast(copied ? `${jobId} copied.` : 'Copy failed. Select the Job ID and copy it manually.');
        });

        header?.querySelector('[data-job-v4-edit-title]')?.addEventListener('click', () => {
            const row = header.querySelector('.job-v4-title-row');
            flowtrackJobV4InlineEditor(row, `<input type="text" maxlength="255" value="${html(job.title || '')}" data-job-v4-inline-value>`, async (target) => {
                const value = target.querySelector('[data-job-v4-inline-value]')?.value.trim();
                if (!value) throw new Error('Job title is required.');
                await flowtrackJobV4SaveJob(job, { title: value }, 'Job title updated.');
            });
        });

        content.querySelector('[data-job-v4-edit-description]')?.addEventListener('click', () => {
            const row = content.querySelector('.job-v4-description-row');
            flowtrackJobV4InlineEditor(row, `<textarea rows="2" maxlength="10000" data-job-v4-inline-value>${html(job.requestDescription || '')}</textarea>`, async (target) => {
                await flowtrackJobV4SaveJob(job, { request_description: target.querySelector('[data-job-v4-inline-value]')?.value.trim() || null }, 'Job overview updated.');
            });
        });
        content.querySelectorAll('[data-job-v4-edit-product]').forEach((button) => button.addEventListener('click', () => flowtrackJobV4ProductEditor(job, Number(button.dataset.jobV4EditProduct))));
        content.querySelector('[data-job-v4-add-product]')?.addEventListener('click', () => flowtrackJobV4ProductEditor(job, null));

        content.querySelectorAll('[data-job-v4-edit-field]').forEach((button) => button.addEventListener('click', () => {
            const field = button.dataset.jobV4EditField;
            const row = button.closest('.job-v4-plan-row');
            if (!row) return;
            if (field === 'delivery') {
                flowtrackJobV4InlineEditor(row, `<input type="date" value="${html(job.delivery || '')}" data-job-v4-inline-value>`, async (target) => flowtrackJobV4SaveJob(job, { delivery_date: target.querySelector('[data-job-v4-inline-value]')?.value || null }, 'Delivery date updated.'));
            } else if (field === 'priority') {
                const options = activeMaster('priorities').map((item) => `<option value="${html(item.id)}" ${Number(item.id) === Number(job.priorityId) ? 'selected' : ''}>${html(item.name)}</option>`).join('');
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value data-searchable>${options}</select>`, async (target) => flowtrackJobV4SaveJob(job, { priority_id: Number(target.querySelector('[data-job-v4-inline-value]')?.value || 0) || null }, 'Priority updated.'));
            } else if (field === 'owner' || field === 'coordinator') {
                const selected = field === 'owner' ? job.ownerId : job.coordinatorId;
                const members = typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : users;
                const options = members.map((member) => `<option value="${html(member.id)}" ${Number(member.id) === Number(selected) ? 'selected' : ''}>${html(member.name)}</option>`).join('');
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value data-searchable>${options}</select>`, async (target) => flowtrackJobV4SaveJob(job, { [field === 'owner' ? 'owner_id' : 'coordinator_id']: Number(target.querySelector('[data-job-v4-inline-value]')?.value || 0) || null }, `${field === 'owner' ? 'Job owner' : 'Coordinator'} updated.`));
            }
        }));

        flowtrackJobV5BindWorkflow(content, job);

        const updateExpandedCount = () => {
            const count = [...content.querySelectorAll('.job-v4-phase-body')].filter((body) => !body.classList.contains('is-collapsed') && !body.closest('.job-v4-phase')?.classList.contains('is-filtered-out')).length;
            const label = content.querySelector('[data-job-v4-expanded-count]');
            if (label) label.textContent = `${count} phase${count === 1 ? '' : 's'} expanded`;
        };
        content.querySelectorAll('[data-job-v4-toggle-phase]').forEach((button) => button.addEventListener('click', () => {
            const body = button.closest('.job-v4-phase')?.querySelector('.job-v4-phase-body');
            body?.classList.toggle('is-collapsed');
            const caret = button.querySelector('.job-v4-caret');
            if (caret) caret.textContent = body?.classList.contains('is-collapsed') ? '›' : '⌄';
            updateExpandedCount();
        }));
        content.querySelector('[data-job-v4-expand-all]')?.addEventListener('click', () => {
            content.querySelectorAll('.job-v4-phase-body').forEach((body) => body.classList.remove('is-collapsed'));
            content.querySelectorAll('.job-v4-caret').forEach((caret) => { caret.textContent = '⌄'; });
            updateExpandedCount();
        });
        content.querySelector('[data-job-v4-collapse-all]')?.addEventListener('click', () => {
            content.querySelectorAll('.job-v4-phase-body').forEach((body) => body.classList.add('is-collapsed'));
            content.querySelectorAll('.job-v4-caret').forEach((caret) => { caret.textContent = '›'; });
            updateExpandedCount();
        });
        content.querySelectorAll('[data-job-v4-add-task]').forEach((button) => button.addEventListener('click', () => openJobTaskModal(job.id, Number(button.dataset.jobV4AddTask))));

        const applyTaskFilters = () => {
            const term = normalizedStatusValue(content.querySelector('[data-job-v4-task-search]')?.value || '');
            const status = normalizedStatusValue(content.querySelector('[data-job-v4-status-filter]')?.value || '');
            const assignee = normalizedStatusValue(content.querySelector('[data-job-v4-assignee-filter]')?.value || '');
            content.querySelectorAll('[data-job-v4-phase]').forEach((phase) => {
                let visible = 0;
                phase.querySelectorAll('[data-job-v4-task-row]').forEach((row) => {
                    const matches = (!term || normalizedStatusValue(row.textContent).includes(term)) && (!status || row.dataset.status === status) && (!assignee || row.dataset.assignee === assignee);
                    row.classList.toggle('is-filtered-out', !matches);
                    if (matches) visible++;
                });
                phase.classList.toggle('is-filtered-out', visible === 0);
            });
            updateExpandedCount();
        };
        content.querySelector('[data-job-v4-task-search]')?.addEventListener('input', applyTaskFilters);
        content.querySelector('[data-job-v4-status-filter]')?.addEventListener('change', applyTaskFilters);
        content.querySelector('[data-job-v4-assignee-filter]')?.addEventListener('change', applyTaskFilters);
        content.querySelector('[data-job-v4-filter-toggle]')?.addEventListener('click', (event) => { event.stopPropagation(); content.querySelector('[data-job-v4-filter-popover]')?.classList.toggle('is-hidden'); });
        content.querySelector('[data-job-v4-clear-filters]')?.addEventListener('click', () => {
            const search = content.querySelector('[data-job-v4-task-search]'); const status = content.querySelector('[data-job-v4-status-filter]'); const assignee = content.querySelector('[data-job-v4-assignee-filter]');
            if (search) search.value = ''; if (status) status.value = ''; if (assignee) assignee.value = ''; applyTaskFilters();
        });

        content.querySelectorAll('[data-job-v4-open-task]').forEach((button) => button.addEventListener('click', () => {
            const row = button.closest('[data-job-v4-task-row]');
            if (!row || row.dataset.planned === '1') return;
            void openTask(row.dataset.taskId);
        }));
        const closeAssigneeEditor = (select) => {
            if (!select || select.disabled) return;
            const cell = select.closest('.job-v4-assignee-cell');
            select.remove();
            cell?.classList.remove('is-editing');
        };
        const bindAssigneeEditor = (select) => {
            if (!select || select.dataset.boundAssigneeEditor === '1') return;
            select.dataset.boundAssigneeEditor = '1';
            select.addEventListener('blur', () => window.setTimeout(() => {
                if ((select.value || '') === (select.dataset.previousValue || '')) closeAssigneeEditor(select);
            }, 0));
            select.addEventListener('change', async () => {
                const row = select.closest('[data-job-v4-task-row]');
                const previous = select.dataset.previousValue ?? '';
                select.disabled = true;
                try {
                    await flowtrackJobV4UpdateTask(job, row, { assignee_id: select.value ? Number(select.value) : null });
                    showToast('Task assignee updated.');
                } catch (error) {
                    select.value = previous;
                    select.disabled = false;
                    closeAssigneeEditor(select);
                    showToast(error.message || 'Assignee could not be updated.');
                }
            });
        };
        content.querySelectorAll('[data-job-v4-edit-assignee]').forEach((button) => button.addEventListener('click', () => {
            const cell = button.closest('.job-v4-assignee-cell');
            const row = button.closest('[data-job-v4-task-row]');
            if (!cell || !row || cell.querySelector('[data-job-v4-task-assignee]')) return;
            const select = document.createElement('select');
            select.className = 'job-v4-task-inline-select job-v4-assignee-editor';
            select.setAttribute('data-job-v4-task-assignee', '');
            // This editor is intentionally a single native select. The global searchable-select
            // enhancer wraps dynamic selects, which previously left orphan wrappers behind after
            // blur and made every click appear to add another assignee dropdown.
            select.setAttribute('data-native-select', '');
            select.setAttribute('aria-label', 'Task assignee');
            const currentAssigneeId = row.dataset.assigneeId || '';
            select.innerHTML = flowtrackJobV4AssigneeOptions({ assigneeId: currentAssigneeId ? Number(currentAssigneeId) : null });
            select.value = currentAssigneeId;
            select.dataset.previousValue = currentAssigneeId;
            cell.appendChild(select);
            cell.classList.add('is-editing');
            bindAssigneeEditor(select);
            window.requestAnimationFrame(() => {
                select.focus();
                if (typeof select.showPicker === 'function') {
                    try { select.showPicker(); } catch (_) {}
                }
            });
        }));
        content.querySelectorAll('[data-job-v4-task-status]').forEach((select) => select.addEventListener('change', async () => {
            const row = select.closest('[data-job-v4-task-row]');
            const previousValue = select.dataset.previousValue || '';
            let task = row?.dataset.planned === '1'
                ? flowtrackJobV4ConfiguredPhaseTasks(job, flowtrackActivePhases(job).find((phase) => Number(phase.id) === Number(row.dataset.phaseId)) || { id: Number(row.dataset.phaseId), sequence: 0, name: '' }, flowtrackJobAllTasks(job)).find((item) => String(item.id) === String(row.dataset.taskId) || Number(item.taskPackItemId || 0) === Number(row.dataset.packItemId || 0))
                : tasks.find((item) => Number(item.dbId) === Number(row?.dataset.taskDbId) || String(item.id) === String(row?.dataset.taskId));
            const requirement = flowtrackJobV4TaskDocumentRequirement(task, Number(row?.dataset.phaseId || 0), Number(row?.dataset.packItemId || 0));
            if (flowtrackJobV4StatusIsComplete(select.value) && requirement && !flowtrackJobV4TaskHasRequiredEvidence(job, task, requirement)) {
                select.value = previousValue;
                showToast(`Upload or provide the required ${requirement.categoryName || 'document'} before completing this task.`);
                if (task && !task.planned && task.id) window.setTimeout(() => void openTask(task.id), 120);
                return;
            }
            select.disabled = true;
            try {
                await flowtrackJobV4UpdateTask(job, row, { task_status_id: Number(select.value) });
                select.dataset.previousValue = select.value;
                showToast('Task status updated.');
            } catch (error) {
                select.disabled = false;
                select.value = previousValue;
                showToast(error.message || 'Status could not be updated. Open the task if a blocker reason is required.');
            }
        }));
        content.querySelectorAll('[data-job-v4-edit-due]').forEach((button) => button.addEventListener('click', () => {
            const input = button.closest('.job-v4-due-cell')?.querySelector('[data-job-v4-due-input]');
            if (!input) return;
            if (typeof input.showPicker === 'function') input.showPicker(); else input.click();
        }));
        content.querySelectorAll('[data-job-v4-due-input]').forEach((input) => input.addEventListener('change', async () => {
            const row = input.closest('[data-job-v4-task-row]'); input.disabled = true;
            try { await flowtrackJobV4UpdateTask(job, row, { due_date: input.value || null }); showToast('Task due date updated.'); } catch (error) { input.disabled = false; showToast(error.message || 'Due date could not be updated.'); }
        }));

        const dropzone = content.querySelector('[data-job-v4-dropzone]');
        const fileInput = content.querySelector('[data-job-v4-file-input]');
        const uploadJobAttachment = async (file) => {
            if (!file) return;
            if (!(canFlow('documents', 'create') && canFlow('documents', 'link'))) return showToast('Your role does not have Document Upload & Link access.');
            if (Number(file.size || 0) > 20 * 1024 * 1024) return showToast('The attachment must be 20 MB or smaller.');
            const formData = new FormData(); formData.append('file', file);
            try {
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/attachments`, { method: 'POST', body: formData });
                flowtrackApplyLinkedDocumentResult(result);
                const updatedJob = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
                flowtrackEnsureJobPanels(updatedJob, 0); flowtrackJobV4Apply(updatedJob, 0);
                showToast(result.message || 'Attachment uploaded successfully.');
            } catch (error) { showToast(error.message || 'Attachment upload failed.'); }
        };
        content.querySelector('[data-job-v4-browse]')?.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); fileInput?.click(); });
        fileInput?.addEventListener('change', () => { const file = fileInput.files?.[0]; if (file) void uploadJobAttachment(file); fileInput.value = ''; });
        content.querySelector('[data-job-v4-choose-documents]')?.addEventListener('click', () => flowtrackOpenExistingJobAttachmentChooser(job, (result) => {
            const updatedJob = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
            flowtrackEnsureJobPanels(updatedJob, 0); flowtrackJobV4Apply(updatedJob, 0);
        }));
        content.querySelectorAll('[data-job-v4-delete-document]').forEach((button) => button.addEventListener('click', () => {
            const documentRecord = docs.find((item) => Number(item.dbId) === Number(button.dataset.jobV4DeleteDocument));
            if (!documentRecord) return showToast('The selected document could not be found.');
            flowtrackConfirmDeleteDocument(documentRecord, (result) => {
                const updatedJob = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
                flowtrackEnsureJobPanels(updatedJob, 0); flowtrackJobV4Apply(updatedJob, 0);
            });
        }));
        dropzone?.addEventListener('dragover', (event) => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
        dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
        dropzone?.addEventListener('drop', (event) => { event.preventDefault(); dropzone.classList.remove('is-dragging'); const file = event.dataTransfer?.files?.[0]; if (file) void uploadJobAttachment(file); });
        dropzone?.addEventListener('keydown', (event) => { if (['Enter', ' '].includes(event.key) && !event.target.closest('button,input')) { event.preventDefault(); fileInput?.click(); } });

        content.querySelectorAll('[data-job-v4-activity-tab]').forEach((button) => button.addEventListener('click', () => flowtrackJobV4ApplyActivityFilter(content, button.dataset.jobV4ActivityTab)));
        flowtrackJobV4ApplyActivityFilter(content, 'comments');
        const commentInput = content.querySelector('[data-job-v4-comment-input]');
        const submitComment = async () => {
            const comment = commentInput?.value.trim(); if (!comment) return showToast('Enter a comment.');
            const button = content.querySelector('[data-job-v4-comment-submit]'); if (button) button.disabled = true;
            try {
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/comments`, { method: 'POST', body: { comment } });
                if (result.job) flowtrackUpsertRecord(jobs, result.job);
                const updatedJob = flowtrackFindJob(result.job?.id || job.id) || job;
                flowtrackEnsureJobPanels(updatedJob, 0); flowtrackJobV4Apply(updatedJob, 0); showToast('Comment added.');
            } catch (error) { if (button) button.disabled = false; showToast(error.message || 'Comment could not be added.'); }
        };
        content.querySelector('[data-job-v4-comment-submit]')?.addEventListener('click', submitComment);
        commentInput?.addEventListener('keydown', (event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); submitComment(); } });

        bindJobDocumentUploadButtons(content, job, 8);
        flowtrackBindJobDocumentTemplate(content, job);
        applyActionPermissions();
        updateExpandedCount();
    }

    jobPanels = function (job) { return flowtrackJobV4Panels(job); };

    const flowtrackJobV4PreviousOpenJob = currentFlowtrackImplementation('openJob');
    registerFlowtrackExtension('openJob', 'openJob-stage-5-final-reference-template', async function (id) {
        const result = await flowtrackJobV4PreviousOpenJob(id);
        const job = flowtrackFindJob(id) || flowtrackFindJob(result?.job?.id || result?.id);
        if (job) {
            setTimeout(() => flowtrackJobV4Apply(flowtrackFindJob(job.id) || job, 0), 0);
            setTimeout(() => flowtrackJobV4Apply(flowtrackFindJob(job.id) || job, 0), 180);
        }
        return result;
    });

    
    /* CSS externalized: flowtrackJobV4Styles. */

    // Workflow readability pass: preserve the approved layout and interactions,
    // but remove the overly compressed 8–10px typography from the prototype.
    
    /* CSS externalized: flowtrackWorkflowReadabilityStyles. */


    // Create Job — final prototype layout.
    // This is a presentation-only reflow of the existing create-job controls: the
    // original IDs, event handlers, validation and POST payload remain intact.
    function flowtrackCreateJobPrototypePriority(host) {
        const priorityField = host.querySelector('input[name="newJobPriority"]')?.closest('.field');
        if (!priorityField || priorityField.querySelector('#newJobPrioritySelect')) return;
        const radios = Array.from(priorityField.querySelectorAll('input[name="newJobPriority"]'));
        if (!radios.length) return;
        const selected = radios.find((radio) => radio.checked) || radios[0];
        const select = document.createElement('select');
        select.id = 'newJobPrioritySelect';
        select.className = 'job-create-prototype-priority-select';
        select.setAttribute('aria-label', 'Priority');
        select.innerHTML = radios.map((radio) => {
            const label = radio.closest('label')?.querySelector('span:last-child')?.textContent?.trim() || `Priority ${radio.value}`;
            return `<option value="${html(radio.value)}" ${String(radio.value) === String(selected?.value) ? 'selected' : ''}>${html(label)}</option>`;
        }).join('');
        select.addEventListener('change', () => {
            radios.forEach((radio) => { radio.checked = String(radio.value) === String(select.value); });
        });
        const options = priorityField.querySelector('.job-priority-options');
        if (options) options.classList.add('job-create-prototype-priority-source');
        options?.insertAdjacentElement('beforebegin', select);
    }

    function flowtrackCreateJobPrototypeWorkflowSummary(host) {
        const workflowSelect = host.querySelector('#newJobWorkflow');
        const phaseSelect = host.querySelector('#newJobStartPhase');
        const summary = host.querySelector('[data-job-create-workflow-summary]');
        const preview = host.querySelector('[data-job-create-workflow-preview-panel]');
        if (!workflowSelect || !phaseSelect || !summary) return;

        const workflow = getWorkflow(Number(workflowSelect.value));
        const phases = flowtrackToArray(workflow?.phases)
            .filter((phase) => phase && phase.active !== false)
            .slice()
            .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
        const totalTasks = phases.reduce((sum, phase) => sum + Number(phase.taskCount || 0), 0);
        summary.innerHTML = `<span class="job-create-info-icon">ⓘ</span><b>${phases.length} phase${phases.length === 1 ? '' : 's'}</b><span>·</span><b>${totalTasks} task${totalTasks === 1 ? '' : 's'} will be created</b>`;

        if (preview) {
            preview.innerHTML = phases.length
                ? phases.map((phase, index) => `<div class="job-create-preview-step"><span>${index + 1}</span><div><b>${html(phase.name || `Phase ${index + 1}`)}</b><small>${Number(phase.taskCount || 0)} task${Number(phase.taskCount || 0) === 1 ? '' : 's'}${phase.taskPack ? ` · ${html(phase.taskPack)}` : ''}</small></div></div>`).join('')
                : '<div class="small muted">No active phases are configured for this workflow.</div>';
        }
    }

    function flowtrackCreateJobPrototypeProductSummary(host) {
        const summary = host.querySelector('[data-job-create-product-summary]');
        if (!summary) return;
        const rows = Array.from(host.querySelectorAll('#newJobProducts .job-product-row'));
        const total = rows.reduce((sum, row) => sum + Math.max(0, Number(row.querySelector('.new-job-quantity')?.value || 0)), 0);
        summary.textContent = `${rows.length} product${rows.length === 1 ? '' : 's'} · ${total.toLocaleString()} total units`;
    }

    function flowtrackApplyCreateJobPrototypeLayout() {
        const page = document.querySelector('#page-record-job-new');
        const content = page?.querySelector('.standalone-record-content');
        const body = content?.querySelector('.modal-body');
        const grid = body?.querySelector('.job-form-stack');
        if (!page || !content || !body || !grid || content.dataset.jobCreatePrototypeApplied === '1') return false;
        content.dataset.jobCreatePrototypeApplied = '1';
        page.classList.add('job-create-prototype-page');
        content.classList.add('job-create-prototype-content');

        const title = page.querySelector('.standalone-record-title');
        if (title) {
            const paragraphs = title.querySelectorAll('p');
            if (paragraphs[0]) paragraphs[0].textContent = 'Jobs / Create job';
            const heading = title.querySelector('h1');
            if (heading) heading.textContent = 'Create new job';
            if (paragraphs[1]) paragraphs[1].textContent = 'Set the job scope, products, ownership and workflow.';
        }

        const modalHead = content.querySelector('.modal-head');
        if (modalHead) modalHead.classList.add('job-create-prototype-hidden-head');
        const footer = content.querySelector('.modal-foot');
        if (footer) {
            footer.classList.add('job-create-prototype-footer');
            const createButton = footer.querySelector('#createJob');
            const draftButton = footer.querySelector('#saveDraftJob');
            if (createButton) createButton.textContent = 'Create job';
            if (draftButton) draftButton.textContent = 'Save draft';
        }

        const fieldFor = (selector) => hostField(content.querySelector(selector));
        function hostField(node) { return node?.closest('.field') || null; }

        const clientField = fieldFor('#newJobClient');
        const contactField = fieldFor('#newJobContact');
        const titleField = fieldFor('#newJobTitle');
        const descriptionField = fieldFor('#newJobDesc');
        const productField = content.querySelector('#newJobProducts')?.closest('.job-products-field');
        const deliveryField = fieldFor('#newJobDelivery');
        const ownerField = fieldFor('#newJobOwner');
        const workflowField = fieldFor('#newJobWorkflow');
        const phaseField = fieldFor('#newJobStartPhase');
        const attachmentField = fieldFor('#newJobAttachments');
        const priorityField = content.querySelector('input[name="newJobPriority"]')?.closest('.field');

        const layout = document.createElement('div');
        layout.className = 'job-create-prototype';
        layout.innerHTML = `
            <section class="job-create-prototype-card" data-job-create-section="basics">
                <div class="job-create-prototype-section-title"><span>1</span><h2>Job basics</h2></div>
                <div class="job-create-prototype-basics-grid">
                    <div class="job-create-prototype-code-field field"><label>Job code</label><div class="job-create-prototype-readonly"><input value="Generated automatically" readonly aria-readonly="true"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></div></div>
                    <div data-job-create-client-slot></div>
                    <div data-job-create-title-slot></div>
                    <div data-job-create-description-slot></div>
                </div>
            </section>
            <section class="job-create-prototype-card" data-job-create-section="products">
                <div class="job-create-prototype-section-title"><span>2</span><h2>Products &amp; quantities</h2></div>
                <div data-job-create-products-slot></div>
            </section>
            <section class="job-create-prototype-card" data-job-create-section="schedule">
                <div class="job-create-prototype-section-title"><span>3</span><h2>Schedule &amp; owner</h2></div>
                <div class="job-create-prototype-schedule-grid"><div data-job-create-delivery-slot></div><div data-job-create-priority-slot></div><div data-job-create-owner-slot></div></div>
            </section>
            <section class="job-create-prototype-card" data-job-create-section="workflow">
                <div class="job-create-prototype-section-title"><span>4</span><h2>Workflow</h2></div>
                <div class="job-create-prototype-workflow-grid"><div data-job-create-workflow-slot></div><div data-job-create-phase-slot></div></div>
                <div class="job-create-prototype-info-row"><div data-job-create-workflow-summary></div><button type="button" data-job-create-preview-workflow>Preview workflow ↗</button></div>
                <div class="job-create-prototype-preview" data-job-create-workflow-preview-panel hidden></div>
                <p class="job-create-prototype-note">Workflow and starting phase are fixed after creation; transitions are managed from the Workflow tab.</p>
            </section>
            <section class="job-create-prototype-card job-create-prototype-attachments" data-job-create-section="attachments">
                <div class="job-create-prototype-section-title"><span>5</span><h2>Attachments</h2></div>
                <div data-job-create-attachment-slot></div>
            </section>`;

        const move = (node, selector) => {
            const slot = layout.querySelector(selector);
            if (node && slot) slot.appendChild(node);
        };
        move(clientField, '[data-job-create-client-slot]');
        if (clientField && contactField) {
            contactField.classList.add('job-create-prototype-contact');
            layout.querySelector('[data-job-create-client-slot]')?.appendChild(contactField);
        }
        move(titleField, '[data-job-create-title-slot]');
        move(descriptionField, '[data-job-create-description-slot]');
        move(productField, '[data-job-create-products-slot]');
        move(deliveryField, '[data-job-create-delivery-slot]');
        move(priorityField, '[data-job-create-priority-slot]');
        move(ownerField, '[data-job-create-owner-slot]');
        move(workflowField, '[data-job-create-workflow-slot]');
        move(phaseField, '[data-job-create-phase-slot]');
        move(attachmentField, '[data-job-create-attachment-slot]');
        grid.replaceChildren(layout);

        titleField?.classList.add('job-create-prototype-full');
        descriptionField?.classList.add('job-create-prototype-full');
        productField?.classList.add('job-create-prototype-product-field');
        deliveryField?.classList.add('job-create-prototype-compact-field');
        priorityField?.classList.add('job-create-prototype-compact-field');
        ownerField?.classList.add('job-create-prototype-compact-field');
        workflowField?.classList.add('job-create-prototype-compact-field');
        phaseField?.classList.add('job-create-prototype-compact-field');
        attachmentField?.classList.add('job-create-prototype-attachment-field');

        const ownerLabel = ownerField?.querySelector('label');
        const workflowLabel = workflowField?.querySelector('label');
        const phaseLabel = phaseField?.querySelector('label');
        if (ownerLabel) ownerLabel.textContent = 'Job owner *';
        if (workflowLabel) workflowLabel.textContent = 'Workflow';
        if (phaseLabel) phaseLabel.textContent = 'Starting phase';
        const ownerHelp = ownerField?.querySelector('.inline-help');
        if (ownerHelp) ownerHelp.textContent = 'Accountable for overall delivery.';
        workflowField?.querySelector('.inline-help')?.classList.add('job-create-prototype-hide-help');
        phaseField?.querySelector('.inline-help')?.classList.add('job-create-prototype-hide-help');

        const productLabels = productField?.querySelectorAll('.job-product-labels span');
        if (productLabels?.[3]) productLabels[3].textContent = 'Action';
        const productTitle = productField?.querySelector('.job-products-title');
        if (productTitle) productTitle.classList.add('job-create-prototype-hide-product-title');
        const addProduct = productField?.querySelector('#addJobProduct');
        if (addProduct) addProduct.textContent = '＋ Add product';
        if (productField && !productField.querySelector('[data-job-create-product-summary]')) {
            const bottom = document.createElement('div');
            bottom.className = 'job-create-prototype-product-bottom';
            bottom.innerHTML = '<span data-job-create-product-button-slot></span><span data-job-create-product-summary></span>';
            const buttonSlot = bottom.querySelector('[data-job-create-product-button-slot]');
            if (addProduct && buttonSlot) buttonSlot.appendChild(addProduct);
            productField.appendChild(bottom);
        }

        flowtrackCreateJobPrototypePriority(content);
        flowtrackCreateJobPrototypeWorkflowSummary(content);
        flowtrackCreateJobPrototypeProductSummary(content);
        window.FlowtrackSearchableDropdown?.enhance(page);

        const updateWorkflow = () => setTimeout(() => flowtrackCreateJobPrototypeWorkflowSummary(content), 0);
        content.querySelector('#newJobWorkflow')?.addEventListener('change', updateWorkflow);
        content.querySelector('#newJobStartPhase')?.addEventListener('change', updateWorkflow);
        content.querySelector('[data-job-create-preview-workflow]')?.addEventListener('click', () => {
            const preview = content.querySelector('[data-job-create-workflow-preview-panel]');
            const button = content.querySelector('[data-job-create-preview-workflow]');
            if (!preview || !button) return;
            preview.hidden = !preview.hidden;
            button.textContent = preview.hidden ? 'Preview workflow ↗' : 'Hide workflow ↑';
        });

        const productList = content.querySelector('#newJobProducts');
        if (productList) {
            const observer = new MutationObserver(() => flowtrackCreateJobPrototypeProductSummary(content));
            observer.observe(productList, { childList: true, subtree: true });
            productList.addEventListener('input', () => flowtrackCreateJobPrototypeProductSummary(content));
            productList.addEventListener('change', () => setTimeout(() => flowtrackCreateJobPrototypeProductSummary(content), 0));
        }

        const delivery = content.querySelector('#newJobDelivery');
        delivery?.addEventListener('click', () => {
            if (typeof delivery.showPicker === 'function') {
                try { delivery.showPicker(); } catch (_) {}
            }
        });
        return true;
    }

    const flowtrackCreateJobPrototypeOpen = openJobModal;
    openJobModal = function (prefillClient) {
        const result = flowtrackCreateJobPrototypeOpen(prefillClient);
        queueMicrotask(() => flowtrackApplyCreateJobPrototypeLayout());
        setTimeout(() => flowtrackApplyCreateJobPrototypeLayout(), 0);
        setTimeout(() => flowtrackApplyCreateJobPrototypeLayout(), 80);
        return result;
    };

    
    /* CSS externalized: flowtrackCreateJobPrototypeStyles. */

    
    /* CSS externalized: flowtrackJobListPrototypeFinalStyles. */

    
    /* CSS externalized: flowtrackJobListFilterConsistencyStyles. */

})();


/* FlowTrack consistency/readability and document action hardening. */
(() => {
    if (document.getElementById('flowtrack-overview-consistency-document-actions')) return;
    
    /* CSS externalized: style. */
})();


/* Job title edit/readability, copy affordance, and required-document task cues. */
(() => {
    if (document.getElementById('flowtrack-job-title-edit-copy-required-doc')) return;
    
    /* CSS externalized: style. */
})();

/* FlowTrack responsive audit — tablet/mobile alignment across every screen. */
(() => {
    if (document.getElementById('flowtrack-responsive-audit-v1')) return;
    
    /* CSS externalized: style. */
})();

/* Job Details small-device compatibility hardening — Overview / Workflow / Commercial / Documents. */
(() => {
    if (document.getElementById('flowtrack-job-detail-small-device-fix')) return;
    
    /* CSS externalized: style. */
})();

/* Tablet-width task/product/history layouts: avoid clipped job-detail tables around 768–820px. */
(() => {
    if (document.getElementById('flowtrack-job-detail-tablet-card-fix')) return;
    
    /* CSS externalized: style. */
})();


/* FlowTrack mobile/tablet alignment hardening for Master Data + Administration Setup. */
(() => {
    if (document.getElementById('flowtrack-master-setup-responsive-fix')) return;
    
    /* CSS externalized: style. */
})();

/* FlowTrack Job Board card-width refinement: slightly wider phase columns/cards for readability. */
(() => {
    if (document.getElementById('flowtrack-job-board-wider-cards')) return;
    
    /* CSS externalized: style. */
})();

/* FlowTrack Operations Board visual consistency: Job Board and Task Board share the same typography and filter sizing. */
(() => {
    if (document.getElementById('flowtrack-operations-board-consistency')) return;
    
    /* CSS externalized: style. */
})();


/* 2026-08-01 Board mobile usability + inline assignee editor final override.
   Keep this block last: older responsive audit styles intentionally preserve desktop
   board minimum widths and are overridden here only on small screens. */
(() => {
    
    /* CSS externalized: style. */
})();

/* 2026-08-01 Mobile Board vertical-scroll ownership fix.
   The stacked mobile Board must use the document/page as the vertical scroller.
   Older sticky-board rules used a contained viewport-height canvas; on touch devices
   that could swallow pan gestures after the canvas was changed to overflow:visible. */
(() => {
    if (document.getElementById('flowtrack-board-mobile-page-scroll')) return;
    
    /* CSS externalized: style. */
})();
