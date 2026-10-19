function initialiseOrderTrackingForm(root) {
    if (!(root instanceof HTMLElement) || root.dataset.trackingReady === 'true') return;

    const typeInput = root.querySelector('[data-tracking-lookup-type]');
    const identifier = root.querySelector('[data-tracking-identifier]');
    const identifierLabel = root.querySelector('[data-tracking-identifier-label]');
    const tabs = Array.from(root.querySelectorAll('[data-tracking-tab]'));

    if (!typeInput || !identifier || !identifierLabel || tabs.length === 0) return;

    const setType = (type) => {
        const normalized = type === 'reference' ? 'reference' : 'order';
        typeInput.value = normalized;
        const label = normalized === 'reference' ? 'Reference number' : 'Order number';
        identifierLabel.textContent = label;
        identifier.placeholder = label;
        tabs.forEach((tab) => {
            const active = tab.dataset.trackingTab === normalized;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    };

    tabs.forEach((tab) => tab.addEventListener('click', () => {
        setType(tab.dataset.trackingTab);
        identifier.focus();
    }));

    setType(typeInput.value);
    root.dataset.trackingReady = 'true';
}

function initialiseOrderTracking() {
    document.querySelectorAll('[data-order-tracking-form]').forEach(initialiseOrderTrackingForm);
}

document.addEventListener('DOMContentLoaded', initialiseOrderTracking, { once: true });
