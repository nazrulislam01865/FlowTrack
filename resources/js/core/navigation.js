const lifecycleState = { bound: false };

/**
 * Central Livewire/navigation lifecycle. All feature initializers are called
 * from this one registry, preventing per-feature duplicate navigation hooks.
 */
export const bindNavigationLifecycle = ({
    boot,
    livewireInit,
    navigating,
    navigated,
    loaded,
}) => {
    if (lifecycleState.bound) return;
    lifecycleState.bound = true;

    const runBoot = () => boot?.();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runBoot, { once: true });
    } else {
        runBoot();
    }

    document.addEventListener('livewire:init', () => livewireInit?.());
    document.addEventListener('livewire:navigating', (event) => navigating?.(event));
    document.addEventListener('livewire:navigated', () => navigated?.());

    if (document.readyState === 'complete') loaded?.();
    else window.addEventListener('load', () => loaded?.(), { once: true });
};
