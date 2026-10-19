const loaded = new Map();

const loadOnce = (key, importer, bootName) => {
    if (!loaded.has(key)) {
        loaded.set(key, importer().then((module) => {
            const boot = module?.[bootName];
            if (typeof boot === 'function') boot();
            return module;
        }));
    } else {
        loaded.get(key)?.then((module) => module?.[bootName]?.());
    }
};

export const bootRouteFeatures = () => {
    if (document.getElementById('ft-bulk-import-page')) {
        loadOnce('bulk-order-import', () => import('./bulk-order-import.js'), 'bootBulkOrderImport');
    }

    if (document.querySelector('[data-order-scroll], [data-order-disclosure], [data-copy-value]')) {
        loadOnce('order-detail', () => import('./orders/detail.js'), 'bootOrderDetail');
    }

    if (document.querySelector('.ft-client-create-prototype')) {
        loadOnce('client-validation-focus', () => import('./client-validation-focus.js'), 'bootClientValidationFocus');
    }
};
