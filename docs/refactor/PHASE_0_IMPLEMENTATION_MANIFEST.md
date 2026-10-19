# Phase 0 implementation manifest

## Added

- `.github/workflows/quality.yml`
- `quality/architecture-baseline.json`
- `quality/frontend-bundle-baseline.json`
- `quality/performance-baseline.json`
- `quality/phpunit-baseline.json`
- `quality/phpunit-baseline.log`
- `quality/visual-scenarios.json`
- `scripts/quality/architecture-budget.php`
- `scripts/quality/bundle-baseline.mjs`
- `scripts/quality/performance-baseline.php`
- `scripts/quality/phase0-baseline.sh`
- `scripts/quality/php-lint.sh`
- `scripts/quality/phpunit-baseline.sh`
- `tests/Visual/visual-regression.mjs`
- `tests/Visual/baselines/` approval location
- `docs/refactor/PHASE_0_BASELINE.md`
- `docs/refactor/CURRENT_STATE.md`
- `docs/refactor/ENGINEERING_GOVERNANCE.md`
- `docs/refactor/REFACTOR_RELEASE_PROCEDURE.md`
- `docs/refactor/DATABASE_BACKUP_RESTORE.md`
- `docs/refactor/VISUAL_BASELINE_PROCEDURE.md`

## Modified

- `package.json`: quality and visual-regression commands plus visual-test dependencies already represented in the lockfile.
- `package-lock.json`: root dependency metadata synchronized with `package.json`; resolved dependency graph retained.
- `docs/ARCHITECTURE.md`: current-state warning so target structure is not mistaken for implemented source.
- `docs/DEVELOPER_GUIDE.md`: same current-state warning.

## Explicitly not changed in Phase 0

- application business logic;
- routes or authorization behavior;
- database schema/data;
- CSS architecture or visual design;
- Livewire/service decomposition;
- upload/security behavior;
- production infrastructure configuration.
