# Button Loading + Flash-Free Navigation Update

## What changed

- Added one shared button-loading controller at `resources/js/flowtrack/interaction-feedback.js`.
- Every dynamically rendered `<button>` receives a centered circular loading indicator while its click-triggered request/navigation is running.
- Repeated clicks are blocked while the button is busy without changing the button's normal dimensions.
- Normal FlowTrack workspace navigation now reuses the existing route page container instead of calling `window.location.assign()` for every sidebar/mobile page change.
- Browser Back/Forward restores FlowTrack routes through the in-place renderer and lazy data loader instead of reloading the full HTML document.
- Direct route URLs and server-side Laravel routing remain unchanged.
- The auth/loading shell is still used for first load/login only, not normal screen-to-screen navigation.

## Source files added

- `resources/js/flowtrack/interaction-feedback.js`
- `resources/css/flowtrack/modules/interaction-feedback.css`

## Main files updated

- `resources/js/flowtrack/modules/core.js`
- `resources/js/flowtrack/modules/records.js`
- `resources/css/flowtrack/app.css`
- `resources/views/flowtrack/partials/assets.blade.php`
- `scripts/build-flowtrack-runtime.mjs`

## Build

Run the normal project build after installing frontend dependencies:

```bash
npm install
npm run build
```

The supplied archive also contains regenerated `public/flowtrack/*` runtime assets and an updated compiled CSS manifest so the fix is available immediately.
