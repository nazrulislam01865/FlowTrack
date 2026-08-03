# Board Empty Header + Bulk Icon Fix

## Fixed

- Job Board phase headers no longer disappear when a phase has zero cards.
- Empty phase columns now stretch to the same grid-row height as populated columns, so sticky headers remain within their parent boundary throughout vertical board scrolling.
- Workflow phases continue to render from workflow configuration, not from card availability.
- Replaced visible `Expand all`, `Collapse all`, and `Minimize all` bulk-control text with compact icon-only controls.
- Icon-only bulk controls retain `title` and `aria-label` text for accessibility and hover explanation.
- Applied the icon treatment to Operations Board bulk expansion and Job Details phase bulk expansion/collapse controls.

## Main files

- `resources/js/flowtrack/modules/operations.js`
- `resources/js/flowtrack/modules/job-details.js`
- `resources/css/flowtrack/runtime/15-board-empty-header-icons.css`
- `resources/css/flowtrack/app.css`
- Regenerated `public/flowtrack/*` runtime output
- Regenerated `public/build/manifest.json` and FlowTrack CSS bundle
