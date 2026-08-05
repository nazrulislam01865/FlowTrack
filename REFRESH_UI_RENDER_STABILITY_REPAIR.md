# Refresh UI / renderer / loader stability repair

This repair addresses hard-refresh flashes, legacy detail-template restoration, and inconsistent loader styles.

## Startup/rendering

- Authenticated hard refresh now runs as an atomic boot.
- `appScreen`, overlay, modal, and drawer content stay invisible while the requested route is being reconstructed.
- The circular startup loader remains visible until the exact requested final route has mounted.
- Before reveal, temporary modal/drawer source state is closed and cleared from view.
- The loader verifies that the final Task Details host, final Job Details v4 host, or requested standalone form is actually mounted before revealing the application.

## Job Details

- Legacy Job Details v3 repaint timers are disabled once the final v4 renderer is loaded.
- Legacy delayed `flowtrackEnsureJobPanels()` repainting is skipped when v4 owns the screen.
- Shared Job panel refreshes route through the final v4 renderer whenever it is available.

## Loader consistency

- Initial authenticated startup now uses the same circular percentage visual language as page-to-page navigation.
- Page navigation remains circular percentage loading.
- Inline data-refresh feedback now uses a circular spinner instead of a horizontal loading bar.
- Existing button action loaders remain circular.
- Actual business progress indicators (job progress, upload progress when meaningful) are not treated as navigation loaders.

## Business logic

No workflow rules, permissions, APIs, form payloads, or database schema were changed.
