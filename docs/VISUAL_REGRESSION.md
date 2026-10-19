# Visual regression testing

CSS migration requires approved screenshots before selectors are moved out of `legacy/historical-overrides.css`. The runner captures Login plus nine authenticated route families at desktop, tablet, and mobile sizes.

## One-time setup

```bash
npm ci --ignore-scripts
npx playwright install chromium
php artisan serve
```

Use a stable seeded database and a user that can access every configured module. Credentials are provided only through environment variables and must never be committed.

## Record approved baselines

```bash
VISUAL_BASE_URL=http://127.0.0.1:8000 \
VISUAL_EMAIL=visual@example.com \
VISUAL_PASSWORD='local-test-password' \
npm run visual:update
```

Review every file under `tests/Visual/baselines` before committing it. Baselines are environment-sensitive; use the same browser version, seed data, locale, timezone, and operating-system image in CI.

## Compare a CSS migration

```bash
VISUAL_BASE_URL=http://127.0.0.1:8000 \
VISUAL_EMAIL=visual@example.com \
VISUAL_PASSWORD='local-test-password' \
npm run visual:test
```

Actual screenshots and failed diffs are written to ignored directories under `tests/Visual`. The default allowed changed-pixel ratio is 0.5%. Override it only for an intentional, reviewed change:

```bash
VISUAL_MAX_DIFF_RATIO=0.002 npm run visual:test
```

Use `VISUAL_SCENARIOS=dashboard,orders` to run a focused subset. A legacy migration is complete only after desktop, tablet, and mobile comparisons pass and the obsolete compatibility rules are deleted.
