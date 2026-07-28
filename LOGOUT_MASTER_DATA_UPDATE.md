# Logout and Master Data update

## Authentication

- A visible **Log out** button is available in the sidebar.
- Logout uses `POST /flowtrack/logout`.
- Laravel logs out the web guard, invalidates the session, regenerates the CSRF token and returns the browser to `/login`.
- FlowTrack pages send no-store headers so a signed-out dashboard is not restored from browser cache.

## Fully database-backed Master Data

- The frontend contains no seeded Master Data record arrays.
- Section definitions are supplied by Laravel from `MasterDataType`.
- All section lists, counts, codes, create forms, edit forms, activation state and deletion actions are loaded from the database.
- Codes remain immutable and are generated independently for each section.

## Delete behavior

Users with `masterdata.delete` can delete a record. Admin and Super Admin always have this permission.

Deletion soft-deletes the selected record and detaches its references from existing Jobs, tasks, users, workflows, documents and Task Pack items. Historical operational records are not deleted.
