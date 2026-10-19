# Order + Inquiry client-base row color priority — 2026-08-24

## Order list
- New Order (stage 1) rows use the client as the initial visual state when no Order task has been completed.
- IID uses a light green row tint.
- NEP uses a light blue row tint.
- As soon as any Order task is completed, the existing task/stage-driven row color logic takes precedence.
- Other Order stages keep their existing row-color behavior.

## Inquiry list
- Inquiries use the client tint until the first task is completed.
- IID uses light green; NEP uses light blue.
- After any Inquiry task is completed, the row switches to the active Task Pack item's configured color.
- If there is no active configured task color (for example, a fully completed taskflow), the Inquiry status color is used as a safe fallback.
- Task colors are resolved in one batch for the visible page; no per-row query is introduced.

## Scope
No workflow progression, task completion rules, filtering, permissions, pagination, or record visibility logic was changed.
