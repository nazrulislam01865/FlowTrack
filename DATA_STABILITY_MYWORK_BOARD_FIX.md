# Data stability, My Work and Board fix

## Fixed

- Prevented realtime/background refreshes from replacing an already-loaded multi-page dataset with only page 1.
- Added stale-navigation protection so an older slow page request cannot overwrite a newer navigation choice.
- The first requested screen now waits for its own lazy page data before first render, avoiding empty/partial data flashes.
- My Work now loads the complete visible task dataset before rendering instead of gradually filling as auto-pagination runs.
- My Work and Board client filters now use bounded related-client lookups returned with task/job pages, so they do not depend on visiting the Clients screen first.
- Removed the `All active jobs` selector from both Job Board and Task Board.
- Job Board phase headers are sticky inside the board scroller on tablet/desktop, matching Task Board behavior.
- Mobile Board keeps normal page scrolling.

## Backend logic

No workflow/business authorization logic was changed. The fix is in frontend data loading/state reconciliation and Board presentation.
