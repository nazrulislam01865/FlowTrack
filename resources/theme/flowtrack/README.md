# FlowTrack Theme Package

This directory is the single static visual-theme owner for FlowTrack.

## Edit one file

For normal system-wide theme changes, edit only:

`resources/theme/flowtrack/settings.css`

It controls:

- primary/brand palette (Dashboard teal by default)
- page, surface, text and border colors
- semantic feedback colors
- font family, font scale, weights and line heights
- radii, shadows and transitions
- application shell sizing
- complete sidebar palette, active state, widths and typography
- advanced Dashboard component palette values

`settings.css` is deliberately the only theme-package CSS file allowed to contain raw static color values.

## Package layout

- `core.css` — lightweight composition for login/non-shell pages
- `theme.css` — authenticated application composition root
- `settings.css` — **single editable source of truth**
- `aliases.css` — maps the package to existing `--ft-*`, Dashboard, sidebar and legacy token APIs
- `system.css` — system shell/body/topbar/common-control theme bindings
- `components/sidebar.css` — sidebar design owned by the theme package
- `components/management-dashboard.css` — approved Dashboard management design owned by the package

## Runtime colors

Master Data status/phase/priority/department colors remain runtime data and are intentionally not hard-coded into this theme package.

## Loading

Authenticated pages load `theme.css` after application and route CSS so the package owns static styling. Login loads `core.css` only.

## Global page background

FlowTrack uses one centralized application canvas: pure white (`#ffffff`). All screen-level page background aliases (Dashboard, Orders, Inquiries, Order Details, Inquiry Intelligence, Bulk Order, My Task and All Task) resolve to `--ft-theme-page-bg`.

Changing `--ft-theme-primary*` changes only the brand/interactive accent. It does not tint the page canvas. Component-local hover, selected, warning, success and runtime Master Data colors remain separate because they communicate interaction or business state.

## Typography ownership

The application sans-serif font family has one owner: `--ft-theme-font-family` in `settings.css`. Foundation tokens, authenticated shell, login, Orders, Inquiries and report CSS delegate to that value. Intentional monospace fields (code/color/technical values) remain monospace.

The shared/base font-size scale is also defined in `settings.css`. Some legacy compatibility screens still retain exact component-level font-size values to preserve their approved density; those are layout compatibility values, not separate font-family ownership.
