# Inquiry RFQ responsive + compact pass — 2026-08-29

## Scope

Inquiry Details > RFQ > Product RFQ invitations only, plus compact-device behavior for the Inquiry Details header/tabs.

## Changes

- Uses the RFQ pane as a CSS inline-size container so responsiveness follows the actual content width beside the persistent sidebar rather than the browser viewport alone.
- Keeps the existing reusable Blade components and Livewire actions unchanged.
- Reduces Product RFQ heading, stat values, and product-name typography using the centralized FlowTrack typography tokens.
- Converts the four RFQ summary cards to a responsive 2-column layout when the workspace narrows.
- Reflows search/filter/email-settings controls to avoid clipping.
- Reflows product badges and actions below the product title when space is constrained.
- Replaces the wide seven-column supplier table with responsive supplier cards at narrow workspace widths. Existing `data-label` attributes are reused, so no duplicate mobile markup is required.
- On phone-sized content widths, supplier cards become a single readable column and row actions become full-width touch targets.
- Makes the Inquiry Details metadata, tabs, page padding, and compact sidebar behavior safer on small screens.
- Keeps desktop layout and RFQ behavior unchanged at wide content widths.

## Build delivery

Because this archive does not include `node_modules`, the affected prebuilt Vite CSS assets were updated directly and the Vite manifest was refreshed with new asset names to avoid stale browser caching.
