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
    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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
    const segmentLoadState = { clients: 'none', jobs: 'none', tasks: 'none', documents: 'none' };
    const pageDataLoading = new Map();
    const pageContextLoading = new Map();
    const pageContextState = new Map();
    let pageContextBaseUrl = '/flowtrack/template-api/context';
    let bootstrapSegmentUrls = {};
    let bootstrapSegmentPerPage = 50;

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
            const data = await loadBootstrap({ silent: true });
            if (!data) return;
            await flowtrackEnsurePageData(previousPage, { force: true });
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



    async function flowtrackCopyIdentifier(value, label = 'ID') {
        const text = String(value || '').trim();
        if (!text) return showToast(`${label} could not be copied.`);
        let copied = false;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
                copied = true;
            }
        } catch (_) {}
        if (!copied) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
            textarea.remove();
        }
        showToast(copied ? `${text} copied.` : `Copy failed. Select the ${label} and copy it manually.`);
        return copied;
    }

    function flowtrackCopyIdentifierButton(value, label = 'ID', extraClass = '') {
        const text = String(value || '').trim();
        if (!text) return '';
        return `<button type="button" class="flow-id-copy ${html(extraClass)}" data-flow-copy-id="${html(text)}" data-flow-copy-label="${html(label)}" title="Copy ${html(label)}" aria-label="Copy ${html(label)} ${html(text)}"><span>${html(text)}</span><span class="flow-id-copy-icon" aria-hidden="true">⧉</span></button>`;
    }

    function flowtrackBindCopyIdentifiers(root = document) {
        if (!root?.querySelectorAll) return;
        root.querySelectorAll('[data-flow-copy-id]').forEach((button) => {
            if (button.dataset.flowCopyBound === '1') return;
            button.dataset.flowCopyBound = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                void flowtrackCopyIdentifier(button.dataset.flowCopyId, button.dataset.flowCopyLabel || 'ID');
            });
        });
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
                'X-CSRF-TOKEN': csrfToken(),
                ...(options.headers || {}),
            };

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
                const fallback = response.status === 413
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
            const notificationText = normalizedStatusValue(`${notification.type || ''} ${notification.severity || ''} ${notification.event || ''} ${notification.title || ''} ${notification.text || ''}`);
            const signal = /ATTENTION|FLAG|BLOCK|RISK|OVERDUE|DELAY/.test(notificationText) || notification.severity === 'warning'
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
        let derivedStateChanged = false;

        if (hasOwnBootstrapField(data, 'currentUser')) acceptCurrentUser(data.currentUser);
        if (hasOwnBootstrapField(data, 'users')) replaceArray(users, data.users);
        if (hasOwnBootstrapField(data, 'boardAssigneeUsers')) {
            replaceArray(boardAssigneeUsers, data.boardAssigneeUsers);
        } else if (hasOwnBootstrapField(data, 'users') && !boardAssigneeUsers.length) {
            replaceArray(boardAssigneeUsers, data.users);
        }
        if (hasOwnBootstrapField(data, 'clients')) replaceArray(clients, data.clients);
        if (hasOwnBootstrapField(data, 'jobs')) replaceArray(jobs, data.jobs);
        if (hasOwnBootstrapField(data, 'boardJobIds')) {
            boardJobIds = new Set(flowtrackToArray(data.boardJobIds).map((id) => Number(id)));
        }
        if (hasOwnBootstrapField(data, 'boardAccess')) {
            boardAccess = data.boardAccess || { isAdministrator: false, taskVisibility: 'assigned_job_tasks' };
        }
        if (hasOwnBootstrapField(data, 'tasks')) {
            replaceArray(tasks, data.tasks);
            tasks.forEach(flowtrackHydrateTaskAssignee);
        }
        if (hasOwnBootstrapField(data, 'documents')) replaceArray(docs, data.documents);
        if (hasOwnBootstrapField(data, 'notifications')) replaceArray(notifications, data.notifications);
        if (hasOwnBootstrapField(data, 'activities')) replaceArray(dashboardActivities, data.activities);
        if (hasOwnBootstrapField(data, 'workflows')) {
            replaceArray(workflowTemplates, flowtrackNormalizeWorkflows(data.workflows));
            derivedStateChanged = true;
        }
        if (hasOwnBootstrapField(data, 'taskPacks')) {
            taskPacks = flowtrackToArray(data.taskPacks).filter((pack) => pack && typeof pack === 'object');
            derivedStateChanged = true;
        }
        if (hasOwnBootstrapField(data, 'capabilities') && data.capabilities && typeof data.capabilities === 'object') {
            capabilities = data.capabilities;
        }
        if (hasOwnBootstrapField(data, 'summaryMetrics') && data.summaryMetrics && typeof data.summaryMetrics === 'object' && !Array.isArray(data.summaryMetrics)) {
            summaryMetrics = { ...summaryMetrics, ...data.summaryMetrics };
        }
        if (hasOwnBootstrapField(data, 'notificationRules')) notificationRules = flowtrackToArray(data.notificationRules);
        if (hasOwnBootstrapField(data, 'notificationAccess')) notificationAccess = data.notificationAccess || notificationAccess;
        notificationUnread = notifications.filter((notification) => !notification.read).length;
        const incomingWorkspaceVersion = Number(data.bootstrapVersion || 0);
        if (incomingWorkspaceVersion > 0) workspaceDataVersion = incomingWorkspaceVersion;

        if (hasOwnBootstrapField(data, 'accessControl')) {
            const access = data.accessControl;
            canViewAccess = Boolean(access);
            canManageAccess = Boolean(access?.canManage);
            canCreateUsers = Boolean(access?.canCreateUsers);
            canDeleteUsers = Boolean(access?.canDeleteUsers);
            isFlowAdmin = Boolean(access?.isAdmin || currentUser?.isAdmin);
            isFlowSuperAdmin = Boolean(access?.isSuperAdmin || currentUser?.isSuperAdmin);
            accessRoles = flowtrackToArray(access?.roles);
            accessMembers = flowtrackToArray(access?.members);
            const incomingAccessCatalog = access?.catalog || accessCatalog || {};
            accessCatalog = {
                modules: flowtrackToArray(incomingAccessCatalog.modules),
                actions: flowtrackToArray(incomingAccessCatalog.actions),
                scopes: flowtrackToArray(incomingAccessCatalog.scopes),
                sensitiveFields: flowtrackToArray(incomingAccessCatalog.sensitiveFields),
            };
            if (!selectedAccessRoleId || !accessRoles.some((role) => Number(role.id) === Number(selectedAccessRoleId))) {
                selectedAccessRoleId = accessRoles[0]?.id || null;
            }
        }

        if (hasOwnBootstrapField(data, 'masterDataCatalog') || hasOwnBootstrapField(data, 'masterData')) {
            if (hasOwnBootstrapField(data, 'masterDataCatalog')) masterCatalog = flowtrackToArray(data.masterDataCatalog);
            for (const key of Object.keys(masterLabels)) delete masterLabels[key];
            for (const key of Object.keys(masterData)) delete masterData[key];
            masterCatalog.forEach((section) => {
                masterLabels[section.key] = section.label;
                masterData[section.key] = flowtrackToArray(data.masterData?.[section.key]);
            });
            if (!masterCatalog.some((section) => section.key === selectedMasterCategory)) {
                selectedMasterCategory = masterCatalog[0]?.key || '';
            }
            derivedStateChanged = true;
        }

        if (derivedStateChanged) rebuildTaskPackDerivedState();
        if (workflowTemplates.length) {
            selectedWorkflowId = workflowTemplates[0]?.id ?? selectedWorkflowId;
            if (!boardWorkflowId || !workflowTemplates.some((workflow) => Number(workflow.id) === Number(boardWorkflowId))) {
                boardWorkflowId = workflowTemplates.find((workflow) => workflow.active !== false)?.id
                    ?? workflowTemplates[0]?.id
                    ?? null;
            }
        }

        applyCurrentUserIdentity();
        if (hasOwnBootstrapField(data, 'notifications') || hasOwnBootstrapField(data, 'currentUser')) initializeRealtimeNotifications();
    }

    async function loadBootstrapSegment(url, perPage = 50, generation = bootstrapGeneration, allPages = false, minimumRows = 0) {
        if (!url) return { rows: [], relatedJobs: [], relatedClients: [], pagination: null };
        if (generation !== bootstrapGeneration) return null;

        const rows = [];
        const relatedJobs = [];
        const relatedClients = [];
        let pagination = null;
        let pageNumber = 1;
        const normalizedPerPage = Math.max(20, Number(perPage) || 50);
        const minimumPageCount = Math.max(1, Math.ceil(Math.max(0, Number(minimumRows) || 0) / normalizedPerPage));
        const maxPages = allPages ? 250 : minimumPageCount;

        const requestPageSize = allPages ? Math.max(100, normalizedPerPage) : normalizedPerPage;
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

            if (!pagination?.hasMorePages || (!allPages && pageNumber >= minimumPageCount)) break;
            pageNumber += 1;
        }

        return { rows, relatedJobs, relatedClients, pagination };
    }

    async function loadBootstrap(options = {}) {
        const silent = Boolean(options?.silent);
        const generation = ++bootstrapGeneration;
        const coreUrl = `/flowtrack/template-api/bootstrap?_flowtrack_generation=${generation}&_=${Date.now()}`;
        if (!silent) flowtrackSetLoadingProgress(6, 'Preparing FlowTrack workspace…');
        const core = await api(coreUrl, { cache: 'no-store' });
        if (generation !== bootstrapGeneration) return null;
        if (!core || typeof core !== 'object') {
            throw new Error('FlowTrack bootstrap data is unavailable. Please reload the page.');
        }

        // Authentication/bootstrap now loads only shared workspace/configuration
        // state. Operational records are fetched only when their page needs them.
        // This prevents login from downloading every visible Job and Task.
        bootstrapSegmentUrls = core.segments || {};
        pageContextBaseUrl = String(core.contextBaseUrl || '/flowtrack/template-api/context');
        bootstrapSegmentPerPage = Math.max(20, Number(bootstrapSegmentUrls.perPage || 50));
        if (!silent) {
            Object.keys(segmentPagination).forEach((segment) => {
                segmentPagination[segment] = null;
                segmentLoadState[segment] = 'none';
            });
            pageDataLoading.clear();
            pageContextLoading.clear();
            pageContextState.clear();
            replaceArray(clientLabels, []);
        }

        // Background bootstrap refreshes must never throw away already-loaded
        // page data. Only a fresh authenticated startup initializes the empty
        // operational collections; subsequent silent refreshes update global
        // shell state in place.
        const data = silent ? core : Object.assign({}, core, {
            clients: [],
            jobs: [],
            tasks: [],
            documents: [],
        });
        if (generation !== bootstrapGeneration) return null;
        if (!silent) flowtrackSetLoadingProgress(82, 'Applying workspace configuration…');
        applyBootstrap(data);
        bootstrapAppliedGeneration = generation;
        if (!silent) flowtrackSetLoadingProgress(90, 'Loading this page…');
        return data;
    }

    function flowtrackSegmentTarget(segment) {
        if (segment === 'clients') return clients;
        if (segment === 'jobs') return jobs;
        if (segment === 'tasks') return tasks;
        if (segment === 'documents') return docs;
        return null;
    }

    async function flowtrackEnsureSegmentData(segment, options = {}) {
        const target = flowtrackSegmentTarget(segment);
        const url = bootstrapSegmentUrls?.[segment];
        if (!target || !url) return;

        const allPages = Boolean(options?.allPages);
        const force = Boolean(options?.force);
        const currentState = segmentLoadState[segment] || 'none';
        if (!force && (currentState === 'all' || (currentState === 'page' && !allPages))) return;
        if (segmentLoading.has(segment)) {
            while (segmentLoading.has(segment)) await new Promise((resolve) => setTimeout(resolve, 20));
            return flowtrackEnsureSegmentData(segment, options);
        }

        segmentLoading.add(segment);
        try {
            // A realtime/background refresh must never shrink a page that already
            // has multiple lazy-loaded pages in memory back to page 1. Preserve
            // the amount of data already visible, while still replacing it with
            // a fresh server snapshot so deleted/changed records stay accurate.
            const loadedRowCount = target.length;
            const refreshAllPages = allPages || (force && currentState === 'all');
            const minimumRows = force && !refreshAllPages ? loadedRowCount : 0;
            const page = await loadBootstrapSegment(
                url,
                bootstrapSegmentPerPage,
                bootstrapGeneration,
                refreshAllPages,
                minimumRows,
            );
            if (!page) return;

            replaceArray(target, page.rows);
            if (segment === 'tasks') target.forEach(flowtrackHydrateTaskAssignee);
            flowtrackToArray(page.relatedJobs).forEach((job) => flowtrackUpsertRecord(jobs, job, false));
            flowtrackToArray(page.relatedClients).forEach((client) => flowtrackUpsertRecord(clientLabels, client, false));
            segmentPagination[segment] = page.pagination || null;
            segmentLoadState[segment] = refreshAllPages || !page.pagination?.hasMorePages ? 'all' : 'page';
        } finally {
            segmentLoading.delete(segment);
        }
    }

    function flowtrackPageDataRequirements(page) {
        const normalized = flowtrackNormalizePageName(page || 'dashboard');
        if (normalized === 'dashboard') return [{ segment: 'jobs' }, { segment: 'tasks' }];
        if (normalized === 'clients') return [{ segment: 'clients' }];
        if (normalized === 'jobs') return [{ segment: 'clients' }, { segment: 'jobs' }];
        if (normalized === 'board') return [{ segment: 'jobs' }, { segment: 'tasks' }];
        // My Work is an assignment inbox, not a paginated browsing surface.
        // Load the complete visible task set before rendering so counts/cards do
        // not grow several seconds later as auto-pagination catches up.
        if (normalized === 'mywork') return [{ segment: 'tasks', allPages: true }];
        if (normalized === 'documents') return [{ segment: 'documents' }];
        if (normalized === 'reports') return [{ segment: 'jobs' }, { segment: 'tasks' }];
        return [];
    }

    function flowtrackPageNeedsContext(page) {
        return ['dashboard', 'mywork', 'jobs', 'clients', 'board', 'documents', 'reports', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup', 'notifications', 'profile']
            .includes(flowtrackNormalizePageName(page || 'dashboard'));
    }

    async function flowtrackEnsurePageContext(page, options = {}) {
        const normalized = flowtrackNormalizePageName(page || 'dashboard');
        if (!flowtrackPageNeedsContext(normalized)) return;
        const force = Boolean(options?.force);
        if (!force && pageContextState.get(normalized) === 'loaded') return;
        if (pageContextLoading.has(normalized)) return pageContextLoading.get(normalized);

        const request = (async () => {
            const base = String(pageContextBaseUrl || '/flowtrack/template-api/context').replace(/\/$/, '');
            const payload = await api(`${base}/${encodeURIComponent(normalized)}?_=${Date.now()}`, { cache: 'no-store' });
            if (!payload || typeof payload !== 'object') throw new Error('Unable to load this page configuration.');
            applyBootstrap(payload);
            pageContextState.set(normalized, 'loaded');
        })().finally(() => pageContextLoading.delete(normalized));

        pageContextLoading.set(normalized, request);
        return request;
    }

    function flowtrackIsPageDataReady(page) {
        const normalized = flowtrackNormalizePageName(page || 'dashboard');
        if (flowtrackPageNeedsContext(normalized) && pageContextState.get(normalized) !== 'loaded') return false;
        return flowtrackPageDataRequirements(normalized).every(({ segment, allPages }) => {
            const state = segmentLoadState[segment] || 'none';
            return state === 'all' || (!allPages && state === 'page');
        });
    }

    async function flowtrackEnsurePageData(page, options = {}) {
        const normalized = flowtrackNormalizePageName(page || 'dashboard');
        const force = Boolean(options?.force);
        const key = `${normalized}:${force ? 'force' : 'normal'}`;
        if (!force && flowtrackIsPageDataReady(normalized)) return;
        if (pageDataLoading.has(key)) return pageDataLoading.get(key);

        const request = (async () => {
            await flowtrackEnsurePageContext(normalized, { force });
            const requirements = flowtrackPageDataRequirements(normalized);
            for (let attempt = 0; attempt < 2; attempt += 1) {
                // Load page segments in a deterministic order. Task list responses
                // carry bounded related Job records; running Jobs and Tasks in
                // parallel allowed a later Jobs response to erase those related
                // records and made cards appear to vanish until another fetch.
                for (const { segment, allPages } of requirements) {
                    await flowtrackEnsureSegmentData(segment, { allPages, force });
                }
                const segmentsReady = requirements.every(({ segment, allPages }) => {
                    const state = segmentLoadState[segment] || 'none';
                    return state === 'all' || (!allPages && state === 'page');
                });
                if (segmentsReady) break;
            }
            if (requirements.some(({ segment }) => segment === 'tasks') && typeof updateBadges === 'function') {
                updateBadges();
            }
            if (currentPage === normalized) {
                renderPage(normalized);
                applyActionPermissions();
            }
        })().finally(() => pageDataLoading.delete(key));

        pageDataLoading.set(key, request);
        return request;
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
        if (!pageName || !canOpenPage(pageName)) {
            showToast('You do not have permission to open this section.');
            window.dispatchEvent(new CustomEvent('flowtrack:navigation-complete', { detail: { page: pageName, ok: false } }));
            return false;
        }

        let target = document.getElementById(`page-${pageName}`);
        if (!target) {
            // Route-level Blade responses intentionally contain one workspace page
            // container. Reuse that container for normal in-app navigation instead
            // of performing a full document reload, which caused the visible flash
            // between every screen. Direct URLs still render through Laravel.
            const routePage = document.querySelector('.content > .page[data-route-page="true"]');
            if (routePage) {
                routePage.id = `page-${pageName}`;
                routePage.dataset.flowtrackScreen = pageName;
                routePage.replaceChildren();
                target = routePage;
                window.FLOWTRACK_ACTIVE_PAGE = pageName;
            }
        }

        if (!target) {
            const route = window.FLOWTRACK_PAGE_ROUTES?.[pageName];
            if (route) {
                window.location.assign(route);
                return true;
            }
            window.dispatchEvent(new CustomEvent('flowtrack:navigation-complete', { detail: { page: pageName, ok: false } }));
            return false;
        }

        const result = originalNavigate(pageName);
        queueMicrotask(() => {
            applyActionPermissions();
            window.FlowtrackSearchableDropdown?.enhance?.(target);
            window.dispatchEvent(new CustomEvent('flowtrack:navigation-complete', { detail: { page: pageName, ok: result !== false } }));
        });
        return result;
    };

    function showApplication(requestedPage = null) {
        $('#loginScreen')?.classList.add('hidden');
        $('#authLoadingScreen')?.classList.add('hidden');
        $('#appScreen')?.classList.remove('hidden');
        updateBadges();
        applyActionPermissions();

        // Promise callbacks receive the resolved bootstrap payload as their first
        // argument. Only an explicit string is a valid requested page.
        const routePage = flowtrackNormalizePageName(window.FLOWTRACK_ACTIVE_PAGE);
        const preferredPage = routePage || flowtrackNormalizePageName(requestedPage) || currentUser?.defaultLandingPage || null;
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

    // Shared date/time formatter must live in the core module because
    // Operations/My Work can render completion timestamps before the
    // lazily-loaded record/detail modules are requested.
    function flowtrackFormatDateTime(value) {
        if (!value) return 'Not recorded';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString([], { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    }



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


/* FLOWTRACK_CSRF_AUTO_RECOVERY */
(function installFlowTrackCsrfRecovery() {
    'use strict';

    if (window.__flowTrackCsrfRecoveryInstalled) {
        return;
    }

    window.__flowTrackCsrfRecoveryInstalled = true;

    const originalFetch = window.fetch.bind(window);
    let refreshPromise = null;

    async function refreshFlowTrackCsrfToken() {
        if (!refreshPromise) {
            refreshPromise = originalFetch('/flowtrack/csrf-token', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(
                        'Unable to refresh CSRF token.'
                    );
                }

                const payload = await response.json();
                const token = String(payload.token || '');

                if (!token) {
                    throw new Error(
                        'Empty CSRF token returned.'
                    );
                }

                const meta = document.querySelector(
                    'meta[name="csrf-token"]'
                );

                if (meta) {
                    meta.setAttribute('content', token);
                }

                if (window.FLOWTRACK_PUSHER) {
                    window.FLOWTRACK_PUSHER.csrfToken = token;
                }

                return token;
            })
            .finally(() => {
                refreshPromise = null;
            });
        }

        return refreshPromise;
    }

    window.refreshFlowTrackCsrfToken =
        refreshFlowTrackCsrfToken;

    window.fetch = async function(input, init = {}) {
        const requestOptions = { ...init };
        const method = String(
            requestOptions.method || 'GET'
        ).toUpperCase();

        const response = await originalFetch(
            input,
            requestOptions
        );

        if (
            response.status !== 419 ||
            ['GET', 'HEAD', 'OPTIONS'].includes(method)
        ) {
            return response;
        }

        try {
            const token =
                await refreshFlowTrackCsrfToken();

            const headers = new Headers(
                requestOptions.headers || {}
            );

            headers.set(
                'X-CSRF-TOKEN',
                token
            );

            headers.set(
                'X-Requested-With',
                'XMLHttpRequest'
            );

            return await originalFetch(
                input,
                {
                    ...requestOptions,
                    headers
                }
            );
        } catch (error) {
            console.error(
                'FlowTrack CSRF recovery failed:',
                error
            );

            return response;
        }
    };
})();
