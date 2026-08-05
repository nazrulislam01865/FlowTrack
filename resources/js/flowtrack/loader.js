import { build, files } from './runtime-manifest.js';

(() => {
    'use strict';

    const loaded = new Set();
    const pending = new Map();
    let clickNavigationSequence = 0;
    let deferredNavigationSequence = 0;
    let foregroundNavigationCount = 0;
    let pageLoadingDelayTimer = null;
    let pageLoadingVisible = false;
    let pendingPageLoading = null;
    const prefetched = new Set();
    const root = new URL('/flowtrack/modules/', window.location.origin);

    function beginForegroundNavigation() {
        foregroundNavigationCount += 1;
        window.FLOWTRACK_NAVIGATION_IN_FLIGHT = true;
    }

    function endForegroundNavigation() {
        foregroundNavigationCount = Math.max(0, foregroundNavigationCount - 1);
        window.FLOWTRACK_NAVIGATION_IN_FLIGHT = foregroundNavigationCount > 0;
    }

    window.flowtrackBeginNavigation = beginForegroundNavigation;
    window.flowtrackEndNavigation = endForegroundNavigation;

    function load(name) {
        if (loaded.has(name)) return Promise.resolve();
        if (pending.has(name)) return pending.get(name);
        const filename = files[name];
        if (!filename) return Promise.reject(new Error('Unknown FlowTrack asset module: ' + name));

        const promise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = new URL(filename + '?v=' + build, root).href;
            script.async = true;
            script.dataset.flowtrackModule = name;
            script.addEventListener('load', () => {
                loaded.add(name);
                pending.delete(name);
                window.dispatchEvent(new CustomEvent('flowtrack:module-loaded', { detail: { name } }));
                resolve();
            }, { once: true });
            script.addEventListener('error', () => {
                pending.delete(name);
                reject(new Error('Unable to load the FlowTrack ' + name + ' module.'));
            }, { once: true });
            document.head.appendChild(script);
        });
        pending.set(name, promise);
        return promise;
    }

    function routeInfo(value = window.location.pathname) {
        const path = String(value || '');
        const prefix = '/flowtrack/view/';
        const parts = path.startsWith(prefix)
            ? path.slice(prefix.length).split('/').filter(Boolean).map(decodeURIComponent)
            : [];
        if (!parts.length) return { page: 'dashboard', kind: 'page', type: 'page' };

        // Compatibility for malformed URLs produced by older route-sync code.
        // These must be classified as Task Pack/Workflow forms before the generic
        // `/tasks/{record}` route is considered.
        if (parts[0] === 'tasks' && parts[1] === 'pack-new') {
            return { page: 'taskpacksetup', kind: 'configuration-record', type: 'task-pack-new', canonicalPath: `${prefix}task-packs/new` };
        }
        if (parts[0] === 'tasks' && /^pack-edit-(.+)$/.test(parts[1] || '')) {
            const slug = String(parts[1]).replace(/^pack-edit-/, '');
            return { page: 'taskpacksetup', kind: 'configuration-record', type: 'task-pack-edit', slug, canonicalPath: `${prefix}task-packs/${encodeURIComponent(slug)}/edit` };
        }
        if (parts[0] === 'record-task-pack-new') {
            return { page: 'taskpacksetup', kind: 'configuration-record', type: 'task-pack-new', canonicalPath: `${prefix}task-packs/new` };
        }
        if (/^record-task-pack-edit-(.+)$/.test(parts[0] || '')) {
            const slug = String(parts[0]).replace(/^record-task-pack-edit-/, '');
            return { page: 'taskpacksetup', kind: 'configuration-record', type: 'task-pack-edit', slug, canonicalPath: `${prefix}task-packs/${encodeURIComponent(slug)}/edit` };
        }
        if (/^record-workflow-copy-(.+)$/.test(parts[0] || '')) {
            const slug = String(parts[0]).replace(/^record-workflow-copy-/, '');
            return { page: 'workflowsetup', kind: 'configuration-record', type: 'workflow-copy', slug, canonicalPath: `${prefix}workflows/${encodeURIComponent(slug)}/copy` };
        }

        // Static/form routes first; dynamic detail routes last.
        if (parts[0] === 'jobs' && parts[1] === 'new') return { page: 'jobs', kind: 'job-new', type: 'job-new' };
        if (parts[0] === 'jobs' && parts[1] && parts[2] === 'edit') return { page: 'jobs', kind: 'job-edit', type: 'job-edit', slug: parts[1] };
        if (parts[0] === 'clients' && parts[1] === 'new') return { page: 'clients', kind: 'client-new', type: 'client-new' };
        if (parts[0] === 'clients' && parts[1] && parts[2] === 'edit') return { page: 'clients', kind: 'client-edit', type: 'client-edit', slug: parts[1] };
        if (parts[0] === 'workflows' && parts[1] === 'new') return { page: 'workflowsetup', kind: 'configuration-record', type: 'workflow-new' };
        if (parts[0] === 'workflows' && parts[1] && parts[2] === 'edit') return { page: 'workflowsetup', kind: 'configuration-record', type: 'workflow-edit', slug: parts[1] };
        if (parts[0] === 'workflows' && parts[1] && parts[2] === 'copy') return { page: 'workflowsetup', kind: 'configuration-record', type: 'workflow-copy', slug: parts[1] };
        if (parts[0] === 'task-packs' && parts[1] === 'new') return { page: 'taskpacksetup', kind: 'configuration-record', type: 'task-pack-new' };
        if (parts[0] === 'task-packs' && parts[1] && parts[2] === 'edit') return { page: 'taskpacksetup', kind: 'configuration-record', type: 'task-pack-edit', slug: parts[1] };

        if (parts[0] === 'tasks' && parts[1]) return { page: 'board', kind: 'task', type: 'task', slug: parts[1] };
        if (parts[0] === 'jobs' && parts[1]) return { page: 'jobs', kind: 'job', type: 'job', slug: parts[1] };
        if (parts[0] === 'clients' && parts[1]) return { page: 'clients', kind: 'client', type: 'client', slug: parts[1] };
        return { page: parts[0], kind: 'page', type: 'page' };
    }

    async function ensureWorkspace() {
        await load('core');
        await load('workspace');
    }

    async function ensureOperations() {
        await ensureWorkspace();
        await load('operations');
    }

    async function ensureConfiguration() {
        await ensureOperations();
        await load('configuration');
    }

    async function ensureRecords() {
        await ensureConfiguration();
        await load('records');
    }

    async function ensureDetail(kind) {
        await ensureRecords();
        await load('job-shared');
        if (kind === 'task') await load('task-details');
        if (kind === 'job') await load('job-details');
    }

    async function ensureForPage(value) {
        const info = String(value || '').startsWith('/') ? routeInfo(value) : { page: String(value || 'dashboard'), kind: 'page' };
        await ensureWorkspace();
        if (!['clients', 'documents'].includes(info.page) || info.kind !== 'page') await ensureOperations();
        if (['board', 'mywork'].includes(info.page)) await load('board');
        if (['workflowsetup', 'taskpacksetup', 'masterdata', 'setup', 'profile'].includes(info.page)) await ensureRecords();
        if (info.page === 'jobs') {
            await ensureDetail('job');
        } else if (info.kind === 'task') {
            await load('board');
            await ensureDetail('task');
        } else if (['client', 'client-new', 'client-edit', 'configuration-record'].includes(info.kind)) {
            await ensureDetail('shared');
        }
        return info;
    }

    function syncPageRoute(page) {
        const normalized = String(page || '').trim();
        if (!normalized || normalized.startsWith('record-') || !window.history?.pushState) return;
        const path = '/flowtrack/view/' + encodeURIComponent(normalized);
        if (window.location.pathname !== path) window.history.pushState({ flowtrackPage: normalized }, '', path);
    }

    function pageLoadingLabel(page) {
        const labels = {
            dashboard: 'Dashboard',
            mywork: 'My Work',
            jobs: 'Jobs',
            clients: 'Clients',
            board: 'Board',
            documents: 'Documents',
            reports: 'Reports',
            workflowsetup: 'Workflow Setup',
            taskpacksetup: 'Task Pack Setup',
            masterdata: 'Master Data',
            setup: 'Setup',
            notifications: 'Notifications',
            profile: 'Profile',
        };
        return labels[String(page || '').trim()] || 'page';
    }

    function showPageLoading(page, percent = 12, message = null) {
        pendingPageLoading = {
            page,
            percent,
            label: message || `Loading ${pageLoadingLabel(page)}…`,
        };
        if (pageLoadingVisible) {
            window.flowtrackSetNavigationProgress?.(pendingPageLoading.percent, pendingPageLoading.label);
            return;
        }
        // Do not flash a loader for navigation that completes from memory/browser
        // cache in a fraction of a second. Slow navigation still receives the
        // existing circular percentage feedback.
        if (!pageLoadingDelayTimer) {
            const delay = String(page || '').trim() === 'mywork' ? 0 : 140;
            pageLoadingDelayTimer = window.setTimeout(() => {
                pageLoadingDelayTimer = null;
                if (!pendingPageLoading) return;
                pageLoadingVisible = true;
                window.flowtrackSetNavigationProgress?.(pendingPageLoading.percent, pendingPageLoading.label);
            }, delay);
        }
    }

    function finishPageLoading(page) {
        window.clearTimeout(pageLoadingDelayTimer);
        pageLoadingDelayTimer = null;
        pendingPageLoading = null;
        if (pageLoadingVisible) {
            window.flowtrackFinishNavigationProgress?.(`${pageLoadingLabel(page)} ready`);
        } else {
            window.flowtrackResetNavigationProgress?.();
        }
        pageLoadingVisible = false;
    }

    function cancelPageLoading() {
        window.clearTimeout(pageLoadingDelayTimer);
        pageLoadingDelayTimer = null;
        pendingPageLoading = null;
        pageLoadingVisible = false;
        window.flowtrackResetNavigationProgress?.();
    }

    function finalRouteMounted(info) {
        if (!info || info.kind === 'page') return true;
        if (info.kind === 'task') {
            return Boolean(document.querySelector('.page.active.ft-task-final-host[data-flowtrack-final-task="1"]'));
        }
        if (info.kind === 'job') {
            return Boolean(document.querySelector('.page.active .standalone-record-page.job-detail-v4-page .job-detail-v4-content'));
        }
        if (info.kind === 'client') {
            return Boolean(document.querySelector('.page.active .standalone-record-page .standalone-record-content'));
        }
        if (['job-new', 'job-edit', 'client-new', 'client-edit', 'configuration-record'].includes(info.kind)) {
            return Boolean(document.querySelector('.page.active .standalone-record-page.standalone-record-form .standalone-record-content'));
        }
        return true;
    }

    function waitForFinalRouteMount(info, timeoutMs = 5000) {
        if (finalRouteMounted(info)) return Promise.resolve(true);
        const started = performance.now();
        return new Promise((resolve, reject) => {
            const inspect = () => {
                if (finalRouteMounted(info)) {
                    resolve(true);
                    return;
                }
                if ((performance.now() - started) >= timeoutMs) {
                    reject(new Error('The final FlowTrack page renderer did not finish mounting.'));
                    return;
                }
                requestAnimationFrame(inspect);
            };
            requestAnimationFrame(inspect);
        });
    }

    function prefetchRemainingModules() {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (connection?.saveData) return;
        const run = () => {
            Object.entries(files).forEach(([name, filename]) => {
                if (loaded.has(name) || pending.has(name) || prefetched.has(name)) return;
                prefetched.add(name);
                const link = document.createElement('link');
                link.rel = 'prefetch';
                link.as = 'script';
                link.href = new URL(filename + '?v=' + build, root).href;
                link.dataset.flowtrackPrefetch = name;
                document.head.appendChild(link);
            });
        };
        if ('requestIdleCallback' in window) window.requestIdleCallback(run, { timeout: 1800 });
        else window.setTimeout(run, 700);
    }

    function installRouteSync() {
        const implementation = window.navigate;
        if (typeof implementation !== 'function' || implementation.__flowtrackAssetRouteSync) return;
        const wrapped = function (page) {
            const normalized = String(page || '').trim();
            const navigationId = ++deferredNavigationSequence;
            if (normalized && typeof window.flowtrackIsPageDataReady === 'function'
                && typeof window.flowtrackEnsurePageData === 'function'
                && !window.flowtrackIsPageDataReady(normalized)) {
                const context = this;
                const args = arguments;
                showPageLoading(normalized, 14);
                void window.flowtrackEnsurePageData(normalized).then(() => {
                    // Ignore an older async navigation if the user has already
                    // selected another screen while its data was loading.
                    if (navigationId !== deferredNavigationSequence) return;
                    showPageLoading(normalized, 88, `Rendering ${pageLoadingLabel(normalized)}…`);
                    const result = implementation.apply(context, args);
                    if (result !== false) syncPageRoute(normalized);
                    finishPageLoading(normalized);
                }).catch((error) => {
                    if (navigationId !== deferredNavigationSequence) return;
                    finishPageLoading(normalized);
                    if (typeof window.showToast === 'function') window.showToast(error?.message || 'This page could not be loaded.');
                    else console.error(error);
                });
                return false;
            }
            const result = implementation.apply(this, arguments);
            if (result !== false) syncPageRoute(page);
            return result;
        };
        Object.defineProperty(wrapped, '__flowtrackAssetRouteSync', { value: true });
        window.navigate = wrapped;
    }

    const api = window.FlowTrackAssets = {
        build,
        ready: null,
        load,
        ensureWorkspace,
        ensureOperations,
        ensureConfiguration,
        ensureRecords,
        ensureDetail,
        ensureForPage,
        isLoaded: (name) => loaded.has(name),
        loadedModules: () => Array.from(loaded),
    };

    document.addEventListener('click', async (event) => {
        if (!window.FLOWTRACK_AUTHENTICATED) return;
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        try {

        const navigation = target.closest('[data-page],[data-page-jump],[data-go]');
        const page = navigation?.dataset.page || navigation?.dataset.pageJump || navigation?.dataset.go;
        if (page) {
            // Real anchors must retain native browser behaviors: right-click
            // context menus, Cmd/Ctrl-click new tabs, Shift-click new windows,
            // and middle-click. Only plain primary activation is upgraded to
            // FlowTrack's in-place navigation. Keyboard Enter on an anchor
            // produces the same plain activation and therefore works here too.
            if (navigation instanceof HTMLAnchorElement && (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)) return;
            const navigationId = ++clickNavigationSequence;
            event.preventDefault();
            event.stopImmediatePropagation();
            beginForegroundNavigation();
            showPageLoading(page, 8);
            try {
                await ensureForPage(page);
                if (navigationId !== clickNavigationSequence) return;
                showPageLoading(page, 38, `Loading ${pageLoadingLabel(page)} data…`);
                const needsData = typeof window.flowtrackIsPageDataReady === 'function'
                    && !window.flowtrackIsPageDataReady(page);
                if (needsData && typeof window.flowtrackEnsurePageData === 'function') {
                    await window.flowtrackEnsurePageData(page);
                }
                if (navigationId !== clickNavigationSequence) return;
                showPageLoading(page, 82, `Rendering ${pageLoadingLabel(page)}…`);
                installRouteSync();
                window.dispatchEvent(new Event('flowtrack:assets-ready'));
                const result = window.navigate(page);
                if (result === false) cancelPageLoading();
            } catch (error) {
                if (navigationId === clickNavigationSequence) cancelPageLoading();
                throw error;
            } finally {
                endForegroundNavigation();
            }
            return;
        }

        const blockedAction = target.closest('[data-delete-job],[data-edit-job],[data-job-action],[data-delete-task],[data-task-action]');
        // Task links exist in several renderers. Job Details rows use
        // data-job-v4-open-task rather than data-task, so they must also pass
        // through the lazy task-details loader before their page handler runs.
        const jobDetailTask = !blockedAction ? target.closest('[data-job-v4-open-task]') : null;
        const task = !blockedAction ? target.closest('[data-task]') : null;
        const job = !blockedAction ? target.closest('[data-job]') : null;
        const client = target.closest('[data-client]');
        const documentRecord = target.closest('[data-doc]');
        const quickCreate = target.closest('#quickCreateBtn,[data-dashboard-action="create"],[data-new-client-job]');
        const kind = (task || jobDetailTask) ? 'task' : (job || quickCreate ? 'job' : (client ? 'client' : (documentRecord ? 'document' : null)));
        const requiredModule = kind === 'task' ? 'task-details' : (kind === 'job' ? 'job-details' : 'job-shared');
        if (!kind || loaded.has(requiredModule)) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        beginForegroundNavigation();
        try {
            showPageLoading(kind === 'task' ? 'board' : kind === 'client' ? 'clients' : kind === 'document' ? 'documents' : 'jobs', 18);
            await ensureDetail(kind === 'document' || kind === 'client' ? 'shared' : kind);
            installRouteSync();
            window.dispatchEvent(new Event('flowtrack:assets-ready'));
            if (jobDetailTask) {
                const row = jobDetailTask.closest('[data-job-v4-task-row]');
                const taskIdentifier = row?.dataset.taskId || jobDetailTask.dataset.task || jobDetailTask.dataset.taskId;
                if (taskIdentifier) await Promise.resolve(window.openTask(taskIdentifier));
            } else if (task) await Promise.resolve(window.openTask(task.dataset.task));
            else if (job) await Promise.resolve(window.openJob(job.dataset.job));
            else if (client) await Promise.resolve(window.openClient(client.dataset.client));
            else if (documentRecord) await Promise.resolve(window.openDoc(documentRecord.dataset.doc));
            else if (quickCreate) window.openJobModal(quickCreate.dataset.newClientJob || undefined);
        } finally {
            finishPageLoading(kind === 'task' ? 'board' : kind === 'client' ? 'clients' : kind === 'document' ? 'documents' : 'jobs');
            endForegroundNavigation();
        }
        } catch (error) {
            if (typeof window.flowtrackResetNavigationProgress === 'function') {
                window.flowtrackResetNavigationProgress();
            }
            const message = error?.message || 'This section could not be loaded. Please try again.';
            if (typeof window.showToast === 'function') window.showToast(message);
            else console.error(error);
        }
    }, true);

    window.addEventListener('flowtrack:navigation-complete', (event) => {
        finishPageLoading(event?.detail?.page || 'page');
    });
    window.addEventListener('pageshow', cancelPageLoading);
    window.addEventListener('unhandledrejection', cancelPageLoading);

    const ready = (async () => {
        await load('core');
        if (!window.FLOWTRACK_AUTHENTICATED) {
            document.querySelector('#authLoadingScreen')?.classList.add('hidden');
            document.querySelector('#loginScreen')?.classList.remove('hidden');
            window.dispatchEvent(new Event('flowtrack:assets-ready'));
            return;
        }

        window.FLOWTRACK_BOOT_IN_PROGRESS = true;
        document.body.classList.add('flowtrack-booting');
        beginForegroundNavigation();
        const info = await ensureForPage(window.location.pathname);
        if (info.canonicalPath && info.canonicalPath !== window.location.pathname && window.history?.replaceState) {
            window.history.replaceState({ flowtrackCanonical: true }, '', info.canonicalPath);
        }
        if (info.kind === 'task' && window.FLOWTRACK_FINAL_TASK_RENDERER_READY !== true) {
            throw new Error('The final Task Details module did not initialize.');
        }
        if (info.kind === 'job' && window.FLOWTRACK_FINAL_JOB_RENDERER_READY !== true) {
            throw new Error('The final Job Details module did not initialize.');
        }
        installRouteSync();
        await window.loadBootstrap({ resetOperational: true });
        // Keep the existing authoritative page-by-page loading contract. No
        // persisted page-data cache is restored here, so reloads cannot show an
        // old workspace snapshot. Direct read-only detail routes only need their
        // page configuration before the authoritative detail endpoint runs; they
        // no longer download the entire parent list first. Create/edit screens
        // still load their parent-page data because their forms depend on it.
        const directDetail = ['job', 'task', 'client'].includes(info.kind);
        if (directDetail && typeof window.flowtrackEnsurePageContext === 'function') {
            await window.flowtrackEnsurePageContext(info.page);
        } else if (typeof window.flowtrackEnsurePageData === 'function') {
            await window.flowtrackEnsurePageData(info.page);
        }

        // Hard refresh is an atomic boot: while the exact route/form/detail is
        // constructed, the application shell and temporary modal/drawer sources
        // remain hidden behind the same circular loader. This prevents form-modal
        // flashes and legacy detail templates from becoming visible.
        await Promise.resolve(window.showApplication(info.page));
        await waitForFinalRouteMount(info);

        // Let synchronous final renderers and their microtasks settle, then remove
        // any transient modal/drawer source state before revealing the app.
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        document.querySelector('#overlay')?.classList.remove('open');
        const jobModal = document.querySelector('#jobModal');
        jobModal?.classList.remove('open', 'document-job-chooser');
        const detailDrawer = document.querySelector('#detailDrawer');
        detailDrawer?.classList.remove('open');

        if (typeof window.flowtrackFinishLoadingProgress === 'function') window.flowtrackFinishLoadingProgress('FlowTrack loaded');
        document.querySelector('#loginScreen')?.classList.add('hidden');
        document.querySelector('#authLoadingScreen')?.classList.add('hidden');
        document.querySelector('#appScreen')?.classList.remove('hidden');
        window.FLOWTRACK_BOOT_IN_PROGRESS = false;
        document.body.classList.remove('flowtrack-booting');
        window.dispatchEvent(new Event('flowtrack:assets-ready'));
        prefetchRemainingModules();
        endForegroundNavigation();
    })().catch((error) => {
        window.FLOWTRACK_BOOT_IN_PROGRESS = false;
        document.body.classList.remove('flowtrack-booting');
        endForegroundNavigation();
        if ([401, 419].includes(error?.status)) {
            window.location.replace('/login?session=' + (error?.payload?.reason === 'replaced' ? 'replaced' : 'expired'));
            return;
        }
        document.querySelector('#authLoadingScreen')?.classList.add('hidden');
        const loadingScreen = document.querySelector('#authLoadingScreen');
        const loadingCard = loadingScreen?.querySelector('.auth-loading-card');
        if (loadingScreen && loadingCard) {
            loadingCard.innerHTML = '<b>FlowTrack could not finish loading</b><div class="small muted auth-loading-help">Your session is still safe. Check the connection and retry.</div><button class="primary" type="button" data-flowtrack-retry>Retry loading</button>';
            loadingCard.querySelector('[data-flowtrack-retry]')?.addEventListener('click', () => window.location.reload());
            loadingScreen.classList.remove('hidden');
        }
        if (typeof window.showToast === 'function') window.showToast(error.message || 'FlowTrack could not finish loading.');
        else console.error(error);
    });

    api.ready = ready;
    Object.freeze(api);
})();
