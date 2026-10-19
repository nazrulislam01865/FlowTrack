const flowtrackTimezoneState = { syncing: false, attemptedTimezone: null };

export const syncBrowserTimezone = async () => {
    if (flowtrackTimezoneState.syncing || !window.Intl?.DateTimeFormat) return false;

    const url = document.querySelector('meta[name="flowtrack-timezone-sync-url"]')?.content;
    const timezoneMeta = document.querySelector('meta[name="flowtrack-display-timezone"]');
    const current = timezoneMeta?.content;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (!url || !csrf || !timezone || timezone === current) return false;

    // Never reload the page just to persist a display time zone. On a cloud
    // deployment a dropped/non-sticky session could otherwise cause an
    // endless reload loop that repeatedly re-mounts Livewire and flickers the
    // whole dashboard. One successful sync is enough for this browser tab.
    if (flowtrackTimezoneState.attemptedTimezone === timezone) return false;

    flowtrackTimezoneState.syncing = true;
    flowtrackTimezoneState.attemptedTimezone = timezone;
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            cache: 'no-store',
            body: JSON.stringify({ timezone }),
        });
        if (!response.ok) return false;

        // Keep the current page stable. Server-rendered dates will use the new
        // time zone on the next normal request/navigation.
        if (timezoneMeta) timezoneMeta.content = timezone;
        return true;
    } catch (_) {
        return false;
    } finally {
        flowtrackTimezoneState.syncing = false;
    }
};
