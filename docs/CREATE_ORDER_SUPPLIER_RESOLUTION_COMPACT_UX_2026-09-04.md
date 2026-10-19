# Create Order Supplier Resolution - Compact UX refinement

## Goal
Reduce the visual weight and apparent zoom of the `Supplier not linked` dialog without changing its workflow, validation, supplier linking, supplier creation, or skip behavior.

## Changes
- Reduced the modal to the centralized medium content width.
- Reduced header, body, footer, notice, option, and field spacing using existing `--ft-*` spacing tokens.
- Changed modal title, product name, and option headings from bold/large presentation to centralized semibold compact typography.
- Returned inputs/search-select controls and footer buttons to the standard medium control height.
- Reduced radio, warning, and close-icon geometry while preserving keyboard focus states and hit clarity.
- Removed the extra selected-option outline so selection is communicated with one brand border instead of a visually heavy double border.
- Added a subtle option hover border for clearer affordance.

## Architecture
The change remains isolated to `resources/css/modules/orders/create-order-supplier-resolution.css` plus the modal button-size props. It continues to use shared `x-ui.modal`, `x-ui.search-select`, `x-ui.input`, and `x-ui.button` components and only consumes centralized FlowTrack typography/theme tokens.

## Behavior
No business logic, database behavior, Livewire method, validation rule, order payload, or supplier persistence behavior was changed.
