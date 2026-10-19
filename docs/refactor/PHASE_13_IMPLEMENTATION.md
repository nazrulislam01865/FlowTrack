# Phase 13 implementation — JavaScript and realtime modularization

## Scope

Phase 13 restructures authenticated browser runtime ownership without changing business rules, routes, database schema or visual design. It follows the roadmap rollback rule by retaining one compatibility namespace while the old global call sites are retired.

## Changes

- Replaced the 727-line historical `app.js` implementation bucket with a small composition root.
- Moved browser behavior into `resources/js/core`, `resources/js/components` and `resources/js/features`.
- Removed source ownership from eight historical `public/js/flowtrack-*.js` files; `public/js` is no longer a FlowTrack source directory.
- Centralized Livewire SPA navigation registration in `core/navigation.js` with an idempotent bind guard.
- Centralized Reverb socket, reconnect and channel lifecycle in `core/realtime.js`.
- Centralized cross-module realtime/Livewire/browser event names in `core/events.js`.
- Separated notification and workspace-refresh consumers from the socket implementation while preserving polling fallbacks.
- Replaced direct broad `window.FlowTrack*` Blade calls with `window.FlowTrack.ui.*`.
- Kept deprecated broad globals only as non-enumerable compatibility getters in `compatibility/browser-bridge.js`.
- Added route-driven dynamic loading for Bulk Order Import, Order detail and client validation behavior.
- Removed the runtime SheetJS CDN include and pinned the existing 0.18.5 parser as a Vite dependency.
- Added JS syntax, unit-contract and browser-smoke tests.

## Behavior preserved

- Existing Livewire routes/deep links and server events.
- Existing Reverb private channel names/auth endpoint.
- Existing notification and workspace polling fallbacks.
- Existing inline-edit, filter, mention, rich-text, upload and Master Data color UI contracts.
- Existing Bulk Order Import spreadsheet normalization behavior and parser version.

## Explicit non-goals

- No CSS cleanup or visual redesign.
- No Phase 14 Redis/infrastructure changes.
- No event payload redesign on the server.
- No silent SheetJS version upgrade.
- No removal of the compatibility aliases until call-site/browser validation proves they are unnecessary.

## Quality gate

`npm run quality:phase13` chains Phase 0–12, JS syntax/unit tests, and the Phase 13 architecture gate. The gate freezes CSS, routes, migrations, AccessControlService and the approved namespaced Blade binding migration.

A real Vite production build, PHPUnit suite and Playwright browser smoke still belong to the dependency-complete release environment when `vendor/` / `node_modules/` and a running application are available.
