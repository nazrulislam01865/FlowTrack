# Create Order Supplier Resolution - Primary Button Contrast Fix

## Change

The Supplier Resolution modal primary action now has an explicit local class and uses the centralized `--ft-text-inverse` token for its text color. Nested Livewire loading/content spans inherit the same color.

## Why

Legacy unlayered Create Order CSS can override the shared layered button component color, which caused dark text on the green primary action. The fix is scoped to this modal and does not modify shared button behavior or introduce hard-coded colors.

## Scope

- No workflow or supplier logic changes.
- No database changes.
- Centralized theme and typography remain intact.
