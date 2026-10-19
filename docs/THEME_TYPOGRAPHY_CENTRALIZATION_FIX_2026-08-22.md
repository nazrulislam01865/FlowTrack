# FlowTrack Central Typography Fix — 22 August 2026

## Problem

The theme exposed a central font-family token, but legacy/module CSS still contained thousands of fixed pixel font sizes and the authenticated theme stylesheet was emitted before Livewire styles. This meant typography was not truly controlled from one place and different screens could retain visibly different typography behavior.

## Fix

The canonical controls are now in `resources/theme/flowtrack/settings.css`:

```css
--ft-theme-root-font-size: 16px;
--ft-theme-font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
--ft-theme-font-family-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
```

- Normal application text and form controls use `--ft-theme-font-family`.
- Technical/code/color-value fields use the centrally owned mono token only.
- Existing fixed CSS `font-size: Npx` values were converted to mathematically equivalent `rem` values using the existing 16px baseline. Current visual sizes therefore do not change at the default setting.
- Changing `--ft-theme-root-font-size` now scales static typography across the application.
- Avatar initials use relative font sizing too.
- The authenticated theme stylesheet is loaded after Livewire styles so the central theme remains the final typography authority.

## Example

Before:

```css
font-size: 12px;
```

After:

```css
font-size: .75rem;
```

At the default `--ft-theme-root-font-size: 16px`, both compute to exactly 12px.

## Governance

`php scripts/quality/theme-package.php` now rejects:

- missing central root-size/family tokens;
- fixed pixel CSS font-size declarations;
- duplicate Inter/application font-family ownership outside theme settings;
- missing central form-control typography enforcement;
- a theme stylesheet loaded before Livewire styles.
