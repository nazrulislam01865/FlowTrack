# My Work Ghost Job + Expand/Collapse Fix

## Changes

- My Work is now task-driven for every role, including Administrator and Super Admin.
- A Job row is rendered only when at least one currently visible My Work Task belongs to that Job.
- Removed the legacy demo Job shown in the reported My Work issue from both the frontend fallback data and the database seeder.
- Added an icon-only Expand All / Collapse All control to My Work.
- The control uses the same chevron-stack icon language and tooltip/ARIA labels as the Board.
- The icon toggles all currently visible My Work Job groups and respects individual collapsed state.
- When no Job groups are visible, the icon remains present but disabled.

## Files changed

- `resources/js/flowtrack/modules/operations.js`
- `resources/js/flowtrack/prototype-fallback.js`
- `database/seeders/FlowTrack/FlowTrackSeeder.php`
- Generated `public/flowtrack/*` runtime assets

## Validation

- FlowTrack source/generated JavaScript syntax passed.
- Seeder PHP syntax passed.
- The reported demo Job no longer exists in runtime fallback or seed data.
