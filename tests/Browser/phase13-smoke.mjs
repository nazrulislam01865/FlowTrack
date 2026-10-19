import { chromium } from 'playwright';

const baseUrl = process.env.FLOWTRACK_BASE_URL;
if (!baseUrl) {
    console.error('FLOWTRACK_BASE_URL is required for the Phase 13 browser smoke test.');
    process.exit(2);
}

const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    await page.goto(baseUrl, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => Boolean(window.FlowTrack?.ui?.inlineEdit));

    const before = await page.evaluate(() => ({
        root: Boolean(window.FlowTrack),
        inline: typeof window.FlowTrack?.ui?.inlineEdit === 'function',
        filter: typeof window.FlowTrack?.ui?.searchSelect === 'function',
        noLegacyInline: typeof window.FlowTrackInlineEdit === 'undefined',
        noLegacyFilter: typeof window.FlowTrackSearchSelect === 'undefined',
        realtime: window.FlowTrack?.realtime?.client,
    }));

    if (!before.root || !before.inline || !before.filter || !before.noLegacyInline || !before.noLegacyFilter) {
        throw new Error('Final FlowTrack namespaced browser API is not ready or legacy aliases remain.');
    }

    await page.evaluate(() => {
        document.dispatchEvent(new CustomEvent('livewire:navigated'));
        document.dispatchEvent(new CustomEvent('livewire:navigated'));
    });

    const stable = await page.evaluate((hadRealtime) => {
        const now = window.FlowTrack?.realtime?.client;
        return hadRealtime ? Boolean(now) : true;
    }, Boolean(before.realtime));
    if (!stable) throw new Error('Realtime client was lost after repeated Livewire navigation.');
} finally {
    await browser.close();
}

console.log('Phase 13 browser smoke PASS');
