# FlowTrack modular frontend structure

FlowTracker keeps the supplied FlowTrack design as the visual source of truth, but the application no longer mounts every screen through an ignored Livewire compatibility shell.

## Route-level Blade pages

Authenticated URLs are handled by dedicated controllers under:

```text
app/Http/Controllers/FlowTrack/Pages/
```

The workspace mounts only the active route page through:

```text
resources/views/flowtrack/pages/screen.blade.php
```

The established `page-*` IDs, CSS classes, data attributes, overlays, board markup and record/detail selectors remain unchanged for frontend compatibility.

## JavaScript source

Canonical application JavaScript lives under:

```text
resources/js/flowtrack/
├── loader.js
├── searchable-dropdown.js
├── prototype-fallback.js
└── modules/
    ├── core.js
    ├── workspace.js
    ├── operations.js
    ├── board.js
    ├── configuration.js
    ├── records.js
    ├── job-shared.js
    ├── task-details.js
    └── job-details.js
```

This follows the same principle used by Hisebghor: responsibility-scoped source modules instead of one application-sized JavaScript source file. The route-aware loader downloads only the modules needed by the active page or first interaction.

`npm run build` runs `scripts/build-flowtrack-runtime.mjs`, which generates ordered runtime files under `public/flowtrack/`. Generated public files must not be edited directly.

The generated runtime remains ordered classic JavaScript intentionally. The preserved FlowTracker renderer shares lexical state across several historical sections; forcing those sections into independent ESM bundles in one step would change behavior. The maintainable source boundary is now explicit, and page/controller boundaries no longer depend on the old monolith.

## CSS

`resources/css/flowtrack/app.css` remains the Vite CSS entry. Existing CSS modules and their cascade order are preserved. No design framework reset or Tailwind preflight was introduced.

## Backend pairing

Page controllers are under `app/Http/Controllers/FlowTrack/Pages/`.
API controllers are separated under `app/Http/Controllers/FlowTrack/Api/` by responsibility: reads, clients, jobs, tasks, documents, configuration, workflows, access, profile, branding and session behavior.

Business rules continue to live in the existing OrderFlow Actions/Services and remain authoritative.
