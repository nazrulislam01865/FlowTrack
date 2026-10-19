# Inquiry RFQ Central Typography — 2026-08-29

## Scope

Updated the Inquiry Details > RFQ product workspace typography only. The RFQ layout, data flow, actions, reusable Blade components, invitation logic, and responsive structure are unchanged.

## Change

The RFQ workspace no longer uses small one-off pixel font sizes or numeric font-weight values. It now consumes FlowTrack's centralized typography tokens from `resources/theme/flowtrack/settings.css`:

- `--ft-theme-font-family`
- `--ft-theme-font-size-xs`
- `--ft-theme-font-size-sm`
- `--ft-theme-font-size-base`
- `--ft-theme-font-size-lg`
- `--ft-theme-font-size-xl`
- centralized regular/medium/semibold/strong/bold/extrabold weights
- centralized tight/snug/normal line heights

This brings product names, supplier names, email addresses, invitation statuses, quotation values, activity timestamps, buttons, table headers, helper text, footer text, and summary-card labels into the same application typography scale used by the rest of FlowTrack.

## Build compatibility

The current prebuilt application CSS asset was refreshed with the updated RFQ module so the change is available without requiring a frontend rebuild on the receiving machine.

## Validation

- No raw numeric `font-size` declarations remain in the RFQ product workspace stylesheet.
- No raw numeric `font-weight` declarations remain in the RFQ product workspace stylesheet.
- RFQ workspace CSS block remains balanced and isolated.
- Existing project CSS governance result is unchanged from the input archive; its pre-existing baseline debt remains outside this change.
