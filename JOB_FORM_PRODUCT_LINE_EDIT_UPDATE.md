# Job Form Product-Line and Edit Update

## Implemented behavior

- Product Category is stored and validated for every Product line.
- Each line keeps Product Category, Product, Quantity, and Remove action together.
- Product choices are filtered by the category selected in that same row.
- The same Product cannot be added more than once to a Job.
- Request Description is positioned directly below Job Title and stored on the Job.
- Priority uses square single-choice controls backed by one radio group.
- Job Assignee is visible in the Job drawer header.
- Authorized users can open Edit Job from the Job drawer.
- Edit Job updates title, request description, product lines, delivery date, priority, assignee, coordinator, client, and permitted financial fields.
- Workflow and current phase are intentionally not changed by Edit Job; phase movement remains controlled by the Workflow Engine.
- Legacy Job summary fields remain synchronized with the first Product line and total quantity.

## Database update

Run migration:

```bash
php artisan migrate --force
```

Migration added:

`2026_07_29_001500_add_request_description_to_flow_jobs.php`
