# FlowTracker: StepPromo removal and Itqan Consulting footer

## Changes
- Removes StepPromo hard-coded login artwork, titles, navigation identity, report labels, reminder branding fallbacks, and legacy demo references.
- Uses the existing workspace branding name and optional uploaded logo/favicon across application, login and public tracking pages. If an old workspace is still called StepPromo, the migration changes *only that exact old name* to FlowTrack. Other configured names are preserved.
- Adds a configurable copyright footer for internal application, login and public tracking. Default output: © 2026 Itqan Consulting. All rights reserved. with the name linking to https://itqanconsulting.com.
- Allows Admin and Super Admin to edit system name, footer visibility, copyright prefix, owner, URL and closing text from Administration > Branding.
- Preserves independent supplier portal footer controls; new unconfigured portal footers default to Itqan copyright.
- Uses the existing cached BrandingService; no per-page footer query and no new sidebar item.

## Deployment
1. Back up code and database before replacing files. Do not replace your server .env or storage/uploads.
2. Copy this project's modified files over your Laravel project (or deploy the full project archive). If using the changed-files archive, additionally remove the paths listed in DELETE_OLD_FILES.txt.
3. In your deployment root, run: php artisan migrate --force
4. Run: php artisan optimize:clear
5. Reload login, dashboard, and Administration > Branding to confirm; edit the defaults as desired.
6. The provided public/build manifest and prebuilt login CSS were updated, and the new footer CSS is served as a versioned static asset, so an npm rebuild is not required just for these changes. On future source rebuilds, run npm ci && npm run build as usual.

## Validation
- PHP syntax checks passed for modified PHP files.
- Frontend JS syntax checked; no live StepPromo references remain outside the historical migration and regression assertion.
- Bundled Vite manifest references all exist.
- Full Laravel integration tests were not run: the supplied archive contains no vendor/ dependencies and Composer installation was unavailable in this environment.

## Notes
- No customer, supplier, legal-company-profile, or order records are overwritten. If existing user-entered content, saved templates, or uploaded custom logo artwork itself says StepPromo, correct those through their respective administration controls.
- This archive contains the FlowTracker project only; the separately deployed Supplier Portal application has not been modified.
