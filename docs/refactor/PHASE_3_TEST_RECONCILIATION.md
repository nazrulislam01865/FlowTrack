# Phase 3 PHPUnit reconciliation

## Why the first full-suite run reported many failures

The first dependency-complete run after Phase 3 surfaced two independent categories:

1. **Phase 3 test ownership drift.** Runtime CSS moved intentionally from `public/css` to `resources/css/legacy/compatibility`, but several existing feature tests still called `public_path('css/flowtrack-*.css')` or asserted the former direct `<link>` tags. Those tests were not migrated with the CSS source-of-truth change.
2. **Pre-existing/stale regression tests.** The uploaded pre-Phase-3 archive already contains tests whose string expectations no longer match its executable source, tests with PHP interpolation bugs in assertion literals, and unrelated functional failures (Master Data behavior, My Work prototype expectations, profile defaults, route state, etc.). Phase 3 did not change those application areas.

The production Vite build completed successfully in the user's dependency-complete environment, confirming that the new CSS entries resolve and compile.

## Corrections included in this reconciliation

- `Tests\TestCase::compatibilityCss()` is now the single test helper for reading frozen migrated compatibility CSS.
- All feature tests that previously read `public/css/flowtrack-*.css` now read the managed source through that helper.
- Tests that verified former direct CSS links now verify the corresponding Vite entry plus the compatibility import that owns the legacy file.
- List-filter JavaScript layout assertions no longer pin an obsolete cache-busting version; they still require the correct script path.
- Static source-code assertions using PHP double-quoted strings now escape `$` so PHPUnit does not throw `Undefined variable` before performing the assertion.
- Phase 3 governance now fails if a test reintroduces `public_path('css/flowtrack-*.css')`.
- Empty Laravel runtime directories carry `.gitkeep` files so archive extraction preserves `storage/framework/views`, `storage/framework/sessions`, cache, logs and `bootstrap/cache`.

## What was deliberately not changed

This reconciliation does not alter application behavior to satisfy unrelated stale tests. Examples from the reported run include Master Data code expectations, old My Work prototype assertions, workflow-route expectations, product/master-data UI assertions, and other features that were not part of the Phase 3 CSS migration.

Those should be handled by comparing each failing test to the approved functional baseline and either fixing the application defect or updating an obsolete test expectation. They must not be silently changed as part of a CSS migration.

## Required verification on the development machine

```bash
php artisan optimize:clear
npm run quality:phase3
php artisan test
npm run build
```

After the rerun, any remaining failures should be treated as the pre-existing functional/test-baseline backlog rather than Phase 3 asset-migration regressions, unless the failing source file is one of the explicit Phase 3 migration files.
