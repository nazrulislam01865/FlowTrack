# Theme Background and Typography Correction — 2026-08-22

## Scope

This correction keeps the Dashboard teal as the centralized FlowTrack brand/interaction accent without forcing the Dashboard page background onto every module.

## Preserved backgrounds

The theme package centrally owns, but does not visually unify, the existing approved screen backgrounds:

- Generic FlowTrack shell: `#f4f7fb`
- Dashboard: `#f3f6fa`
- Orders list: `#f3f6fb`
- Inquiries: `#f3f6fb`
- Order Details: `#f3f6f9`
- Inquiry Intelligence: `#f3f7fb`

Changing the primary theme color no longer changes these backgrounds.

## Typography

`resources/theme/flowtrack/settings.css` is the only owner of the application sans-serif font family through `--ft-theme-font-family`.

Foundation tokens and feature CSS delegate to that variable. Intentional monospace fields remain monospace.

The shared/base font-size scale is centralized in the same settings file. Exact legacy component-level font sizes remain in compatibility CSS where changing them would alter current layout density; they are not separate font-family definitions.

## Validation

- Full Phase 0–15 + theme quality chain: PASS
- Application sans-serif font-family duplicates outside theme settings: 0
- Static primary blue literals outside theme package: 0
- Dashboard brand teal literals outside theme package: 0
- PHP lint: PASS
