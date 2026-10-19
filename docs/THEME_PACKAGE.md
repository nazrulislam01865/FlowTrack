# FlowTrack Central Theme Package

## Purpose

FlowTrack now has a separate modular theme package at `resources/theme/flowtrack/`. The package is based on the approved Dashboard management color language and is loaded across the application after legacy/module CSS. This creates one static theme authority without rewriting business screens or runtime Master Data colors.

## Single control point

Edit `resources/theme/flowtrack/settings.css` to change the system theme.

The high-level values at the top of the file cover the settings normally changed by product/design teams:

- `--ft-theme-primary*` — brand/interactive teal palette
- `--ft-theme-page-bg`, `--ft-theme-surface*` — page/card/input surfaces
- `--ft-theme-text-*` — text hierarchy
- `--ft-theme-border*` — borders/dividers
- `--ft-theme-font-*` — family, sizes, weights, line heights
- `--ft-theme-radius-*`, `--ft-theme-shadow-*` — shape/elevation
- `--ft-theme-topbar-*`, `--ft-theme-content-*` — shell geometry
- `--ft-theme-sidebar-*` — complete sidebar design and sizing

The lower `--ft-theme-compat-*` section centralizes remaining Dashboard component shades so static colors still have only one owner. These advanced values normally do not need editing.

## Default Dashboard theme

The package defaults are intentionally derived from the current Dashboard management design:

- primary: `#007d70`
- primary hover: `#006d62`
- global page canvas: `#ffffff`
- surface: `#ffffff`
- primary text: `#152238`
- secondary text: `#61738d`
- border: `#d9e2ec`

The sidebar remains dark navy for hierarchy/readability, but its selected/active treatment now uses the same Dashboard teal rather than a separate blue brand color.

## Compatibility strategy

`aliases.css` maps the theme package onto the existing FlowTrack contracts, including:

- official `--ft-*` design tokens
- historical variables such as `--blue`, `--line`, `--card`
- Dashboard `--mgmt-*` variables
- old Dashboard prototype variables
- sidebar variables

This means new shared components and existing compatibility CSS can consume the same theme source while page-by-page legacy cleanup continues.

## Ownership changes

The following active styles are no longer owned by `resources/css/legacy/compatibility/`:

- sidebar template
- Dashboard management theme

They now live in `resources/theme/flowtrack/components/`, reducing the Phase 15 legacy exception set by two active CSS files.

## Runtime Master Data colors

Workflow phase, task status, priority and department colors are runtime business configuration. The theme package must not override them. Those continue through the existing validated runtime-color contract.

## Build

Run:

```bash
npm ci
npm run build
npm run quality:theme
```

`quality:theme` executes the full Phase 0–15 gate and then verifies theme-package ownership and color centralization.

## Whole-system primary color coverage

The first theme-package extraction centralized token definitions but did not rewrite every legacy/page-level hard-coded primary blue. The whole-system coverage pass now converts those primary/static states to theme variables across the active CSS tree.

Covered static interaction states include buttons, links, active tabs, pagination, toggles, progress bars, focus rings, selectors, upload/drop zones, Orders, Inquiries, My Task, Documents, Finance, setup/admin and catalog controls.

Run:

```bash
npm run quality:theme
```

The `theme-coverage.php` gate rejects saturated legacy brand-blue literals and hard-coded Dashboard-brand teal literals under `resources/css`. This prevents pages from bypassing `resources/theme/flowtrack/settings.css` again.

### Intentional exception: runtime Master Data colors

Task, phase, status, priority and department colors configured in Master Data remain runtime data. They are not static theme colors. When no runtime color exists, CSS fallbacks use the theme primary where applicable, but an explicitly configured Master Data color is preserved.

## Central plain-white page canvas

The system page background is intentionally **not part of the teal accent palette**. Every normal application screen resolves its canvas to one central token:

```css
--ft-theme-page-bg: #ffffff;
```

Dashboard, Orders, Bulk Order, My Task, All Task, Inquiries, Order Details and Inquiry Intelligence all delegate their screen-level background aliases to that token. This prevents individual pages from reintroducing tinted page backgrounds.

Teal remains an interaction/brand accent for primary buttons, links, active tabs, selected controls and focus states. Local semantic and runtime Master Data colors remain available where they communicate state; they do not redefine the page canvas.

## Centralized font family

`--ft-theme-font-family` in `settings.css` is the only application sans-serif family source. The foundation token bridge and feature CSS consume that variable. Intentional technical/monospace controls are excluded.

The base/shared font-size scale (`--ft-theme-font-size-*`) remains centralized. Exact legacy component font sizes are retained where changing them would alter current screen density; they can be migrated separately to semantic typography roles without a visual redesign.

## Central typography enforcement (2026-08-22)

Typography is now controlled from the theme package rather than only referenced by it.

Primary controls in `resources/theme/flowtrack/settings.css`:

```css
--ft-theme-root-font-size: 16px;
--ft-theme-font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
--ft-theme-font-family-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
```

Rules:

- `--ft-theme-font-family` is the only normal application font-family owner.
- `--ft-theme-font-family-mono` is the only approved alternate family for technical/code/color-value fields.
- Every static CSS font size is expressed in `rem`, preserving the previous pixel size at the default 16px root.
- Changing `--ft-theme-root-font-size` scales the complete static typography system proportionally.
- Form controls are explicitly forced to the application family because browsers do not consistently inherit form typography.
- The authenticated theme stylesheet is loaded after Livewire styles so later page/runtime CSS cannot silently become the global font owner.
