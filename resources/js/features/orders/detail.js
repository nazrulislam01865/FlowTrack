/**
 * Order Details presentation helpers.
 *
 * Workflow stages/tasks are rendered by Livewire from the Order's backend
 * workflow snapshot. This module intentionally contains no workflow business
 * rules or hard-coded stage/task definitions.
 */
let delegatedUiBound = false;

const scrollToTarget = (selector) => {
    if (!selector) return;
    const target = document.querySelector(selector);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.classList.add('ft-order-focus-pulse');
    window.setTimeout(() => target.classList.remove('ft-order-focus-pulse'), 900);
};

const copyValue = async (value, button) => {
    if (!value) return;
    try {
        await navigator.clipboard.writeText(value);
        const previous = button?.textContent;
        if (button) button.textContent = '✓';
        window.setTimeout(() => {
            if (button && previous !== undefined) button.textContent = previous;
        }, 900);
    } catch (_) {
        // Clipboard access is optional and must never break the page.
    }
};

const toggleDisclosure = (trigger) => {
    const host = trigger.closest('[data-order-disclosure]');
    const body = host?.querySelector('[data-order-disclosure-body]');
    if (!body) return;

    const willOpen = body.hidden || body.classList.contains('collapsed');
    body.hidden = !willOpen;
    body.classList.toggle('collapsed', !willOpen);
    trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    trigger.textContent = willOpen ? 'Hide files' : 'Show files';
};

export const bootOrderDetail = () => {
    if (delegatedUiBound) return;
    delegatedUiBound = true;

    document.addEventListener('click', (event) => {
        const scrollButton = event.target.closest('[data-order-scroll]');
        if (scrollButton) window.setTimeout(() => scrollToTarget(scrollButton.dataset.orderScroll), 40);

        const copyButton = event.target.closest('[data-copy-value]');
        if (copyButton) {
            event.preventDefault();
            copyValue(copyButton.dataset.copyValue, copyButton);
            return;
        }

        const disclosure = event.target.closest('[data-order-disclosure-trigger]');
        if (disclosure) {
            event.preventDefault();
            toggleDisclosure(disclosure);
        }
    });
};
