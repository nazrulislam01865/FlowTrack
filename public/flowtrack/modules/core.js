/* FlowTrack generated lazy module: core. Source: resources/js/flowtrack/flowtrack-dynamic-source.js. Build: 6254af6ca40e4526. */
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
