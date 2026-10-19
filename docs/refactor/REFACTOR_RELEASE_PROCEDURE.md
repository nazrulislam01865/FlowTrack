# Refactor branch, release and rollback procedure

## Branching

Use one phase branch with small feature-family batches, for example:

- `refactor/phase-0-baseline`
- `refactor/phase-1-design-foundation`
- `refactor/phase-3-orders-css-batch-01`

Avoid a long-lived branch containing multiple unfinished phases. Rebase/merge from the production branch regularly so regression evidence represents the current release line.

## Before implementation

1. Run `npm run quality:architecture` and retain the output in the PR/release evidence.
2. Run `bash scripts/quality/php-lint.sh`.
3. After dependencies are installed, run Pint and the full PHP test suite.
4. For a UI-affecting batch, confirm approved visual baselines exist for the affected routes/viewports.
5. For data/schema work, take and verify a database backup according to `DATABASE_BACKUP_RESTORE.md`.

## Commit structure

Prefer independently reversible commits:

1. tests/baseline coverage;
2. compatibility boundary or reusable primitive;
3. migration of one workflow/screen;
4. deletion of replaced legacy code;
5. documentation/budget update after the debt reduction.

Do not mix unrelated formatting or redesign with a structural extraction.

## Pre-release gate

Run:

```bash
bash scripts/quality/php-lint.sh
php scripts/quality/architecture-budget.php --check
vendor/bin/pint --test
php artisan test
npm ci --ignore-scripts
npm run build
npm run visual:test   # when the affected baselines are approved/configured
composer audit --no-interaction
npm audit --omit=dev --audit-level=high
```

For query/data changes, capture representative performance metrics before and after the change.

## Deployment unit

Every migration batch must be deployable without depending on code from a later phase. Database migrations must be backwards-safe for the immediately previous application release whenever practical.

## Rollback

Phase 0 adds no business/data behavior. Its rollback is removal/reversion of quality tooling and documentation only.

For later phases, rollback the smallest migration batch. Do not roll back shared design-system foundations merely because one feature migration fails; revert the feature adapter/markup/query/action that introduced the regression.
