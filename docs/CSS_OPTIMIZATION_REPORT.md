# FlowTrack CSS optimization report

## Result

The authenticated interface no longer delivers every feature stylesheet on every page. Vite now emits one shared core and nine route-level feature entries. Laravel selects the active feature entry through `PageAssetResolver`, preserving hashed assets, the production manifest, browser caching, and Livewire navigation.

The original authenticated CSS was 1,321,492 bytes raw and 195,906 bytes gzip. The shared core is 553,081 bytes raw and 87,237 bytes gzip. Each page adds only its feature bundle.

| Route family | Total raw CSS | Total gzip CSS | Gzip reduction |
|---|---:|---:|---:|
| Notifications/core-only | 553,081 B | 87,237 B | 55.5% |
| Documents | 573,585 B | 91,568 B | 53.3% |
| Board / My Work | 603,255 B | 95,053 B | 51.5% |
| Clients | 609,939 B | 96,917 B | 50.5% |
| Reports | 622,922 B | 99,519 B | 49.2% |
| Dashboard | 633,008 B | 100,144 B | 48.9% |
| Orders | 749,282 B | 118,577 B | 39.5% |
| Management/catalogue | 765,559 B | 120,789 B | 38.3% |
| Inquiries | 911,211 B | 131,933 B | 32.7% |

Measurements pipe each generated asset through maximum gzip compression without filename metadata. Browser-transfer values can vary slightly by server compression settings.

## Architecture

- `resources/css/app.css` contains only the application shell, shared components, shared list controls, and compatibility styles.
- `resources/css/pages/*.css` composes feature modules by route family.
- `resources/css/modules/*` owns domain-specific selectors; page entries only compose modules.
- `resources/css/components/product-creation-modal.css` keeps the product-creation UI reusable without loading the complete management catalogue stylesheet in Orders or Inquiries.
- `resources/css/modules/dashboard/theme.css` remains available to the Phase 1 baseline routes until visual comparisons prove narrower ownership is safe.
- `app/Support/PageAssetResolver.php` is the allow-listed route-to-entry mapping.
- `vite.config.js` registers every deployable entry so `public/build/manifest.json` is complete.
- `resources/views/layouts/app.blade.php` retains one `@vite` directive and receives its entries from the resolver.

New feature styles must be placed in a component/domain module and imported by the narrowest page entry. They must not be added to `app.css` unless nearly every authenticated page genuinely requires them.

## Compatibility-layer decision

`legacy/historical-overrides.css` remains in the shared core. It contains interleaved, date-ordered fixes for many screens, and automatically separating those rules without approved screenshot baselines could change cascade order and regress layouts. Phase 2 adds a 30-screenshot visual-regression harness and hard budgets preventing its 5,281 rules, 16,917 declarations, and 1,235 `!important` declarations from increasing. Migration now proceeds one screen at a time.

## Verification

- Vite 8 production build succeeded with a complete manifest.
- Every CSS import resolves to an existing source file.
- CSS braces and comments are structurally balanced.
- PHP source, including the resolver and its tests, passes parser validation.
- JavaScript source passes syntax validation.
- Production npm audit reports zero vulnerabilities.
- Source scans still reject embedded Blade styles and raw DOM event handlers.

---

## 2026-08-24 — Phase 3 CSS finalization

The source-level monolith/compatibility migration is complete. `resources/css/flowtrack.css`, `resources/css/legacy/`, and `resources/css/migration/` were removed. Their active rules were split into bounded component/module owners while preserving cascade order. The largest source CSS file is now below 90 KB and a 100 KB hard ceiling is enforced by `npm run quality:css-modularization`.

See `docs/refactor/PHASE_3_CSS_FINALIZATION.md` and `quality/css-finalization-report.json` for the migration map and measurements.
