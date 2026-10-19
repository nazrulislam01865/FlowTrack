const isTruthyNavigationValue = (value) => ['1', 'true', 'yes', 'on'].includes(String(value || '').toLowerCase());

const sidebarLinkMatches = (link, currentUrl) => {
    if (!(link instanceof HTMLAnchorElement)) return false;

    let targetUrl;
    try {
        targetUrl = new URL(link.href, window.location.origin);
    } catch (_) {
        return false;
    }

    const route = link.dataset.ftNavRoute || '';
    const currentPath = currentUrl.pathname.replace(/\/$/, '') || '/';
    const targetPath = targetUrl.pathname.replace(/\/$/, '') || '/';

    // Setup create/edit screens live below their list path. Keep the parent
    // navigation item active while visiting those nested URLs.
    if (route === 'workflow.setup' || route === 'task-pack.setup') {
        return currentPath === targetPath || currentPath.startsWith(`${targetPath}/`);
    }

    if (currentPath !== targetPath) return false;

    const currentCreate = isTruthyNavigationValue(currentUrl.searchParams.get('create'));
    const targetCreate = isTruthyNavigationValue(targetUrl.searchParams.get('create'));

    // These screens intentionally share one route for list/detail/create.
    // Query-aware matching prevents both list and create links being active.
    if (['jobs.index', 'inquiries.index', 'clients.index'].includes(route)) {
        return targetCreate ? currentCreate : !currentCreate;
    }

    if (route === 'master-data') {
        const targetGroup = targetUrl.searchParams.get('group') || 'product';
        const currentGroup = currentUrl.searchParams.get('group') || 'product';
        if (targetGroup !== currentGroup) return false;

        return targetCreate ? currentCreate : !currentCreate;
    }

    if (route === 'financial-master-data') {
        const targetGroup = targetUrl.searchParams.get('group');
        const currentGroup = currentUrl.searchParams.get('group');
        return targetGroup ? targetGroup === currentGroup : true;
    }

    if (route === 'administration') {
        const targetTab = targetUrl.searchParams.get('tab');
        const currentTab = currentUrl.searchParams.get('tab');

        if (targetTab) return targetTab === currentTab;
        return !['settings', 'branding'].includes(currentTab || '');
    }

    // Generic sidebar links use path matching. Ignore incidental filter/detail
    // query parameters so list links remain active on their detail variants.
    return true;
};

export const syncSidebarNavigation = () => {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const currentUrl = new URL(window.location.href);
    const links = sidebar.querySelectorAll('a.nav-btn[data-ft-nav-route]');

    links.forEach((link) => {
        const active = sidebarLinkMatches(link, currentUrl);
        link.classList.toggle('active', active);

        if (active) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
    });

    sidebar.querySelectorAll('details.ft-sidebar-group').forEach((group) => {
        const active = Boolean(group.querySelector('a.nav-btn.active'));
        const summary = group.querySelector(':scope > .ft-sidebar-group-toggle');

        summary?.classList.toggle('is-active', active);
        group.open = active;
    });
};

export const bootShell = () => {
    const sidebar = document.getElementById('sidebar');
    const shade = document.getElementById('sidebarShade');
    const menu = document.getElementById('mobileMenu');

    // The sidebar is persisted across wire:navigate visits, so its server-side
    // request()->routeIs() classes are intentionally not re-rendered. Refresh
    // only the tiny active/open state from the current URL instead of replacing
    // the full sidebar DOM (which was the source of the visible flash).
    syncSidebarNavigation();

    const closeSidebar = () => {
        sidebar?.classList.remove('open');
        shade?.classList.remove('open');
    };

    if (menu && !menu.dataset.flowtrackBound) {
        menu.dataset.flowtrackBound = '1';
        menu.addEventListener('click', () => {
            sidebar?.classList.add('open');
            shade?.classList.add('open');
        });
    }
    if (shade && !shade.dataset.flowtrackBound) {
        shade.dataset.flowtrackBound = '1';
        shade.addEventListener('click', closeSidebar);
    }

    // On phones/tablets, close the off-canvas navigation immediately after a
    // destination is chosen. This also works with Livewire wire:navigate.
    if (sidebar && !sidebar.dataset.flowtrackNavCloseBound) {
        sidebar.dataset.flowtrackNavCloseBound = '1';
        sidebar.addEventListener('click', (event) => {
            const link = event.target.closest('a.nav-btn, a.ft-system-brand');
            if (!link || window.matchMedia('(min-width: 901px)').matches) return;
            closeSidebar();
        });
    }
};
