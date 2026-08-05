# Job Details mobile phase-task repair

The phone layout broke because legacy tablet rules assigned fixed percentage widths to the six task table columns. Later mobile rules converted each table row into a stacked card, but the higher-specificity `nth-child` widths remained active, squeezing values into tiny columns and forcing dates/task numbers to wrap one character per line.

The final responsive layer now resets all six cell widths on screens up to 720px and makes every task cell a full-width labelled row. Assignee, due date, status, action and task title controls are constrained to the card width without horizontal scrolling. Desktop and tablet layouts are unchanged.
