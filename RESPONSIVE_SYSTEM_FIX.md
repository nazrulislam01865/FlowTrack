# FlowTrack Responsive Repair

This update replaces the previous system-wide responsive patch that caused page cropping.

## Root cause fixed

The earlier final responsive layer forced `#appScreen .main` to `width: 100%` while the desktop shell still uses a fixed 248px sidebar and a 248px left margin. The result was a main area wider than the viewport. Because horizontal overflow was also clipped/hidden, the right side of pages was cut off.

That rule has been removed.

## Correct responsive behavior

- Desktop main content keeps `width: calc(100% - 248px)` while the sidebar is visible.
- At 900px and below the sidebar becomes the existing mobile drawer and main content uses the full viewport width.
- Jobs filters wrap into 5/4/3/2/1-column layouts based on available viewport size instead of extending off-screen.
- My Work and Operations Board filters wrap into multiple rows before they can overflow.
- Job and Task board data keeps horizontal scrolling inside the board canvas on tablet/desktop.
- Existing stacked mobile Board/My Work behavior remains intact.
- Master Data category navigation wraps into a responsive grid instead of being clipped on tablet/mobile.
- Master Data rows become readable cards at 900px and below, keeping all action buttons visible.
- Wide tables and permission matrices scroll inside their own containers instead of increasing document width.
- Page headings, action groups, toolbars, cards, search inputs and dropdown controls are allowed to shrink/wrap.
- Mobile bottom navigation remains within the viewport.
- No backend/business logic was changed.
- No sidebar loader/navigation behavior was changed.

## Validation

Representative layouts were rendered in Chromium and checked at:

1920, 1700, 1536, 1440, 1366, 1280, 1180, 1024, 901, 900, 768, 640, 520, 430, 390 and 360px.

For the tested Dashboard/generic layout, Jobs, My Work, Operations Board and Master Data layouts:

- document width matched viewport width;
- desktop main content ended exactly at the viewport edge;
- no top-level page section extended outside the viewport;
- Jobs tables scrolled inside the table card;
- desktop/tablet boards scrolled inside the board canvas;
- mobile boards used the existing stacked layout;
- Master Data actions stayed inside the record card.

## Files changed

- `resources/css/flowtrack/runtime/13-system-responsive.css`
- `public/build/manifest.json`
- `public/build/assets/app-8c70d0b7.css`
- `RESPONSIVE_SYSTEM_FIX.md`

The public CSS asset was given a new hashed filename so browsers do not keep using the previous cached broken stylesheet.
