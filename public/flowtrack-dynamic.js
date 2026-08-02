/* FlowTrack route-aware JavaScript loader. Build: 6254af6ca40e4526. */
(() => {
    'use strict';

    const build = '6254af6ca40e4526';
    const files = {
        "core": "core.js",
        "workspace": "workspace.js",
        "operations": "operations.js",
        "configuration": "configuration.js",
        "records": "records.js",
        "job-shared": "job-shared.js",
        "task-details": "task-details.js",
        "job-details": "job-details.js",
        "board": "board.js"
};
    const loaded = new Set();
    const pending = new Map();
    const currentScript = document.currentScript;
    const root = new URL('./flowtrack/modules/', currentScript?.src || window.location.href);

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
            // The Jobs list needs configuration helpers, but Job detail/edit
            // code is fetched only when the user opens or edits a Job.
            await ensureConfiguration();
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
            const needs = (!['clients', 'documents'].includes(page) && !loaded.has('operations'))
                || (['board', 'mywork'].includes(page) && !loaded.has('board'))
                || (['workflowsetup', 'taskpacksetup', 'masterdata', 'setup', 'profile'].includes(page) && !loaded.has('records'))
                || (page === 'jobs' && !loaded.has('configuration'));
            if (needs) {
                event.preventDefault();
                event.stopImmediatePropagation();
                await ensureForPage(page);
                await window.flowtrackEnsurePageData?.(page);
                installRouteSync();
                window.dispatchEvent(new Event('flowtrack:assets-ready'));
                window.navigate(page);
            }
            return;
        }

        const blockedAction = target.closest('[data-delete-job],[data-edit-job],[data-job-action],[data-delete-task],[data-task-action]');
        const task = !blockedAction ? target.closest('[data-task]') : null;
        const job = !blockedAction ? target.closest('[data-job]') : null;
        const jobLazyAction = target.closest('[data-board-job-link],[data-job-list-menu],[data-job-list-edit-owner],[data-job-list-edit-delivery],#jobCreate,#boardCreate');
        const client = target.closest('[data-client]');
        const documentRecord = target.closest('[data-doc]');
        const quickCreate = target.closest('#quickCreateBtn,[data-dashboard-action="create"],[data-new-client-job]');
        const kind = task ? 'task' : (job || jobLazyAction || quickCreate ? 'job' : (client ? 'client' : (documentRecord ? 'document' : null)));
        const requiredModule = kind === 'task' ? 'task-details' : (kind === 'job' ? 'job-details' : 'job-shared');
        if (!kind || loaded.has(requiredModule)) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        await ensureDetail(kind === 'document' || kind === 'client' ? 'shared' : kind);
        const dataPage = kind === 'task' ? 'board' : kind === 'job' ? 'jobs' : kind === 'client' ? 'clients' : kind === 'document' ? 'documents' : null;
        if (dataPage) await window.flowtrackEnsurePageData?.(dataPage);
        installRouteSync();
        window.dispatchEvent(new Event('flowtrack:assets-ready'));
        if (jobLazyAction) {
            // Re-dispatch once after the heavy Job module is present. Capture
            // sees it as loaded on the second pass, so the normal handler runs.
            jobLazyAction.click();
        } else if (task) window.openTask(task.dataset.task);
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
        await window.flowtrackEnsurePageData?.(info.page);
        window.showApplication(info.page);
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
