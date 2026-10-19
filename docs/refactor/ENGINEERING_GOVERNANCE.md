# FlowTrack refactor engineering governance

## Purpose

Phase 0 freezes observable behavior and technical-debt ceilings before structural refactoring starts. The rule is simple: later phases may reduce debt, but they must not silently increase it while migration is in progress.

## Non-negotiable refactor rules

1. **Preserve behavior first.** Structural work does not change business semantics unless the change is explicitly approved and separately tested.
2. **Keep every batch deployable.** No half-migrated feature may require old and new implementations to disagree about a rule.
3. **Do not redesign during structural migration.** Visual changes require explicit approval and updated visual baselines.
4. **Architecture debt is a ceiling.** `php scripts/quality/architecture-budget.php --check` must pass. Any temporary exception requires a written reason, owner, expiry phase, and follow-up issue.
5. **Sensitive operations remain server-authorized.** Blade visibility is never treated as authorization.
6. **Reads stay bounded.** New list/search/report work must deliberately choose pagination, aggregation, or a justified bounded read.
7. **Compatibility code is temporary.** An adapter must name its replacement boundary and deletion condition.
8. **No Phase 1+ structure is claimed before it exists.** Documentation must distinguish current executable state from target state.

## Phase 0 architecture debt budgets

The authoritative values are stored in `quality/architecture-baseline.json`. The scanner currently covers:

- line counts for the six largest coordinators/services identified by the roadmap;
- Blade `@php`, `app()`, `auth()`, `style=`, `<style>` and hard-coded hex usage;
- canonical CSS `!important` and hard-coded hex usage;
- `flowtrack.css` bytes, lines, `!important` and color occurrence counts;
- models using `protected $guarded = []`;
- application `->get()` call count as an early bounded-read debt signal.

File-count metrics are recorded for context but are not treated as debt ceilings because modularization can legitimately increase the number of focused files.

## Pull request release gate

Every refactor pull request must state:

- phase and migration batch;
- affected workflows and routes;
- functional tests executed and any known pre-existing failures;
- visual scenarios checked at desktop/tablet/mobile where presentation changes;
- architecture-budget result;
- before/after performance evidence when queries/data loading change;
- authorization denial tests for sensitive read/write changes;
- rollback unit (normally one migration batch/commit set).

A batch is not complete merely because the new implementation works. The obsolete implementation should be deleted when call-site search and regression coverage prove it is no longer needed.

## Exception process

A quality-gate exception is allowed only to unblock a production defect. Record:

- exact failing metric/gate;
- why fixing it in the same change is riskier;
- owner;
- expiry date or refactor phase;
- maximum permitted temporary delta;
- rollback/follow-up reference.

Never regenerate `quality/architecture-baseline.json` simply to make a regression pass. Re-baselining is appropriate only after an approved debt reduction or an explicitly accepted scope change.


## Phase 1 CSS governance

Phase 1 adds a mandatory CSS gate:

```bash
php scripts/quality/css-foundation-budget.php
```

Rules:

- `resources/css/foundation/tokens.css` is the only approved owner of new static design values;
- `resources/css/app.css` remains composition-only;
- `global.css` owns only low-specificity base/accessibility/typography behavior;
- new architecture classes use `ft-`; narrow utilities use `u-`;
- no new direct stylesheet may be added under `public/css`;
- existing legacy CSS byte, `!important`, and hard-coded-color budgets may only decrease;
- no new `!important` or raw static colors are allowed in managed foundation CSS;
- the compatibility stylesheet is not a destination for new features;
- runtime Master Data colors remain the validated data-driven exception.

Run the combined gate with `npm run quality:phase1`.

Do not re-baseline `quality/css-legacy-baseline.json` to accommodate new debt. Intentional exceptions follow the same owner/reason/expiry process as the Phase 0 architecture budget.


## Phase 2 component-library governance

Run `php scripts/quality/ui-component-library.php` or the combined `npm run quality:phase2` gate. New page code must consume official `x-ui` components before adding direct root component markup. Component CSS belongs under `resources/css/components`, uses `ft-` namespacing, consumes design tokens, and may not introduce hard-coded colors or `!important`. The `data-ft-ui-component` marker is a compatibility boundary and must remain on official root elements until legacy class collisions are eliminated in Phase 3.

`quality/ui-component-baseline.json` freezes the only known direct root collision: four pre-existing Dashboard `.ft-table` instances. That number may decrease but may not increase. Runtime Master Data colors are the documented exception to static styling and must use `App\Support\MasterColor` / `--ft-dynamic-*`.

## Phase 4 shared interaction rules

- New searchable selectors use `x-ui.search-select`; large datasets set a remote selector `type`.
- New multi-selects use `x-ui.multi-select`; do not preload complete client/user/product/order catalogs.
- Remote option reads go through `FilterOptionService::searchPage()` or a compatibility adapter that delegates to it.
- A no-match or incomplete non-empty search must never return unrelated fallback options.
- Selected values are resolved independently from the current result page.
- New filter screens compose `x-ui.filter-bar`, `x-ui.search-input`, `x-ui.filter-chip`, `x-ui.date-range`, and `x-ui.filter-reset` before inventing feature markup.
- Deprecated selector/filter components may remain only in documented compatibility locations and must not gain ordinary feature-page call sites.
- Phase 4 component CSS remains token-driven and may not add hard-coded colors or `!important`.
