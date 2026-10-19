# Order Details Equal Summary Card Width — 2026-08-22

## Change
The three Order Details workflow summary cards now use equal-width grid columns.

Affected cards:
- Current stage
- Overall progress
- Next required action

## CSS
Changed the desktop summary grid from:

`1fr 1fr 1.25fr`

to:

`repeat(3, minmax(0, 1fr))`

The existing mobile responsive rule remains unchanged, so the cards still stack on narrow screens.

## Scope
Visual-only change. No order, workflow, task, phase, or action logic was modified.
