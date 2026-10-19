# FlowTrack CI/CD and release gates

Phase 15 makes the refactor rules executable in `.github/workflows/flowtrack-ci.yml`.

## Required CI stages

1. **Architecture/static** — Composer validation, Phase 0→15 architecture chain, PHP parser scan, JavaScript syntax/unit contracts.
2. **Backend** — clean Composer install against MySQL/Redis, migrations, Pint, PHPUnit.
3. **Frontend** — clean `npm ci`, JavaScript checks, Vite production build, bundle-size budgets, npm audit.
4. **Composer audit** — lockfile dependency advisory scan.
5. **Visual/browser** — opt-in authenticated Playwright visual regression and Phase 13 browser smoke tests. Enable only after approved screenshots are committed by setting repository variable `FLOWTRACK_VISUAL_CI=true`.
6. **Release reproducibility** — tag builds recreate dependencies/build output from lockfiles and emit a source/build hash manifest.

No CI failure should be bypassed by silently changing a baseline. A temporary rule exception must be documented, narrow, time-bound and removed when its owner is migrated.

## Local release gate

```bash
composer install --no-interaction --prefer-dist
npm ci
vendor/bin/pint --test
php artisan test
npm run quality:phase15
npm run build
npm run quality:bundle
composer audit --locked
npm audit --audit-level=high
```

For an affected screen family, also run the authenticated visual/browser checks after approved baselines exist.

## Build artifact rule

`public/build` is generated output. Never ship a stale manifest from an earlier source revision. A deploy either builds from the checked-in lockfiles or consumes an artifact produced by the same CI revision.
