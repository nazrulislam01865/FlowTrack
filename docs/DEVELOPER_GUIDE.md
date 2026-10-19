# FlowTrack developer guide

> **Current-state note (Phase 1):** This document contains target-state guidance that can be ahead of the executable archive. Use `docs/refactor/CURRENT_STATE.md`, `docs/refactor/PHASE_0_BASELINE.md`, `docs/refactor/PHASE_1_IMPLEMENTATION.md`, and `docs/ui-design-system.md` for what is implemented now; activate later target directories/rules only as their roadmap phase lands.


## Where code belongs

- Put a user-initiated write in `app/Features/<Feature>/Actions`. An Action has one public `execute` method, accepts the actor explicitly, and delegates transaction/audit work to the domain service while that compatibility layer exists.
- Put permission-scoped reads in `app/Features/<Feature>/Queries`. Never fetch a record by ID in Livewire before applying the actor's visibility scope.
- Keep Livewire public properties and navigation state on the coordinator. Put workflow behavior in a clearly named `Concerns` trait; do not re-grow the coordinator into a multi-thousand-line component.
- Put reusable infrastructure in `app/Services` or `app/Support`; it must not depend on a Livewire component.
- Put CSS in the smallest `resources/css` module and browser behavior in `resources/js`. Blade must not contain `<style>`, `style=`, or raw DOM event attributes.
- Keep page CSS entries composition-only. Reusable selectors belong in `components`; screen-specific selectors belong in `modules/<domain>`.
- Never manually minify source CSS. Run `npm run css:format`; Vite owns production minification.

## Commenting standard

Comments explain intent, invariants, security decisions, or non-obvious compatibility constraints. Avoid narrating obvious syntax. Public Actions and Queries require a short class docblock; complex transactions and normalization rules require a focused inline comment at the decision point.

Examples of useful comments include why an actor must be passed explicitly, why a list query is bounded, or why a legacy field remains synchronized. Examples to avoid include `// Set the name` immediately before `$model->name = ...`.

## Adding an Order or Inquiry workflow

1. Add or extend the permission-scoped Query needed to load the aggregate.
2. Add one Action for the command and keep validation close to the input boundary.
3. Call the Action from the relevant Livewire concern.
4. Add an allowed-user test, a denied-user test, and any source-boundary regression test.
5. Run the full quality gate.

## Quality gate

```bash
composer install --no-interaction --prefer-dist
npm ci --ignore-scripts
vendor/bin/pint --test
php artisan test
npm run css:check
npm run build
composer audit --no-interaction
npm audit --omit=dev --audit-level=high
```

Before moving or deleting compatibility selectors, capture approved screenshots and run the workflow in [Visual regression testing](VISUAL_REGRESSION.md).

For a local SQLite setup, copy `.env.example`, generate an application key, create `database/database.sqlite`, and run migrations before tests. Production must use shared cache/session/queue infrastructure and supervised workers as described in the architecture guide.
