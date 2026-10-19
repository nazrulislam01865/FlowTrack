# FlowTrack CSS Phase 2 report

## Completed work

- Reorganized source CSS into `foundation`, `layouts`, `components`, domain-owned `modules`, composition-only `pages`, `utilities`, and `legacy`.
- Restored readable source formatting across all 57 stylesheets. For example, `data-table.css` is now 116 readable lines and the Dashboard theme is 3,199 lines instead of compressed rule streams.
- Added `npm run css:format` and `npm run css:check`. The check validates formatting, imports, cycles, orphan files, composition-only page entries, global-core boundaries, and non-increasing legacy budgets.
- Verified that formatting produces byte-identical minified CSS, so readability changes do not alter the rendered cascade.
- Kept attachment-upload styling in the global core until visual baselines approve moving it to Orders, Inquiries, and Documents.
- Kept list-filter and master-color components global because they are used across seven of nine authenticated route families; duplicating them into route assets would reduce cross-navigation cache reuse.
- Preserved the Phase 1 Dashboard-theme coverage for Clients, Reports, and Management after design regressions were reported. Narrower ownership is deferred until screenshot comparisons pass.
- Added a Playwright/pixelmatch visual-regression runner for 10 routes at three viewports, producing 30 comparisons per complete run.
- Added PHP architecture tests and whitespace-insensitive CSS source assertions so tests validate behavior instead of requiring minified source.
- Corrected two malformed activity-delete buttons where an extracted utility class had been inserted inside a Blade property expression; the architecture scan now rejects that corruption pattern.

## Legacy status

`resources/css/legacy/historical-overrides.css` is intentionally not auto-split. Readable formatting increases its source byte count but leaves its compiled output unchanged. Its technical-debt baseline is:

| Metric | Maximum allowed |
|---|---:|
| Rules | 5,281 |
| Declarations | 16,917 |
| `!important` declarations | 1,235 |

The automated CSS check fails if any of these counts increase. The next migration must first record approved screenshots, then move one screen-owned section, remove redundant specificity, compare all three viewports, and reduce these budgets.

## Transfer-size result

Phase 2 intentionally retains the exact Phase 1 compiled CSS coverage while adding maintainability and regression tooling. The shared authenticated core remains 553,081 raw bytes / 87,237 gzip bytes.

All generated CSS and JavaScript asset hashes match the last approved Phase 1 build. This exact-output check is the stability gate until real screenshots are recorded.

## Required next migration

Capture and approve the visual baselines described in `docs/VISUAL_REGRESSION.md`. Then migrate Dashboard-owned sections from the legacy file into `modules/dashboard` as the reference implementation. Do not change compatibility rule order before the baselines exist.
