# Phase 3 migration order

| Batch | Family | Current result | Next deletion target |
|---|---|---|---|
| 3A | Shared shell assets | `/public/css` eliminated; Vite owns compatibility bundles | split/remove compatibility selectors as migrated |
| 3B | Shared component CSS | Blade style blocks extracted | normalize summary/date/export selectors into official Phase 2 APIs |
| 3C | Orders / All Tasks | page layouts are route-scoped module CSS | replace legacy `.ft-button`, pills, filters, pagination with official components |
| 3D | Setup/Admin | repeated inline styles centralized | move remaining Master Data/Workflow/Task Pack visual rules from legacy compatibility CSS |
| 3E | Inquiries / Intelligence | compatibility stylesheet remains source-managed | migrate buttons/badges/forms/table patterns, then delete matching selectors |
| 3F | Dashboard/Reports | dashboard prototype Vite-managed + inline geometry reduced | promote tables/cards/filter controls to official components |
| 3G | Clients/Catalog/Documents | compatibility styles source-managed | migrate page structures and delete replaced compatibility selectors |

Every future batch must run feature tests, desktop/tablet/mobile visual comparison, `npm run quality:phase3`, and delete the selectors it replaces rather than leaving two active implementations.
