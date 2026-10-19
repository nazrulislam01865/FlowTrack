const valid = value => /^#[0-9a-f]{6}$/i.test(String(value || '').trim());
    const rgb = value => {
        const hex = String(value).replace('#', '');
        return [parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16)];
    };
    const textColor = ([r, g, b]) => {
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        if (luminance <= 0.68) return `#${[r,g,b].map(v => v.toString(16).padStart(2, '0')).join('')}`;
        return `#${[r,g,b].map(v => Math.round(v * 0.52).toString(16).padStart(2, '0')).join('')}`;
    };
    const clear = select => {
        select.classList.remove('ft-master-color');
        ['--ft-master-color','--ft-master-bg','--ft-master-border','--ft-master-text'].forEach(prop => select.style.removeProperty(prop));
    };
    const applySelect = select => {
        if (!(select instanceof HTMLSelectElement)) return;
        const option = select.options[select.selectedIndex];
        const color = String(option?.dataset?.color || '').trim().toUpperCase();
        if (!valid(color)) { clear(select); return; }
        const [r,g,b] = rgb(color);
        select.classList.add('ft-master-color');
        select.style.setProperty('--ft-master-color', color);
        select.style.setProperty('--ft-master-bg', `rgba(${r},${g},${b},.12)`);
        select.style.setProperty('--ft-master-border', `rgba(${r},${g},${b},.34)`);
        select.style.setProperty('--ft-master-text', textColor([r,g,b]));
    };
    const applyAll = root => (root || document).querySelectorAll?.('select[data-master-color-select]').forEach(applySelect);

const state = { bound: false, livewireHookBound: false };

export const masterColor = { applySelect, applyAll };

export const bootMasterColors = () => {
    if (!state.bound) {
        state.bound = true;
        document.addEventListener('change', event => {
            if (event.target?.matches?.('select[data-master-color-select]')) applySelect(event.target);
        }, true);
        document.addEventListener('focusin', () => requestAnimationFrame(() => applyAll(document)), true);
    }

    applyAll(document);

    if (!state.livewireHookBound && window.Livewire?.hook) {
        state.livewireHookBound = true;
        try {
            window.Livewire.hook('morph.updated', ({ el }) => {
                applyAll(el || document);
                requestAnimationFrame(() => applyAll(document));
            });
        } catch (_) {
            state.livewireHookBound = false;
        }
    }
};
