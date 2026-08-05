# Logout permission/navigation fix

## Root cause

The legacy prototype bound every `.nav-btn` to `navigate(button.dataset.page)`.
The Logout control also used `.nav-btn`, but had no `data-page`, so on screens where the lazy Records module was not loaded the click became `navigate(undefined)`. The modern permission guard then showed:

`You do not have permission to open this section.`

The actual logout click handler also lived in the lazy Records module, which meant Logout behavior depended on which page modules had already been loaded.

## Fix

- Sidebar navigation now binds only to `.nav-btn[data-page]`.
- Logout is a real POST form to `flowtrack.logout` with CSRF protection.
- The logout controller returns JSON for AJAX callers and redirects to `/login` for normal form submission.
- Removed the Records-module-only Logout click handler.
- Logout remains excluded from button loading because it lives in the sidebar.
- Cleared compiled Blade view files so the updated sidebar markup is regenerated.

No database migration is required.
