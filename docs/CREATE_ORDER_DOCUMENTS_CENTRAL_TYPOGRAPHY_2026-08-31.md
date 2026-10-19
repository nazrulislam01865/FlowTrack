# Create Order Documents — Central Typography Alignment — 2026-08-31

## Goal
Keep the redesigned Create Order Documents section visually identical in structure while making its typography match the rest of the Create Order form and remain centrally controlled.

## Central source of truth
The Documents section now consumes the existing Create Form typography tokens from:

- `resources/theme/flowtrack/settings.css`
- `resources/theme/flowtrack/forms/foundation.css`
- `resources/theme/flowtrack/forms/order.css`

The relevant shared tokens are:

- `--ft-form-page-copy-size` / `--ft-form-page-copy-weight`
- `--ft-form-section-title-size` / `--ft-form-section-title-weight`
- `--ft-form-label-size` / `--ft-form-label-weight`
- `--ft-form-control-size` / `--ft-form-control-weight`
- `--ft-form-helper-size` / `--ft-form-helper-weight`
- `--ft-form-button-size` / `--ft-form-button-weight`

## Result
The redesigned Documents UI no longer owns independent text sizing for headings, labels, upload copy, file names, metadata, badges, actions, status notices, or the compact workflow preview beneath it. These values now follow the same Create Order typography system as the other numbered sections.

Responsive rules keep layout changes only; they no longer introduce a separate mobile font scale for the Documents area.
