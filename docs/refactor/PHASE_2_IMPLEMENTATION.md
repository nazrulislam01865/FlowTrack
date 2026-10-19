# Phase 2 — Reusable CSS and Blade UI component library

## Implementation status

Implemented against the 20 August 2026 FlowTrack archive with the Phase 0/1 governance and design-token foundation present.

## Objective

Turn the Phase 1 token system into stable, accessible and reusable UI contracts while preserving existing page behavior. Phase 2 does **not** perform the broad page migration assigned to Phase 3.

## Delivered CSS architecture

```text
resources/css/
├── app.css
├── foundation/
│   ├── tokens.css
│   └── global.css
├── components.css
├── components/
│   ├── headers.css
│   ├── buttons.css
│   ├── badges.css
│   ├── forms.css
│   ├── dropdowns.css
│   ├── cards.css
│   ├── tables.css
│   ├── tabs.css
│   ├── modals.css
│   ├── tooltips.css
│   ├── pagination.css
│   ├── loading.css
│   ├── empty-state.css
│   └── validation.css
├── utilities.css
└── legacy/historical-overrides.css
```

`components.css` is composition-only and is loaded after the foundation but before utilities/legacy CSS.

## Compatibility isolation

The current project already uses some future-looking class names, particularly four Dashboard `.ft-table` instances. Official component CSS therefore uses the public class API plus a root marker such as:

```html
<table class="ft-table" data-ft-ui-component="table">
```

Selectors are anchored to that marker. Legacy markup with `.ft-table` but no Phase 2 marker is unaffected. This provides a safe compatibility layer until Phase 3 converts screens intentionally.

## Delivered Blade contracts

The official library now includes buttons, icon buttons, badges/status badges, page/section headers, cards, field/input/textarea/select/remote-select/date controls, table, tabs/tab, modal, tooltip, pagination, loading, empty-state and validation-message components.

Existing components are preserved. The existing `x-ui.badge` remains backward-compatible when called without an explicit variant; explicit Phase 2 variants use the official contract. `x-ui.remote-select` composes the existing proven remote-filter behavior rather than introducing a competing JavaScript implementation.

## Runtime color bridge

`App\Support\MasterColor::style()` now emits both `--ft-dynamic-*` and historical `--ft-master-*` custom properties. Existing phase/status/priority/department UI keeps its current behavior, while new official dynamic badges have one normalized runtime-color contract.

## Accessibility states

The library centrally defines focus-visible behavior, disabled/loading button semantics, `aria-busy`, form `aria-invalid`/feedback relationships, tablist/tab roles and selection state, modal dialog semantics, tooltip `aria-describedby`, loading `role=status`, validation `role=alert`, and reduced-motion behavior inherited from Phase 1.

## Developer reference

`resources/views/dev/ui-kit.blade.php` is available at `/_dev/ui-kit` only when the application environment is `local` or `testing`, and only inside the authenticated route group.

## Governance and tests

Added:

- `scripts/quality/ui-component-library.php`
- `quality/ui-component-baseline.json`
- `tests/Feature/UiComponentLibraryStructureTest.php`
- `npm run quality:components`
- `npm run quality:phase2`
- `.github/workflows/quality.yml` quality skeleton for this archive

The component gate prevents raw colors/`!important` in official component CSS, embedded `<style>` blocks in official Phase 2 Blade components, non-`ft-` CSS classes, composition-root drift, missing compatibility markers, unsafe developer-reference exposure, and growth of page-level direct component root markup.

## Phase 0 architecture-budget result after implementation

The combined Phase 2 gate reports:

- giant Livewire/service file ceilings unchanged;
- Blade `@php` blocks reduced from 189 to 188;
- Blade `style=` lines unchanged at 157;
- Blade `<style>` blocks unchanged at 6;
- Blade hard-coded color count unchanged at 576;
- canonical CSS `!important` unchanged at 1,265;
- legacy `flowtrack.css` remains below its Phase 0 size/color ceiling;
- official Phase 2 component CSS has zero hard-coded color literals and zero `!important`.

## Explicitly deferred

- broad replacement of existing page markup/CSS — Phase 3;
- deletion of generated `flowtrack-01..04.css` — Phase 3;
- complete unification of searchable/multi-select/filter query behavior — Phase 4;
- Livewire coordinator decomposition — Phases 5–7;
- business Action/Query decomposition — Phase 8.

## Acceptance criteria

- [x] Foundational shared component types have one official implementation.
- [x] Components consume Phase 1 tokens rather than raw static colors.
- [x] Disabled/loading/hover/focus/error states are centralized.
- [x] Runtime Master Data colors use a shared custom-property contract.
- [x] Existing remote-filter behavior is reused rather than duplicated.
- [x] Developer-only component reference exists.
- [x] Static/architecture gate prevents new component styling debt.
- [x] Phase 0 architecture budgets do not increase.
- [x] Legacy CSS budgets remain frozen/non-increasing.
- [x] Existing page CSS is not broadly migrated in this phase.
- [ ] Full PHPUnit remains dependent on a Composer `vendor/` environment not included in the uploaded archive.
- [ ] Production Vite build remains dependent on installed frontend dependencies not included in the uploaded archive.
- [ ] Authenticated visual approval remains a release-environment gate before Phase 3 page migration.

## Rollback

No database/data rollback is required. Remove the Phase 2 component files/import, restore the previous token/`MasterColor`/badge definitions, regenerate the four compatibility CSS chunks and remove the local reference route. Business logic and schema are unchanged.
