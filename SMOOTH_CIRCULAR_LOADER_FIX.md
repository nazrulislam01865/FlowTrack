# Smooth Circular Loader Fix

Updated the existing circular navigation percentage loader so internal loading milestones are no longer shown as visible jumps.

## Behaviour

- Progress animates continuously with requestAnimationFrame.
- Internal navigation milestones remain meaningful targets but are approached smoothly.
- While a request is waiting, progress gently creeps toward 95% and never reaches 100% early.
- Progress slows naturally as it gets closer to completion.
- Completion eases smoothly to 100%, pauses briefly, then hides.
- Existing 20-second stuck-loader safety reset remains.
- No navigation, business logic, page data, button loader, or UI layout behaviour was changed.

## Updated source

- resources/js/flowtrack/modules/core.js
- generated public/flowtrack/modules/core.js
- regenerated public/flowtrack/loader.js/runtime manifest through the existing runtime build script
