/* Optional, read-only Phase 1 diagnostics. No network, local storage or DOM changes. */
(() => {
  'use strict';
  if (window.__flowOrderBaseline) return;
  const navStart = performance.timeOrigin;
  const events = [];
  const sections = new Map();
  let cls = 0;
  let longTasks = 0;
  let firstContentfulPaint = null;

  function trackSections() {
    const seen = new Set();
    document.querySelectorAll('.ft-progressive-section-placeholder[wire\\:key]').forEach((node) => {
      const key = node.getAttribute('wire:key') || '';
      if (!key.includes('-order-') && !key.includes('order-')) return;
      seen.add(key);
      if (!sections.has(key)) sections.set(key, {first_seen_ms: Math.round(performance.now()), loaded_ms: null});
    });
    for (const [key, item] of sections.entries()) {
      if (!seen.has(key) && item.loaded_ms === null) item.loaded_ms = Math.round(performance.now());
    }
  }

  try {
    const observer = new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) {
        if (entry.entryType === 'layout-shift' && !entry.hadRecentInput) cls += entry.value;
        if (entry.entryType === 'longtask') longTasks += 1;
        if (entry.entryType === 'paint' && entry.name === 'first-contentful-paint') firstContentfulPaint = entry.startTime;
        // Livewire update endpoints may be /livewire/update or a versioned
        // /livewire-<hash>/update; record both without collecting URL content.
        if (entry.entryType === 'resource' && /\/livewire(?:-[a-z0-9]+)?\/update(?:[?#]|$)/i.test(entry.name)) {
          events.push({kind: 'livewire_network', start_ms: Math.round(entry.startTime), duration_ms: Math.round(entry.duration * 100) / 100,
            encoded_body_bytes: entry.encodedBodySize || null, decoded_body_bytes: entry.decodedBodySize || null});
        }
      }
    });
    for (const type of ['layout-shift', 'longtask', 'paint', 'resource']) {
      try { observer.observe({type, buffered: true}); } catch (_) { /* unsupported */ }
    }
  } catch (_) { /* PerformanceObserver unsupported */ }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', trackSections, {once: true});
  else trackSections();
  const mutationObserver = new MutationObserver(trackSections);
  mutationObserver.observe(document.documentElement, {childList: true, subtree: true});

  window.__flowOrderBaseline = {
    export() {
      const nav = performance.getEntriesByType('navigation')[0];
      const paints = performance.getEntriesByType('paint');
      const fcp = firstContentfulPaint ?? paints.find((entry) => entry.name === 'first-contentful-paint')?.startTime;
      const timing = nav ? {
        ttfb_ms: Math.round((nav.responseStart - nav.requestStart) * 100) / 100,
        dom_content_loaded_ms: Math.round(nav.domContentLoadedEventEnd * 100) / 100,
        load_ms: Math.round(nav.loadEventEnd * 100) / 100,
        transfer_bytes: nav.transferSize || null,
      } : null;
      return {
        kind: 'browser_baseline',
        recorded_at: new Date().toISOString(),
        navigation_started_at: new Date(navStart).toISOString(),
        order_id: Number(new URLSearchParams(location.search).get('open')) || null,
        timing,
        first_contentful_paint_ms: fcp == null ? null : Math.round(fcp * 100) / 100,
        cumulative_layout_shift: Math.round(cls * 10000) / 10000,
        long_task_count: longTasks,
        sections: [...sections.entries()].map(([key, state]) => ({key, ...state})),
        livewire_network: events.slice(0, 200),
      };
    },
    print() { const result = this.export(); console.table(result.livewire_network); console.log(JSON.stringify(result, null, 2)); return result; },
  };
  console.info('FlowTracker Phase 1 baseline enabled. Run __flowOrderBaseline.print() after all sections load.');
})();
