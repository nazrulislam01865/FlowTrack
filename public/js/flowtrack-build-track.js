(() => {
    'use strict';

    const state = { checking: false };

    const trackerVersion = () => {
        const tracker = document.querySelector('script[data-flowtrack-build-track]');
        if (!tracker?.src) return 'unknown';

        try {
            return new URL(tracker.src, window.location.href).searchParams.get('v') || 'unknown';
        } catch (_) {
            return 'unknown';
        }
    };

    const verifyOrderDetailStyles = () => {
        if (state.checking || !document.querySelector('.ft-order-prototype-detail')) return;
        state.checking = true;

        requestAnimationFrame(() => requestAnimationFrame(() => {
            state.checking = false;

            const page = document.querySelector('.ft-order-prototype-detail');
            if (!page) return;

            const contract = getComputedStyle(page)
                .getPropertyValue('--ft-order-detail-style-contract')
                .trim();

            if (contract === '20260827') return;

            // One recovery attempt per deployed build + URL. This covers a
            // transiently missing/late stylesheet without allowing reload loops.
            const key = [
                'flowtrack-order-style-recovery',
                trackerVersion(),
                window.location.pathname,
                window.location.search,
            ].join(':');

            if (sessionStorage.getItem(key) === '1') return;

            sessionStorage.setItem(key, '1');
            window.location.reload();
        }));
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', verifyOrderDetailStyles, { once: true });
    } else {
        verifyOrderDetailStyles();
    }

    document.addEventListener('livewire:navigated', verifyOrderDetailStyles);
})();
