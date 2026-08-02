# FlowTrack modular frontend structure

## Blade and Livewire

The root `resources/views/flowtrack-template.blade.php` only composes modules:

- `flowtrack/auth/` — login and authenticated loading state
- `flowtrack/layouts/` — workspace shell, navigation and content host
- `flowtrack/partials/` — runtime defaults, overlays and asset tags
- `livewire/flow-track/screens/` — one view for each of the 13 canonical screens

The existing screen IDs, classes, data attributes and `wire:ignore` boundaries
are preserved. JavaScript and backend endpoints therefore keep the same DOM and
API contracts.

## CSS and Vite

`resources/css/flowtrack/app.css` is the Vite entry. It imports CSS in the same
cascade order as the previous embedded styles:

- `modules/` — base, authentication, shell, dashboard, tables, board, My Work,
  clients, documents, reports, setup, overlays, responsive, configuration,
  compact controls and detail/form modules
- `vendors/` — the preserved searchable-dropdown stylesheet
- `runtime/` — the 53 former JavaScript-injected style blocks, externalized in
  their original order

`npm run build` writes the fingerprinted CSS and Laravel manifest under
`public/build/`. No CSS remains in Blade `<style>` tags, and JavaScript no
longer creates runtime `<style>` elements.

Tailwind was not enabled. Its preflight reset could change established form,
table and typography rendering; Vite compiles the existing exact CSS directly.

## JavaScript

The route-aware loader remains small. Nine generated modules load only when
the current screen or interaction needs them. Generated files under
`public/flowtrack/modules/` must not be edited directly.

## Safe deployment

On an existing database, run the additive migrations with
`php artisan migrate --force`. Never run `migrate:fresh` or `db:wipe`.
