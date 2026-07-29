(() => {
    const instances = new WeakMap();
    let opened = null;

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

    class FlowSearchableSelect {
        constructor(select) {
            this.select = select;
            this.wrapper = document.createElement('div');
            this.wrapper.className = 'flow-searchable';
            this.trigger = document.createElement('button');
            this.trigger.type = 'button';
            this.trigger.className = 'flow-searchable-trigger';
            this.trigger.setAttribute('aria-haspopup', 'listbox');
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
            this.trigger.disabled = this.select.disabled;
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

        open() {
            if (this.trigger.disabled) return;
            if (opened && opened !== this) opened.close();
            opened = this;
            const rect = this.wrapper.getBoundingClientRect();
            const boundary = this.wrapper.closest('.modal')?.getBoundingClientRect();
            const roomBelow = (boundary?.bottom ?? window.innerHeight) - rect.bottom;
            const roomAbove = rect.top - (boundary?.top ?? 0);
            const requestedDirection = String(this.select.dataset.dropdownDirection || '').toLowerCase();
            const shouldDropUp = requestedDirection === 'up'
                || (requestedDirection !== 'down' && roomBelow < 290 && roomAbove > roomBelow);
            this.wrapper.classList.toggle('drop-up', shouldDropUp);
            this.wrapper.classList.add('open');
            this.trigger.setAttribute('aria-expanded', 'true');
            this.search.value = '';
            this.render();
            requestAnimationFrame(() => this.search.focus());
        }

        close() {
            this.wrapper.classList.remove('open');
            this.trigger.setAttribute('aria-expanded', 'false');
            if (opened === this) opened = null;
        }

        toggle() { this.wrapper.classList.contains('open') ? this.close() : this.open(); }

        filtered() {
            const query = this.search.value.trim().toLocaleLowerCase();
            return this.optionData().filter((item) => !query || `${item.label} ${item.value}`.toLocaleLowerCase().includes(query));
        }

        render() {
            const options = this.filtered();
            this.optionsEl.innerHTML = options.length ? options.map((item) => `<button type="button" class="flow-searchable-option${item.selected ? ' selected' : ''}" data-flow-option-index="${item.index}" role="option" aria-selected="${item.selected}" ${item.disabled ? 'disabled' : ''}><span>${escapeHtml(item.label)}</span>${item.selected ? '<span class="flow-searchable-check">✓</span>' : ''}</button>`).join('') : '<div class="flow-searchable-empty">No matching result found</div>';
        }

        choose(index) {
            const option = this.select.options[index];
            if (!option || option.disabled) return;
            this.select.value = option.value;
            option.selected = true;
            this.select.dispatchEvent(new Event('change', { bubbles: true }));
            this.refreshLabel();
            this.close();
            this.trigger.focus();
        }

        onKeydown(event) {
            if (event.key === 'Escape') { event.preventDefault(); this.close(); this.trigger.focus(); return; }
            const options = Array.from(this.optionsEl.querySelectorAll('.flow-searchable-option:not(:disabled)'));
            if (!options.length) return;
            const active = document.activeElement?.closest?.('.flow-searchable-option');
            let index = Math.max(0, options.indexOf(active));
            if (event.key === 'ArrowDown') { event.preventDefault(); options[Math.min(options.length - 1, active ? index + 1 : 0)].focus(); }
            if (event.key === 'ArrowUp') { event.preventDefault(); options[Math.max(0, active ? index - 1 : options.length - 1)].focus(); }
            if (event.key === 'Enter' && !active) { event.preventDefault(); options[0].click(); }
        }
    }

    function enhance(root = document) {
        const nodes = root.matches?.('select[data-searchable]') ? [root] : root.querySelectorAll?.('select[data-searchable]') || [];
        nodes.forEach((select) => {
            if (instances.has(select)) { instances.get(select).refresh(); return; }
            const instance = new FlowSearchableSelect(select);
            instances.set(select, instance);
        });
    }

    function refresh(target) {
        if (!target) return;
        const select = typeof target === 'string' ? document.querySelector(target) : target;
        if (!select) return;
        if (!instances.has(select)) enhance(select);
        instances.get(select)?.refresh();
    }

    document.addEventListener('click', (event) => {
        if (opened && !opened.wrapper.contains(event.target)) opened.close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && opened) opened.close();
    });

    window.FlowtrackSearchableDropdown = { enhance, refresh, close: () => opened?.close() };
    document.addEventListener('DOMContentLoaded', () => enhance(document));
})();
