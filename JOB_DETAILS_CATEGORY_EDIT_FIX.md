# Job Details Category Edit Fix

## Change
- Added the same edit control beside **Category** in Job Details → Overview → Products & quantities.
- Category, Product, and Quantity continue to use one linked row editor.
- Changing the category refreshes the Product selector so only products belonging to the selected category can be saved.
- Existing backend `products[]` update validation and persistence are reused; no new business logic or schema change was introduced.

## Files
- `resources/js/flowtrack/modules/job-details.js`
- Generated `public/flowtrack/modules/job-details.js` and loader/runtime metadata via `npm run build:runtime` equivalent.
