# Master Data Pagination Update

Updated Master Data sections:

- Product Categories
- Products
- Document Categories
- Departments

Behavior:

- Default page size: 10 records.
- Page-size options: 10, 20, 30, 50.
- Previous / Next navigation with current page indicator.
- Showing X–Y of Z record count.
- Search filters the complete selected section and pagination applies to the filtered result.
- Changing Master Data section resets pagination to page 1.
- CRUD operations keep the selected section and pagination state safely clamped to the available records.
- Other Master Data sections remain unchanged and continue to show all records.

Implementation keeps the complete Master Data catalog in memory because Products depend on Product Categories and other configuration forms reuse these records. Pagination is therefore applied at the Master Data table presentation layer without breaking relationships, dropdowns, or duplicate-entry validation.
