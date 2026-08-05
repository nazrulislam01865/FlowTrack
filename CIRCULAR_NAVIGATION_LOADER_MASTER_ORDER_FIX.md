# Circular navigation loader / Master Data order / stuck loader fix

- Replaced the in-app top progress bar with a centered circular percentage loader for page-to-page navigation.
- Navigation loader has a 20-second safety reset and is force-cleared on navigation errors, aborted navigation, pageshow, and unhandled promise failures.
- Shared edit/delete/save bootstrap refreshes no longer own the page-navigation loader, preventing 60–100% stuck progress.
- AJAX/native submit button loading no longer waits for a navigation-complete event that may never happen.
- Master Data > Document Categories now displays Sort Order after Description / Use.
