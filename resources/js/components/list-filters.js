const normaliseOptionValue = (value) => String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ');

    const uniqueOptions = (items = []) => {
        const seen = new Set();
        return items.filter((item) => {
            const id = String(item?.id ?? '');
            if (!id || seen.has(id)) return false;
            seen.add(id);
            return true;
        });
    };

    // Central remote-dropdown sizing contract. Remote selectors should always
    // reopen with a compact recent page. Search can still return a wider page,
    // and Load more remains available, but those expanded rows must never leak
    // into the next open and make the dropdown grow after repeated use.
    const REMOTE_RECENT_PAGE_SIZE = 5;
    const REMOTE_SEARCH_PAGE_SIZE = 20;
    const REMOTE_MENU_HEIGHT_CAP = 280;

    const normaliseRecentPageSize = (value) => {
        const parsed = Number(value || REMOTE_RECENT_PAGE_SIZE);
        return Math.max(1, Math.min(10, Number.isFinite(parsed) ? parsed : REMOTE_RECENT_PAGE_SIZE));
    };

    const normalisePastedSearchText = (value) => String(value ?? '')
        .replace(/[\u200B-\u200D\uFEFF]/g, '')
        .replace(/\u00A0/g, ' ')
        .replace(/[\r\n\t]+/g, ' ')
        .replace(/\s{2,}/g, ' ')
        .trim();

    const isSearchInput = (target) => {
        if (!(target instanceof HTMLInputElement)) return false;
        const placeholder = String(target.getAttribute('placeholder') || '').trim().toLowerCase();
        return target.type === 'search'
            || target.getAttribute('role') === 'searchbox'
            || target.classList.contains('ft-remote-filter-search')
            || placeholder.startsWith('search');
    };

    // Search inputs are used throughout FlowTrack by both Livewire and Alpine.
    // Normal browser paste normally fires an input event, but text copied from
    // email, Excel, chat apps and PDFs can contain NBSP/zero-width/newline
    // characters that make a visually identical search fail. Handle paste in
    // one place, preserve the user's current selection, and emit a real input
    // event so every search implementation reacts exactly like typed text.
    document.addEventListener('paste', (event) => {
        const input = event.target;
        if (!isSearchInput(input)) return;

        const clipboardText = event.clipboardData?.getData('text/plain');
        if (typeof clipboardText !== 'string') return;

        const pasted = normalisePastedSearchText(clipboardText);
        event.preventDefault();

        const start = Number.isInteger(input.selectionStart) ? input.selectionStart : input.value.length;
        const end = Number.isInteger(input.selectionEnd) ? input.selectionEnd : start;
        input.setRangeText(pasted, start, end, 'end');

        let inputEvent;
        try {
            inputEvent = new InputEvent('input', {
                bubbles: true,
                composed: true,
                inputType: 'insertFromPaste',
                data: pasted,
            });
        } catch (_) {
            inputEvent = new Event('input', {bubbles: true, composed: true});
        }
        input.dispatchEvent(inputEvent);
    }, true);

    const measureNaturalMenuHeight = (menu, heightCap) => {
        // Fixed remote menus are flex columns. After several open/search/select
        // cycles the previous inline max-height can leave the scrollable option
        // list with a zero flex height even though it still contains rows. Reading
        // only menu.scrollHeight at that point reproduces the collapsed height and
        // the next open looks "empty". Measure the list independently while both
        // containers are unconstrained, then restore every inline style exactly.
        const list = menu.querySelector('.ft-remote-filter-list');
        const menuSnapshot = {
            maxHeight: menu.style.getPropertyValue('max-height'),
            maxHeightPriority: menu.style.getPropertyPriority('max-height'),
            height: menu.style.getPropertyValue('height'),
            heightPriority: menu.style.getPropertyPriority('height'),
            overflow: menu.style.getPropertyValue('overflow'),
            overflowPriority: menu.style.getPropertyPriority('overflow'),
        };
        const listSnapshot = list ? {
            flex: list.style.getPropertyValue('flex'),
            flexPriority: list.style.getPropertyPriority('flex'),
            maxHeight: list.style.getPropertyValue('max-height'),
            maxHeightPriority: list.style.getPropertyPriority('max-height'),
            height: list.style.getPropertyValue('height'),
            heightPriority: list.style.getPropertyPriority('height'),
            overflow: list.style.getPropertyValue('overflow'),
            overflowPriority: list.style.getPropertyPriority('overflow'),
        } : null;

        menu.style.setProperty('max-height', 'none', 'important');
        menu.style.setProperty('height', 'auto', 'important');
        menu.style.setProperty('overflow', 'visible', 'important');
        if (list) {
            list.style.setProperty('flex', '0 0 auto', 'important');
            list.style.setProperty('max-height', 'none', 'important');
            list.style.setProperty('height', 'auto', 'important');
            list.style.setProperty('overflow', 'visible', 'important');
        }

        const measuredHeight = Math.max(menu.scrollHeight || 0, menu.getBoundingClientRect().height || 0);

        const restore = (node, property, value, priority) => {
            if (!node) return;
            if (value) node.style.setProperty(property, value, priority);
            else node.style.removeProperty(property);
        };
        restore(menu, 'max-height', menuSnapshot.maxHeight, menuSnapshot.maxHeightPriority);
        restore(menu, 'height', menuSnapshot.height, menuSnapshot.heightPriority);
        restore(menu, 'overflow', menuSnapshot.overflow, menuSnapshot.overflowPriority);
        if (list && listSnapshot) {
            restore(list, 'flex', listSnapshot.flex, listSnapshot.flexPriority);
            restore(list, 'max-height', listSnapshot.maxHeight, listSnapshot.maxHeightPriority);
            restore(list, 'height', listSnapshot.height, listSnapshot.heightPriority);
            restore(list, 'overflow', listSnapshot.overflow, listSnapshot.overflowPriority);
        }

        return Math.min(heightCap, Math.max(120, measuredHeight || heightCap));
    };

    const findFixedContainingBlock = (node) => {
        let parent = node?.parentElement || null;

        while (parent && parent !== document.documentElement) {
            const style = window.getComputedStyle(parent);
            const contain = String(style.contain || '');
            const willChange = String(style.willChange || '');
            const contentVisibility = String(style.contentVisibility || 'visible');
            const hasBackdropFilter = Boolean(style.backdropFilter) && style.backdropFilter !== 'none';

            // position:fixed is viewport-relative only when no ancestor creates a
            // fixed-position containing block. My Tasks order groups use
            // content-visibility/contain for performance, so a fixed dropdown
            // nested inside them is otherwise offset by the group's own position.
            if (
                style.transform !== 'none'
                || style.perspective !== 'none'
                || style.filter !== 'none'
                || hasBackdropFilter
                || contain.includes('layout')
                || contain.includes('paint')
                || contain.includes('strict')
                || contain.includes('content')
                || contentVisibility === 'auto'
                || contentVisibility === 'hidden'
                || /transform|perspective|filter/.test(willChange)
            ) {
                return parent;
            }

            parent = parent.parentElement;
        }

        return null;
    };

    const usableAnchorRect = (rect) => {
        if (!rect) return null;

        const values = [rect.left, rect.top, rect.right, rect.bottom, rect.width, rect.height]
            .map((value) => Number(value));
        if (values.some((value) => !Number.isFinite(value))) return null;
        if (Number(rect.width) <= 0 || Number(rect.height) <= 0) return null;

        return rect;
    };

    const positionDropdown = (component) => {
        const trigger = component.$refs?.trigger;
        const menu = component.$refs?.menu;

        // External inline-edit anchors are commonly hidden as soon as the editor
        // opens. A hidden element still exists, but getBoundingClientRect() then
        // returns 0,0,0,0. Prefer a live, usable external anchor; otherwise anchor
        // to the newly visible picker trigger, and finally use the pre-edit rect
        // snapshot supplied by inline-edit.js. This keeps the menu beside the
        // assignee field instead of incorrectly rendering at the page's top-left.
        const externalRect = usableAnchorRect(component.externalAnchorEl?.getBoundingClientRect?.());
        const triggerRect = usableAnchorRect(trigger?.getBoundingClientRect?.());
        const snapshotRect = usableAnchorRect(component.externalAnchorRect);
        const rect = externalRect || triggerRect || snapshotRect;

        if (!rect || !menu || !component.open) return;
        const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        const edge = 12;
        const gap = 5;

        // Follow the supplied prototype: the menu stays anchored to the
        // control that opened it. It may be wider than the trigger, but it
        // never participates in page layout or horizontal board scrolling.
        const availableWidth = Math.max(0, viewportWidth - (edge * 2));
        const requestedWidth = Number(component.menuWidth || 320);
        const preferredWidth = Number.isFinite(requestedWidth) ? Math.max(rect.width, requestedWidth) : 320;
        const width = Math.min(preferredWidth, availableWidth);
        const alignRight = rect.left + width > viewportWidth - edge;

        const roomBelow = Math.max(0, viewportHeight - rect.bottom - edge - gap);
        const roomAbove = Math.max(0, rect.top - edge - gap);
        const heightCap = component.searchable === false ? 300 : Number(component.menuMaxHeight || REMOTE_MENU_HEIGHT_CAP);
        const naturalHeight = measureNaturalMenuHeight(menu, heightCap);
        const openAbove = roomBelow < Math.min(190, naturalHeight) && roomAbove > roomBelow;
        const availableHeight = Math.max(120, Math.min(naturalHeight, openAbove ? roomAbove : roomBelow || naturalHeight));

        if (component.fixedMenu) {
            // Inline editors can live inside rounded cards/panels that intentionally
            // use overflow:hidden. A fixed menu is anchored to the viewport rather
            // than that card, so the final-row assignee picker can never be clipped
            // by the following Attachments/Activity section.
            const left = alignRight
                ? Math.max(edge, Math.min(rect.right - width, viewportWidth - width - edge))
                : Math.max(edge, Math.min(rect.left, viewportWidth - width - edge));
            const preferredTop = openAbove
                ? rect.top - availableHeight - gap
                : rect.bottom + gap;
            const top = Math.max(edge, Math.min(preferredTop, viewportHeight - availableHeight - edge));

            const containingBlock = findFixedContainingBlock(menu);
            const containingRect = containingBlock?.getBoundingClientRect?.() || null;
            const containingLeft = containingRect
                ? containingRect.left + Number(containingBlock.clientLeft || 0)
                : 0;
            const containingTop = containingRect
                ? containingRect.top + Number(containingBlock.clientTop || 0)
                : 0;
            const renderedLeft = left - containingLeft;
            const renderedTop = top - containingTop;

            component.menuStyle = [
                'position:fixed!important',
                `left:${Math.round(renderedLeft)}px!important`,
                `top:${Math.round(renderedTop)}px!important`,
                'right:auto!important',
                'bottom:auto!important',
                `width:${Math.round(width)}px!important`,
                `max-width:${Math.round(availableWidth)}px!important`,
                `max-height:${Math.round(availableHeight)}px!important`,
                'z-index:2450!important',
            ].join(';');
            return;
        }

        component.menuStyle = [
            'position:absolute!important',
            `width:${Math.round(width)}px!important`,
            `max-width:${Math.round(availableWidth)}px!important`,
            `max-height:${Math.round(availableHeight)}px!important`,
            alignRight ? 'right:0!important' : 'left:0!important',
            alignRight ? 'left:auto!important' : 'right:auto!important',
            openAbove ? `bottom:calc(100% + ${gap}px)!important` : `top:calc(100% + ${gap}px)!important`,
            openAbove ? 'top:auto!important' : 'bottom:auto!important',
        ].join(';');
    };

    const focusElementWithoutScroll = (element) => {
        if (!element) return null;

        const scrollLeft = window.scrollX;
        const scrollTop = window.scrollY;

        try {
            element.focus({preventScroll: true});
        } catch (_) {
            // Older engines may not support FocusOptions. Restore the viewport
            // immediately so opening a teleported search field can never move
            // the page to the menu's temporary pre-positioned location.
            element.focus();
        }

        if (window.scrollX !== scrollLeft || window.scrollY !== scrollTop) {
            window.scrollTo(scrollLeft, scrollTop);
        }

        return {scrollLeft, scrollTop};
    };

    const positionAndFocusDropdown = (component) => {
        // Position synchronously inside Alpine's post-render tick before focus.
        // Calling component.reposition() here would add a second tick, allowing
        // the browser to scroll to the teleported menu before it is anchored.
        positionDropdown(component);
        const viewportBeforeFocus = focusElementWithoutScroll(component.$refs?.search);

        // Images, fonts and remote rows can settle after the first measurement.
        // Re-anchor once more without moving focus or changing page scroll.
        window.requestAnimationFrame(() => {
            if (
                viewportBeforeFocus
                && (window.scrollX !== viewportBeforeFocus.scrollLeft || window.scrollY !== viewportBeforeFocus.scrollTop)
            ) {
                window.scrollTo(viewportBeforeFocus.scrollLeft, viewportBeforeFocus.scrollTop);
            }
            if (component.open) positionDropdown(component);
        });
    };

    const positioningMethods = {
        menuStyle: '',
        reposition() {
            this.$nextTick(() => positionDropdown(this));
        },
        openPositionedMenu() {
            // A teleported menu initially lives at the end of <body>. Keep that
            // intermediate frame hidden until its viewport coordinates exist.
            this.menuStyle = 'visibility:hidden!important;pointer-events:none!important';
            this.open = true;
            this.reposition();
        },
        focusSearchWithoutScroll() {
            positionAndFocusDropdown(this);
        },
    };

    const positionFloatingActionMenu = (component) => {
        const trigger = component.$refs?.trigger;
        const menu = component.$refs?.menu;
        if (!trigger || !menu) return;

        const triggerRect = trigger.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        const viewportHeight = document.documentElement.clientHeight || window.innerHeight;
        const edge = 10;
        const gap = 6;
        const width = menu.offsetWidth || 168;
        const height = menu.offsetHeight || 120;
        const roomBelow = viewportHeight - triggerRect.bottom - edge - gap;
        const roomAbove = triggerRect.top - edge - gap;
        const openAbove = roomBelow < height && roomAbove > roomBelow;
        const maxLeft = Math.max(edge, viewportWidth - width - edge);
        const left = Math.min(Math.max(edge, triggerRect.right - width), maxLeft);
        const preferredTop = openAbove
            ? triggerRect.top - height - gap
            : triggerRect.bottom + gap;
        const maxTop = Math.max(edge, viewportHeight - height - edge);
        const top = Math.min(Math.max(edge, preferredTop), maxTop);

        component.menuStyle = [
            'position:fixed!important',
            `left:${Math.round(left)}px!important`,
            `top:${Math.round(top)}px!important`,
            'right:auto!important',
            'bottom:auto!important',
            `z-index:${Math.max(1, Number(component.menuZIndex || 1200))}!important`,
        ].join(';');
    };

export const createFloatingActionMenu = () => ({
        menuStyle: '',
        positionMenu() {
            this.$nextTick(() => positionFloatingActionMenu(this));
        },
    });

export const createRemoteFilter = (config) => {
        const initialItems = Array.isArray(config.initialItems) ? config.initialItems : [];
        const recentPageSize = normaliseRecentPageSize(config.recentPageSize);
        const initialRecentItems = initialItems.slice(0, recentPageSize);
        // Do not seed the remote-response cache from render-time items. They do
        // not carry pagination metadata, so treating them as a real page-one
        // response can incorrectly hide Load more after a Livewire morph.
        const initialCache = new Map();
        const initialLabels = new Map(
            initialItems.map((item) => [String(item?.id ?? ''), String(item?.label ?? '')])
        );

        return {
            ...positioningMethods,
            searchable: true,
            menuWidth: Number(config.menuWidth || 320),
            menuMaxHeight: Number(config.menuMaxHeight || REMOTE_MENU_HEIGHT_CAP),
            fixedMenu: config.fixedMenu === true,
            disabled: config.disabled === true,
            externalAnchorEl: null,
            externalAnchorRect: null,
            open: false,
            query: '',
            loading: false,
            items: initialRecentItems,
            recentItems: initialRecentItems,
            recentHasMore: initialItems.length > recentPageSize,
            recentNextPage: initialItems.length > recentPageSize ? 2 : null,
            recentPageSize,
            params: config.params && typeof config.params === 'object' ? {...config.params} : {},
            selectedValue: String(config.value || ''),
            selectedLabel: config.selectedLabel || config.placeholder,
            message: 'Recent options shown instantly. Type 2 characters to search.',
            page: 1,
            perPage: recentPageSize,
            hasMore: initialItems.length > recentPageSize,
            nextPage: initialItems.length > recentPageSize ? 2 : null,
            minSearchLength: 2,
            controller: null,
            cache: initialCache,
            knownLabels: initialLabels,
            recentLoaded: initialRecentItems.length > 0,
            requestSequence: 0,
            pendingValue: '',
            pendingLabel: '',
            pendingPreviousValue: '',
            pendingPreviousLabel: '',
            pendingAt: 0,
            get visibleItems() {
                return this.items;
            },
            rememberItems(items = []) {
                if (!Array.isArray(items)) return;
                items.forEach((item) => {
                    const id = String(item?.id ?? '');
                    const label = String(item?.label ?? '');
                    if (id && label) this.knownLabels.set(id, label);
                });
            },
            restoreCompactRecentPage() {
                this.items = [...this.recentItems];
                this.page = 1;
                this.perPage = this.recentPageSize;
                this.hasMore = this.recentHasMore;
                this.nextPage = this.recentNextPage;
                this.message = this.resultMessage('');
            },
            toggle() {
                if (this.disabled) return;
                this.open ? this.close() : this.openMenu();
            },
            openMenu() {
                if (this.disabled) return;
                this.restoreCompactRecentPage();
                this.openPositionedMenu();
                // Show the render-provided recent options immediately, then refresh
                // them in the background so each open reflects current source data.
                this.searchOptions(true);
                this.$nextTick(() => {
                    this.focusSearchWithoutScroll();
                });
            },
            close() {
                this.open = false;
                this.menuStyle = '';
                this.externalAnchorEl = null;
                this.externalAnchorRect = null;
                this.query = '';
                this.loading = false;
                this.requestSequence++;
                this.controller?.abort();
                this.controller = null;
                this.restoreCompactRecentPage();
            },
            closeAfterSelection() {
                // A searched option can still have a debounced search response or
                // a Livewire morph queued behind the click. close() invalidates and
                // aborts that work before the Livewire value update is started.
                this.close();
                this.$nextTick(() => {
                    this.open = false;
                    this.query = '';
                    try {
                        this.$refs.trigger?.focus({preventScroll: true});
                    } catch (_) {
                        this.$refs.trigger?.focus();
                    }
                });
            },
            focusFirst() {
                this.$refs.menu?.querySelector('.ft-remote-filter-list .ft-remote-filter-option')?.focus();
            },
            moveOption(direction) {
                const buttons = [...(this.$refs.menu?.querySelectorAll('.ft-remote-filter-list .ft-remote-filter-option') || [])];
                if (!buttons.length) return;
                const index = buttons.indexOf(document.activeElement);
                const next = index < 0 ? 0 : Math.max(0, Math.min(buttons.length - 1, index + direction));
                buttons[next]?.focus();
            },
            focusBoundary(boundary) {
                const buttons = [...(this.$refs.menu?.querySelectorAll('.ft-remote-filter-list .ft-remote-filter-option') || [])];
                if (!buttons.length) return;
                (boundary === 'last' ? buttons[buttons.length - 1] : buttons[0])?.focus();
            },
            async searchOptions(force = false, append = false) {
                const q = this.query.trim();
                if (q.length > 0 && q.length < this.minSearchLength) {
                    this.controller?.abort();
                    this.loading = false;
                    this.items = [];
                    this.page = 1;
                    this.hasMore = false;
                    this.nextPage = null;
                    this.message = `Type at least ${this.minSearchLength} characters to search.`;
                    this.reposition();
                    return;
                }

                const requestedPage = append ? Math.max(1, Number(this.nextPage || (this.page + 1))) : 1;
                const key = `${q.toLowerCase()}|${requestedPage}`;
                if (!force && this.cache.has(key)) {
                    const cached = this.cache.get(key);
                    this.items = append ? uniqueOptions([...this.items, ...cached.items]) : cached.items;
                    this.page = cached.page;
                    this.perPage = cached.perPage;
                    this.hasMore = cached.hasMore;
                    this.nextPage = cached.nextPage;
                    this.message = this.resultMessage(q);
                    this.reposition();
                    return;
                }

                this.controller?.abort();
                this.controller = new AbortController();
                const sequence = ++this.requestSequence;
                this.loading = true;
                this.message = append ? 'Loading more…' : (q ? 'Searching…' : 'Loading recent options…');

                try {
                    const url = new URL(config.endpoint, window.location.origin);
                    if (q) url.searchParams.set('q', q);
                    if (config.context) url.searchParams.set('context', config.context);
                    url.searchParams.set('page', String(requestedPage));
                    url.searchParams.set('per_page', String(q ? REMOTE_SEARCH_PAGE_SIZE : this.recentPageSize));
                    if (this.selectedValue) url.searchParams.append('selected[]', this.selectedValue);
                    Object.entries(this.params || {}).forEach(([name, value]) => {
                        if (value !== null && value !== undefined && String(value) !== '') {
                            url.searchParams.set(name, String(value));
                        }
                    });

                    const response = await fetch(url, {
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                        credentials: 'same-origin',
                        signal: this.controller.signal,
                    });
                    if (!response.ok) throw new Error('option-search-failed');

                    const payload = await response.json();
                    if (sequence !== this.requestSequence) return;

                    const pageItems = Array.isArray(payload.items) ? payload.items : [];
                    const selectedItems = Array.isArray(payload.selected_items) ? payload.selected_items : [];
                    this.rememberItems([...selectedItems, ...pageItems]);
                    this.items = append ? uniqueOptions([...this.items, ...pageItems]) : pageItems;
                    this.page = Number(payload.pagination?.page || requestedPage);
                    this.perPage = Number(payload.pagination?.per_page || (q ? REMOTE_SEARCH_PAGE_SIZE : this.recentPageSize));
                    this.hasMore = payload.pagination?.has_more === true;
                    this.nextPage = payload.pagination?.next_page ? Number(payload.pagination.next_page) : null;
                    if (!q && requestedPage === 1 && !append) {
                        this.recentItems = pageItems.slice(0, this.recentPageSize);
                        this.recentHasMore = this.hasMore;
                        this.recentNextPage = this.nextPage;
                    }
                    this.minSearchLength = Number(payload.query?.min_length || this.minSearchLength || 2);
                    this.cache.set(key, {
                        items: pageItems,
                        page: this.page,
                        perPage: this.perPage,
                        hasMore: this.hasMore,
                        nextPage: this.nextPage,
                    });
                    if (!q) this.recentLoaded = true;
                    this.message = this.resultMessage(q);
                    this.reposition();
                } catch (error) {
                    if (error?.name !== 'AbortError') {
                        this.message = 'Could not load options. Try again.';
                    }
                } finally {
                    if (sequence === this.requestSequence) this.loading = false;
                }
            },
            resultMessage(q = this.query.trim()) {
                if (q && this.items.length === 0) return 'No matching options.';
                if (q) return `${this.items.length} result${this.items.length === 1 ? '' : 's'}${this.hasMore ? ' · more available' : ''}`;
                return this.hasMore
                    ? `${this.items.length} recent options · more available`
                    : 'Recent options shown instantly. Type 2 characters to search.';
            },
            loadMore() {
                if (!this.hasMore || this.loading || !this.nextPage) return;
                return this.searchOptions(true, true);
            },
            sync(value, label) {
                this.syncSelection({ value, label }, this.params, this.items);
            },
            syncDisabled(disabled = false) {
                this.disabled = disabled === true;
                if (this.disabled && this.open) this.close();
            },
            syncSelection(selection, params = {}, serverItems = []) {
                const nextParams = params && typeof params === 'object' ? {...params} : {};
                const currentParamsKey = JSON.stringify(this.params || {});
                const nextParamsKey = JSON.stringify(nextParams);
                const freshItems = Array.isArray(serverItems) ? serverItems : [];
                this.rememberItems(freshItems);

                // Dependent selectors (Workflow by Client, Product by
                // Category, etc.) must never keep requests/cache from the
                // previous parent selection. Livewire can preserve Alpine
                // state during a morph, so refresh the selector context here
                // as well as relying on wire:key.
                if (currentParamsKey !== nextParamsKey) {
                    this.controller?.abort();
                    this.requestSequence++;
                    this.params = nextParams;
                    this.recentItems = freshItems.slice(0, this.recentPageSize);
                    this.items = [...this.recentItems];
                    this.cache = new Map();
                    this.recentHasMore = freshItems.length > this.recentPageSize;
                    this.recentNextPage = this.recentHasMore ? 2 : null;
                    this.recentLoaded = this.recentItems.length > 0;
                    this.page = 1;
                    this.perPage = this.recentPageSize;
                    this.hasMore = this.recentHasMore;
                    this.nextPage = this.recentNextPage;
                    this.query = '';
                    this.message = 'Recent options shown instantly. Type 2 characters to search.';
                }

                // Value and label arrive as one server-rendered object. This is
                // important during a Livewire morph: accepting them as separate
                // reactive arguments allowed a new Client id to be paired with
                // the previous Client label for one or more renders.
                const next = String(selection?.value || '');
                const suppliedLabel = selection?.label && selection.label !== config.placeholder
                    ? String(selection.label)
                    : '';

                // A click is optimistic and must stay visible immediately.
                // Other Livewire requests (progressive Create Order sections,
                // a previous selector request, etc.) can finish while the Client
                // change is still in flight. Those responses contain an older
                // server value. Ignore them until the server confirms the exact
                // value the user most recently chose. This prevents the selector
                // from jumping back to the previous Client and then changing a
                // few moments later.
                if (this.pendingAt) {
                    if (next === this.pendingValue) {
                        const confirmedLabel = freshItems.find((candidate) => String(candidate?.id) === next)?.label
                            || this.knownLabels.get(next)
                            || this.pendingLabel
                            || suppliedLabel
                            || next;
                        this.selectedValue = next;
                        this.selectedLabel = String(confirmedLabel);
                        this.knownLabels.set(next, String(confirmedLabel));
                        this.pendingValue = '';
                        this.pendingLabel = '';
                        this.pendingPreviousValue = '';
                        this.pendingPreviousLabel = '';
                        this.pendingAt = 0;
                        return;
                    }

                    if ((Date.now() - this.pendingAt) < 15000) return;

                    // A very old pending selection should not lock the control
                    // forever if a request was interrupted outside Livewire.
                    this.pendingValue = '';
                    this.pendingLabel = '';
                    this.pendingPreviousValue = '';
                    this.pendingPreviousLabel = '';
                    this.pendingAt = 0;
                }

                if (!next) {
                    this.selectedValue = '';
                    this.selectedLabel = config.placeholder;
                    return;
                }

                const item = freshItems.find((candidate) => String(candidate?.id) === next)
                    || this.items.find((candidate) => String(candidate?.id) === next);
                const knownLabel = this.knownLabels.get(next);

                // Never reuse the currently displayed label as a fallback for a
                // server value. Alpine state may have survived a DOM morph while
                // the value changed, which is exactly how "anything" could be
                // selected while "hh" or another previous Client was shown.
                const resolved = item?.label || knownLabel || suppliedLabel || next;

                this.selectedValue = next;
                this.selectedLabel = String(resolved);
                if (resolved && resolved !== next) this.knownLabels.set(next, String(resolved));
            },
            beginPendingSelection(next, label) {
                if (!this.pendingValue) {
                    this.pendingPreviousValue = this.selectedValue;
                    this.pendingPreviousLabel = this.selectedLabel;
                }
                this.pendingValue = String(next || '');
                this.pendingLabel = String(label || config.placeholder);
                this.pendingAt = Date.now();
            },
            selectionFailed() {
                if (!this.pendingValue && !this.pendingAt) return;
                this.selectedValue = this.pendingPreviousValue;
                this.selectedLabel = this.pendingPreviousLabel || config.placeholder;
                this.pendingValue = '';
                this.pendingLabel = '';
                this.pendingPreviousValue = '';
                this.pendingPreviousLabel = '';
                this.pendingAt = 0;
            },
            clearSelection() {
                this.beginPendingSelection('', config.placeholder);
                this.selectedValue = '';
                this.selectedLabel = config.placeholder;
                this.closeAfterSelection();
            },
            select(item) {
                const next = String(item.id);
                const nextLabel = String(item.label || next);
                this.beginPendingSelection(next, nextLabel);
                this.knownLabels.set(next, nextLabel);
                this.selectedValue = next;
                this.selectedLabel = nextLabel;
                this.closeAfterSelection();
            },
        };
    };


    // Phase 4 official single-select name. Keep FlowTrackRemoteFilter as a
    // compatibility alias for existing inline editors until Phase 13 modularizes JS.
export const createSearchSelect = (config) => createRemoteFilter(config);

export const createMultiSelect = (config) => {
        const initialItems = Array.isArray(config.initialItems) ? config.initialItems : [];
        const recentPageSize = normaliseRecentPageSize(config.recentPageSize);
        const initialRecentItems = initialItems.slice(0, recentPageSize);
        const initialSelected = Array.isArray(config.values) ? config.values.map((value) => String(value)) : [];

        return {
            ...positioningMethods,
            menuWidth: Number(config.menuWidth || 320),
            menuMaxHeight: Number(config.menuMaxHeight || REMOTE_MENU_HEIGHT_CAP),
            fixedMenu: config.fixedMenu === true,
            remote: config.remote === true,
            disabled: config.disabled === true,
            maxSelected: Math.max(1, Math.min(100, Number(config.maxSelected || 100))),
            open: false,
            query: '',
            loading: false,
            items: config.remote === true ? initialRecentItems : initialItems,
            recentItems: initialRecentItems,
            recentHasMore: initialItems.length > recentPageSize,
            recentNextPage: initialItems.length > recentPageSize ? 2 : null,
            recentPageSize,
            selected: [...new Set(initialSelected)],
            params: config.params && typeof config.params === 'object' ? {...config.params} : {},
            page: 1,
            perPage: config.remote === true ? recentPageSize : 0,
            hasMore: config.remote === true && initialItems.length > recentPageSize,
            nextPage: config.remote === true && initialItems.length > recentPageSize ? 2 : null,
            minSearchLength: 2,
            controller: null,
            requestSequence: 0,
            knownItems: new Map(initialItems.map((item) => [String(item?.id ?? ''), item])),
            get visibleItems() {
                if (this.remote) return this.items;
                const q = normaliseOptionValue(this.query);
                if (!q) return this.items;
                return this.items.filter((item) => normaliseOptionValue(item.label).includes(q) || normaliseOptionValue(item.meta).includes(q));
            },
            get selectedItems() {
                return this.selected.map((id) => this.knownItems.get(String(id)) || {id, label: id, meta: ''});
            },
            remember(items = []) {
                items.forEach((item) => {
                    const id = String(item?.id ?? '');
                    if (id) this.knownItems.set(id, item);
                });
            },
            restoreCompactRecentPage() {
                if (!this.remote) return;
                this.items = [...this.recentItems];
                this.page = 1;
                this.perPage = this.recentPageSize;
                this.hasMore = this.recentHasMore;
                this.nextPage = this.recentNextPage;
                this.message = this.hasMore
                    ? `${this.items.length} recent options · more available`
                    : 'Recent options shown instantly. Type 2 characters to search.';
            },
            toggle() {
                if (this.disabled) return;
                this.open ? this.close() : this.openMenu();
            },
            openMenu() {
                if (this.disabled) return;
                if (this.remote) this.restoreCompactRecentPage();
                this.openPositionedMenu();
                if (this.remote) this.searchOptions(true);
                this.$nextTick(() => this.focusSearchWithoutScroll());
            },
            close() {
                this.open = false;
                this.menuStyle = '';
                this.query = '';
                this.loading = false;
                this.requestSequence++;
                this.controller?.abort();
                this.controller = null;
                this.restoreCompactRecentPage();
            },
            isSelected(id) {
                return this.selected.includes(String(id));
            },
            toggleValue(item) {
                const id = String(item?.id ?? '');
                if (!id) return;
                this.remember([item]);
                if (this.isSelected(id)) {
                    this.selected = this.selected.filter((value) => value !== id);
                    return;
                }
                if (this.selected.length >= this.maxSelected) {
                    this.message = `Select up to ${this.maxSelected} options.`;
                    return;
                }
                this.selected = [...this.selected, id];
            },
            syncValues(values = [], items = [], params = {}) {
                const nextParams = params && typeof params === 'object' ? {...params} : {};
                if (JSON.stringify(this.params) !== JSON.stringify(nextParams)) {
                    this.params = nextParams;
                    const freshItems = Array.isArray(items) ? items : [];
                    this.recentItems = freshItems.slice(0, this.recentPageSize);
                    this.items = this.remote ? [...this.recentItems] : freshItems;
                    this.recentHasMore = freshItems.length > this.recentPageSize;
                    this.recentNextPage = this.recentHasMore ? 2 : null;
                    this.knownItems = new Map();
                    this.remember(freshItems);
                    this.page = 1;
                    this.perPage = this.remote ? this.recentPageSize : 0;
                    this.hasMore = this.remote && this.recentHasMore;
                    this.nextPage = this.remote ? this.recentNextPage : null;
                } else {
                    this.remember(Array.isArray(items) ? items : []);
                }
                this.selected = [...new Set((Array.isArray(values) ? values : []).map((value) => String(value)))];
            },
            async searchOptions(force = false, append = false) {
                if (!this.remote) return;
                const q = this.query.trim();
                if (q.length > 0 && q.length < this.minSearchLength) {
                    this.controller?.abort();
                    this.loading = false;
                    this.items = [];
                    this.hasMore = false;
                    this.nextPage = null;
                    this.message = `Type at least ${this.minSearchLength} characters to search.`;
                    return;
                }

                this.controller?.abort();
                this.controller = new AbortController();
                const sequence = ++this.requestSequence;
                const requestedPage = append ? Math.max(1, Number(this.nextPage || (this.page + 1))) : 1;
                this.loading = true;
                this.message = append ? 'Loading more…' : (q ? 'Searching…' : 'Loading recent options…');

                try {
                    const url = new URL(config.endpoint, window.location.origin);
                    if (q) url.searchParams.set('q', q);
                    if (config.context) url.searchParams.set('context', config.context);
                    url.searchParams.set('page', String(requestedPage));
                    url.searchParams.set('per_page', String(q ? REMOTE_SEARCH_PAGE_SIZE : this.recentPageSize));
                    this.selected.forEach((value) => url.searchParams.append('selected[]', value));
                    Object.entries(this.params || {}).forEach(([name, value]) => {
                        if (value !== null && value !== undefined && String(value) !== '') url.searchParams.set(name, String(value));
                    });

                    const response = await fetch(url, {
                        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                        credentials: 'same-origin',
                        signal: this.controller.signal,
                    });
                    if (!response.ok) throw new Error('option-search-failed');
                    const payload = await response.json();
                    if (sequence !== this.requestSequence) return;

                    const pageItems = Array.isArray(payload.items) ? payload.items : [];
                    const selectedItems = Array.isArray(payload.selected_items) ? payload.selected_items : [];
                    this.remember([...selectedItems, ...pageItems]);
                    this.items = append ? uniqueOptions([...this.items, ...pageItems]) : pageItems;
                    this.page = Number(payload.pagination?.page || requestedPage);
                    this.perPage = Number(payload.pagination?.per_page || (q ? REMOTE_SEARCH_PAGE_SIZE : this.recentPageSize));
                    this.hasMore = payload.pagination?.has_more === true;
                    this.nextPage = payload.pagination?.next_page ? Number(payload.pagination.next_page) : null;
                    if (!q && requestedPage === 1 && !append) {
                        this.recentItems = pageItems.slice(0, this.recentPageSize);
                        this.recentHasMore = this.hasMore;
                        this.recentNextPage = this.nextPage;
                    }
                    this.minSearchLength = Number(payload.query?.min_length || 2);
                    this.message = q
                        ? (this.items.length ? `${this.items.length} result${this.items.length === 1 ? '' : 's'}${this.hasMore ? ' · more available' : ''}` : 'No matching options.')
                        : (this.hasMore ? `${this.items.length} recent options · more available` : 'Recent options shown instantly. Type 2 characters to search.');
                    this.reposition();
                } catch (error) {
                    if (error?.name !== 'AbortError') this.message = 'Could not load options. Try again.';
                } finally {
                    if (sequence === this.requestSequence) this.loading = false;
                }
            },
            loadMore() {
                if (!this.remote || !this.hasMore || this.loading || !this.nextPage) return;
                return this.searchOptions(true, true);
            },
            message: config.remote === true ? 'Recent options shown instantly. Type 2 characters to search.' : '',
        };
    };

    // Fixed list filters now use the same searchable, lightweight interaction
    // as the Job Details assignee picker. Search stays client-side because the
    // option sets are already small and fully loaded.
export const createLocalFilter = (config) => ({
        ...positioningMethods,
        searchable: true,
        menuWidth: Number(config.menuWidth || 320),
        fixedMenu: config.fixedMenu === true,
        disabled: config.disabled === true,
        open: false,
        query: '',
        loading: false,
        message: '',
        hasMore: false,
        items: Array.isArray(config.items) ? config.items : [],
        selectedValue: String(config.value || ''),
        selectedLabel: config.selectedLabel || config.placeholder,
        get filteredItems() {
            const q = normaliseOptionValue(this.query);
            if (!q) return this.items;
            return this.items.filter((item) =>
                normaliseOptionValue(item.label).includes(q) || normaliseOptionValue(item.meta).includes(q)
            );
        },
        get visibleItems() {
            return this.filteredItems;
        },
        toggle() {
            if (this.disabled) return;
            if (this.open) {
                this.close();
                return;
            }

            this.openPositionedMenu();
            this.$nextTick(() => {
                this.focusSearchWithoutScroll();
            });
        },
        close() {
            this.open = false;
            this.menuStyle = '';
            this.query = '';
        },
        focusFirst() {
            this.$refs.menu?.querySelector('.ft-remote-filter-list .ft-remote-filter-option')?.focus();
        },
        moveOption(direction) {
            const buttons = [...(this.$refs.menu?.querySelectorAll('.ft-remote-filter-list .ft-remote-filter-option') || [])];
            if (!buttons.length) return;
            const index = buttons.indexOf(document.activeElement);
            const next = index < 0 ? 0 : Math.max(0, Math.min(buttons.length - 1, index + direction));
            buttons[next]?.focus();
        },
        focusBoundary(boundary) {
            const buttons = [...(this.$refs.menu?.querySelectorAll('.ft-remote-filter-list .ft-remote-filter-option') || [])];
            if (!buttons.length) return;
            (boundary === 'last' ? buttons[buttons.length - 1] : buttons[0])?.focus();
        },
        choose(value, label) {
            this.selectedValue = String(value || '');
            this.selectedLabel = label || config.placeholder;
            this.open = false;
            this.query = '';
        },
        sync(value, label) {
            const next = String(value || '');
            if (!next) {
                this.selectedValue = '';
                this.selectedLabel = config.placeholder;
                return;
            }

            const normalised = normaliseOptionValue(next);
            const item = this.items.find((candidate) =>
                String(candidate.id) === next || normaliseOptionValue(candidate.id) === normalised
            );
            const resolved = label && label !== config.placeholder
                ? label
                : item?.label || (this.selectedValue === next && this.selectedLabel !== config.placeholder ? this.selectedLabel : next);

            this.selectedValue = next;
            this.selectedLabel = resolved;
        },
        syncOptions(value, label, items, disabled = false, placeholder = config.placeholder) {
            config.placeholder = placeholder || config.placeholder;
            this.disabled = disabled === true;
            const nextItems = Array.isArray(items) ? items : [];
            const currentSignature = JSON.stringify(this.items.map((item) => [String(item?.id ?? ''), String(item?.label ?? ''), String(item?.meta ?? '')]));
            const nextSignature = JSON.stringify(nextItems.map((item) => [String(item?.id ?? ''), String(item?.label ?? ''), String(item?.meta ?? '')]));
            if (currentSignature !== nextSignature) {
                this.items = nextItems;
                this.query = '';
                if (this.open) this.reposition();
            }
            this.sync(value, label);
            if (this.disabled && this.open) this.close();
        },
    });
