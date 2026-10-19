# Order Details long-idle / refresh-only recovery fix — 2026-08-27

## Symptom
An already-open FlowTrack tab could sit for a long time and later open an Order Details page with a visibly broken workflow/task layout. Manually refreshing the browser immediately restored the correct design.

## Root cause
FlowTrack uses Livewire `wire:navigate`. That keeps the current browser CSS/JavaScript shell alive while the next page HTML is fetched and swapped in. With frequent FlowTrack deployments, a tab that was opened before a deployment can therefore combine **old Vite CSS/JS** with **new Blade/Livewire markup**.

The existing Vite build uses hashed filenames. Livewire's tracked-asset reload mechanism is most reliable when the tracked asset keeps a stable URI and its **query string** changes. A stale tab could therefore survive a deployment until the user manually refreshed it.

The task grid also previously had responsive CSS conflicts. The deterministic grid guard remains the final Order Details stylesheet layer; this fix addresses the separate long-idle/deployment mismatch that a CSS-only change cannot prevent.

## Permanent fix
1. `App\Support\FrontendBuildVersion` fingerprints `public/build/manifest.json` together with the stable tracker file.
2. The authenticated layout now includes a tiny stable asset: `public/js/flowtrack-build-track.js?v=<manifest fingerprint>` with `data-navigate-track`.
3. When a new frontend build is deployed, the tracker keeps the same path but receives a new `?v=` value. On the next `wire:navigate`, Livewire detects that change and performs a real browser reload before mismatched assets can be used.
4. The final Order Details CSS layer publishes `--ft-order-detail-style-contract: 20260827`.
5. The tracker checks that contract after normal load and after `livewire:navigated`. If the Order Details stylesheet is transiently absent, it performs one guarded self-healing reload per build/URL. Session storage prevents loops.

## Scope
No Order workflow rules, task transitions, permissions, assignment, documents, inline editing, finance, or database behavior were changed.

## Deployment requirement
Deploy source and its matching `public/build` together. For future releases, run `npm ci && npm run build` before publishing the release, then clear Laravel caches as usual. Once this fix has been deployed, users only need one normal refresh to load the new tracker; future deployments self-refresh stale tabs automatically.
