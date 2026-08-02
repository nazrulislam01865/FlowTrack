(function () {
    'use strict';

    const dispatchPage = (page) => {
        const normalized = String(page || '').trim();
        if (!normalized || !window.Livewire?.dispatch) return;

        window.Livewire.dispatch('flowtrack:navigate', { page: normalized });
    };

    const wrapGlobal = (name, pageResolver) => {
        const implementation = window[name];
        if (typeof implementation !== 'function' || implementation.__flowtrackLivewireBridge) return;

        const wrapped = function (...args) {
            const result = implementation.apply(this, args);
            const page = pageResolver(args, result);
            if (page) dispatchPage(page);
            return result;
        };

        Object.defineProperty(wrapped, '__flowtrackLivewireBridge', { value: true });
        window[name] = wrapped;
    };

    const installBridge = () => {
        wrapGlobal('navigate', ([page], result) => result === false ? null : page);
        wrapGlobal('activateStandalonePage', ([page], result) => result === false ? null : page);

        const active = document.querySelector('.page.active');
        if (active?.id?.startsWith('page-')) dispatchPage(active.id.slice(5));
    };

    document.addEventListener('livewire:init', installBridge, { once: true });
    window.addEventListener('flowtrack:assets-ready', installBridge);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', installBridge, { once: true });
    } else {
        installBridge();
    }
})();
