/* Generated from resources/js/flowtrack/loader.js. Build: f400609959067571. */
const build = "f400609959067571";
const files = Object.freeze({
    "core": "core.js",
    "workspace": "workspace.js",
    "operations": "operations.js",
    "configuration": "configuration.js",
    "records": "records.js",
    "job-shared": "job-shared.js",
    "task-details": "task-details.js",
    "job-details": "job-details.js",
    "board": "board.js"
});

(() => {
    'use strict';

    const loaded = new Set();
    const pending = new Map();
    let clickNavigationSequence = 0;
    let deferredNavigationSequence = 0;
    const root = new URL('/flowtrack/modules/', window.location.origin);

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
        if (!parts.length) return { page: 'dashboard', kind: 'page' };
        if (parts[0] === 'tasks' && parts[1]) return { page: 'board', kind: 'task' };
        if (parts[0] === 'jobs' && parts[1]) return { page: 'jobs', kind: 'job' };
        if (parts[0] === 'clients' && parts[1]) return { page: 'clients', kind: 'client' };
        if (parts[0] === 'workflows' && parts[1]) return { page: 'workflowsetup', kind: 'configuration-record' };
        if (parts[0] === 'task-packs' && parts[1]) return { page: 'taskpacksetup', kind: 'configuration-record' };
        return { page: parts[0], kind: 'page' };
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
        } else if (info.kind === 'client' || info.kind === 'configuration-record') {
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
                void window.flowtrackEnsurePageData(normalized).then(() => {
                    // Ignore an older async navigation if the user has already
                    // selected another screen while its data was loading.
                    if (navigationId !== deferredNavigationSequence) return;
                    const result = implementation.apply(context, args);
                    if (result !== false) syncPageRoute(normalized);
                }).catch((error) => {
                    if (navigationId !== deferredNavigationSequence) return;
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
            const navigationId = ++clickNavigationSequence;
            const needs = (!['clients', 'documents'].includes(page) && !loaded.has('operations'))
                || (['board', 'mywork'].includes(page) && !loaded.has('board'))
                || (['workflowsetup', 'taskpacksetup', 'masterdata', 'setup', 'profile'].includes(page) && !loaded.has('records'))
                || (page === 'jobs' && !loaded.has('job-details'));
            const needsData = typeof window.flowtrackIsPageDataReady === 'function'
                && !window.flowtrackIsPageDataReady(page);
            if (needs || needsData) {
                event.preventDefault();
                event.stopImmediatePropagation();
                await ensureForPage(page);
                if (typeof window.flowtrackEnsurePageData === 'function') await window.flowtrackEnsurePageData(page);
                if (navigationId !== clickNavigationSequence) return;
                installRouteSync();
                window.dispatchEvent(new Event('flowtrack:assets-ready'));
                window.navigate(page);
            }
            return;
        }

        const blockedAction = target.closest('[data-delete-job],[data-edit-job],[data-job-action],[data-delete-task],[data-task-action]');
        const task = !blockedAction ? target.closest('[data-task]') : null;
        const job = !blockedAction ? target.closest('[data-job]') : null;
        const client = target.closest('[data-client]');
        const documentRecord = target.closest('[data-doc]');
        const quickCreate = target.closest('#quickCreateBtn,[data-dashboard-action="create"],[data-new-client-job]');
        const kind = task ? 'task' : (job || quickCreate ? 'job' : (client ? 'client' : (documentRecord ? 'document' : null)));
        const requiredModule = kind === 'task' ? 'task-details' : (kind === 'job' ? 'job-details' : 'job-shared');
        if (!kind || loaded.has(requiredModule)) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        await ensureDetail(kind === 'document' || kind === 'client' ? 'shared' : kind);
        installRouteSync();
        window.dispatchEvent(new Event('flowtrack:assets-ready'));
        if (task) window.openTask(task.dataset.task);
        else if (job) window.openJob(job.dataset.job);
        else if (client) window.openClient(client.dataset.client);
        else if (documentRecord) window.openDoc(documentRecord.dataset.doc);
        else if (quickCreate) window.openJobModal(quickCreate.dataset.newClientJob || undefined);
        } catch (error) {
            const message = error?.message || 'This section could not be loaded. Please try again.';
            if (typeof window.showToast === 'function') window.showToast(message);
            else console.error(error);
        }
    }, true);

    const ready = (async () => {
        await load('core');
        if (!window.FLOWTRACK_AUTHENTICATED) {
            document.querySelector('#authLoadingScreen')?.classList.add('hidden');
            document.querySelector('#loginScreen')?.classList.remove('hidden');
            window.dispatchEvent(new Event('flowtrack:assets-ready'));
            return;
        }

        const info = await ensureForPage(window.location.pathname);
        installRouteSync();
        await window.loadBootstrap();
        // Load only the requested page's data before its first paint. This keeps
        // lazy login behavior, but avoids briefly rendering empty/partial cards
        // and then making records appear several seconds later.
        if (typeof window.flowtrackEnsurePageData === 'function') await window.flowtrackEnsurePageData(info.page);
        window.showApplication(info.page);
        if (typeof window.flowtrackFinishLoadingProgress === 'function') window.flowtrackFinishLoadingProgress('FlowTrack loaded');
        window.dispatchEvent(new Event('flowtrack:assets-ready'));
    })().catch((error) => {
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
