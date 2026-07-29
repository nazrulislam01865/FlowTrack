# Build and Runtime Fix

This package fixes two extraction/runtime problems:

1. `php artisan optimize:clear` failed with `View path not found` because the project did not contain a robust `config/view.php` and ZIP extraction could omit empty `storage/framework/views` directories.
2. `npm run build` invoked the Wayfinder Vite plugin even though the exact-template frontend is served directly by Blade and `public/flowtrack-dynamic.js`. If Artisan could not boot, the Wayfinder generation step stopped the whole build.

## Applied fix

- Added `config/view.php` with a non-empty compiled-view path.
- Included all required Laravel runtime directories using `.gitignore` placeholders.
- Removed machine-specific `public/storage` links and generated cache files.
- Changed Vite to build two small optional FlowTrack placeholder entries without Wayfinder.
- Added `prepare_runtime.sh`.

The exact-template interface and dynamic workflow implementation are unchanged.

## Duplicate Vite configuration fix

The project previously contained both `vite.config.js` and `vite.config.ts`.
Vite selected the obsolete JavaScript configuration first. That file referenced
assets that are not part of this project:

- `resources/css/landing-admin.css`
- `resources/js/app.js`
- `resources/js/passkeys.js`

The obsolete `vite.config.js` has been removed. The supported configuration is
`vite.config.ts`, using the existing FlowTrack validation entries. The
`rebuild_frontend.sh` script now removes any stale copy of `vite.config.js`
before running the build.
