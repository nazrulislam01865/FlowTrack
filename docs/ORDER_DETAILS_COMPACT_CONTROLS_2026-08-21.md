# Order Details compact controls update — 2026-08-21

This UI-only pass keeps all existing Order workflow/backend behaviour and tightens the controls that were visually oversized after the inline-edit work.

## Header command bar
- Shipment urgency remains visible as the status badge.
- The separate shipment-urgency action is now a compact pencil control.
- The Order owner remains visible with avatar/name.
- The separate Reassign owner action is now a compact pencil control using the same existing remote-user picker.
- No owner/urgency persistence methods were changed.

## Activity composer
- The Comment button no longer stretches to the height of the rich-text editor.
- It is a compact 36px action aligned to the bottom-right of the composer.
- Mobile layout keeps the button right-aligned under the editor.

## Disclosure controls
- `Hide activity` / `Show activity` and `Show files` use a compact 26px neutral control.
- Their existing disclosure behaviour is unchanged.

## Asset delivery
The compiled Orders CSS is included as `public/build/assets/index-order-compact-controls-20260821.css`, and the Vite manifest points to it, so a frontend rebuild is not required for this change.
