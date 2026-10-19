# FlowTrack refactoring report

## Outcome

FlowTrack now has a production-oriented modular-monolith baseline for Laravel 13 and Livewire 4. The refactor preserves the existing database and validated domain services while moving UI orchestration behind explicit feature Actions, Queries, and workflow concerns. This keeps one reliable deployment unit for the expected 50–60 concurrent users without accepting monolithic presentation files or permissive persistence models.

## Completed work

### Orders

- Reduced the shared Order coordinator from about 3,989 lines to 360 lines.
- Split catalog, creation, finance, Inquiry links, products, inline edits, documents, tasks, activity/attention, and page-data behavior into named concerns.
- Added separate `Orders\\Create`, `Orders\\Detail`, and `Orders\\TaskDetail` Livewire entry components while retaining the shared serialized state contract for deep-link compatibility.
- Added 21 focused Order Actions and two permission-scoped Queries. Livewire no longer invokes `JobService` directly for Order reads or writes; the service is an internal compatibility adapter behind the feature boundary.
- Preserved existing list pagination, record scopes, audit activity, transactions, notifications, and optimistic edit responses.

### Inquiries

- Reduced the Inquiry coordinator from about 3,070 lines to 895 lines.
- Split creation, products, tasks, documents, and final-decision workflows into named concerns.
- Added 25 focused Inquiry Actions and two permission-scoped Queries for list/detail access, product lines, attachments, comments, attention, tasks, conversion, status, and workflow persistence.
- Retained the existing Inquiry service as the transactional adapter so permission, activity, mention, and notification behavior is not duplicated.

### Model security

- Replaced every empty `$guarded` declaration in application models with an explicit migration-audited `$fillable` list.
- Excluded primary keys, timestamps, and soft-delete markers from request mass assignment.
- Added comments documenting the lifecycle-field invariant on each affected model.

### Frontend and delivery

- Kept editable CSS and JavaScript under modular `resources/css` and `resources/js` trees with no CSS embedded in Blade.
- Kept dedicated authenticated and login Vite entry points.
- Generated and included `public/build/manifest.json` plus hashed assets, fixing the reported `ViteManifestNotFoundException` after extraction.
- Added `package-lock.json`; local setup and CI now use reproducible `npm ci` installs.
- Split authenticated CSS into a shared core and nine route-level Vite entries, reducing transferred gzip CSS by approximately 32–55% depending on the page.
- Completed CSS Phase 2 ownership cleanup: readable source formatting, domain module folders, composition-only page entries, legacy-growth budgets, and a 30-screenshot visual-regression harness.
- Excluded runtime uploads, environment files, logs, databases, dependency directories, and other machine state from the release archive.

## Quantitative change

| Boundary | Before | Current |
|---|---:|---:|
| Order coordinator | ~3,989 lines | 360 lines |
| Inquiry coordinator | ~3,070 lines | 895 lines |
| Order Actions / Queries | 0 | 21 / 2 |
| Inquiry Actions / Queries | 0 | 25 / 2 |
| Models with `protected $guarded = []` | 40 | 0 |
| Editable CSS/JS directories under `public` | 2 | 0 |
| Vite production manifest in release | missing | included |
| Authenticated gzip CSS per page | 195,906 B | 87,237–131,933 B |

## Verification

Completed in the refactoring environment:

- Vite 8 production build, including the manifest, shared core and route-level CSS entries.
- JavaScript syntax checks for every source module.
- PHP parser validation across application, configuration, migrations, routes, and tests.
- CSS import-target and brace-balance checks.
- Architecture scans for inline styling/handlers, request closures, direct Order service calls, and empty model guards.
- Archive scans for traversal paths, runtime uploads, secrets, databases, logs, and dependencies.

The environment does not contain a PHP runtime or Composer executable, so PHPUnit, Pint, and Composer Audit cannot be executed here. CI remains the release authority for those three checks and uses PHP 8.3, Node 22, `npm ci`, Pint, PHPUnit, the production Vite build, and dependency audits.

## Remaining staged work

1. Continue moving lower-level Inquiry task-link and option reads behind feature boundaries when those workflows next change; the user-facing workflows are already separated.
2. Capture approved visual baselines, then migrate Dashboard compatibility sections as the reference legacy-CSS extraction.
3. Move remaining embedded interface copy into `lang/en` and `lang/zh` before claiming complete bilingual coverage.
4. Collect production CSP reports and enforce `Content-Security-Policy` after all observed violations are resolved.
5. Load-test the busiest authenticated workflows with at least 60 virtual users and record p95 latency, error rate, slow queries, and queue delay.

## Deployment gate

```bash
composer install --no-interaction --prefer-dist
npm ci --ignore-scripts
cp .env.example .env
php artisan key:generate
php artisan migrate --force
vendor/bin/pint --test
php artisan test
npm run build
php artisan optimize
```

Production should use a real database, Redis-backed cache/session/queues, supervised queue and Reverb processes, TLS, and external object storage/backups. Never deploy the development SQLite database or local runtime uploads.
