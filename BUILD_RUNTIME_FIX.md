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
