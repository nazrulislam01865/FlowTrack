# Order Details - Artwork popup size restored (2026-08-22)

## Change
The blanket Artwork-phase modal-width override was removed because it made simple action dialogs such as Request Artwork Revision excessively wide.

Artwork workflow dialogs now use the same per-dialog sizing rules that were in place before the artwork-wide override:
- standard action dialogs use the normal Order Details modal width;
- Review Artwork continues to use its existing `--wide` variant where configured;
- upload dialogs use the original fixed responsive upload size;
- responsive/mobile behavior remains unchanged.

## Scope
Presentation-only rollback. No artwork revision logic, document handling, task progression, validation, permissions, or workflow branching was changed.
