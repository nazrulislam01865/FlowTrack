# Approved visual baseline procedure

## Required screens

Phase 0 records the representative route families in `quality/visual-scenarios.json`: Dashboard, Orders, My Work, All Tasks, Inquiries, Inquiry Intelligence, Master Data, Clients, Documents, Workflow Setup and Task Pack Setup at desktop, tablet and mobile widths.

Order detail/task and Inquiry detail/task must also be captured using stable seeded record IDs before their respective migration batches. These are data-dependent and are intentionally not guessed by the repository script.

## Setup

```bash
npm ci --ignore-scripts
npx playwright install chromium
php artisan serve
```

Use a stable seeded database and a test account with the permissions needed by all configured scenarios.

## Capture baseline

```bash
VISUAL_BASE_URL=http://127.0.0.1:8000 \
VISUAL_EMAIL=visual@example.com \
VISUAL_PASSWORD='local-test-password' \
VISUAL_TIMEZONE=Asia/Dhaka \
npm run visual:update
```

Review every image under `tests/Visual/baselines`. Committing an image means it is an approved behavioral/visual reference, not merely a screenshot that happened to render.

## Compare later changes

```bash
VISUAL_BASE_URL=http://127.0.0.1:8000 \
VISUAL_EMAIL=visual@example.com \
VISUAL_PASSWORD='local-test-password' \
npm run visual:test
```

The default maximum changed-pixel ratio is 0.5%. Use `VISUAL_SCENARIOS=dashboard,orders-list` for a focused run. Actual screenshots and diffs are ignored; approved baselines are versioned.

## Determinism requirements

Keep the following stable between baseline and comparison runs:

- seed data and record IDs;
- user/permissions;
- browser/Playwright version;
- locale and timezone;
- viewport definitions;
- fonts and operating-system image;
- application build mode;
- realtime/demo data that can reorder page content.
