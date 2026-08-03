(() => {
    const selectInstances = new WeakMap();
    const searchInputs = new WeakSet();
    let opened = null;
    let observer = null;
    let scheduledRoot = null;
    let scheduled = false;

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');

    const isElement = (value) => value instanceof Element;

    function visibleEnough(element) {
        if (!isElement(element)) return false;
        if (element.type === 'hidden') return false;
        if (element.hidden || element.closest('[hidden]')) return false;
        return true;
    }

    function shouldEnhanceSelect(select) {
        if (!(select instanceof HTMLSelectElement)) return false;
        if (select.multiple || Number(select.size || 0) > 1) return false;
        if (select.classList.contains('flow-searchable-native')) return false;
        if (select.dataset.flowSearchableEnhanced === 'true') return false;
        if (select.matches('[data-no-searchable], [data-native-select]')) return false;
        if (select.closest('.flow-searchable')) return false;
        return true;
    }

    function shouldEnhanceSearchInput(input) {
        if (!(input instanceof HTMLInputElement)) return false;
        if (!visibleEnough(input)) return false;
        if (input.closest('.flow-searchable-panel')) return false;
        if (input.matches('[data-no-searchbar], .flow-searchable-search')) return false;
        if (input.closest('.flow-searchbar')) return false;
        const haystack = `${input.type || ''} ${input.id || ''} ${input.className || ''} ${input.placeholder || ''} ${input.getAttribute('aria-label') || ''}`;
        return /(^|\s)search(\s|$)|search/i.test(haystack);
    }

    function syncWrapperSizing(select, wrapper) {
        const style = select.getAttribute('style') || '';
        if (style) wrapper.setAttribute('data-source-style', style);
        ['width', 'minWidth', 'maxWidth', 'flex', 'flexBasis'].forEach((prop) => {
            if (select.style[prop]) wrapper.style[prop] = select.style[prop];
        });
    }

    class FlowSearchableSelect {
        constructor(select) {
            this.select = select;
            this.wrapper = document.createElement('div');
            const wrapperClasses = ['flow-searchable'];
            if (select.classList.contains('ops-filter')) wrapperClasses.push('flow-searchable-ops-filter');
            if (select.classList.contains('access-scope-select')) wrapperClasses.push('flow-searchable-access-scope');
            if (select.closest('.access-member-table')) wrapperClasses.push('flow-searchable-access-member');
            if (select.closest('.jobs-v2-page-size')) wrapperClasses.push('flow-searchable-page-size');
            this.wrapper.className = wrapperClasses.join(' ');
            this.wrapper.dataset.flowSearchableFor = select.id || select.name || 'select';
            syncWrapperSizing(select, this.wrapper);

            this.trigger = document.createElement('button');
            this.trigger.type = 'button';
            this.trigger.className = 'flow-searchable-trigger';
            this.trigger.setAttribute('aria-haspopup', 'listbox');
            this.trigger.setAttribute('aria-expanded', 'false');
            this.trigger.innerHTML = '<span class="flow-searchable-value"></span><span class="flow-searchable-arrow" aria-hidden="true"></span>';

            this.panel = document.createElement('div');
            this.panel.className = 'flow-searchable-panel';
            this.panel.innerHTML = '<div class="flow-searchable-search-wrap"><span class="flow-searchable-search-icon" aria-hidden="true">⌕</span><input class="flow-searchable-search" type="search" autocomplete="off" spellcheck="false"></div><div class="flow-searchable-options" role="listbox"></div>';

            select.parentNode.insertBefore(this.wrapper, select);
            this.wrapper.append(select, this.trigger, this.panel);
            select.classList.add('flow-searchable-native');
            select.dataset.flowSearchableEnhanced = 'true';

            this.valueEl = this.trigger.querySelector('.flow-searchable-value');
            this.search = this.panel.querySelector('.flow-searchable-search');
            this.optionsEl = this.panel.querySelector('.flow-searchable-options');
            this.placeholder = select.dataset.searchPlaceholder || select.dataset.placeholder || 'Search options…';
            this.search.placeholder = this.placeholder;
            this.bind();
            this.refresh();
        }

        bind() {
            this.trigger.addEventListener('click', () => this.toggle());
            this.trigger.addEventListener('keydown', (event) => {
                if (['Enter', ' ', 'ArrowDown'].includes(event.key)) {
                    event.preventDefault();
                    this.open();
                }
            });
            this.search.addEventListener('input', () => this.render());
            this.search.addEventListener('keydown', (event) => this.onKeydown(event));
            this.optionsEl.addEventListener('click', (event) => {
                const option = event.target.closest('[data-flow-option-index]');
                if (!option || option.disabled) return;
                this.choose(Number(option.dataset.flowOptionIndex));
            });
            this.select.addEventListener('change', () => this.refreshLabel());
        }

        optionData() {
            return Array.from(this.select.options).map((option, index) => ({
                index,
                value: option.value,
                label: option.textContent.trim(),
                disabled: option.disabled,
                selected: option.selected,
            }));
        }

        refresh() {
            if (!this.select.isConnected) {
                this.close();
                return;
            }
            this.trigger.disabled = this.select.disabled;
            this.wrapper.classList.toggle('is-disabled', this.select.disabled);
            syncWrapperSizing(this.select, this.wrapper);
            this.refreshLabel();
            if (this.wrapper.classList.contains('open')) this.render();
        }

        refreshLabel() {
            const selected = this.select.selectedOptions[0];
            const text = selected?.textContent?.trim() || this.select.dataset.placeholder || 'Select an option';
            const placeholder = !selected || selected.value === '';
            this.valueEl.textContent = text;
            this.valueEl.classList.toggle('flow-searchable-placeholder', placeholder);
            this.trigger.title = text;
        }

        viewportBounds() {
            const viewport = window.visualViewport;
            const viewportTop = viewport?.offsetTop ?? 0;
            const viewportHeight = viewport?.height ?? window.innerHeight;
            const viewportBottom = viewportTop + viewportHeight;
            const boundary = this.wrapper.closest('.modal, .drawer, .standalone-record-shell')?.getBoundingClientRect();
            const margin = 10;
            return {
                top: Math.max(viewportTop + margin, (boundary?.top ?? viewportTop) + margin),
                bottom: Math.min(viewportBottom - margin, (boundary?.bottom ?? viewportBottom) - margin),
                height: Math.max(120, viewportHeight - (margin * 2)),
            };
        }

        estimatedPanelHeight() {
            const optionCount = this.filtered().length;
            // Search field + panel padding/borders + roughly one normal option row per item.
            return 66 + (optionCount * 42);
        }

        updatePanelGeometry() {
            if (!this.wrapper.classList.contains('open')) return;

            const bounds = this.viewportBounds();
            const rect = this.wrapper.getBoundingClientRect();
            const roomBelow = Math.max(0, bounds.bottom - rect.bottom - 6);
            const roomAbove = Math.max(0, rect.top - bounds.top - 6);
            const requestedDirection = String(this.select.dataset.dropdownDirection || '').toLowerCase();
            const compactSheet = window.matchMedia('(max-width: 620px)').matches;
            const desiredHeight = Math.min(this.estimatedPanelHeight(), bounds.height);

            let shouldDropUp = false;
            if (!compactSheet) {
                shouldDropUp = requestedDirection === 'up'
                    || (requestedDirection !== 'down' && roomBelow < desiredHeight && roomAbove > roomBelow);
            }

            this.wrapper.classList.toggle('drop-up', shouldDropUp);

            const availableHeight = compactSheet
                ? bounds.height
                : Math.max(120, shouldDropUp ? roomAbove : roomBelow);
            const panelMaxHeight = Math.max(120, Math.min(availableHeight, bounds.height));

            const panelStyle = getComputedStyle(this.panel);
            const searchWrap = this.panel.querySelector('.flow-searchable-search-wrap');
            const searchHeight = searchWrap?.getBoundingClientRect().height || 42;
            const searchMargin = parseFloat(getComputedStyle(searchWrap || this.search).marginBottom || '0') || 0;
            const verticalChrome = (parseFloat(panelStyle.paddingTop || '0') || 0)
                + (parseFloat(panelStyle.paddingBottom || '0') || 0)
                + (parseFloat(panelStyle.borderTopWidth || '0') || 0)
                + (parseFloat(panelStyle.borderBottomWidth || '0') || 0)
                + searchHeight
                + searchMargin;
            const optionsMaxHeight = Math.max(64, Math.floor(panelMaxHeight - verticalChrome));

            this.panel.style.setProperty('--flow-dropdown-panel-max-height', `${Math.floor(panelMaxHeight)}px`);
            this.optionsEl.style.setProperty('--flow-dropdown-options-max-height', `${optionsMaxHeight}px`);
        }

        open() {
            if (this.trigger.disabled) return;
            if (opened && opened !== this) opened.close();
            opened = this;
            this.wrapper.classList.add('open');
            this.trigger.setAttribute('aria-expanded', 'true');
            this.search.value = '';
            this.render();
            this.updatePanelGeometry();
            requestAnimationFrame(() => {
                this.updatePanelGeometry();
                this.search.focus();
            });
        }

        close() {
            this.wrapper.classList.remove('open');
            this.trigger.setAttribute('aria-expanded', 'false');
            if (opened === this) opened = null;
        }

        toggle() {
            this.wrapper.classList.contains('open') ? this.close() : this.open();
        }

        filtered() {
            const query = this.search.value.trim().toLocaleLowerCase();
            return this.optionData().filter((item) => !query || `${item.label} ${item.value}`.toLocaleLowerCase().includes(query));
        }

        render() {
            const options = this.filtered();
            this.optionsEl.innerHTML = options.length
                ? options.map((item) => `<button type="button" class="flow-searchable-option${item.selected ? ' selected' : ''}" data-flow-option-index="${item.index}" role="option" aria-selected="${item.selected}" ${item.disabled ? 'disabled' : ''}><span>${escapeHtml(item.label)}</span>${item.selected ? '<span class="flow-searchable-check">✓</span>' : ''}</button>`).join('')
                : '<div class="flow-searchable-empty">No matching result found</div>';
            if (this.wrapper.classList.contains('open')) {
                requestAnimationFrame(() => this.updatePanelGeometry());
            }
        }

        choose(index) {
            const option = this.select.options[index];
            if (!option || option.disabled) return;
            this.select.value = option.value;
            option.selected = true;
            this.select.dispatchEvent(new Event('input', { bubbles: true }));
            this.select.dispatchEvent(new Event('change', { bubbles: true }));
            this.refreshLabel();
            this.close();
            this.trigger.focus();
        }

        onKeydown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.close();
                this.trigger.focus();
                return;
            }
            const options = Array.from(this.optionsEl.querySelectorAll('.flow-searchable-option:not(:disabled)'));
            if (!options.length) return;
            const active = document.activeElement?.closest?.('.flow-searchable-option');
            const index = options.indexOf(active);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                options[Math.min(options.length - 1, index >= 0 ? index + 1 : 0)].focus();
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                options[Math.max(0, index >= 0 ? index - 1 : options.length - 1)].focus();
            }
            if (event.key === 'Enter' && !active) {
                event.preventDefault();
                options[0].click();
            }
        }
    }

    function enhanceSearchInput(input) {
        if (!(input instanceof HTMLInputElement)) return;
        if (input.closest('.search')) {
            input.classList.add('flow-searchbar-input');
            searchInputs.add(input);
            return;
        }
        const wrapper = document.createElement('div');
        wrapper.className = 'flow-searchbar';
        ['width', 'minWidth', 'maxWidth', 'flex', 'flexBasis'].forEach((prop) => {
            if (input.style[prop]) wrapper.style[prop] = input.style[prop];
        });
        input.parentNode.insertBefore(wrapper, input);
        wrapper.append(input);
        input.classList.add('flow-searchbar-input');
        searchInputs.add(input);
    }

    function enhance(root = document) {
        const start = root instanceof Document || root instanceof DocumentFragment ? root : (isElement(root) ? root : document);
        const selectNodes = start instanceof HTMLSelectElement ? [start] : Array.from(start.querySelectorAll?.('select') || []);
        selectNodes.forEach((select) => {
            if (selectInstances.has(select)) {
                selectInstances.get(select).refresh();
                return;
            }
            if (shouldEnhanceSelect(select)) {
                const instance = new FlowSearchableSelect(select);
                selectInstances.set(select, instance);
            }
        });

        const inputNodes = start instanceof HTMLInputElement ? [start] : Array.from(start.querySelectorAll?.('input') || []);
        inputNodes.forEach((input) => {
            if (searchInputs.has(input)) return;
            if (shouldEnhanceSearchInput(input)) enhanceSearchInput(input);
        });
    }

    function refresh(target) {
        if (!target) return;
        const node = typeof target === 'string' ? document.querySelector(target) : target;
        if (!node) return;
        if (node instanceof HTMLSelectElement) {
            if (!selectInstances.has(node) && shouldEnhanceSelect(node)) {
                const instance = new FlowSearchableSelect(node);
                selectInstances.set(node, instance);
            }
            selectInstances.get(node)?.refresh();
            return;
        }
        enhance(node);
    }

    function scheduleEnhance(root = document) {
        scheduledRoot = root instanceof Element || root instanceof Document || root instanceof DocumentFragment ? root : document;
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => {
            scheduled = false;
            enhance(scheduledRoot || document);
            scheduledRoot = null;
        });
    }

    function startObserver() {
        if (observer) return;
        observer = new MutationObserver((mutations) => {
            let needsEnhance = false;
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList') {
                    mutation.addedNodes.forEach((node) => {
                        if (!(node instanceof Element)) return;
                        if (node.matches?.('select,input') || node.querySelector?.('select,input')) needsEnhance = true;
                    });
                }
                if (mutation.type === 'attributes' && mutation.target instanceof HTMLSelectElement) {
                    const instance = selectInstances.get(mutation.target);
                    if (instance) instance.refresh();
                    else needsEnhance = true;
                }
            });
            if (needsEnhance) scheduleEnhance(document);
        });
        observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['disabled', 'class', 'style', 'data-searchable', 'data-search-placeholder'],
        });
    }

    document.addEventListener('click', (event) => {
        if (opened && !opened.wrapper.contains(event.target)) opened.close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && opened) opened.close();
    });
    window.addEventListener('resize', () => opened?.updatePanelGeometry(), { passive: true });
    window.addEventListener('scroll', () => opened?.updatePanelGeometry(), { passive: true, capture: true });
    window.visualViewport?.addEventListener('resize', () => opened?.updatePanelGeometry(), { passive: true });
    window.visualViewport?.addEventListener('scroll', () => opened?.updatePanelGeometry(), { passive: true });

    window.FlowtrackSearchableDropdown = {
        enhance,
        refresh,
        close: () => opened?.close(),
        instances: selectInstances,
    };

    const boot = () => {
        enhance(document);
        startObserver();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
