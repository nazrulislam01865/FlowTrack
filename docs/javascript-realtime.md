# FlowTrack JavaScript and realtime architecture — Phase 13

## Purpose

Phase 13 makes `resources/js/app.js` a composition root instead of an implementation bucket. Browser behavior is owned by ESM modules under `core`, `components` and `features`. Phase 15 keeps only the minimal namespaced `window.FlowTrack` API required by Alpine/Blade and removes the deprecated broad compatibility aliases.

## Source layout

```text
resources/js/
├── app.js
├── core/
│   ├── events.js
│   ├── meta.js
│   ├── navigation.js
│   ├── realtime.js
│   ├── session-recovery.js
│   ├── session-safety.js
│   ├── shell.js
│   └── timezone.js
├── components/
│   ├── attachment-auto-upload.js
│   ├── file-dropzones.js
│   ├── inline-edit.js
│   ├── list-filters.js
│   ├── master-colors.js
│   ├── mentions.js
│   ├── rich-text.js
│   └── sidebar-counters.js
├── features/
│   ├── notifications.js
│   ├── workspace-refresh.js
│   ├── route-loader.js
│   ├── bulk-order-import.js
│   ├── client-validation-focus.js
│   └── orders/detail.js
└── core/
    └── browser-api.js             # minimal Alpine/Blade namespace
```

`public/js` is not a source directory. Runtime source is compiled and fingerprinted by Vite into `public/build`.

## Navigation lifecycle

`core/navigation.js` is the only top-level owner of `livewire:init`, `livewire:navigating` and `livewire:navigated` registration. It has an idempotent bind guard. Feature modules expose boot functions and own their own local one-time guards, so repeated Livewire SPA navigation does not multiply event handlers.

## Realtime lifecycle

`core/realtime.js` owns the Reverb/Pusher-protocol WebSocket lifecycle. `bootRealtimeClient()` returns one browser singleton. Reconnect uses a deterministic exponential sequence of 500 ms, 1 s, 2 s, 4 s, 8 s, then a 15 s cap. Channel objects deduplicate identical callbacks and are re-subscribed after reconnect.

`features/notifications.js` and `features/workspace-refresh.js` consume that single client. They keep the existing polling fallbacks when realtime is unavailable. They do not create independent WebSocket clients.

## Event contracts

`core/events.js` is the authoritative source for cross-module names:

- Reverb: `flowtrack.refresh`, `flowtrack.notification`, `flowtrack.notification-state`
- Livewire: `flowtrack-refresh`, `flowtrack-notification`, unread state events
- Browser bridge events: realtime connection/notification state

Producers and consumers import these constants rather than repeating string literals.

## Browser API boundary

Blade/Alpine callers use `window.FlowTrack.ui.*`, `window.FlowTrack.realtime.client`, and `window.FlowTrack.events`. Phase 15 removed the deprecated broad aliases such as `window.FlowTrackInlineEdit` and `window.FlowTrackRealtime` after call-site search confirmed active source no longer depended on them. `core/browser-api.js` is now the only browser namespace owner and must remain minimal.

## Route-specific features

`features/route-loader.js` dynamically imports larger/narrow features only when their page marker exists. Bulk Order Import and Order detail behavior therefore do not need to execute on unrelated pages.

The previous runtime SheetJS CDN script remains Vite-managed. Phase 15 upgrades the parser from the vulnerable npm-registry 0.18.5 build to the authoritative SheetJS 0.20.3 tarball while preserving the same `import * as XLSX from 'xlsx'` feature API. The dependency audit must reject a return to the stale npm package.

## Verification

- `npm run test:js:syntax` — parses every source JS module with Node.
- `npm run test:js:unit` — verifies event-handler deduplication, event contracts and deterministic reconnect delays without Laravel or a browser.
- `FLOWTRACK_BASE_URL=https://... npm run test:js:browser` — Playwright smoke test for the namespaced bridge and repeated Livewire navigation against a running application.
- `npm run quality:phase13` — Phase 0–13 architecture chain plus syntax/unit JS checks.

The browser smoke test requires the dependency-complete application to be running and authenticated as appropriate for the supplied URL.
